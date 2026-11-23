<?php
namespace Portale;

/**
 * Invio email con tetto orario e coda di uscita.
 *
 * Il server di posta (account cPanel) accetta un numero fisso di messaggi all'ora per tutti i siti e RIFIUTA quelli oltre il tetto: il portale
 * ne ha una quota (`mail_cap_per_hour`, 60). Ogni messaggio passa da qui: se c'è posto va via subito, altrimenti (o se l'invio fallisce) resta nella
 * tabella `mail_outbox` e lo smaltisce `bin/mail-outbox.php` (ogni 5 minuti), prima gli urgenti (link di accesso, conferme di pagamento), poi i
 * massivi (i lavori del cron). Un rifiuto non perde il messaggio: si ritenta con attesa crescente per 72 ore.
 *
 * Mezzi di invio: `mail.transports` in config.php, in ordine (il primo con posto e che risponde); ognuno ha il suo tetto orario. Senza elenco si usa
 * mail() di PHP con il tetto di `mail_cap_per_hour`. Le credenziali SMTP stanno solo in config.php sul server, mai nel repository né nei log.
 *
 * Nei test: {@see Mailer::$sender} sostituisce tutto; {@see Mailer::$transport} sostituisce solo l'invio vero.
 */
final class Mailer {

	/** @var callable|null function (string $to, string $subject, string $body): bool — sostituisce tutto (test) */
	public static $sender = null;

	/** @var callable|null function (array $transport, string $to, string $subject, string $body): bool — sostituisce l'invio vero (test) */
	public static $transport = null;

	/** @var Db|null database per tetto e coda; senza, si invia subito senza tetto */
	public static $db = null;

	/** I lavori del cron mettono true: i loro messaggi sono «massivi» e lasciano sempre posto agli urgenti. */
	public static $bulk = false;

	const URGENT = 'urgent';
	const BULK   = 'bulk';

	/** Attese tra i tentativi falliti (secondi); poi REPEAT. */
	const BACKOFF = array( 300, 900, 3600 );
	const REPEAT  = 3600;
	/** Dopo tante ore un messaggio che non parte si abbandona (resta scritto nel log). */
	const GIVE_UP_HOURS = 72;
	/** I messaggi chiusi si cancellano dopo tanti giorni (contengono testi e indirizzi). */
	const KEEP_DAYS = 30;

	/**
	 * Accoda o invia. @return bool true se il messaggio è partito o è stato preso in carico (resta in uscita); false solo se non si può nemmeno accodare.
	 */
	public static function send( string $to, string $subject, string $body, ?string $priority = null, ?int $now = null ): bool {
		if ( null !== self::$sender ) {
			return (bool) call_user_func( self::$sender, $to, $subject, $body );
		}
		$priority = $priority ?? ( self::$bulk ? self::BULK : self::URGENT );
		$db       = self::$db;
		$now      = $now ?? time();
		if ( null === $db ) {
			return 'sent' === self::attempt( null, $to, $subject, $body, $priority, $now );
		}
		// Se c'è già una coda dello stesso o di maggior peso, non si scavalca (l'ordine di arrivo conta): si accoda.
		$backlog = self::backlog( $db, $priority );
		if ( ! $backlog && 'sent' === self::attempt( $db, $to, $subject, $body, $priority, $now ) ) {
			return true;
		}
		return self::enqueue( $db, $to, $subject, $body, $priority, $now );
	}

	/** Smaltisce la coda: gli urgenti prima. @return array [inviati, rimasti, abbandonati] */
	public static function drain( Db $db, ?int $now = null, int $limit = 100 ): array {
		$now  = $now ?? time();
		$sent = 0;
		$gone = 0;
		$rows = $db->all( 'SELECT * FROM mail_outbox WHERE done_at IS NULL AND next_at <= ? ORDER BY CASE WHEN prio = ? THEN 0 ELSE 1 END, created_at LIMIT ' . (int) $limit, array( gmdate( 'Y-m-d H:i:s', $now ), self::URGENT ) );
		foreach ( $rows as $r ) {
			$res = self::attempt( $db, (string) $r['to_addr'], (string) $r['subject'], (string) $r['body'], (string) $r['prio'], $now );
			if ( 'sent' === $res ) {
				$db->run( 'UPDATE mail_outbox SET done_at = ?, last_error = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $now ), '', $r['id'] ) );
				++$sent;
				continue;
			}
			if ( 'full' === $res ) {
				if ( self::URGENT === $r['prio'] ) {
					break; // niente posto per gli urgenti: nemmeno i massivi (hanno meno posto) possono partire
				}
				continue; // un massivo senza posto: gli urgenti sono già stati provati, resta per la prossima ora
			}
			$n = (int) $r['attempts'];
			if ( $now - strtotime( (string) $r['created_at'] . ' UTC' ) > self::GIVE_UP_HOURS * 3600 ) {
				$db->run( 'UPDATE mail_outbox SET done_at = ?, last_error = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $now ), 'abbandonato: invio non riuscito per ' . self::GIVE_UP_HOURS . ' ore', $r['id'] ) );
				error_log( '[portale] email abbandonata dopo ' . self::GIVE_UP_HOURS . ' ore (id ' . $r['id'] . ')' );
				++$gone;
				continue;
			}
			$wait = self::BACKOFF[ $n ] ?? self::REPEAT;
			$db->run( 'UPDATE mail_outbox SET attempts = attempts + 1, next_at = ?, last_error = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $now + $wait ), 'rifiutata dal server di posta', $r['id'] ) );
		}
		$db->run( 'DELETE FROM mail_outbox WHERE done_at IS NOT NULL AND done_at < ?', array( gmdate( 'Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400 ) ) );
		$left = (int) $db->one( 'SELECT COUNT(*) AS n FROM mail_outbox WHERE done_at IS NULL' )['n'];
		return array( $sent, $left, $gone );
	}

	/** Mezzi di invio configurati, nell'ordine; senza elenco, mail() di PHP con il tetto orario standard. @return array[] name, kind (mail|smtp), cap, … */
	public static function transports(): array {
		$out = array();
		foreach ( (array) Config::get( 'mail.transports', array() ) as $i => $t ) {
			if ( ! is_array( $t ) || empty( $t['host'] ) || false === ( $t['enabled'] ?? true ) ) {
				continue; // voce preparata ma non attiva
			}
			$out[] = $t + array( 'name' => 'smtp' . $i, 'kind' => 'smtp', 'port' => 465, 'secure' => 'ssl', 'cap' => 60 );
		}
		return $out ? $out : array( array( 'name' => 'php', 'kind' => 'mail', 'cap' => max( 1, (int) Config::get( 'mail_cap_per_hour', 60 ) ) ) );
	}

	/** Posti riservati agli urgenti in ogni ora: i massivi usano al massimo (tetto - riserva). */
	public static function reserve(): int {
		return max( 0, (int) Config::get( 'mail_urgent_reserve', 20 ) );
	}

	/** @return string sent | full (nessun posto nell'ora) | failed (il mezzo ha rifiutato) */
	private static function attempt( ?Db $db, string $to, string $subject, string $body, string $priority, int $now ): string {
		$full = true;
		foreach ( self::transports() as $t ) {
			$cap = (int) $t['cap'];
			if ( self::BULK === $priority ) {
				$cap -= self::reserve();
			}
			$bucket = 'mail:' . $t['name'];
			$hour   = gmdate( 'YmdH', $now );
			if ( $db && ( $cap < 1 || ! RateLimit::take( $db, $bucket, $hour, $cap, $now ) ) ) {
				continue;
			}
			$full = false;
			if ( self::transmit( $t, $to, $subject, $body ) ) {
				return 'sent';
			}
			if ( $db ) {
				RateLimit::give_back( $db, $bucket, $hour ); // un invio rifiutato non consuma la quota
			}
		}
		return $full ? 'full' : 'failed';
	}

	private static function backlog( Db $db, string $priority ): bool {
		$sql = self::URGENT === $priority ? 'SELECT 1 AS x FROM mail_outbox WHERE done_at IS NULL AND prio = ? LIMIT 1' : 'SELECT 1 AS x FROM mail_outbox WHERE done_at IS NULL LIMIT 1';
		return (bool) $db->one( $sql, self::URGENT === $priority ? array( self::URGENT ) : array() );
	}

	private static function enqueue( Db $db, string $to, string $subject, string $body, string $priority, int $now ): bool {
		try {
			$at = gmdate( 'Y-m-d H:i:s', $now );
			$db->run( 'INSERT INTO mail_outbox (id, prio, to_addr, subject, body, attempts, next_at, done_at, last_error, created_at) VALUES (?,?,?,?,?,0,?,NULL,NULL,?)', array( bin2hex( random_bytes( 16 ) ), $priority, $to, $subject, $body, $at, $at ) );
			return true;
		} catch ( \Throwable $e ) {
			error_log( '[portale] email non accodata: ' . get_class( $e ) );
			return false;
		}
	}

	private static function transmit( array $t, string $to, string $subject, string $body ): bool {
		if ( null !== self::$transport ) {
			return (bool) call_user_func( self::$transport, $t, $to, $subject, $body );
		}
		$to   = str_replace( array( "\r", "\n" ), '', $to );
		$from = str_replace( array( "\r", "\n" ), '', (string) ( $t['from'] ?? Config::get( 'mail_from', '' ) ) );
		if ( 'smtp' === $t['kind'] ) {
			return self::smtp( $t, $from, $to, $subject, $body );
		}
		$headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n" . ( '' !== $from ? 'From: Associazione Semplice <' . $from . ">\r\n" : '' );
		return mail( $to, '=?UTF-8?B?' . base64_encode( $subject ) . '?=', $body, $headers );
	}

	/** SMTP autenticato (porta 465 con TLS, o 587 con STARTTLS). Le credenziali non finiscono mai nei log: solo host e codice di risposta. */
	private static function smtp( array $t, string $from, string $to, string $subject, string $body ): bool {
		$host   = (string) $t['host'];
		$port   = (int) $t['port'];
		$secure = (string) $t['secure'];
		$fp     = @stream_socket_client( ( 'ssl' === $secure ? 'ssl://' : 'tcp://' ) . $host . ':' . $port, $errno, $errstr, 10 );
		if ( ! $fp ) {
			error_log( '[portale] SMTP ' . $host . ': connessione non riuscita' );
			return false;
		}
		stream_set_timeout( $fp, 15 );
		$last = '';
		$read = function () use ( $fp, &$last ) {
			$r = '';
			while ( false !== ( $l = fgets( $fp, 515 ) ) ) {
				$r .= $l;
				if ( strlen( $l ) < 4 || ' ' === $l[3] ) {
					break;
				}
			}
			$last = $r;
			return (int) substr( $r, 0, 3 );
		};
		$cmd = function ( ?string $c, array $ok ) use ( $fp, $read ) {
			if ( null !== $c ) {
				fwrite( $fp, $c . "\r\n" );
			}
			return in_array( $read(), $ok, true );
		};
		$name = (string) ( parse_url( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), PHP_URL_HOST ) ?: 'localhost' );
		$ok   = $cmd( null, array( 220 ) ) && $cmd( 'EHLO ' . $name, array( 250 ) );
		if ( $ok && 'tls' === $secure ) {
			$ok = $cmd( 'STARTTLS', array( 220 ) ) && stream_socket_enable_crypto( $fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) && $cmd( 'EHLO ' . $name, array( 250 ) );
		}
		if ( $ok && '' !== (string) ( $t['user'] ?? '' ) ) {
			$ok = $cmd( 'AUTH LOGIN', array( 334 ) ) && $cmd( base64_encode( (string) $t['user'] ), array( 334 ) ) && $cmd( base64_encode( (string) ( $t['pass'] ?? '' ) ), array( 235 ) );
		}
		$envelope = '' !== $from ? $from : (string) ( $t['user'] ?? '' );
		$ok       = $ok && $cmd( 'MAIL FROM:<' . $envelope . '>', array( 250 ) ) && $cmd( 'RCPT TO:<' . $to . '>', array( 250, 251 ) ) && $cmd( 'DATA', array( 354 ) );
		if ( $ok ) {
			$msg = 'From: Associazione Semplice <' . $envelope . ">\r\nTo: <" . $to . ">\r\nSubject: =?UTF-8?B?" . base64_encode( $subject ) . "?=\r\nDate: " . gmdate( 'r' ) . "\r\nMessage-ID: <" . bin2hex( random_bytes( 12 ) ) . '@' . $name . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
			$txt = preg_replace( '/\r\n|\r|\n/', "\r\n", $body );
			$txt = preg_replace( '/^\./m', '..', (string) $txt );
			fwrite( $fp, $msg . $txt . "\r\n.\r\n" );
			$ok = 250 === $read();
		}
		if ( ! $ok ) {
			error_log( '[portale] SMTP ' . $host . ': ' . substr( trim( $last ), 0, 3 ) ); // solo il codice di risposta
		}
		@fwrite( $fp, "QUIT\r\n" );
		@fclose( $fp );
		return $ok;
	}
}

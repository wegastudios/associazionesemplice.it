<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Coda breve delle email singole (conferme, codici di accesso, ricevute, avvisi alla segreteria) che il server di posta non ha accettato.
 *
 * Le email singole non sono mai fermate dal tetto orario ({@see MailBudget}); ma se il provider le rifiuta o le limita (tetto superato dalla sua parte),
 * non si perdono: restano qui e si riprovano dopo pochi minuti dal giro dei lavori (WP-Cron). Tentativi: il primo (subito) più tre di riprova,
 * dopo 3, 8 e 15 minuti; se anche l'ultimo fallisce l'email si dà per non inviata e resta traccia nel registro della posta e nell'avviso
 * del pannello. Le comunicazioni a gruppi e i promemoria hanno già la loro coda e non passano da qui.
 * Nessuno schema: la coda è un'opzione non caricata a ogni richiesta, con al massimo {@see MAX} messaggi.
 */
final class MailRetry {

	const HOOK     = 'asem_mail_retry';
	const OPTION   = 'asem_mail_retry_queue';
	const LOST     = 'asem_mail_retry_lost';
	const MAX      = 50;
	const DELAYS   = array( 180, 480, 900 ); // attesa prima di ogni riprova
	const MAX_BODY = 40000;
	const BUDGET   = 15; // secondi di un giro

	/** @var int|null solo per le prove: l'ora che si vuole simulare */
	public static $clock = null;

	private static function now(): int {
		return null !== self::$clock ? (int) self::$clock : time();
	}

	/** @return array[] */
	private static function queue(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$q = get_option( self::OPTION, array() );
		return is_array( $q ) ? array_values( $q ) : array();
	}

	private static function save( array $q ): void {
		if ( $q ) {
			update_option( self::OPTION, array_values( $q ), false );
		} else {
			delete_option( self::OPTION );
		}
	}

	/** Email in attesa di una nuova riprova. */
	public static function pending(): int {
		return count( self::queue() );
	}

	/** Email date per non inviate negli ultimi giorni (dopo tutte le riprove). */
	public static function lost( int $days = 7 ): int {
		$since = self::now() - $days * DAY_IN_SECONDS;
		$n     = 0;
		foreach ( (array) get_option( self::LOST, array() ) as $r ) {
			if ( is_array( $r ) && (int) ( $r['t'] ?? 0 ) >= $since ) {
				$n++;
			}
		}
		return $n;
	}

	/** Solo per le prove. */
	public static function reset(): void {
		delete_option( self::OPTION );
		delete_option( self::LOST );
		wp_clear_scheduled_hook( self::HOOK );
		self::$clock = null;
	}

	/**
	 * Mette in coda un'email che il server non ha accettato. @param string|string[] $to @param string|string[] $headers @param string|string[] $attachments
	 * @return bool messa in coda (false: destinatario non valido, allegato mancante, coda piena: non si riprova)
	 */
	public static function add( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
		$list = array_values( array_filter( array_map( 'trim', is_array( $to ) ? array_map( 'strval', $to ) : explode( ',', (string) $to ) ), 'strlen' ) );
		if ( ! $list ) {
			return false;
		}
		foreach ( $list as $addr ) {
			if ( ! is_email( preg_match( '/<([^>]+)>/', $addr, $m ) ? $m[1] : $addr ) ) {
				return false; // un indirizzo sbagliato non si sistema riprovando
			}
		}
		$files = array_values( array_filter( array_map( 'strval', (array) $attachments ), 'strlen' ) );
		foreach ( $files as $f ) {
			if ( ! is_readable( $f ) ) {
				return false;
			}
		}
		$q = self::queue();
		if ( count( $q ) >= self::MAX || strlen( $message ) > self::MAX_BODY ) {
			self::note_lost( $list[0], $subject );
			return false;
		}
		$q[] = array(
			'to'   => $list,
			's'    => $subject,
			'm'    => $message,
			'h'    => $headers,
			'a'    => $files,
			'n'    => 1,
			'next' => self::now() + self::DELAYS[0],
		);
		self::save( $q );
		self::schedule( self::now() + self::DELAYS[0] );
		return true;
	}

	private static function schedule( int $at ): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( false === $next || $next > $at + 60 ) {
			wp_schedule_single_event( max( $at, time() + 30 ), self::HOOK );
		}
	}

	private static function note_lost( string $to, string $subject ): void {
		$l   = array_values( array_filter( (array) get_option( self::LOST, array() ), 'is_array' ) );
		$l[] = array( 't' => self::now(), 'to' => MailLog::mask( $to ), 'what' => mb_substr( trim( $subject ), 0, 80 ) );
		update_option( self::LOST, array_slice( $l, -20 ), false );
	}

	/** Giro dei lavori: riprova le email dovute e programma il giro dopo se ne restano. @return int email partite */
	public static function run(): int {
		$q = self::queue();
		if ( ! $q ) {
			return 0;
		}
		$now = self::now();
		$due = array();
		foreach ( $q as $i => $e ) {
			if ( (int) $e['next'] <= $now ) {
				$due[]           = $i;
				$q[ $i ]['next'] = $now + 120; // preso in carico: se la richiesta si interrompe, non si manda due volte; si riprova tra poco
			}
		}
		if ( $due ) {
			self::save( $q );
		}
		$start = microtime( true );
		$sent  = 0;
		$keep  = array();
		foreach ( $q as $i => $e ) {
			if ( ! in_array( $i, $due, true ) ) {
				$keep[] = $e;
				continue;
			}
			if ( microtime( true ) - $start > self::BUDGET ) {
				$e['next'] = $now + 60; // tempo finito: tocca al giro dopo, senza contare un tentativo
				$keep[]    = $e;
				continue;
			}
			if ( self::deliver( $e ) ) {
				$sent++;
				continue;
			}
			if ( (int) $e['n'] >= count( self::DELAYS ) ) {
				self::note_lost( (string) $e['to'][0], (string) $e['s'] ); // anche l'ultimo tentativo è fallito: resta traccia nel registro e nell'avviso
				continue;
			}
			$e['next'] = $now + self::DELAYS[ (int) $e['n'] ];
			$e['n']    = (int) $e['n'] + 1;
			$keep[]    = $e;
		}
		if ( $due ) {
			$added = array_slice( self::queue(), count( $q ) ); // le email messe in coda da altre richieste nel frattempo restano
			self::save( array_merge( $keep, $added ) );
			MailBudget::flush();
			MailLog::flush();
		}
		$left = self::queue();
		if ( $left ) {
			self::schedule( min( array_map( static function ( $e ) { return (int) $e['next']; }, $left ) ) );
		}
		return $sent;
	}

	private static function deliver( array $e ): bool {
		foreach ( (array) $e['a'] as $f ) {
			if ( ! is_readable( (string) $f ) ) {
				return false;
			}
		}
		MailLog::begin();
		$ok = (bool) wp_mail( $e['to'], (string) $e['s'], (string) $e['m'], $e['h'], (array) $e['a'] );
		MailLog::record( $e['to'], (string) $e['s'], $ok );
		MailBudget::count( MailBudgetRules::recipients( $e['to'] ) );
		return $ok;
	}
}

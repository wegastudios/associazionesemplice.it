<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Verifica in due passaggi dell'area soci: dopo il link, il codice o la password serve anche il codice di un'app (TOTP, RFC 6238: 30 secondi,
 * 6 cifre, SHA1, si accetta il passo prima e quello dopo) oppure uno dei 10 codici di riserva. Spenta di partenza: ogni socio la accende per sé
 * da «Il mio profilo». Vale per i soci che entrano con l'accesso proprio (senza utente WordPress).
 *
 * Nel database: il segreto cifrato con la stessa cifratura delle chiavi dei pagamenti ({@see Secrets}), dei codici di riserva solo l'impronta
 * (con un sale per codice), l'ultimo passo di 30 secondi usato (un codice già usato non vale una seconda volta). Nelle copie di sicurezza e
 * nell'esportazione dei dati queste colonne non escono; cancellazione e anonimizzazione le svuotano.
 *
 * Al login la sessione non si apre subito: un cookie breve ({@see self::COOKIE}) ricorda chi ha superato il primo passaggio, e la pagina di
 * accesso chiede il codice. Chi ha perso il telefono usa un codice di riserva o si fa mandare per email un link (15 minuti, una volta sola)
 * che spegne la verifica; alla segreteria parte un avviso.
 */
final class TwoFactor {

	const PERIOD       = 30;  // secondi di un passo
	const DIGITS       = 6;
	const WINDOW       = 1;   // passi accettati prima e dopo quello di adesso (orologi un po' sfasati)
	const BACKUP_COUNT = 10;
	const BACKUP_LEN   = 10;  // cifre di un codice di riserva (si mostrano come 12345-67890)
	const COOKIE       = 'asem_tfa';
	const PENDING_TTL  = 600; // dieci minuti per scrivere il codice dopo il primo passaggio
	const RECOVERY_TTL = 900; // il link dell'email che spegne la verifica vale 15 minuti
	const SHOW_TTL     = 600; // i codici di riserva appena creati si possono vedere (una volta) per dieci minuti

	const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** @var int|null orario finto per le prove */
	public static $clock = null;

	private static function now(): int {
		return null !== self::$clock ? (int) self::$clock : time();
	}

	// ---------- Parte pura: base32, codici, indirizzo dell'app ----------

	public static function base32_encode( string $bin ): string {
		$bits = '';
		$len  = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $bin[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::B32[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/** @return string|null i byte; null se il testo non è base32 */
	public static function base32_decode( string $text ): ?string {
		$text = strtoupper( (string) preg_replace( '/[\s=-]+/', '', $text ) );
		if ( '' === $text || 1 !== preg_match( '/^[A-Z2-7]+$/D', $text ) ) {
			return null;
		}
		$bits = '';
		$len  = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( (int) strpos( self::B32, $text[ $i ] ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( (int) bindec( $byte ) );
			}
		}
		return $out;
	}

	/** Nuovo segreto: 20 byte casuali (160 bit, come chiede la RFC 4226), in base32 (32 caratteri). */
	public static function new_secret(): string {
		return self::base32_encode( random_bytes( 20 ) );
	}

	/** Codice HOTP (RFC 4226) per un contatore: 6 cifre. */
	public static function hotp( string $key, int $counter ): string {
		$msg  = pack( 'N2', ( $counter >> 32 ) & 0xFFFFFFFF, $counter & 0xFFFFFFFF );
		$h    = hash_hmac( 'sha1', $msg, $key, true );
		$o    = ord( $h[19] ) & 0x0f;
		$v    = ( ( ord( $h[ $o ] ) & 0x7f ) << 24 ) | ( ord( $h[ $o + 1 ] ) << 16 ) | ( ord( $h[ $o + 2 ] ) << 8 ) | ord( $h[ $o + 3 ] );
		return str_pad( (string) ( $v % ( 10 ** self::DIGITS ) ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	/** Il passo di 30 secondi di un orario. */
	public static function step( int $ts ): int {
		return intdiv( $ts, self::PERIOD );
	}

	/** Codice dell'app per un segreto base32 a un certo orario. */
	public static function code_at( string $secret, int $ts ): string {
		$key = self::base32_decode( $secret );
		return null === $key ? '' : self::hotp( $key, self::step( $ts ) );
	}

	/**
	 * Il codice vale adesso (passo di adesso, quello prima o quello dopo) ed è più recente dell'ultimo usato?
	 *
	 * @return int|null il passo che corrisponde; null se il codice non vale o è già stato usato
	 */
	public static function match_step( string $secret, string $code, int $ts, int $last_step ): ?int {
		$code = (string) preg_replace( '/\s+/', '', $code );
		$key  = self::base32_decode( $secret );
		if ( null === $key || 1 !== preg_match( '/^\d{' . self::DIGITS . '}$/D', $code ) ) {
			return null;
		}
		$now   = self::step( $ts );
		$found = null;
		for ( $d = -self::WINDOW; $d <= self::WINDOW; $d++ ) { // si provano tutti: il tempo non dice quale passo ha funzionato
			$s = $now + $d;
			if ( hash_equals( self::hotp( $key, $s ), $code ) && $s > $last_step && null === $found ) {
				$found = $s;
			}
		}
		return $found;
	}

	/** Indirizzo «otpauth://» da mostrare come QR all'app. Rimane dentro i 213 byte del QR: se l'etichetta è troppo lunga si accorcia. */
	public static function otpauth_uri( string $secret, string $issuer, string $account ): string {
		$issuer  = trim( (string) preg_replace( '/[:\s]+/u', ' ', $issuer ) );
		$issuer  = '' !== $issuer ? $issuer : 'Area soci';
		$account = trim( $account );
		foreach ( array( array( 40, 60 ), array( 24, 40 ), array( 16, 0 ) ) as $cut ) {
			$iss   = function_exists( 'mb_substr' ) ? mb_substr( $issuer, 0, $cut[0], 'UTF-8' ) : substr( $issuer, 0, $cut[0] );
			$acc   = $cut[1] > 0 ? substr( $account, 0, $cut[1] ) : '';
			$label = '' !== $acc ? $iss . ':' . $acc : $iss;
			$uri   = 'otpauth://totp/' . rawurlencode( $label ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $iss );
			if ( strlen( $uri ) <= QrCode::MAX_BYTES ) {
				return $uri;
			}
		}
		return 'otpauth://totp/' . rawurlencode( 'Area soci' ) . '?secret=' . $secret;
	}

	/** Il segreto a gruppi di 4 caratteri, da copiare a mano nell'app. */
	public static function grouped( string $secret ): string {
		return implode( ' ', str_split( $secret, 4 ) );
	}

	/** Toglie spazi e trattini da un codice scritto a mano. */
	public static function normalize( string $code ): string {
		return (string) preg_replace( '/[^0-9A-Za-z]+/', '', $code );
	}

	/** 10 codici di riserva nuovi (10 cifre ciascuno). @return string[] */
	public static function new_backup_codes(): array {
		$out = array();
		while ( count( $out ) < self::BACKUP_COUNT ) {
			$c = '';
			for ( $i = 0; $i < self::BACKUP_LEN; $i++ ) {
				$c .= (string) random_int( 0, 9 );
			}
			$out[ $c ] = $c;
		}
		return array_values( $out );
	}

	/** Come si mostra un codice di riserva: 12345-67890. */
	public static function show_backup( string $code ): string {
		return substr( $code, 0, 5 ) . '-' . substr( $code, 5 );
	}

	/** Impronta di un codice di riserva: «sale$hmac», con il segreto del sito (dall'impronta non si risale al codice). */
	public static function backup_hash( string $code, string $secret, string $salt = '' ): string {
		$salt = '' !== $salt ? $salt : bin2hex( random_bytes( 8 ) );
		return $salt . '$' . hash_hmac( 'sha256', 'tfa-backup:' . $salt . ':' . self::normalize( $code ), $secret );
	}

	/** @return int|null la posizione dell'impronta che corrisponde al codice; null se nessuna */
	public static function backup_match( array $hashes, string $code, string $secret ): ?int {
		$code  = self::normalize( $code );
		$found = null;
		if ( self::BACKUP_LEN !== strlen( $code ) || ! ctype_digit( $code ) ) {
			return null;
		}
		foreach ( array_values( $hashes ) as $i => $h ) {
			$parts = explode( '$', (string) $h, 2 );
			if ( 2 === count( $parts ) && hash_equals( (string) $h, self::backup_hash( $code, $secret, $parts[0] ) ) && null === $found ) {
				$found = $i;
			}
		}
		return $found;
	}

	// ---------- Dati della persona ----------

	private static function secret_key(): string {
		return wp_salt( 'auth' ); // come le chiavi dei pagamenti
	}

	private static function hmac_secret(): string {
		return MemberSession::secret() . '|tfa';
	}

	/** @return array{secret:string,on:bool,backup:array,backup_raw:string,last_step:int,on_at:string} */
	private static function state( int $person_id ): array {
		$db  = Db::db();
		$r   = $person_id > 0 && Plugin::schema_ready() ? $db->get_row( $db->prepare( 'SELECT tfa_secret, tfa_backup, tfa_on_at, tfa_last_step FROM ' . Db::t( 'people' ) . ' WHERE id = %d', $person_id ), ARRAY_A ) : null;
		$enc = $r ? (string) $r['tfa_secret'] : '';
		$sec = '' !== $enc ? (string) Secrets::decrypt( $enc, self::secret_key() ) : '';
		$bk  = $r && '' !== (string) $r['tfa_backup'] ? json_decode( (string) $r['tfa_backup'], true ) : array();
		return array(
			'secret'     => $sec,
			'on'         => $r && ! empty( $r['tfa_on_at'] ) && '' !== $sec,
			'backup'     => is_array( $bk ) ? array_values( array_map( 'strval', $bk ) ) : array(),
			'backup_raw' => $r ? (string) $r['tfa_backup'] : '',
			'last_step'  => $r ? (int) $r['tfa_last_step'] : 0,
			'on_at'      => $r ? (string) $r['tfa_on_at'] : '',
		);
	}

	/** La persona ha la verifica in due passaggi accesa? */
	public static function is_on( int $person_id ): bool {
		return $person_id > 0 && self::state( $person_id )['on'];
	}

	/** Da quando è accesa (UTC), vuoto se spenta. */
	public static function on_since( int $person_id ): string {
		$s = self::state( $person_id );
		return $s['on'] ? $s['on_at'] : '';
	}

	/** Codici di riserva ancora da usare. */
	public static function backup_left( int $person_id ): int {
		return count( self::state( $person_id )['backup'] );
	}

	/** Il segreto in attesa di conferma (accensione iniziata, primo codice non ancora scritto), oppure vuoto. */
	public static function pending_secret( int $person_id ): string {
		$s = self::state( $person_id );
		return $s['on'] ? '' : $s['secret'];
	}

	/**
	 * Inizia l'accensione: un segreto nuovo, salvato cifrato, che vale solo dopo la conferma del primo codice. Se un'accensione è già in corso
	 * si riprende quella (ricaricare la pagina non cambia il QR). @return string il segreto in base32
	 * @throws \InvalidArgumentException
	 */
	public static function start_setup( int $person_id ): string {
		$s = self::state( $person_id );
		if ( $s['on'] ) {
			throw new \InvalidArgumentException( 'La verifica in due passaggi è già accesa.' );
		}
		if ( '' !== $s['secret'] ) {
			return $s['secret'];
		}
		$secret = self::new_secret();
		Db::db()->update( Db::t( 'people' ), array( 'tfa_secret' => Secrets::encrypt( $secret, self::secret_key() ), 'tfa_backup' => null, 'tfa_on_at' => null, 'tfa_last_step' => 0 ), array( 'id' => $person_id ) );
		Audit::log( 'member.tfa_setup_started', 'person', $person_id );
		return $secret;
	}

	/** Lascia perdere un'accensione non confermata. */
	public static function cancel_setup( int $person_id ): void {
		if ( self::state( $person_id )['on'] ) {
			return;
		}
		self::forget( $person_id );
		Audit::log( 'member.tfa_setup_cancelled', 'person', $person_id );
	}

	/** Chiave del contatore dei codici sbagliati di una persona (finestra di 10 minuti). */
	private static function fail_key( int $person_id ): string {
		return 'asem_mt_tfa_' . $person_id;
	}

	/** Troppi codici sbagliati di recente? */
	public static function blocked( int $person_id ): bool {
		try {
			MemberThrottle::strike( self::fail_key( $person_id ), Limits::get( 'member_tfa_fail_per_10min' ), MemberThrottle::WINDOW, 0 );
		} catch ( \InvalidArgumentException $e ) {
			return true;
		}
		return false;
	}

	private static function count_fail( int $person_id, string $where ): void {
		MemberThrottle::add( self::fail_key( $person_id ), MemberThrottle::WINDOW );
		Audit::log( 'member.tfa_failed', 'person', $person_id, array( 'where' => $where ) );
	}

	/** Messaggio quando si è superato il limite. */
	const BLOCKED_MSG = 'Troppi codici sbagliati: aspetta 10 minuti e riprova.';

	/**
	 * Conferma l'accensione con il primo codice dell'app. @return string[] i 10 codici di riserva in chiaro (da mostrare una volta sola)
	 * @throws \InvalidArgumentException
	 */
	public static function confirm_setup( int $person_id, string $code ): array {
		if ( self::blocked( $person_id ) ) {
			throw new \InvalidArgumentException( self::BLOCKED_MSG ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$s = self::state( $person_id );
		if ( $s['on'] || '' === $s['secret'] ) {
			throw new \InvalidArgumentException( 'Ricomincia dall\'inizio: premi «Accendi la verifica in due passaggi».' );
		}
		$step = self::match_step( $s['secret'], $code, self::now(), 0 );
		if ( null === $step ) {
			self::count_fail( $person_id, 'setup' );
			throw new \InvalidArgumentException( 'Il codice non è corretto. Controlla che l\'ora del telefono sia giusta e scrivi il codice che vedi adesso nell\'app.' );
		}
		$codes = self::new_backup_codes();
		Db::db()->update( Db::t( 'people' ), array( 'tfa_on_at' => gmdate( 'Y-m-d H:i:s', self::now() ), 'tfa_last_step' => $step, 'tfa_backup' => self::hash_all( $codes ) ), array( 'id' => $person_id ) );
		Counters::delete( self::fail_key( $person_id ) );
		Audit::log( 'member.tfa_on', 'person', $person_id );
		self::keep_for_showing( $person_id, $codes );
		return $codes;
	}

	private static function hash_all( array $codes ): string {
		$secret = self::hmac_secret();
		return (string) wp_json_encode( array_map( function ( $c ) use ( $secret ) {
			return self::backup_hash( (string) $c, $secret );
		}, $codes ) );
	}

	/**
	 * Controlla un codice dell'app o un codice di riserva e lo consuma (un codice dell'app vale una volta sola, un codice di riserva sparisce).
	 *
	 * @return string «ok», «wrong» (sbagliato o già usato) o «blocked» (troppi errori di recente: non si controlla nemmeno)
	 */
	public static function check( int $person_id, string $code, string $where = 'login' ): string {
		if ( self::blocked( $person_id ) ) {
			return 'blocked';
		}
		$s = self::state( $person_id );
		if ( ! $s['on'] ) {
			return 'wrong';
		}
		$db   = Db::db();
		$tbl  = Db::t( 'people' );
		$norm = self::normalize( $code );
		if ( self::DIGITS === strlen( $norm ) ) {
			$step = self::match_step( $s['secret'], $norm, self::now(), $s['last_step'] );
			// Il passo si segna con una sola istruzione: due richieste con lo stesso codice non passano tutte e due.
			if ( null !== $step && 1 === (int) $db->query( $db->prepare( "UPDATE $tbl SET tfa_last_step = %d WHERE id = %d AND tfa_last_step < %d", $step, $person_id, $step ) ) ) {
				Counters::delete( self::fail_key( $person_id ) );
				return 'ok';
			}
		} else {
			$i = self::backup_match( $s['backup'], $norm, self::hmac_secret() );
			if ( null !== $i ) {
				$left = $s['backup'];
				array_splice( $left, $i, 1 );
				$won = $db->query( $db->prepare( "UPDATE $tbl SET tfa_backup = %s WHERE id = %d AND tfa_backup = %s", (string) wp_json_encode( array_values( $left ) ), $person_id, $s['backup_raw'] ) );
				if ( 1 === (int) $won ) {
					Counters::delete( self::fail_key( $person_id ) );
					Audit::log( 'member.tfa_backup_used', 'person', $person_id, array( 'left' => count( $left ) ) );
					return 'ok';
				}
			}
		}
		self::count_fail( $person_id, $where );
		return 'wrong';
	}

	/** Messaggio unico per un codice che non va. */
	const WRONG_MSG = 'Il codice non è corretto o è già stato usato. Scrivi il codice che vedi adesso nell\'app, oppure un codice di riserva.';

	/** @throws \InvalidArgumentException se il codice non va */
	private static function require_code( int $person_id, string $code, string $where ): void {
		$r = self::check( $person_id, $code, $where );
		if ( 'blocked' === $r ) {
			throw new \InvalidArgumentException( self::BLOCKED_MSG ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'ok' !== $r ) {
			throw new \InvalidArgumentException( self::WRONG_MSG ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Il socio la spegne da «Il mio profilo»: serve un codice valido (chi ha solo rubato la sessione non può spegnerla). @throws \InvalidArgumentException */
	public static function turn_off( int $person_id, string $code ): void {
		if ( ! self::is_on( $person_id ) ) {
			return;
		}
		self::require_code( $person_id, $code, 'off' );
		self::forget( $person_id );
		Audit::log( 'member.tfa_off', 'person', $person_id, array( 'how' => 'code' ) );
	}

	/** Nuovi codici di riserva al posto dei vecchi (che smettono di valere): serve un codice valido. @return string[] @throws \InvalidArgumentException */
	public static function regenerate_backup( int $person_id, string $code ): array {
		if ( ! self::is_on( $person_id ) ) {
			throw new \InvalidArgumentException( 'La verifica in due passaggi è spenta.' );
		}
		self::require_code( $person_id, $code, 'regen' );
		$codes = self::new_backup_codes();
		Db::db()->update( Db::t( 'people' ), array( 'tfa_backup' => self::hash_all( $codes ) ), array( 'id' => $person_id ) );
		Audit::log( 'member.tfa_backup_regenerated', 'person', $person_id );
		self::keep_for_showing( $person_id, $codes );
		return $codes;
	}

	/** Svuota tutto (spegnimento, cancellazione e anonimizzazione della persona). */
	public static function forget( int $person_id ): void {
		if ( ! Plugin::schema_ready() ) {
			return;
		}
		Db::db()->update( Db::t( 'people' ), array( 'tfa_secret' => null, 'tfa_backup' => null, 'tfa_on_at' => null, 'tfa_last_step' => 0 ), array( 'id' => $person_id ) );
		Counters::delete( self::show_key( $person_id ) );
	}

	// ---------- I codici di riserva appena creati: si vedono una volta sola ----------

	private static function show_key( int $person_id ): string {
		return 'asem_tfa_show_' . $person_id;
	}

	private static function keep_for_showing( int $person_id, array $codes ): void {
		Counters::set( self::show_key( $person_id ), array( 'c' => Secrets::encrypt( implode( ',', $codes ), self::secret_key() ) ), self::SHOW_TTL ); // cifrati, e solo per pochi minuti
	}

	/** I codici di riserva appena creati, da mostrare: dopo questa lettura non si vedono più. @return string[] */
	public static function take_new_codes( int $person_id ): array {
		$v = Counters::get( self::show_key( $person_id ) );
		if ( ! is_array( $v ) || empty( $v['c'] ) ) {
			return array();
		}
		Counters::delete( self::show_key( $person_id ) );
		$plain = Secrets::decrypt( (string) $v['c'], self::secret_key() );
		return null === $plain || '' === $plain ? array() : explode( ',', $plain );
	}

	// ---------- Al login: lo stato intermedio fra il primo passaggio e il codice ----------

	private static function pending_key( string $raw ): string {
		return 'asem_tfa_p_' . substr( SessionToken::hash( $raw ), 0, 40 );
	}

	private static function cookie(): string {
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return SessionToken::is_well_formed( $c ) ? $c : '';
	}

	private static function set_cookie( string $value, int $expires ): void {
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => $expires,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$_COOKIE[ self::COOKIE ] = $value;
		}
	}

	/** Primo passaggio superato: niente sessione, solo il cookie breve che porta alla domanda del codice. */
	public static function begin_login( int $person_id, string $how, bool $remember ): void {
		$raw = SessionToken::generate();
		Counters::set( self::pending_key( $raw ), array( 'p' => $person_id, 'r' => $remember ? 1 : 0, 'h' => substr( sanitize_key( $how ), 0, 20 ) ), self::PENDING_TTL );
		self::set_cookie( $raw, time() + self::PENDING_TTL );
		Audit::log( 'member.tfa_asked', 'person', $person_id, array( 'how' => $how ) );
	}

	/** Chi ha superato il primo passaggio e deve scrivere il codice (null se nessuno). @return array{p:int,r:int,h:string}|null */
	public static function pending(): ?array {
		$raw = self::cookie();
		if ( '' === $raw ) {
			return null;
		}
		$v = Counters::get( self::pending_key( $raw ) );
		if ( ! is_array( $v ) || empty( $v['p'] ) ) {
			return null;
		}
		$p = Plugin::people()->get( (int) $v['p'] );
		if ( ! MemberSession::person_allowed( $p ) || ! self::is_on( (int) $v['p'] ) ) {
			return null;
		}
		return array( 'p' => (int) $v['p'], 'r' => (int) ( $v['r'] ?? 0 ), 'h' => (string) ( $v['h'] ?? '' ) );
	}

	/** Lascia perdere il passaggio a metà (pulsante «Annulla» o login finito). */
	public static function drop_pending(): void {
		$raw = self::cookie();
		if ( '' !== $raw ) {
			Counters::delete( self::pending_key( $raw ) );
		}
		self::set_cookie( '', time() - HOUR_IN_SECONDS );
	}

	/**
	 * Secondo passaggio: con il codice giusto si apre la sessione.
	 *
	 * @return string «ok» (sessione aperta), «wrong», «blocked» o «expired» (nessun passaggio a metà: si ricomincia dall'accesso)
	 */
	public static function finish_login( string $code ): string {
		$pend = self::pending();
		if ( ! $pend ) {
			return 'expired';
		}
		$r = self::check( $pend['p'], $code, 'login' );
		if ( 'ok' !== $r ) {
			return $r;
		}
		self::drop_pending();
		MemberLogin::open( $pend['p'], '' !== $pend['h'] ? $pend['h'] . '+tfa' : 'tfa', 1 === $pend['r'], true );
		return 'ok';
	}

	// ---------- Ripristino via email: un link che spegne la verifica ----------

	private static function recovery_key( string $raw ): string {
		return 'asem_tfa_r_' . substr( SessionToken::hash( $raw ), 0, 40 );
	}

	/**
	 * Manda all'indirizzo della scheda il link che spegne la verifica. Si chiede solo dal passaggio a metà (dopo link, codice o password):
	 * nessuno può farlo partire scrivendo un indirizzo qualunque. @return bool true se l'email è partita (o è in coda)
	 */
	public static function request_recovery(): bool {
		$pend = self::pending();
		if ( ! $pend ) {
			return false;
		}
		$p = Plugin::people()->get( $pend['p'] );
		if ( ! $p || '' === (string) $p['email'] ) {
			return false;
		}
		$mail_key = 'asem_tfa_rm_' . (int) $p['id'];
		$sent     = (int) Counters::get( $mail_key );
		if ( $sent >= Limits::get( 'first_access_per_email' ) ) {
			return false;
		}
		Counters::set( $mail_key, $sent + 1, HOUR_IN_SECONDS );
		$raw = SessionToken::generate();
		Counters::set( self::recovery_key( $raw ), array( 'p' => (int) $p['id'] ), self::RECOVERY_TTL );
		Audit::log( 'member.tfa_recovery_sent', 'person', (int) $p['id'] );
		$assoc = (string) Settings::get( 'association_name' );
		$link  = add_query_arg( 'asem_tfa_off', $raw, Frontend\MemberLoginPage::url() ); // la pagina di accesso dei soci, con il link
		$text  = 'Ciao ' . $p['first_name'] . ",\n\nhai chiesto di spegnere la verifica in due passaggi dell'area soci" . ( '' !== $assoc ? ' di ' . $assoc : '' ) . ". Apri questo link e conferma:\n\n" . $link
			. "\n\nIl link vale " . (int) ( self::RECOVERY_TTL / 60 ) . " minuti e si usa una volta sola. Dopo potrai entrare come prima e riaccendere la verifica da «Il mio profilo».\n\nSe non l'hai chiesto tu, ignora questo messaggio e scrivi alla segreteria: qualcuno conosce la tua password o ha aperto la tua email.";
		return ResponseMail::send( (string) $p['email'], Texts::subject( 'Spegni la verifica in due passaggi' ), $text );
	}

	/** Il link dell'email vale ancora? (solo per mostrare la pagina di conferma: non lo consuma) */
	public static function recovery_valid( string $raw ): bool {
		if ( ! SessionToken::is_well_formed( $raw ) ) {
			return false;
		}
		$v = Counters::get( self::recovery_key( $raw ) );
		return is_array( $v ) && ! empty( $v['p'] );
	}

	/** Consuma il link una volta sola. @return int la persona, 0 se il link non vale */
	private static function claim_recovery( string $raw ): int {
		$key = self::recovery_key( $raw );
		$v   = Counters::get( $key );
		if ( ! is_array( $v ) || empty( $v['p'] ) ) {
			return 0;
		}
		if ( Counters::uses_table() ) { // la riga si toglie con una sola istruzione: due clic insieme non lo usano due volte
			$db  = Db::db();
			$won = $db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'counters' ) . ' WHERE k = %s AND expires > %d', $key, time() ) );
			if ( 1 !== (int) $won ) {
				return 0;
			}
		} else {
			Counters::delete( $key );
		}
		return (int) $v['p'];
	}

	/**
	 * Spegne la verifica dal link dell'email: registro, avviso alla segreteria ed email all'interessato. @return bool true se spenta
	 */
	public static function use_recovery( string $raw ): bool {
		if ( ! SessionToken::is_well_formed( $raw ) ) {
			return false;
		}
		$pid = self::claim_recovery( $raw );
		if ( $pid <= 0 ) {
			return false;
		}
		$p = Plugin::people()->get( $pid );
		if ( ! $p || ! self::is_on( $pid ) ) {
			return false;
		}
		self::forget( $pid );
		MemberSession::destroy_all( $pid ); // spenta dall'email: chi era dentro altrove rientra
		Audit::log( 'member.tfa_off', 'person', $pid, array( 'how' => 'email' ) );
		$name  = trim( (string) $p['first_name'] . ' ' . (string) $p['last_name'] );
		$admin = trim( (string) Applications::notify_email() );
		if ( is_email( $admin ) ) {
			ResponseMail::send(
				$admin,
				Texts::subject( 'Verifica in due passaggi spenta da un socio' ),
				$name . ' (scheda n. ' . $pid . ') ha spento la verifica in due passaggi dell\'area soci con il link ricevuto per email, il ' . wp_date( 'j/n/Y \a\l\l\e H:i' ) . ".\n\nDi solito succede quando si cambia o si perde il telefono. Se ti sembra strano, contatta il socio: può riaccenderla da «Il mio profilo»."
			);
		}
		if ( '' !== (string) $p['email'] ) {
			ResponseMail::send(
				(string) $p['email'],
				Texts::subject( 'La verifica in due passaggi è spenta' ),
				'Ciao ' . $p['first_name'] . ",\n\nla verifica in due passaggi della tua area soci è stata spenta con il link che ti abbiamo mandato. Ora entri come prima; per riaccenderla vai in «Il mio profilo».\n\nSe non sei stato tu, scrivi subito alla segreteria."
			);
		}
		return true;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le chiavi con cui si cifrano i file riservati.
 *
 * - Chiave dei dati (DEK): 32 byte casuali, creata dal plugin alla prima necessità. Cifra i file e i campi. Non sta mai in chiaro nel database:
 *   è avvolta da una chiave principale.
 * - Chiave principale: se in wp-config.php c'è la costante `ASEM_ENC_KEY` (consigliata) viene da lì; altrimenti si ricava dai codici di sicurezza
 *   di WordPress (come per le chiavi dei pagamenti). Se cambia, la chiave dei dati si avvolge di nuovo senza ricifrare i file, purché la vecchia sia nota.
 * - Identificativo del sito: serve a legare ogni file al suo sito (dati associati della cifratura). Viaggia nella copia di sicurezza.
 *
 * Con i soli codici di sicurezza (nessuna costante) chi ha il database e wp-config.php può aprire i file: la protezione è parziale. La costante, tenuta
 * fuori dal database, la completa.
 */
final class FileKey {

	const OPT_KEYS = 'asem_file_keys';
	const OPT_SITE = 'asem_site_id';
	const CONSTANT = 'ASEM_ENC_KEY';
	const KDF_OPS  = 2;         // SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
	const KDF_MEM  = 67108864;  // SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE (64 MiB)
	const PHRASE_MIN = 10;

	/** @var array|null */
	private static $cache = null;

	/** Dimentica le chiavi lette (dopo un cambio, nei test). */
	public static function reset(): void {
		self::$cache = null;
	}

	// ---------- Chiave principale ----------

	/** Chiave principale dalla costante, se c'è ed è abbastanza lunga (almeno 16 caratteri, oppure 64 cifre esadecimali già pronte). */
	private static function constant_key(): ?string {
		if ( ! defined( self::CONSTANT ) ) {
			return null;
		}
		$v = constant( self::CONSTANT );
		if ( ! is_string( $v ) || strlen( $v ) < 16 ) {
			return null;
		}
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/i', $v ) ) {
			return (string) hex2bin( $v );
		}
		return FileCrypt::master_from_material( 'const|' . $v );
	}

	private static function salt_key(): string {
		return FileCrypt::master_from_material( 'salt|' . wp_salt( 'auth' ) );
	}

	/** @return array<string,string> chiavi principali disponibili: 'const' (se c'è) e 'salt' */
	private static function masters(): array {
		$m = array();
		$c = self::constant_key();
		if ( null !== $c ) {
			$m['const'] = $c;
		}
		$m['salt'] = self::salt_key();
		return $m;
	}

	/** Da dove viene la chiave principale in uso: 'const' o 'salt'. */
	public static function source(): string {
		return null !== self::constant_key() ? 'const' : 'salt';
	}

	/** La costante c'è ma è troppo corta per essere usata. */
	public static function constant_weak(): bool {
		return defined( self::CONSTANT ) && null === self::constant_key();
	}

	/** I codici di sicurezza di wp-config.php mancano o sono deboli, e non c'è la costante: chiave principale debole. */
	public static function salts_weak(): bool {
		return null === self::constant_key() && Secrets::salts_weak( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : null, defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : null );
	}

	// ---------- Identificativo del sito ----------

	public static function site_id(): string {
		$s = (string) get_option( self::OPT_SITE, '' );
		if ( 1 === preg_match( '/^[0-9a-f]{16}$/', $s ) ) {
			return $s;
		}
		$new = bin2hex( random_bytes( 8 ) );
		add_option( self::OPT_SITE, $new, '', 'no' );
		$s = (string) get_option( self::OPT_SITE, '' );
		return 1 === preg_match( '/^[0-9a-f]{16}$/', $s ) ? $s : $new;
	}

	/** Dopo un ripristino: il sito prende l'identificativo della copia (i file della copia sono legati a quello). */
	public static function adopt_site( string $id ): void {
		if ( 1 === preg_match( '/^[0-9a-f]{16}$/', $id ) ) {
			update_option( self::OPT_SITE, $id, false );
		}
	}

	// ---------- Chiavi dei dati ----------

	private static function option(): array {
		$o = get_option( self::OPT_KEYS );
		if ( ! is_array( $o ) || empty( $o['keys'] ) || ! is_array( $o['keys'] ) ) {
			return array( 'current' => 0, 'keys' => array() );
		}
		return array( 'current' => (int) ( $o['current'] ?? 0 ), 'keys' => $o['keys'] );
	}

	/** Ci sono chiavi salvate (anche se su questo sito non si aprono)? */
	public static function has_keys(): bool {
		return (bool) self::option()['keys'];
	}

	/**
	 * Apre le chiavi salvate. Se una chiave si apre con una chiave principale diversa da quella preferita (ad esempio è stata appena aggiunta
	 * la costante in wp-config.php) la si avvolge di nuovo con quella preferita.
	 *
	 * @return array{deks:array<int,string>,locked:int[],current:int,state:string} state: nosodium, none, ok, mismatch
	 */
	private static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out = array( 'deks' => array(), 'locked' => array(), 'current' => 0, 'state' => 'none' );
		if ( ! FileCrypt::available() ) {
			$out['state'] = 'nosodium';
			self::$cache  = $out;
			return $out;
		}
		$o = self::option();
		if ( ! $o['keys'] ) {
			self::$cache = $out;
			return $out;
		}
		$masters = self::masters();
		$pref    = self::source();
		$order   = array( $pref => $masters[ $pref ] ) + $masters;
		$changed = false;
		foreach ( $o['keys'] as $id => $k ) {
			$id  = (int) $id;
			$dek = null;
			$by  = '';
			foreach ( $order as $name => $mk ) {
				$d = FileCrypt::unwrap_key( (string) ( $k['w'] ?? '' ), $mk );
				if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
					$dek = $d;
					$by  = (string) $name;
					break;
				}
			}
			if ( null === $dek ) {
				$out['locked'][] = $id;
				continue;
			}
			$out['deks'][ $id ] = $dek;
			if ( $by !== $pref ) {
				$o['keys'][ $id ]['w']  = FileCrypt::wrap_key( $dek, $masters[ $pref ] );
				$o['keys'][ $id ]['by'] = $pref;
				$changed                = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPT_KEYS, $o, false );
		}
		$out['current'] = (int) $o['current'];
		$out['state']   = isset( $out['deks'][ $out['current'] ] ) ? 'ok' : 'mismatch';
		self::$cache    = $out;
		return $out;
	}

	private static function proof( string $dek, int $id ): string {
		return FileCrypt::encrypt_field( 'asem-prova', $dek, $id, 'as-proof' );
	}

	/**
	 * Crea una chiave dei dati. Con $first lo fa una sola volta anche se due richieste arrivano insieme.
	 *
	 * @param array|null $rec chiave di recupero e metodo con cui si ricava dalla frase: array( 'key' => ..., 'kdf' => ... )
	 */
	private static function create( bool $first, ?array $rec = null ): void {
		$dek   = random_bytes( 32 );
		$id    = FileCrypt::key_id( $dek );
		$pref  = self::source();
		$entry = array( 'w' => FileCrypt::wrap_key( $dek, self::masters()[ $pref ] ), 'by' => $pref, 'at' => time(), 'p' => self::proof( $dek, $id ) );
		if ( null !== $rec ) {
			$entry['r'] = array( 'w' => FileCrypt::wrap_key( $dek, $rec['key'] ), 'k' => $rec['kdf'] );
		}
		if ( $first ) {
			add_option( self::OPT_KEYS, array( 'current' => $id, 'keys' => array( $id => $entry ) ), '', 'no' ); // se un'altra richiesta l'ha già creata, resta la sua
		} else {
			$o                 = self::option();
			$o['keys'][ $id ]  = $entry;
			$o['current']      = $id;
			update_option( self::OPT_KEYS, $o, false );
		}
		self::$cache = null;
	}

	/** La chiave in uso ha la frase di recupero? */
	public static function has_recovery(): bool {
		$o = self::option();
		$k = $o['keys'][ $o['current'] ] ?? null;
		return is_array( $k ) && ! empty( $k['r']['w'] ) && ! empty( $k['r']['k'] );
	}

	/**
	 * Come si salvano i nuovi file: 'encrypt' (cifrati), 'plain' (manca la libreria sodium: in chiaro, con avviso), 'blocked' (la chiave salvata non si
	 * apre con la chiave principale di questo sito: i nuovi file non si salvano finché non si sistema) o 'setup' (manca la frase di recupero: la chiave
	 * si crea solo scegliendola, e i nuovi file non si salvano finché non c'è).
	 */
	public static function mode(): string {
		if ( ! FileCrypt::available() ) {
			return 'plain';
		}
		$st = self::load()['state'];
		if ( 'mismatch' === $st ) {
			return 'blocked';
		}
		return 'ok' === $st && self::has_recovery() ? 'encrypt' : 'setup';
	}

	/** Situazione senza creare nulla: nosodium, none, ok o mismatch. */
	public static function state(): string {
		return self::load()['state'];
	}

	/** @return array{id:int,key:string}|null chiave per cifrare i nuovi file */
	public static function current(): ?array {
		$l = self::load();
		return 'ok' === $l['state'] ? array( 'id' => $l['current'], 'key' => $l['deks'][ $l['current'] ] ) : null;
	}

	/** Chiave dei dati con questo identificativo, se si apre su questo sito. */
	public static function key_for( int $id ): ?string {
		$l = self::load();
		return $l['deks'][ $id ] ?? null;
	}

	/**
	 * Controllo «chiave valida»: tutte le chiavi si aprono e il blocco di prova si decifra.
	 *
	 * @return array{state:string,valid:bool,total:int,open:int,locked:int}
	 */
	public static function check(): array {
		$l = self::load();
		$o = self::option();
		$proof_ok = true;
		foreach ( $l['deks'] as $id => $dek ) {
			$p = (string) ( $o['keys'][ $id ]['p'] ?? '' );
			if ( '' !== $p ) {
				try {
					if ( 'asem-prova' !== FileCrypt::decrypt_field( $p, array( __CLASS__, 'key_for' ), 'as-proof' ) ) {
						$proof_ok = false;
					}
				} catch ( FileCryptError $e ) {
					$proof_ok = false;
				}
			}
		}
		return array(
			'state'  => $l['state'],
			'valid'  => 'ok' === $l['state'] && ! $l['locked'] && $proof_ok,
			'total'  => count( $o['keys'] ),
			'open'   => count( $l['deks'] ),
			'locked' => count( $l['locked'] ),
		);
	}

	/**
	 * Le chiavi salvate non si aprono più (codici di sicurezza o costante cambiati): con i vecchi valori si riaprono e si avvolgono con la chiave
	 * principale di adesso, senza ricifrare nessun file.
	 *
	 * @param string $old_const    vecchio valore di ASEM_ENC_KEY (vuoto se non c'era)
	 * @param string $old_auth_key vecchio AUTH_KEY
	 * @param string $old_auth_salt vecchio AUTH_SALT
	 * @return int chiavi recuperate
	 * @throws \InvalidArgumentException
	 */
	public static function recover( string $old_const, string $old_auth_key, string $old_auth_salt ): int {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'Questo server non ha la libreria di cifratura (sodium).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$cands = array();
		$old_const = trim( $old_const );
		if ( '' !== $old_const ) {
			$cands[] = 1 === preg_match( '/^[0-9a-f]{64}$/i', $old_const ) ? (string) hex2bin( $old_const ) : FileCrypt::master_from_material( 'const|' . $old_const );
		}
		$old_auth_key  = trim( $old_auth_key );
		$old_auth_salt = trim( $old_auth_salt );
		if ( '' !== $old_auth_key || '' !== $old_auth_salt ) {
			$cands[] = FileCrypt::master_from_material( 'salt|' . $old_auth_key . $old_auth_salt );
		}
		$l = self::load();
		$o = self::option();
		$m = self::masters();
		$n = 0;
		foreach ( $l['locked'] as $id ) {
			foreach ( $cands as $mk ) {
				$d = FileCrypt::unwrap_key( (string) ( $o['keys'][ $id ]['w'] ?? '' ), $mk );
				if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
					$o['keys'][ $id ]['w']  = FileCrypt::wrap_key( $d, $m[ self::source() ] );
					$o['keys'][ $id ]['by'] = self::source();
					$n++;
					break;
				}
			}
		}
		if ( 0 === $n ) {
			throw new \InvalidArgumentException( Texts::t( 'Con questi valori la chiave non si apre: controlla di aver incollato proprio quelli usati in precedenza.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		update_option( self::OPT_KEYS, $o, false );
		self::$cache = null;
		return $n;
	}

	/** Ricomincia con una chiave nuova: i file già cifrati con quella precedente restano illeggibili finché la vecchia chiave non torna disponibile. */
	public static function start_new(): void {
		if ( FileCrypt::available() ) {
			self::create( false );
		}
	}

	// ---------- Frase di recupero ----------

	const RECOVERY_MIN = 12;

	/** Controlla la frase di recupero. @return string vuoto se va bene, altrimenti il perché */
	public static function recovery_error( string $phrase, string $again ): string {
		if ( mb_strlen( $phrase ) < self::RECOVERY_MIN ) {
			return Texts::t( 'La frase di recupero deve avere almeno {n} caratteri.', array( 'n' => self::RECOVERY_MIN ) );
		}
		if ( $phrase !== $again ) {
			return Texts::t( 'Le due frasi di recupero non coincidono.' );
		}
		return '';
	}

	/**
	 * Sceglie la frase di recupero: la chiave dei dati si avvolge una seconda volta, dentro il database, con una chiave ricavata dalla frase. Se non c'è
	 * ancora nessuna chiave dei dati, la si crea qui (è l'unico modo: la cifratura parte solo dopo aver scelto la frase). Con una frase già scelta, la
	 * sostituisce per le chiavi che si aprono su questo sito.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function set_recovery( string $phrase, string $again ): void {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'Questo server non ha la libreria di cifratura (sodium).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$err = self::recovery_error( $phrase, $again );
		if ( '' !== $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$l = self::load();
		if ( 'mismatch' === $l['state'] ) {
			throw new \InvalidArgumentException( Texts::t( 'La chiave non si apre su questo sito: prima va sistemata (sezione «Sistemare la chiave»).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		try {
			$rec = FileCrypt::kdf_make( $phrase );
		} catch ( FileCryptError $e ) {
			throw new \InvalidArgumentException( Texts::t( 'La frase non si è potuta elaborare su questo server: riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'none' === $l['state'] ) {
			self::create( true, $rec );
			if ( ! self::has_recovery() ) { // un'altra richiesta aveva già creato la chiave: si aggiunge la frase a quella
				self::$cache = null;
				$l           = self::load();
			} else {
				return;
			}
		}
		$o = self::option();
		foreach ( $l['deks'] as $id => $dek ) {
			$o['keys'][ $id ]['r'] = array( 'w' => FileCrypt::wrap_key( $dek, $rec['key'] ), 'k' => $rec['kdf'] );
		}
		update_option( self::OPT_KEYS, $o, false );
		self::$cache = null;
	}

	/**
	 * Le chiavi salvate non si aprono più (codici di sicurezza o costante cambiati): con la frase di recupero si riaprono e si avvolgono con la chiave
	 * principale di adesso, senza ricifrare nessun file.
	 *
	 * @return int chiavi recuperate
	 * @throws \InvalidArgumentException
	 */
	public static function recover_with_phrase( string $phrase ): int {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'Questo server non ha la libreria di cifratura (sodium).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$l     = self::load();
		$o     = self::option();
		$m     = self::masters();
		$n     = 0;
		$cache = array();
		foreach ( $l['locked'] as $id ) {
			$r = $o['keys'][ $id ]['r'] ?? null;
			if ( ! is_array( $r ) || empty( $r['w'] ) || empty( $r['k'] ) || ! is_array( $r['k'] ) ) {
				continue;
			}
			$ck = (string) wp_json_encode( $r['k'] );
			try {
				if ( ! isset( $cache[ $ck ] ) ) {
					$cache[ $ck ] = FileCrypt::kdf_derive( $phrase, $r['k'] );
				}
			} catch ( FileCryptError $e ) {
				continue;
			}
			$d = FileCrypt::unwrap_key( (string) $r['w'], $cache[ $ck ] );
			if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
				$o['keys'][ $id ]['w']  = FileCrypt::wrap_key( $d, $m[ self::source() ] );
				$o['keys'][ $id ]['by'] = self::source();
				$n++;
			}
		}
		if ( 0 === $n ) {
			throw new \InvalidArgumentException( Texts::t( 'Con questa frase la chiave non si apre: controlla di averla scritta proprio come l\'avevi scelta.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		update_option( self::OPT_KEYS, $o, false );
		self::$cache = null;
		return $n;
	}

	// ---------- Frase segreta della copia di sicurezza ----------

	/** Controlla la frase scelta per una copia. @return string vuoto se va bene, altrimenti il perché */
	public static function phrase_error( string $phrase, string $again ): string {
		if ( mb_strlen( $phrase ) < self::PHRASE_MIN ) {
			return Texts::t( 'La frase segreta deve avere almeno {n} caratteri.', array( 'n' => self::PHRASE_MIN ) );
		}
		if ( $phrase !== $again ) {
			return Texts::t( 'Le due frasi segrete non coincidono.' );
		}
		return '';
	}

	/**
	 * Le chiavi per una copia di sicurezza: avvolte con la chiave principale di questo sito e, se c'è una frase, anche con la chiave ricavata dalla frase.
	 *
	 * @return array vuoto se non ci sono chiavi
	 * @throws FileCryptError
	 */
	public static function bundle( ?string $phrase ): array {
		$o = self::option();
		if ( ! $o['keys'] ) {
			return array();
		}
		$want = null !== $phrase && '' !== $phrase;
		if ( $want && ! FileCrypt::available() ) {
			throw new FileCryptError( FileCryptError::NO_SODIUM ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- solo un codice interno, mai mostrato così com'è
		}
		$l   = $want ? self::load() : array( 'deks' => array() );
		$out = array( 'v' => 1, 'site' => self::site_id(), 'current' => $o['current'], 'keys' => array() );
		$pk  = null;
		if ( null !== $phrase && '' !== $phrase ) {
			$made       = FileCrypt::kdf_make( $phrase ); // il metodo (Argon2id o, con poca memoria, uno più leggero) si scrive nella copia
			$pk         = $made['key'];
			$out['kdf'] = $made['kdf'];
		}
		foreach ( $o['keys'] as $id => $k ) {
			$e = array( 'm' => (string) ( $k['w'] ?? '' ), 'mb' => (string) ( $k['by'] ?? 'salt' ) );
			if ( ! empty( $k['r']['w'] ) && ! empty( $k['r']['k'] ) ) {
				$e['r'] = array( 'w' => (string) $k['r']['w'], 'k' => $k['r']['k'] ); // la frase di recupero del sito viaggia con la copia
			}
			if ( null !== $pk && isset( $l['deks'][ (int) $id ] ) ) {
				$e['p'] = FileCrypt::wrap_key( $l['deks'][ (int) $id ], $pk );
			}
			$out['keys'][ (int) $id ] = $e;
		}
		return $out;
	}

	/** La copia ha una frase segreta? */
	public static function bundle_has_phrase( array $b ): bool {
		return ! empty( $b['kdf'] ) && is_array( $b['kdf'] );
	}

	/**
	 * Prima di un ripristino: ottiene le chiavi dei dati della copia (da questo sito, dalla chiave principale o dalla frase) e le aggiunge a quelle del
	 * sito. Non cambia l'identificativo del sito: lo si adotta dopo, a ripristino riuscito, con {@see adopt_site()}.
	 *
	 * @param array $b copia di `chiavi.json`
	 * @return int[] identificativi delle chiavi della copia
	 * @throws \InvalidArgumentException
	 */
	public static function adopt_keys( array $b, string $phrase ): array {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'La copia contiene file cifrati e questo server non ha la libreria di cifratura (sodium): non si può ripristinare qui.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 1 !== (int) ( $b['v'] ?? 0 ) || empty( $b['keys'] ) || ! is_array( $b['keys'] ) || 1 !== preg_match( '/^[0-9a-f]{16}$/', (string) ( $b['site'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Le chiavi contenute nella copia non sono valide.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$l       = self::load();
		$masters = self::masters();
		$pk      = null;
		$got     = array();
		$no_phrase = false;
		foreach ( $b['keys'] as $id => $k ) {
			$id = (int) $id;
			if ( isset( $l['deks'][ $id ] ) ) {
				continue; // già nota a questo sito
			}
			$dek = null;
			foreach ( $masters as $mk ) {
				$d = FileCrypt::unwrap_key( (string) ( $k['m'] ?? '' ), $mk );
				if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
					$dek = $d;
					break;
				}
			}
			if ( null === $dek && ! empty( $k['p'] ) && self::bundle_has_phrase( $b ) ) {
				if ( '' === $phrase ) {
					$no_phrase = true;
					continue;
				}
				if ( null === $pk ) {
					try {
						$pk = FileCrypt::kdf_derive( $phrase, (array) $b['kdf'] ); // legge dalla copia quale metodo si è usato
					} catch ( FileCryptError $e ) {
						throw new \InvalidArgumentException( Texts::t( 'La frase segreta non si può elaborare su questo server (manca la memoria o la libreria): il ripristino qui non è possibile.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
					}
				}
				$d = FileCrypt::unwrap_key( (string) $k['p'], $pk );
				if ( null === $d || FileCrypt::key_id( $d ) !== $id ) {
					throw new \InvalidArgumentException( Texts::t( 'La frase segreta non è quella giusta: nessun dato è stato cambiato.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$dek = $d;
			}
			if ( null === $dek && ! $no_phrase ) {
				throw new \InvalidArgumentException( Texts::t( 'La copia è cifrata con la chiave di un altro sito e non contiene una frase segreta: qui non si può ripristinare.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			if ( null !== $dek ) {
				$got[ $id ] = $dek;
			}
		}
		if ( $no_phrase ) {
			throw new \InvalidArgumentException( Texts::t( 'Questa copia è protetta da una frase segreta: scrivila nel campo «Frase segreta della copia» e riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $got ) {
			$o    = self::option();
			$pref = self::source();
			foreach ( $got as $id => $dek ) {
				$o['keys'][ $id ] = array( 'w' => FileCrypt::wrap_key( $dek, $masters[ $pref ] ), 'by' => $pref, 'at' => time(), 'p' => self::proof( $dek, $id ) );
				$r                = $b['keys'][ $id ]['r'] ?? null;
				if ( is_array( $r ) && ! empty( $r['w'] ) && ! empty( $r['k'] ) && is_array( $r['k'] ) ) {
					$o['keys'][ $id ]['r'] = array( 'w' => (string) $r['w'], 'k' => $r['k'] );
				}
			}
			if ( 'ok' !== $l['state'] ) {
				$cur          = (int) ( $b['current'] ?? 0 );
				$o['current'] = isset( $got[ $cur ] ) ? $cur : (int) array_key_first( $got );
			}
			update_option( self::OPT_KEYS, $o, false );
			self::$cache = null;
		}
		return array_map( 'intval', array_keys( $b['keys'] ) );
	}
}

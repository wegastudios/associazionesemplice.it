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
	const OPT_TS   = 'asem_activated_at';
	const OPT_CHANGED = 'asem_file_phrase_changed';
	const CONSTANT = 'ASEM_ENC_KEY';

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

	/** @return array{current:int,keys:array,pm:string,lic:string} pm: 'auto' (frase automatica) o 'custom' (frase scelta); lic: impronta del codice di licenza con cui le chiavi sono avvolte */
	private static function option(): array {
		$o = get_option( self::OPT_KEYS );
		if ( ! is_array( $o ) || empty( $o['keys'] ) || ! is_array( $o['keys'] ) ) {
			return array( 'current' => 0, 'keys' => array(), 'pm' => 'auto', 'lic' => '' );
		}
		return array(
			'current' => (int) ( $o['current'] ?? 0 ),
			'keys'    => $o['keys'],
			'pm'      => 'custom' === ( $o['pm'] ?? '' ) ? 'custom' : 'auto',
			'lic'     => (string) ( $o['lic'] ?? '' ),
		);
	}

	// ---------- La frase in uso ----------

	/** Data e ora (UTC) in cui il plugin è stato attivato su questo sito: è la frase predefinita nel plugin gratuito. */
	public static function activated_at(): string {
		$v = (string) get_option( self::OPT_TS, '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ) {
			$v = gmdate( 'Y-m-d H:i:s' );
			add_option( self::OPT_TS, $v, '', 'no' );
			$v = (string) get_option( self::OPT_TS, $v );
		}
		return $v;
	}

	/** Codice di licenza del sito, se c'è (lo fornisce l'edizione a pagamento con il filtro `asem_file_license_code`). */
	public static function license_code(): string {
		return trim( (string) apply_filters( 'asem_file_license_code', '' ) );
	}

	/** La frase automatica: il codice di licenza se c'è, altrimenti la data e l'ora di attivazione. */
	private static function phrase_auto(): string {
		$lic = self::license_code();
		return '' !== $lic ? $lic : self::activated_at();
	}

	/** Quale frase è in uso: 'custom' (scelta dall'amministratore), 'license' (il codice di licenza) o 'timestamp' (data e ora di attivazione). */
	public static function phrase_kind(): string {
		if ( 'custom' === self::option()['pm'] ) {
			return 'custom';
		}
		return '' !== self::license_code() ? 'license' : 'timestamp';
	}

	/** Quando la frase (o la licenza) è cambiata l'ultima volta: dopo di allora serve una copia nuova. */
	public static function phrase_changed_at(): int {
		return (int) get_option( self::OPT_CHANGED, 0 );
	}

	/** @param array{key:string,kdf:array} $rec @return array{w:string,k:array} */
	private static function wrapped( string $dek, array $rec ): array {
		return array( 'w' => FileCrypt::wrap_key( $dek, $rec['key'] ), 'k' => $rec['kdf'] );
	}

	/** Chiave e metodo ricavati da una frase, o null se non si riesce. */
	private static function rec( string $text ): ?array {
		try {
			return FileCrypt::kdf_make( $text );
		} catch ( FileCryptError $e ) {
			return null;
		}
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
		// Le chiavi sono avvolte anche con la frase in uso e con il codice di licenza: si aggiornano quando la licenza compare, cambia o sparisce,
		// o quando una chiave non ha ancora la sua frase (sito aggiornato, copia ripristinata).
		if ( $out['deks'] ) {
			$lic     = self::license_code();
			$h       = '' === $lic ? '' : hash( 'sha256', 'asem-lic|' . $lic );
			$lic_chg = $h !== (string) $o['lic'];
			$auto    = null;
			$licrec  = ( $lic_chg && '' !== $lic ) ? self::rec( $lic ) : null;
			foreach ( $out['deks'] as $id => $dek ) {
				$has_r = ! empty( $o['keys'][ $id ]['r']['w'] );
				if ( ( 'custom' !== $o['pm'] && $lic_chg ) || ! $has_r ) {
					$auto = $auto ?? self::rec( self::phrase_auto() );
					if ( null !== $auto ) {
						$o['keys'][ $id ]['r'] = self::wrapped( $dek, $auto );
						if ( ! $has_r ) {
							$o['pm'] = 'auto';
						}
						$changed = true;
					}
				}
				if ( $lic_chg ) {
					if ( null !== $licrec ) {
						$o['keys'][ $id ]['l'] = self::wrapped( $dek, $licrec );
					} else {
						unset( $o['keys'][ $id ]['l'] );
					}
					$changed = true;
				}
			}
			if ( $lic_chg && ( '' === $lic || null !== $licrec ) ) {
				if ( 'x' !== (string) $o['lic'] ) {
					update_option( self::OPT_CHANGED, time(), false ); // la licenza è cambiata davvero (non è un ripristino): le copie fatte prima vanno rifatte
				}
				$o['lic'] = $h;
				$changed  = true;
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
	 * La nuova chiave è avvolta con la frase automatica (codice di licenza o data di attivazione) e, se c'è, anche con il codice di licenza.
	 */
	private static function create( bool $first ): void {
		$dek   = random_bytes( 32 );
		$id    = FileCrypt::key_id( $dek );
		$pref  = self::source();
		$entry = array( 'w' => FileCrypt::wrap_key( $dek, self::masters()[ $pref ] ), 'by' => $pref, 'at' => time(), 'p' => self::proof( $dek, $id ) );
		$auto  = self::rec( self::phrase_auto() );
		if ( null !== $auto ) {
			$entry['r'] = self::wrapped( $dek, $auto );
		}
		$lic    = self::license_code();
		$licrec = '' !== $lic ? self::rec( $lic ) : null;
		if ( null !== $licrec ) {
			$entry['l'] = self::wrapped( $dek, $licrec );
		}
		$h = '' === $lic || null === $licrec ? '' : hash( 'sha256', 'asem-lic|' . $lic );
		if ( $first ) {
			add_option( self::OPT_KEYS, array( 'current' => $id, 'keys' => array( $id => $entry ), 'pm' => 'auto', 'lic' => $h ), '', 'no' ); // se un'altra richiesta l'ha già creata, resta la sua
		} else {
			$o                = self::option();
			$o['keys'][ $id ] = $entry;
			$o['current']     = $id;
			$o['pm']          = 'auto';
			$o['lic']         = $h;
			update_option( self::OPT_KEYS, $o, false );
		}
		self::$cache = null;
	}

	/** Crea la chiave dei dati la prima volta che serve (nessuna scelta da fare: la frase è automatica). */
	private static function ensure(): bool {
		$l = self::load();
		if ( 'none' === $l['state'] ) {
			self::create( true );
			$l = self::load();
		}
		return 'ok' === $l['state'];
	}

	/**
	 * Come si salvano i nuovi file: 'encrypt' (cifrati), 'plain' (manca la libreria sodium: in chiaro, con avviso) o 'blocked' (la chiave salvata non si
	 * apre con la chiave principale di questo sito: i nuovi file non si salvano finché non si sistema).
	 */
	public static function mode(): string {
		if ( ! FileCrypt::available() ) {
			return 'plain';
		}
		return self::ensure() ? 'encrypt' : 'blocked';
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

	// ---------- Frase scelta dall'amministratore ----------

	const RECOVERY_MIN = 12;

	/** Controlla una frase scelta. @return string vuoto se va bene, altrimenti il perché */
	public static function recovery_error( string $phrase, string $again ): string {
		if ( mb_strlen( $phrase ) < self::RECOVERY_MIN ) {
			return Texts::t( 'La frase deve avere almeno {n} caratteri.', array( 'n' => self::RECOVERY_MIN ) );
		}
		if ( $phrase !== $again ) {
			return Texts::t( 'Le due frasi non coincidono.' );
		}
		return '';
	}

	/**
	 * Cambia la frase: da quel momento la chiave dei dati si apre con la frase scelta (e, se c'è, con il codice di licenza, che resta sempre valido).
	 * Le copie di sicurezza fatte prima si aprono con la frase di allora.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function set_custom_phrase( string $phrase, string $again ): void {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'Questo server non ha la libreria di cifratura (sodium).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$err = self::recovery_error( $phrase, $again );
		if ( '' !== $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! self::ensure() ) {
			throw new \InvalidArgumentException( Texts::t( 'La chiave non si apre su questo sito: prima va sistemata (sezione «Sistemare la chiave»).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$rec = self::rec( $phrase );
		if ( null === $rec ) {
			throw new \InvalidArgumentException( Texts::t( 'La frase non si è potuta elaborare su questo server: riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::rewrap_phrase( $rec, 'custom' );
	}

	/** Torna alla frase automatica (codice di licenza o data di attivazione). @throws \InvalidArgumentException */
	public static function use_auto_phrase(): void {
		if ( ! self::ensure() ) {
			throw new \InvalidArgumentException( Texts::t( 'La chiave non si apre su questo sito: prima va sistemata (sezione «Sistemare la chiave»).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$rec = self::rec( self::phrase_auto() );
		if ( null === $rec ) {
			throw new \InvalidArgumentException( Texts::t( 'La frase non si è potuta elaborare su questo server: riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::rewrap_phrase( $rec, 'auto' );
	}

	private static function rewrap_phrase( array $rec, string $pm ): void {
		$l = self::load();
		$o = self::option();
		foreach ( $l['deks'] as $id => $dek ) {
			$o['keys'][ $id ]['r'] = self::wrapped( $dek, $rec );
		}
		$o['pm'] = $pm;
		update_option( self::OPT_KEYS, $o, false );
		update_option( self::OPT_CHANGED, time(), false );
		self::$cache = null;
	}

	/**
	 * Le chiavi salvate non si aprono più (codici di sicurezza o costante cambiati): con la frase in uso, o con il codice di licenza, si riaprono e si
	 * avvolgono con la chiave principale di adesso, senza ricifrare nessun file.
	 *
	 * @return int chiavi recuperate
	 * @throws \InvalidArgumentException
	 */
	public static function recover_with_phrase( string $phrase ): int {
		if ( ! FileCrypt::available() ) {
			throw new \InvalidArgumentException( Texts::t( 'Questo server non ha la libreria di cifratura (sodium).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$phrase = trim( $phrase );
		$l      = self::load();
		$o      = self::option();
		$m      = self::masters();
		$n      = 0;
		foreach ( $l['locked'] as $id ) {
			$d = self::open_by_phrase( $o['keys'][ $id ] ?? array(), $id, $phrase );
			if ( null !== $d ) {
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

	/**
	 * Apre una chiave dei dati con una frase, provando l'avvolgimento della frase in uso e quello del codice di licenza.
	 *
	 * @param array $entry voce della chiave (o della copia)
	 * @return string|null la chiave dei dati, o null se la frase non l'apre
	 */
	private static function open_by_phrase( array $entry, int $id, string $phrase, ?string &$via = null ): ?string {
		if ( '' === $phrase ) {
			return null;
		}
		foreach ( array( 'r', 'l' ) as $name ) {
			$w = $entry[ $name ] ?? null;
			if ( ! is_array( $w ) || empty( $w['w'] ) || empty( $w['k'] ) || ! is_array( $w['k'] ) ) {
				continue;
			}
			try {
				$pk = FileCrypt::kdf_derive( $phrase, $w['k'] );
			} catch ( FileCryptError $e ) {
				continue;
			}
			$d = FileCrypt::unwrap_key( (string) $w['w'], $pk );
			if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
				$via = $name;
				return $d;
			}
		}
		return null;
	}

	// ---------- Chiavi nella copia di sicurezza ----------

	/**
	 * Le chiavi per una copia di sicurezza: avvolte con la chiave principale di questo sito, con la frase in uso e con il codice di licenza (se c'è).
	 *
	 * @return array vuoto se non ci sono chiavi
	 */
	public static function bundle(): array {
		$o = self::option();
		if ( ! $o['keys'] ) {
			return array();
		}
		self::load(); // allinea le frasi alla licenza di adesso prima di copiarle
		$o   = self::option();
		$out = array( 'v' => 1, 'site' => self::site_id(), 'current' => $o['current'], 'pm' => $o['pm'], 'keys' => array() );
		foreach ( $o['keys'] as $id => $k ) {
			$e = array( 'm' => (string) ( $k['w'] ?? '' ), 'mb' => (string) ( $k['by'] ?? 'salt' ) );
			foreach ( array( 'r', 'l' ) as $name ) {
				if ( ! empty( $k[ $name ]['w'] ) && ! empty( $k[ $name ]['k'] ) ) {
					$e[ $name ] = array( 'w' => (string) $k[ $name ]['w'], 'k' => $k[ $name ]['k'] );
				}
			}
			$out['keys'][ (int) $id ] = $e;
		}
		return $out;
	}

	/**
	 * Prima di un ripristino: ottiene le chiavi dei dati della copia (da questo sito, dalla chiave principale o dalla frase) e le aggiunge a quelle del
	 * sito. Non cambia l'identificativo del sito: lo si adotta dopo, a ripristino riuscito, con {@see adopt_site()}.
	 *
	 * @param array  $b      copia di `chiavi.json`
	 * @param string $phrase la frase (o il codice di licenza) che aveva la copia, se serve
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
		$phrase  = trim( $phrase );
		$l       = self::load();
		$masters = self::masters();
		$got     = array();
		$via     = array();
		$no_ph   = false;
		foreach ( $b['keys'] as $id => $k ) {
			$id = (int) $id;
			if ( isset( $l['deks'][ $id ] ) ) {
				continue; // già nota a questo sito
			}
			$k   = is_array( $k ) ? $k : array();
			$dek = null;
			foreach ( $masters as $mk ) {
				$d = FileCrypt::unwrap_key( (string) ( $k['m'] ?? '' ), $mk );
				if ( null !== $d && FileCrypt::key_id( $d ) === $id ) {
					$dek = $d;
					break;
				}
			}
			if ( null === $dek ) {
				if ( empty( $k['r']['w'] ) && empty( $k['l']['w'] ) ) {
					throw new \InvalidArgumentException( Texts::t( 'La copia è cifrata con la chiave di un altro sito e non contiene una frase per riaprirla: qui non si può ripristinare.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				if ( '' === $phrase ) {
					$no_ph = true;
					continue;
				}
				$v   = null;
				$dek = self::open_by_phrase( $k, $id, $phrase, $v );
				if ( null === $dek ) {
					throw new \InvalidArgumentException( Texts::t( 'La frase non è quella giusta: nessun dato è stato cambiato.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$via[ $id ] = $v;
			}
			$got[ $id ] = $dek;
		}
		if ( $no_ph ) {
			throw new \InvalidArgumentException( Texts::t( 'Scrivi la frase: se non l\'hai cambiata, è il codice di licenza (o, nel plugin gratuito, la data e ora di attivazione) che avevi quando hai fatto la copia.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $got ) {
			$o    = self::option();
			$pref = self::source();
			$had  = (bool) $o['keys'];
			foreach ( $got as $id => $dek ) {
				$entry = array( 'w' => FileCrypt::wrap_key( $dek, $masters[ $pref ] ), 'by' => $pref, 'at' => time(), 'p' => self::proof( $dek, $id ) );
				// Una frase scelta dall'amministratore (e riconosciuta dalla frase scritta) si porta con la chiave; altrimenti la chiave prende la frase automatica
				// di questo sito e la licenza di questo sito (se ne occupa load()).
				$r = $b['keys'][ $id ]['r'] ?? null;
				if ( 'custom' === ( $b['pm'] ?? '' ) && 'r' === ( $via[ $id ] ?? '' ) && ( ! $had || 'custom' === $o['pm'] ) && is_array( $r ) && ! empty( $r['w'] ) && ! empty( $r['k'] ) && is_array( $r['k'] ) ) {
					$entry['r'] = array( 'w' => (string) $r['w'], 'k' => $r['k'] );
					if ( ! $had ) {
						$o['pm'] = 'custom';
					}
				}
				$o['keys'][ $id ] = $entry;
			}
			$o['lic'] = 'x'; // segnala a load() di avvolgere le chiavi con la licenza di questo sito
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

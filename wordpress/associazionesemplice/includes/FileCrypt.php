<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- cifratura a flussi di file privati (memoria costante); i messaggi degli errori sono codici tecnici interni

/**
 * Cifratura a riposo dei file riservati e di singoli campi, con libsodium. Classe «pura»: la chiave si passa da fuori, nessuna dipendenza da WordPress,
 * così si può provare da sola e riusare per altri file (ad esempio i PDF delle ricevute).
 *
 * Formato di un file (lo stesso del gestionale online):
 *   0x01 (formato) · identificativo della chiave (4 byte, big-endian) · intestazione secretstream (24 byte) · blocchi
 * Ogni blocco contiene fino a 64 KiB di testo in chiaro (XChaCha20-Poly1305, secretstream): il file non sta mai intero in memoria. L'ultimo blocco
 * porta il segno di fine, quindi un file tagliato non si apre. Ogni blocco autentica anche i «dati associati» (AD): `as-file|{sito}|{percorso}`,
 * per cui un file spostato altrove (o preso da un altro sito) non si apre.
 *
 * Formato di un campo: «asemf1:» + base64( 0x01 · identificativo chiave (4 byte) · nonce (24 byte) · testo cifrato con autenticazione ).
 */
final class FileCrypt {

	const FORMAT       = 1;
	const CHUNK        = 65536;
	const HEADER_LEN   = 29; // 1 + 4 + 24
	const FIELD_PREFIX = 'asemf1:';
	const ABYTES       = 17; // SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
	const HEADER_BYTES = 24; // SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES

	/** La libreria sodium (con secretstream e XChaCha20-Poly1305) è presente? Il surrogato di WordPress non conta: troppo lento per file grandi. */
	/** Solo per le prove: simula la libreria sodium assente. */
	public static $disabled = false;

	public static function available(): bool {
		if ( self::$disabled ) {
			return false;
		}
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_secretbox' );
	}

	/** La derivazione della chiave da una frase (Argon2id) è disponibile? */
	public static function pwhash_available(): bool {
		return extension_loaded( 'sodium' ) && function_exists( 'sodium_crypto_pwhash' ) && defined( 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13' );
	}

	private static function need(): void {
		if ( ! self::available() ) {
			throw new FileCryptError( FileCryptError::NO_SODIUM );
		}
	}

	// ---------- Chiavi ----------

	/** Chiave principale (32 byte) da un testo segreto. */
	public static function master_from_material( string $material ): string {
		return hash( 'sha256', 'asem-file-master-v1|' . $material, true );
	}

	/** Identificativo (28 bit) di una chiave dei dati: stessa chiave, stesso numero; non rivela nulla della chiave. */
	public static function key_id( string $dek ): int {
		return (int) hexdec( substr( hash( 'sha256', 'asem-file-key-id-v1|' . $dek ), 0, 7 ) );
	}

	/** Avvolge una chiave con un'altra: base64( nonce · testo cifrato ). */
	public static function wrap_key( string $dek, string $kek ): string {
		self::need();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $dek, $nonce, $kek ) );
	}

	/** @return string|null la chiave dei dati; null se la chiave che avvolge non è quella giusta o il testo è danneggiato */
	public static function unwrap_key( string $wrapped, string $kek ): ?string {
		self::need();
		$raw = base64_decode( $wrapped, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || 32 !== strlen( $kek ) ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $kek );
		return ( false === $plain || 32 !== strlen( $plain ) ) ? null : $plain;
	}

	/** Chiave (32 byte) da una frase segreta, con Argon2id. @throws FileCryptError */
	public static function key_from_phrase( string $phrase, string $salt, int $ops, int $mem ): string {
		if ( ! self::pwhash_available() || SODIUM_CRYPTO_PWHASH_SALTBYTES !== strlen( $salt ) ) {
			throw new FileCryptError( FileCryptError::NO_SODIUM );
		}
		try {
			return sodium_crypto_pwhash( 32, $phrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
		} catch ( \Throwable $e ) { // memoria insufficiente o parametri non ammessi
			throw new FileCryptError( FileCryptError::IO );
		}
	}

	// ---------- Dati associati ----------

	public static function ad_file( string $site, string $rel ): string {
		return 'as-file|' . $site . '|' . $rel;
	}

	public static function ad_field( string $site, string $table, string $column, int $id ): string {
		return 'as-field|' . $site . '|' . $table . '|' . $column . '|' . $id;
	}

	// ---------- Flussi ----------

	/** Legge fino a $n byte (o fino alla fine): fread può dare meno del richiesto anche prima della fine. */
	private static function read_full( $h, int $n ): string {
		$buf = '';
		while ( strlen( $buf ) < $n && ! feof( $h ) ) {
			$d = fread( $h, $n - strlen( $buf ) );
			if ( false === $d ) {
				throw new FileCryptError( FileCryptError::IO );
			}
			if ( '' === $d ) {
				break;
			}
			$buf .= $d;
		}
		return $buf;
	}

	private static function write( $h, string $data ): void {
		$len = strlen( $data );
		for ( $off = 0; $off < $len; ) {
			$n = fwrite( $h, substr( $data, $off ) );
			if ( false === $n || 0 === $n ) {
				throw new FileCryptError( FileCryptError::IO );
			}
			$off += $n;
		}
	}

	/**
	 * Cifra un flusso in un altro.
	 *
	 * @param resource $in  flusso da leggere (in chiaro)
	 * @param resource $out flusso su cui scrivere (cifrato)
	 * @return array{bytes:int,sha256:string} dimensione e impronta SHA-256 del contenuto in chiaro
	 * @throws FileCryptError
	 */
	public static function encrypt_stream( $in, $out, string $key, int $key_id, string $ad ): array {
		self::need();
		if ( 32 !== strlen( $key ) ) {
			throw new FileCryptError( FileCryptError::BAD_KEY );
		}
		$init  = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		$state = $init[0];
		self::write( $out, chr( self::FORMAT ) . pack( 'N', $key_id ) . $init[1] );
		$ctx   = hash_init( 'sha256' );
		$total = 0;
		$buf   = self::read_full( $in, self::CHUNK );
		for ( ;; ) {
			$next  = strlen( $buf ) === self::CHUNK ? self::read_full( $in, self::CHUNK ) : ''; // un blocco pieno è l'ultimo solo se dopo non c'è altro
			$final = '' === $next;
			hash_update( $ctx, $buf );
			$total += strlen( $buf );
			self::write( $out, sodium_crypto_secretstream_xchacha20poly1305_push( $state, $buf, $ad, $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE ) );
			if ( $final ) {
				break;
			}
			$buf = $next;
		}
		sodium_memzero( $state );
		return array( 'bytes' => $total, 'sha256' => hash_final( $ctx ) );
	}

	/**
	 * Decifra un flusso. Ogni blocco è controllato prima di essere consegnato; un file tagliato, modificato o aperto con la chiave sbagliata dà un errore.
	 *
	 * @param resource      $in      flusso cifrato
	 * @param callable|null $sink    riceve il testo in chiaro a pezzi (null = si controlla soltanto)
	 * @param callable      $key_for riceve l'identificativo della chiave e dà la chiave (32 byte) o null
	 * @return array{bytes:int,sha256:string,key_id:int}
	 * @throws FileCryptError
	 */
	public static function decrypt_stream( $in, ?callable $sink, callable $key_for, string $ad ): array {
		self::need();
		$head = self::read_full( $in, self::HEADER_LEN );
		if ( self::HEADER_LEN !== strlen( $head ) || chr( self::FORMAT ) !== $head[0] ) {
			throw new FileCryptError( FileCryptError::FORMAT );
		}
		$u      = unpack( 'N', substr( $head, 1, 4 ) );
		$key_id = (int) $u[1];
		$key    = $key_for( $key_id );
		if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
			throw new FileCryptError( FileCryptError::NO_KEY );
		}
		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $head, 5, self::HEADER_BYTES ), $key );
		} catch ( \Throwable $e ) {
			throw new FileCryptError( FileCryptError::FORMAT );
		}
		$ctx   = hash_init( 'sha256' );
		$total = 0;
		$done  = false;
		for ( ;; ) {
			$blk = self::read_full( $in, self::CHUNK + self::ABYTES );
			if ( '' === $blk ) {
				if ( ! $done ) {
					throw new FileCryptError( FileCryptError::TRUNCATED );
				}
				break;
			}
			if ( $done ) {
				throw new FileCryptError( FileCryptError::FORMAT ); // dati dopo il blocco finale
			}
			try {
				$r = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $blk, $ad );
			} catch ( \Throwable $e ) {
				throw new FileCryptError( FileCryptError::AUTH );
			}
			if ( false === $r ) {
				throw new FileCryptError( FileCryptError::AUTH );
			}
			hash_update( $ctx, $r[0] );
			$total += strlen( $r[0] );
			if ( null !== $sink && '' !== $r[0] ) {
				$sink( $r[0] );
			}
			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $r[1] ) {
				$done = true;
			}
		}
		sodium_memzero( $state );
		return array( 'bytes' => $total, 'sha256' => hash_final( $ctx ), 'key_id' => $key_id );
	}

	// ---------- File ----------

	/**
	 * Cifra un file in un altro (da scrivere e poi rinominare dove serve). Se qualcosa va storto il file di destinazione si cancella.
	 *
	 * @return array{bytes:int,sha256:string}
	 * @throws FileCryptError
	 */
	public static function encrypt_file( string $src, string $dst, string $key, int $key_id, string $ad ): array {
		$in = @fopen( $src, 'rb' );
		if ( false === $in ) {
			throw new FileCryptError( FileCryptError::IO );
		}
		$out = @fopen( $dst, 'xb' );
		if ( false === $out ) {
			fclose( $in );
			throw new FileCryptError( FileCryptError::IO );
		}
		try {
			$r = self::encrypt_stream( $in, $out, $key, $key_id, $ad );
			fflush( $out );
		} catch ( \Throwable $e ) {
			fclose( $in );
			fclose( $out );
			wp_delete_file( $dst );
			throw $e;
		}
		fclose( $in );
		fclose( $out );
		return $r;
	}

	/**
	 * Decifra un file in un altro, oppure (con $dst nullo) lo controlla soltanto.
	 *
	 * @return array{bytes:int,sha256:string,key_id:int}
	 * @throws FileCryptError
	 */
	public static function decrypt_file( string $src, ?string $dst, callable $key_for, string $ad ): array {
		$in = @fopen( $src, 'rb' );
		if ( false === $in ) {
			throw new FileCryptError( FileCryptError::IO );
		}
		$out = null;
		if ( null !== $dst ) {
			$out = @fopen( $dst, 'xb' );
			if ( false === $out ) {
				fclose( $in );
				throw new FileCryptError( FileCryptError::IO );
			}
		}
		try {
			$sink = null;
			if ( $out ) {
				$sink = function ( string $d ) use ( $out ): void {
					self::write( $out, $d );
				};
			}
			$r = self::decrypt_stream( $in, $sink, $key_for, $ad );
		} catch ( \Throwable $e ) {
			fclose( $in );
			if ( $out ) {
				fclose( $out );
				wp_delete_file( (string) $dst );
			}
			throw $e;
		}
		fclose( $in );
		if ( $out ) {
			fclose( $out );
		}
		return $r;
	}

	/** Il file comincia con l'intestazione del formato (e ha almeno la lunghezza minima)? Non prova ad aprirlo. */
	public static function is_encrypted_file( string $path ): bool {
		if ( ! is_file( $path ) || filesize( $path ) < self::HEADER_LEN + self::ABYTES ) {
			return false;
		}
		$h = @fopen( $path, 'rb' );
		if ( false === $h ) {
			return false;
		}
		$b = fread( $h, 1 );
		fclose( $h );
		return chr( self::FORMAT ) === $b;
	}

	// ---------- Campi ----------

	public static function is_field( string $v ): bool {
		return 0 === strpos( $v, self::FIELD_PREFIX );
	}

	/** Cifra un testo breve (nome del medico, nome del file…). */
	public static function encrypt_field( string $plain, string $key, int $key_id, string $ad ): string {
		self::need();
		if ( 32 !== strlen( $key ) ) {
			throw new FileCryptError( FileCryptError::BAD_KEY );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $ad, $nonce, $key );
		return self::FIELD_PREFIX . base64_encode( chr( self::FORMAT ) . pack( 'N', $key_id ) . $nonce . $ct );
	}

	/**
	 * @param callable $key_for riceve l'identificativo della chiave e dà la chiave o null
	 * @throws FileCryptError
	 */
	public static function decrypt_field( string $stored, callable $key_for, string $ad ): string {
		self::need();
		if ( ! self::is_field( $stored ) ) {
			throw new FileCryptError( FileCryptError::FORMAT );
		}
		$raw = base64_decode( substr( $stored, strlen( self::FIELD_PREFIX ) ), true );
		$np  = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) < 5 + $np + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || chr( self::FORMAT ) !== $raw[0] ) {
			throw new FileCryptError( FileCryptError::FORMAT );
		}
		$u   = unpack( 'N', substr( $raw, 1, 4 ) );
		$key = $key_for( (int) $u[1] );
		if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
			throw new FileCryptError( FileCryptError::NO_KEY );
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, 5 + $np ), $ad, substr( $raw, 5, $np ), $key );
		if ( false === $plain ) {
			throw new FileCryptError( FileCryptError::AUTH );
		}
		return $plain;
	}
}

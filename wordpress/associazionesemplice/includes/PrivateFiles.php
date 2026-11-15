<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- salvataggio e lettura a flussi di file privati (memoria costante)

/**
 * Salvataggio, lettura e controllo dei file della cartella riservata, cifrati a riposo (vedi {@see FileCrypt} e {@see FileKey}).
 *
 * Ogni file ha il suo percorso relativo alla cartella riservata (oggi il solo nome casuale; in futuro anche sottocartelle): è parte dei dati associati
 * della cifratura. Nel database, accanto al nome del file, stanno l'impronta SHA-256 del contenuto in chiaro e `enc_ver` (1 = cifrato, vuoto = in chiaro).
 */
final class PrivateFiles {

	const ENC_VER = 1;

	// ---------- Dove stanno i file ----------

	/**
	 * Tabelle con file nella cartella riservata. Un'estensione ne aggiunge con il filtro `asem_file_sources`.
	 * Ogni voce: key, table (senza prefisso), stored (colonna del nome sul disco), sha (colonna dell'impronta), enc (colonna enc_ver), prio (ordine: prima i numeri bassi),
	 * fields (colonne di testo da cifrare, facoltative).
	 *
	 * @return array[]
	 */
	public static function sources(): array {
		$s = array(
			array( 'key' => 'attachments', 'table' => 'attachments', 'stored' => 'stored_name', 'sha' => 'sha256', 'enc' => 'enc_ver', 'prio' => 20, 'fields' => array() ),
			array( 'key' => 'documents', 'table' => 'documents', 'stored' => 'file_stored', 'sha' => 'file_sha256', 'enc' => 'enc_ver', 'prio' => 30, 'fields' => array() ),
			array( 'key' => 'items', 'table' => 'items', 'stored' => 'file_stored', 'sha' => 'file_sha256', 'enc' => 'enc_ver', 'prio' => 40, 'fields' => array() ),
		);
		$s   = (array) apply_filters( 'asem_file_sources', $s );
		$out = array();
		foreach ( $s as $src ) {
			if ( ! is_array( $src ) || empty( $src['table'] ) || empty( $src['stored'] ) || empty( $src['sha'] ) || empty( $src['enc'] ) || ! self::table_exists( (string) $src['table'] ) ) {
				continue;
			}
			$src['fields'] = isset( $src['fields'] ) ? (array) $src['fields'] : array();
			$src['prio']   = (int) ( $src['prio'] ?? 50 );
			$out[]         = $src;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['prio'] <=> $b['prio'];
			}
		);
		return $out;
	}

	private static function table_exists( string $table ): bool {
		static $seen = array();
		if ( ! isset( $seen[ $table ] ) ) {
			$db            = Db::db();
			$seen[ $table ] = (bool) $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( Db::t( $table ) ) ) );
		}
		return $seen[ $table ];
	}

	/** Dimentica le tabelle già controllate (nei test, dopo averne create o tolte). */
	public static function reset(): void {
		FileKey::reset();
	}

	/** nome sul disco => [enc, sha] di tutte le righe che hanno un file. */
	public static function lookup_map(): array {
		$db  = Db::db();
		$map = array();
		foreach ( self::sources() as $s ) {
			$rows = $db->get_results( 'SELECT `' . $s['stored'] . '` AS st, `' . $s['enc'] . '` AS en, `' . $s['sha'] . '` AS sh FROM `' . Db::t( $s['table'] ) . '` WHERE `' . $s['stored'] . "` <> ''", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
			foreach ( $rows as $r ) {
				if ( null !== $r['st'] ) {
					$map[ (string) $r['st'] ] = array( null === $r['en'] ? 0 : (int) $r['en'], (string) $r['sh'] );
				}
			}
		}
		return $map;
	}

	// ---------- Dati associati e chiavi ----------

	public static function ad( string $rel ): string {
		return FileCrypt::ad_file( FileKey::site_id(), $rel );
	}

	/** Callback delle chiavi per {@see FileCrypt}. @return callable */
	public static function key_for(): callable {
		return array( FileKey::class, 'key_for' );
	}

	// ---------- Salvataggio ----------

	/** Frase per chi prova a salvare mentre la chiave di questo sito non coincide con quella usata finora. */
	public static function blocked_text(): string {
		return Texts::t( 'Il file non è stato salvato: la chiave di cifratura di questo sito non coincide con quella usata finora. Apri Impostazioni → Copia di sicurezza → «Cifratura dei file riservati» per sistemarla.' );
	}

	/** Frase per chi prova a salvare prima di aver scelto la frase di recupero. */
	public static function setup_text(): string {
		return Texts::t( 'Il file non è stato salvato: prima va scelta la frase di recupero. Apri Impostazioni → Copia di sicurezza → «Cifratura dei file riservati» e scegline una.' );
	}

	/**
	 * Cifra un file (di norma quello appena caricato) e lo mette nella cartella riservata con il nome indicato: scrive un file temporaneo e lo rinomina,
	 * quindi il file in chiaro non resta mai sul disco. Con la libreria di cifratura assente non fa nulla e dà null: chi chiama decide cosa fare.
	 *
	 * @param bool $strict true per i dati sanitari: senza cifratura il file non si salva
	 * @return array{path:string,sha256:string,size:int,enc_ver:int}|null
	 * @throws \InvalidArgumentException
	 */
	public static function save_encrypted( string $src, string $dir, string $stored, bool $strict = false ): ?array {
		$mode = FileKey::mode();
		if ( 'blocked' === $mode ) {
			throw new \InvalidArgumentException( self::blocked_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'setup' === $mode ) {
			throw new \InvalidArgumentException( self::setup_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'encrypt' !== $mode ) {
			if ( $strict ) {
				throw new \InvalidArgumentException( Texts::t( 'Il file non è stato salvato: questo server non ha la libreria di cifratura (sodium) e i dati sanitari non si salvano in chiaro. Chiedi a chi gestisce il server di attivare l\'estensione sodium di PHP.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			return null;
		}
		$key   = FileKey::current();
		$final = $dir . '/' . $stored;
		$tmp   = $final . '.part-' . bin2hex( random_bytes( 4 ) );
		try {
			$r = FileCrypt::encrypt_file( $src, $tmp, (string) $key['key'], (int) $key['id'], self::ad( $stored ) );
		} catch ( FileCryptError $e ) {
			throw new \InvalidArgumentException( Texts::t( 'Il file non si è potuto cifrare e non è stato salvato: riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		chmod( $tmp, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		if ( ! rename( $tmp, $final ) ) {
			wp_delete_file( $tmp );
			throw new \InvalidArgumentException( Texts::t( 'Il file non si è potuto salvare: controlla i permessi della cartella dei file riservati.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( 'path' => $final, 'sha256' => $r['sha256'], 'size' => $r['bytes'], 'enc_ver' => self::ENC_VER );
	}

	// ---------- Lettura e controllo ----------

	/**
	 * Il file va decifrato? Sì se la riga dice cifrato (enc_ver); se la riga non lo dice ma il file ha l'intestazione e si apre (cifratura appena fatta dalla
	 * migrazione, riga non ancora aggiornata) si tratta da cifrato.
	 *
	 * @param mixed $enc valore della colonna enc_ver
	 */
	public static function is_enc( string $path, string $rel, $enc ): bool {
		if ( null !== $enc && '' !== $enc && (int) $enc > 0 ) {
			return true;
		}
		if ( FileCrypt::available() && FileCrypt::is_encrypted_file( $path ) ) {
			try {
				FileCrypt::decrypt_file( $path, null, self::key_for(), self::ad( $rel ) );
				return true;
			} catch ( \Throwable $e ) {
				return false; // un file in chiaro che per caso comincia con quel byte
			}
		}
		return false;
	}

	/**
	 * Decifra un file solo per controllarlo (nessuna scrittura) e, se c'è, confronta l'impronta con quella registrata.
	 *
	 * @return array{bytes:int,sha256:string}
	 * @throws FileCryptError
	 */
	public static function verify( string $path, string $rel, string $sha = '' ): array {
		$r = FileCrypt::decrypt_file( $path, null, self::key_for(), self::ad( $rel ) );
		if ( '' !== $sha && ! hash_equals( strtolower( $sha ), $r['sha256'] ) ) {
			throw new FileCryptError( FileCryptError::HASH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- solo un codice interno, mai mostrato così com'è
		}
		return array( 'bytes' => $r['bytes'], 'sha256' => $r['sha256'] );
	}

	/**
	 * Prima di mandare un file: se è cifrato lo controlla (chiave, integrità, impronta) e dice la lunghezza del contenuto in chiaro. Se qualcosa non va
	 * la richiesta finisce con un messaggio chiaro, senza mandare nulla.
	 *
	 * @param string $kind cert, doc, att, photo o refund (decide la frase)
	 * @param mixed  $enc  valore di enc_ver della riga
	 * @return array{enc:bool,length:int}
	 */
	public static function prepare_read( string $path, string $rel, $enc, string $sha, string $kind ): array {
		$is = self::is_enc( $path, $rel, $enc );
		if ( ! $is ) {
			return array( 'enc' => false, 'length' => (int) filesize( $path ) );
		}
		try {
			$v = self::verify( $path, $rel, $sha );
		} catch ( FileCryptError $e ) {
			wp_die( esc_html( self::message( $kind, $e->reason ) ), esc_html( Texts::t( 'File non leggibile' ) ), array( 'response' => 500, 'back_link' => true ) );
		}
		return array( 'enc' => true, 'length' => $v['bytes'] );
	}

	/** Manda il contenuto (le intestazioni le ha già mandate chi chiama) e termina. */
	public static function send_body( string $path, string $rel, bool $enc ): void {
		if ( ! $enc ) {
			readfile( $path );
			exit;
		}
		$in = @fopen( $path, 'rb' );
		if ( false === $in ) {
			exit;
		}
		try {
			FileCrypt::decrypt_stream(
				$in,
				function ( string $d ): void {
					echo $d; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contenuto di un file, mandato com'è con il suo tipo e «nosniff»
					flush();
				},
				self::key_for(),
				self::ad( $rel )
			);
		} catch ( FileCryptError $e ) {
			// già controllato prima di mandare le intestazioni: se il file cambia nel frattempo la risposta si interrompe
			unset( $e );
		}
		fclose( $in );
		exit;
	}

	// ---------- Frasi ----------

	/** Perché un file non si apre. */
	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case FileCryptError::NO_SODIUM:
				return Texts::t( 'questo server non ha la libreria di cifratura (sodium).' );
			case FileCryptError::NO_KEY:
				return Texts::t( 'la chiave di cifratura usata per salvarlo non è disponibile su questo sito (codici di sicurezza di wp-config.php o costante ASEM_ENC_KEY cambiati, o database copiato da un altro sito). Rimetti quelli di prima oppure ripristina una copia di sicurezza con la sua frase segreta.' );
			case FileCryptError::AUTH:
				return Texts::t( 'la chiave di questo sito non coincide con quella usata per salvarlo, oppure il file è stato modificato. Il sito non lo rifà in silenzio: rimetti la chiave di prima o ripristina una copia di sicurezza.' );
			case FileCryptError::HASH:
				return Texts::t( 'l\'impronta del contenuto non coincide con quella registrata: il file è stato modificato o sostituito. Va ripristinato da una copia di sicurezza.' );
			case FileCryptError::TRUNCATED:
				return Texts::t( 'il file è incompleto (tagliato). Va ripristinato da una copia di sicurezza.' );
			case FileCryptError::FORMAT:
				return Texts::t( 'il file non ha il formato atteso o è danneggiato. Va ripristinato da una copia di sicurezza.' );
		}
		return Texts::t( 'il file non si riesce a leggere dal disco: controlla i permessi della cartella dei file riservati.' );
	}

	/** Messaggio completo «… non leggibile su questo sito: …» per ogni tipo di file. */
	public static function message( string $kind, string $reason ): string {
		$why = array( 'motivo' => self::reason_text( $reason ) );
		switch ( $kind ) {
			case 'cert':
				return Texts::t( 'Certificato non leggibile su questo sito: {motivo}', $why );
			case 'att':
				return Texts::t( 'Allegato non leggibile su questo sito: {motivo}', $why );
			case 'photo':
				return Texts::t( 'Foto non leggibile su questo sito: {motivo}', $why );
			case 'refund':
				return Texts::t( 'Giustificativo non leggibile su questo sito: {motivo}', $why );
		}
		return Texts::t( 'Documento non leggibile su questo sito: {motivo}', $why );
	}

	// ---------- Campi di testo ----------

	/** Cifra un testo breve di una riga del database; senza cifratura (libreria assente) lo restituisce com'è. Un valore vuoto resta vuoto. */
	public static function field_encrypt( string $table, string $col, int $id, string $plain ): string {
		if ( '' === $plain || FileCrypt::is_field( $plain ) ) {
			return $plain;
		}
		if ( 'encrypt' !== FileKey::mode() ) {
			return $plain;
		}
		$key = FileKey::current();
		return FileCrypt::encrypt_field( $plain, (string) $key['key'], (int) $key['id'], FileCrypt::ad_field( FileKey::site_id(), $table, $col, $id ) );
	}

	/**
	 * Legge un testo breve di una riga: se è cifrato lo apre, se è in chiaro (non ancora migrato) lo restituisce com'è.
	 *
	 * @throws FileCryptError
	 */
	public static function field_decrypt( string $table, string $col, int $id, ?string $stored ): string {
		$stored = (string) $stored;
		if ( ! FileCrypt::is_field( $stored ) ) {
			return $stored;
		}
		return FileCrypt::decrypt_field( $stored, self::key_for(), FileCrypt::ad_field( FileKey::site_id(), $table, $col, $id ) );
	}
}

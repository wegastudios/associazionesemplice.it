<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Allegati dei movimenti (scontrini, fatture): PDF e foto.
 *
 * Non passano dalla libreria media di WordPress (niente caos in galleria, niente indirizzi pubblici):
 * stanno in una cartella privata dentro `uploads`, con nome casuale e senza estensione, e si aprono solo
 * da qui, con il controllo dei permessi. Un allegato non si cancella mai di nascosto: "rimuovere" lo toglie
 * dall'elenco e lo registra nel registro azioni, il file resta sul disco.
 */
final class Attachments {

	const DIR_NAME = 'associazionesemplice-private';

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Cartella privata ----------

	public static function dir(): string {
		$u = wp_upload_dir( null, false );
		return rtrim( (string) $u['basedir'], '/\\' ) . '/' . self::DIR_NAME;
	}

	/** Crea (se manca) la cartella privata degli allegati e delle copie di sicurezza. */
	public static function prepare_dir(): string {
		return self::ensure_dir();
	}

	private static function ensure_dir(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \InvalidArgumentException( 'Impossibile creare la cartella per gli allegati: controlla i permessi di wp-content/uploads.' );
		}
		// Apache: accesso diretto negato. Altri server: i nomi sono casuali, senza estensione e la cartella non si elenca.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	private static function real_mime( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$f = finfo_open( FILEINFO_MIME_TYPE );
		if ( ! $f ) {
			return '';
		}
		$m = (string) finfo_file( $f, $path );
		finfo_close( $f );
		return $m;
	}

	// ---------- Controllo e salvataggio ----------

	/**
	 * Controlla i file caricati (campo `<input type="file" multiple>`) PRIMA di registrare qualsiasi cosa.
	 *
	 * @param array|null $files struttura di $_FILES per un campo
	 * @param int        $tx_id movimento a cui si allegano (0 se ancora da creare): serve per limite e doppioni
	 * @return array[] file pronti per add()
	 * @throws \InvalidArgumentException
	 */
	public static function prepare( ?array $files, int $tx_id = 0 ): array {
		$n = AttachmentRules::normalize_files( $files );
		if ( $n['errors'] ) {
			throw new \InvalidArgumentException( $n['errors'][0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$existing = $tx_id ? array_column( self::list_for( $tx_id ), 'sha256' ) : array();
		$count    = $tx_id ? count( $existing ) : 0;
		$out      = array();
		$seen     = array();
		foreach ( $n['files'] as $f ) {
			$path = $f['tmp_name'];
			if ( '' === $path || ! is_readable( $path ) ) {
				throw new \InvalidArgumentException( 'Caricamento di "' . AttachmentRules::display_name( $f['name'] ) . '" non riuscito: riprova.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$size = (int) filesize( $path );
			$mime = self::real_mime( $path );
			$err  = AttachmentRules::check( $f['name'], $size, $mime, Limits::get( 'attach_max_mb' ) * 1048576 );
			if ( $err ) {
				throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$hash = (string) hash_file( 'sha256', $path );
			if ( in_array( $hash, $existing, true ) || isset( $seen[ $hash ] ) ) {
				throw new \InvalidArgumentException( 'Il file "' . AttachmentRules::display_name( $f['name'] ) . '" è già allegato.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$seen[ $hash ] = true;
			$out[]         = array( 'name' => AttachmentRules::display_name( $f['name'] ), 'tmp_name' => $path, 'size' => $size, 'mime' => $mime, 'sha256' => $hash );
		}
		if ( $count + count( $out ) > Limits::get( 'attach_max_per_tx' ) ) {
			throw new \InvalidArgumentException( 'Al massimo ' . Limits::get( 'attach_max_per_tx' ) . ' allegati per movimento.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $out;
	}

	/** Salva i file già controllati con prepare(). @return int[] id degli allegati */
	public static function add( int $tx_id, array $prepared ): array {
		if ( ! $prepared ) {
			return array();
		}
		$tx = self::db()->get_row( self::db()->prepare( 'SELECT id, voided_at FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx_id ), ARRAY_A );
		if ( ! $tx || $tx['voided_at'] ) {
			throw new \InvalidArgumentException( 'Movimento non trovato o annullato.' );
		}
		$dir = self::ensure_dir();
		$ids = array();
		foreach ( $prepared as $f ) {
			$stored = AttachmentRules::stored_name( bin2hex( random_bytes( 16 ) ) );
			$target = self::store_file( $f, $dir, $stored );
			if ( '' === $target ) {
				throw new \InvalidArgumentException( 'Impossibile salvare "' . $f['name'] . '".' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
			self::db()->insert(
				Db::t( 'attachments' ),
				array(
					'transaction_id' => $tx_id, 'original_name' => $f['name'], 'stored_name' => $stored, 'mime' => $f['mime'], 'size_bytes' => $f['size'],
					'sha256' => $f['sha256'], 'uploaded_by' => get_current_user_id() ?: null, 'created_at' => Db::now(),
				)
			);
			$id    = (int) self::db()->insert_id;
			$ids[] = $id;
			Audit::log( 'attachment.added', 'transaction', $tx_id, array( 'attachment' => $id, 'bytes' => $f['size'], 'type' => $f['mime'] ) );
		}
		return $ids;
	}

	/** Cancella dal disco un file salvato con {@see save_upload()} (documenti sostituiti o eliminati, foto dei beni). */
	public static function delete_stored( ?string $stored ): void {
		$name = AttachmentRules::stored_name( (string) $stored );
		if ( '' !== $name ) {
			wp_delete_file( self::dir() . '/' . $name );
		}
	}

	/**
	 * Manda al browser un file della cartella privata e termina. I permessi li controlla chi chiama, prima.
	 *
	 * @param string $path     percorso del file (già controllato)
	 * @param string $ext      estensione originale (decide il tipo di contenuto)
	 * @param string $filename nome con cui il browser lo salva
	 * @param bool   $inline   aprirlo nel browser invece di scaricarlo (solo i tipi che lo permettono)
	 */
	public static function stream( string $path, string $ext, string $filename, bool $inline = false ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( DocumentRules::SERVE_AS[ $ext ] ?? 'application/octet-stream' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( $inline && DocumentRules::is_inline( $ext ) ? 'inline' : 'attachment' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- lettura in streaming di un file privato
		exit;
	}

	/** Tipo reale di un file (letto dal contenuto), '' se il server non sa leggerlo. */
	public static function mime_of( string $path ): string {
		return self::real_mime( $path );
	}

	/**
	 * Salva nella cartella privata un file caricato (campo di un modulo) già controllato, con nome casuale e senza estensione.
	 *
	 * @param array $f name, tmp_name, size, mime
	 * @return string nome con cui è salvato
	 * @throws \InvalidArgumentException
	 */
	public static function save_upload( array $f ): string {
		$dir    = self::ensure_dir();
		$stored = AttachmentRules::stored_name( bin2hex( random_bytes( 16 ) ) );
		$target = self::store_file( $f, $dir, $stored );
		if ( '' === $target ) {
			throw new \InvalidArgumentException( 'Impossibile salvare "' . AttachmentRules::display_name( (string) $f['name'] ) . '".' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		return $stored;
	}

	/**
	 * Mette il file caricato nella cartella privata con le funzioni di WordPress (wp_handle_upload per i file arrivati dal browser,
	 * wp_handle_sideload per gli altri). Il nome salvato è già quello casuale, il tipo è stato controllato da {@see prepare()}.
	 *
	 * @return string percorso del file salvato, '' se non è riuscito
	 */
	private static function store_file( array $f, string $dir, string $stored ): string {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$to_dir = function ( array $u ) use ( $dir ): array {
			$u['path']   = $dir;
			$u['subdir'] = '';
			$u['url']    = '';
			return $u;
		};
		add_filter( 'upload_dir', $to_dir );
		$file    = array( 'name' => $stored, 'type' => (string) $f['mime'], 'tmp_name' => (string) $f['tmp_name'], 'error' => 0, 'size' => (int) $f['size'] );
		$handler = is_uploaded_file( $file['tmp_name'] ) ? 'wp_handle_upload' : 'wp_handle_sideload';
		$copy    = '';
		if ( 'wp_handle_sideload' === $handler ) { // il file di partenza resta dov'è: WordPress sposta quello che riceve
			$copy = wp_tempnam( $stored );
			if ( ! copy( $file['tmp_name'], $copy ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				remove_filter( 'upload_dir', $to_dir );
				return '';
			}
			$file['tmp_name'] = $copy;
		}
		$res     = $handler(
			$file,
			array(
				'test_form'                => false,
				'test_type'                => false, // il tipo reale è già stato controllato; il nome salvato non ha estensione
				'unique_filename_callback' => function () use ( $stored ) {
					return $stored;
				},
			)
		);
		remove_filter( 'upload_dir', $to_dir );
		if ( ! is_array( $res ) || isset( $res['error'] ) || empty( $res['file'] ) ) {
			return '';
		}
		return (string) $res['file'];
	}

	// ---------- Lettura ----------

	public static function get( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Allegati attivi di un movimento, in ordine di caricamento. */
	public static function list_for( int $tx_id ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE transaction_id = %d AND removed_at IS NULL ORDER BY id', $tx_id ),
			ARRAY_A
		) ?: array();
	}

	/** @param int[] $tx_ids @return array<int,array[]> movimento => allegati attivi */
	public static function map_for( array $tx_ids ): array {
		$tx_ids = array_values( array_filter( array_map( 'intval', $tx_ids ) ) );
		if ( ! $tx_ids ) {
			return array();
		}
		$in   = implode( ',', $tx_ids );
		$rows = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'attachments' ) . " WHERE removed_at IS NULL AND transaction_id IN ($in) ORDER BY id", ARRAY_A ) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['transaction_id'] ][] = $r;
		}
		return $out;
	}

	public static function path_of( array $a ): string {
		return self::dir() . '/' . AttachmentRules::stored_name( (string) $a['stored_name'] );
	}

	public static function remove( int $id ): void {
		$a = self::get( $id );
		if ( ! $a || $a['removed_at'] ) {
			throw new \InvalidArgumentException( 'Allegato non trovato.' );
		}
		self::db()->update( Db::t( 'attachments' ), array( 'removed_at' => Db::now(), 'removed_by' => get_current_user_id() ?: null ), array( 'id' => $id ) );
		Audit::log( 'attachment.removed', 'transaction', (int) $a['transaction_id'], array( 'attachment' => $id ) );
	}

	// ---------- Apertura (solo con permesso) ----------

	public static function url( int $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_attachment', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'asem_attachment_' . $id );
	}

	/** Chi gestisce il plugin vede tutto; il tesoriere solo gli allegati delle spese registrate da lui. */
	public static function can_open( ?array $a ): bool {
		if ( current_user_can( Plugin::CAP_OPS ) ) {
			return true;
		}
		if ( ! $a || ! current_user_can( 'asem_add_expense', 0 ) ) {
			return false;
		}
		$by = self::db()->get_var( self::db()->prepare( 'SELECT created_by FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', (int) $a['transaction_id'] ) );
		return null !== $by && (int) $by === get_current_user_id();
	}

	/**
	 * I file di un campo `<input type="file">` della richiesta, con i valori ripuliti: nome come testo semplice, percorso temporaneo
	 * e dimensione con il loro tipo. Il contenuto viene poi controllato da prepare() (tipo reale, dimensione, duplicati).
	 *
	 * @return array ['files' => [['name','tmp_name','size'], ...], 'errors' => string[]]
	 */
	public static function request_files( string $key ): array {
		if ( empty( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- il nonce lo controlla chi gestisce l'azione
			return array( 'files' => array(), 'errors' => array() );
		}
		$n = AttachmentRules::normalize_files( $_FILES[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ogni valore viene ripulito qui sotto
		foreach ( $n['files'] as $i => $f ) {
			$n['files'][ $i ] = array(
				'name'     => sanitize_text_field( AttachmentRules::display_name( (string) $f['name'] ) ),
				'tmp_name' => (string) $f['tmp_name'],
				'size'     => absint( $f['size'] ),
			);
		}
		return $n;
	}

	/** File caricati con i campi `docs` (scelti) e `shots` (scattati con la fotocamera), uniti nella struttura di un solo campo. */
	public static function from_request(): ?array {
		$all = array();
		foreach ( array( 'docs', 'shots' ) as $k ) {
			$n = self::request_files( $k );
			if ( $n['errors'] ) {
				throw new \InvalidArgumentException( $n['errors'][0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			foreach ( $n['files'] as $f ) {
				$all['name'][]     = $f['name'];
				$all['tmp_name'][] = $f['tmp_name'];
				$all['size'][]     = $f['size'];
				$all['error'][]    = UPLOAD_ERR_OK;
			}
		}
		return $all ?: null;
	}

	public static function handle_download(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$a  = self::get( $id );
		if ( ! self::can_open( $a ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_attachment_' . $id );
		$path = $a ? self::path_of( $a ) : '';
		if ( ! $a || $a['removed_at'] || '' === basename( $path ) || ! is_readable( $path ) ) {
			wp_die( 'Allegato non trovato.', 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		$name = AttachmentRules::display_name( $a['original_name'] );
		header( 'Content-Type: ' . $a['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- lettura/scrittura in streaming di file grandi
		exit;
	}
}

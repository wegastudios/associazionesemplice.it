<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Archivio documenti: statuto, regolamenti, verbali firmati, moduli. I file stanno nella cartella privata di `uploads`
 * (nome casuale, senza estensione) e si aprono solo da {@see Documents::handle_download()}, dopo il controllo dei permessi in {@see Access}.
 * Un documento eliminato esce dagli elenchi ma il file resta sul disco (come gli allegati dei movimenti).
 */
final class Documents {

	const ACTION = 'asem_document';

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_download' ) ); // i documenti pubblici si aprono anche senza accesso
	}

	// ---------- Caricamento ----------

	/**
	 * Controlla il file del campo `$field` di un modulo prima di registrare qualsiasi cosa.
	 *
	 * @return array|null name, tmp_name, size, mime (null se non è stato scelto nessun file)
	 * @throws \InvalidArgumentException
	 */
	public static function prepare_upload( string $field = 'file' ): ?array {
		if ( empty( $_FILES[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}
		$n = AttachmentRules::normalize_files( $_FILES[ $field ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		if ( $n['errors'] ) {
			throw new \InvalidArgumentException( $n['errors'][0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! $n['files'] ) {
			return null;
		}
		$f    = $n['files'][0];
		$path = $f['tmp_name'];
		if ( '' === $path || ! is_readable( $path ) ) {
			throw new \InvalidArgumentException( 'Caricamento non riuscito: riprova.' );
		}
		$size = (int) filesize( $path );
		$mime = Attachments::mime_of( $path );
		$err  = DocumentRules::check( $f['name'], $size, $mime, Limits::get( 'attach_max_mb' ) * 1048576 );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( 'name' => AttachmentRules::display_name( $f['name'] ), 'tmp_name' => $path, 'size' => $size, 'mime' => $mime );
	}

	// ---------- Registro ----------

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in, ?array $file, ?int $user_id = null ): int {
		$title = mb_substr( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), 0, 190 );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Scrivi il titolo del documento.' );
		}
		$vis = (string) ( $in['visibility'] ?? '' );
		if ( ! DocumentRules::is_visibility( $vis ) ) {
			throw new \InvalidArgumentException( 'Scegli chi può vedere il documento.' );
		}
		$date = trim( (string) ( $in['doc_date'] ?? '' ) );
		if ( '' !== $date && ! LoanRules::is_date( $date ) ) {
			throw new \InvalidArgumentException( 'Data del documento non valida.' );
		}
		$d = array(
			'title'       => $title,
			'category'    => mb_substr( trim( sanitize_text_field( (string) ( $in['category'] ?? '' ) ) ), 0, 80 ),
			'doc_date'    => '' === $date ? null : $date,
			'visibility'  => $vis,
			'description' => mb_substr( trim( sanitize_text_field( (string) ( $in['description'] ?? '' ) ) ), 0, 255 ),
		);
		$db = self::db();
		if ( $id ) {
			$old = self::get( $id );
			if ( ! $old ) {
				throw new \InvalidArgumentException( 'Documento non trovato.' );
			}
			if ( $file ) {
				$d['file_name']   = $file['name'];
				$d['file_stored'] = Attachments::save_upload( $file );
				$d['file_size']   = $file['size'];
			}
			$db->update( Db::t( 'documents' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
			if ( $file ) {
				Attachments::delete_stored( $old['file_stored'] ); // il file sostituito non resta sul disco (né nelle copie di sicurezza)
			}
			Audit::log( 'document.updated', 'document', $id, array( 'visibility' => $vis, 'file_replaced' => (bool) $file ) );
			return $id;
		}
		if ( ! $file ) {
			throw new \InvalidArgumentException( 'Scegli il file da caricare.' );
		}
		$d['file_name']   = $file['name'];
		$d['file_stored'] = Attachments::save_upload( $file );
		$d['file_size']   = $file['size'];
		if ( ! $db->insert( Db::t( 'documents' ), $d + array( 'uploaded_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare il documento (errore del database).' );
		}
		$id = (int) $db->insert_id;
		Audit::log( 'document.created', 'document', $id, array( 'visibility' => $vis ) );
		return $id;
	}

	public static function delete( int $id ): void {
		$doc = self::get( $id );
		if ( ! $doc ) {
			throw new \InvalidArgumentException( 'Documento non trovato.' );
		}
		self::db()->update( Db::t( 'documents' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Attachments::delete_stored( (string) $doc['file_stored'] ); // il file non resta sul disco (né nelle copie di sicurezza)
		Audit::log( 'document.deleted', 'document', $id );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'documents' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** @return array[] documenti, per categoria e dal più recente */
	public static function all( string $category = '', string $visibility = '' ): array {
		$where = array( 'deleted_at IS NULL' );
		$args  = array();
		if ( '' !== $category ) {
			$where[] = 'category = %s';
			$args[]  = $category;
		}
		if ( '' !== $visibility && DocumentRules::is_visibility( $visibility ) ) {
			$where[] = 'visibility = %s';
			$args[]  = $visibility;
		}
		$sql = 'SELECT * FROM ' . Db::t( 'documents' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY category, COALESCE(doc_date, DATE(created_at)) DESC, id DESC';
		return self::db()->get_results( $args ? self::db()->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	/** Le categorie in uso più quelle proposte. @return string[] */
	public static function categories(): array {
		$used = self::db()->get_col( 'SELECT DISTINCT category FROM ' . Db::t( 'documents' ) . " WHERE deleted_at IS NULL AND category <> ''" ) ?: array();
		return array_values( array_unique( array_merge( DocumentRules::default_categories(), array_map( 'strval', $used ) ) ) );
	}

	/**
	 * I documenti che l'utente può leggere, raggruppati per categoria.
	 *
	 * @param string $only_visibility se indicata, solo quelli con quella visibilità (per l'elenco pubblico: «public»)
	 * @return array<string,array[]>
	 */
	public static function readable_grouped( string $only_visibility = '' ): array {
		$out = array();
		foreach ( self::all( '', $only_visibility ) as $d ) {
			if ( Access::can_read_document( $d ) ) {
				$out[ '' !== (string) $d['category'] ? (string) $d['category'] : 'Altro' ][] = $d;
			}
		}
		return $out;
	}

	// ---------- Apertura (solo con permesso) ----------

	public static function url( int $id ): string {
		return add_query_arg( array( 'action' => self::ACTION, 'id' => $id ), admin_url( 'admin-post.php' ) );
	}

	public static function handle_download(): void {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- sola lettura: il controllo è il permesso sul documento
		$doc = self::get( $id );
		if ( ! $doc ) {
			wp_die( 'Documento non trovato.', 404 );
		}
		if ( ! Access::can_read_document( $doc ) ) {
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( self::url( $id ) ) );
				exit;
			}
			wp_die( 'Non hai il permesso di aprire questo documento.', 403 );
		}
		$path = Attachments::dir() . '/' . AttachmentRules::stored_name( (string) $doc['file_stored'] );
		if ( '' === basename( $path ) || ! is_readable( $path ) || ! is_file( $path ) ) {
			wp_die( 'File non trovato.', 404 );
		}
		$ext  = AttachmentRules::extension( (string) $doc['file_name'] );
		$type = DocumentRules::SERVE_AS[ $ext ] ?? 'application/octet-stream';
		$name = DocumentRules::download_name( (string) $doc['title'], (string) $doc['file_name'] );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( DocumentRules::is_inline( $ext ) ? 'inline' : 'attachment' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- lettura in streaming di un file privato
		exit;
	}
}

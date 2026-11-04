<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) dell'archivio documenti: chi può leggere, quali file si accettano, nomi. Il resto sta in Documents. */
final class DocumentRules {

	const STAFF   = 'staff';
	const MEMBERS = 'members';
	const PUBLIC  = 'public';

	const MAX_BYTES = 10485760;

	public static function visibilities(): array {
		return array( self::STAFF => 'Solo segreteria', self::MEMBERS => 'Soci', self::PUBLIC => 'Pubblico (con lo shortcode)' );
	}

	public static function is_visibility( string $v ): bool {
		return isset( self::visibilities()[ $v ] );
	}

	/** Le categorie proposte (se ne possono scrivere altre). */
	public static function default_categories(): array {
		return array( 'Statuto', 'Regolamenti', 'Verbali firmati', 'Moduli', 'Altro' );
	}

	/** Chi può leggere un documento: la segreteria tutti; il pubblico solo quelli «Pubblico»; i soci in regola anche quelli per i soci. */
	public static function can_read( string $visibility, bool $is_staff, bool $is_active_member ): bool {
		if ( $is_staff || self::PUBLIC === $visibility ) {
			return true;
		}
		return self::MEMBERS === $visibility && $is_active_member;
	}

	/** Estensione => tipi reali accettati (letti dal contenuto). `''`: va bene anche se il server non sa riconoscerlo. */
	const TYPES = array(
		'pdf'  => array( 'application/pdf' ),
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'webp' => array( 'image/webp' ),
		'txt'  => array( 'text/plain' ),
		'doc'  => array( 'application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream', '' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream', '' ),
		'odt'  => array( 'application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream', '' ),
		'xls'  => array( 'application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream', '' ),
		'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream', '' ),
		'ods'  => array( 'application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/octet-stream', '' ),
	);

	/** Tipo con cui si serve il file (deciso dall'estensione controllata, mai da quello inviato dal browser). */
	const SERVE_AS = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'txt'  => 'text/plain; charset=utf-8',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
	);

	/** @return string|null messaggio d'errore, oppure null se il file va bene */
	public static function check( string $name, int $size, string $real_mime, int $max_bytes = self::MAX_BYTES ): ?string {
		$shown = AttachmentRules::display_name( $name );
		if ( $size <= 0 ) {
			return 'Il file "' . $shown . '" è vuoto.';
		}
		if ( $size > $max_bytes ) {
			return 'Il file "' . $shown . '" supera i ' . (int) ( $max_bytes / 1048576 ) . ' MB.';
		}
		$ext = AttachmentRules::extension( $name );
		if ( ! isset( self::TYPES[ $ext ] ) || ! in_array( $real_mime, self::TYPES[ $ext ], true ) ) {
			return 'Il file "' . $shown . '" non è ammesso: si accettano PDF, foto (JPG, PNG, WebP), testi e fogli (Word, Excel, LibreOffice).';
		}
		return null;
	}

	/** Il browser può mostrare il file nella pagina (PDF e foto); gli altri si scaricano. */
	public static function is_inline( string $ext ): bool {
		return in_array( $ext, array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ), true );
	}

	/** Nome del file scaricato: titolo del documento più estensione. */
	public static function download_name( string $title, string $original ): string {
		$ext  = AttachmentRules::extension( $original );
		$base = trim( (string) preg_replace( '/[^\p{L}\p{N}._ -]+/u', '', $title ) );
		$base = '' === $base ? 'documento' : mb_substr( $base, 0, 100 );
		return $base . ( '' !== $ext ? '.' . $ext : '' );
	}
}

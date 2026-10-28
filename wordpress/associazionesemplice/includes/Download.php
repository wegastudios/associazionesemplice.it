<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Invio del contenuto di un file generato dal plugin (CSV, PDF, Excel, calendario, JSON) dopo le intestazioni di download.
 * Non è HTML e non va in una pagina: si scrive così com'è nello stream di uscita, senza echo.
 */
final class Download {

	public static function bytes( string $bytes ): void {
		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream di uscita, non un file su disco
		if ( false === $out ) {
			return;
		}
		fwrite( $out, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** File CSV con il BOM, così Excel riconosce l'UTF-8. */
	public static function csv( string $csv ): void {
		self::bytes( "\xEF\xBB\xBF" . $csv );
	}
}

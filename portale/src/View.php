<?php
namespace Portale;

/** Mostra una pagina dentro il layout comune. */
final class View {

	public static function render( string $name, array $vars = array() ): void {
		extract( $vars, EXTR_SKIP );
		ob_start();
		require __DIR__ . '/../views/' . $name . '.php';
		$content = ob_get_clean();
		self::headers( 'text/html; charset=utf-8' );
		require __DIR__ . '/../views/layout.php';
	}

	/** Intestazioni di sicurezza comuni a pagine, API e scarichi (HSTS lo manda già il server web). */
	public static function security(): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
		// Niente plugin, niente <base> altrui, nessuna incorniciatura; gli script di monitoraggio configurati dal pannello restano ammessi.
		header( "Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'none'" );
	}

	public static function headers( string $type ): void {
		header( 'Content-Type: ' . $type );
		self::security();
		if ( ! Config::get( 'indexable', false ) ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet' );
		}
	}
}

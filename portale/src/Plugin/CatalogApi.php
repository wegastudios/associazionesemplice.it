<?php
namespace Portale\Plugin;

use Portale\View;

/** `GET /wordpress/api/catalogo/v1`: catalogo pubblico del plugin, senza dati personali, cacheabile (ETag). La firma EdDSA arriva in un passo successivo. */
final class CatalogApi {

	const PATH = '/wordpress/api/catalogo/v1';

	/** Corpo JSON canonico e relativo ETag. @return array{0:string,1:string} */
	public static function render( Catalog $catalog ): array {
		$body = (string) json_encode( $catalog->document(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return array( $body, '"' . substr( hash( 'sha256', $body ), 0, 32 ) . '"' );
	}

	public static function serve( Catalog $catalog ): void {
		list( $body, $etag ) = self::render( $catalog );
		View::security();
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'ETag: ' . $etag );
		if ( ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) === $etag ) {
			http_response_code( 304 );
			return;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		echo $body;
	}
}

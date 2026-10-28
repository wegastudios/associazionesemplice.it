<?php
namespace Portale;

/** Indirizzo IP del visitatore, su cui poggiano tutti i limiti orari (accesso, moduli, verifiche dei plugin). */
final class ClientIp {

	/**
	 * Senza «trust_forwarded_for» vale l'indirizzo della connessione. Con il proxy fidato si legge X-Forwarded-For dal lato del proxy:
	 * ogni proxy aggiunge in fondo l'indirizzo che ha visto, mentre le voci a sinistra le sceglie chi manda la richiesta e basterebbe
	 * scriverne una diversa a ogni tentativo per aggirare i limiti. «forwarded_hops» è il numero di proxy fidati in fila (di solito 1).
	 *
	 * @param array $server il contenuto di $_SERVER
	 */
	public static function from( array $server, bool $trustForwarded, int $hops = 1 ): string {
		$remote = (string) ( $server['REMOTE_ADDR'] ?? '' );
		if ( ! $trustForwarded || empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote;
		}
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ), 'strlen' ) );
		$pos  = count( $list ) - max( 1, $hops );
		return $pos >= 0 && false !== filter_var( $list[ $pos ], FILTER_VALIDATE_IP ) ? $list[ $pos ] : $remote;
	}
}

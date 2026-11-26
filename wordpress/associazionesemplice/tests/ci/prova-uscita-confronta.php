<?php
/**
 * Confronta due fotografie di prova-uscita-istantanea.php: dopo l'aggiornamento le righe dei dati, le somme e il dovuto, versato e saldo di ogni
 * iscrizione ai corsi devono essere uguali. Uso: wp eval-file prova-uscita-confronta.php <prima> <dopo>. Esce con errore e l'elenco delle differenze.
 */

$read = function ( string $n ): array {
	$f = WP_CONTENT_DIR . '/prove/istantanea-' . preg_replace( '/[^a-z0-9-]/', '', $n ) . '.json';
	return is_readable( $f ) ? (array) json_decode( (string) file_get_contents( $f ), true ) : array();
};
$a    = $read( (string) ( $args[0] ?? '' ) );
$b    = $read( (string) ( $args[1] ?? '' ) );
$diff = array();
if ( ! $a || ! $b ) {
	WP_CLI::error( 'Fotografie mancanti.' );
}
foreach ( array( 'tables', 'money' ) as $k ) {
	foreach ( (array) $a[ $k ] as $key => $v ) {
		if ( ( $b[ $k ][ $key ] ?? null ) !== $v ) {
			$diff[] = $k . '.' . $key . ': prima ' . wp_json_encode( $v ) . ', dopo ' . wp_json_encode( $b[ $k ][ $key ] ?? null );
		}
	}
}
$courses = 0;
foreach ( (array) $a['courses'] as $key => $v ) {
	if ( ( $b['courses'][ $key ] ?? null ) !== $v ) {
		$courses++;
		if ( $courses <= 25 ) {
			$diff[] = 'iscrizione ' . $key . ' (dovuto, versato, saldo): prima ' . wp_json_encode( $v ) . ', dopo ' . wp_json_encode( $b['courses'][ $key ] ?? null );
		}
	}
}
WP_CLI::log( 'Confronto: ' . count( (array) $a['tables'] ) . ' tabelle, ' . count( (array) $a['money'] ) . ' somme, ' . count( (array) $a['courses'] ) . ' iscrizioni ai corsi.' );
if ( $diff ) {
	foreach ( $diff as $d ) {
		WP_CLI::warning( $d );
	}
	WP_CLI::error( count( $diff ) . ' differenze' . ( $courses > 25 ? ' (iscrizioni diverse in tutto: ' . $courses . ')' : '' ) . '.' );
}
WP_CLI::success( 'Dati, somme e quote dei corsi uguali dopo l\'aggiornamento.' );

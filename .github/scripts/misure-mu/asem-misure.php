<?php
/**
 * Solo per le misure di velocità in CI (workflow «Misure di velocità»): se la richiesta porta l'intestazione X-Asem-Misure,
 * in fondo alla pagina scrive un commento con numero di query, millisecondi e memoria di picco di quella richiesta.
 * Non fa nient'altro e non esiste su nessun sito vero: il file si monta solo nel WordPress di prova del job.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! empty( $_SERVER['HTTP_X_ASEM_MISURE'] ) ) {
	ob_start(
		function ( $html ) {
			global $wpdb;
			$t0 = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			return $html . sprintf( "\n<!-- asem-misure q=%d ms=%d mem=%d -->", $wpdb ? (int) $wpdb->num_queries : 0, (int) round( ( microtime( true ) - $t0 ) * 1000 ), memory_get_peak_usage() );
		}
	);
}

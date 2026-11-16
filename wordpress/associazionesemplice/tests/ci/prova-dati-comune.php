<?php
/** Funzioni comuni alle prove sui dati (prima e dopo l'aggiornamento). */

use AssociazioneSemplice\Db;

function dati_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

/** Impronta dei dati: conteggi, somme e un hash delle righe dei soci (identica prima e dopo, se niente si perde). */
function dati_impronta(): array {
	global $wpdb;
	$p  = Db::t( 'people' );
	$tx = Db::t( 'transactions' );
	return array(
		'soci'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p" ),
		'iscrizioni' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) ),
		'movimenti'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tx" ),
		'importi'    => (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM $tx" ),
		'prenotaz'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) ),
		'hash_soci'  => md5( wp_json_encode( $wpdb->get_results( "SELECT first_name, last_name, email FROM $p ORDER BY id", ARRAY_N ) ) ),
	);
}

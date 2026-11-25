<?php
/** Dopo le prove nel browser: i dati nel database sono quelli attesi. Esce con errore se no. */
use AssociazioneSemplice\Db;

global $wpdb;
$d   = get_option( 'prove_gruppo' );
$bad = 0;
$ok  = function ( bool $c, string $t ) use ( &$bad ): void {
	WP_CLI::log( ( $c ? '- ok ' : '- MANCA ' ) . $t );
	$bad += $c ? 0 : 1;
};
$rows = function ( int $sid ) use ( $wpdb ): array {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", $sid ), ARRAY_A );
};
$won = 0;
foreach ( $d['gare'] as $i => $g ) {
	$r    = $rows( (int) $g['sid'] );
	$who  = array_unique( array_map( function ( $b ) { return (int) ( $b['booked_by'] ?: $b['person_id'] ); }, $r ) );
	$ok( 2 === count( $r ) && 1 === count( $who ), 'gara ' . $i . ': due posti presi da uno solo dei due soci (prenotati ' . count( $r ) . ', soci ' . count( $who ) . ')' );
	$won += count( $r ) > 0 ? 1 : 0;
}
$ospiti = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . " WHERE type = 'guest' AND first_name LIKE 'Gara%'" );
$ok( $ospiti === $won, 'gare: gli ospiti in anagrafica sono solo quelli di chi ha vinto (' . $ospiti . ' per ' . $won . ' gare): di chi ha perso non resta traccia' );
foreach ( array( 1440, 375 ) as $w ) {
	$r = $rows( (int) $d[ 'senzajs' . $w ]['sid'] );
	$ok( 2 <= count( $r ), 'senza JavaScript ' . $w . ': prenotazioni registrate (' . count( $r ) . ')' );
	$r = $rows( (int) $d[ 'tastiera' . $w ]['sid'] );
	$ok( 1 === count( $r ) && (int) $r[0]['person_id'] === (int) $d['paola'], 'tastiera ' . $w . ': prenotazione registrata' );
	$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', (int) $d[ 'conferma' . $w ]['sid'], (int) $d['gino'] ), ARRAY_A );
	$ok( ! empty( $b['confirmed_at'] ) && (int) $b['booked_by'] === (int) $d['paola'], 'conferma ' . $w . ': prenotazione confermata da Gino, contributo di Paola' );
	$t = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d AND voided_at IS NULL', (int) $d[ 'paga' . $w ]['sid'], (int) $d['gino'] ), ARRAY_A );
	$ok( 1 === count( $t ) && null === $t[0]['payer_person_id'] && 500 === (int) $t[0]['amount_cents'], 'paga chi partecipa ' . $w . ': incasso a nome di Gino' );
}
$ok( 0 === (int) $wpdb->get_var( 'SELECT no_booking_by_others FROM ' . Db::t( 'people' ) . ' WHERE id = ' . (int) $d['gino'] ), 'profilo: la spunta è tornata accesa' );
if ( $bad ) {
	WP_CLI::error( $bad . ' prove non riuscite' );
}

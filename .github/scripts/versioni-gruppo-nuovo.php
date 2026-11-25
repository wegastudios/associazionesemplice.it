<?php
/** Con la build nuova: una prenotazione di gruppo sui dati nati con la 1.3.38. */
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

require_once __DIR__ . '/prova-dati-comune.php';
global $wpdb;
wp_set_current_user( 1 );
$ids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Db::t( 'people' ) . ' ORDER BY id LIMIT 3' ) );
$act = Plugin::activities();
foreach ( $wpdb->get_results( 'SELECT booked_by, declined_at, confirmed_at FROM ' . Db::t( 'bookings' ), ARRAY_A ) as $b ) {
	dati_ok( null === $b['booked_by'] && null === $b['declined_at'] && null === $b['confirmed_at'], 'prenotazioni di prima: campi nuovi vuoti' );
}
dati_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . ' WHERE no_booking_by_others <> 0' ), 'soci di prima: prenotazioni degli altri accese' );
$aid = (int) $act->create( array( 'name' => 'Gruppo tra versioni', 'social_year' => Settings::social_year()->label(), 'kind' => 'event', 'fee_cents' => 700, 'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + 20 * 86400 ), 'capacity' => 10 ) ) );
$sid = (int) $act->sessions( $aid )[0]['id'];
$act->book_many( $sid, array( $ids[0], $ids[1] ), $ids[0], array() );
update_option( 'prova_gruppo_v', array( 'sid' => $sid, 'aid' => $aid, 'ids' => $ids ), false );
dati_ok( 2 === count( $act->unpaid_bookings_for_person( $ids[0] ) ) - count( array_filter( $act->unpaid_bookings_for_person( $ids[0] ), function ( $b ) use ( $sid ) { return (int) $b['session_id'] !== $sid; } ) ), 'due contributi a carico di chi ha prenotato' );
WP_CLI::success( 'gruppo creato' );

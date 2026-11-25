<?php
/** Con la 1.3.38 rimessa sopra lo schema 77: legge, prenota, incassa senza errori e non perde le colonne nuove. */
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;

require_once __DIR__ . '/prova-dati-comune.php';
global $wpdb;
wp_set_current_user( 1 );
$v   = get_option( 'prova_gruppo_v' );
$act = Plugin::activities();
dati_ok( '1.3.38' === ASEM_VERSION, 'versione 1.3.38 attiva' );
WP_CLI::log( '- schema pronto per la 1.3.38: ' . ( Plugin::schema_ready() ? 'si' : 'no' ) . ', salvato ' . get_option( 'asem_db_version' ) );
dati_ok( Plugin::schema_ready(), 'la 1.3.38 non resta bloccata sullo schema' );
dati_ok( 2 === count( array_filter( $act->bookings_for_session( (int) $v['sid'] ), function ( $b ) { return 'booked' === $b['status']; } ) ), 'le prenotazioni di gruppo si vedono' );
$act->book( (int) $v['sid'], (int) $v['ids'][2] );
dati_ok( $act->has_active_booking( (int) $v['sid'], (int) $v['ids'][2] ), 'con la 1.3.38 si prenota' );
$l  = Plugin::ledger();
$n  = $l->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $l->accounts()[0]['id'], 'method' => 'cash', 'person_id' => (int) $v['ids'][2], 'lines' => array( array( 'category_id' => $l->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 700, 'activity_id' => (int) $v['aid'], 'session_id' => (int) $v['sid'] ) ) ) );
dati_ok( $n > 0, 'con la 1.3.38 si incassa' );
dati_ok( (int) $v['ids'][0] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT booked_by FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', (int) $v['sid'], (int) $v['ids'][1] ) ), 'chi aveva prenotato resta scritto' );
WP_CLI::success( 'ritorno a posto' );

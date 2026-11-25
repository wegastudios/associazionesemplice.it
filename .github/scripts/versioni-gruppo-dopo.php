<?php
/** Di nuovo con la build nuova: chi risponde dei contributi è ancora quello giusto. */
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;

require_once __DIR__ . '/prova-dati-comune.php';
global $wpdb;
wp_set_current_user( 1 );
$v   = get_option( 'prova_gruppo_v' );
$act = Plugin::activities();
$of  = function ( int $pid ) use ( $act, $v ): int {
	return count( array_filter( $act->unpaid_bookings_for_person( $pid ), function ( $b ) use ( $v ) { return (int) $b['session_id'] === (int) $v['sid']; } ) );
};
dati_ok( Plugin::schema_ready(), 'schema pronto' );
dati_ok( 2 === $of( (int) $v['ids'][0] ) && 0 === $of( (int) $v['ids'][1] ) && 0 === $of( (int) $v['ids'][2] ), 'contributi: due a chi ha prenotato, nessuno all\'altro socio, quello incassato con la 1.3.38 è pagato' );
WP_CLI::success( 'dati di gruppo conservati' );

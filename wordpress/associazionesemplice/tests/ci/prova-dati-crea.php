<?php
/**
 * Prima dell'aggiornamento (build 1.3.25 installata): crea soci, un corso con prenotazione e due incassi in prima nota e ne salva l'impronta.
 *   wp eval-file wp-content/prove/prova-dati-crea.php
 */

use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

require_once __DIR__ . '/prova-dati-comune.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$people = Plugin::people();
$acts   = Plugin::activities();
$ledger = Plugin::ledger();
$sy     = Settings::social_year( current_time( 'Y-m-d' ) )->label();
$cash   = $ledger->accounts()[0];
$cat    = $ledger->category_id_of_kind( 'activity_fee' );

$ids = array();
foreach ( array( array( 'Anna', 'Rossi' ), array( 'Bruno', 'Bianchi' ), array( 'Carla', 'Verdi' ) ) as $n ) {
	$ids[] = $people->create( array( 'type' => 'ordinary', 'first_name' => $n[0], 'last_name' => $n[1], 'email' => strtolower( $n[0] ) . '.prova@example.com' ) );
	$people->set_membership( end( $ids ), Settings::membership_year()->label(), true );
}
dati_ok( 3 === count( array_filter( $ids ) ), 'tre soci con iscrizione creati' );
$aid = $acts->create( array( 'name' => 'Cena di prova', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 1500, 'session' => array( 'session_date' => current_time( 'Y-m-d' ), 'capacity' => 10 ) ) );
$sid = (int) $acts->sessions( $aid )[0]['id'];
foreach ( $ids as $pid ) {
	$acts->book( $sid, $pid );
}
foreach ( array( 1500, 2500 ) as $i => $cents ) {
	$tx = $ledger->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $ids[ $i ], 'lines' => array( array( 'category_id' => $cat, 'amount_cents' => $cents, 'activity_id' => $aid, 'session_id' => $sid ) ) ) );
	dati_ok( $tx > 0, "incasso da $cents centesimi registrato" );
}
$imp = dati_impronta();
dati_ok( $imp['soci'] >= 3 && $imp['movimenti'] >= 2 && $imp['importi'] >= 4000 && $imp['prenotaz'] >= 1, 'impronta prima dell\'aggiornamento: ' . wp_json_encode( $imp ) );
update_option( 'asem_prova_impronta', array( 'impronta' => $imp, 'db_version' => (string) get_option( 'asem_db_version', '' ), 'versione' => ASEM_VERSION ), false );
WP_CLI::success( 'Dati creati con la versione ' . ASEM_VERSION . ' (DB ' . get_option( 'asem_db_version' ) . ').' );

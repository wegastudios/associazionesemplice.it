<?php
/**
 * Pagamento online simulato (nessun gestore: la riga del pagamento e la sua conferma) nella coppia attiva.
 *   wp eval-file versioni-gruppo-pagamento.php vecchio|nuovo
 * vecchio = Pro 1.3.24 + gratuito nuovo: altri soci non offerti; per sé, ospite e minore il pagamento si registra giusto. nuovo = Pro nuovo: anche l'altro socio.
 */
use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Guardians;
use AssociazioneSemplice\PaymentItems;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

require_once __DIR__ . '/prova-dati-comune.php';
global $wpdb;
wp_set_current_user( 1 );
$nuovo = 'nuovo' === ( $args[0] ?? '' );
Settings::update( array( 'license_key' => 'SANDBOX-0001', 'guests_enabled' => 1 ) );
dati_ok( defined( 'ASEM_PRO_VERSION' ) && Plugin::payments() instanceof \AssociazioneSemplice\PaymentService, 'pagamenti del Pro ' . ASEM_PRO_VERSION . ' col gratuito ' . ASEM_VERSION );
dati_ok( $nuovo === ActivityService::others_allowed(), $nuovo ? 'prenotazione per altri soci offerta' : 'prenotazione per altri soci NON offerta col Pro vecchio' );
ob_start();
do_action( 'admin_notices' );
$avviso = (string) ob_get_clean();
dati_ok( $nuovo === ( false === strpos( $avviso, 'per far prenotare ai soci anche altri soci' ) ), $nuovo ? 'nessun avviso in amministrazione' : 'avviso «Aggiorna il Pro» in amministrazione' );

$peo  = Plugin::people();
$act  = Plugin::activities();
$sy   = Settings::social_year()->label();
$tag  = $nuovo ? 'N' : 'V';
$mk   = function ( array $d ) use ( $peo, $sy ): int {
	$id = (int) $peo->create( $d + array( 'type' => 'ordinary' ) );
	if ( 'ordinary' === ( $d['type'] ?? 'ordinary' ) ) {
		$peo->set_membership( $id, $sy, true );
	}
	return $id;
};
$paga   = $mk( array( 'first_name' => 'Pia', 'last_name' => 'Paga' . $tag, 'email' => 'pia' . $tag . '@example.com', 'birth_date' => '1980-01-01' ) );
$altro  = $mk( array( 'first_name' => 'Aldo', 'last_name' => 'Altro' . $tag, 'email' => 'aldo' . $tag . '@example.com', 'birth_date' => '1980-01-01' ) );
$ospite = $mk( array( 'type' => 'guest', 'host_person_id' => $paga, 'first_name' => 'Olmo', 'last_name' => 'Ospite' . $tag, 'phone' => $nuovo ? '335 1110001' : '335 1110002' ) );
$minore = $mk( array( 'first_name' => 'Mina', 'last_name' => 'Minore' . $tag, 'birth_date' => ( (int) gmdate( 'Y' ) - 10 ) . '-02-02' ) );
Guardians::link( $minore, $paga );
$aid = (int) $act->create( array( 'name' => 'Pagamento ' . $tag, 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 900, 'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + 20 * 86400 ), 'capacity' => 10 ) ) );
$sid = (int) $act->sessions( $aid )[0]['id'];
$act->book_many( $sid, array( $paga, $ospite, $minore, $altro ), $paga, array() ); // l'altro socio c'è già: prenotazione fatta prima (o con un Pro poi tornato indietro)
$dues = array_values( array_filter( Plugin::payments()->dues_for( $peo->get( $paga ) ), function ( $i ) use ( $sid ) { return (int) ( $i['session_id'] ?? 0 ) === $sid; } ) );
$chi  = array_map( function ( $i ) { return (int) $i['person_id']; }, $dues );
sort( $chi );
$attesi = $nuovo ? array( $paga, $altro, $ospite, $minore ) : array( $paga, $ospite, $minore );
sort( $attesi );
dati_ok( $chi === $attesi, $nuovo ? 'si paga online per sé, ospite, minore e altro socio' : 'si paga online per sé, ospite e minore; la voce dell\'altro socio non c\'è' );
$tot = PaymentItems::total( $dues );
$now = Db::now();
$pub = PaymentItems::new_public_id();
$wpdb->insert( Db::t( 'payments' ), array( 'public_id' => $pub, 'provider' => 'woocommerce', 'status' => 'pending', 'amount_cents' => $tot, 'currency' => 'EUR', 'payer_person_id' => $paga, 'payer_user_id' => 0, 'items' => wp_json_encode( $dues ), 'provider_ref' => 'PROVA-' . $tag, 'created_at' => $now, 'updated_at' => $now ) );
dati_ok( '' === (string) $wpdb->last_error, 'pagamento creato ' . $wpdb->last_error );
$prima = array_sum( array_column( Plugin::ledger()->balances(), 'balance' ) );
Plugin::payments()->finalize_external( $pub, $tot, 'PROVA-' . $tag );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE public_id = %s', $pub ), ARRAY_A );
dati_ok( 'paid' === $row['status'] && 0 === (int) $row['review'], 'pagamento registrato senza «da controllare» (' . $row['status'] . ', review ' . $row['review'] . ', ' . $row['error'] . ')' );
dati_ok( $tot === (int) ( array_sum( array_column( Plugin::ledger()->balances(), 'balance' ) ) - $prima ), 'la cassa cresce esattamente di quanto pagato (' . $tot . ')' );
foreach ( $attesi as $pid ) {
	$t = $wpdb->get_results( $wpdb->prepare( 'SELECT person_id, payer_person_id, amount_cents FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d AND voided_at IS NULL', $sid, $pid ), ARRAY_A );
	dati_ok( 1 === count( $t ) && 900 === (int) $t[0]['amount_cents'] && 0 === (int) $act->booking_left( $sid, $pid ), 'persona ' . $pid . ': un movimento da 9,00, niente più da pagare (pagante ' . var_export( $t[0]['payer_person_id'], true ) . ')' );
	if ( $nuovo && $pid !== $paga ) {
		dati_ok( $paga === (int) $t[0]['payer_person_id'], 'persona ' . $pid . ': il pagante è chi ha prenotato' );
	}
}
if ( ! $nuovo ) {
	dati_ok( 900 === (int) $act->booking_left( $sid, $altro ) && array() === $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d', $sid, $altro ) ), 'altro socio: nessun movimento, il contributo resta a chi ha prenotato' );
}
WP_CLI::success( 'pagamento simulato a posto' );

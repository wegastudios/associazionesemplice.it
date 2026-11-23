<?php
/**
 * Prove di: esportazioni a blocchi (prima nota e soci uguali a prima, riga per riga), saldi letti una volta per richiesta,
 * aggiornamento dello schema a tappe. Si carica da smoke.php.
 */

use AssociazioneSemplice\Admin\Exports;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Install;
use AssociazioneSemplice\Plugin;

global $wpdb;

wp_set_current_user( 1 );

// ---------- Prima nota a blocchi ----------
$sx_exp = 0;
foreach ( $ledger->categories() as $sx_c ) {
	if ( 'general_cost' === $sx_c['kind'] && ! $sx_exp ) {
		$sx_exp = (int) $sx_c['id'];
	}
}
$sx_ym   = substr( $today, 0, 7 );
$sx_from = $sx_ym . '-01';
$sx_to   = $sx_ym . '-28';
$sx_base = count( $ledger->rows( $sx_from, $sx_to ) ); // movimenti già presenti nel mese
for ( $sx_i = 0; $sx_i < 1005; $sx_i++ ) { // più di un blocco da 1.000, con molte righe nello stesso giorno (conta anche l'identificativo)
	$ledger->record_expense( array( 'date' => $sx_ym . '-0' . ( 1 + $sx_i % 3 ), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $sx_exp, 'amount_cents' => 100 + $sx_i, 'description' => 'Blocco ' . $sx_i ) );
}
$sx_all = $ledger->rows( $sx_from, $sx_to, null, true );
asem_ok( 1005 + $sx_base === count( $sx_all ), 'esportazioni: la prova ha 1005 movimenti nello stesso mese' );
$sx_ids = array();
$sx_after = null;
$sx_pages = 0;
do {
	$sx_page = $ledger->rows( $sx_from, $sx_to, null, true, 400, 0, $sx_after );
	foreach ( $sx_page as $sx_r ) {
		$sx_ids[]  = (int) $sx_r['id'];
		$sx_after = array( $sx_r['tx_date'], (int) $sx_r['id'] );
	}
	++$sx_pages;
} while ( 400 === count( $sx_page ) );
asem_ok( $sx_ids === array_map( 'intval', array_column( $sx_all, 'id' ) ) && (int) ceil( ( 1005 + $sx_base ) / 400 ) === $sx_pages, 'esportazioni: la prima nota letta a pagine «dopo l\'ultimo letto» è la stessa lista, nello stesso ordine' );
list( $sx_name, $sx_csv ) = Exports::ledger( $sx_from, $sx_to );
$sx_lines = explode( "\r\n", rtrim( $sx_csv, "\r\n" ) );
asem_ok( 1006 + $sx_base === count( $sx_lines ) && 0 === strpos( $sx_lines[0], 'Data;Tipo;Conto' ) && false !== strpos( $sx_csv, 'Blocco 0' ) && false !== strpos( $sx_csv, 'Blocco 1004' ), 'esportazioni: il file della prima nota ha intestazione e tutte le righe, anche oltre il primo blocco' );
$sx_dates = array();
foreach ( array_slice( $sx_lines, 1 ) as $sx_l ) {
	$sx_f = explode( ';', $sx_l );
	$sx_dates[] = $sx_f[0];
}
$sx_sorted = $sx_dates;
sort( $sx_sorted );
asem_ok( $sx_sorted === $sx_dates, 'esportazioni: righe in ordine cronologico' );
asem_ok( 1005 === substr_count( $sx_csv, 'Blocco ' ), 'esportazioni: nessuna riga persa né ripetuta tra un blocco e l\'altro' );

// ---------- Soci a blocchi ----------
$sx_people = Plugin::people();
$sx_full   = $sx_people->search();
$sx_got    = array();
for ( $sx_o = 0; $sx_o < count( $sx_full ) + 7; $sx_o += 7 ) {
	foreach ( $sx_people->search( array( 'limit' => 7, 'offset' => $sx_o ) ) as $sx_r ) {
		$sx_got[] = (int) $sx_r['id'];
	}
}
asem_ok( $sx_got === array_map( 'intval', array_column( $sx_full, 'id' ) ) && count( $sx_full ) > 7, 'esportazioni: l\'anagrafica letta a pagine è la stessa lista, nello stesso ordine' );
list( , $sx_pcsv ) = Exports::people();
asem_ok( count( $sx_full ) + 1 === count( explode( "\r\n", rtrim( $sx_pcsv, "\r\n" ) ) ), 'esportazioni: il file dei soci ha una riga per persona' );

// ---------- Saldi una volta per richiesta ----------
$GLOBALS['sx_scans'] = 0;
add_filter(
	'query',
	function ( $q ) {
		if ( is_string( $q ) && false !== strpos( $q, 'GROUP BY account_id' ) ) {
			++$GLOBALS['sx_scans'];
		}
		return $q;
	}
);
$sx_b1 = $ledger->balances();
$sx_b2 = $ledger->balances( null, true );
$sx_b3 = $ledger->balances();
asem_ok( 1 === $GLOBALS['sx_scans'] && $sx_b1 === $sx_b3, 'saldi: la somma dei movimenti si legge una volta sola per richiesta, qualunque conto si chieda' );
$ledger->balances( $sx_ym . '-02' );
asem_ok( 2 === $GLOBALS['sx_scans'], 'saldi: una data diversa è un\'altra lettura' );
$sx_cash_before = 0;
foreach ( $sx_b1 as $sx_a ) {
	if ( (int) $sx_a['id'] === (int) $cash['id'] ) {
		$sx_cash_before = (int) $sx_a['balance'];
	}
}
$ledger->record_expense( array( 'date' => $sx_ym . '-05', 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $sx_exp, 'amount_cents' => 777, 'description' => 'Saldo dopo scrittura' ) );
$sx_cash_after = 0;
foreach ( $ledger->balances() as $sx_a ) {
	if ( (int) $sx_a['id'] === (int) $cash['id'] ) {
		$sx_cash_after = (int) $sx_a['balance'];
	}
}
asem_ok( $sx_cash_before - 777 === $sx_cash_after && 3 === $GLOBALS['sx_scans'], 'saldi: dopo un nuovo movimento il saldo si rilegge e non resta quello vecchio' );

// ---------- Aggiornamento dello schema a tappe ----------
delete_option( Install::PROGRESS_OPTION );
wp_clear_scheduled_hook( Install::CRON_HOOK );
update_option( Install::DB_VERSION_OPTION, '67' );
Install::run_upgrade( '67', 0.0 ); // tempo finito subito: un passaggio solo
$sx_prog = json_decode( (string) get_option( Install::PROGRESS_OPTION, '' ), true );
asem_ok( '67' === (string) get_option( Install::DB_VERSION_OPTION ) && ! Install::schema_ready(), 'schema a tappe: a metà la versione resta quella vecchia (lo schema non è pronto)' );

// Con lo schema a metà le scritture rispondono «riprova tra un minuto» invece di scrivere colonne che potrebbero non esserci; le letture proseguono.
$sx_msg = 'Aggiornamento in corso, riprova tra un minuto.';
asem_ok( ! Plugin::schema_ready() && $sx_msg === Plugin::write_refusal( 'asem_save_person' ) && $sx_msg === Plugin::write_refusal( 'asem_front_book' ) && $sx_msg === Plugin::write_refusal( 'asem_checkin' ) && $sx_msg === Plugin::write_refusal( 'asem_long_job' ), 'schema a metà: i moduli che scrivono rispondono «riprova tra un minuto»' );
asem_ok( '' === Plugin::write_refusal( 'asem_export' ) && '' === Plugin::write_refusal( 'asem_cert_csv' ) && '' === Plugin::write_refusal( 'asem_person_search' ) && '' === Plugin::write_refusal( 'heartbeat' ), 'schema a metà: scaricare, cercare e le azioni degli altri plugin restano com\'erano' );
$sx_old_page = $GLOBALS['pagenow'] ?? null;
$sx_old_req  = $_REQUEST;
$GLOBALS['pagenow'] = 'admin-post.php';
$_REQUEST['action'] = 'asem_save_person';
$sx_asked           = Plugin::requested_action();
$GLOBALS['pagenow'] = $sx_old_page;
$_REQUEST           = $sx_old_req;
asem_ok( 'asem_save_person' === $sx_asked && $sx_msg === Plugin::write_refusal( $sx_asked ), 'schema a metà: una richiesta vera a admin-post con un\'azione del plugin viene rifiutata' );
$sx_rest = Plugin::rest_guard( null, null, new WP_REST_Request( 'POST', '/associazionesemplice/v1/webhooks/stripe' ) );
asem_ok( is_wp_error( $sx_rest ) && 503 === (int) ( $sx_rest->get_error_data()['status'] ?? 0 ) && $sx_msg === $sx_rest->get_error_message(), 'schema a metà: i segnali dei pagamenti ricevono 503 (chi li manda riprova)' );
asem_ok( null === Plugin::rest_guard( null, null, new WP_REST_Request( 'GET', '/associazionesemplice/v1/me' ) ) && null === Plugin::rest_guard( null, null, new WP_REST_Request( 'POST', '/wp/v2/posts' ) ), 'schema a metà: le letture REST e le rotte degli altri plugin passano' );
asem_ok( \AssociazioneSemplice\SchemaGuard::job_allowed( 'asem_schema_upgrade', Plugin::schema_ready() ) && ! \AssociazioneSemplice\SchemaGuard::job_allowed( 'asem_send_reminders', Plugin::schema_ready() ), 'schema a metà: dei lavori periodici gira solo quello che porta avanti l\'aggiornamento' );
asem_ok( is_array( $sx_prog ) && '67' === $sx_prog['from'] && 1 === (int) $sx_prog['next'], 'schema a tappe: il punto raggiunto si salva con la versione di partenza' );
asem_ok( false !== wp_next_scheduled( Install::CRON_HOOK ), 'schema a tappe: un evento di WP-Cron porta avanti il resto' );
$sx_guard = 0;
while ( ! Install::schema_ready() && $sx_guard++ < 40 ) {
	Install::run_upgrade( '70', 0.0 ); // chiamato con un\'altra versione: conta quella salvata col punto raggiunto
}
asem_ok( Install::schema_ready() && Install::DB_VERSION === (string) get_option( Install::DB_VERSION_OPTION ) && 10 === $sx_guard, 'schema a tappe: un passaggio per volta (il primo è già fatto sopra), poi la versione del codice [' . $sx_guard . ']' );
asem_ok( '' === (string) get_option( Install::PROGRESS_OPTION, '' ), 'schema a tappe: finito, il punto raggiunto non resta' );
asem_ok( Plugin::schema_ready() && '' === Plugin::write_refusal( 'asem_save_person' ) && null === Plugin::rest_guard( null, null, new WP_REST_Request( 'POST', '/associazionesemplice/v1/webhooks/stripe' ) ), 'schema pronto: le scritture tornano a passare' );
wp_clear_scheduled_hook( Install::CRON_HOOK );

// ---------- Schema del Pro a metà: stesse risposte di quello del gratuito ----------
if ( class_exists( '\AssociazioneSemplice\ProBoot' ) ) {
	$sx_pro_ver = get_option( \AssociazioneSemplice\ProBoot::SCHEMA_OPTION, '' );
	asem_ok( Plugin::schema_ready() && \AssociazioneSemplice\ProBoot::schema_ready(), 'schema del Pro: a posto, le scritture passano' );
	update_option( \AssociazioneSemplice\ProBoot::SCHEMA_OPTION, '19' );
	asem_ok( Install::schema_ready() && ! Plugin::schema_ready(), 'schema del Pro a metà: il gratuito è pronto, ma lo schema nel suo insieme no' );
	asem_ok( $sx_msg === Plugin::write_refusal( 'asem_save_person' ) && $sx_msg === Plugin::write_refusal( 'asem_front_book' ) && '' === Plugin::write_refusal( 'asem_export' ) && '' === Plugin::write_refusal( 'asem_cert_csv' ), 'schema del Pro a metà: i moduli che scrivono aspettano, scaricare no' );
	$sx_rest = Plugin::rest_guard( null, null, new WP_REST_Request( 'POST', '/associazionesemplice/v1/webhooks/stripe' ) );
	asem_ok( is_wp_error( $sx_rest ) && 503 === (int) ( $sx_rest->get_error_data()['status'] ?? 0 ) && null === Plugin::rest_guard( null, null, new WP_REST_Request( 'GET', '/associazionesemplice/v1/me' ) ), 'schema del Pro a metà: le scritture REST ricevono 503, le letture passano' );
	asem_ok( \AssociazioneSemplice\SchemaGuard::job_allowed( 'asem_schema_upgrade', Plugin::schema_ready() ) && ! \AssociazioneSemplice\SchemaGuard::job_allowed( 'asem_send_reminders', Plugin::schema_ready() ), 'schema del Pro a metà: dei lavori periodici gira solo quello che porta avanti l\'aggiornamento' );
	update_option( \AssociazioneSemplice\ProBoot::SCHEMA_OPTION, $sx_pro_ver );
	asem_ok( Plugin::schema_ready() && '' === Plugin::write_refusal( 'asem_save_person' ) && null === Plugin::rest_guard( null, null, new WP_REST_Request( 'POST', '/associazionesemplice/v1/webhooks/stripe' ) ), 'schema del Pro pronto: le scritture tornano a passare' );
}

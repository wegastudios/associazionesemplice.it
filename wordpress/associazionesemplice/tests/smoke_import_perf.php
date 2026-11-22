<?php
/**
 * Prove di velocità dell'importazione e del suo annullo: quante richieste al database costa ogni riga, e che il risultato non cambi.
 * Le righe di prova sono poche (qualche centinaio): il costo per riga è lo stesso di un file da migliaia. Si carica da smoke.php.
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\ImportService;
use AssociazioneSemplice\Plugin;

global $wpdb;

$GLOBALS['asem_ip_bounds'] = true; // i limiti di richieste a riga valgono (la prima versione di questo file misurava soltanto)

/** Esegue $fn e dice quante richieste al database ha fatto. @return array{0:mixed,1:int} */
function asem_ip_count( callable $fn ): array {
	global $wpdb;
	$before = $wpdb->num_queries;
	$res    = $fn();
	return array( $res, $wpdb->num_queries - $before );
}

// ---------- Prima nota: 300 movimenti e 10 giroconti ----------

$ip_cash = (string) Plugin::ledger()->accounts()[0]['name'];
$ip_year = gmdate( 'Y' );
$ip_csv  = "Data;Tipo;Conto;Modalità;Voce;Importo;Descrizione;Riferimento;N. tessera;Attività;Competenza\r\n";
for ( $i = 0; $i < 300; $i++ ) {
	$ip_csv .= sprintf( '%02d/03/%s;%s;%s;;%s;%s;Prova veloce %d;PV-%d;;;', 1 + $i % 28, $ip_year, 0 === $i % 3 ? 'Uscita' : 'Entrata', $ip_cash, 0 === $i % 3 ? 'Costo generale' : '', number_format( 5 + $i % 40, 2, ',', '' ), $i, $i ) . "\r\n";
}
for ( $i = 0; $i < 10; $i++ ) {
	$ip_csv .= sprintf( '%02d/04/%s;Giroconto in uscita;%s;;Giroconto;100,00;Giro veloce %d;;;;', 1 + $i, $ip_year, $ip_cash, $i ) . "\r\n";
	$ip_csv .= sprintf( '%02d/04/%s;Giroconto in entrata;Conto Prova Veloce;;Giroconto;100,00;Giro veloce %d;;;;', 1 + $i, $ip_year, $i ) . "\r\n";
}
$ip_path = sys_get_temp_dir() . '/asem-ip-' . wp_generate_password( 6, false ) . '.csv';
file_put_contents( $ip_path, $ip_csv );
$ip_prev = ImportService::preview_file( $ip_path, 'prova-veloce.csv', array() );
unlink( $ip_path );
$ip_cnt = $ip_prev['ledger']['summary']['counts'];
asem_ok( 300 === (int) $ip_cnt['create'] && 10 === (int) $ip_cnt['transfer'], 'import veloce: l\'anteprima legge 300 movimenti e 10 giroconti [' . wp_json_encode( $ip_cnt ) . ']' );

list( $ip_res, $ip_q ) = asem_ip_count( function () use ( $ip_prev ) {
	return ImportService::apply( $ip_prev, array( 'keep_balances' => false ) );
} );
asem_ok( 300 === (int) $ip_res['ledger']['created'] && 10 === (int) $ip_res['ledger']['transfers'] && array() === $ip_res['ledger']['failed'], 'import veloce: 300 movimenti e 10 giroconti scritti' );
$ip_per = $ip_q / 310;
WP_CLI::log( sprintf( 'misura - import prima nota: %d richieste per 310 righe, %.1f a riga', $ip_q, $ip_per ) );
if ( ! empty( $GLOBALS['asem_ip_bounds'] ) ) {
	asem_ok( $ip_per < 5.0, 'import veloce: la prima nota costa meno di 5 richieste a riga (' . round( $ip_per, 1 ) . ')' );
}
$ip_batch = (int) $ip_res['batch_id'];
asem_ok( 320 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE import_batch = %d AND voided_at IS NULL', $ip_batch ) ), 'import veloce: le righe sono legate all\'import (300 movimenti e 20 righe di giroconto)' );

list( $ip_undo, $ip_uq ) = asem_ip_count( function () use ( $ip_batch ) {
	return ImportService::undo( $ip_batch );
} );
asem_ok( 310 === (int) $ip_undo['voided'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE import_batch = %d AND voided_at IS NULL', $ip_batch ) ), 'import veloce: l\'annullo toglie tutti i movimenti, i giroconti con entrambe le righe (' . (int) $ip_undo['voided'] . ')' );
asem_ok( 1 === (int) $ip_undo['accounts_removed'], 'import veloce: l\'annullo toglie il conto nato con l\'import' );
$ip_uper = $ip_uq / 310;
WP_CLI::log( sprintf( 'misura - annullo prima nota: %d richieste per 310 righe, %.1f a riga', $ip_uq, $ip_uper ) );
if ( ! empty( $GLOBALS['asem_ip_bounds'] ) ) {
	asem_ok( $ip_uper < 8.0, 'import veloce: l\'annullo della prima nota costa meno di 8 richieste a riga (' . round( $ip_uper, 1 ) . ')' );
}

// ---------- Soci: 200 righe con iscrizione ----------

$ip_n   = 200;
$ip_pv  = asem_imp_preview( 'Vel', 800000, $ip_n, '9' );
$ip_seq = count( ImportService::people_sequence( $ip_pv['people']['plan'] ) );
list( $ip_pres, $ip_pq ) = asem_ip_count( function () use ( $ip_pv ) {
	return ImportService::apply( $ip_pv, array( 'mark_members' => true ) );
} );
asem_ok( (int) $ip_pres['people']['created'] > 100 && $ip_seq > 100, 'import veloce: i soci sono entrati (' . (int) $ip_pres['people']['created'] . ' nuovi)' );
$ip_pper = $ip_pq / max( 1, $ip_seq );
WP_CLI::log( sprintf( 'misura - import soci: %d richieste per %d righe, %.1f a riga', $ip_pq, $ip_seq, $ip_pper ) );
if ( ! empty( $GLOBALS['asem_ip_bounds'] ) ) {
	asem_ok( $ip_pper < 30.0, 'import veloce: i soci costano meno di 30 richieste a riga (' . round( $ip_pper, 1 ) . ')' );
}
$ip_pbatch = (int) $ip_pres['batch_id'];
$ip_snap   = asem_imp_count( 'Vel' );
list( $ip_pundo, $ip_puq ) = asem_ip_count( function () use ( $ip_pbatch ) {
	return ImportService::undo( $ip_pbatch );
} );
asem_ok( (int) $ip_pundo['people_removed'] === (int) $ip_pres['people']['created'] && asem_imp_count( 'Vel' ) === $ip_snap - (int) $ip_pres['people']['created'], 'import veloce: l\'annullo toglie tutti i soci nati con l\'import e lascia gli altri' );
$ip_puper = $ip_puq / max( 1, (int) $ip_pres['people']['created'] );
WP_CLI::log( sprintf( 'misura - annullo soci: %d richieste per %d soci, %.1f a socio', $ip_puq, (int) $ip_pres['people']['created'], $ip_puper ) );
if ( ! empty( $GLOBALS['asem_ip_bounds'] ) ) {
	asem_ok( $ip_puper < 25.0, 'import veloce: l\'annullo dei soci costa meno di 25 richieste a socio (' . round( $ip_puper, 1 ) . ')' );
}

// ---------- Anteprima e piano fuori dal database ----------

if ( class_exists( '\\AssociazioneSemplice\\ImportPlans' ) ) {
	$ip_big = $ip_pv;
	$ip_big['padding'] = str_repeat( 'x', 6 * 1048576 ); // più del pacchetto massimo di MySQL 5.7 (4 MB)
	$ip_tok = 'perf' . strtolower( wp_generate_password( 6, false ) );
	\AssociazioneSemplice\ImportPlans::save_preview( 1, $ip_tok, $ip_big );
	$ip_back = \AssociazioneSemplice\ImportPlans::load_preview( 1, $ip_tok );
	asem_ok( $ip_back === $ip_big, 'anteprima grande (6 MB): scritta e riletta identica' );
	asem_ok( false === get_transient( \AssociazioneSemplice\ImportPlans::transient_name( 1, $ip_tok ) ), 'anteprima grande: non passa dalla tabella delle opzioni' );
	asem_ok( null === \AssociazioneSemplice\ImportPlans::load_preview( 2, $ip_tok ), 'anteprima: un altro utente non la vede' );
	\AssociazioneSemplice\ImportPlans::drop_preview( 1, $ip_tok );
	asem_ok( null === \AssociazioneSemplice\ImportPlans::load_preview( 1, $ip_tok ), 'anteprima: dopo l\'importazione non resta nulla' );
	\AssociazioneSemplice\ImportPlans::save_plan( $ip_big );
	asem_ok( \AssociazioneSemplice\ImportPlans::load_plan() === $ip_big && false === get_option( \AssociazioneSemplice\ImportJob::OPT_PLAN, false ), 'piano grande (6 MB): sta su file, non in un\'opzione' );
	\AssociazioneSemplice\ImportPlans::drop_plan();
	asem_ok( null === \AssociazioneSemplice\ImportPlans::load_plan(), 'piano: tolto a fine lavoro' );
	$ip_dir = \AssociazioneSemplice\PrivateFolder::dir() . '/import';
	asem_ok( ! is_dir( $ip_dir ) || ! glob( $ip_dir . '/*.dat' ), 'anteprima e piano: nessun file dimenticato nella cartella riservata' );
}

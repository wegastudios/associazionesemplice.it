<?php
/**
 * Fotografia dei dati per la prova d'aggiornamento dalla 1.3.39 (workflow «Plugin update tests», job «uscita-corsi»): righe delle tabelle di dati,
 * somme della prima nota e dei fondi, e per ogni iscrizione ai corsi dovuto, versato e saldo calcolati dal plugin installato.
 * Usa solo funzioni presenti anche nella 1.3.39. Uso: wp eval-file prova-uscita-istantanea.php <nome> (scrive wp-content/prove/istantanea-<nome>.json).
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;

$name = isset( $args[0] ) ? preg_replace( '/[^a-z0-9-]/', '', (string) $args[0] ) : 'x';
wp_set_current_user( 1 );
$db  = Db::db();
$out = array( 'tables' => array(), 'money' => array(), 'courses' => array() );
foreach ( array( 'people', 'activities', 'enrollments', 'sessions', 'bookings', 'transactions', 'funds', 'fund_entries', 'memberships', 'guardians', 'credit_entries', 'receipts' ) as $t ) {
	$tbl = Db::t( $t );
	if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
		$out['tables'][ $t ] = (int) $db->get_var( "SELECT COUNT(*) FROM $tbl" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabella interna
	}
}
foreach ( $db->get_results( 'SELECT type, COUNT(*) n, COALESCE(SUM(amount_cents),0) s FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL GROUP BY type', ARRAY_A ) ?: array() as $r ) {
	$out['money'][ 'tx_' . $r['type'] ] = array( (int) $r['n'], (int) $r['s'] );
}
$out['money']['funds'] = (int) $db->get_var( 'SELECT COALESCE(SUM(CASE WHEN kind IN (\'release\', \'payout\') THEN -cents ELSE cents END),0) FROM ' . Db::t( 'fund_entries' ) );
$acts = Plugin::activities();
foreach ( $db->get_col( "SELECT id FROM " . Db::t( 'activities' ) . " WHERE kind = 'course'" ) as $aid ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nessun valore esterno
	foreach ( $acts->status_for_activity( (int) $aid ) as $s ) {
		$sum = (array) ( $s['summary'] ?? array() );
		$out['courses'][ $aid . ':' . (int) $s['enrollment']['person_id'] ] = array( (int) ( $sum['total_due'] ?? 0 ), (int) ( $sum['total_paid'] ?? 0 ), (int) ( $sum['balance'] ?? 0 ) );
	}
}
ksort( $out['courses'] );
file_put_contents( WP_CONTENT_DIR . '/prove/istantanea-' . $name . '.json', wp_json_encode( $out, JSON_PRETTY_PRINT ) );
WP_CLI::log( 'Fotografia «' . $name . '»: ' . count( $out['tables'] ) . ' tabelle, ' . count( $out['courses'] ) . ' iscrizioni ai corsi, schema ' . get_option( 'asem_db_version' ) . '.' );

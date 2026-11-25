<?php
/**
 * Accesso di prova da tesoriere: un socio inventato («Teresa Demo») con un utente WordPress collegato e la carica di tesoriere
 * (registra incassi e spese dall'area soci, iscrive i soci: niente altri permessi). Idempotente e solo additivo: si può lanciare
 * anche a demo in uso, senza azzerare. Ogni notte lo rifà reset.sh dopo provision.php.
 * Uso: wp eval-file ~/demo-provision/tesoriere.php --user=setup_tmp
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\AssociazioneSemplice\Plugin' ) ) {
	WP_CLI::error( 'Il plugin non è attivo.' );
}
use AssociazioneSemplice\Access;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;

const DEMO_PASS_TESORIERE = 'Tesoriere-Demo-2026';
$email  = 'tesoriere@demo.invalid';
$people = Plugin::people();
global $wpdb;

// 1. Utente WordPress: socio (ruolo «socio» del plugin), mai amministratore.
$user = get_user_by( 'login', 'tesoriere' );
if ( ! $user ) {
	$uid = wp_insert_user( array( 'user_login' => 'tesoriere', 'user_email' => $email, 'user_pass' => DEMO_PASS_TESORIERE, 'display_name' => 'Teresa Demo (tesoriere)', 'first_name' => 'Teresa', 'last_name' => 'Demo', 'role' => Plugin::ROLE_MEMBER ) );
	if ( is_wp_error( $uid ) ) {
		WP_CLI::error( $uid->get_error_message() );
	}
	update_user_meta( (int) $uid, 'asem_demo_user', '1' );
} else {
	$uid = (int) $user->ID;
}

// 2. Scheda del socio e collegamento all'utente.
$pid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'people' ) . ' WHERE email = %s AND deleted_at IS NULL', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
if ( ! $pid ) {
	$y   = (int) gmdate( 'Y' );
	$pid = (int) $people->create( array(
		'type' => 'ordinary', 'first_name' => 'Teresa', 'last_name' => 'Demo', 'email' => $email, 'city' => 'Roma', 'province' => 'RM',
		'joined_on' => ( $y - 2 ) . '-10-20', 'card_number' => $people->next_free_card(),
	) );
}
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $uid ), array( 'id' => $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

// 3. Carica: solo il permesso di tesoriere.
Access::set_treasurer( $uid, true );

// 4. Quota in regola (la tessera del tesoriere è valida), una sola volta.
$ledger = Plugin::ledger();
$cat    = $ledger->category_id_of_kind( 'membership' );
$acc    = $ledger->default_account_for( 'cash' );
if ( $cat && $acc && ! $people->is_active_member( $pid ) ) {
	try {
		$ledger->record_receipt( array(
			'date' => gmdate( 'Y-m-d', time() - 20 * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'person_id' => $pid,
			'lines' => array( array( 'category_id' => $cat, 'amount_cents' => 2000, 'description' => 'Quota associativa' ) ),
		) );
	} catch ( \Throwable $e ) {
		WP_CLI::warning( 'Quota: ' . $e->getMessage() );
	}
}
WP_CLI::success( sprintf( 'Tesoriere di prova pronto: utente %d, socio %d, in regola: %s.', $uid, $pid, $people->is_active_member( $pid ) ? 'sì' : 'no' ) );

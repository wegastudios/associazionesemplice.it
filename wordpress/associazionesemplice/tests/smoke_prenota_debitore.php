<?php
/**
 * Prenotazione fatta da un socio per un altro: il contributo è a carico di chi prenota e mai dell'altro. Pagamenti, incassi, tolleranza, rimborsi vedono il debito su chi
 * prenota; cassa e prima nota restano quelle di una prenotazione normale (stessi importi, stessa voce, stesso intestatario). Si carica da smoke.php dopo smoke_prenota_gruppo.php.
 */

use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\PaymentItems;
use AssociazioneSemplice\Plugin;

wp_set_current_user( 1 );
$gd_ledger = Plugin::ledger();
$gd_cash   = $gd_ledger->default_account_for( 'cash' );
$gd_cat    = $gd_ledger->category_id_of_kind( 'activity_fee' );
$gd_sum    = function () use ( $gd_ledger ): int {
	return (int) array_sum( array_column( $gd_ledger->balances(), 'balance' ) );
};
$gd_tx     = function ( int $sid, int $pid ) use ( $wpdb ): array {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d AND voided_at IS NULL ORDER BY id', $sid, $pid ), ARRAY_A );
};
$gd_dues   = function ( int $pid, int $sid ) use ( $gb_peo ): array {
	return array_values( array_filter( Plugin::payments()->dues_for( $gb_peo->get( $pid ) ), function ( $i ) use ( $sid ) { return (int) ( $i['session_id'] ?? 0 ) === $sid; } ) );
};
$gd_receipt = function ( int $payer, int $for, int $aid, int $sid, int $cents ) use ( $gd_ledger, $gd_cash, $gd_cat ): void {
	$gd_ledger->record_receipt(
		array(
			'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $gd_cash['id'], 'method' => 'cash', 'person_id' => $payer, 'check_due' => true,
			'lines' => array( array( 'category_id' => $gd_cat, 'amount_cents' => $cents, 'activity_id' => $aid, 'session_id' => $sid, 'person_id' => $for ) ),
		)
	);
};

// ---------- Il debito ----------
list( $gd_a1, $gd_s1 ) = $gb_event( 'Debitore uno', 10, 800 );
$gb_acts->book_many( $gd_s1, array( $gb_booker, $gb_gino ), $gb_booker, array() );
$gd_d = $gd_dues( $gb_booker, $gd_s1 );
asem_ok( 2 === count( $gd_d ) && 1600 === PaymentItems::total( $gd_d ) && array() === $gd_dues( $gb_gino, $gd_s1 ), 'debitore: le due voci sono di chi ha prenotato, nessuna dell\'altro socio' );
$gd_i = array_values( array_filter( $gd_d, function ( $i ) use ( $gb_gino ) { return (int) $i['person_id'] === $gb_gino; } ) )[0];
asem_ok( $gb_booker === (int) $gd_i['payer_id'] && 'socio con tessera n. 7002' === $gd_i['person_name'], 'debitore: la voce resta di chi partecipa, la paga chi ha prenotato e il nome dell\'altro socio non compare' );
asem_ok( 0 === count( array_filter( $gb_acts->unpaid_bookings_for_person( $gb_gino ), function ( $b ) use ( $gd_s1 ) { return (int) $b['session_id'] === $gd_s1; } ) ), 'debitore: in fase di incasso l\'altro socio non deve nulla' );
$gb_acts->confirm_booking( $gd_s1, $gb_gino );
asem_ok( 2 === count( $gd_dues( $gb_booker, $gd_s1 ) ) && array() === $gd_dues( $gb_gino, $gd_s1 ), 'debitore: anche dopo la conferma dell\'interessato il debito resta di chi ha prenotato' );
$gd_err = (string) asem_throws( function () use ( $gd_receipt, $gb_gino, $gd_a1, $gd_s1 ) { $gd_receipt( $gb_gino, $gb_gino, $gd_a1, $gd_s1, 800 ); } );
asem_ok( false !== strpos( $gd_err, 'a carico di chi ha prenotato' ) && array() === $gd_tx( $gd_s1, $gb_gino ), 'debitore: non si incassa dall\'altro socio' );

// ---------- Cassa e prima nota: identiche a una prenotazione normale ----------
list( $gd_a2, $gd_s2 ) = $gb_event( 'Debitore confronto', 10, 800 );
$gb_acts->book( $gd_s2, $gb_rina );
$gd_before = $gd_sum();
$gd_receipt( $gb_rina, 0, $gd_a2, $gd_s2, 800 );
$gd_normal = $gd_tx( $gd_s2, $gb_rina )[0];
asem_ok( $gd_before + 800 === $gd_sum(), 'debitore: cassa di riferimento, prenotazione normale' );
$gd_before = $gd_sum();
$gd_receipt( $gb_booker, $gb_gino, $gd_a1, $gd_s1, 800 );
$gd_rows = $gd_tx( $gd_s1, $gb_gino );
asem_ok( $gd_before + 800 === $gd_sum() && 1 === count( $gd_rows ), 'debitore: la cassa cresce di quanto incassato, con un solo movimento' );
foreach ( array( 'type', 'amount_cents', 'account_id', 'method', 'category_id', 'discount_cents', 'vat_rate', 'vat_cents', 'competence_month', 'social_year' ) as $gd_f ) {
	asem_ok( (string) $gd_normal[ $gd_f ] === (string) $gd_rows[0][ $gd_f ], 'debitore: prima nota invariata, ' . $gd_f );
}
asem_ok( $gb_gino === (int) $gd_rows[0]['person_id'] && $gb_booker === (int) $gd_rows[0]['payer_person_id'], 'debitore: intestatario chi partecipa, pagante chi ha prenotato' );
asem_ok( 1 === count( $gd_dues( $gb_booker, $gd_s1 ) ) && 0 === (int) $gb_acts->booking_left( $gd_s1, $gb_gino ), 'debitore: pagata la voce dell\'altro socio resta solo la propria' );
asem_ok( '' !== (string) asem_throws( function () use ( $gd_receipt, $gb_booker, $gb_gino, $gd_a1, $gd_s1 ) { $gd_receipt( $gb_booker, $gb_gino, $gd_a1, $gd_s1, 800 ); } ) && 1 === count( $gd_tx( $gd_s1, $gb_gino ) ), 'debitore: la stessa voce non si incassa due volte' );
$gd_before = $gd_sum();
$gd_receipt( $gb_booker, 0, $gd_a1, $gd_s1, 800 );
asem_ok( $gd_before + 800 === $gd_sum() && array() === $gd_dues( $gb_booker, $gd_s1 ) && null === $gd_tx( $gd_s1, $gb_booker )[0]['payer_person_id'], 'debitore: la propria voce si incassa come sempre' );

// ---------- Ricevute dei pagamenti online: un gruppo per persona e per pagante ----------
$gd_b = PaymentItems::receipt_batches( array( array( 'type' => PaymentItems::BOOKING, 'person_id' => $gb_booker, 'amount_cents' => 800 ), $gd_i ) );
asem_ok( 2 === count( $gd_b ) && $gb_gino === $gd_b[1][0] && $gb_booker === (int) $gd_b[1][1][0]['payer_id'], 'debitore: pagamento online, voci separate per persona e pagante' );

// ---------- Rimborso: torna a chi ha pagato, cassa in pari ----------
$gd_before = $gd_sum();
ActivityReset::delete_registration( $gd_a1, $gb_gino, $gd_s1, ActivityReset::REFUND );
$gd_exp = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'transactions' ) . " WHERE type = 'expense' AND activity_id = %d ORDER BY id DESC LIMIT 1", $gd_a1 ), ARRAY_A );
asem_ok( $gd_before - 800 === $gd_sum() && 800 === (int) $gd_exp['amount_cents'] && $gb_booker === (int) $gd_exp['person_id'], 'debitore: la restituzione è a chi ha pagato e la cassa scende dello stesso importo' );

// ---------- Versamento di chi pagava prima: non paga il debito di un altro ----------
list( $gd_a3, $gd_s3 ) = $gb_event( 'Debitore versato', 10, 800 );
$gb_acts->book_many( $gd_s3, array( $gb_gino ), $gb_booker, array() );
$gd_receipt( $gb_booker, $gb_gino, $gd_a3, $gd_s3, 800 );
$gb_acts->decline_booking( $gd_s3, $gb_gino );
asem_ok( $gb_acts->paid_by_another( $gd_s3, $gb_gino, $gb_gino ) && ! $gb_acts->paid_by_another( $gd_s3, $gb_gino, $gb_booker ), 'debitore: il versamento di chi aveva prenotato non copre una nuova prenotazione dell\'interessato' );

// ---------- Tolleranza del pagamento ----------
list( $gd_a4, $gd_s4 ) = $gb_event( 'Debitore tolleranza', 10, 800 );
$wpdb->update( Db::t( 'activities' ), array( 'hold_hours' => 1 ), array( 'id' => $gd_a4 ) );
wp_cache_flush();
$gb_acts->book_many( $gd_s4, array( $gb_gino ), $gb_booker, array() );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'bookings' ) . ' SET created_at = DATE_SUB(created_at, INTERVAL 3 HOUR) WHERE session_id = %d', $gd_s4 ) );
$gd_to   = array();
$gd_mail = function ( $null, $atts ) use ( &$gd_to ) {
	$gd_to[ implode( ',', (array) $atts['to'] ) ] = (string) $atts['message'];
	return true;
};
add_filter( 'pre_wp_mail', $gd_mail, 10, 2 );
\AssociazioneSemplice\Holds::release_expired( $gd_s4 );
remove_filter( 'pre_wp_mail', $gd_mail, 10 );
asem_ok( 0 === $gb_count( $gd_s4 ) && isset( $gd_to['paola.gruppo@example.com'] ) && false !== strpos( $gd_to['paola.gruppo@example.com'], 'contributo' ) && false === strpos( $gd_to['paola.gruppo@example.com'], 'Gino' ), 'debitore: scaduta la tolleranza il sollecito va a chi ha prenotato, senza il nome dell\'altro socio' );
asem_ok( isset( $gd_to['gino.gruppo@example.com'] ) && false === strpos( $gd_to['gino.gruppo@example.com'], 'contributo' ), 'debitore: all\'altro socio si dice solo che la prenotazione non vale più' );

wp_set_current_user( 1 );

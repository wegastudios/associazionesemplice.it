<?php
/**
 * Prova del rimborso Stripe in modalità di prova (sandbox), con il vero Stripe e il vero plugin.
 * Lanciata dal workflow «Prove in sandbox» dentro wp-env:
 *   wp eval-file wp-content/sandbox-scripts/stripe-sandbox.php --user=admin
 *
 * La chiave sk_test_… si legge dal file `.stripe-key` accanto a questo script (lo scrive il workflow dal segreto GitHub e lo cancella a fine prova).
 * Non si stampa mai: ogni messaggio passa da scrub(). Con una chiave che non inizia per sk_test_ lo script si ferma prima di chiamare Stripe.
 *
 * Cosa fa: configura il gateway Stripe del plugin, crea un pagamento con il plugin (sessione Checkout vera, in prova), fa pagare lo stesso importo con un
 * PaymentIntent confermato dal server (pm_card_visa), consegna al plugin l'evento firmato (segreto del webhook locale) come farebbe Stripe, poi rimborsa
 * DAL PLUGIN e controlla pagamento, prima nota, doppi rimborsi e riservatezza della chiave.
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\PaymentService;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\ProSettings;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\StripeApi;
use AssociazioneSemplice\StripeWebhook;

define( 'ASEM_TESTING', true );

$keyfile = __DIR__ . '/.stripe-key';
$SK      = is_readable( $keyfile ) ? trim( (string) file_get_contents( $keyfile ) ) : '';
@unlink( $keyfile ); // phpcs:ignore
$GLOBALS['SK'] = $SK; // wp eval-file esegue lo script dentro una funzione: senza questa riga le funzioni sotto non vedrebbero la chiave (global $SK)
if ( ! preg_match( '/^sk_test_[A-Za-z0-9]+$/', $SK ) ) {
	WP_CLI::error( 'La chiave non è una chiave segreta di prova (sk_test_…): mi fermo senza chiamare Stripe.' );
}

/** Toglie la chiave da qualunque testo prima di scriverlo. */
function scrub( string $s ): string {
	global $SK;
	return '' === $SK ? $s : str_replace( $SK, '[chiave]', $s );
}

function ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( scrub( 'FAIL: ' . $msg ) );
	}
	WP_CLI::log( scrub( 'ok - ' . $msg ) );
}

function throws_msg( callable $f ): ?string {
	try {
		$f();
	} catch ( \InvalidArgumentException $e ) {
		return $e->getMessage();
	}
	return null;
}

/** Chiamata diretta a Stripe (per creare l'incasso di prova e rileggere lo stato vero). @return array decodificato */
function stripe( string $method, string $path, ?array $form = null, array $headers = array() ): array {
	global $SK;
	$h    = array_merge( array( 'Authorization' => 'Bearer ' . $SK ), $headers );
	$body = null;
	if ( null !== $form ) {
		$h['Content-Type'] = 'application/x-www-form-urlencoded';
		$body              = http_build_query( $form, '', '&', PHP_QUERY_RFC3986 );
	}
	$r    = Gateways::wp_http( $method, StripeApi::BASE . $path, $h, $body );
	$data = json_decode( $r['body'], true );
	if ( $r['code'] < 200 || $r['code'] >= 300 || ! is_array( $data ) ) {
		$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'risposta ' . $r['code'];
		throw new \RuntimeException( 'Stripe ' . $method . ' ' . $path . ': ' . $msg );
	}
	return $data;
}

try {
	global $wpdb;
	wp_set_current_user( 1 );
	add_filter( 'pre_wp_mail', '__return_true' ); // nessuna email vera dalla prova

	// ---------- Configurazione del gateway, con i mezzi del plugin ----------
	$whsec = 'whsec_' . bin2hex( random_bytes( 16 ) ); // segreto del webhook di prova, solo locale
	Settings::update(
		array(
			'license_key'            => 'SANDBOX-0001', // «standby»: le funzioni Pro sono disponibili come nelle prove del plugin
			'member_wp_users'        => 1,
			'membership_fee_cents'   => 1000,
			'payment_provider'       => 'stripe',
			'stripe_mode'            => 'test',
			'stripe_publishable_key' => 'pk_test_sandboxplaceholder', // non serve al server: il pagamento si fa con la chiave segreta
			'stripe_secret_key'      => $SK,
			'stripe_webhook_secret'  => $whsec,
			'limits'                 => array( 'pay_open_per_hour' => 100 ),
		)
	);
	ok( Edition::has( 'payments' ), 'il Pro è attivo e i pagamenti online sono disponibili' );
	$ps = Plugin::payments();
	ok( $ps instanceof PaymentService && $ps->enabled() && 'stripe' === $ps->provider(), 'Stripe è il gateway attivo del plugin' );
	$raw = (string) wp_json_encode( get_option( Settings::OPTION ) );
	ok( false === strpos( $raw, $SK ) && false === strpos( $raw, $whsec ), 'nel database le chiavi sono cifrate (la chiave in chiaro non c\'è)' );
	$t = Gateways::test( 'stripe', ProSettings::payment_config(), array( Gateways::class, 'wp_http' ) );
	ok( $t['ok'] && false !== strpos( $t['message'], 'prova' ), 'verifica connessione del plugin: ' . $t['message'] );

	// ---------- Un pagamento creato dal plugin (sessione Checkout vera, in prova) ----------
	$people = Plugin::people();
	$pid    = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Sandra', 'last_name' => 'Prova', 'email' => 'sandra.prova@example.com' ) );
	$person = $people->get( $pid );
	$uid    = (int) $person['wp_user_id'];
	ok( $uid > 0, 'socio di prova con il suo utente WordPress' );
	$dues = $ps->dues_for( $person );
	$keys = array_values( array_filter( array_keys( $dues ), function ( $k ) { return 0 === strpos( $k, 'q:' ); } ) );
	ok( 1 === count( $keys ) && 1000 === (int) $dues[ $keys[0] ]['amount_cents'], 'da pagare: la quota associativa di 10,00 € decisa dal server' );
	wp_set_current_user( $uid );
	$url = $ps->create_checkout( $person, $uid, array( $keys[0] ), home_url( '/area/' ) );
	wp_set_current_user( 1 );
	ok( 0 === strpos( $url, 'https://checkout.stripe.com/' ), 'il plugin ha creato la sessione Checkout su Stripe' );
	$tbl = Db::t( 'payments' );
	$pay = $wpdb->get_row( "SELECT * FROM $tbl ORDER BY id DESC LIMIT 1", ARRAY_A );
	$id  = (int) $pay['id'];
	$pub = (string) $pay['public_id'];
	$amt = (int) $pay['amount_cents'];
	ok( 'pending' === $pay['status'] && 'stripe' === $pay['provider'] && 0 === strpos( (string) $pay['provider_ref'], 'cs_test_' ) && 1000 === $amt, 'pagamento in attesa, riferimento cs_test_…, 10,00 €' );

	// ---------- Il pagamento vero (di prova): PaymentIntent confermato dal server ----------
	$pi = stripe(
		'POST',
		'/v1/payment_intents',
		array(
			'amount'                    => $amt,
			'currency'                  => 'eur',
			'payment_method'            => 'pm_card_visa',
			'confirm'                   => 'true',
			'automatic_payment_methods' => array( 'enabled' => 'true', 'allow_redirects' => 'never' ),
			'description'               => 'Prova sandbox Associazione Semplice',
			'metadata'                  => array( 'asem_payment' => $pub ),
		),
		array( 'Idempotency-Key' => 'sandbox-pi-' . $pub )
	);
	ok( 'succeeded' === $pi['status'] && $amt === (int) $pi['amount'] && false === $pi['livemode'], 'Stripe ha incassato ' . $amt . ' centesimi in modalità di prova (' . $pi['id'] . ')' );
	$pi_id = (string) $pi['id'];

	// ---------- L'evento firmato arriva al webhook del plugin ----------
	$deliver = function ( array $event, ?string $header = null ) use ( $whsec ): array {
		$payload = (string) wp_json_encode( $event );
		$req     = new \WP_REST_Request( 'POST', '/' . \AssociazioneSemplice\Rest\Api::NS . '/webhooks/stripe' );
		$req->set_header( 'stripe-signature', $header ?? StripeWebhook::header( $payload, $whsec, time() ) );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( $payload );
		$res = rest_do_request( $req );
		return array( 'status' => $res->get_status(), 'data' => $res->get_data() );
	};
	$ev_paid = array(
		'id'       => 'evt_sandbox_paid',
		'type'     => 'checkout.session.completed',
		'livemode' => false,
		'data'     => array(
			'object' => array(
				'id'                  => $pay['provider_ref'],
				'object'              => 'checkout.session',
				'client_reference_id' => $pub,
				'metadata'            => array( 'asem_payment' => $pub ),
				'payment_status'      => 'paid',
				'status'              => 'complete',
				'currency'            => 'eur',
				'amount_total'        => $amt,
				'payment_intent'      => $pi_id,
			),
		),
	);
	$bad = $deliver( $ev_paid, 't=' . time() . ',v1=' . str_repeat( '0', 64 ) );
	ok( 400 === $bad['status'] && 'pending' === $wpdb->get_var( "SELECT status FROM $tbl WHERE id = $id" ), 'webhook con firma sbagliata: rifiutato, nulla registrato' );
	$r1 = $deliver( $ev_paid );
	ok( 200 === $r1['status'] && 'registrato' === ( $r1['data']['result'] ?? '' ), 'webhook firmato: il plugin registra il pagamento' );
	$r2 = $deliver( $ev_paid ); // Stripe può consegnare lo stesso evento più volte
	ok( 200 === $r2['status'], 'lo stesso evento consegnato di nuovo è accettato' );

	$tx   = Db::t( 'transactions' );
	$ref  = substr( (string) $pay['provider_ref'], 0, 80 );
	$pay  = $wpdb->get_row( "SELECT * FROM $tbl WHERE id = $id", ARRAY_A );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, amount_cents, voided_at FROM $tx WHERE document_ref = %s", $ref ), ARRAY_A );
	ok( 'paid' === $pay['status'] && $pi_id === $pay['provider_payment_id'] && 0 === (int) $pay['review'] && $amt === (int) $pay['allocated_cents'], 'pagamento: incassato, riferimento al PaymentIntent, nessun controllo da fare' );
	// Col pagamento entra l'incasso e, se l'ente ha scelto di stimarla, la spesa «commissioni di incasso» con lo stesso riferimento: ognuna una sola volta.
	$incomes  = array_values( array_filter( $rows, function ( $r ) { return 'income' === $r['type']; } ) );
	$expenses = array_values( array_filter( $rows, function ( $r ) { return 'expense' === $r['type']; } ) );
	ok( 1 === count( $incomes ) && $amt === (int) $incomes[0]['amount_cents'] && null === $incomes[0]['voided_at'] && count( $expenses ) <= 1 && count( $rows ) === count( $incomes ) + count( $expenses ), 'prima nota: un solo incasso di 10,00 € e al più una commissione (l\'evento ripetuto non ha raddoppiato niente)' );
	$tx_id = (int) $incomes[0]['id'];

	// ---------- Regola: l'incasso va annullato prima del rimborso ----------
	$msg = throws_msg( function () use ( $ps, $id, $amt ) { $ps->refund( $id, $amt, 0, 'Prova sandbox' ); } );
	ok( null !== $msg && false !== strpos( $msg, 'annullalo lì' ),'rimborso con l\'incasso ancora valido: rifiutato dal plugin (' . $msg . ')' );
	$list = stripe( 'GET', '/v1/refunds?payment_intent=' . rawurlencode( $pi_id ) );
	ok( 0 === count( $list['data'] ), 'su Stripe non è partito nessun rimborso' );

	Plugin::ledger()->void( $tx_id, 'Prova sandbox: incasso annullato prima del rimborso' );
	$pay = $wpdb->get_row( "SELECT * FROM $tbl WHERE id = $id", ARRAY_A );
	$inf = $ps->refund_info( $pay );
	ok( $inf['ok'] && 'b' === $inf['case'] && $amt === $inf['remaining'] && 1 === (int) $pay['review'], 'incasso annullato: il pagamento è «da controllare» e ora si può rimborsare (caso b)' );

	// ---------- Il rimborso, dal plugin, sul vero Stripe (di prova) ----------
	$balance_tx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tx" );
	$sum_active = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM $tx WHERE voided_at IS NULL" );
	$res        = $ps->refund( $id, $amt, 0, 'Prova sandbox: rimborso dal plugin' );
	$pay        = $wpdb->get_row( "SELECT * FROM $tbl WHERE id = $id", ARRAY_A );
	ok( $res['full'] && $amt === $res['total'] && $amt === (int) $pay['refunded_cents'] && 0 === (int) $pay['refund_pending_cents'], 'plugin: rimborso completo registrato sul pagamento' );
	ok( 'paid' === $pay['status'] && 0 === (int) $pay['review'] && 0 === (int) $pay['refund_due'] && 0 === strpos( (string) $pay['refund_id'], 're_' ), 'plugin: stato del pagamento coerente, nessun controllo rimasto, id rimborso re_…' );
	ok( $balance_tx === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tx" ) && $sum_active === (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_cents),0) FROM $tx WHERE voided_at IS NULL" ), 'prima nota: il rimborso non crea né cambia movimenti (l\'incasso annullato resta annullato)' );

	$list = stripe( 'GET', '/v1/refunds?payment_intent=' . rawurlencode( $pi_id ) );
	ok( 1 === count( $list['data'] ), 'Stripe: un solo rimborso sul pagamento' );
	$re = $list['data'][0];
	ok( $re['id'] === $pay['refund_id'] && $amt === (int) $re['amount'] && 'succeeded' === $re['status'] && $pub === ( $re['metadata']['asem_payment'] ?? '' ), 'Stripe: il rimborso è completo, riuscito e porta il riferimento del pagamento' );
	$pi2 = stripe( 'GET', '/v1/payment_intents/' . rawurlencode( $pi_id ) . '?expand[]=latest_charge' );
	ok( true === ( $pi2['latest_charge']['refunded'] ?? false ) && $amt === (int) $pi2['latest_charge']['amount_refunded'], 'Stripe: l\'addebito risulta rimborsato per intero' );

	// ---------- Nessun doppio rimborso ----------
	$msg = throws_msg( function () use ( $ps, $id ) { $ps->refund( $id, 50, $GLOBALS['wpdb']->get_var( 'SELECT refunded_cents FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $id ), 'Ancora' ); } );
	ok( null !== $msg && false !== strpos( $msg, 'già stato restituito per intero' ), 'secondo rimborso: rifiutato dal plugin (' . $msg . ')' );
	$replay = StripeApi::refund( array( Gateways::class, 'wp_http' ), $SK, $pi_id, $amt, 'refund-' . $pub . '-0-' . $amt, $pub );
	ok( $replay['ok'] && $replay['id'] === $re['id'], 'stessa chiave di idempotenza: Stripe restituisce lo stesso rimborso' );
	$list = stripe( 'GET', '/v1/refunds?payment_intent=' . rawurlencode( $pi_id ) );
	ok( 1 === count( $list['data'] ), 'dopo i tentativi ripetuti su Stripe c\'è ancora un solo rimborso' );

	// ---------- L'evento charge.refunded che Stripe manda dopo il rimborso non genera segnalazioni ----------
	$ev_ref = array(
		'id'       => 'evt_sandbox_refunded',
		'type'     => 'charge.refunded',
		'livemode' => false,
		'data'     => array( 'object' => array( 'object' => 'charge', 'payment_intent' => $pi_id, 'amount_refunded' => $amt ) ),
	);
	$r3 = $deliver( $ev_ref );
	$pay = $wpdb->get_row( "SELECT * FROM $tbl WHERE id = $id", ARRAY_A );
	ok( 200 === $r3['status'] && 'già registrato' === ( $r3['data']['result'] ?? '' ) && 0 === (int) $pay['review'], 'evento charge.refunded: riconosciuto come il rimborso fatto dal plugin, nessun avviso' );

	// ---------- La chiave non compare da nessuna parte ----------
	$dump = (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM $tbl", ARRAY_A ) )
		. (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Db::t( 'audit_log' ), ARRAY_A ) )
		. (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM $tx", ARRAY_A ) );
	ok( false === strpos( $dump, $SK ), 'la chiave non è nei pagamenti, nel registro azioni né in prima nota' );
	$log = WP_CONTENT_DIR . '/debug.log';
	ok( ! is_readable( $log ) || false === strpos( (string) file_get_contents( $log ), $SK ), 'la chiave non è nel debug.log di WordPress' );
	ok( 'payment.refunded' === $wpdb->get_var( 'SELECT action FROM ' . Db::t( 'audit_log' ) . " WHERE action LIKE 'payment.%' ORDER BY id DESC LIMIT 1" ), 'registro azioni: il rimborso è scritto' );
} catch ( \Throwable $e ) {
	WP_CLI::error( scrub( 'ECCEZIONE: ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' ) );
} finally {
	// Pulizia: la sessione Checkout lasciata aperta si fa scadere (se è già chiusa, Stripe risponde con un errore che qui non conta).
	if ( isset( $pay['provider_ref'] ) && 0 === strpos( (string) $pay['provider_ref'], 'cs_test_' ) ) {
		try {
			stripe( 'POST', '/v1/checkout/sessions/' . rawurlencode( (string) $pay['provider_ref'] ) . '/expire', array() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
WP_CLI::success( 'Rimborso Stripe in sandbox: tutti i controlli superati.' );

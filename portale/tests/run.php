<?php
// Prove del portale senza dipendenze: php tests/run.php (esce con codice 1 se qualcosa fallisce).
require __DIR__ . '/../src/bootstrap.php';

use Portale\{Billing, Config, Db, Domain, Http, Mailer, Releases, Stripe, UserError, WegaClient, WegaException, Webhooks};

$fails = 0;
function check( string $name, bool $ok ): void {
	global $fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		++$fails;
	}
}
function throwsUser( callable $f ): ?string {
	try {
		$f();
	} catch ( UserError $e ) {
		return $e->getMessage();
	}
	return null;
}

Config::load( array(
	'base_url' => 'https://portale.test', 'app_key' => base64_encode( str_repeat( 'k', 32 ) ),
	'stripe' => array( 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test' ),
	'paypal' => array( 'live' => false, 'client_id' => 'id', 'secret' => 's', 'webhook_id' => 'WH1' ),
	'duration_days' => array( 'month' => 31, 'year' => 366, 'free' => 3650 ), 'renew_window_days' => 5,
	'plans' => array(
		'free' => array( 'label' => 'Free', 'plan_name' => 'Free', 'prices' => array() ),
		'full' => array( 'label' => 'Pro Full', 'plan_name' => 'Pro Full', 'prices' => array(
			'year' => array( 'cents' => 11900, 'stripe_price' => 'price_1', 'paypal_plan' => 'P-1' ),
		) ),
	),
) );

// --- Dominio -------------------------------------------------------------
check( 'dominio: schema, www, percorso e porta', 'esempio.it' === Domain::normalize( 'https://WWW.Esempio.it:8080/a/b?x=1' ) );
check( 'dominio: sottodominio resta', 'staging.esempio.it' === Domain::normalize( 'staging.esempio.it' ) );
check( 'dominio: IP rifiutato', '' === Domain::normalize( '192.168.1.5' ) );
check( 'dominio: wildcard rifiutato', '' === Domain::normalize( '*.esempio.it' ) );
check( 'dominio: senza punto rifiutato', '' === Domain::normalize( 'localhost' ) );
check( 'dominio: spazi e punti finali', 'esempio.it' === Domain::normalize( '  esempio.it. ' ) );

// --- Stripe: firma ---------------------------------------------------------
$payload = '{"id":"evt_1"}';
$t       = 1700000000;
$sig     = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_test' );
check( 'stripe: firma valida', Stripe::verify( $payload, $sig, 'whsec_test', $t + 10 ) );
check( 'stripe: firma con corpo alterato', ! Stripe::verify( $payload . ' ', $sig, 'whsec_test', $t + 10 ) );
check( 'stripe: firma scaduta', ! Stripe::verify( $payload, $sig, 'whsec_test', $t + 1000 ) );
check( 'stripe: segreto vuoto', ! Stripe::verify( $payload, $sig, '', $t ) );

// --- Server finto -----------------------------------------------------------
$calls = array();
$state = array( 'expires' => '2027-01-01T00:00:00Z', 'bind_error' => null );
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$calls, &$state ) {
	$calls[] = array( $method, $url, null === $body ? null : ( json_decode( $body, true ) ?: $body ) );
	if ( false !== strpos( $url, '/licenses/renew' ) ) {
		return array( 200, json_encode( array( 'data' => array( 'expires_at' => '2028-01-01T00:00:00Z' ) ) ) );
	}
	if ( false !== strpos( $url, '/binding' ) ) {
		return $state['bind_error']
			? array( 429, json_encode( array( 'error' => array( 'code' => $state['bind_error'], 'message' => 'x' ) ) ) )
			: array( 200, json_encode( array( 'data' => array() ) ) );
	}
	if ( false !== strpos( $url, '/licenses' ) ) {
		return array( 201, json_encode( array( 'data' => array( 'license_id' => 'lic_1', 'license_key' => 'WEGA-AAAA', 'expires_at' => $state['expires'] ) ) ) );
	}
	if ( false !== strpos( $url, '/oauth2/token' ) ) {
		return array( 200, '{"access_token":"tok"}' );
	}
	if ( false !== strpos( $url, 'verify-webhook-signature' ) ) {
		return array( 200, '{"verification_status":"SUCCESS"}' );
	}
	if ( false !== strpos( $url, '/checkout/sessions' ) ) {
		return array( 200, '{"url":"https://stripe.test/pay"}' );
	}
	if ( false !== strpos( $url, '/v2/checkout/orders' ) ) {
		return array( 200, '{"id":"ORD1","links":[{"rel":"approve","href":"https://paypal.test/approve"}]}' );
	}
	if ( false !== strpos( $url, '/v1/billing/subscriptions' ) ) {
		return array( 200, '{"links":[{"rel":"approve","href":"https://paypal.test/sub"}]}' );
	}
	return array( 404, '{}' );
};
$mails = array();
Mailer::$sender = function ( $to, $subject, $body ) use ( &$mails ) {
	$mails[] = array( $to, $subject, $body );
	return true;
};

$db = new Db( new PDO( 'sqlite::memory:' ) );
$db->migrate();
$billing = new Billing( $db, new WegaClient( 'https://license.test/api/v1', 'wic_x.y' ) );

// --- Licenza free -----------------------------------------------------------
list( $cust, $sub ) = $billing->startFree( 'Anna@Esempio.it', 'https://www.mia-associazione.it/' );
check( 'free: cliente in minuscolo', 'anna@esempio.it' === $cust['email'] );
check( 'free: in attesa finché non conferma', 'pending' === $billing->subscription( $sub['id'] )['status'] && empty( $billing->subscription( $sub['id'] )['license_id'] ) );
check( 'free: dominio già preso da altri', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'altro@esempio.it', 'mia-associazione.it' );
} ) );
check( 'free: dominio non valido', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'x@esempio.it', '10.0.0.1' );
} ) );
check( 'email non valida', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'non-una-email', 'esempio.org' );
} ) );

$active = $billing->activate( $sub['id'] );
$create = null;
foreach ( $calls as $c ) {
	if ( 'POST' === $c[0] && substr( $c[1], -9 ) === '/licenses' ) {
		$create = $c[2];
	}
}
check( 'free: licenza tipo 3 con dominio e riferimento idempotente', $create && 3 === $create['type'] && 'mia-associazione.it' === $create['domain'] && 'sub-' . $sub['id'] === $create['external_ref'] && 3650 === $create['duration_days'] && 'Free' === $create['plan_name'] );
check( 'free: attiva e scadenza salvata', 'active' === $active['status'] && '2027-01-01 00:00:00' === $active['expires_at'] );
check( 'free: chiave cifrata nel database', false === strpos( $active['license_key_enc'], 'WEGA-AAAA' ) && 'WEGA-AAAA' === Portale\Secrets::open( $active['license_key_enc'] ) );
$n = count( $calls );
$billing->activate( $sub['id'] );
check( 'free: riattivare non richiama il server', count( $calls ) === $n );

// --- Cambio dominio -----------------------------------------------------------
$billing->changeDomain( $sub['id'], $cust['id'], 'nuovo.it' );
check( 'dominio: cambio salvato', 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
check( 'dominio: cliente diverso rifiutato', null !== throwsUser( function () use ( $billing, $sub ) {
	$billing->changeDomain( $sub['id'], 'altro', 'x.it' );
} ) );
$state['bind_error'] = 'BINDING_CHANGE_LIMIT';
$msg = throwsUser( function () use ( $billing, $sub, $cust ) {
	$billing->changeDomain( $sub['id'], $cust['id'], 'ancora.it' );
} );
check( 'dominio: limite 3 cambi spiegato', null !== $msg && false !== strpos( $msg, '3 volte' ) && 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
$state['bind_error'] = null;

// --- Stripe: acquisto, rinnovo, doppioni ------------------------------------
$hooks = new Webhooks( $db, $billing );
$send  = function ( array $event ) use ( $hooks ) {
	$body = json_encode( $event );
	$ts   = time();
	return $hooks->stripe( $body, 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_test' ) );
};
$c2 = $billing->customer( 'bruno@esempio.it' );
$db->run( "INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, created_at, updated_at) VALUES ('s2', ?, 'full', 'year', 'stripe', 'pending', 'bruno.it', ?, ?)", array( $c2['id'], Db::now(), Db::now() ) );
$mails = array();
check( 'stripe: firma errata rifiutata', 400 === $hooks->stripe( '{"id":"e","type":"x"}', 't=1,v1=00' ) );
check( 'stripe: checkout completato', 200 === $send( array( 'id' => 'evt_a', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'subscription', 'payment_status' => 'paid', 'client_reference_id' => 's2', 'subscription' => 'sub_9', 'customer' => 'cus_9' ) ) ) ) );
$s2 = $billing->subscription( 's2' );
check( 'stripe: attivo con riferimenti del provider', 'active' === $s2['status'] && 'sub_9' === $s2['provider_ref'] && 'cus_9' === $s2['provider_customer'] );
check( 'stripe: email con il link inviata una volta', 1 === count( $mails ) && 'bruno@esempio.it' === $mails[0][0] && false !== strpos( $mails[0][2], 'https://portale.test/entra/' ) );
$ev = array( 'id' => 'evt_b', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_9' ) ) );
$send( $ev );
check( 'stripe: rinnovo aggiorna la scadenza', '2028-01-01 00:00:00' === $billing->subscription( 's2' )['expires_at'] );
$n = count( $calls );
check( 'stripe: consegna ripetuta ignorata', 200 === $send( $ev ) && count( $calls ) === $n );
$send( array( 'id' => 'evt_c', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_create', 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: primo pagamento non conta come rinnovo', count( $calls ) === $n );
$send( array( 'id' => 'evt_d', 'type' => 'invoice.payment_failed', 'data' => array( 'object' => array( 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: pagamento fallito', 'past_due' === $billing->subscription( 's2' )['status'] );
$send( array( 'id' => 'evt_e', 'type' => 'customer.subscription.deleted', 'data' => array( 'object' => array( 'id' => 'sub_9' ) ) ) );
check( 'stripe: abbonamento annullato', 'canceled' === $billing->subscription( 's2' )['status'] );
$n = count( $calls );
$send( array( 'id' => 'evt_f', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: nessun rinnovo se annullato', count( $calls ) === $n );

// --- PayPal: il primo pagamento non raddoppia ----------------------------------
$c3 = $billing->customer( 'carla@esempio.it' );
$db->run( "INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, created_at, updated_at) VALUES ('s3', ?, 'full', 'year', 'paypal', 'pending', 'carla.it', ?, ?)", array( $c3['id'], Db::now(), Db::now() ) );
$state['expires'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 366 * 86400 );
$pp = function ( array $event ) use ( $hooks ) {
	return $hooks->paypal( json_encode( $event ), array( 'paypal-transmission-id' => 'x' ) );
};
$pp( array( 'id' => 'WH-1', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => array( 'id' => 'I-1', 'custom_id' => 's3' ) ) );
check( 'paypal: attivato', 'active' === $billing->subscription( 's3' )['status'] && 'I-1' === $billing->subscription( 's3' )['provider_ref'] );
$n = count( $calls );
$pp( array( 'id' => 'WH-2', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => array( 'billing_agreement_id' => 'I-1' ) ) );
$renewCalls = 0;
foreach ( array_slice( $calls, $n ) as $c ) {
	$renewCalls += false !== strpos( $c[1], '/licenses/renew' ) ? 1 : 0;
}
check( 'paypal: pagamento iniziale non rinnova', 0 === $renewCalls );
check( 'paypal: rinnovo vicino alla scadenza', $billing->renew( 's3', 5, time() + 363 * 86400 ) && '2028-01-01 00:00:00' === $billing->subscription( 's3' )['expires_at'] );
$pp( array( 'id' => 'WH-3', 'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED', 'resource' => array( 'id' => 'I-1' ) ) );
check( 'paypal: annullato', 'canceled' === $billing->subscription( 's3' )['status'] );

// --- Accesso con link ----------------------------------------------------------
$auth = new Portale\Auth( $db );
$tok  = $auth->issue( $c2['id'] );
check( 'accesso: link valido una volta sola', null !== $auth->consume( $tok ) && null === $auth->consume( $tok ) );
check( 'accesso: link inventato', null === $auth->consume( 'xyz' ) );

// --- Impostazioni salvate dal pannello -------------------------------------------------------------------------------
Config::set( 'trial_enabled', true );
Portale\Settings::savePlan( $db, 'full', array(
	'label' => 'Pro Completo', 'plan_name' => 'Pro Full', 'tagline' => 'Tutto', 'tag' => 'Top', 'features' => "Uno\n\nDue\n", 'visible' => '1',
	'price_month' => '15,50', 'stripe_month' => 'price_ABCDEF', 'paypal_month' => '', 'once_month' => '1',
	'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'once_year' => '1',
) );
Portale\Settings::load( $db );
check( 'impostazioni: prezzo e nome del piano sovrascritti', 1550 === Config::get( 'plans.full.prices.month.cents' ) && 'Pro Completo' === Config::get( 'plans.full.label' ) && array( 'Uno', 'Due' ) === Config::get( 'plans.full.features' ) );
check( 'impostazioni: prezzo non valido rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::savePlan( $db, 'full', array( 'price_month' => 'abc' ) );
} ) );
check( 'impostazioni: id Stripe non valido rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::savePlan( $db, 'full', array( 'price_month' => '5', 'stripe_month' => 'sbagliato' ) );
} ) );
Portale\Settings::saveGeneral( $db, array( 'duration_month' => 9999, 'reminder_days' => -4, 'trial_days' => 0, 'trial_plan' => 'free', 'indexable' => '' ) );
Portale\Settings::load( $db );
check( 'impostazioni: limiti sempre dentro i margini', 62 === Portale\Settings::limit( 'duration_month' ) && 0 === Portale\Settings::limit( 'reminder_days' ) && 1 === Portale\Settings::limit( 'trial_days' ) && 'full' === Config::get( 'trial_plan' ) && false === Config::get( 'indexable' ) );
Portale\Settings::saveGeneral( $db, array( 'trial_days' => 30, 'trial_plan' => 'full', 'trial_enabled' => '1', 'duration_month' => 31, 'reminder_days' => 14, 'duration_year' => 366, 'duration_free' => 3650, 'renew_window' => 5 ) );
Portale\Settings::load( $db );
check( 'impostazioni: ripristino dei valori', 31 === Portale\Settings::limit( 'duration_month' ) && 14 === Portale\Settings::limit( 'reminder_days' ) && 30 === Portale\Settings::limit( 'trial_days' ) );

// --- Modi di pagamento -----------------------------------------------------------------------------------------------------
$price = Config::price( 'full', 'year' );
check( 'pagamenti: quattro modi con Stripe e PayPal configurati', 4 === count( $billing->methods( $price ) ) );
$noOnce = $price;
$noOnce['once'] = false;
check( 'pagamenti: senza «singolo» restano i ricorrenti', array( array( 'stripe', 'recurring' ), array( 'paypal', 'recurring' ) ) === $billing->methods( $noOnce ) );
$noIds = array( 'cents' => 500 );
check( 'pagamenti: senza id il rinnovo automatico si crea da solo, e restano i singoli', array( array( 'stripe', 'recurring' ), array( 'paypal', 'recurring' ), array( 'stripe', 'once' ), array( 'paypal', 'once' ) ) === $billing->methods( $noIds ) );
check( 'pagamenti: senza importo non si vende', array() === $billing->methods( array() ) );
check( 'pagamenti: modo non disponibile rifiutato', null !== throwsUser( function () use ( $billing ) {
	$billing->startPaid( 'dora@esempio.it', 'dora.it', 'full', 'year', 'stripe', 'strano' );
} ) );
Portale\Settings::savePlan( $db, 'full', array( 'label' => 'Pro Completo', 'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'visible' => '' ) );
Portale\Settings::load( $db );
check( 'pagamenti: piano nascosto non si compra', null === Config::price( 'full', 'year' ) );
Portale\Settings::savePlan( $db, 'full', array( 'label' => 'Pro Completo', 'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'once_year' => '1', 'visible' => '1', 'price_month' => '15,50', 'stripe_month' => 'price_ABCDEF', 'once_month' => '1' ) );
Portale\Settings::load( $db );

// Pagamento singolo con carta: stesso flusso, nessun rinnovo automatico.
$url = $billing->startPaid( 'dora@esempio.it', 'Dora.it', 'full', 'year', 'stripe', 'once' );
$ds  = $db->one( "SELECT * FROM subscriptions WHERE domain = 'dora.it'" );
check( 'singolo: indirizzo di pagamento e riga in attesa', 'https://stripe.test/pay' === $url && 'pending' === $ds['status'] && 'once' === $ds['mode'] && 'stripe' === $ds['provider'] );
$mails = array();
$send( array( 'id' => 'evt_once', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'payment', 'payment_status' => 'paid', 'client_reference_id' => $ds['id'], 'payment_intent' => 'pi_1' ) ) ) );
$ds = $billing->subscription( $ds['id'] );
check( 'singolo: licenza emessa dopo il pagamento', 'active' === $ds['status'] && ! empty( $ds['license_key_enc'] ) && 'pi_1' === $ds['provider_ref'] );
check( 'singolo: non è un rinnovo automatico', false === $billing->isRecurring( $ds ) );
$unpaid = $send( array( 'id' => 'evt_unpaid', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'payment', 'payment_status' => 'unpaid', 'client_reference_id' => 'inesistente' ) ) ) );
check( 'singolo: pagamento non completato ignorato', 200 === $unpaid );
check( 'singolo: cancel rifiutata (niente rinnovo da disdire)', null !== throwsUser( function () use ( $billing, $ds ) {
	$billing->cancel( $ds['id'], $ds['customer_id'] );
} ) );

// Rinnovo di un pagamento singolo con PayPal: la chiave resta, la scadenza si sposta.
$url = $billing->startRenewal( $ds['id'], $ds['customer_id'], 'paypal' );
$rn  = $db->one( "SELECT * FROM subscriptions WHERE renews = ?", array( $ds['id'] ) );
check( 'rinnovo singolo: ordine PayPal creato e riga collegata', 'https://paypal.test/approve' === $url && 'ORD1' === $rn['provider_ref'] && 'pending' === $rn['status'] );
check( 'rinnovo singolo: cliente estraneo rifiutato', null !== throwsUser( function () use ( $billing, $ds ) {
	$billing->startRenewal( $ds['id'], 'altro', 'paypal' );
} ) );
$n = count( $calls );
$billing->completePayment( $rn['id'] );
$billing->completePayment( $rn['id'] );
$renews = 0;
foreach ( array_slice( $calls, $n ) as $c ) {
	$renews += false !== strpos( $c[1], '/licenses/renew' ) ? 1 : 0;
}
check( 'rinnovo singolo: un solo rinnovo anche se arriva due volte', 1 === $renews && 'merged' === $billing->subscription( $rn['id'] )['status'] );
check( 'rinnovo singolo: non compare tra le licenze del cliente', 1 === count( array_filter( $billing->forCustomer( $ds['customer_id'] ), function ( $s ) { return 'dora.it' === $s['domain']; } ) ) );

// Pagamento singolo con PayPal: ordine e ricorrente.
$url = $billing->startPaid( 'ugo@esempio.it', 'ugo.it', 'full', 'month', 'paypal', 'once' );
check( 'singolo PayPal: ordine creato', 'https://paypal.test/approve' === $url && 'ORD1' === $db->one( "SELECT provider_ref FROM subscriptions WHERE domain = 'ugo.it'" )['provider_ref'] );
$url = $billing->startPaid( 'eva@esempio.it', 'eva.it', 'full', 'year', 'paypal', 'recurring' );
check( 'ricorrente PayPal: abbonamento creato', 'https://paypal.test/sub' === $url );

// --- Prova Pro -------------------------------------------------------------------------------------------------------------------
$freeNow = $billing->subscription( $sub['id'] );
check( 'prova: disponibile per la licenza gratuita attiva', $billing->trialAvailable( $freeNow ) );
$mails = array();
$calls0 = count( $calls );
$trial  = $billing->startTrial( $cust['id'], $sub['id'] );
$tcall  = null;
foreach ( array_slice( $calls, $calls0 ) as $c ) {
	if ( 'POST' === $c[0] && substr( $c[1], -9 ) === '/licenses' ) {
		$tcall = $c[2];
	}
}
check( 'prova: licenza Pro a tempo sullo stesso dominio', $tcall && 30 === $tcall['duration_days'] && 'trial-' . $sub['id'] === $tcall['external_ref'] && $freeNow['domain'] === $tcall['domain'] && 'Pro Full' === $tcall['plan_name'] );
check( 'prova: sub di tipo prova con chiave propria', 'trial' === $trial['provider'] && 'active' === $trial['status'] && ! empty( $trial['license_key_enc'] ) );
check( 'prova: email di avvio inviata', 1 === count( $mails ) && false !== strpos( $mails[0][1], 'prova' ) );
check( 'prova: una sola volta per licenza', ! $billing->trialAvailable( $billing->subscription( $sub['id'] ) ) && null !== throwsUser( function () use ( $billing, $cust, $sub ) {
	$billing->startTrial( $cust['id'], $sub['id'] );
} ) );
check( 'prova: cliente estraneo rifiutato', null !== throwsUser( function () use ( $billing, $sub ) {
	$billing->startTrial( 'altro', $sub['id'] );
} ) );
Config::set( 'trial_enabled', false );
list( $c9, $s9 ) = $billing->startFree( 'zita@esempio.it', 'zita.it' );
$billing->activate( $s9['id'] );
check( 'prova: spenta dal pannello', ! $billing->trialAvailable( $billing->subscription( $s9['id'] ) ) );
Config::set( 'trial_enabled', true );
check( 'prova: di nuovo disponibile se riaccesa', $billing->trialAvailable( $billing->subscription( $s9['id'] ) ) );

// --- Emissione manuale e promemoria -------------------------------------------------------------------------------------------
$mails = array();
$man   = $billing->issueManual( 'regalo@esempio.it', 'regalo.it', 'full', 90, 'partner', true );
check( 'manuale: licenza emessa con nota e email di benvenuto', 'manual' === $man['provider'] && 'partner' === $man['note'] && 1 === count( $mails ) );
check( 'manuale: piano sconosciuto rifiutato', null !== throwsUser( function () use ( $billing ) {
	$billing->issueManual( 'x@esempio.it', 'x.it', 'inesistente', 30, '', false );
} ) );
$db->run( 'UPDATE subscriptions SET expires_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 ), $man['id'] ) );
$mails = array();
check( 'promemoria: parte per la licenza che scade', 1 <= $billing->sendReminders() && 'regalo@esempio.it' === $mails[ count( $mails ) - 1 ][0] );
check( 'promemoria: non si ripete', 0 === $billing->sendReminders() );
$recurring = $db->one( "SELECT * FROM subscriptions WHERE id = 's2'" );
check( 'promemoria: nessun avviso per i rinnovi automatici', ! in_array( 's2', array_column( $db->all( "SELECT id FROM subscriptions WHERE reminded_for IS NOT NULL" ), 'id' ), true ) );

// --- Email: modelli e interruttori ---------------------------------------------------------------------------------------------
Portale\Emails::save( $db, 'renewed', 'Rinnovato {dominio}', 'Fino al {scadenza} per {dominio} {strano}', true );
$mails = array();
$billing->notify( 'renewed', $billing->subscription( $ds['id'] ) );
check( 'email: modello personalizzato con segnaposto sostituiti', 1 === count( $mails ) && 'Rinnovato dora.it' === $mails[0][1] && false !== strpos( $mails[0][2], 'per dora.it {strano}' ) && false !== strpos( $mails[0][2], 'associazionesemplice.it' ) );
check( 'email: segnaposto sconosciuti segnalati', array( 'strano' ) === Portale\Emails::unknownVars( 'renewed', 'ciao {strano} {dominio}' ) );
Portale\Emails::save( $db, 'renewed', 'Rinnovato', 'Testo', false );
$mails = array();
$billing->notify( 'renewed', $billing->subscription( $ds['id'] ) );
check( 'email: evento spento non parte', 0 === count( $mails ) );
Portale\Emails::save( $db, 'login_link', 'Accesso', 'Link: {link}', false );
Portale\Emails::send( $db, 'login_link', 'a@esempio.it', array( 'link' => 'https://x' ) );
check( 'email: link di accesso parte anche se «spento»', 1 === count( $mails ) && 'Link: https://x' === substr( $mails[0][2], 0, 15 ) );
Portale\Emails::reset( $db, 'login_link' );
check( 'email: ripristino del testo originale', ! Portale\Emails::template( $db, 'login_link' )['custom'] );
check( 'email: oggetto vuoto rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Emails::save( $db, 'renewed', '  ', 'x', true );
} ) );
check( 'email: oggetto senza a capo (niente iniezione di intestazioni)', false === strpos( Portale\Emails::template( $db, 'welcome' )['subject'], "\n" ) );

// --- Pannello: chi può entrare --------------------------------------------------------------------------------------------------
Config::set( 'admins', array( 'Io@Esempio.it' ) );
check( 'admin: riconosciuto senza badare alle maiuscole', Portale\Admin::isAdminEmail( 'io@esempio.it' ) && ! Portale\Admin::isAdminEmail( 'altro@esempio.it' ) && ! Portale\Admin::isAdminEmail( '' ) );
Portale\Audit::log( $db, 'io@esempio.it', 'prova', 'dettaglio' );
check( 'registro: azione salvata', 'prova' === Portale\Audit::recent( $db, 5 )[0]['action'] );
check( 'robots: segnali di non indicizzazione di default', false === Config::get( 'indexable', false ) );

// --- Chiavi dal pannello ----------------------------------------------------------------------------------------------------------
$tokenOk = 'wic_3c6d8003fdd2b68f.d5S6nSoypyWsa5RmmMPApIHcrhCf5d_rB1Pr_6ftmO13bJlM';
$changed = Portale\Settings::saveSecrets( $db, array( 'stripe_secret_test' => ' sk_test_abcdefghij1234567890 ', 'wega_token' => $tokenOk, 'paypal_live_present' => '1', 'paypal_live' => '1' ) );
Portale\Settings::load( $db );
check( 'chiavi: salvate e applicate alla configurazione', array( 'stripe_secret_test', 'wega_token' ) === $changed && 'sk_test_abcdefghij1234567890' === Config::get( 'stripe.secret_key' ) && $tokenOk === Config::get( 'wega.token' ) && true === Config::get( 'paypal.live' ) );
$raw = $db->one( "SELECT val FROM settings WHERE name = 'secrets'" )['val'];
check( 'chiavi: nel database restano cifrate', false === strpos( $raw, 'sk_test_' ) && false === strpos( $raw, 'wic_3c6d' ) );
$st = Portale\Settings::secretsStatus( $db );
check( 'chiavi: si vedono solo gli ultimi caratteri', '••••7890' === $st['stripe_secret_test']['mask'] && 'pannello' === $st['stripe_secret_test']['source'] && 'config.php' === $st['paypal_secret']['source'] );
check( 'chiavi: formato sbagliato rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::saveSecrets( $db, array( 'stripe_secret' => 'non-una-chiave' ) );
} ) && null !== throwsUser( function () use ( $db ) {
	Portale\Settings::saveSecrets( $db, array( 'wega_token' => 'sbagliato' ) );
} ) );
Portale\Settings::saveSecrets( $db, array( 'stripe_webhook_test' => 'whsec_abcdefghijklmnop' ) );
Portale\Settings::load( $db );
check( 'chiavi: un modulo non cancella le altre né la modalità PayPal', 'sk_test_abcdefghij1234567890' === Config::get( 'stripe.secret_key' ) && 'whsec_abcdefghijklmnop' === Config::get( 'stripe.webhook_secret' ) && true === Config::get( 'paypal.live' ) );
Portale\Settings::saveSecrets( $db, array( 'remove_stripe_webhook_test' => '1' ) );
check( 'chiavi: rimozione', ! Portale\Settings::secretsStatus( $db )['stripe_webhook_test']['set'] || 'config.php' === Portale\Settings::secretsStatus( $db )['stripe_webhook_test']['source'] );

// Prova dei collegamenti (server finto).
$prev = Http::$transport;
$code = 200;
Http::$transport = function ( $m, $u ) use ( &$code ) {
	return array( $code, '{}' );
};
Config::set( 'paypal.client_id', 'AbcdefghijklmnopqrstUV' );
Config::set( 'paypal.secret', 'AbcdefghijklmnopqrstUV' );
$code = 200;
list( $okS ) = Portale\Connections::test( 'stripe' );
list( $okP ) = Portale\Connections::test( 'paypal' );
list( $okW ) = Portale\Connections::test( 'wega' );
check( 'collegamenti: tutto raggiungibile', $okS && $okP && $okW );
$code = 401;
check( 'collegamenti: chiave rifiutata (401) segnalata', ! Portale\Connections::test( 'stripe' )[0] && ! Portale\Connections::test( 'wega' )[0] && ! Portale\Connections::test( 'paypal' )[0] );
$code = 403;
check( 'collegamenti: server licenze con token valido ma permessi parziali va bene', Portale\Connections::test( 'wega' )[0] );
$code = 503;
check( 'collegamenti: server giù non è un successo', ! Portale\Connections::test( 'wega' )[0] );
check( 'collegamenti: nome sconosciuto', ! Portale\Connections::test( 'altro' )[0] );
Http::$transport = $prev;

// --- Script di monitoraggio e cookie ---------------------------------------------------------------------------------------------
Config::set( 'tracking', array() );
check( 'cookie: senza script niente banner', ! Portale\Tracking::banner() && '' === Portale\Tracking::slot( 'head' ) );
check( 'cookie: snippet che chiude il template rifiutato', null !== throwsUser( function () {
	Portale\Tracking::validate( '<script>x</script></TEMPLATE><script>evil()</script>' );
} ) );
Portale\Settings::saveTracking( $db, array( 'head' => '<script async src="https://t.example/a.js"></script>', 'head_cat' => 'analytics', 'foot' => '<script>necessario()</script>', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
$rev1 = Portale\Tracking::conf()['rev'];
check( 'cookie: script statistico dentro un template inerte', 0 === strpos( Portale\Tracking::slot( 'head' ), '<template data-cc-cat="analytics" data-cc-slot="head">' ) && Portale\Tracking::banner() && array( 'analytics' ) === array_keys( Portale\Tracking::categories() ) );
check( 'cookie: script necessario stampato subito', '<script>necessario()</script>' === trim( Portale\Tracking::slot( 'foot' ) ) );
Portale\Settings::saveTracking( $db, array( 'head' => '<script async src="https://t.example/a.js"></script>', 'head_cat' => 'analytics', 'foot' => '<script>necessario()</script>', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
check( 'cookie: salvare senza cambiare non richiede un nuovo consenso', $rev1 === Portale\Tracking::conf()['rev'] );
Portale\Settings::saveTracking( $db, array( 'head' => '<script>altro()</script>', 'head_cat' => 'marketing', 'foot' => '', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
check( 'cookie: cambiare gli script aggiorna la revisione e le categorie', Portale\Tracking::conf()['rev'] >= $rev1 && array( 'marketing' ) === array_keys( Portale\Tracking::categories() ) );
check( 'cookie: categoria sconosciuta ripiega su «statistiche»', 'analytics' === ( function () use ( $db ) {
	Portale\Settings::saveTracking( $db, array( 'head' => '<b></b>', 'head_cat' => 'hack' ) );
	Portale\Settings::load( $db );
	return Portale\Tracking::conf()['head_cat'];
} )() );
Portale\Settings::saveTracking( $db, array() );
Portale\Settings::load( $db );

// --- Pagine legali -----------------------------------------------------------------------------------------------------------------------
Portale\Settings::saveLegal( $db, array( 'titolare' => 'Associazione <b>Prova</b>', 'email' => 'privacy@esempio.it', 'privacy_body' => "## Titolo\n\nUn <script>alert(1)</script> **forte** per {titolare}\n\n- uno\n- due" ) );
Portale\Settings::load( $db );
$html = Portale\Legal::html( Portale\Legal::body( 'privacy' ) );
check( 'legale: testo personalizzato con titoli, elenco e grassetto', false !== strpos( $html, '<h2>Titolo</h2>' ) && false !== strpos( $html, '<strong>forte</strong>' ) && false !== strpos( $html, '<ul><li>uno</li><li>due</li></ul>' ) );
check( 'legale: HTML inserito dall\'amministratore è neutralizzato', false === strpos( $html, '<script>' ) && false === strpos( $html, '<b>Prova' ) && false !== strpos( $html, '&lt;b&gt;Prova&lt;/b&gt;' ) );
check( 'legale: pagina cookie usa il testo di base se non personalizzata', ! Portale\Legal::isCustom( 'cookie' ) && false !== strpos( Portale\Legal::html( Portale\Legal::body( 'cookie' ) ), 'privacy@esempio.it' ) );
Portale\Settings::saveLegal( $db, array( 'email' => 'non-valida' ) );
Portale\Settings::load( $db );
check( 'legale: email non valida scartata, si torna ai valori di base', 'info@associazionesemplice.it' === Portale\Legal::vars()['email'] && ! Portale\Legal::isCustom( 'privacy' ) );

// --- Errori del server licenze -----------------------------------------------------
Http::$transport = function () {
	return array( 422, json_encode( array( 'error' => array( 'code' => 'INVALID_DOMAIN', 'message' => 'no' ) ) ) );
};
try {
	( new WegaClient( 'https://license.test/api/v1', 't' ) )->createLicense( array( 'type' => 3 ) );
	check( 'wega: errore propagato', false );
} catch ( WegaException $e ) {
	check( 'wega: errore propagato con codice', 'INVALID_DOMAIN' === $e->errorCode && 422 === $e->httpStatus );
}

// --- Rimbalzo delle verifiche del plugin ------------------------------------------------
$pdb    = new Db( new PDO( 'sqlite::memory:' ) );
$pdb->migrate();
$pcalls = 0;
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$pcalls ) {
	++$pcalls;
	if ( false !== strpos( $url, 'jwks.json' ) ) {
		return array( 200, '{"keys":[{"kty":"OKP"}]}' );
	}
	$in = json_decode( (string) $body, true );
	if ( 'chiave-sconosciuta' === $in['license_key'] ) {
		return array( 404, json_encode( array( 'error' => array( 'code' => 'LICENSE_NOT_FOUND', 'message' => 'no' ) ) ) );
	}
	if ( 'chiave-guasta-1' === $in['license_key'] ) {
		return array( 500, '' );
	}
	return array( 200, json_encode( array( 'valid' => true, 'domain' => $in['domain'], 'attestation' => 'JWT.INTATTO.X' ) ) );
};
$proxy = new Portale\LicenseProxy( $pdb, new WegaClient( 'https://license.test/api/v1', 'tok-segreto' ) );
$ok    = '{"license_key":"chiave-valida-1","domain":"https://WWW.Esempio.it/x"}';
$r     = $proxy->validate( $ok, '1.1.1.1', 1700000000 );
$j     = json_decode( $r[1], true );
check( 'rimbalzo: risposta di Wega girata com\'è, dominio normalizzato', 200 === $r[0] && true === $j['valid'] && 'esempio.it' === $j['domain'] && 'JWT.INTATTO.X' === $j['attestation'] );
check( 'rimbalzo: il token non compare nella risposta', false === strpos( $r[1], 'tok-segreto' ) );
$before = $pcalls;
$proxy->validate( $ok, '1.1.1.1', 1700000100 );
check( 'rimbalzo: seconda richiesta dalla memoria', $pcalls === $before );
$proxy->validate( $ok, '1.1.1.1', 1700000400 );
check( 'rimbalzo: memoria scaduta, nuova richiesta a Wega', $pcalls === $before + 1 );
check( 'rimbalzo: JSON rotto rifiutato', 422 === $proxy->validate( 'nonjson', '2.2.2.2' )[0] );
check( 'rimbalzo: dominio locale rifiutato', 422 === $proxy->validate( '{"license_key":"chiave-valida-1","domain":"localhost"}', '2.2.2.2' )[0] );
check( 'rimbalzo: chiave corta rifiutata', 422 === $proxy->validate( '{"license_key":"abc","domain":"esempio.it"}', '2.2.2.2' )[0] );
$r = $proxy->validate( '{"license_key":"chiave-sconosciuta","domain":"esempio.it"}', '3.3.3.3', 1700000000 );
check( 'rimbalzo: errore 4xx di Wega girato con il suo codice', 404 === $r[0] && 'LICENSE_NOT_FOUND' === json_decode( $r[1], true )['error']['code'] );
$r = $proxy->validate( '{"license_key":"chiave-guasta-1","domain":"esempio.it"}', '4.4.4.4', 1700000000 );
check( 'rimbalzo: errore del server Wega diventa 502 (il plugin tiene lo stato)', 502 === $r[0] );
$last = 0;
for ( $i = 0; $i < 25; $i++ ) {
	$last = $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 )[0];
}
check( 'rimbalzo: limite per chiave (20/ora) → 429 con Retry-After', 429 === $last && in_array( 'Retry-After: 3600', $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 )[2], true ) );
check( 'rimbalzo: la finestra successiva riparte', 200 === $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 + 7200 )[0] );
$r = $proxy->jwks( 1700000000 );
check( 'jwks: chiavi pubbliche girate', 200 === $r[0] && isset( json_decode( $r[1], true )['keys'] ) );
$before = $pcalls;
$proxy->jwks( 1700000100 );
check( 'jwks: seconda lettura dalla memoria', $pcalls === $before );
Http::$transport = function () { return array( 0, '' ); };
check( 'jwks: Wega irraggiungibile, si usa l\'ultima copia', 200 === $proxy->jwks( 1700000000 + 7200 )[0] );

// --- Pacchetti del plugin ------------------------------------------------------------------
if ( class_exists( 'ZipArchive' ) ) {
	$rdir = sys_get_temp_dir() . '/asem-rel-' . bin2hex( random_bytes( 4 ) );
	Config::set( 'releases_dir', $rdir );
	$rdb = new Db( new PDO( 'sqlite::memory:' ) );
	$rdb->migrate();
	$mkzip = function ( string $slug, string $ver, string $inner = '' ) {
		$f = tempnam( sys_get_temp_dir(), 'z' );
		$z = new ZipArchive();
		$z->open( $f, ZipArchive::OVERWRITE );
		$z->addFromString( ( '' !== $inner ? $inner : $slug ) . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: X\n * Version:           $ver\n */\n" );
		$z->close();
		return $f;
	};
	for ( $i = 1; $i <= 12; $i++ ) {
		Portale\Releases::add( $rdb, 'free', $mkzip( 'associazionesemplice', "1.0.$i" ), 'n', 'test' );
	}
	$vers = array_column( Portale\Releases::all( $rdb, 'free' ), 'version' );
	check( 'pacchetti: restano le ultime 10, dalla più recente', 10 === count( $vers ) && '1.0.12' === $vers[0] && '1.0.3' === $vers[9] );
	check( 'pacchetti: i file vecchi sono cancellati dal disco', ! is_file( $rdir . '/free/1.0.1.zip' ) && is_file( $rdir . '/free/1.0.12.zip' ) );
	check( 'pacchetti: 1.0.10 viene dopo 1.0.9 (ordine numerico)', array_search( '1.0.10', $vers, true ) < array_search( '1.0.9', $vers, true ) );
	check( 'pacchetti: versione già presente rifiutata', null !== throwsUser( function () use ( $rdb, $mkzip ) { Portale\Releases::add( $rdb, 'free', $mkzip( 'associazionesemplice', '1.0.12' ), '', 'test' ); } ) );
	check( 'pacchetti: zip del prodotto sbagliato rifiutato', null !== throwsUser( function () use ( $rdb, $mkzip ) { Portale\Releases::add( $rdb, 'pro', $mkzip( 'associazionesemplice', '2.0.0' ), '', 'test' ); } ) );
	check( 'pacchetti: file non zip rifiutato', null !== throwsUser( function () use ( $rdb ) { Portale\Releases::add( $rdb, 'free', __FILE__, '', 'test' ); } ) );
	Portale\Releases::add( $rdb, 'pro', $mkzip( 'associazionesemplice-pro', '1.1.4' ), '', 'test' );
	$active = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'plan_code' => 'free' );
	$pro    = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'plan_code' => 'full' );
	$lapsed = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'plan_code' => 'full' );
	check( 'accesso: licenza gratuita → solo il gratuito', array( 'free' ) === Portale\Releases::entitled( array( $active ) ) );
	check( 'accesso: Pro attivo → gratuito e Pro', array( 'free', 'pro' ) === Portale\Releases::entitled( array( $active, $pro ) ) );
	check( 'accesso: Pro scaduto → niente', array() === Portale\Releases::entitled( array( $lapsed ) ) );
	check( 'accesso: senza licenze → niente', array() === Portale\Releases::entitled( array() ) );
	check( 'scarico: senza licenza Pro rifiutato', null !== throwsUser( function () use ( $rdb, $active ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $active ), 'pro', '1.1.4' ); } ) );
	check( 'scarico: versione inesistente rifiutata', null !== throwsUser( function () use ( $rdb, $pro ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '9.9.9' ); } ) );
	$now = 1700000000;
	for ( $i = 0; $i < 10; $i++ ) {
		Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now );
	}
	check( 'scarico: 11° scarico in 24 ore rifiutato', null !== throwsUser( function () use ( $rdb, $pro, $now ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now ); } ) );
	check( 'scarico: il giorno dopo si riparte', is_file( Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now + 90000 ) ) );
	check( 'scarico: amministratore senza limiti né licenza', is_file( Portale\Releases::authorize( $rdb, 'c9', true, array(), 'pro', '1.1.4', $now ) ) );
	check( 'pacchetti: versione letta dall\'intestazione', '1.2.3' === Portale\Releases::headerVersion( "<?php\n/**\n * Version:           1.2.3\n */" ) );
	check( 'pacchetti: Stable tag non scambiato per Version', '' === Portale\Releases::headerVersion( "Stable tag: 1.2.3\nRequires at least: 6.0" ) );
}

// --- Accesso con password -----------------------------------------------------------------
$adb = new Db( new PDO( 'sqlite::memory:' ) );
$adb->migrate();
$auth = new Portale\Auth( $adb );
$adb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'c1', 'socio@esempio.it', Db::now() ) );
check( 'password: senza password non si entra', 'Email o password non corrette.' === throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'qualsiasi-cosa', '9.9.9.1' ); } ) );
check( 'password: troppo corta rifiutata', null !== throwsUser( function () use ( $auth ) { $auth->setPassword( 'c1', 'corta' ); } ) );
check( 'password: uguale all\'email rifiutata', null !== throwsUser( function () use ( $auth ) { $auth->setPassword( 'c1', 'socio@esempio.it', 'socio@esempio.it' ); } ) );
$auth->setPassword( 'c1', 'una password lunga abbastanza', 'socio@esempio.it' );
check( 'password: impostata e salvata come impronta', $auth->hasPassword( 'c1' ) && false === strpos( (string) $adb->one( 'SELECT password_hash FROM customers' )['password_hash'], 'lunga' ) );
check( 'password: accesso riuscito (email in maiuscolo e spazi)', 'c1' === $auth->loginWithPassword( '  Socio@Esempio.IT ', 'una password lunga abbastanza', '9.9.9.2' )['id'] );
check( 'password: password lunghissima non troncata (oltre 72 byte)', true === ( function () use ( $auth ) { $auth->setPassword( 'c1', str_repeat( 'a', 80 ) . 'X' ); return ! $auth->checkPassword( 'c1', str_repeat( 'a', 80 ) . 'Y' ) && $auth->checkPassword( 'c1', str_repeat( 'a', 80 ) . 'X' ); } )() );
$auth->setPassword( 'c1', 'una password lunga abbastanza', 'socio@esempio.it' );
$m1 = throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'sbagliata-1', '9.9.9.3' ); } );
$m2 = throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'nessuno@esempio.it', 'sbagliata-1', '9.9.9.4' ); } );
check( 'password: stesso errore per email sconosciuta e password sbagliata', $m1 === $m2 && null !== $m1 );
for ( $i = 0; $i < 6; $i++ ) {
	throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'sbagliata-x', '9.9.9.5' ); } );
}
check( 'password: dopo 5 errori si blocca anche con la password giusta', false !== strpos( (string) throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'una password lunga abbastanza', '9.9.9.6' ); } ), 'Troppi tentativi' ) );
check( 'password: il blocco scade con l\'ora', 'c1' === $auth->loginWithPassword( 'socio@esempio.it', 'una password lunga abbastanza', '9.9.9.6', time() + 7200 )['id'] );

// --- Eliminazione di clienti e licenze --------------------------------------------------------
$ddb = new Db( new PDO( 'sqlite::memory:' ) );
$ddb->migrate();
$dbil = new Billing( $ddb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$ins = function ( string $cid, string $dom, string $status, string $prov = 'free', string $mode = 'once', int $cancel = 0 ) use ( $ddb ) {
	$id = Db::id();
	$ddb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, cancel_at_period_end, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array( $id, $cid, 'free', 'free', $prov, $status, $dom, $mode, $cancel, Db::now(), Db::now() ) );
	return $id;
};
$ddb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'k1', 'a@esempio.it', Db::now() ) );
$ddb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'k2', 'b@esempio.it', Db::now() ) );
$p1 = $ins( 'k1', 'sogna.it', 'pending' );
check( 'dominio: occupato da una licenza in attesa di un altro cliente', null !== throwsUser( function () use ( $dbil ) { $dbil->startFree( 'b@esempio.it', 'sogna.it' ); } ) );
$dbil->deleteSubscription( $p1 );
check( 'eliminazione: licenza tolta e dominio di nuovo libero', null === $dbil->subscription( $p1 ) && null === throwsUser( function () use ( $dbil ) { $dbil->startFree( 'b@esempio.it', 'sogna.it' ); } ) );
$rec = $ins( 'k1', 'ricorrente.it', 'active', 'stripe', 'recurring', 0 );
check( 'eliminazione: rinnovo automatico attivo → rifiutata', null !== throwsUser( function () use ( $dbil, $rec ) { $dbil->deleteSubscription( $rec ); } ) && null !== $dbil->subscription( $rec ) );
check( 'eliminazione cliente: bloccata se una licenza ha il rinnovo attivo', null !== throwsUser( function () use ( $dbil ) { $dbil->deleteCustomer( 'k1' ); } ) );
$ddb->run( 'UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = ?', array( $rec ) );
$ddb->run( 'INSERT INTO login_tokens (token_hash, customer_id, expires_at) VALUES (?,?,?)', array( 'h', 'k1', Db::now() ) );
check( 'eliminazione cliente: restituisce il numero di licenze eliminate', 1 === $dbil->deleteCustomer( 'k1' ) );
check( 'eliminazione cliente: email libera', null === $ddb->one( 'SELECT id FROM customers WHERE id = ?', array( 'k1' ) ) && null === $ddb->one( 'SELECT 1 AS x FROM login_tokens WHERE customer_id = ?', array( 'k1' ) ) && null === $ddb->one( 'SELECT 1 AS x FROM subscriptions WHERE customer_id = ?', array( 'k1' ) ) );

// --- Importazione dall'elenco Wega ------------------------------------------------------------
$sdb = new Db( new PDO( 'sqlite::memory:' ) );
$sdb->migrate();
$sbil  = new Billing( $sdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$pages = array(
	'' => array( 'data' => array(
		array( 'id' => 'L1', 'plan_name' => 'Pro Full', 'customer_id' => 'Uno@Esempio.it', 'state' => 'ACTIVE', 'domain' => 'https://www.uno.it', 'expires_at' => '2027-01-01T00:00:00Z' ),
		array( 'id' => 'L2', 'plan_name' => 'Piano Misterioso', 'customer_id' => 'due@esempio.it', 'state' => 'ACTIVE', 'domain' => 'due.it' ),
	), 'next_cursor' => 'c2' ),
	'c2' => array( 'data' => array(
		array( 'id' => 'L3', 'plan_name' => 'Free', 'customer_id' => 'non-un-email', 'state' => 'ACTIVE', 'domain' => 'tre.it' ),
		array( 'id' => 'L4', 'plan_name' => 'Pro Full', 'customer_id' => 'quattro@esempio.it', 'state' => 'REVOKED', 'domain' => 'quattro.it' ),
	) ),
);
Http::$transport = function ( $m, $url ) use ( $pages ) {
	preg_match( '/cursor=([^&]*)/', $url, $mm );
	return array( 200, json_encode( $pages[ isset( $mm[1] ) ? rawurldecode( $mm[1] ) : '' ] ) );
};
$all = Portale\WegaSync::fetchAll( new WegaClient( 'https://license.test/api/v1', 't' ) );
check( 'wega elenco: segue la paginazione fino in fondo', 4 === count( $all ) );
$cmp = Portale\WegaSync::compare( $sdb, $all );
check( 'wega elenco: mancante, piano ignoto, email non valida, mancante', array( 'missing', 'blocked', 'blocked', 'missing' ) === array_column( $cmp, '_state' ) );
$sub = Portale\WegaSync::import( $sdb, $sbil, $all[0] );
check( 'wega import: cliente in minuscolo, dominio normalizzato, piano mappato', 'uno@esempio.it' === $sdb->one( 'SELECT email FROM customers' )['email'] && 'uno.it' === $sub['domain'] && 'full' === $sub['plan_code'] && 'L1' === $sub['license_id'] && 'active' === $sub['status'] );
check( 'wega import: stato REVOKED → annullata', 'canceled' === Portale\WegaSync::import( $sdb, $sbil, $all[3] )['status'] );
check( 'wega import: ripetuta rifiutata', null !== throwsUser( function () use ( $sdb, $sbil, $all ) { Portale\WegaSync::import( $sdb, $sbil, $all[0] ); } ) );
check( 'wega import: dopo l\'importazione risulta già nel portale', 'known' === Portale\WegaSync::compare( $sdb, array( $all[0] ) )[0]['_state'] );
check( 'wega orfane: licenza del portale che Wega non elenca', 1 === count( Portale\WegaSync::orphans( $sdb, array( $all[0] ) ) ) );

// --- Specchio su Wega -------------------------------------------------------------------------
$mdb  = new Db( new PDO( 'sqlite::memory:' ) );
$mdb->migrate();
$mbil = new Billing( $mdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$mcalls = array();
$mcode  = 200;
Http::$transport = function ( $m, $url ) use ( &$mcalls, &$mcode ) {
	$mcalls[] = $m . ' ' . substr( $url, strlen( 'https://license.test/api/v1' ) );
	return 200 === $mcode ? array( 200, '{"data":{"state":"REVOKED"}}' ) : array( $mcode, '{"error":{"code":"X","message":"m"}}' );
};
$msub = array( 'license_id' => 'L9', 'domain' => 'm.it' );
check( 'specchio: senza licenza su Wega non chiama nulla', '' === $mbil->mirror( array( 'license_id' => null ), 'revoke' ) && array() === $mcalls );
check( 'specchio: revoca riuscita chiama Wega', '' === $mbil->mirror( $msub, 'revoke' ) && array( 'POST /licenses/L9/revoke' ) === $mcalls );
$mcode = 404;
check( 'specchio: operazione non ancora offerta (404) → avviso, si prosegue', '' !== $mbil->mirror( $msub, 'suspend' ) );
$mcode = 500;
check( 'specchio: errore vero del server → bloccato', null !== throwsUser( function () use ( $mbil, $msub ) { $mbil->mirror( $msub, 'restore' ); } ) );
check( 'differenze: licenze identiche', array() === Portale\WegaSync::differences( array( 'domain' => 'a.it', 'expires_at' => '2027-01-01 00:00:00', 'status' => 'active' ), array( 'domain' => 'www.a.it', 'expires_at' => '2027-01-01T00:00:00Z', 'state' => 'ACTIVE' ) ) );
check( 'differenze: dominio, scadenza e stato diversi', 3 === count( Portale\WegaSync::differences( array( 'domain' => 'a.it', 'expires_at' => '2027-01-01 00:00:00', 'status' => 'canceled' ), array( 'domain' => 'b.it', 'expires_at' => '2027-03-01T00:00:00Z', 'state' => 'ACTIVE' ) ) ) );

// --- Rinnovo automatico delle licenze gratuite ------------------------------------------------
$fdb  = new Db( new PDO( 'sqlite::memory:' ) );
$fdb->migrate();
$fbil = new Billing( $fdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$fdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'f1', 'f@esempio.it', Db::now() ) );
$fnow = 1800000000;
$fmk  = function ( string $dom, string $exp, ?string $seen, string $created, string $provider = 'free' ) use ( $fdb ) {
	$id = Db::id();
	$fdb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, license_key_enc, expires_at, last_seen_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array( $id, 'f1', 'free', 'free', $provider, 'active', $dom, 'once', Portale\Secrets::seal( 'K-' . $dom ), $exp, $seen, $created, $created ) );
	return $id;
};
$day     = 86400;
$due     = $fmk( 'attiva.it', gmdate( 'Y-m-d H:i:s', $fnow + $day ), gmdate( 'Y-m-d H:i:s', $fnow - 5 * $day ), gmdate( 'Y-m-d H:i:s', $fnow - 300 * $day ) );
$idle    = $fmk( 'inattiva.it', gmdate( 'Y-m-d H:i:s', $fnow + $day ), gmdate( 'Y-m-d H:i:s', $fnow - 45 * $day ), gmdate( 'Y-m-d H:i:s', $fnow - 300 * $day ) );
$fresh   = $fmk( 'nuova.it', gmdate( 'Y-m-d H:i:s', $fnow + $day ), null, gmdate( 'Y-m-d H:i:s', $fnow - 10 * $day ) );
$later   = $fmk( 'lontana.it', gmdate( 'Y-m-d H:i:s', $fnow + 100 * $day ), gmdate( 'Y-m-d H:i:s', $fnow ), gmdate( 'Y-m-d H:i:s', $fnow - 300 * $day ) );
$paid    = $fmk( 'pagata.it', gmdate( 'Y-m-d H:i:s', $fnow + $day ), gmdate( 'Y-m-d H:i:s', $fnow ), gmdate( 'Y-m-d H:i:s', $fnow - 300 * $day ), 'stripe' );
$renewed = array();
Http::$transport = function ( $m, $url, $h, $body ) use ( &$renewed, $fnow ) {
	$renewed[] = json_decode( (string) $body, true )['license_key'];
	return array( 200, json_encode( array( 'data' => array( 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $fnow + 366 * 86400 ) ) ) ) );
};
list( $r, $l ) = $fbil->autoRenewFree( $fnow );
check( 'gratuite: rinnova chi ha accessi recenti e chi è appena nato, lascia scadere chi è inattivo da oltre 30 giorni', 2 === $r && 1 === $l && array( 'K-attiva.it', 'K-nuova.it' ) === $renewed );
check( 'gratuite: la scadenza della rinnovata si sposta avanti', strtotime( $fbil->subscription( $due )['expires_at'] . ' UTC' ) > $fnow + 300 * $day );
check( 'gratuite: non tocca quelle lontane dalla scadenza né quelle a pagamento', in_array( 'K-lontana.it', $renewed, true ) === false && in_array( 'K-pagata.it', $renewed, true ) === false );
list( $r2 ) = $fbil->autoRenewFree( $fnow );
check( 'gratuite: ripetere il lavoro non rinnova due volte', 0 === $r2 );
$fbil->touch( 'ZZ' );
$fdb->run( 'UPDATE subscriptions SET license_id = ? WHERE id = ?', array( 'LIC-I', $idle ) );
$fbil->touch( 'LIC-I', $fnow );
check( 'gratuite: un accesso riporta la licenza tra quelle da rinnovare', 'x' !== (string) $fdb->one( 'SELECT last_seen_at FROM subscriptions WHERE id = ?', array( $idle ) )['last_seen_at'] && strtotime( $fdb->one( 'SELECT last_seen_at FROM subscriptions WHERE id = ?', array( $idle ) )['last_seen_at'] . ' UTC' ) === $fnow );

// --- Ultime versioni dei plugin (avviso aggiornamenti) ----------------------------------------
$ldb = new Db( new PDO( 'sqlite::memory:' ) );
$ldb->migrate();
$lpx = new Portale\LicenseProxy( $ldb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$r   = $lpx->latest( '7.7.7.7', 1700000000 );
check( 'aggiornamenti: senza versioni pubblicate l\'elenco è vuoto', 200 === $r[0] && '{}' === $r[1] );
foreach ( array( '1.1.9', '1.1.14', '1.1.10' ) as $v ) {
	$ldb->run( 'INSERT INTO releases (product, version, size, sha256, created_at) VALUES (?,?,?,?,?)', array( 'free', $v, 1, 'x', Db::now() ) );
}
$j = json_decode( $lpx->latest( '7.7.7.7', 1700000000 )[1], true );
check( 'aggiornamenti: ultima versione in ordine numerico, prodotto senza versioni omesso, link https all\'accesso', '1.1.14' === $j['free']['version'] && ! isset( $j['pro'] ) && 0 === strpos( $j['free']['url'], 'https://' ) && '/accedi' === substr( $j['free']['url'], -7 ) );
check( 'aggiornamenti: cache pubblica di 5 minuti', in_array( 'Cache-Control: public, max-age=300', $lpx->latest( '7.7.7.7', 1700000000 )[2], true ) );
$last = 0;
for ( $i = 0; $i < 70; $i++ ) {
	$last = $lpx->latest( '8.8.8.8', 1700000000 )[0];
}
check( 'aggiornamenti: limite per IP', 429 === $last );

// --- Recupero della chiave da Wega ------------------------------------------------------------
$kdb  = new Db( new PDO( 'sqlite::memory:' ) );
$kdb->migrate();
$kbil = new Billing( $kdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$kdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'k1', 'k@esempio.it', Db::now() ) );
$kdb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, license_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array( 's1', 'k1', 'free', 'manual', 'manual', 'active', 'k.it', 'once', 'LK1', Db::now(), Db::now() ) );
$kurl = '';
Http::$transport = function ( $m, $url ) use ( &$kurl ) {
	$kurl = $m . ' ' . $url;
	return array( 200, '{"data":{"license_id":"LK1","license_key":"WEGA-RECUPERATA"}}' );
};
check( 'chiave: recuperata da Wega e salvata cifrata', $kbil->recoverKey( 's1' ) && 'GET https://license.test/api/v1/licenses/LK1/key' === $kurl && 'WEGA-RECUPERATA' === Portale\Secrets::open( $kbil->subscription( 's1' )['license_key_enc'] ) );
check( 'chiave: già presente, non si rilegge', ! $kbil->recoverKey( 's1' ) );
// --- Risoluzione di riserva del server licenze --------------------------------------------------
new WegaClient( 'https://license.test/api/v1', 't' );
check( 'wega: se il DNS non risolve il nome si riprova sull IP noto', '65.108.75.89' === ( Http::$fallback['license.test'] ?? '' ) );
Http::$fallback = array();

// --- Modalità reale/prova: due gruppi di credenziali ---------------------------------------------------------
$k = array( 'live' => 'sk_live_' . str_repeat( 'b', 24 ), 'test' => 'sk_test_' . str_repeat( 'a', 24 ) );
$pdb2 = new Db( new PDO( 'sqlite::memory:' ) );
$pdb2->migrate();
Portale\Settings::load( $pdb2 );
check( 'stripe: chiave di prova nel campo reale rifiutata', null !== throwsUser( function () use ( $pdb2, $k ) { Portale\Settings::saveSecrets( $pdb2, array( 'stripe_secret' => $k['test'] ) ); } ) );
check( 'stripe: chiave reale nel campo di prova rifiutata', null !== throwsUser( function () use ( $pdb2, $k ) { Portale\Settings::saveSecrets( $pdb2, array( 'stripe_secret_test' => $k['live'] ) ); } ) );
Portale\Settings::saveSecrets( $pdb2, array( 'stripe_live_present' => '1', 'stripe_secret' => $k['live'], 'stripe_secret_test' => $k['test'], 'stripe_webhook' => 'whsec_LIVELIVELIVE1', 'stripe_webhook_test' => 'whsec_TESTTESTTEST1' ) );
Portale\Settings::load( $pdb2 );
check( 'stripe: con la casella spenta si usano le credenziali di prova', false === Portale\Settings::stripeLive() && $k['test'] === Config::get( 'stripe.secret_key' ) && 'whsec_TESTTESTTEST1' === Config::get( 'stripe.webhook_secret' ) );
Portale\Settings::saveSecrets( $pdb2, array( 'stripe_live_present' => '1', 'stripe_live' => '1' ) );
Portale\Settings::load( $pdb2 );
check( 'stripe: accendere la casella cambia da sola le credenziali (reali)', true === Portale\Settings::stripeLive() && $k['live'] === Config::get( 'stripe.secret_key' ) && 'whsec_LIVELIVELIVE1' === Config::get( 'stripe.webhook_secret' ) );
Portale\Settings::saveSecrets( $pdb2, array( 'paypal_client_test' => str_repeat( 'S', 24 ), 'paypal_secret_test' => str_repeat( 'T', 24 ), 'paypal_live_present' => '1' ) );
Portale\Settings::load( $pdb2 );
check( 'paypal: sandbox attivo usa le credenziali sandbox; Stripe resta reale', str_repeat( 'S', 24 ) === Config::get( 'paypal.client_id' ) && true === Portale\Settings::stripeLive() );
// Vecchio salvataggio con un solo gruppo: si assegna alla modalità giusta.
$mdb2 = new Db( new PDO( 'sqlite::memory:' ) );
$mdb2->migrate();
$mdb2->run( 'INSERT INTO settings (name, val) VALUES (?,?)', array( 'secrets', json_encode( array( 'stripe_secret' => Portale\Secrets::seal( $k['test'] ), 'stripe_webhook' => Portale\Secrets::seal( 'whsec_OLDOLDOLD12' ) ) ) ) );
Portale\Settings::load( $mdb2 );
$st2 = Portale\Settings::secretsStatus( $mdb2 );
check( 'migrazione: chiave Stripe di prova del vecchio salvataggio finisce nel gruppo di prova', ! $st2['stripe_secret']['set'] && $st2['stripe_secret_test']['set'] && $st2['stripe_webhook_test']['set'] && false === Portale\Settings::stripeLive() );


// --- Dominio registrabile: la licenza vale per i sottodomini ----------------------------------
check( 'dominio: staging, test e clone ricadono nel dominio registrabile', array( 'esempio.it', 'esempio.it', 'esempio.it', 'esempio.it' ) === array_map( array( 'Portale\Domain', 'forLicense' ), array( 'staging.esempio.it', 'https://test.esempio.it/wp-admin', 'www.clone.esempio.it', 'esempio.it/staging' ) ) );
check( 'dominio: suffissi a più etichette (co.uk)', 'esempio.co.uk' === Domain::forLicense( 'staging.esempio.co.uk' ) && '' === Domain::forLicense( 'localhost' ) );
$rdb2 = new Db( new PDO( 'sqlite::memory:' ) );
$rdb2->migrate();
$seen = array();
Http::$transport = function ( $m, $url, $h, $body ) use ( &$seen ) {
	$in     = json_decode( (string) $body, true );
	$seen[] = $in['domain'];
	$ok     = in_array( $in['domain'], array( 'esempio.it', 'legato.esempio.it' ), true ) && ( 'legato.esempio.it' === $in['domain'] || ! empty( $GLOBALS['reg_ok'] ) );
	return array( 200, json_encode( array( 'valid' => $ok, 'reason_code' => $ok ? 'ACTIVE' : 'DOMAIN_MISMATCH', 'license_id' => 'LZ' ) ) );
};
$GLOBALS['reg_ok'] = true;
$px = new Portale\LicenseProxy( $rdb2, new WegaClient( 'https://license.test/api/v1', 't' ) );
$r  = json_decode( $px->validate( '{"license_key":"chiave-di-prova-1","domain":"staging.esempio.it"}', '3.3.3.3', 1700000000 )[1], true );
check( 'rimbalzo: un sottodominio chiede a Wega il dominio registrabile', true === $r['valid'] && array( 'esempio.it' ) === $seen );
$seen = array();
$GLOBALS['reg_ok'] = false;
$r = json_decode( $px->validate( '{"license_key":"chiave-di-prova-2","domain":"legato.esempio.it"}', '3.3.3.4', 1700000000 )[1], true );
check( 'rimbalzo: licenza emessa per un sottodominio esatto: secondo tentativo sul sottodominio', true === $r['valid'] && array( 'esempio.it', 'legato.esempio.it' ) === $seen );
// --- Staff e accesso unico al supporto --------------------------------------------------------
$tdb  = new Db( new PDO( 'sqlite::memory:' ) );
$tdb->migrate();
$tbil = new Billing( $tdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
Config::set( 'admins', array( 'Capo@Esempio.it' ) );
check( 'staff: admin di config.php', 'admin' === Portale\Staff::role( $tdb, 'capo@esempio.it' ) );
check( 'staff: sconosciuto senza ruolo', null === Portale\Staff::role( $tdb, 'altro@esempio.it' ) );
Portale\Staff::set( $tdb, $tbil, ' Aiuto@Esempio.it ', 'support' );
check( 'staff: solo supporto non è amministratore', 'support' === Portale\Staff::role( $tdb, 'aiuto@esempio.it' ) && ! Portale\Admin::isAdminEmail( 'aiuto@esempio.it', $tdb ) );
check( 'staff: crea anche il cliente per poter entrare', 1 === (int) $tdb->one( 'SELECT COUNT(*) AS n FROM customers WHERE email = ?', array( 'aiuto@esempio.it' ) )['n'] );
Portale\Staff::set( $tdb, $tbil, 'aiuto@esempio.it', 'admin' );
check( 'staff: cambio ruolo a admin', Portale\Admin::isAdminEmail( 'aiuto@esempio.it', $tdb ) && 2 === count( Portale\Staff::all( $tdb ) ) );
check( 'staff: ruolo non valido rifiutato', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'x@esempio.it', 'root' ); } ) );
check( 'staff: admin di config.php non modificabile', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'capo@esempio.it', 'support' ); } ) );
Portale\Staff::remove( $tdb, 'aiuto@esempio.it' );
check( 'staff: rimosso', null === Portale\Staff::role( $tdb, 'aiuto@esempio.it' ) );
Config::set( 'base_url', 'https://portale.test' );
Config::set( 'support', array( 'url' => 'https://supporto.test/', 'sso_secret' => str_repeat( 's', 40 ) ) );
check( 'sso: attivo con segreto lungo, indirizzo senza barra finale', Portale\SupportSso::enabled() && 'https://supporto.test' === Portale\SupportSso::supportUrl() );
list( $p64, $sig ) = explode( '.', Portale\SupportSso::token( 'Capo@Esempio.it', 'admin', 1000 ) );
$pl = json_decode( base64_decode( strtr( $p64, '-_', '+/' ) ), true );
check( 'sso: contenuto del token', 'capo@esempio.it' === $pl['sub'] && 'admin' === $pl['role'] && 1060 === $pl['exp'] && 'https://supporto.test' === $pl['aud'] && 32 === strlen( $pl['jti'] ) );
check( 'sso: firma HMAC-SHA256 verificabile', hash_equals( rtrim( strtr( base64_encode( hash_hmac( 'sha256', $p64, str_repeat( 's', 40 ), true ) ), '+/', '-_' ), '=' ), $sig ) );
check( 'sso: chi non è staff entra come cliente', 'customer' === Portale\SupportSso::roleFor( $tdb, 'cliente@esempio.it' ) );
Config::set( 'support', array( 'url' => 'https://supporto.test', 'sso_secret' => 'corto' ) );
check( 'sso: segreto corto → non attivo, si va alla home del supporto', ! Portale\SupportSso::enabled() && 'https://supporto.test' === Portale\SupportSso::url( $tdb, 'capo@esempio.it' ) );

// --- Accesso unico al supporto: piano nel token ---------------------------------------------
$cdb = new Db( new PDO( 'sqlite::memory:' ) );
$cdb->migrate();
$cdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'p1', 'free@esempio.it', Db::now() ) );
$cdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'p2', 'pro@esempio.it', Db::now() ) );
$cdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'p3', 'scaduto@esempio.it', Db::now() ) );
$ins2 = function ( $cid, $plan, $exp ) use ( $cdb ) {
	$cdb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array( Db::id(), $cid, $plan, 'year', 'manual', 'active', Db::id() . '.it', 'once', $exp, Db::now(), Db::now() ) );
};
$future = gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 );
$past   = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$ins2( 'p1', 'free', $future );
$ins2( 'p2', 'free', $future );
$ins2( 'p2', 'full', $future );
$ins2( 'p3', 'full', $past );
check( 'sso: solo licenza gratuita → piano Free', array( 'plan' => 'Free' ) === Portale\SupportSso::claimsFor( $cdb, 'Free@Esempio.it' ) );
check( 'sso: licenza a pagamento in regola → piano Pro', array( 'plan' => 'Pro' ) === Portale\SupportSso::claimsFor( $cdb, 'pro@esempio.it' ) );
check( 'sso: licenza scaduta o sconosciuto → nessun piano', array() === Portale\SupportSso::claimsFor( $cdb, 'scaduto@esempio.it' ) && array() === Portale\SupportSso::claimsFor( $cdb, 'nessuno@esempio.it' ) );
Config::set( 'support', array( 'url' => 'https://supporto.test', 'sso_secret' => str_repeat( 'a', 64 ) ) );
list( $pp ) = explode( '.', Portale\SupportSso::token( 'pro@esempio.it', 'customer', 1000, array( 'plan' => 'Pro' ) ) );
$pl2 = json_decode( base64_decode( strtr( $pp, '-_', '+/' ) ), true );
check( 'sso: il token porta il piano e conserva gli altri claim', 'Pro' === $pl2['plan'] && 'customer' === $pl2['role'] && 'pro@esempio.it' === $pl2['sub'] && 1060 === $pl2['exp'] );

// --- Versioni con due numeri (1.2) ---------------------------------------------------------------
check( 'versioni: ammesse x.y.z e x.y, il resto no', Portale\Releases::validVersion( '1.1.14' ) && Portale\Releases::validVersion( '1.2' ) && ! Portale\Releases::validVersion( '1' ) && ! Portale\Releases::validVersion( '1.2.3.4' ) && ! Portale\Releases::validVersion( '../1.2' ) && ! Portale\Releases::validVersion( '1.2 ' ) );
check( 'versioni: intestazione con due numeri', '1.2' === Portale\Releases::headerVersion( "<?php\n/**\n * Version:           1.2\n */" ) );
if ( class_exists( 'ZipArchive' ) ) {
	$vdb = new Db( new PDO( 'sqlite::memory:' ) );
	$vdb->migrate();
	Config::set( 'releases_dir', sys_get_temp_dir() . '/asem-rel-v-' . bin2hex( random_bytes( 4 ) ) );
	foreach ( array( '1.1.20', '1.2', '1.1.9' ) as $vv ) {
		$zf = tempnam( sys_get_temp_dir(), 'z' );
		$zz = new ZipArchive();
		$zz->open( $zf, ZipArchive::OVERWRITE );
		$zz->addFromString( 'associazionesemplice/associazionesemplice.php', "<?php\n/**\n * Version:           $vv\n */\n" );
		$zz->close();
		Portale\Releases::add( $vdb, 'free', $zf, '', 'test' );
	}
	check( 'versioni: 1.1.20 viene prima di 1.2, che è l\'ultima', array( '1.2', '1.1.20', '1.1.9' ) === array_column( Portale\Releases::all( $vdb, 'free' ), 'version' ) );
	$lp = json_decode( ( new Portale\LicenseProxy( $vdb, new WegaClient( 'https://license.test/api/v1', 't' ) ) )->latest( '4.4.4.4', 1700000000 )[1], true );
	check( 'versioni: l\'avviso aggiornamenti propone 1.2', '1.2' === $lp['free']['version'] );
	check( 'versioni: scarico della 1.2 autorizzato', is_file( Portale\Releases::authorize( $vdb, 'k', true, array(), 'free', '1.2' ) ) );
}

// --- Aggiornamento diretto dei plugin da WordPress ------------------------------------------------
if ( class_exists( 'ZipArchive' ) ) {
	$udb = new Db( new PDO( 'sqlite::memory:' ) );
	$udb->migrate();
	Config::set( 'releases_dir', sys_get_temp_dir() . '/asem-rel-u-' . bin2hex( random_bytes( 4 ) ) );
	foreach ( array( array( 'free', 'associazionesemplice', '1.2' ), array( 'pro', 'associazionesemplice-pro', '1.1.5' ) ) as $rel ) {
		$zf = tempnam( sys_get_temp_dir(), 'z' );
		$zz = new ZipArchive();
		$zz->open( $zf, ZipArchive::OVERWRITE );
		$zz->addFromString( $rel[1] . '/' . $rel[1] . '.php', "<?php\n/**\n * Version:           {$rel[2]}\n */\n" );
		$zz->close();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) { continue; }
		static $ukp = null;
		if ( null === $ukp ) { $ukp = sodium_crypto_sign_keypair(); Config::set( 'package_keys', array( 'k-u' => rtrim( strtr( base64_encode( sodium_crypto_sign_publickey( $ukp ) ), '+/', '-_' ), '=' ) ) ); }
		$usig = rtrim( strtr( base64_encode( sodium_crypto_sign_detached( Portale\Releases::signedMessage( $rel[0], $rel[2], hash_file( 'sha256', $zf ) ), sodium_crypto_sign_secretkey( $ukp ) ) ), '+/', '-_' ), '=' );
		Portale\Releases::add( $udb,$rel[0], $zf, '', 'test', null, $usig, 'k-u' );
	}
	$GLOBALS['u_valid'] = true;
	Http::$transport = function ( $m, $url, $h, $body ) {
		$in = json_decode( (string) $body, true );
		return array( 200, json_encode( array( 'valid' => $GLOBALS['u_valid'], 'reason_code' => 'ACTIVE', 'plan_name' => $GLOBALS['u_plan'] ?? 'Pro Full', 'license_id' => 'LU', 'product_code' => 'prodotto-u', 'identity' => 'esempio.it' ) ) );
	};
	$upx = new Portale\LicenseProxy( $udb, new WegaClient( 'https://license.test/api/v1', 't' ) );
	$ut  = 1800000000;
	$r   = Portale\PluginUpdates::check( $udb, $upx, '{"product":"free","version":"1.1.20"}', '9.1.1.1', $ut );
	$j   = json_decode( $r[1], true );
	check( 'aggiornamento free: versione più vecchia → c\'è l\'aggiornamento, con link firmato, senza chiave', 200 === $r[0] && '1.2' === $j['version'] && true === $j['update'] && 0 === strpos( (string) $j['package'], 'https://portale.test/api/plugin/package?t=' ) );
	$j2 = json_decode( Portale\PluginUpdates::check( $udb, $upx, '{"product":"free","version":"1.2"}', '9.1.1.2', $ut )[1], true );
	check( 'aggiornamento free: già all\'ultima → nessun link', false === $j2['update'] && null === $j2['package'] );
	check( 'aggiornamento: prodotto sconosciuto rifiutato', 422 === Portale\PluginUpdates::check( $udb, $upx, '{"product":"x"}', '9.1.1.3', $ut )[0] );
	$pro = '{"product":"pro","version":"1.1.4","license_key":"chiave-pro-di-prova","domain":"staging.esempio.it"}';
	$GLOBALS['u_valid'] = false;
	check( 'aggiornamento Pro: licenza non valida → 403', 403 === Portale\PluginUpdates::check( $udb, $upx, $pro, '9.1.1.4', $ut )[0] );
	$GLOBALS['u_valid'] = true;
	$GLOBALS['u_plan']  = 'Free';
	check( 'aggiornamento Pro: licenza gratuita → 403', 403 === Portale\PluginUpdates::check( $udb, $upx, str_replace( 'chiave-pro-di-prova', 'chiave-gratuita-prova', $pro ), '9.1.1.5', $ut )[0] );
	$GLOBALS['u_plan'] = 'Pro Lite';
	$jp = json_decode( Portale\PluginUpdates::check( $udb, $upx, str_replace( 'chiave-pro-di-prova', 'chiave-lite-prova-1', $pro ), '9.1.1.6', $ut )[1], true );
	check( 'aggiornamento Pro: licenza Pro valida → link', true === $jp['update'] && '1.1.5' === $jp['version'] && ! empty( $jp['package'] ) );
	// Con la chiave del portale conta il livello firmato dal portale, non il nome del piano su Wega.
	Config::set( 'portal_sign_key', base64_encode( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
	$udb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, license_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( 'su1', 'cu', 'free', 'year', 'free', 'active', 'esempio.it', 'LU', Db::now(), Db::now() ) );
	$GLOBALS['u_plan'] = 'Pro Lite';
	check( 'aggiornamento Pro: il portale dice «free», anche se il nome su Wega sembra Pro → 403', 403 === Portale\PluginUpdates::check( $udb, $upx, str_replace( 'chiave-pro-di-prova', 'chiave-nome-pro-1', $pro ), '9.1.1.7', $ut )[0] );
	$udb->run( "UPDATE subscriptions SET plan_code = 'full' WHERE id = 'su1'" );
	$GLOBALS['u_plan'] = 'Free';
	$jq = json_decode( Portale\PluginUpdates::check( $udb, $upx, str_replace( 'chiave-pro-di-prova', 'chiave-nome-free-1', $pro ), '9.1.1.8', $ut )[1], true );
	check( 'aggiornamento Pro: il portale dice «fiscal», anche se il nome su Wega è Free → link', ! empty( $jq['update'] ) && ! empty( $jq['package'] ) );
	Config::set( 'portal_sign_key', '' );
	$tok = substr( $j['package'], strpos( $j['package'], '?t=' ) + 3 );
	$pk  = Portale\PluginUpdates::package( $udb, $tok, $ut + 10 );
	check( 'pacchetto: token valido → file dello zip', 200 === $pk[0] && is_file( $pk[1] ) && 'free' === $pk[2] && '1.2' === $pk[3] );
	check( 'pacchetto: token scaduto → 410', 410 === Portale\PluginUpdates::package( $udb, $tok, $ut + 3600 )[0] );
	check( 'pacchetto: token manomesso → 403', 403 === Portale\PluginUpdates::package( $udb, substr( $tok, 0, -3 ) . 'xxx', $ut )[0] && 403 === Portale\PluginUpdates::package( $udb, 'abc', $ut )[0] );
}

// --- Revisione di sicurezza ---------------------------------------------------------------------
$sdb2 = new Db( new PDO( 'sqlite::memory:' ) );
$sdb2->migrate();
$wh   = new Portale\Webhooks( $sdb2, new Billing( $sdb2, new WegaClient( 'https://license.test/api/v1', 't' ) ) );
Config::set( 'stripe', array( 'webhook_secret' => 'whsec_test' ) );
$stripeEvent = function ( array $ev ) use ( $wh ) {
	$p = json_encode( $ev );
	$t = time();
	return $wh->stripe( $p, 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $p, 'whsec_test' ) );
};
$sdb2->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'w1', 'w@esempio.it', Db::now() ) );
$sdb2->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( 'ws1', 'w1', 'full', 'year', 'stripe', 'pending', 'w.it', 'recurring', Db::now(), Db::now() ) );
$wcalls = 0;
Http::$transport = function () use ( &$wcalls ) {
	++$wcalls;
	return array( 200, json_encode( array( 'data' => array( 'license_id' => 'LW', 'license_key' => 'WEGA-KEY-W', 'expires_at' => '2027-01-01T00:00:00Z' ) ) ) );
};
$stripeEvent( array( 'id' => 'evt_a', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'client_reference_id' => 'ws1', 'mode' => 'subscription', 'payment_status' => 'unpaid', 'subscription' => 'sub_1', 'customer' => 'cus_1' ) ) ) );
check( 'stripe: pagamento non ancora incassato (unpaid) → nessuna licenza', 0 === $wcalls && 'pending' === $sdb2->one( "SELECT status FROM subscriptions WHERE id = 'ws1'" )['status'] );
$stripeEvent( array( 'id' => 'evt_b', 'type' => 'checkout.session.async_payment_succeeded', 'data' => array( 'object' => array( 'client_reference_id' => 'ws1', 'mode' => 'subscription', 'payment_status' => 'paid', 'subscription' => 'sub_1', 'customer' => 'cus_1' ) ) ) );
check( 'stripe: incasso asincrono riuscito → licenza emessa', $wcalls >= 1 && 'active' === $sdb2->one( "SELECT status FROM subscriptions WHERE id = 'ws1'" )['status'] );
// moduli pubblici e domini
check( 'limite moduli: l\'undicesima richiesta dello stesso IP in un\'ora è respinta', ( function () use ( $sdb2 ) { $ok = 0; for ( $i = 0; $i < 12; $i++ ) { $ok += Portale\RateLimit::hit( $sdb2, 'form', '5.6.7.8', 10, 1700000000 ) ? 1 : 0; } return 10 === $ok; } )() && Portale\RateLimit::hit( $sdb2, 'form', '5.6.7.9', 10, 1700000000 ) && Portale\RateLimit::hit( $sdb2, 'form', '5.6.7.8', 10, 1700000000 + 7200 ) );
$sbil2 = new Billing( $sdb2, new WegaClient( 'https://license.test/api/v1', 't' ) );
$sdb2->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'w2', 'altro@esempio.it', Db::now() ) );
$sdb2->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( 'old1', 'w2', 'free', 'free', 'free', 'pending', 'occupato.it', 'once', gmdate( 'Y-m-d H:i:s', time() - 5 * 86400 ), Db::now() ) );
check( 'dominio: una richiesta in attesa da oltre 48 ore non lo tiene più occupato', null === throwsUser( function () use ( $sbil2 ) { $sbil2->startFree( 'w@esempio.it', 'occupato.it' ); } ) );
$sdb2->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( 'fresh1', 'w2', 'free', 'free', 'free', 'active', 'squat.it', 'once', Db::now(), Db::now() ) );
check( 'dominio: una licenza gratuita altrui attiva blocca un\'altra richiesta gratuita', null !== throwsUser( function () use ( $sbil2 ) { $sbil2->startFree( 'w@esempio.it', 'squat.it' ); } ) );
$rc = new ReflectionMethod( $sbil2, 'create' );
$rc->setAccessible( true );
check( 'dominio: chi paga non è bloccato da una licenza gratuita altrui sullo stesso dominio', is_array( $rc->invoke( $sbil2, 'w@esempio.it', 'squat.it', 'full', 'year', 'stripe', 'recurring' ) ) );
// intestazioni
check( 'intestazioni: nosniff, permessi e CSP definiti', ( function () { ob_start(); $src = file_get_contents( __DIR__ . '/../src/View.php' ); ob_end_clean(); return false !== strpos( $src, 'X-Content-Type-Options: nosniff' ) && false !== strpos( $src, 'Content-Security-Policy' ) && false !== strpos( $src, 'Permissions-Policy' ); } )() );

// --- Nome del cliente ----------------------------------------------------------------------------
$ndb = new Db( new PDO( 'sqlite::memory:' ) );
$ndb->migrate();
$nbil = new Billing( $ndb, new WegaClient( 'https://license.test/api/v1', 't' ) );
check( 'nome: spazi e caratteri di controllo ripuliti, al massimo 150 caratteri', 'Mario Rossi' === Portale\Billing::cleanName( "  Mario \t\n Rossi\x00 " ) && 150 === mb_strlen( Portale\Billing::cleanName( str_repeat( 'a', 400 ) ) ) );
list( $nc ) = $nbil->startFree( 'nuovo@esempio.it', 'nome-prova.it', '  Anna   Verdi ' );
check( 'nome: dal modulo pubblico per un cliente nuovo', 'Anna Verdi' === $ndb->one( 'SELECT name FROM customers WHERE id = ?', array( $nc['id'] ) )['name'] );
$nbil->startFree( 'nuovo@esempio.it', 'altro-nome.it', 'Impostore' );
check( 'nome: un modulo pubblico non cambia il nome di un cliente già presente', 'Anna Verdi' === $ndb->one( 'SELECT name FROM customers WHERE id = ?', array( $nc['id'] ) )['name'] );
$nbil->setName( $nc['id'], 'Anna V. Verdi' );
check( 'nome: dall\'area riservata si può cambiare', 'Anna V. Verdi' === $ndb->one( 'SELECT name FROM customers WHERE id = ?', array( $nc['id'] ) )['name'] );
check( 'sso: il token porta il nome del cliente', 'Anna V. Verdi' === Portale\SupportSso::claimsFor( $ndb, 'nuovo@esempio.it' )['name'] );
$nbil->setName( $nc['id'], '   ' );
check( 'sso: senza nome il claim non c\'è', ! isset( Portale\SupportSso::claimsFor( $ndb, 'nuovo@esempio.it' )['name'] ) );

// --- Pacchetti firmati ---------------------------------------------------------------------------
if ( class_exists( 'ZipArchive' ) && function_exists( 'sodium_crypto_sign_keypair' ) ) {
	$kp   = sodium_crypto_sign_keypair();
	$sk   = sodium_crypto_sign_secretkey( $kp );
	$b64u = function ( $r ) { return rtrim( strtr( base64_encode( $r ), '+/', '-_' ), '=' ); };
	Config::set( 'package_keys', array( 'k-test' => $b64u( sodium_crypto_sign_publickey( $kp ) ) ) );
	$gdb = new Db( new PDO( 'sqlite::memory:' ) );
	$gdb->migrate();
	Config::set( 'releases_dir', sys_get_temp_dir() . '/asem-rel-g-' . bin2hex( random_bytes( 4 ) ) );
	$mkz = function ( $ver ) {
		$zf = tempnam( sys_get_temp_dir(), 'z' );
		$zz = new ZipArchive();
		$zz->open( $zf, ZipArchive::OVERWRITE );
		$zz->addFromString( 'associazionesemplice-pro/associazionesemplice-pro.php', "<?php\n/**\n * Version:           $ver\n */\n" );
		$zz->close();
		return $zf;
	};
	$z1  = $mkz( '1.0.1' );
	$sig = $b64u( sodium_crypto_sign_detached( Portale\Releases::signedMessage( 'pro', '1.0.1', hash_file( 'sha256', $z1 ) ), $sk ) );
	$row = Portale\Releases::add( $gdb, 'pro', $z1, '', 'test', null, $sig, 'k-test' );
	check( 'firma: pacchetto firmato accettato e registrato', $sig === $row['signature'] && 'k-test' === $row['kid'] );
	check( 'firma: firma di un altro pacchetto rifiutata', null !== throwsUser( function () use ( $gdb, $mkz, $sig ) { Portale\Releases::add( $gdb, 'pro', $mkz( '1.0.2' ), '', 'test', null, $sig, 'k-test' ); } ) );
	check( 'firma: chiave sconosciuta rifiutata', null !== throwsUser( function () use ( $gdb, $mkz, $sig ) { Portale\Releases::add( $gdb, 'pro', $mkz( '1.0.3' ), '', 'test', null, $sig, 'k-altra' ); } ) );
	$z4 = $mkz( '1.0.4' );
	Portale\Releases::add( $gdb, 'pro', $z4, '', 'test' );
	$sig4 = $b64u( sodium_crypto_sign_detached( Portale\Releases::signedMessage( 'pro', '1.0.4', hash_file( 'sha256', $z4 ) ), $sk ) );
	Http::$transport = function () { return array( 200, json_encode( array( 'valid' => true, 'reason_code' => 'ACTIVE', 'plan_name' => 'Pro Full', 'license_id' => 'LG' ) ) ); };
	$gpx = new Portale\LicenseProxy( $gdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
	$req = '{"product":"pro","version":"1.0.0","license_key":"chiave-firma-prova","domain":"esempio.it"}';
	$g1  = json_decode( Portale\PluginUpdates::check( $gdb, $gpx, $req, '8.8.1.1', 1800000000 )[1], true );
	check( 'aggiornamento: l\'ultima versione NON firmata (1.0.4) non si offre, si offre la firmata (1.0.1) con impronta e firma', '1.0.1' === $g1['version'] && $sig === $g1['signature'] && 'k-test' === $g1['kid'] && 64 === strlen( (string) $g1['sha256'] ) );
	Portale\Releases::sign( $gdb, 'pro', '1.0.4', $sig4, 'k-test', 'test' );
	$g2 = json_decode( Portale\PluginUpdates::check( $gdb, $gpx, str_replace( 'chiave-firma-prova', 'chiave-firma-prova2', $req ), '8.8.1.2', 1800000000 )[1], true );
	check( 'firma: dopo la firma la 1.0.4 diventa l\'aggiornamento offerto', '1.0.4' === $g2['version'] && $sig4 === $g2['signature'] );
	check( 'firma: firma aggiunta a una versione sbagliata rifiutata', null !== throwsUser( function () use ( $gdb, $sig ) { Portale\Releases::sign( $gdb, 'pro', '1.0.4', $sig, 'k-test', 'test' ); } ) );
	Config::set( 'package_keys', Portale\Releases::SIGNING_KEYS );
}

// --- Public Suffix List completa -----------------------------------------------------------------
$pslCases = array(
	'esempio.it' => 'esempio.it', 'staging.esempio.it' => 'esempio.it', 'a.b.c.esempio.it' => 'esempio.it',
	'www.esempio.co.uk' => 'esempio.co.uk', 'esempio.com.au' => 'esempio.com.au',
	'sito.altervista.org' => 'sito.altervista.org', 'staging.sito.altervista.org' => 'sito.altervista.org',
	'utente.github.io' => 'utente.github.io', 'x.blogspot.com' => 'x.blogspot.com',
	'foo.bar.ck' => 'foo.bar.ck', 'www.ck' => 'www.ck', 'altro.www.ck' => 'www.ck',
	'esempio.org.it' => 'org.it', 'esempio.roma.it' => 'esempio.roma.it', 'test.associazione.roma.it' => 'associazione.roma.it',
	'xn--bcher-kva.ch' => 'xn--bcher-kva.ch', 'esempio.xn--p1ai' => 'esempio.xn--p1ai',
	'co.uk' => '', 'altervista.org' => '', 'ck' => '',
);
$pslBad = array();
foreach ( $pslCases as $in => $want ) {
	if ( $want !== Domain::registrable( $in ) ) {
		$pslBad[] = $in . ' → ' . Domain::registrable( $in ) . ' (atteso ' . $want . ')';
	}
}
check( 'psl: ' . count( $pslCases ) . ' casi (suffissi ICANN e privati, wildcard, eccezioni, punycode)' . ( $pslBad ? ' — ' . implode( '; ', $pslBad ) : '' ), ! $pslBad );
check( 'psl: due clienti dello stesso ospite (altervista.org) non condividono il dominio registrabile', Domain::registrable( 'a.altervista.org' ) !== Domain::registrable( 'b.altervista.org' ) );

// --- Vault di Wega (copia di riserva cifrata) ---------------------------------------------------------
$vh = array();
$vb = '';
Http::$transport = function ( $m, $url, $h, $body ) use ( &$vh, &$vb ) {
	$vh = array( $m, $url, $h );
	$vb = (string) $body;
	return array( 201, json_encode( array( 'data' => array( 'id' => 'V1', 'name' => 'k.pem', 'size' => 12, 'sha256' => str_repeat( 'a', 64 ), 'stored_at' => '2026-10-20T10:00:00Z' ) ) ) );
};
$vr = ( new WegaClient( 'https://license.test/api/v1', 'tok' ) )->vaultStore( 'k.pem', "-----BEGIN ENCRYPTED PRIVATE KEY-----\nAAAA\n" );
check( 'vault: richiesta multipart con file e nome, e risposta con l\'impronta', 'POST' === $vh[0] && 'https://license.test/api/v1/vault' === $vh[1] && false !== strpos( implode( '|', $vh[2] ), 'multipart/form-data; boundary=' ) && false !== strpos( $vb, 'name="file"; filename="k.pem"' ) && false !== strpos( $vb, 'ENCRYPTED PRIVATE KEY' ) && 'V1' === $vr['id'] && str_repeat( 'a', 64 ) === $vr['sha256'] );
Http::$transport = function () { return array( 422, json_encode( array( 'error' => array( 'code' => 'VAULT_QUOTA_EXCEEDED', 'message' => 'pieno' ) ) ) ); };
try {
	( new WegaClient( 'https://license.test/api/v1', 'tok' ) )->vaultStore( 'k.pem', 'x' );
	check( 'vault: errore di quota segnalato', false );
} catch ( WegaException $e ) {
	check( 'vault: errore di quota segnalato', 'VAULT_QUOTA_EXCEEDED' === $e->errorCode && 422 === $e->httpStatus );
}

// --- Cambio di piano (upgrade con differenza, downgrade al rinnovo) ------------------------------------------------------
check( 'cambio piano: differenza proporzionale ai giorni residui', 200 === Portale\PlanChange::diffCents( 800, 1200, gmdate( 'Y-m-d H:i:s', 1000000 + 15 * 86400 ), 30, 1000000 ) );
check( 'cambio piano: mai sotto il minimo del provider', 50 === Portale\PlanChange::diffCents( 800, 1200, gmdate( 'Y-m-d H:i:s', 1000000 + 3600 ), 30, 1000000 ) );
check( 'cambio piano: a scadenza lontana non supera la differenza piena', 400 === Portale\PlanChange::diffCents( 800, 1200, gmdate( 'Y-m-d H:i:s', 1000000 + 90 * 86400 ), 30, 1000000 ) );

Config::load( array(
	'base_url' => 'https://portale.test', 'app_key' => base64_encode( str_repeat( 'k', 32 ) ),
	'stripe' => array( 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test' ),
	'paypal' => array( 'live' => false, 'client_id' => 'id', 'secret' => 's', 'webhook_id' => 'WH1' ),
	'plans' => array(
		'free' => array( 'label' => 'Free', 'plan_name' => 'Free', 'prices' => array() ),
		'lite' => array( 'label' => 'Pro Contabile', 'plan_name' => 'Pro Lite', 'prices' => array(
			'month' => array( 'cents' => 800, 'stripe_price' => 'price_lite', 'paypal_plan' => 'P-L' ),
		) ),
		'full' => array( 'label' => 'Pro Fiscale', 'plan_name' => 'Pro Full', 'prices' => array(
			'month' => array( 'cents' => 1200, 'stripe_price' => 'price_full', 'paypal_plan' => 'P-F' ),
		) ),
	),
) );
$pdb = new Db( new PDO( 'sqlite::memory:' ) );
$pdb->migrate();
Portale\Settings::load( $pdb );
$pcalls = array();
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$pcalls ) {
	$pcalls[] = array( $method, $url, $body );
	if ( false !== strpos( $url, '/licenses/renew' ) ) {
		return array( 200, json_encode( array( 'data' => array( 'expires_at' => '2030-02-01T00:00:00Z' ) ) ) );
	}
	if ( false !== strpos( $url, '/licenses/' ) ) {
		return array( 200, json_encode( array( 'data' => array( 'license_id' => 'lic_9' ) ) ) );
	}
	if ( 'GET' === $method && false !== strpos( $url, '/subscriptions/sub_9' ) ) {
		return array( 200, '{"items":{"data":[{"id":"si_1"}]}}' );
	}
	if ( false !== strpos( $url, '/subscriptions/sub_9' ) ) {
		return array( 200, '{}' );
	}
	if ( false !== strpos( $url, '/checkout/sessions' ) ) {
		return array( 200, '{"url":"https://stripe.test/pay"}' );
	}
	return array( 404, '{}' );
};
$pbil  = new Billing( $pdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$pcust = $pbil->customer( 'cliente@esempio.it' );
$pexp  = gmdate( 'Y-m-d H:i:s', time() + 15 * 86400 );
$pdb->run(
	"INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, provider_ref, status, domain, mode, license_id, license_key_enc, expires_at, created_at, updated_at) VALUES ('s1',?, 'lite','month','stripe','sub_9','active','piani.it','recurring','lic_9',?,?,?,?)",
	array( $pcust['id'], Portale\Secrets::seal( 'KEY-9' ), $pexp, Db::now(), Db::now() )
);
$ps  = $pbil->subscription( 's1' );
$pc  = $pbil->planChange();
$opt = $pc->options( $ps );
check( 'cambio piano: dal Lite si offre il Full come upgrade con la differenza', 1 === count( $opt ) && 'full' === $opt[0]['plan'] && $opt[0]['up'] && $opt[0]['pay'] >= 190 && $opt[0]['pay'] <= 400 );
check( 'cambio piano: cliente estraneo rifiutato', null !== throwsUser( function () use ( $pc ) {
	$pc->startUpgrade( 's1', 'altro', 'full' );
} ) );
check( 'cambio piano: verso il piano che ha già rifiutato', null !== throwsUser( function () use ( $pc, $pcust ) {
	$pc->startUpgrade( 's1', $pcust['id'], 'lite' );
} ) );
$url = $pc->startUpgrade( 's1', $pcust['id'], 'full' );
$chg = $pdb->one( "SELECT * FROM subscriptions WHERE renews = 's1'" );
check( 'upgrade: pagamento singolo creato e riga collegata', 'https://stripe.test/pay' === $url && 'full' === $chg['change_to'] && 'pending' === $chg['status'] );
$pbil->completePayment( $chg['id'] );
$pbil->completePayment( $chg['id'] );
$pa = $pbil->subscription( 's1' );
$patches = array_filter( $pcalls, function ( $c ) { return 'PATCH' === $c[0]; } );
$swaps   = array_filter( $pcalls, function ( $c ) { return 'POST' === $c[0] && false !== strpos( $c[1], '/subscriptions/sub_9' ) && false !== strpos( (string) $c[2], 'price_full' ) && false !== strpos( (string) $c[2], 'proration_behavior=none' ); } );
check( 'upgrade: piano cambiato, scadenza e chiave invariate', 'full' === $pa['plan_code'] && $pexp === $pa['expires_at'] && 'KEY-9' === Portale\Secrets::open( $pa['license_key_enc'] ) );
check( 'upgrade: un solo cambio sul server licenze e un solo cambio di prezzo su Stripe, anche se il pagamento arriva due volte', 1 === count( $patches ) && 1 === count( $swaps ) && false !== strpos( (string) array_values( $patches )[0][2], 'Pro Full' ) );
check( 'upgrade: nessun rinnovo registrato', 0 === count( array_filter( $pcalls, function ( $c ) { return false !== strpos( $c[1], '/licenses/renew' ); } ) ) );

$pc->scheduleDowngrade( 's1', $pcust['id'], 'lite' );
$pd = $pbil->subscription( 's1' );
check( 'downgrade: programmato, la licenza resta Full fino al rinnovo', 'lite' === $pd['next_plan'] && 'full' === $pd['plan_code'] );
check( 'downgrade: un secondo cambio mentre ce n\'è uno programmato è rifiutato', null !== throwsUser( function () use ( $pc, $pcust ) {
	$pc->scheduleDowngrade( 's1', $pcust['id'], 'lite' );
} ) );
$pc->cancelScheduled( 's1', $pcust['id'] );
check( 'downgrade: annullabile', null === $pbil->subscription( 's1' )['next_plan'] );
$pc->scheduleDowngrade( 's1', $pcust['id'], 'lite' );
$pbil->renew( 's1' );
$pr = $pbil->subscription( 's1' );
check( 'downgrade: al rinnovo la licenza passa al piano inferiore e il programma si azzera', 'lite' === $pr['plan_code'] && null === $pr['next_plan'] );
$lastPatch = array_values( array_filter( $pcalls, function ( $c ) { return 'PATCH' === $c[0]; } ) );
check( 'downgrade: il server licenze riceve il tipo nuovo', false !== strpos( (string) end( $lastPatch )[2], 'Pro Lite' ) );

$pdb->run( "UPDATE subscriptions SET provider = 'paypal', mode = 'recurring' WHERE id = 's1'" );
check( 'cambio piano: PayPal ricorrente non automatico, spiegato', null !== $pc->reason( $pbil->subscription( 's1' ) ) && array() === $pc->options( $pbil->subscription( 's1' ) ) );
$pdb->run( "UPDATE subscriptions SET provider = 'stripe', status = 'active', cancel_at_period_end = 1 WHERE id = 's1'" );
check( 'cambio piano: rinnovo disdetto, non si cambia', null !== $pc->reason( $pbil->subscription( 's1' ) ) );

// --- Novità dal CHANGELOG.md del pacchetto -------------------------------------------------------------------------------
$cl = "# Novità\n\nTesto di introduzione.\n\n## 1.2.20\n- Widget nella Bacheca.\nriga libera ignorata\n\n## 1.2.19\n- Anni solari.\n- Ospiti.\n\n## 1.2.18\n- Listini.\n";
check( 'novità: solo le versioni più recenti di quella installata', "## 1.2.20\n- Widget nella Bacheca.\n## 1.2.19\n- Anni solari.\n- Ospiti." === Releases::filterChangelog( $cl, '1.2.18' ) );
check( 'novità: senza versione installata solo l\'ultima, senza righe estranee', "## 1.2.20\n- Widget nella Bacheca." === Releases::filterChangelog( $cl, '' ) );
check( 'novità: nulla se è già aggiornato', '' === Releases::filterChangelog( $cl, '1.2.20' ) );
check( 'novità: tetto di 20000 caratteri', strlen( Releases::filterChangelog( "## 9.0\n" . str_repeat( "- voce lunga lunga lunga\n", 2000 ), '1.0' ) ) <= 20000 );

// --- Promozione in vetrina (listino barrato) -------------------------------------------------------------------------------
Portale\Settings::savePromo( $pdb, array( 'promo_label' => 'Black Friday', 'promo_start' => '2026-11-27', 'promo_end' => '2026-12-01' ) );
Portale\Settings::load( $pdb );
Portale\Settings::savePlan( $pdb, 'lite', array( 'label' => 'Pro Contabile', 'plan_name' => 'Pro Lite', 'visible' => '1', 'price_month' => '8', 'list_month' => '10', 'stripe_month' => 'price_liteAB12', 'paypal_month' => 'P-LITE01' ) );
Portale\Settings::load( $pdb );
$rome = function ( string $when ) { return ( new DateTime( $when, new DateTimeZone( 'Europe/Rome' ) ) )->getTimestamp(); };
check( 'promo: attiva tra le date (fine compresa)', 'Black Friday' === Portale\Settings::promoNow( $rome( '2026-11-30 12:00' ) ) && 'Black Friday' === Portale\Settings::promoNow( $rome( '2026-12-01 23:30' ) ) );
check( 'promo: non attiva prima dell\'inizio e dopo la fine', null === Portale\Settings::promoNow( $rome( '2026-11-26 23:59' ) ) && null === Portale\Settings::promoNow( $rome( '2026-12-02 00:01' ) ) );
check( 'promo: listino barrato solo in promo e solo se più alto', 1000 === Portale\Settings::listCents( 'lite', 'month', $rome( '2026-11-28 10:00' ) ) && null === Portale\Settings::listCents( 'lite', 'month', $rome( '2026-12-05 10:00' ) ) && null === Portale\Settings::listCents( 'lite', 'year', $rome( '2026-11-28 10:00' ) ) );
check( 'promo: il prezzo incassato resta quello in vigore', 800 === Portale\Config::price( 'lite', 'month' )['cents'] );
check( 'promo: serve la data di fine', null !== throwsUser( function () use ( $pdb ) {
	Portale\Settings::savePromo( $pdb, array( 'promo_label' => 'Prova' ) );
} ) );
check( 'promo: inizio dopo la fine rifiutato', null !== throwsUser( function () use ( $pdb ) {
	Portale\Settings::savePromo( $pdb, array( 'promo_label' => 'Prova', 'promo_start' => '2026-12-05', 'promo_end' => '2026-12-01' ) );
} ) );
Portale\Settings::savePromo( $pdb, array( 'promo_label' => '' ) );
Portale\Settings::load( $pdb );
check( 'promo: nome vuoto la spegne', null === Portale\Settings::promoNow( $rome( '2026-11-28 10:00' ) ) );

// --- Rinnovo automatico senza prezzi creati a mano su Stripe e PayPal ------------------------------------------------------
Portale\Settings::savePlan( $pdb, 'full', array( 'label' => 'Pro Fiscale', 'plan_name' => 'Pro Full', 'visible' => '1', 'price_month' => '12', 'price_year' => '120' ) );
Portale\Settings::load( $pdb );
$acalls = array();
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$acalls ) {
	$acalls[] = array( $method, $url, $body );
	if ( false !== strpos( $url, '/oauth2/token' ) ) {
		return array( 200, '{"access_token":"tok"}' );
	}
	if ( false !== strpos( $url, '/checkout/sessions' ) ) {
		return array( 200, '{"url":"https://stripe.test/pay"}' );
	}
	if ( false !== strpos( $url, '/catalogs/products' ) ) {
		return array( 201, '{"id":"PROD-1"}' );
	}
	if ( false !== strpos( $url, '/v1/billing/plans' ) ) {
		return array( 201, '{"id":"P-AUTO' . count( $GLOBALS['acalls'] ) . '"}' );
	}
	if ( false !== strpos( $url, '/v1/billing/subscriptions' ) ) {
		return array( 200, '{"links":[{"rel":"approve","href":"https://paypal.test/sub"}]}' );
	}
	return array( 404, '{}' );
};
$abil = new Billing( $pdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
check( 'automatico: il piano si può comprare con rinnovo automatico anche senza ID', in_array( array( 'stripe', 'recurring' ), $abil->methods( Portale\Config::price( 'full', 'year' ) ), true ) && in_array( array( 'paypal', 'recurring' ), $abil->methods( Portale\Config::price( 'full', 'year' ) ), true ) );
$abil->startPaid( 'auto@esempio.it', 'auto-uno.it', 'full', 'year', 'stripe', 'recurring' );
$co = null;
foreach ( $acalls as $c ) {
	if ( false !== strpos( $c[1], '/checkout/sessions' ) ) {
		parse_str( (string) $c[2], $co );
	}
}
check( 'automatico Stripe: prezzo ricorrente annuale creato al volo con l\'importo del piano', $co && 'subscription' === $co['mode'] && '12000' === (string) $co['line_items'][0]['price_data']['unit_amount'] && 'year' === $co['line_items'][0]['price_data']['recurring']['interval'] && empty( $co['line_items'][0]['price'] ) );
$abil->startPaid( 'auto2@esempio.it', 'auto-due.it', 'full', 'year', 'paypal', 'recurring' );
$abil->startPaid( 'auto3@esempio.it', 'auto-tre.it', 'full', 'year', 'paypal', 'recurring' );
$made = array_filter( $acalls, function ( $c ) { return false !== strpos( $c[1], '/v1/billing/plans' ); } );
$prod = array_filter( $acalls, function ( $c ) { return false !== strpos( $c[1], '/catalogs/products' ); } );
$subs = array_values( array_filter( $acalls, function ( $c ) { return false !== strpos( $c[1], '/v1/billing/subscriptions' ); } ) );
$pj   = json_decode( (string) array_values( $made )[0][2], true );
check( 'automatico PayPal: prodotto e piano creati una volta sola e riusati', 1 === count( $made ) && 1 === count( $prod ) && 2 === count( $subs ) && json_decode( (string) $subs[0][2], true )['plan_id'] === json_decode( (string) $subs[1][2], true )['plan_id'] );
check( 'automatico PayPal: piano annuale da 120,00 EUR', 'YEAR' === $pj['billing_cycles'][0]['frequency']['interval_unit'] && '120.00' === $pj['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] );

// --- Sicurezza: dati scelti da chi si iscrive non devono finire in uno script del pannello ---------------------------
check( 'email: indirizzo normale accettato', Billing::validEmail( 'mario.rossi+asem@esempio.it' ) );
check( 'email: parte locale tra virgolette rifiutata', ! Billing::validEmail( "\"x');alert(1);//\"@esempio.it" ) && ! Billing::validEmail( '"a,b@c.it"@esempio.it' ) );
check( 'email: apice e accento grave senza virgolette restano validi ma innocui', Billing::validEmail( "o'brien@esempio.it" ) );
$attack = "x'+setTimeout`location=/evil.it`+'@esempio.it";
$conf   = js_confirm( 'Eliminare ' . $attack . ' con tutte le sue licenze?' );
check( 'conferma: il messaggio è una stringa JavaScript che non si può chiudere dall\'interno', 0 === strpos( $conf, 'return confirm(&quot;' ) && false === strpos( $conf, "'" ) && false === strpos( $conf, '<' ) );
check( 'conferma: il testo originale si ritrova nel valore decodificato', false !== strpos( html_entity_decode( $conf, ENT_QUOTES ), 'x' . chr( 92 ) . 'u0027+setTimeout`location=/evil.it`+' . chr( 92 ) . 'u0027@esempio.it' ) );
check( 'conferma: da testo con virgolette e tag esce solo JSON', false === strpos( js_confirm( '</script><img src=x onerror=alert(1)>"' ), '<' ) );

// --- Indirizzo IP dietro il proxy: contano le voci aggiunte dal proxy, non quelle scritte da chi chiama -------------
$srv = array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.7' );
check( 'IP: senza proxy fidato conta la connessione', '10.0.0.1' === Portale\ClientIp::from( $srv, false ) );
check( 'IP: con un proxy fidato conta l\'ultima voce, non la prima scelta dal chiamante', '203.0.113.7' === Portale\ClientIp::from( $srv, true ) );
check( 'IP: due proxy fidati, penultima voce', '1.2.3.4' === Portale\ClientIp::from( $srv, true, 2 ) );
check( 'IP: voce non valida o mancante, torna la connessione', '10.0.0.1' === Portale\ClientIp::from( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'non-un-ip' ), true ) && '10.0.0.1' === Portale\ClientIp::from( array( 'REMOTE_ADDR' => '10.0.0.1' ), true ) );
check( 'IP: più proxy dichiarati delle voci presenti, torna la connessione', '10.0.0.1' === Portale\ClientIp::from( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), true, 2 ) );

// --- Demo pubblica: dati della pagina /prova ---------------------------------------------------------------------------
$demoAcc = Portale\Demo::accounts();
check( 'demo: segreteria, socio e amministratore del plugin, nessun «admin» di WordPress', array( 'segreteria', 'socio', 'amministratore' ) === array_column( $demoAcc, 'user' ) && ! preg_match( '/admin/i', implode( ' ', array_column( $demoAcc, 'user' ) ) ) );
check( 'demo: ogni accesso ha una password di almeno 12 caratteri e un punto di partenza nella demo', ! array_filter( $demoAcc, function ( $a ) { return strlen( $a['password'] ) < 12 || 0 !== strpos( $a['start'], '/' ); } ) );
$demoRoles = Portale\Demo::roles();
check( 'demo: la tabella dei ruoli ha tante celle quante colonne', ! array_filter( $demoRoles['rows'], function ( $cells ) use ( $demoRoles ) { return count( $cells ) !== count( $demoRoles['columns'] ); } ) );
$demoLinks = array();
foreach ( Portale\Demo::links() as $rows ) {
	foreach ( $rows as $r ) {
		$demoLinks[] = $r[1];
	}
}
check( 'demo: i collegamenti sono percorsi della demo e non portano alle pagine di WordPress riservate agli amministratori del sito', $demoLinks && ! array_filter( $demoLinks, function ( $p ) { return 0 !== strpos( $p, '/' ) || preg_match( '#(users|plugins|plugin-install|themes|options-general|tools|update-core).php#', $p ); } ) );
check( 'demo: l\'indirizzo non finisce con la barra', '/' !== substr( Portale\Demo::url(), -1 ) );

// --- Livello firmato dal portale (portal_attestation) -------------------------------------------------------------------
$pseed = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
$adb   = new Db( new PDO( 'sqlite::memory:' ) );
$adb->migrate();
$mkSub = function ( string $id, string $plan, string $status, string $license ) use ( $adb ) {
	$adb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, license_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( $id, 'c1', $plan, 'year', 'stripe', $status, 'esempio.it', $license, Db::now(), Db::now() ) );
};
$mkSub( 's1', 'full', 'active', 'LIC-FULL' );
$mkSub( 's2', 'lite', 'active', 'LIC-LITE' );
$mkSub( 's3', 'free', 'active', 'LIC-FREE' );
$mkSub( 's4', 'full', 'canceled', 'LIC-OFF' );
$mkSub( 's5', 'strano', 'active', 'LIC-ODD' );
$wega = function ( string $license, bool $valid = true ) {
	return array( 'valid' => $valid, 'license_id' => $license, 'product_code' => 'prodotto-wega-x1', 'identity' => 'esempio.it', 'domain' => 'esempio.it', 'plan_name' => 'Nome libero' );
};
$verifyJwt = function ( string $jwt ) use ( $pseed ) {
	$p = explode( '.', $jwt );
	if ( 3 !== count( $p ) ) {
		return null;
	}
	$d   = function ( $s ) { return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true ); };
	$pub = $d( Portale\PortalAttestation::publicKey( $pseed ) );
	return sodium_crypto_sign_verify_detached( (string) $d( $p[2] ), $p[0] . '.' . $p[1], (string) $pub ) ? array( json_decode( (string) $d( $p[0] ), true ), json_decode( (string) $d( $p[1] ), true ) ) : null;
};
check( 'prova del portale: senza chiave non si firma niente', ! Portale\PortalAttestation::enabled() && null === Portale\PortalAttestation::forResponse( $adb, $wega( 'LIC-FULL' ) ) );
Config::set( 'portal_sign_key', base64_encode( $pseed ) );
check( 'prova del portale: con la chiave è attiva e dà kid e chiave pubblica da fissare nel plugin', Portale\PortalAttestation::enabled() && 0 === strpos( Portale\PortalAttestation::kid(), 'portale-' ) && 43 === strlen( Portale\PortalAttestation::publicKey() ) );
$jwt = Portale\PortalAttestation::forResponse( $adb, $wega( 'LIC-FULL' ), 1700000000 );
$ver = $jwt ? $verifyJwt( $jwt ) : null;
check( 'prova del portale: firma Ed25519 valida con la chiave pubblica e intestazione EdDSA con kid', $ver && 'EdDSA' === $ver[0]['alg'] && Portale\PortalAttestation::kid() === $ver[0]['kid'] );
check( 'prova del portale: Pro Fiscale → livello «fiscal», aud e bid come Wega, 14 giorni', $ver && 'fiscal' === $ver[1]['plan'] && 'prodotto-wega-x1' === $ver[1]['aud'] && 'esempio.it' === $ver[1]['bid'] && 'LIC-FULL' === $ver[1]['sub'] && 'https://portale.test' === $ver[1]['iss'] && 1209600 === $ver[1]['exp'] - $ver[1]['iat'] );
check( 'prova del portale: il livello viene dalla tabella dei piani, non dal nome su Wega', $ver && false === stripos( (string) $wega( 'LIC-FULL' )['plan_name'], 'fiscal' ) && 'fiscal' === $ver[1]['plan'] );
$lite = $verifyJwt( (string) Portale\PortalAttestation::forResponse( $adb, $wega( 'LIC-LITE' ) ) );
$free = $verifyJwt( (string) Portale\PortalAttestation::forResponse( $adb, $wega( 'LIC-FREE' ) ) );
check( 'prova del portale: Pro Contabile → «accounting», gratuito → «free»', $lite && 'accounting' === $lite[1]['plan'] && $free && 'free' === $free[1]['plan'] );
$lvl = function ( string $license, bool $valid = true ) use ( $adb, $wega, $verifyJwt ) {
	$j = $verifyJwt( (string) Portale\PortalAttestation::forResponse( $adb, $wega( $license, $valid ) ) );
	return $j ? $j[1]['plan'] : null;
};
check( 'prova del portale: licenza non valida per Wega → nessuna prova', null === Portale\PortalAttestation::forResponse( $adb, $wega( 'LIC-FULL', false ) ) );
check( 'prova del portale: licenza valida ma sconosciuta, annullata o di piano ignoto → prova con livello «free» (mai niente)', 'free' === $lvl( 'LIC-NON-ESISTE' ) && 'free' === $lvl( 'LIC-OFF' ) && 'free' === $lvl( 'LIC-ODD' ) );
check( 'prova del portale: levelOf rilegge il livello dalla prova e rifiuta il resto', 'fiscal' === Portale\PortalAttestation::levelOf( (string) $jwt ) && null === Portale\PortalAttestation::levelOf( 'a.b' ) && null === Portale\PortalAttestation::levelOf( '' ) );
$tamper = explode( '.', (string) $jwt );
$tamper[1] = rtrim( strtr( base64_encode( str_replace( 'fiscal', 'free', (string) base64_decode( strtr( $tamper[1], '-_', '+/' ) ) ) ), '+/', '-_' ), '=' );
check( 'prova del portale: se si cambia il livello la firma non torna più', null === $verifyJwt( implode( '.', $tamper ) ) );
Config::set( 'plan_levels', array( 'lite' => 'lite' ) );
check( 'prova del portale: la corrispondenza piano → livello si cambia da config.php', 'lite' === Portale\PortalAttestation::level( 'lite' ) && 'fiscal' === Portale\PortalAttestation::level( 'full' ) && null === Portale\PortalAttestation::level( 'altro' ) );
Config::set( 'plan_levels', array() );
$transportBefore = Http::$transport;
Http::$transport = function ( $method, $url, $headers, $body ) use ( $wega ) {
	return array( 200, json_encode( $wega( 'LIC-FULL' ) + array( 'attestation' => 'JWT.WEGA.X' ) ) );
};
$aproxy = new Portale\LicenseProxy( $adb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$ar     = $aproxy->validate( '{"license_key":"chiave-valida-1","domain":"esempio.it"}', '9.9.9.9', 1700000000 );
$aj     = json_decode( $ar[1], true );
check( 'prova del portale: nella risposta del rimbalzo accanto all\'attestazione di Wega, che resta com\'è', 200 === $ar[0] && 'JWT.WEGA.X' === $aj['attestation'] && ! empty( $aj['portal_attestation'] ) && null !== $verifyJwt( $aj['portal_attestation'] ) );
Http::$transport = $transportBefore;
Config::set( 'portal_sign_key', '' );

echo $fails ? "\n$fails prove fallite\n" : "\nTutte le prove sono passate\n";
exit( $fails ? 1 : 0 );

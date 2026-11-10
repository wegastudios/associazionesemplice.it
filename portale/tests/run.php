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
	'duration_days' => array( 'month' => 31, 'year' => 366, 'free' => 30 ), 'renew_window_days' => 5,
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
check( 'dominio: numeri tipo 0x7f.1 rifiutati', '' === Domain::forLicense( '0x7f.1' ) && '' === Domain::forLicense( '10.1' ) && 'esempio.it' === Domain::forLicense( 'esempio.it' ) );
check( 'versione: a capo finale rifiutato', ! Portale\Releases::validVersion( "1.3.8\n" ) && Portale\Releases::validVersion( '1.3.8' ) );
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
check( 'free: licenza tipo 3 con dominio e riferimento idempotente', $create && 3 === $create['type'] && 'mia-associazione.it' === $create['domain'] && 'sub-' . $sub['id'] === $create['external_ref'] && 30 === $create['duration_days'] && 'Free' === $create['plan_name'] );
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
$msgLock = throwsUser( function () use ( $billing, $sub, $cust ) {
	$billing->changeDomain( $sub['id'], $cust['id'], 'ancora.it' );
} );
check( 'dominio: dopo un cambio il cliente è bloccato e la data di sblocco è spiegata', null !== $msgLock && false !== strpos( $msgLock, 'potrai farlo di nuovo dal' ) && 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
$state['bind_error'] = 'BINDING_CHANGE_LIMIT';
$msg = throwsUser( function () use ( $billing, $sub, $cust ) {
	$billing->changeDomain( $sub['id'], $cust['id'], 'ancora.it', true );
} );
check( 'dominio: limite di sicurezza del server (3 cambi) spiegato anche al personale', null !== $msg && false !== strpos( $msg, '3 volte' ) && 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
$state['bind_error'] = null;
$billing->changeDomain( $sub['id'], $cust['id'], 'staff.it', true );
check( 'dominio: il personale cambia anche con il blocco attivo', 'staff.it' === $billing->subscription( $sub['id'] )['domain'] );
$billing->changeDomain( $sub['id'], $cust['id'], 'nuovo.it', true );
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
Portale\Settings::saveGeneral( $db, array( 'trial_days' => 30, 'trial_plan' => 'full', 'trial_enabled' => '1', 'duration_month' => 31, 'reminder_days' => 14, 'duration_year' => 366, 'duration_free' => 30, 'renew_window' => 5 ) );
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
// Primo pagamento: se un altro processo sta già emettendo la licenza (segnaposto recente) non se ne crea una seconda.
$billing->startPaid( 'dora@esempio.it', 'Claim.it', 'full', 'year', 'stripe', 'once' );
$cl = $db->one( "SELECT * FROM subscriptions WHERE domain = 'claim.it'" );
$db->run( 'UPDATE subscriptions SET license_id = ?, updated_at = ? WHERE id = ?', array( Portale\Billing::CLAIM, Portale\Db::now(), $cl['id'] ) );
$n = count( $calls );
$billing->completePayment( $cl['id'], 'pi_claim' );
$created = 0;
foreach ( array_slice( $calls, $n ) as $c ) {
	$created += false !== strpos( $c[1], '/licenses' ) ? 1 : 0;
}
check( 'primo pagamento: emissione già in corso, nessuna seconda licenza', 0 === $created && Portale\Billing::CLAIM === $billing->subscription( $cl['id'] )['license_id'] );
check( 'primo pagamento: dal webhook l\'emissione in corso dà errore da riprovare', null !== ( function () use ( $billing, $cl ) { try { $billing->completePayment( $cl['id'], 'pi_claim', null, true ); } catch ( \RuntimeException $e ) { return $e; } return null; } )() );
$db->run( 'UPDATE subscriptions SET updated_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', time() - 3600 ), $cl['id'] ) );
$billing->completePayment( $cl['id'], 'pi_claim' );
$cl2 = $billing->subscription( $cl['id'] );
check( 'primo pagamento: segnaposto abbandonato, la licenza viene emessa', 'active' === $cl2['status'] && Portale\Billing::CLAIM !== $cl2['license_id'] && ! empty( $cl2['license_key_enc'] ) );
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
check( 'admin: riconosciuto senza badare alle maiuscole', Portale\Plugin\Admin::isAdminEmail( 'io@esempio.it' ) && ! Portale\Plugin\Admin::isAdminEmail( 'altro@esempio.it' ) && ! Portale\Plugin\Admin::isAdminEmail( '' ) );
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

// Revisione di sicurezza: tentativi riservati prima del controllo, impronta finta dello stesso costo
$dm = new ReflectionMethod( Portale\Auth::class, 'dummy_hash' );
$dm->setAccessible( true );
check( 'password: l\'impronta finta ha lo stesso costo di quelle vere (i tempi non rivelano chi è cliente)', password_get_info( (string) $dm->invoke( null ) )['options']['cost'] === password_get_info( password_hash( 'x', PASSWORD_DEFAULT ) )['options']['cost'] && ! password_verify( 'qualunque', (string) $dm->invoke( null ) ) );
check( 'password: una password enorme non viene nemmeno controllata', null !== throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', str_repeat( 'a', 2000 ), '9.9.9.20' ); } ) );
$rdb = new Db( new PDO( 'sqlite::memory:' ) );
$rdb->migrate();
$took = 0;
for ( $i = 0; $i < 6; $i++ ) {
	$took += Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) ? 1 : 0;
}
check( 'limite: al massimo tre posti in un\'ora, anche con tante richieste', 3 === $took );
Portale\RateLimit::give_back( $rdb, 'prova', '2026110100' );
check( 'limite: un posto restituito si può riusare, un solo posto', Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) && ! Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) );
$mdb = new Db( new PDO( 'sqlite::memory:' ) );
$mdb->migrate();
$mdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'c9', 'ok@esempio.it', Db::now() ) );
$mauth = new Portale\Auth( $mdb );
$mauth->setPassword( 'c9', 'una password lunga abbastanza', 'ok@esempio.it' );
for ( $i = 0; $i < 3; $i++ ) {
	$mauth->loginWithPassword( 'ok@esempio.it', 'una password lunga abbastanza', '8.8.8.8' );
}
check( 'password: gli accessi riusciti non consumano il limite dell\'indirizzo', 'c9' === $mauth->loginWithPassword( 'ok@esempio.it', 'una password lunga abbastanza', '8.8.8.8' )['id'] && 0 === (int) ( $mdb->one( 'SELECT n FROM rate_limits WHERE bucket = ?', array( 'login:i:' . hash( 'sha256', '8.8.8.8' ) ) )['n'] ?? 0 ) );

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
check( 'staff: solo supporto non è amministratore', 'support' === Portale\Staff::role( $tdb, 'aiuto@esempio.it' ) && ! Portale\Plugin\Admin::isAdminEmail( 'aiuto@esempio.it', $tdb ) );
check( 'staff: crea anche il cliente per poter entrare', 1 === (int) $tdb->one( 'SELECT COUNT(*) AS n FROM customers WHERE email = ?', array( 'aiuto@esempio.it' ) )['n'] );
Portale\Staff::set( $tdb, $tbil, 'aiuto@esempio.it', 'admin' );
check( 'staff: cambio ruolo a admin', Portale\Plugin\Admin::isAdminEmail( 'aiuto@esempio.it', $tdb ) && 2 === count( Portale\Staff::all( $tdb ) ) );
check( 'staff: ruolo non valido rifiutato', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'x@esempio.it', 'root' ); } ) );
check( 'staff: admin di config.php non modificabile', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'capo@esempio.it', 'support' ); } ) );
Portale\Staff::remove( $tdb, 'aiuto@esempio.it' );
check( 'staff: rimosso', null === Portale\Staff::role( $tdb, 'aiuto@esempio.it' ) );
// Permessi separati per area: plugin e SaaS.
check( 'staff: admin di config.php è anche amministratore SaaS', 'admin' === Portale\Staff::saas( $tdb, 'capo@esempio.it' ) );
check( 'staff: sconosciuto senza accesso SaaS', '' === Portale\Staff::saas( $tdb, 'altro@esempio.it' ) );
Portale\Staff::set( $tdb, $tbil, 'solo.saas@esempio.it', 'none', 'support' );
check( 'staff: solo SaaS (nessun accesso al plugin)', null === Portale\Staff::role( $tdb, 'solo.saas@esempio.it' ) && 'support' === Portale\Staff::saas( $tdb, 'solo.saas@esempio.it' ) );
Portale\Staff::set( $tdb, $tbil, 'solo.plugin@esempio.it', 'support' );
check( 'staff: solo plugin (nessun accesso al SaaS)', 'support' === Portale\Staff::role( $tdb, 'solo.plugin@esempio.it' ) && '' === Portale\Staff::saas( $tdb, 'solo.plugin@esempio.it' ) );
Portale\Staff::set( $tdb, $tbil, 'solo.plugin@esempio.it', 'support', 'admin' );
Portale\Staff::set( $tdb, $tbil, 'solo.plugin@esempio.it', 'admin' );
check( 'staff: cambiare il plugin non toglie il livello SaaS', 'admin' === Portale\Staff::role( $tdb, 'solo.plugin@esempio.it' ) && 'admin' === Portale\Staff::saas( $tdb, 'solo.plugin@esempio.it' ) );
check( 'staff: serve almeno un accesso', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'nessuno@esempio.it', 'none', '' ); } ) );
check( 'staff: livello SaaS non valido rifiutato', null !== throwsUser( function () use ( $tdb, $tbil ) { Portale\Staff::set( $tdb, $tbil, 'x@esempio.it', 'support', 'root' ); } ) );
foreach ( array( 'solo.saas@esempio.it', 'solo.plugin@esempio.it' ) as $rm ) {
	Portale\Staff::remove( $tdb, $rm );
}
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
$proPlans = Portale\SupportSso::plansFor( $cdb, 'pro@esempio.it' );
usort( $proPlans, function ( $a, $b ) { return strcmp( $a['plan'], $b['plan'] ); } );
check( 'sso v2: piani del cliente (prodotto, slug, livello dal catalogo, stato)', array( array( 'product' => 'plugin', 'plan' => 'free', 'support_level' => 'free', 'status' => 'active' ), array( 'product' => 'plugin', 'plan' => 'profiscale', 'support_level' => 'paid', 'status' => 'active' ) ) === $proPlans );
check( 'sso v2: licenza scaduta → cancelled; sconosciuto → nessun piano', 'cancelled' === Portale\SupportSso::plansFor( $cdb, 'scaduto@esempio.it' )[0]['status'] && array() === Portale\SupportSso::plansFor( $cdb, 'nessuno@esempio.it' ) && array() === Portale\SupportSso::plansFor( null, 'pro@esempio.it' ) );
Config::set( 'support.sso_secret', str_repeat( 's', 40 ) );
parse_str( (string) parse_url( Portale\SupportSso::url( $cdb, 'pro@esempio.it' ), PHP_URL_QUERY ), $q2 );
$pl2 = json_decode( (string) base64_decode( strtr( explode( '.', (string) $q2['t'] )[0], '-_', '+/' ) ), true );
check( 'sso v2: il token porta v:2 e plans; senza piani resta v:1', 2 === $pl2['v'] && 2 === count( $pl2['plans'] ) );
parse_str( (string) parse_url( Portale\SupportSso::url( $cdb, 'nessuno@esempio.it' ), PHP_URL_QUERY ), $q3 );
check( 'sso v2: cliente senza licenze → token v:1 senza plans', 1 === json_decode( (string) base64_decode( strtr( explode( '.', (string) $q3['t'] )[0], '-_', '+/' ) ), true )['v'] );
Config::set( 'support.sso_secret', 'corto' );
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
check( 'demo: segreteria, socio e amministratore del plugin, nessun «admin» di WordPress', array( 'segreteria', 'socio@demo.invalid', 'amministratore' ) === array_column( $demoAcc, 'user' ) && ! preg_match( '/admin/i', implode( ' ', array_column( $demoAcc, 'user' ) ) ) );
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

// Blocco del dominio dopo un cambio: libero la prima volta, poi bloccato per domain_lock_days; 0 lo toglie.
check( 'blocco dominio: mai cambiato → nessun blocco', null === Portale\Billing::domainUnlockAt( array( 'domain_changed_at' => null ) ) );
$lockSub = array( 'domain_changed_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * 86400 ) );
check( 'blocco dominio: cambiato da 10 giorni → bloccato fino a 180 giorni dal cambio', null !== Portale\Billing::domainUnlockAt( $lockSub ) && abs( strtotime( Portale\Billing::domainUnlockAt( $lockSub ) . ' UTC' ) - ( time() + 170 * 86400 ) ) < 5 );
check( 'blocco dominio: cambiato da 200 giorni → di nuovo libero', null === Portale\Billing::domainUnlockAt( array( 'domain_changed_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * 86400 ) ) ) );
Config::set( 'domain_lock_days', 0 );
check( 'blocco dominio: 0 giorni = nessun blocco', null === Portale\Billing::domainUnlockAt( $lockSub ) );
Config::set( 'domain_lock_days', 180 );
check( 'blocco dominio: il valore predefinito è 180 giorni e si può impostare da pannello (clamp 0-730)', 180 === Portale\Settings::limit( 'domain_lock_days' ) && 730 === Portale\Settings::clamp( 'domain_lock_days', 9999 ) && 0 === Portale\Settings::clamp( 'domain_lock_days', -5 ) );

$pp = Portale\Site::publicPlans()['piani'];
check( 'elenco pubblico dei piani: slug fissi nell\'ordine free, procontabile, profiscale', array( 'free', 'procontabile', 'profiscale' ) === array_column( $pp, 'slug' ) );
Config::set( 'plans.lite.label', 'Nome nuovo' );
check( 'elenco pubblico dei piani: il nome segue il pannello e gli slug restano', 'Nome nuovo' === Portale\Site::publicPlans()['piani'][1]['nome'] && 'procontabile' === Portale\Site::publicPlans()['piani'][1]['slug'] );
check( 'elenco pubblico dei piani: nessun prezzo né altri dati', array( 'slug', 'nome' ) === array_keys( $pp[0] ) );


// Amministrazione della piattaforma SaaS: richieste firmate (contratto §2.3), su trasporto simulato.
$prevTransport = Http::$transport;
Config::set( 'saas_sign_key', '' );
Config::set( 'saas_sign_key_file', '/nonesiste/saas.key' );
check( 'saas: senza chiave di firma nessuna richiesta', ! Portale\SaasClient::enabled() && null !== throwsUser( function () { Portale\SaasClient::orgs( 'staff@esempio.it' ); } ) );
Config::set( 'saas_sign_key', base64_encode( str_repeat( 's', 32 ) ) );
$saasSeen = array();
Http::$transport = function ( $m, $u, $h, $b ) use ( &$saasSeen ) {
	$saasSeen = array( $m, $u, json_decode( (string) $b, true ) );
	return array( 200, '{"page":1,"per_page":50,"total":0,"orgs":[]}' );
};
$r = Portale\SaasClient::orgs( 'staff@esempio.it', array( 'q' => ' alfa ', 'status' => 'active', 'page' => 0, 'per_page' => 999 ) );
$parts = explode( '.', (string) $saasSeen[2]['token'] );
$cl    = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
$hd    = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
check( 'saas: elenco con POST verso l\'indirizzo del contratto', 'POST' === $saasSeen[0] && 'https://app.associazionesemplice.it/api/portale/v1/admin/orgs' === $saasSeen[1] && 0 === $r['total'] );
check( 'saas: token EdDSA con claim del contratto (aud, typ, scope read, act, vita 60 s, jti)', 'EdDSA' === $hd['alg'] && 'associazionesemplice-saas' === $cl['aud'] && 'admin_read' === $cl['typ'] && 'read' === $cl['scope'] && 'staff@esempio.it' === $cl['act'] && 60 === $cl['exp'] - $cl['iat'] && 32 === strlen( $cl['jti'] ) );
check( 'saas: filtri ripuliti (q senza spazi, pagina almeno 1, massimo 100 per pagina)', 'alfa' === $cl['q'] && 'active' === $cl['status'] && 1 === $cl['page'] && 100 === $cl['per_page'] );
$sigOk = sodium_crypto_sign_verify_detached( (string) base64_decode( strtr( $parts[2], '-_', '+/' ) ), $parts[0] . '.' . $parts[1], sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( 's', 32 ) ) ) );
check( 'saas: la firma si verifica con la chiave pubblica del portale verso il SaaS', $sigOk && Portale\SaasClient::publicKey() === rtrim( strtr( base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( 's', 32 ) ) ) ), '+/', '-_' ), '=' ) );
Portale\SaasClient::setUserDisabled( 'staff@esempio.it', '11111111-2222-3333-4444-555555555555', true, str_repeat( 'x', 300 ) );
$wc = json_decode( (string) base64_decode( strtr( explode( '.', (string) $saasSeen[2]['token'] )[1], '-_', '+/' ) ), true );
check( 'saas: disattivazione = token write, indirizzo dell\'utente, motivo troncato a 200', 'admin_write' === $wc['typ'] && 'write' === $wc['scope'] && true === $wc['disabled'] && 200 === strlen( $wc['reason'] ) && false !== strpos( $saasSeen[1], '/admin/users/11111111-2222-3333-4444-555555555555/state' ) );
check( 'saas: identificativo non valido rifiutato (niente percorsi strani)', null !== throwsUser( function () { Portale\SaasClient::org( 'staff@esempio.it', '../../x' ); } ) );
Http::$transport = function () { return array( 403, '{"error":"scope"}' ); };
check( 'saas: errore del SaaS tradotto in messaggio chiaro', 'Questa operazione richiede il permesso di scrittura.' === throwsUser( function () { Portale\SaasClient::setUserDisabled( 'a@esempio.it', '11111111-2222-3333-4444-555555555555', true ); } ) );
Http::$transport = function () { return array( 0, '' ); };
check( 'saas: SaaS non raggiungibile', 'Il SaaS non risponde: riprova tra poco.' === throwsUser( function () { Portale\SaasClient::orgs( 'a@esempio.it' ); } ) );
Http::$transport = $prevTransport;

// --- Confine logico del plugin: /wordpress/admin (docs/ARCHITETTURA_DOMINI.md §3.2) ------------------------------------------------
check( 'plugin: l\'amministrazione sta sotto /wordpress/admin', '/wordpress/admin' === Portale\Plugin\Admin::BASE );
check( 'plugin: le viste stanno in views/wordpress-admin e non più in views/admin', is_file( __DIR__ . '/../views/wordpress-admin/_nav.php' ) && ! is_dir( __DIR__ . '/../views/admin' ) );
$pluginSrc = (string) file_get_contents( __DIR__ . '/../src/Plugin/Admin.php' );
check( 'plugin: nessun indirizzo «/admin» scritto a mano nel codice dell\'amministrazione', 0 === preg_match( '#[\'"]/admin#', $pluginSrc ) );
$badLinks = 0;
foreach ( glob( __DIR__ . '/../views/wordpress-admin/*.php' ) as $f ) {
	$badLinks += preg_match_all( '#(?:href|action)="/admin#', (string) file_get_contents( $f ) );
}
check( 'plugin: le viste puntano tutte a /wordpress/admin', 0 === $badLinks );
// Staff del plugin in tabella propria (plugin_staff) e migrazione in due tempi
$sdb = new Db( new PDO( 'sqlite::memory:' ) );
$sdb->migrate();
$sdb->run( 'INSERT INTO staff (email, role, saas, created_at) VALUES (?,?,?,?)', array( 'vecchio@esempio.it', 'support', 'admin', Db::now() ) );
$sdb->run( 'INSERT INTO staff (email, role, saas, created_at) VALUES (?,?,?,?)', array( 'solosaas@esempio.it', 'none', 'admin', Db::now() ) );
check( 'plugin staff: prima della copia il ruolo si legge ancora dalla tabella vecchia', 'support' === Portale\Plugin\Staff::role( $sdb, 'vecchio@esempio.it' ) );
$sdb->migrate(); // la migrazione si può ripetere
check( 'plugin staff: la migrazione copia chi ha un ruolo nel plugin, non chi ha solo il SaaS', 'support' === (string) $sdb->one( 'SELECT role FROM plugin_staff WHERE email = ?', array( 'vecchio@esempio.it' ) )['role'] && null === $sdb->one( 'SELECT 1 AS x FROM plugin_staff WHERE email = ?', array( 'solosaas@esempio.it' ) ) );
check( 'plugin staff: chi ha solo il SaaS non entra nel plugin', null === Portale\Plugin\Staff::role( $sdb, 'solosaas@esempio.it' ) );
Portale\Plugin\Staff::put( $sdb, 'vecchio@esempio.it', 'admin' );
check( 'plugin staff: il ruolo nuovo vince su quello della tabella vecchia', 'admin' === Portale\Staff::role( $sdb, 'vecchio@esempio.it' ) );
$sbil = new Billing( $sdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
Portale\Staff::set( $sdb, $sbil, 'vecchio@esempio.it', 'none' ); // toglie il plugin, tiene il SaaS
$sdb->migrate();
check( 'plugin staff: tolto il ruolo non rivive né dal ripiego né da una nuova migrazione', null === Portale\Staff::role( $sdb, 'vecchio@esempio.it' ) && 'admin' === Portale\Staff::saas( $sdb, 'vecchio@esempio.it' ) );

// Codici promo del plugin (Plugin\Promos)
$rdb2 = new Db( new PDO( 'sqlite::memory:' ) );
$rdb2->migrate();
$rcat  = new Portale\Plugin\ConfigCatalog();
$rplan = Portale\Site::slug( 'full' );
$rcodes = array_column( $rcat->document()['plans'], 'code' );
$rbase  = (int) Portale\Config::get( 'plans.full.prices.year.cents', 0 );
$rnow   = strtotime( '2026-11-15 12:00:00 UTC' );
check( 'promo: codice troppo corto o con simboli rifiutato', null !== throwsUser( function () use ( $rcodes ) { Portale\Plugin\Promos::validate( array( 'code' => 'a!', 'kind' => 'percent', 'value' => 10, 'ends_on' => '2026-12-31' ), $rcodes ); } ) );
check( 'promo: percentuale 0 o oltre 100 rifiutata', null !== throwsUser( function () use ( $rcodes ) { Portale\Plugin\Promos::validate( array( 'code' => 'ABC', 'kind' => 'percent', 'value' => 101, 'ends_on' => '2026-12-31' ), $rcodes ); } ) );
check( 'promo: serve la data di fine; inizio dopo la fine rifiutato', null !== throwsUser( function () use ( $rcodes ) { Portale\Plugin\Promos::validate( array( 'code' => 'ABC', 'kind' => 'percent', 'value' => 10 ), $rcodes ); } ) && null !== throwsUser( function () use ( $rcodes ) { Portale\Plugin\Promos::validate( array( 'code' => 'ABC', 'kind' => 'percent', 'value' => 10, 'starts_on' => '2026-12-31', 'ends_on' => '2026-12-01' ), $rcodes ); } ) );
check( 'promo: piano non del plugin rifiutato (le promo sono di un solo prodotto)', null !== throwsUser( function () use ( $rcodes ) { Portale\Plugin\Promos::validate( array( 'code' => 'ABC', 'kind' => 'percent', 'value' => 10, 'ends_on' => '2026-12-31', 'plans' => array( 'saas_pro' ) ), $rcodes ); } ) );
Portale\Plugin\Promos::save( $rdb2, Portale\Plugin\Promos::validate( array( 'code' => ' black-friday ', 'kind' => 'percent', 'value' => 20, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'max_uses' => 2, 'plans' => array( $rplan ) ), $rcodes ) );
$rck = Portale\Plugin\Promos::check( $rdb2, $rcat, 'Black-Friday', $rplan, 'year', $rnow );
check( 'promo: codice valido, senza distinzione di maiuscole, sconto del 20% calcolato', $rbase > 0 ? ( $rck['valid'] && (int) round( $rbase * 0.2 ) === $rck['discount_cents'] && $rbase - $rck['discount_cents'] === $rck['final_cents'] && '2026-11-30' === $rck['ends_on'] ) : true );
check( 'promo: controllare non consuma nulla', 0 === (int) Portale\Plugin\Promos::get( $rdb2, 'BLACK-FRIDAY' )['used'] );
check( 'promo: codice sconosciuto, fuori date, piano non coperto', 'unknown_code' === Portale\Plugin\Promos::check( $rdb2, $rcat, 'NOPE', $rplan, 'year', $rnow )['reason'] && 'out_of_dates' === Portale\Plugin\Promos::check( $rdb2, $rcat, 'BLACK-FRIDAY', $rplan, 'year', strtotime( '2026-12-02 UTC' ) )['reason'] && 'out_of_dates' === Portale\Plugin\Promos::check( $rdb2, $rcat, 'BLACK-FRIDAY', $rplan, 'year', strtotime( '2026-10-31 UTC' ) )['reason'] );
check( 'promo: confermare lo stesso ordine due volte consuma un uso solo', true === Portale\Plugin\Promos::redeem( $rdb2, 'BLACK-FRIDAY', 'ord-1', $rnow ) && false === Portale\Plugin\Promos::redeem( $rdb2, 'BLACK-FRIDAY', 'ord-1', $rnow ) && 1 === (int) Portale\Plugin\Promos::get( $rdb2, 'BLACK-FRIDAY' )['used'] );
Portale\Plugin\Promos::redeem( $rdb2, 'BLACK-FRIDAY', 'ord-2', $rnow );
check( 'promo: limite d\'uso raggiunto = esaurita, il terzo ordine rifiutato e non registrato', 'exhausted' === Portale\Plugin\Promos::check( $rdb2, $rcat, 'BLACK-FRIDAY', $rplan, 'year', $rnow )['reason'] && null !== throwsUser( function () use ( $rdb2, $rnow ) { Portale\Plugin\Promos::redeem( $rdb2, 'BLACK-FRIDAY', 'ord-3', $rnow ); } ) && 2 === (int) Portale\Plugin\Promos::get( $rdb2, 'BLACK-FRIDAY' )['used'] && null === $rdb2->one( "SELECT 1 AS x FROM plugin_promo_uses WHERE order_ref = 'ord-3'" ) );
Portale\Plugin\Promos::save( $rdb2, Portale\Plugin\Promos::validate( array( 'code' => 'EURO5', 'kind' => 'amount', 'value' => 500, 'ends_on' => '2026-12-31' ), $rcodes ) );
$reuro = Portale\Plugin\Promos::check( $rdb2, $rcat, 'EURO5', $rplan, 'year', $rnow );
check( 'promo: sconto in euro, mai oltre il prezzo', $rbase > 0 ? ( $reuro['valid'] && 500 === $reuro['discount_cents'] ) : true );
Portale\Plugin\Promos::setActive( $rdb2, 'EURO5', false );
check( 'promo: sospesa = non vale e non compare nel catalogo pubblico', 'inactive' === Portale\Plugin\Promos::check( $rdb2, $rcat, 'EURO5', $rplan, 'year', $rnow )['reason'] && array() === array_values( array_filter( Portale\Plugin\Promos::publicList( $rdb2, $rnow ), function ( $p ) { return 'EURO5' === $p['code']; } ) ) );
$rpub = Portale\Plugin\Promos::publicList( $rdb2, $rnow );
check( 'promo: la lista pubblica non espone dati interni', array() === array_filter( $rpub, function ( $p ) { return isset( $p['used'] ) || isset( $p['active'] ); } ) );

// Operazioni dell'area clienti (CustomerApi, DownloadLink)
$adb = new Db( new PDO( 'sqlite::memory:' ) );
$adb->migrate();
$abil = new Billing( $adb, new WegaClient( 'https://license.test/api/v1', 't' ) );
foreach ( array( '1.0.0' => '2026-01-01 00:00:00', '1.1.0' => '2026-03-01 00:00:00', '1.2.0' => '2026-06-01 00:00:00' ) as $ver => $at ) {
	foreach ( array( 'free', 'pro' ) as $prd ) {
		$adb->run( 'INSERT INTO releases (product, version, size, sha256, created_at) VALUES (?,?,?,?,?)', array( $prd, $ver, 1, str_repeat( 'a', 64 ), $at ) );
	}
}
$mk = function ( string $id, string $cid, string $plan, string $status, string $exp ) use ( $adb ) {
	$adb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( $id, $cid, $plan, 'year', 'stripe', $status, $id . '.it', $exp, Db::now(), Db::now() ) );
	return $adb->one( 'SELECT * FROM subscriptions WHERE id = ?', array( $id ) );
};
$now    = strtotime( '2026-09-01 00:00:00 UTC' );
$okSub  = $mk( 'ok', 'cA', 'full', 'active', '2027-01-01 00:00:00' );
$oldSub = $mk( 'old', 'cA', 'full', 'active', '2026-04-01 00:00:00' ); // scaduta il 1/4: l'ultima uscita prima è la 1.1.0
$frSub  = $mk( 'fr', 'cA', 'free', 'active', '2027-01-01 00:00:00' );
check( 'download: licenza in regola scarica qualunque versione', null !== Portale\Plugin\DownloadLink::allowed( $adb, $okSub, 'pro', '1.0.0', $now ) );
check( 'download: licenza scaduta solo l\'ultima versione precedente alla scadenza', null !== Portale\Plugin\DownloadLink::allowed( $adb, $oldSub, 'pro', '1.1.0', $now ) && null === Portale\Plugin\DownloadLink::allowed( $adb, $oldSub, 'pro', '1.2.0', $now ) && null === Portale\Plugin\DownloadLink::allowed( $adb, $oldSub, 'pro', '1.0.0', $now ) );
check( 'download: licenza gratuita non scarica il Pro', null === Portale\Plugin\DownloadLink::allowed( $adb, $frSub, 'pro', '1.0.0', $now ) && null !== Portale\Plugin\DownloadLink::allowed( $adb, $frSub, 'free', '1.0.0', $now ) );
$link = Portale\Plugin\DownloadLink::create( $adb, $okSub, 'pro', '1.2.0', $now );
check( 'download: il collegamento punta a /wordpress/scarica con token', 0 === strpos( $link, 'https://portale.test/wordpress/scarica?t=' ) );
$tok = (string) substr( $link, strpos( $link, 't=' ) + 2 );
check( 'download: token scaduto dopo 5 minuti', 410 === Portale\Plugin\DownloadLink::resolve( $adb, $tok, $now + 301 )[0] );
check( 'download: token alterato rifiutato', 403 === Portale\Plugin\DownloadLink::resolve( $adb, $tok . 'x', $now )[0] && 403 === Portale\Plugin\DownloadLink::resolve( $adb, 'a.b', $now )[0] );
check( 'download: licenza non scaricabile = nessun collegamento', null !== throwsUser( function () use ( $adb, $oldSub, $now ) { Portale\Plugin\DownloadLink::create( $adb, $oldSub, 'pro', '1.2.0', $now ); } ) );
$capi = new Portale\Plugin\CustomerApi( $adb, $abil );
check( 'area clienti: license.sites elenca solo le licenze del cliente, senza chiave', 3 === count( $capi->sites( 'cA' ) ) && 0 === count( $capi->sites( 'altro' ) ) && ! array_key_exists( 'license_key_enc', $capi->sites( 'cA' )[0] ) );
check( 'area clienti: licenza di un altro cliente = non trovata', null !== throwsUser( function () use ( $capi ) { $capi->domainCheck( 'altro', 'ok', 'nuovo.it' ); } ) && null !== throwsUser( function () use ( $capi ) { $capi->downloadLink( 'altro', 'ok', 'free' ); } ) );
check( 'area clienti: domain.check non modifica nulla e segnala dominio uguale/non valido', false === $capi->domainCheck( 'cA', 'ok', 'ok.it' )['ok'] && false === $capi->domainCheck( 'cA', 'ok', '' )['ok'] && 'ok.it' === $adb->one( 'SELECT domain FROM subscriptions WHERE id = ?', array( 'ok' ) )['domain'] );
check( 'area clienti: domain.check dà ok per un dominio libero', true === $capi->domainCheck( 'cA', 'ok', 'nuovo-sito.it' )['ok'] );

// Verifica in due passaggi opzionale (TOTP, per persona)
$rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890" in base32 (vettori RFC 6238)
check( 'totp: vettori RFC 6238 (SHA-1, 6 cifre)', '287082' === Portale\Plugin\Totp::code( $rfc, 59 ) && '081804' === Portale\Plugin\Totp::code( $rfc, 1111111109 ) && '005924' === Portale\Plugin\Totp::code( $rfc, 1234567890 ) );
check( 'totp: base32 va e torna', '12345678901234567890' === Portale\Plugin\Totp::decode32( $rfc ) && $rfc === Portale\Plugin\Totp::base32( '12345678901234567890' ) );
check( 'totp: tolleranza di 30 secondi, non di più; formato rigoroso', Portale\Plugin\Totp::verify( $rfc, '287082', 59 + 30 ) && ! Portale\Plugin\Totp::verify( $rfc, '287082', 59 + 90 ) && ! Portale\Plugin\Totp::verify( $rfc, '28708', 59 ) && ! Portale\Plugin\Totp::verify( $rfc, 'abcdef', 59 ) );
$mdb = new Db( new PDO( 'sqlite::memory:' ) );
$mdb->migrate();
$mbil = new Billing( $mdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
Portale\Staff::set( $mdb, $mbil, 'persona@esempio.it', 'admin' );
Portale\Staff::set( $mdb, $mbil, 'altra@esempio.it', 'support' );
check( 'mfa: di base è spenta per tutti', ! Portale\Plugin\Mfa::enabled( $mdb, 'persona@esempio.it' ) && ! Portale\Plugin\Mfa::enabled( $mdb, 'altra@esempio.it' ) );
$now = 1700000000;
$prevSession = $_SESSION ?? array();
$_SESSION = array();
check( 'mfa: attivazione con codice sbagliato rifiutata', null !== throwsUser( function () use ( $mdb, $rfc, $now ) { Portale\Plugin\Mfa::enable( $mdb, 'persona@esempio.it', $rfc, '000000', $now ); } ) && ! Portale\Plugin\Mfa::enabled( $mdb, 'persona@esempio.it' ) );
Portale\Plugin\Mfa::enable( $mdb, 'persona@esempio.it', $rfc, Portale\Plugin\Totp::code( $rfc, $now ), $now );
check( 'mfa: ognuno sceglie per sé (attiva per una persona, non per l\'altra)', Portale\Plugin\Mfa::enabled( $mdb, 'persona@esempio.it' ) && ! Portale\Plugin\Mfa::enabled( $mdb, 'altra@esempio.it' ) );
check( 'mfa: il segreto nel database è cifrato', false === strpos( (string) $mdb->one( 'SELECT totp_secret_enc FROM plugin_staff WHERE email = ?', array( 'persona@esempio.it' ) )['totp_secret_enc'], $rfc ) );
check( 'mfa: il ruolo non cambia attivando la verifica', 'admin' === Portale\Plugin\Staff::role( $mdb, 'persona@esempio.it' ) );
$_SESSION = array();
check( 'mfa: sessione senza codice non è verificata', ! Portale\Plugin\Mfa::sessionOk( 'persona@esempio.it', $now ) );
Portale\Plugin\Mfa::markSession( 'Persona@esempio.it', $now );
check( 'mfa: sessione verificata per 8 ore, solo per quell\'indirizzo', Portale\Plugin\Mfa::sessionOk( 'persona@esempio.it', $now + 3600 ) && ! Portale\Plugin\Mfa::sessionOk( 'altra@esempio.it', $now + 3600 ) && ! Portale\Plugin\Mfa::sessionOk( 'persona@esempio.it', $now + 8 * 3600 + 1 ) );
check( 'mfa: codice giusto accettato, sbagliato no', Portale\Plugin\Mfa::check( $mdb, 'persona@esempio.it', Portale\Plugin\Totp::code( $rfc, $now ), $now ) && ! Portale\Plugin\Mfa::check( $mdb, 'persona@esempio.it', '111111', $now ) );
check( 'mfa: spegnere richiede un codice valido', null !== throwsUser( function () use ( $mdb, $now ) { Portale\Plugin\Mfa::disable( $mdb, 'persona@esempio.it', '222222', $now ); } ) && Portale\Plugin\Mfa::enabled( $mdb, 'persona@esempio.it' ) );
Portale\Plugin\Mfa::disable( $mdb, 'persona@esempio.it', Portale\Plugin\Totp::code( $rfc, $now + 3600 ), $now + 3600 );
check( 'mfa: spenta di nuovo con un codice valido', ! Portale\Plugin\Mfa::enabled( $mdb, 'persona@esempio.it' ) );
$_SESSION = $prevSession;

// Firma EdDSA del catalogo (chiave separata)
if ( function_exists( 'sodium_crypto_sign_detached' ) ) {
	$gseed  = str_repeat( 'g', SODIUM_CRYPTO_SIGN_SEEDBYTES );
	$gother = str_repeat( 'h', SODIUM_CRYPTO_SIGN_SEEDBYTES );
	$gpub   = Portale\Plugin\CatalogSigner::publicKey( $gseed );
	$gbody  = (string) json_encode( ( new Portale\Plugin\ConfigCatalog() )->document() );
	$ghead  = (string) Portale\Plugin\CatalogSigner::header( $gbody, $gseed );
	check( 'firma catalogo: kid proprio e chiave pubblica di 32 byte', 0 === strpos( Portale\Plugin\CatalogSigner::kid( $gseed ), 'catalogo-plugin-' ) && 32 === strlen( (string) base64_decode( strtr( $gpub, '-_', '+/' ) ) ) );
	check( 'firma catalogo: l\'intestazione contiene algoritmo, kid e firma', false !== strpos( $ghead, 'alg="EdDSA"' ) && false !== strpos( $ghead, 'kid="' . Portale\Plugin\CatalogSigner::kid( $gseed ) . '"' ) );
	check( 'firma catalogo: si verifica con la chiave pubblica', Portale\Plugin\CatalogSigner::verify( $gbody, $ghead, $gpub ) );
	check( 'firma catalogo: corpo alterato rifiutato', ! Portale\Plugin\CatalogSigner::verify( $gbody . ' ', $ghead, $gpub ) && ! Portale\Plugin\CatalogSigner::verify( str_replace( '"plugin"', '"saas"', $gbody ), $ghead, $gpub ) );
	check( 'firma catalogo: altra chiave rifiutata, intestazione rotta rifiutata', ! Portale\Plugin\CatalogSigner::verify( $gbody, $ghead, Portale\Plugin\CatalogSigner::publicKey( $gother ) ) && ! Portale\Plugin\CatalogSigner::verify( $gbody, 'sig="xx"', $gpub ) && ! Portale\Plugin\CatalogSigner::verify( $gbody, '', $gpub ) );
	check( 'firma catalogo: senza chiave configurata non si firma', null === Portale\Plugin\CatalogSigner::header( $gbody, '' ) || ! Portale\Plugin\CatalogSigner::enabled() );
}

// Notifica di abbonamento (Plugin\SubscriptionNotice)
$ndb   = new Db( new PDO( 'sqlite::memory:' ) );
$ndb->migrate();
$ncalls = array();
$nprev  = Http::$transport;
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$ncalls ) {
	$ncalls[] = array( $method, $url, (string) $body );
	return array( 200, '{"data":{"expires_at":"2027-03-31T23:59:59Z"}}' );
};
$nbil = new Billing( $ndb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$ncus = $nbil->customer( 'notifica@esempio.it' );
$ndb->run( "INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, license_id, license_key_enc, expires_at, created_at, updated_at) VALUES ('n1',?, 'lite','year','stripe','active','notifica.it','L-1',?, '2026-12-01 00:00:00', ?, ?)", array( $ncus['id'], Portale\Secrets::seal( 'KEY-N' ), Db::now(), Db::now() ) );
$ncat = new Portale\Plugin\ConfigCatalog( 1 );
$nmsg = array( 'ref' => 'n1', 'rev' => 1, 'plan' => Portale\Site::slug( 'full' ), 'plan_version' => 1, 'until' => '2027-03-31', 'sites' => 3 );
check( 'notifica: piano sconosciuto = errore e nessuna modifica', null !== throwsUser( function () use ( $ndb, $nbil, $ncat, $nmsg ) { Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'plan' => 'inventato' ) + $nmsg ); } ) && 'lite' === $ndb->one( "SELECT plan_code FROM subscriptions WHERE id = 'n1'" )['plan_code'] && 0 === count( $ncalls ) );
check( 'notifica: versione del piano sconosciuta = nessuna modifica', null !== throwsUser( function () use ( $ndb, $nbil, $ncat, $nmsg ) { Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'plan_version' => 99 ) + $nmsg ); } ) && 0 === count( $ncalls ) );
check( 'notifica: messaggio incompleto rifiutato', null !== throwsUser( function () use ( $ndb, $nbil, $ncat ) { Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'ref' => 'n1' ) ); } ) && null !== throwsUser( function () use ( $ndb, $nbil, $ncat, $nmsg ) { Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'until' => '31/03/2027' ) + $nmsg ); } ) );
check( 'notifica: licenza inesistente rifiutata', null !== throwsUser( function () use ( $ndb, $nbil, $ncat, $nmsg ) { Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'ref' => 'zzz' ) + $nmsg ); } ) );
check( 'notifica: applicata', 'applied' === Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, $nmsg ) );
$nrow = $ndb->one( "SELECT * FROM subscriptions WHERE id = 'n1'" );
check( 'notifica: piano, versione, siti, revisione e scadenza salvati', 'full' === $nrow['plan_code'] && 1 === (int) $nrow['plan_version'] && 3 === (int) $nrow['sites_allowed'] && 1 === (int) $nrow['notice_rev'] && '2027-03-31 23:59:59' === $nrow['expires_at'] );
check( 'notifica: il server licenze riceve nome piano e scadenza', 1 === count( $ncalls ) && 'PATCH' === $ncalls[0][0] && false !== strpos( $ncalls[0][2], '2027-03-31T23:59:59Z' ) && false !== strpos( $ncalls[0][2], 'plan_name' ) );
check( 'notifica: revisione già applicata o più vecchia = ignorata, nessuna chiamata', 'stale' === Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'sites' => 9 ) + $nmsg ) && 1 === count( $ncalls ) && 3 === (int) $ndb->one( "SELECT sites_allowed FROM subscriptions WHERE id = 'n1'" )['sites_allowed'] );
check( 'notifica: revisione più nuova applicata', 'applied' === Portale\Plugin\SubscriptionNotice::apply( $ndb, $nbil, $ncat, array( 'rev' => 2, 'sites' => 5 ) + $nmsg ) && 5 === (int) $ndb->one( "SELECT sites_allowed FROM subscriptions WHERE id = 'n1'" )['sites_allowed'] );
Http::$transport = $nprev;

// Downgrade bloccato dai limiti del piano di arrivo (Plugin\Downgrade)
$wdb = new Db( new PDO( 'sqlite::memory:' ) );
$wdb->migrate();
$wbase  = ( new Portale\Plugin\ConfigCatalog() )->document();
$wcodes = array_column( $wbase['plans'], 'code' );
$wfull  = Portale\Site::slug( 'full' );
$wfree  = Portale\Site::slug( 'free' );
$wd     = Portale\Plugin\CatalogStore::draft( $wdb );
$wd['features'] = array( array( 'id' => 'soci', 'text' => 'Anagrafica soci' ) );
foreach ( $wcodes as $wc ) {
	$wd['matrix'][ $wc ]['soci'] = array( 'state' => 'included', 'note' => '' );
}
$wd['plans'][ $wfree ]['sites'] = 1; // il piano gratuito ammette 1 sito
Portale\Plugin\CatalogStore::saveDraft( $wdb, $wd, 'staff@esempio.it' );
Portale\Plugin\CatalogStore::publish( $wdb, $wbase, 'staff@esempio.it' );
$wcat = new Portale\Plugin\DbCatalog( $wdb );
$wnow = strtotime( '2026-09-01 UTC' );
$wsub = function ( string $id, string $exp, string $status = 'active' ) use ( $wdb ) {
	$wdb->run( 'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', array( $id, 'cW', 'full', 'year', 'stripe', $status, $id . '.it', $exp, Db::now(), Db::now() ) );
	return $wdb->one( 'SELECT * FROM subscriptions WHERE id = ?', array( $id ) );
};
$w1 = $wsub( 'w1', '2027-01-01 00:00:00' );
check( 'downgrade: con un solo sito attivo il passaggio a un piano da 1 sito è possibile', array() === Portale\Plugin\Downgrade::blockers( $wdb, $wcat, $w1, 'free', $wnow ) );
$wsub( 'w2', '2027-02-01 00:00:00' );
$wsub( 'w3', '2026-01-01 00:00:00' ); // scaduta: non conta
$wsub( 'w4', '2027-02-01 00:00:00', 'canceled' ); // annullata: non conta
check( 'downgrade: licenze scadute o annullate non contano come siti in uso', 2 === Portale\Plugin\Downgrade::activeSites( $wdb, 'cW', 'full', $wnow ) );
$wb = Portale\Plugin\Downgrade::blockers( $wdb, $wcat, $w1, 'free', $wnow );
check( 'downgrade: con più siti attivi di quelli ammessi è bloccato, con motivo chiaro', 1 === count( $wb ) && false !== strpos( $wb[0], '2 siti attivi' ) && false !== strpos( $wb[0], 'ne ammette 1' ) );
check( 'downgrade: piano di arrivo senza limite di siti = nessun blocco', array() === Portale\Plugin\Downgrade::blockers( $wdb, $wcat, $w1, 'full', $wnow ) && array() === Portale\Plugin\Downgrade::blockers( $wdb, $wcat, $w1, 'inesistente', $wnow ) );

// Editor del catalogo: bozza, validazione, pubblicazione, versioni dei piani
$kdb = new Db( new PDO( 'sqlite::memory:' ) );
$kdb->migrate();
$kbase  = ( new Portale\Plugin\ConfigCatalog() )->document();
$kcodes = array_column( $kbase['plans'], 'code' );
$kfull  = Portale\Site::slug( 'full' );
$kcat   = new Portale\Plugin\DbCatalog( $kdb );
check( 'catalogo editor: senza pubblicazioni il catalogo è quello della configurazione', null === Portale\Plugin\CatalogStore::published( $kdb ) && $kcat->document()['plans'] == $kbase['plans'] );
$kd = Portale\Plugin\CatalogStore::draft( $kdb );
check( 'catalogo editor: la bozza parte dai piani di oggi', array_keys( $kd['plans'] ) === $kcodes && array() === $kd['features'] );
check( 'catalogo editor: nessuna bozza salvata finché non si salva', ! Portale\Plugin\CatalogStore::hasDraft( $kdb ) );
check( 'catalogo editor: piano visibile senza caratteristiche non pubblicabile', null !== throwsUser( function () use ( $kdb, $kbase ) { Portale\Plugin\CatalogStore::publish( $kdb, $kbase, 'staff@esempio.it' ); } ) );
list( $kfeat, $kerr ) = Portale\Plugin\CatalogStore::parseFeatures( "soci | Anagrafica soci\nprima_nota | Prima nota\n\n" );
check( 'catalogo editor: righe «codice | testo» lette', array() === $kerr && 2 === count( $kfeat ) && 'prima_nota' === $kfeat[1]['id'] );
check( 'catalogo editor: riga senza separatore segnalata', array() !== Portale\Plugin\CatalogStore::parseFeatures( 'solo testo' )[1] );
$kd['features'] = $kfeat;
foreach ( $kcodes as $kc ) {
	$kd['matrix'][ $kc ]['soci']       = array( 'state' => 'included', 'note' => '' );
	$kd['matrix'][ $kc ]['prima_nota'] = array( 'state' => $kc === $kfull ? 'included' : 'excluded', 'note' => '' );
}
$kd['plans'][ $kfull ]['support_level'] = 'top';
check( 'catalogo editor: bozza valida', array() === Portale\Plugin\CatalogStore::validate( $kd, $kcodes ) );
$kbad = $kd;
$kbad['features'][0]['id'] = 'Soci!';
$kbad['plans'][ $kfull ]['support_level'] = 'oro';
$kbad['plans'][ $kfull ]['sites'] = 5000;
$kbad['features'][1]['text'] = '<b>x</b>';
check( 'catalogo editor: codice, livello, siti e HTML non validi segnalati', count( Portale\Plugin\CatalogStore::validate( $kbad, $kcodes ) ) >= 4 );
$knone = $kd;
foreach ( $knone['plans'] as $kc => $kp ) {
	$knone['plans'][ $kc ]['visible'] = false;
}
check( 'catalogo editor: deve restare visibile almeno un piano', in_array( 'Deve restare visibile almeno un piano.', Portale\Plugin\CatalogStore::validate( $knone, $kcodes ), true ) );
Portale\Plugin\CatalogStore::saveDraft( $kdb, $kd, 'staff@esempio.it' );
check( 'catalogo editor: bozza salvata ma non ancora visibile al portale', Portale\Plugin\CatalogStore::hasDraft( $kdb ) && null === Portale\Plugin\CatalogStore::published( $kdb ) && array() === $kcat->document()['features'] );
check( 'catalogo editor: pubblicazione = revisione 1', 1 === Portale\Plugin\CatalogStore::publish( $kdb, $kbase, 'staff@esempio.it' ) && ! Portale\Plugin\CatalogStore::hasDraft( $kdb ) );
$kdoc = $kcat->document();
$kplan = null;
foreach ( $kdoc['plans'] as $kp ) {
	if ( $kp['code'] === $kfull ) {
		$kplan = $kp;
	}
}
check( 'catalogo editor: il portale legge la revisione pubblicata (rev, livello, caratteristiche)', 1 === $kdoc['rev'] && 'top' === $kplan['support_level'] && 1 === $kplan['plan_version'] && 2 === count( $kdoc['features'] ) && 'included' === $kdoc['features'][1]['plans'][ $kfull ]['state'] );
check( 'catalogo editor: i prezzi restano quelli vivi della configurazione', $kdoc['prices'] === $kbase['prices'] );
// Ripubblicare senza cambiare nulla: nuova revisione, versioni dei piani invariate
Portale\Plugin\CatalogStore::saveDraft( $kdb, Portale\Plugin\CatalogStore::draft( $kdb ), 'staff@esempio.it' );
check( 'catalogo editor: ripubblicare senza modifiche = revisione 2, plan_version invariata', 2 === Portale\Plugin\CatalogStore::publish( $kdb, $kbase, 'staff@esempio.it' ) && 1 === (int) array_values( array_filter( $kcat->document()['plans'], function ( $p ) use ( $kfull ) { return $p['code'] === $kfull; } ) )[0]['plan_version'] );
// Cambiando un piano la sua versione sale; l'altro resta com'è
$k2 = Portale\Plugin\CatalogStore::draft( $kdb );
$k2['matrix'][ $kfull ]['soci'] = array( 'state' => 'limit', 'note' => 'fino a 500 soci' );
Portale\Plugin\CatalogStore::saveDraft( $kdb, $k2, 'staff@esempio.it' );
Portale\Plugin\CatalogStore::publish( $kdb, $kbase, 'staff@esempio.it' );
$kv = array();
foreach ( $kcat->document()['plans'] as $kp ) {
	$kv[ $kp['code'] ] = $kp['plan_version'];
}
check( 'catalogo editor: cambia un piano = la sua plan_version sale, quelle degli altri no', 2 === $kv[ $kfull ] && count( $kcodes ) === 1 + count( array_filter( $kv, function ( $v ) { return 1 === $v; } ) ) );
check( 'catalogo editor: revisioni conservate in elenco', 3 === count( Portale\Plugin\CatalogStore::history( $kdb ) ) );
check( 'catalogo editor: scartare la bozza', ( Portale\Plugin\CatalogStore::saveDraft( $kdb, $k2, 'x' ) || true ) && ( Portale\Plugin\CatalogStore::discard( $kdb ) || true ) && ! Portale\Plugin\CatalogStore::hasDraft( $kdb ) );

// Catalogo del plugin (interfaccia Catalog, formato catalogo/v1)
$cat    = new Portale\Plugin\ConfigCatalog( 3 );
$catDoc = $cat->document();
check( 'catalogo: schema, prodotto e rev', 'catalogo/v1' === $catDoc['schema'] && 'plugin' === $catDoc['product'] && 3 === $catDoc['rev'] );
check( 'catalogo: un piano per ogni piano di config, con slug stabili', count( (array) Portale\Config::get( 'plans', array() ) ) === count( $catDoc['plans'] ) && null !== $cat->plan( Portale\Site::slug( 'full' ) ) );
check( 'catalogo: livelli di assistenza free/paid/top (gratuito = free, a pagamento = paid)', 'free' === $catDoc['plans'][0]['support_level'] && 'top' === $cat->plan( Portale\Site::slug( 'full' ) )['support_level'] && array() === array_diff( array_column( $catDoc['plans'], 'support_level' ), array( 'free', 'paid', 'top' ) ) );
check( 'assistenza: tra i piani a pagamento il più economico è Standard, gli altri Top; il gratuito resta free', 'full' === Portale\Plugin\ConfigCatalog::standardPlan( array( 'free' => array(), 'a' => array( 'prices' => array( 'year' => array( 'cents' => 9000 ) ) ), 'full' => array( 'prices' => array( 'year' => array( 'cents' => 4800 ) ) ) ) ) );
$sd = array( 'plans' => array( 'free' => array( 'audience' => '', 'visible' => true, 'order' => 1, 'support_level' => 'paid', 'sites' => null ), 'pro' => array( 'audience' => '', 'visible' => true, 'order' => 2, 'support_level' => '', 'sites' => null ) ), 'features' => array( array( 'id' => 'soci', 'text' => 'Soci' ) ), 'matrix' => array( 'free' => array( 'soci' => array( 'state' => 'included', 'note' => '' ) ), 'pro' => array( 'soci' => array( 'state' => 'included', 'note' => '' ) ) ) );
$sdN = Portale\Plugin\CatalogStore::normalize( $sd );
check( 'assistenza: free non può essere altro che Gratuita, un piano a pagamento senza scelta vale Standard', 1 === count( Portale\Plugin\CatalogStore::validate( $sdN, array( 'free', 'pro' ) ) ) );
$sdN['plans']['free']['support_level'] = 'free';
$sdN['plans']['pro']['support_level']  = 'free';
check( 'assistenza: un piano a pagamento non può essere «free»', 1 === count( Portale\Plugin\CatalogStore::validate( $sdN, array( 'free', 'pro' ) ) ) );
$sdN['plans']['pro']['support_level'] = 'top';
check( 'assistenza: free + Top valida', array() === Portale\Plugin\CatalogStore::validate( $sdN, array( 'free', 'pro' ) ) );
$sdN['features'][0]['compare'] = 'members';
check( 'confronto: chiave compare ammessa', array() === Portale\Plugin\CatalogStore::validate( $sdN, array( 'free', 'pro' ) ) );
$sdN['features'][0]['compare'] = 'boh';
check( 'confronto: chiave compare sconosciuta rifiutata', 1 === count( Portale\Plugin\CatalogStore::validate( $sdN, array( 'free', 'pro' ) ) ) );
$imp = Portale\Plugin\ConfrontoImport::parse( (string) file_get_contents( __DIR__ . '/../content/confronto.md' ), array( 'free', 'procontabile', 'profiscale' ) );
$impIds = array_column( $imp['features'], 'id' );
check( 'confronto: importate le righe di confronto.md con codici validi e unici', count( $imp['features'] ) > 100 && count( $impIds ) === count( array_unique( $impIds ) ) && 0 === count( array_filter( $impIds, function ( $i ) { return 1 !== preg_match( '/^[a-z0-9_]{2,40}$/', $i ); } ) ) );
check( 'confronto: stato per piano (IVA solo nel Fiscale, libro soci in tutti)', 'excluded' === $imp['matrix']['procontabile']['iva']['state'] && 'included' === $imp['matrix']['profiscale']['iva']['state'] && 'included' === $imp['matrix']['free']['prima_nota_con_cassa_unica']['state'] );
check( 'confronto: importazione ripetibile (stessi codici)', $imp === Portale\Plugin\ConfrontoImport::parse( (string) file_get_contents( __DIR__ . '/../content/confronto.md' ), array( 'free', 'procontabile', 'profiscale' ) ) );
check( 'confronto: testi entro 160 caratteri e righe con prezzi escluse', 0 === count( array_filter( $imp['features'], function ( $f ) { return mb_strlen( $f['text'] ) > 160; } ) ) && ! in_array( 'prezzo', $impIds, true ) );
check( 'catalogo: piano inesistente = null',null === $cat->plan( 'inesistente' ) && null === $cat->price( 'inesistente', 'year' ) );
$fullCents = (int) Portale\Config::get( 'plans.full.prices.year.cents', 0 );
check( 'catalogo: prezzo in centesimi interi identico a config', $fullCents > 0 ? ( array( 'cents' => $fullCents, 'currency' => 'EUR' ) === $cat->price( Portale\Site::slug( 'full' ), 'year' ) ) : true );
list( $catBody, $catEtag ) = Portale\Plugin\CatalogApi::render( $cat );
check( 'catalogo: JSON valido e ETag stabile', is_array( json_decode( $catBody, true ) ) && $catEtag === Portale\Plugin\CatalogApi::render( $cat )[1] );
// --- Indirizzi delle sezioni /saas e /wordpress -----------------------------
use Portale\Sections;
Config::set( 'saas_admin_url', '' );
check( 'sezioni: /saas/accedi diventa /account/da-gestionale con lo stesso token', array( 301, '/account/da-gestionale?t=abc' ) === Sections::legacy( 'GET', '/saas/accedi', 't=abc' ) );
check( 'sezioni: /saas/accedi con POST conserva il metodo (308)', 308 === Sections::legacy( 'POST', '/saas/accedi', '' )[0] );
check( 'sezioni: /saas/admin va all\'amministrazione del SaaS (temporaneo)', array( 302, 'https://app.associazionesemplice.it/admin' ) === Sections::legacy( 'GET', '/saas/admin', '' ) );
check( 'sezioni: /saas/admin/percorso mantiene il resto e la query', array( 302, 'https://app.associazionesemplice.it/admin/associazioni?q=a' ) === Sections::legacy( 'GET', '/saas/admin/associazioni', 'q=a' ) );
Config::set( 'saas_admin_url', 'http://non-sicuro.test/admin' );
check( 'sezioni: indirizzo di amministrazione non https ignorato', 'https://app.associazionesemplice.it/admin' === Sections::saasAdminUrl() );
Config::set( 'saas_admin_url', 'https://gestione.esempio.it/admin/' );
check( 'sezioni: indirizzo di amministrazione configurabile', 'https://gestione.esempio.it/admin/x' === Sections::legacy( 'GET', '/saas/admin/x', '' )[1] );
check( 'sezioni: vecchia pagina di un\'associazione → /saas/admin (301)', array( 301, '/saas/admin' ) === Sections::legacy( 'GET', '/saas/11111111-2222-3333-4444-555555555555', '' ) );
check( 'sezioni: vecchio /saas/utenti/… → /saas/admin (308 per POST)', array( 308, '/saas/admin' ) === Sections::legacy( 'POST', '/saas/utenti/abc', '' ) );
check( 'sezioni: /saas e /wordpress sono contenuti, non redirect', null === Sections::legacy( 'GET', '/saas', '' ) && null === Sections::legacy( 'GET', '/wordpress/changelog', '' ) );
check( 'sezioni: pagine di contenuto', 'saas_section' === Sections::view( '/saas' ) && 'wordpress_section' === Sections::view( '/wordpress' ) && 'wordpress_changelog' === Sections::view( '/wordpress/changelog' ) && null === Sections::view( '/saas/admin' ) );

// --- Catalogo del SaaS: calcolo dei prezzi, firma, anti-rollback ------------------
use Portale\{Catalog, PriceCalculator, PortalAttestation};
$cases = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/catalogo-casi.json' ), true );
$calcOk = true;
foreach ( array( 'members_tiers', 'members_tiers_year' ) as $g ) {
	foreach ( $cases[ $g ]['cases'] as $c ) {
		$calcOk = $calcOk && PriceCalculator::members( $cases[ $g ]['price'], $c['members'] ) === $c['cents'];
	}
}
foreach ( array( 'step_tiers', 'step_tiers_closed' ) as $g ) {
	foreach ( $cases[ $g ]['cases'] as $c ) {
		$calcOk = $calcOk && PriceCalculator::steps( $cases[ $g ]['price'], $c['quantity'] ) === $c['cents'];
	}
}
foreach ( $cases['vat'] as $c ) {
	$calcOk = $calcOk && PriceCalculator::vat( $c['net'], $c['percent'] ) === $c['vat'];
}
check( 'prezzi: casi comuni con il SaaS (soci, anno, fasce, IVA)', $calcOk );
check( 'prezzi: net() per tipo di prezzo', 1500 === PriceCalculator::net( array( 'kind' => 'flat', 'amount_cents' => 1500 ) ) && 3300 === PriceCalculator::net( $cases['members_tiers']['price'], 150 ) && null === PriceCalculator::net( array( 'kind' => 'altro' ) ) );

$cseed = str_repeat( 'c', 32 );
Config::set( 'saas_public_keys', array( PortalAttestation::kid( $cseed ) => PortalAttestation::publicKey( $cseed ) ) );
$cdoc = function ( int $rev ) {
	return (string) json_encode( array( 'schema' => 'catalogo/v1', 'product' => 'saas', 'rev' => $rev, 'features' => array( array( 'id' => 'members', 'text' => 'Soci', 'group' => 'Base' ) ),
		'plans' => array( array( 'code' => 'gestionale', 'plan_version' => 1, 'name' => 'Gestionale', 'order' => 1, 'visible' => true, 'features' => array( 'members' => array( 'state' => 'included' ) ), 'prices' => array( $GLOBALS['cases']['members_tiers']['price'] ) ) ) ) );
};
$csign = function ( string $body, int $rev, array $over = array(), string $typ = 'catalog', int $now = 1000000 ) use ( $cseed ) {
	return PortalAttestation::sign( $over + array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => $typ, 'iat' => $now, 'exp' => $now + 600, 'jti' => bin2hex( random_bytes( 8 ) ), 'rev' => $rev, 'sha256' => hash( 'sha256', $body ) ), $cseed );
};
$cdb = new Db( new PDO( "sqlite::memory:" ) );
$cdb->migrate();
$b1 = $cdoc( 5 );
check( 'catalogo: firma e impronta valide → salvato', '' === Catalog::accept( $cdb, $b1, $csign( $b1, 5 ), '"e5"', 1000000 ) && 5 === Catalog::stored( $cdb, 1000000 )['rev'] );
check( 'catalogo: corpo alterato rifiutato (impronta)', '' !== Catalog::accept( $cdb, $cdoc( 6 ) . ' ', $csign( $b1, 6 ), '', 1000000 ) );
check( 'catalogo: rev più vecchia rifiutata (anti-rollback)', 'rev più vecchia di quella salvata' === Catalog::accept( $cdb, $cdoc( 4 ), $csign( $cdoc( 4 ), 4 ), '', 1000000 ) );
check( 'catalogo: stessa rev con contenuto diverso rifiutata', '' !== Catalog::accept( $cdb, str_replace( 'Soci', 'Altro', $b1 ), $csign( str_replace( 'Soci', 'Altro', $b1 ), 5 ), '', 1000000 ) );
check( 'catalogo: firma con altra chiave rifiutata', '' !== Catalog::accept( $cdb, $cdoc( 7 ), PortalAttestation::sign( array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => 'catalog', 'iat' => 1000000, 'exp' => 1000600, 'jti' => 'x', 'rev' => 7, 'sha256' => hash( 'sha256', $cdoc( 7 ) ) ), str_repeat( 'z', 32 ) ), '', 1000000 ) );
check( 'catalogo: tipo, destinatario e vita lunga rifiutati', null === Catalog::verifyToken( $csign( $b1, 5, array(), 'usage' ), 'catalog', 1000000 ) && null === Catalog::verifyToken( $csign( $b1, 5, array( 'aud' => 'altro' ) ), 'catalog', 1000000 ) && null === Catalog::verifyToken( $csign( $b1, 5, array( 'exp' => 1001000 ) ), 'catalog', 1000000 ) && null === Catalog::verifyToken( $csign( $b1, 5 ), 'catalog', 1002000 ) );
check( 'catalogo: rev nuova accettata', '' === Catalog::accept( $cdb, $cdoc( 6 ), $csign( $cdoc( 6 ), 6 ), '"e6"', 1000100 ) && 6 === Catalog::stored( $cdb, 1000100 )['rev'] );
check( 'catalogo: da ricontrollare dopo 10 minuti', ! Catalog::stored( $cdb, 1000100 )['stale'] && Catalog::stored( $cdb, 1000100 + 700 )['stale'] );
$cplan = Catalog::plans( Catalog::stored( $cdb )['doc'] )[0];
check( 'catalogo: «da» il prezzo con i soci della base, mensile e annuale', array( 1500, 22 ) === Catalog::from( $cplan, 'month' ) && null === Catalog::from( $cplan, 'year' ) );

$cn = PortalAttestation::sign( array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => 'catalog_changed', 'iat' => 1000000, 'exp' => 1000060, 'jti' => 'jti-1', 'rev' => 7 ), $cseed );
check( 'catalogo: avviso firmato accettato una volta sola', Catalog::notify( $cdb, (string) json_encode( array( 'token' => $cn ) ), 1000000 ) && ! Catalog::notify( $cdb, (string) json_encode( array( 'token' => $cn ) ), 1000000 ) && ! Catalog::notify( $cdb, '{"token":"x.y.z"}', 1000000 ) );

$cprev = Http::$transport;
$cb7 = $cdoc( 7 );
$cseen = array();
Http::$transport = function ( $m, $u, $h ) use ( &$cseen, $cb7, $csign ) {
	$cseen = array( $m, $u, $h );
	return array( 200, $cb7, array( 'x-catalog-signature' => $csign( $cb7, 7, array(), 'catalog', time() ), 'etag' => '"e7"' ) );
};
check( 'catalogo: scarico con If-None-Match e firma nell\'intestazione', 'updated' === Catalog::refresh( $cdb ) && 'GET' === $cseen[0] && 'https://app.associazionesemplice.it/api/catalogo/v1' === $cseen[1] && in_array( 'If-None-Match: "e6"', $cseen[2], true ) && 7 === Catalog::stored( $cdb )['rev'] );
Http::$transport = function () { return array( 304, '' ); };
check( 'catalogo: 304 lascia la copia e la rinfresca', 'unchanged' === Catalog::refresh( $cdb ) && 7 === Catalog::stored( $cdb )['rev'] );
Http::$transport = function () { return array( 0, '' ); };
check( 'catalogo: SaaS non raggiungibile → resta l\'ultima copia', 0 === strpos( Catalog::refresh( $cdb ), 'error' ) && 7 === Catalog::stored( $cdb )['rev'] );
Http::$transport = $cprev;
Config::set( 'saas_public_keys', array() );
check( 'catalogo: senza chiavi fissate non si scarica niente', 'off' === Catalog::refresh( $cdb ) && ! Catalog::needsRefresh( $cdb ) );

// --- Acquisto SaaS: preventivo, ordine con istantanea, coda verso il SaaS ----------
use Portale\{Quote, Orders, Outbox};
$qdoc = json_decode( (string) json_encode( array(
	'schema' => 'catalogo/v1', 'product' => 'saas', 'rev' => 7, 'currency' => 'EUR', 'features' => array(),
	'plans' => array( array( 'code' => 'gestionale', 'plan_version' => 3, 'name' => 'Gestionale', 'order' => 1, 'visible' => true, 'prices' => array( $cases['members_tiers']['price'], $cases['members_tiers_year']['price'] ) ) ),
	'addons' => array( array( 'code' => 'operators_extra', 'visible' => true, 'prices' => array( array( 'period' => 'month', 'kind' => 'flat', 'amount_cents' => 300, 'vat_percent' => 22 ) ) ) ),
) ), true );
$q150 = Quote::build( $qdoc, 'gestionale', 'month', 150 );
check( 'preventivo: 150 soci = 3300 + IVA 726 = 4026, versione e rev nell\'istantanea', 3300 === $q150['net'] && 726 === $q150['vat'] && 4026 === $q150['total'] && 3 === $q150['plan_version'] && 7 === $q150['rev'] );
$qa = Quote::build( $qdoc, 'gestionale', 'month', 150, array( 'operators_extra' => 2 ) );
check( 'preventivo: addon fisso per unità (2 x 300)', 3900 === $qa['net'] && 2 === $qa['lines'][1]['qty'] && 600 === $qa['lines'][1]['net'] );
$qp = Quote::build( $qdoc, 'gestionale', 'month', 150, array(), array( 'code' => 'P20', 'discount' => array( 'type' => 'percent', 'value' => 20 ) ) );
check( 'preventivo: sconto 20% sull\'imponibile, IVA sul netto scontato', 660 === $qp['discount'] && 2640 === $qp['net'] && 581 === $qp['vat'] && 'P20' === $qp['promo'] );
$qf = Quote::build( $qdoc, 'gestionale', 'month', 150, array(), array( 'code' => 'X', 'discount' => array( 'type' => 'amount_cents', 'value' => 99999 ) ) );
check( 'preventivo: sconto a importo mai oltre il totale', 0 === $qf['net'] && 0 === $qf['total'] );
check( 'preventivo: annuale = 10 mensilità, piano o addon sconosciuti rifiutati', 33000 === Quote::build( $qdoc, 'gestionale', 'year', 150 )['net'] && null !== throwsUser( function () use ( $qdoc ) { Quote::build( $qdoc, 'altro', 'month', 10 ); } ) && null !== throwsUser( function () use ( $qdoc ) { Quote::build( $qdoc, 'gestionale', 'month', 10, array( 'zzz' => 1 ) ); } ) );

$oseed = str_repeat( 'o', 32 );
Config::set( 'saas_public_keys', array( PortalAttestation::kid( $oseed ) => PortalAttestation::publicKey( $oseed ) ) );
$odb = new Db( new PDO( 'sqlite::memory:' ) );
$odb->migrate();
$mkcat = function ( int $rev, int $base ) use ( $qdoc ) {
	$d        = $qdoc;
	$d['rev'] = $rev;
	$d['plans'][0]['prices'][0]['base_cents'] = $base;
	return (string) json_encode( $d );
};
$serve = function ( string $body, int $rev ) use ( $oseed ) {
	Http::$transport = function () use ( $body, $rev, $oseed ) {
		$sig = PortalAttestation::sign( array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => 'catalog', 'iat' => time(), 'exp' => time() + 600, 'jti' => 'j' . $rev, 'rev' => $rev, 'sha256' => hash( 'sha256', $body ) ), $oseed );
		return array( 200, $body, array( 'x-catalog-signature' => $sig, 'etag' => '"r' . $rev . '"' ) );
	};
};
$serve( $mkcat( 7, 1500 ), 7 );
$ord = Orders::open( $odb, 'cust1', 'gestionale', 'month', 150 );
check( 'ordine: prezzo e plan_version fissati nell\'istantanea', 4026 === $ord['total'] && 3 === (int) Orders::get( $odb, $ord['id'] )['plan_version'] && 7 === (int) Orders::get( $odb, $ord['id'] )['catalog_rev'] );
check( 'ordine: catalogo invariato → si può incassare', 'pending' === Orders::confirm( $odb, $ord['id'] )['status'] );
$serve( $mkcat( 8, 1500 ), 8 );
$o2 = Orders::confirm( $odb, $ord['id'] );
check( 'ordine: rev cambiata ma stesso totale → ricalcolato in silenzio', 8 === (int) $o2['catalog_rev'] );
$serve( $mkcat( 9, 2000 ), 9 );
$msg = throwsUser( function () use ( $odb, $ord ) { Orders::confirm( $odb, $ord['id'] ); } );
check( 'ordine: prezzi cambiati → avviso con il nuovo totale e nuova istantanea', null !== $msg && false !== strpos( $msg, 'I prezzi sono stati aggiornati' ) && 9 === (int) Orders::get( $odb, $ord['id'] )['catalog_rev'] && 3800 === Orders::get( $odb, $ord['id'] )['quote']['net'] );
Http::$transport = function () { return array( 0, '' ); };
$odb2 = new Db( new PDO( 'sqlite::memory:' ) );
$odb2->migrate();
check( 'ordine: senza catalogo corrente non si vende', null !== throwsUser( function () use ( $odb2 ) { Orders::open( $odb2, 'cust1', 'gestionale', 'month', 50 ); } ) );

Config::set( 'saas_sign_key', base64_encode( str_repeat( 's', 32 ) ) );
$sentMsgs = array();
Http::$transport = function ( $m, $u, $h, $b ) use ( &$sentMsgs ) {
	$sentMsgs[] = array( $m, $u, json_decode( (string) $b, true ) );
	return array( 200, '{"org_id":"11111111-2222-3333-4444-555555555555","rev":1}' );
};
$oid = '11111111-2222-3333-4444-555555555555';
Outbox::enqueue( $odb, $oid, array( 'ref' => 'sub_1', 'rev' => 1, 'status' => 'active', 'until' => '2026-12-31', 'plan' => 'gestionale', 'plan_version' => 3, 'members' => 150, 'price_cents' => 4026 ), 1000 );
check( 'coda: subscription con PUT verso l\'indirizzo del contratto', array( 1, 0 ) === Outbox::run( $odb, 1000 ) && 'PUT' === $sentMsgs[0][0] && 'https://app.associazionesemplice.it/api/portale/v2/orgs/' . $oid . '/subscription' === $sentMsgs[0][1] );
$scl = json_decode( (string) base64_decode( strtr( explode( '.', (string) $sentMsgs[0][2]['token'] )[1], '-_', '+/' ) ), true );
check( 'coda: claim firmati (sub, plan_version, vita 60 s, aud del SaaS)', 'subscription' === $scl['typ'] && $oid === $scl['sub'] && 3 === $scl['plan_version'] && 60 === $scl['exp'] - $scl['iat'] && 'associazionesemplice-saas' === $scl['aud'] );
check( 'coda: messaggio già inviato non si rimanda', array( 0, 0 ) === Outbox::run( $odb, 5000 ) );
Http::$transport = function () { return array( 503, '' ); };
Outbox::enqueue( $odb, $oid, array( 'ref' => 'sub_1', 'rev' => 2, 'status' => 'active', 'plan' => 'gestionale', 'plan_version' => 3 ), 2000 );
check( 'coda: errore 5xx → ritento dopo 1 minuto, non prima', array( 0, 1 ) === Outbox::run( $odb, 2000 ) && array( 0, 0 ) === Outbox::run( $odb, 2030 ) );
Http::$transport = function () { return array( 409, '{"error":"stale_revision"}' ); };
check( 'coda: 409 stale_revision = già applicata → chiusa', array( 1, 0 ) === Outbox::run( $odb, 2100 ) );
Config::set( 'saas_sign_key', '' );
Config::set( 'saas_sign_key_file', '/nonesiste/saas.key' );
Outbox::enqueue( $odb, $oid, array( 'ref' => 'sub_1', 'rev' => 3 ), 3000 );
check( 'coda: senza chiave di firma il messaggio resta in coda (nessun errore)', array( 0, 1 ) === Outbox::run( $odb, 3000 ) );
// --- Acquisto SaaS: pagina, promo, pagamento, creazione associazione, subscription ----------
use Portale\SaasCheckout;
Config::set( 'saas_sign_key', base64_encode( str_repeat( 's', 32 ) ) );
Config::set( 'stripe.secret_key', 'sk_test_abc' );
Config::set( 'base_url', 'https://portale.test' );
$cdb = new Db( new PDO( 'sqlite::memory:' ) );
$cdb->migrate();
$cbill  = new Billing( $cdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$ccat   = $mkcat( 7, 1500 );
$cseen  = array();
$promoOk = true;
$provCode = 201;
$cnet = function ( string $m, string $u, $h, $b ) use ( &$cseen, $ccat, $oseed, &$promoOk, &$provCode ) {
	if ( false !== strpos( $u, 'catalogo/v1' ) ) {
		$sig = PortalAttestation::sign( array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => 'catalog', 'iat' => time(), 'exp' => time() + 600, 'jti' => 'jc' . count( $cseen ), 'rev' => 7, 'sha256' => hash( 'sha256', $ccat ) ), $oseed );
		return array( 200, $ccat, array( 'x-catalog-signature' => $sig, 'etag' => '"r7"' ) );
	}
	if ( false !== strpos( $u, 'stripe.com' ) ) {
		$cseen[] = array( 'stripe', $b );
		return array( 200, '{"url":"https://checkout.stripe.test/s1"}' );
	}
	$body = json_decode( (string) $b, true );
	$cl   = isset( $body['token'] ) ? json_decode( (string) base64_decode( strtr( explode( '.', (string) $body['token'] )[1], '-_', '+/' ) ), true ) : array();
	$cseen[] = array( $cl['typ'] ?? '', $cl, $u );
	switch ( $cl['typ'] ?? '' ) {
		case 'promo_check':
			return array( 200, json_encode( $promoOk && 'PRIMAVERA' === $cl['code'] ? array( 'valid' => true, 'discount' => array( 'type' => 'percent', 'value' => 20 ) ) : array( 'valid' => false, 'reason' => 'expired' ) ) );
		case 'promo_redeem':
			return array( 200, '{"redeemed":true}' );
		case 'provision':
			return array( $provCode, $provCode >= 400 ? '{"error":"slug_taken"}' : '{"org_id":"aaaaaaaa-bbbb-cccc-dddd-000000000001","created":true}' );
	}
	return array( 200, '{}' );
};
Http::$transport = $cnet;
check( 'slug proposto dal nome: minuscole, trattini, niente accenti', 'circolo-san-giorgio' === SaasCheckout::slugFrom( 'Circolo San Giorgio!' ) && SaasCheckout::validSlug( 'circolo-san-giorgio' ) && ! SaasCheckout::validSlug( '-ab' ) && ! SaasCheckout::validSlug( 'a--b' ) );
check( 'promo: codice valido → sconto dal SaaS; scaduto → messaggio', 20 === SaasCheckout::promo( 'primavera', 'gestionale', 3, 'month' )['discount']['value'] && null === SaasCheckout::promo( '', 'gestionale', 3, 'month' ) && false !== strpos( (string) throwsUser( function () { SaasCheckout::promo( 'ZZZ', 'gestionale', 3, 'month' ); } ), 'scaduto' ) );
$form = array( 'email' => 'Anna@Esempio.it', 'name' => 'Anna', 'org_name' => 'Circolo San Giorgio', 'slug' => 'circolo-san-giorgio', 'plan' => 'gestionale', 'period' => 'month', 'members' => 150, 'addons' => array(), 'promo' => 'PRIMAVERA', 'provider' => 'stripe' );
$cseen = array();
check( 'acquisto: totale visto diverso dal listino → si rivede il prezzo, nessun ordine aperto', null !== throwsUser( function () use ( $cdb, $cbill, $form ) { SaasCheckout::start( $cdb, $cbill, $form + array( 'seen_total' => 1 ) ); } ) && 0 === (int) $cdb->one( 'SELECT COUNT(*) n FROM orders' )['n'] );
$url = SaasCheckout::start( $cdb, $cbill, $form + array( 'seen_total' => 3221 ) );
$ord = $cdb->one( 'SELECT * FROM orders' );
$stripeForm = array();
foreach ( $cseen as $c ) {
	if ( 'stripe' === $c[0] ) {
		parse_str( (string) $c[1], $stripeForm );
	}
}
check( 'acquisto: Stripe con importo del preventivo (3300 - 20% + IVA = 3221)', 'https://checkout.stripe.test/s1' === $url && '3221' === (string) ( $stripeForm['line_items'][0]['price_data']['unit_amount'] ?? '' ) && $ord['id'] === $stripeForm['client_reference_id'] );
check( 'acquisto: ordine con istantanea, associazione e metodo', 'pending' === $ord['status'] && 3 === (int) $ord['plan_version'] && 'stripe' === $ord['provider'] && 1 === (int) $cdb->one( 'SELECT COUNT(*) n FROM saas_orgs WHERE ref = ?', array( $ord['org_ref'] ) )['n'] );
$cseen = array();
check( 'pagamento: id che non è un ordine del gestionale → non è affar nostro', false === SaasCheckout::paid( $cdb, 'nonesiste', 'sub_1', 'cus_1' ) );
check( 'pagamento riuscito: associazione creata, promo consumata, subscription in coda', true === SaasCheckout::paid( $cdb, $ord['id'], 'sub_1', 'cus_1', 1000 ) );
$types = array_map( function ( $c ) { return $c[0]; }, $cseen );
check( 'pagamento: provision → promo_redeem, una volta', array( 'provision', 'promo_redeem' ) === $types );
$prov = $cseen[0][1];
check( 'provision: ref stabile, nome, sottodominio e titolare', $ord['org_ref'] === $prov['ref'] && 'Circolo San Giorgio' === $prov['org']['name'] && 'circolo-san-giorgio' === $prov['org']['slug'] && 'anna@esempio.it' === $prov['owner']['email'] );
$qrow = json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox' )['body'], true );
check( 'subscription in coda: rev 1, plan_version dell\'ordine, scadenza, promo e prezzo netto', 1 === $qrow['rev'] && 'active' === $qrow['status'] && 3 === $qrow['plan_version'] && 150 === $qrow['members'] && 'PRIMAVERA' === $qrow['promo'] && 2640 === $qrow['price_cents'] && gmdate( 'Y-m-d', strtotime( '+1 month', 1000 ) ) === $qrow['until'] && $ord['org_ref'] === $qrow['ref'] );
$cseen = array();
SaasCheckout::paid( $cdb, $ord['id'], 'sub_1', 'cus_1', 1100 );
check( 'pagamento ripetuto (webhook doppio): nessun effetto in più', array() === $cseen && 1 === (int) $cdb->one( 'SELECT COUNT(*) n FROM saas_outbox' )['n'] && 'active' === $cdb->one( 'SELECT status FROM orders' )['status'] );
check( 'rinnovo: nuova subscription con rev 2 e scadenza spostata', true === SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'renewed', 5000000 ) && 2 === json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 2' )['body'], true )['rev'] );
SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'failed', 5000100 );
$pd = json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 3' )['body'], true );
check( 'pagamento non riuscito: past_due, scadenza invariata', 'past_due' === $pd['status'] && gmdate( 'Y-m-d', strtotime( '+1 month', 5000000 ) ) === $pd['until'] );
SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'cancelled', 5000200 );
check( 'disdetta: cancelled; riferimento sconosciuto → non è nostro', 'cancelled' === json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 4' )['body'], true )['status'] && false === SaasCheckout::event( $cdb, 'stripe', 'altro', 'renewed' ) );
// Sottodominio già preso: ne prova un altro; provision fallita: l'ordine resta «paid» e il webhook riprova.
$provCode = 409;
$f2 = array( 'promo' => '', 'org_name' => 'Altro Circolo', 'slug' => 'altro' ) + $form;
SaasCheckout::start( $cdb, $cbill, $f2 );
$o2 = $cdb->one( "SELECT * FROM orders WHERE status = 'pending'" );
$err = null;
try {
	SaasCheckout::paid( $cdb, $o2['id'], 'sub_2', 'cus_1', 6000 );
} catch ( \RuntimeException $e ) {
	$err = $e->getMessage();
}
check( 'provision senza esito → eccezione da ritentare, ordine pagato ma non attivo', null !== $err && 'paid' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $o2['id'] ) )['status'] );
$provCode = 201;
SaasCheckout::paid( $cdb, $o2['id'], 'sub_2', 'cus_1', 6100 );
check( 'ritento riuscito: ordine attivo con subscription in coda', 'active' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $o2['id'] ) )['status'] && 5 === (int) $cdb->one( 'SELECT COUNT(*) n FROM saas_outbox' )['n'] );
Config::set( 'stripe.secret_key', '' );
check( 'senza provider di pagamento non si apre l\'ordine', null !== throwsUser( function () use ( $cdb, $cbill, $form ) { SaasCheckout::start( $cdb, $cbill, $form ); } ) );

Http::$transport = $prevTransport;
Config::set( 'saas_public_keys', array() );
echo $fails ? "\n$fails prove fallite\n" : "\nTutte le prove sono passate\n";
exit( $fails ? 1 : 0 );

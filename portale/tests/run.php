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
/**
 * Database di prova nuovo a ogni chiamata: SQLite in memoria; con PORTALE_TEST_PG_DSN (es. «pgsql:host=127.0.0.1;dbname=portale_test») lo stesso
 * insieme di prove gira su PostgreSQL, ogni database di prova in uno schema nuovo.
 */
function testDb(): Db {
	static $n = 0;
	$dsn = (string) getenv( 'PORTALE_TEST_PG_DSN' );
	if ( '' === $dsn ) {
		return new Db( new PDO( 'sqlite::memory:' ) );
	}
	$pdo    = new PDO( $dsn, (string) getenv( 'PORTALE_TEST_PG_USER' ) ?: null, (string) getenv( 'PORTALE_TEST_PG_PASS' ) ?: null );
	$schema = 't' . getmypid() . '_' . ( ++$n );
	$pdo->exec( 'CREATE SCHEMA ' . $schema );
	$pdo->exec( 'SET search_path TO ' . $schema );
	return new Db( $pdo );
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

$db = testDb();
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
// Codice promo all'acquisto: sconto sul pagamento singolo, uso confermato solo a pagamento riuscito.
$pcodes = array_column( ( new Portale\Plugin\ConfigCatalog() )->document()['plans'], 'code' );
Portale\Plugin\Promos::save( $db, Portale\Plugin\Promos::validate( array( 'code' => 'CHECKOUT20', 'kind' => 'percent', 'value' => 20, 'ends_on' => '2099-12-31', 'max_uses' => 5 ), $pcodes ) );
check( 'codice all\'acquisto: col rinnovo automatico è rifiutato', null !== throwsUser( function () use ( $billing ) { $billing->startPaid( 'pia@esempio.it', 'pia.it', 'full', 'year', 'stripe', 'recurring', null, 'CHECKOUT20' ); } ) );
check( 'codice all\'acquisto: codice sconosciuto rifiutato', null !== throwsUser( function () use ( $billing ) { $billing->startPaid( 'pia@esempio.it', 'pia.it', 'full', 'year', 'stripe', 'once', null, 'NOPE' ); } ) );
$n = count( $calls );
$billing->startPaid( 'pia@esempio.it', 'Pia.it', 'full', 'year', 'stripe', 'once', null, ' checkout20 ' );
$pd = $db->one( "SELECT * FROM subscriptions WHERE domain = 'pia.it'" );
$po = $db->one( 'SELECT * FROM plugin_promo_orders WHERE sub_id = ?', array( $pd['id'] ) );
$pfull = (int) Portale\Config::price( 'full', 'year' )['cents'];
$pbody = '';
foreach ( array_slice( $calls, $n ) as $c ) { $pbody .= (string) ( is_array( $c[2] ) ? http_build_query( $c[2] ) : $c[2] ); }
check( 'codice all\'acquisto: ordine registrato con lo sconto del 20%', $po && 'CHECKOUT20' === $po['code'] && (int) round( $pfull * 0.2 ) === (int) $po['discount_cents'] );
check( 'codice all\'acquisto: a Stripe va l\'importo scontato', false !== strpos( $pbody, (string) ( $pfull - (int) $po['discount_cents'] ) ) );
check( 'codice all\'acquisto: prima del pagamento nessun uso consumato', 0 === (int) Portale\Plugin\Promos::get( $db, 'CHECKOUT20' )['used'] );
$billing->completePayment( $pd['id'], 'pi_promo' );
$billing->completePayment( $pd['id'], 'pi_promo' );
check( 'codice all\'acquisto: a pagamento riuscito l\'uso si conta una volta sola', 1 === (int) Portale\Plugin\Promos::get( $db, 'CHECKOUT20' )['used'] );

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
$lv = Portale\Legal::vars();
check( 'legale: segnaposto dei piani nuovi e vecchi → stesso nome del pannello', $lv['piano_contabile'] === $lv['piano_lite'] && $lv['piano_fiscale'] === $lv['piano_full'] && '' !== $lv['piano_gratuito'] && false === strpos( Portale\Legal::html( Portale\Legal::body( 'termini' ) ), '{piano_' ) );
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
$pdb    = testDb();
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
	$rdb = testDb();
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
$adb = testDb();
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
$rdb = testDb();
$rdb->migrate();
$took = 0;
for ( $i = 0; $i < 6; $i++ ) {
	$took += Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) ? 1 : 0;
}
check( 'limite: al massimo tre posti in un\'ora, anche con tante richieste', 3 === $took );
Portale\RateLimit::give_back( $rdb, 'prova', '2026110100' );
check( 'limite: un posto restituito si può riusare, un solo posto', Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) && ! Portale\RateLimit::take( $rdb, 'prova', '2026110100', 3, 1793000000 ) );
$mdb = testDb();
$mdb->migrate();
$mdb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'c9', 'ok@esempio.it', Db::now() ) );
$mauth = new Portale\Auth( $mdb );
$mauth->setPassword( 'c9', 'una password lunga abbastanza', 'ok@esempio.it' );
for ( $i = 0; $i < 3; $i++ ) {
	$mauth->loginWithPassword( 'ok@esempio.it', 'una password lunga abbastanza', '8.8.8.8' );
}
check( 'password: gli accessi riusciti non consumano il limite dell\'indirizzo', 'c9' === $mauth->loginWithPassword( 'ok@esempio.it', 'una password lunga abbastanza', '8.8.8.8' )['id'] && 0 === (int) ( $mdb->one( 'SELECT n FROM rate_limits WHERE bucket = ?', array( 'login:i:' . hash( 'sha256', '8.8.8.8' ) ) )['n'] ?? 0 ) );

// --- Eliminazione di clienti e licenze --------------------------------------------------------
$ddb = testDb();
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
$sdb = testDb();
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
$mdb  = testDb();
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
$fdb  = testDb();
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
$ldb = testDb();
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
$kdb  = testDb();
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
$pdb2 = testDb();
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
$mdb2 = testDb();
$mdb2->migrate();
$mdb2->run( 'INSERT INTO settings (name, val) VALUES (?,?)', array( 'secrets', json_encode( array( 'stripe_secret' => Portale\Secrets::seal( $k['test'] ), 'stripe_webhook' => Portale\Secrets::seal( 'whsec_OLDOLDOLD12' ) ) ) ) );
Portale\Settings::load( $mdb2 );
$st2 = Portale\Settings::secretsStatus( $mdb2 );
check( 'migrazione: chiave Stripe di prova del vecchio salvataggio finisce nel gruppo di prova', ! $st2['stripe_secret']['set'] && $st2['stripe_secret_test']['set'] && $st2['stripe_webhook_test']['set'] && false === Portale\Settings::stripeLive() );


// --- Dominio registrabile: la licenza vale per i sottodomini ----------------------------------
check( 'dominio: staging, test e clone ricadono nel dominio registrabile', array( 'esempio.it', 'esempio.it', 'esempio.it', 'esempio.it' ) === array_map( array( 'Portale\Domain', 'forLicense' ), array( 'staging.esempio.it', 'https://test.esempio.it/wp-admin', 'www.clone.esempio.it', 'esempio.it/staging' ) ) );
check( 'dominio: suffissi a più etichette (co.uk)', 'esempio.co.uk' === Domain::forLicense( 'staging.esempio.co.uk' ) && '' === Domain::forLicense( 'localhost' ) );
$rdb2 = testDb();
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
$tdb  = testDb();
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
$cdb = testDb();
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
	$vdb = testDb();
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
	$udb = testDb();
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
$sdb2 = testDb();
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
$ndb = testDb();
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
	$gdb = testDb();
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
	// Sostituzione eccezionale di una versione già pubblicata (stesso numero)
	$rzdir = Portale\Releases::dir( 'pro' );
	$oldsha = Portale\Releases::get( $gdb, 'pro', '1.0.1' )['sha256'];
	$z1b = $mkz( '1.0.1' );
	$zz  = new ZipArchive();
	$zz->open( $z1b );
	$zz->addFromString( 'associazionesemplice-pro/extra.txt', 'cambiato' );
	$zz->close();
	$sig1b = $b64u( sodium_crypto_sign_detached( Portale\Releases::signedMessage( 'pro', '1.0.1', hash_file( 'sha256', $z1b ) ), $sk ) );
	check( 'sostituzione: senza firma rifiutata', null !== throwsUser( function () use ( $gdb, $z1b ) { Portale\Releases::replace( $gdb, 'pro', $z1b, '', 'test', '', 'k-test' ); } ) );
	check( 'sostituzione: firma di un altro pacchetto rifiutata', null !== throwsUser( function () use ( $gdb, $z1b, $sig ) { Portale\Releases::replace( $gdb, 'pro', $z1b, '', 'test', $sig, 'k-test' ); } ) );
	check( 'sostituzione: versione non ancora pubblicata rifiutata', null !== throwsUser( function () use ( $gdb, $mkz, $b64u, $sk ) { $z = $mkz( '7.7.7' ); Portale\Releases::replace( $gdb, 'pro', $z, '', 'test', $b64u( sodium_crypto_sign_detached( Portale\Releases::signedMessage( 'pro', '7.7.7', hash_file( 'sha256', $z ) ), $sk ) ), 'k-test' ); } ) );
	check( 'sostituzione: pacchetto identico rifiutato', null !== throwsUser( function () use ( $gdb, $z1, $sig ) { Portale\Releases::replace( $gdb, 'pro', $z1, '', 'test', $sig, 'k-test' ); } ) );
	$rep = Portale\Releases::replace( $gdb, 'pro', $z1b, '', 'test', $sig1b, 'k-test' );
	$now1 = Portale\Releases::get( $gdb, 'pro', '1.0.1' );
	check( 'sostituzione: impronta, firma e file nuovi, stesso numero', $now1['sha256'] === hash_file( 'sha256', $z1b ) && $sig1b === $now1['signature'] && $rep['sha256'] === $now1['sha256'] && hash_file( 'sha256', $rzdir . '/1.0.1.zip' ) === $now1['sha256'] );
	check( 'sostituzione: il vecchio zip resta conservato e non è scaricabile', is_file( $rzdir . '/1.0.1.zip.' . substr( $oldsha, 0, 8 ) . '.replaced' ) && hash_file( 'sha256', $rzdir . '/1.0.1.zip.' . substr( $oldsha, 0, 8 ) . '.replaced' ) === $oldsha );
	check( 'sostituzione: registrata nell\'audit', null !== $gdb->one( "SELECT 1 AS x FROM audit WHERE action = 'release.replace'" ) );
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
$pdb = testDb();
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

// --- Regime IVA dei prezzi del plugin --------------------------------------------------------------------------------------
Portale\Settings::load( $pdb );
check( 'iva: non scelta, le pagine non dicono nulla', '' === Portale\Settings::vatLine( true ) && '' === Portale\Settings::vat()['mode'] );
check( 'iva: senza IVA richiede la dicitura', null !== throwsUser( function () use ( $pdb ) {
	Portale\Settings::saveVat( $pdb, array( 'vat_mode' => 'exempt', 'vat_note' => '  ' ) );
} ) );
check( 'iva: regime sconosciuto rifiutato', null !== throwsUser( function () use ( $pdb ) {
	Portale\Settings::saveVat( $pdb, array( 'vat_mode' => 'boh' ) );
} ) );
Portale\Settings::saveVat( $pdb, array( 'vat_mode' => 'exempt', 'vat_note' => 'Operazione senza IVA ai sensi della legge 190/2014.' ) );
Portale\Settings::load( $pdb );
check( 'iva: senza IVA con dicitura', 'Prezzi in euro, senza IVA. Operazione senza IVA ai sensi della legge 190/2014.' === Portale\Settings::vatLine( true ) && 'Prezzi in euro, senza IVA.' === Portale\Settings::vatLine() );
Portale\Settings::saveVat( $pdb, array( 'vat_mode' => 'included', 'vat_note' => 'ignorata' ) );
Portale\Settings::load( $pdb );
check( 'iva: inclusa, la dicitura non conta', 'Prezzi in euro, IVA inclusa.' === Portale\Settings::vatLine( true ) && '' === Portale\Settings::vat()['note'] );
Portale\Settings::saveVat( $pdb, array( 'vat_mode' => '' ) );
Portale\Settings::load( $pdb );
check( 'iva: scelta vuota la toglie', '' === Portale\Settings::vatLine( true ) );

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
check( 'demo: segreteria, socio e amministratore del plugin, nessun «admin» di WordPress', array( 'segreteria@demo.invalid', 'socio@demo.invalid', 'amministratore@demo.invalid' ) === array_column( $demoAcc, 'user' ) && ! preg_match( '/admin/i', implode( ' ', array_column( $demoAcc, 'user' ) ) ) );
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
$adb   = testDb();
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
$sdb = testDb();
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
$rdb2 = testDb();
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
$adb = testDb();
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
$mdb = testDb();
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
$ndb   = testDb();
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
$wdb = testDb();
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
$kdb = testDb();
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
Config::set( 'saas_admin_url', 'https://app.associazionesemplice.it/admin' );
check( 'sezioni: /saas/accedi diventa /account/da-gestionale con lo stesso token', array( 301, '/account/da-gestionale?t=abc' ) === Sections::legacy( 'GET', '/saas/accedi', 't=abc' ) );
check( 'sezioni: /saas/prezzi rimanda al listino del gestionale in /prezzi', array( 301, '/prezzi#prezzi-saas' ) === Sections::legacy( 'GET', '/saas/prezzi', '' ) );
$prevStripe = Config::get( 'stripe' );
$prevPaypal = Config::get( 'paypal' );
Config::set( 'stripe', array( 'secret_key' => '' ) );
Config::set( 'paypal', array( 'client_id' => '', 'secret' => '' ) );
check( 'vendita: senza chiavi dei pagamenti niente «Scegli» (paymentsOn falso)', false === Portale\Billing::paymentsOn() );
Config::set( 'stripe', array( 'secret_key' => 'sk_test_x' ) );
check( 'vendita: con Stripe configurato i pagamenti sono attivi', true === Portale\Billing::paymentsOn() );
Config::set( 'stripe', $prevStripe ); Config::set( 'paypal', $prevPaypal );
check( 'sezioni: /saas/accedi con POST conserva il metodo (308)', 308 === Sections::legacy( 'POST', '/saas/accedi', '' )[0] );
check( 'sezioni: /saas/admin va all\'amministrazione del SaaS (temporaneo)', array( 302, 'https://app.associazionesemplice.it/admin' ) === Sections::legacy( 'GET', '/saas/admin', '' ) );
check( 'sezioni: /saas/admin/percorso mantiene il resto e la query', array( 302, 'https://app.associazionesemplice.it/admin/associazioni?q=a' ) === Sections::legacy( 'GET', '/saas/admin/associazioni', 'q=a' ) );
Config::set( 'saas_admin_url', 'http://non-sicuro.test/admin' );
check( 'sezioni: indirizzo di amministrazione non https ignorato', 'https://app.associazionesemplice.it/admin' === Sections::saasAdminUrl() );
Config::set( 'saas_admin_url', 'https://gestione.esempio.it/admin/' );
check( 'sezioni: indirizzo di amministrazione configurabile', 'https://gestione.esempio.it/admin/x' === Sections::legacy( 'GET', '/saas/admin/x', '' )[1] );
check( 'sezioni: vecchia pagina di un\'associazione → /saas/admin (301)', array( 301, '/saas/admin' ) === Sections::legacy( 'GET', '/saas/11111111-2222-3333-4444-555555555555', '' ) );
check( 'sezioni: vecchio /saas/utenti/… → /saas/admin (308 per POST)', array( 308, '/saas/admin' ) === Sections::legacy( 'POST', '/saas/utenti/abc', '' ) );
check( 'sezioni: /wordpress/confronto rimanda a /confronto (301, 308 per POST, query conservata)', array( 301, '/confronto' ) === Sections::legacy( 'GET', '/wordpress/confronto', '' ) && 308 === Sections::legacy( 'POST', '/wordpress/confronto', '' )[0] && array( 301, '/confronto?x=1' ) === Sections::legacy( 'GET', '/wordpress/confronto', 'x=1' ) );
check( 'sezioni: /saas/acquista non è un vecchio indirizzo', null === Sections::legacy( 'GET', '/saas/acquista', 'plan=gestionale' ) );
check( 'sezioni: con saas_admin_url vuoto /saas/admin non è più rimandato (lo serve il SaaS)', ( function () { Config::set( 'saas_admin_url', '' ); $r = Sections::legacy( 'GET', '/saas/admin/utenti', '' ); Config::set( 'saas_admin_url', 'https://app.associazionesemplice.it/admin' ); return $r; } )() === null );
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
$cdb = testDb();
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
// Condizioni di vendita del catalogo (`terms`): partenza, regime forfettario, valori non validi
$tdef = Portale\Catalog::terms( $qdoc );
check( 'condizioni: senza `terms` valgono i valori di partenza (IVA standard, 0%/3%, prova senza esportazioni né commercialista)', 'standard' === $tdef['vat_mode'] && 0 === $tdef['own_gateway_percent'] && 3 === $tdef['collected_by_us_percent'] && false === $tdef['trial_exports'] && false === $tdef['trial_accountant_access'] && 'standard' === $q150['vat_mode'] );
$xdoc = $qdoc;
$xdoc['terms'] = array( 'vat' => array( 'mode' => 'exempt', 'note' => 'Operazione senza IVA.' ), 'payments' => array( 'own_gateway_percent' => 1, 'collected_by_us_percent' => 99 ), 'trial' => array( 'exports' => true ) );
$qx = Quote::build( $xdoc, 'gestionale', 'month', 150, array( 'operators_extra' => 2 ) );
check( 'condizioni: regime forfettario = nessuna IVA, totale = importo dei prezzi, dicitura nell\'istantanea (vat_percent del catalogo ignorato)', 3900 === $qx['total'] && 0 === $qx['vat'] && 0 === $qx['vat_percent'] && 'exempt' === $qx['vat_mode'] && 'Operazione senza IVA.' === $qx['vat_note'] );
$tx = Portale\Catalog::terms( $xdoc );
check( 'condizioni: commissione fuori da 0–30 torna al valore di partenza, prova con esportazioni', 1 === $tx['own_gateway_percent'] && 3 === $tx['collected_by_us_percent'] && true === $tx['trial_exports'] );
$xdoc['terms']['vat']['note'] = '';
check( 'condizioni: «exempt» senza dicitura non si applica (resta l\'IVA)', 'standard' === Portale\Catalog::terms( $xdoc )['vat_mode'] && 4026 + 732 === Quote::build( $xdoc, 'gestionale', 'month', 150, array( 'operators_extra' => 2 ) )['total'] );
check( 'condizioni: frase sulla prova dalle regole del catalogo', 'tutto tranne le esportazioni e l\'accesso del commercialista' === Portale\Catalog::trialLimits( $qdoc ) );
check( 'preventivo: annuale = 10 mensilità, piano o addon sconosciuti rifiutati', 33000 === Quote::build( $qdoc, 'gestionale', 'year', 150 )['net'] && null !== throwsUser( function () use ( $qdoc ) { Quote::build( $qdoc, 'altro', 'month', 10 ); } ) && null !== throwsUser( function () use ( $qdoc ) { Quote::build( $qdoc, 'gestionale', 'month', 10, array( 'zzz' => 1 ) ); } ) );

$oseed = str_repeat( 'o', 32 );
Config::set( 'saas_public_keys', array( PortalAttestation::kid( $oseed ) => PortalAttestation::publicKey( $oseed ) ) );
$odb = testDb();
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
$odb2 = testDb();
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
$cdb = testDb();
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
$mails = array();
check( 'pagamento: id che non è un ordine del gestionale → non è affar nostro', false === SaasCheckout::paid( $cdb, 'nonesiste', 'sub_1', 'cus_1' ) );
check( 'pagamento riuscito: associazione creata, promo consumata, subscription in coda', true === SaasCheckout::paid( $cdb, $ord['id'], 'sub_1', 'cus_1', 1000 ) );
check( 'email gestionale: benvenuto e «pronto» alla cliente, con associazione, piano del catalogo e supporto, senza nome → «Ciao,»', 2 === count( $mails ) && 'anna@esempio.it' === $mails[0][0] && 'anna@esempio.it' === $mails[1][0] && false !== strpos( $mails[0][2], 'Circolo San Giorgio' ) && false === strpos( $mails[0][2], '{' ) && false === strpos( $mails[1][2], '{' ) );
$mails = array();
$types = array_map( function ( $c ) { return $c[0]; }, $cseen );
check( 'pagamento: provision → promo_redeem, una volta', array( 'provision', 'promo_redeem' ) === $types );
$prov = $cseen[0][1];
check( 'provision: ref stabile, nome, sottodominio e titolare', $ord['org_ref'] === $prov['ref'] && 'Circolo San Giorgio' === $prov['org']['name'] && 'circolo-san-giorgio' === $prov['org']['slug'] && 'anna@esempio.it' === $prov['owner']['email'] );
$qrow = json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox' )['body'], true );
check( 'subscription in coda: rev 1, plan_version dell\'ordine, scadenza, promo e prezzo netto', 1 === $qrow['rev'] && 'active' === $qrow['status'] && 3 === $qrow['plan_version'] && 150 === $qrow['members'] && 'PRIMAVERA' === $qrow['promo'] && 2640 === $qrow['price_cents'] && gmdate( 'Y-m-d', strtotime( '+1 month', 1000 ) ) === $qrow['until'] && $ord['org_ref'] === $qrow['ref'] );
$cseen = array();
SaasCheckout::paid( $cdb, $ord['id'], 'sub_1', 'cus_1', 1100 );
check( 'pagamento ripetuto (webhook doppio): nessuna email in più', array() === $mails );
check( 'pagamento ripetuto (webhook doppio): nessun effetto in più', array() === $cseen && 1 === (int) $cdb->one( 'SELECT COUNT(*) n FROM saas_outbox' )['n'] && 'active' === $cdb->one( 'SELECT status FROM orders' )['status'] );
check( 'rinnovo: nuova subscription con rev 2 e scadenza spostata', true === SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'renewed', 5000000 ) && 2 === json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 2' )['body'], true )['rev'] );
check( 'email rinnovo: una sola, alla cliente', 1 === count( $mails ) && 'anna@esempio.it' === $mails[0][0] );
SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'failed', 5000100 );
check( 'email pagamento non riuscito: inviata', 2 === count( $mails ) && 'anna@esempio.it' === $mails[1][0] && false === strpos( $mails[1][2], '{' ) );
$pd = json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 3' )['body'], true );
check( 'pagamento non riuscito: past_due, scadenza invariata', 'past_due' === $pd['status'] && gmdate( 'Y-m-d', strtotime( '+1 month', 5000000 ) ) === $pd['until'] );
// «Riattiva» con pagamento non riuscito (Stripe): paga la fattura aperta con il metodo salvato, una volta sola.
$rrow    = array( 'state' => 'past_due', 'status' => 'active', 'until' => '2099-01-01', 'total' => 3221, 'provider' => 'stripe', 'plan' => 'x', 'period' => 'month', 'members' => 1, 'quote' => '{}' );
$reSeen  = array();
$reInv   = array( 'status' => 'open', 'id' => 'in_1' );
$reSub   = array( 'status' => 'past_due' );
$reCode  = 200;
$reBody  = '{}';
$reTrans = Http::$transport;
Http::$transport = function ( $m, $u, $h, $b ) use ( &$reSeen, &$reInv, &$reSub, &$reCode, &$reBody ) {
	$reSeen[] = array( $m, $u );
	if ( 'GET' === $m ) {
		return array( 200, json_encode( $reSub + array( 'latest_invoice' => $reInv ) ) );
	}
	return array( $reCode, $reBody );
};
check( 'riattiva (Stripe): fattura aperta → pagata con il metodo salvato', 'paid' === Stripe::payOpenInvoice( 'sub_1' ) && 'POST' === $reSeen[1][0] && false !== strpos( $reSeen[1][1], '/invoices/in_1/pay' ) );
$reSeen = array();
$reInv  = array( 'status' => 'paid', 'id' => 'in_1' );
check( 'riattiva (Stripe): premuto due volte, fattura già pagata → nessun secondo addebito', 'paid' === Stripe::payOpenInvoice( 'sub_1' ) && 1 === count( $reSeen ) );
$reInv  = array( 'status' => 'open', 'id' => 'in_2' );
$reCode = 402;
check( 'riattiva (Stripe): carta rifiutata → declined', 'declined' === Stripe::payOpenInvoice( 'sub_1' ) );
$reCode = 200;
$reSub  = array( 'status' => 'canceled' );
check( 'riattiva (Stripe): abbonamento chiuso → non c\'è nulla da pagare qui', 'unavailable' === Stripe::payOpenInvoice( 'sub_1' ) );
$reSub  = array( 'status' => 'past_due' );
$reOrd  = $cdb->one( 'SELECT * FROM orders WHERE id = ?', array( $ord['id'] ) );
$reSeen = array();
check( 'riattiva: importo cambiato rispetto a quello mostrato → non si addebita nulla', null !== throwsUser( function () use ( $cdb, $cbill, $reOrd ) { SaasCheckout::reactivate( $cdb, $cbill, $reOrd, 1 ); } ) && array() === $reSeen );
$reSeen = array();
check( 'riattiva: pagamento riuscito → nessun indirizzo da aprire', null === SaasCheckout::reactivate( $cdb, $cbill, $reOrd, (int) json_decode( (string) $reOrd['quote'], true )['total'] ) && 'POST' === $reSeen[1][0] );
$reCode = 402;
check( 'riattiva: rifiuto della carta → messaggio per il cliente', null !== throwsUser( function () use ( $cdb, $cbill, $reOrd ) { SaasCheckout::reactivate( $cdb, $cbill, $reOrd ); } ) );
// Nuovi tentativi dopo un rifiuto: 3 al giorno nei primi 2 giorni, poi 1 al giorno per altri 5 (11 in 7 giorni), mai dopo un rifiuto definitivo.
$rt = function () use ( $cdb, $reOrd ) { return $cdb->one( 'SELECT retry_from, retry_n, retry_at FROM orders WHERE id = ?', array( $reOrd['id'] ) ); };
check( 'tentativi: programmati al primo rifiuto (primo dopo 8 ore), 11 in 7 giorni', 5000100 + 8 * 3600 === strtotime( $rt()['retry_at'] . ' UTC' ) && 0 === (int) $rt()['retry_n'] && 11 === count( SaasCheckout::RETRY_HOURS ) && 168 === max( SaasCheckout::RETRY_HOURS ) && 6 === count( array_filter( SaasCheckout::RETRY_HOURS, function ( $h ) { return $h <= 48; } ) ) );
$mailsBefore = count( $mails );
SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'failed', 5000200 );
check( 'tentativi: un altro «non riuscito» durante i tentativi → niente email in più né nuova programmazione', count( $mails ) === $mailsBefore && 0 === (int) $rt()['retry_n'] && 5000100 === strtotime( $rt()['retry_from'] . ' UTC' ) );
$due = 5000100 + 8 * 3600;
$reSeen = array();
check( 'tentativi: spenti finché non si accende saas_retry', 0 === SaasCheckout::retryPayments( $cdb, $due ) && array() === $reSeen );
Config::set( 'saas_retry', true );
check( 'tentativi: prima dell\'ora prevista non succede nulla', 0 === SaasCheckout::retryPayments( $cdb, $due - 60 ) && array() === $reSeen );
$reCode = 402;
$reBody = json_encode( array( 'error' => array( 'code' => 'card_declined', 'decline_code' => 'insufficient_funds' ) ) );
check( 'tentativi: rifiuto normale → riprogrammato alle 16 ore dal primo rifiuto', 1 === SaasCheckout::retryPayments( $cdb, $due ) && 1 === (int) $rt()['retry_n'] && 5000100 + 16 * 3600 === strtotime( $rt()['retry_at'] . ' UTC' ) );
$cdb->run( 'UPDATE orders SET retry_n = 10, retry_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $due ), $reOrd['id'] ) );
SaasCheckout::retryPayments( $cdb, $due );
check( 'tentativi: dopo l\'undicesimo si smette', null === $rt()['retry_at'] );
$cdb->run( 'UPDATE orders SET retry_n = 2, retry_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $due ), $reOrd['id'] ) );
$reBody = json_encode( array( 'error' => array( 'code' => 'card_declined', 'decline_code' => 'stolen_card' ) ) );
SaasCheckout::retryPayments( $cdb, $due );
check( 'tentativi: carta rubata (rifiuto definitivo) → mai più', null === $rt()['retry_at'] );
$cdb->run( 'UPDATE orders SET retry_n = 2, retry_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $due ), $reOrd['id'] ) );
$reBody = json_encode( array( 'error' => array( 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'advice_code' => 'do_not_try_again' ) ) );
SaasCheckout::retryPayments( $cdb, $due );
check( 'tentativi: la banca dice «non riprovare» → mai più', null === $rt()['retry_at'] );
$cdb->run( 'UPDATE orders SET retry_n = 2, retry_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $due ), $reOrd['id'] ) );
$reCode = 200;
$reBody = '{}';
SaasCheckout::retryPayments( $cdb, $due );
check( 'tentativi: pagamento riuscito → programma azzerato', null === $rt()['retry_at'] );
Config::set( 'saas_retry', false );
Http::$transport = $reTrans;
// Nuovo ordine per la stessa associazione (Riattiva dopo la scadenza): niente benvenuto, ordine vecchio sostituito, gestionale attivo.
$reNew = Orders::open( $cdb, (string) $reOrd['customer_id'], (string) $reOrd['plan'], (string) $reOrd['period'], (int) $reOrd['members'], Orders::addonsOf( (array) json_decode( (string) $reOrd['quote'], true ) ), null );
$cdb->run( 'UPDATE orders SET org_ref = ?, provider = ? WHERE id = ?', array( $reOrd['org_ref'], 'stripe', $reNew['id'] ) );
$mails = array();
SaasCheckout::paid( $cdb, $reNew['id'], 'sub_9', 'cus_1', 5000300 );
check( 'riattiva (nuovo ordine): una sola email di conferma, nessun benvenuto; ordine vecchio sostituito; stessa associazione', 1 === count( $mails ) && false !== stripos( $mails[0][1], 'rinnovo' ) && 'replaced' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $reOrd['id'] ) )['status'] && 'active' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $reNew['id'] ) )['status'] && 'active' === Portale\SaasAccount::stateOf( $cdb, (string) $cdb->one( 'SELECT org_id FROM saas_orgs WHERE ref = ?', array( $reOrd['org_ref'] ) )['org_id'] ) );
$cdb->run( "UPDATE orders SET status = 'active' WHERE id = ?", array( $reOrd['id'] ) );
$reOrgId = (string) $cdb->one( 'SELECT org_id FROM saas_orgs WHERE ref = ?', array( $reOrd['org_ref'] ) )['org_id'];
$cdb->run( 'DELETE FROM saas_outbox WHERE org_id = ? AND rev = 4', array( $reOrgId ) );
$cdb->run( 'UPDATE saas_orgs SET rev = 3 WHERE ref = ?', array( $reOrd['org_ref'] ) );
$cdb->run( 'DELETE FROM orders WHERE id = ?', array( $reNew['id'] ) );
$mails = array();
check( 'riattiva: il pulsante c\'è se in ritardo col pagamento o scaduto, non se tutto è in regola', null !== Portale\SaasAccount::reactivation( $rrow ) && true === Portale\SaasAccount::reactivation( $rrow )['pay_now'] && null === Portale\SaasAccount::reactivation( array( 'state' => 'active' ) + $rrow ) && null !== Portale\SaasAccount::reactivation( array( 'state' => 'active', 'until' => '2020-01-01', 'provider' => 'paypal' ) + $rrow, null, 1700000000 ) && null === Portale\SaasAccount::reactivation( array( 'state' => 'pending' ) + $rrow ) );
check( 'riattiva: dati conservati 180 giorni dal giorno dopo la scadenza', '2020-06-30' === Portale\SaasAccount::reactivation( array( 'until' => '2020-01-01', 'state' => 'suspended' ) + $rrow )['delete_on'] );
SaasCheckout::event( $cdb, 'stripe', 'sub_1', 'cancelled', 5000200 );
check( 'disdetta: cancelled; riferimento sconosciuto → non è nostro', 'cancelled' === json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox WHERE rev = 4' )['body'], true )['status'] && false === SaasCheckout::event( $cdb, 'stripe', 'altro', 'renewed' ) );
// Email di scadenza e sospensione (lavoro giornaliero): una per scadenza, niente a sorpresa per le scadenze vecchie.
$lcUntil = strtotime( json_decode( (string) $cdb->one( 'SELECT body FROM saas_outbox ORDER BY rev DESC LIMIT 1' )['body'], true )['until'] . ' UTC' );
$mails = array();
check( 'scadenza: senza disdetta niente avviso', 0 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil - 5 * 86400 ) && array() === $mails );
$cdb->run( 'UPDATE orders SET cancel_at_period_end = 1 WHERE id = ?', array( $ord['id'] ) );
check( 'scadenza: con disdetta e scadenza vicina, una email sola', 1 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil - 5 * 86400 ) && 0 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil - 4 * 86400 ) && 1 === count( $mails ) && 'anna@esempio.it' === $mails[0][0] && false === strpos( $mails[0][2], '{' ) );
$mails = array();
check( 'sospensione: il giorno dopo la scadenza, una email sola, con la data di cancellazione a 180 giorni', 1 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil + 86400 ) && 0 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil + 2 * 86400 ) && 1 === count( $mails ) && false !== strpos( $mails[0][2], date_it( gmdate( 'Y-m-d', $lcUntil + 181 * 86400 ) . ' 00:00:00' ) ) && false === strpos( $mails[0][2], '{' ) );
$cdb->run( 'UPDATE orders SET suspended_for = NULL WHERE id = ?', array( $ord['id'] ) );
$mails = array();
check( 'sospensione: scadenza di molti giorni fa → niente email a sorpresa', 0 === SaasCheckout::sendLifecycleMails( $cdb, $lcUntil + 10 * 86400 ) && array() === $mails );
// Fine della prova senza un piano: sequenza P1…P6, sola lettura il giorno dopo, chiusura alla cancellazione; niente arretrati.
$cdb->run( 'INSERT INTO saas_orgs (ref, customer_id, name, slug, rev, created_at, org_id) VALUES (?,?,?,?,0,?,?)', array( 'otrial', $reOrd['customer_id'], 'Circolo Prova', 'prova', '2030-01-01 00:00:00', 'orgT' ) );
$tOrd = Orders::open( $cdb, (string) $reOrd['customer_id'], (string) $reOrd['plan'], (string) $reOrd['period'], (int) $reOrd['members'], array(), null );
$cdb->run( "UPDATE orders SET status = 'trial', trial_ends = '2030-01-31', org_ref = 'otrial' WHERE id = ?", array( $tOrd['id'] ) );
$tAt   = function ( string $d ) { return strtotime( $d . ' 12:00:00 UTC' ); };
$mails = array();
check( 'prova: nei giorni prima di P1 non parte nulla', 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-01-23' ) ) && array() === $mails );
check( 'prova: P1 sette giorni prima, una volta sola', 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-01-24' ) ) && 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-01-24' ) ) && false !== strpos( $mails[0][1], 'finisce il 31/01/2030' ) && false !== strpos( $mails[0][2], 'sola lettura' ) && false !== strpos( $mails[0][2], '07/02/2030' ) && false === strpos( $mails[0][2], '{' ) );
check( 'prova: P2 due giorni prima', 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-01-29' ) ) && false !== strpos( $mails[1][1], 'Ancora 2 giorni' ) );
check( 'prova: P3 il giorno della fine, senza sconto non c\'è il paragrafo dell\'offerta', 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-01-31' ) ) && false !== strpos( $mails[2][2], 'consultare tutto' ) && false === stripos( $mails[2][2], 'sconto' ) && false === strpos( $mails[2][2], "\n\n\n" ) );
check( 'prova: il giorno dopo la fine il gestionale passa a «sospeso» (sola lettura) una volta', 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-01' ) ) && 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-02' ) ) && 'suspended' === Portale\SaasAccount::stateOf( $cdb, 'orgT' ) && 1 === (int) $cdb->one( 'SELECT COUNT(*) n FROM saas_outbox WHERE org_id = ?', array( 'orgT' ) )['n'] );
check( 'prova: P4 tre giorni dopo, P5 il giorno prima della cancellazione', 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-03' ) ) && false !== strpos( $mails[3][2], 'in sola lettura da 3 giorni' ) && 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-06' ) ) && false !== strpos( $mails[4][1], 'Domani cancelliamo' ) );
check( 'prova: P6 a cancellazione fatta, ordine chiuso', 'trial' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $tOrd['id'] ) )['status'] && 1 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-07' ) ) && false !== strpos( $mails[5][1], 'Abbiamo cancellato' ) && 'trial_purged' === $cdb->one( 'SELECT status FROM orders WHERE id = ?', array( $tOrd['id'] ) )['status'] && 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-08' ) ) );
$cdb->run( "UPDATE orders SET status = 'trial', trial_step = 0, suspended_for = NULL WHERE id = ?", array( $tOrd['id'] ) );
$mails = array();
check( 'prova: se il lavoro non è girato, gli avvisi vecchi non partono in arretrato', 0 === SaasCheckout::sendTrialMails( $cdb, $tAt( '2030-02-05' ) ) && array() === $mails );
$cdb->run( 'DELETE FROM orders WHERE id = ?', array( $tOrd['id'] ) );
$cdb->run( 'DELETE FROM saas_outbox WHERE org_id = ?', array( 'orgT' ) );
$cdb->run( 'DELETE FROM saas_orgs WHERE ref = ?', array( 'otrial' ) );
$mails = array();
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

// --- Area clienti: il gestionale, accesso dal gestionale, disdetta ----------
use Portale\SaasAccount;
$custA = $cdb->one( "SELECT * FROM customers WHERE email = 'anna@esempio.it'" );
$mine = array();
foreach ( SaasAccount::forCustomer( $cdb, $custA['id'] ) as $g ) {
	$mine[ $g['org_name'] ] = $g;
}
check( 'area clienti: due abbonamenti al gestionale con stato e scadenza dall\'ultimo messaggio', 2 === count( $mine ) && 'cancelled' === $mine['Circolo San Giorgio']['state'] && '' !== $mine['Circolo San Giorgio']['until'] && 'https://app.associazionesemplice.it/gestionale' === $mine['Circolo San Giorgio']['url'] && 'https://circolo-san-giorgio.associazionesemplice.it' === $mine['Circolo San Giorgio']['site_url'] );
check( 'area clienti: un altro cliente non vede nulla e non può agire sull\'ordine', array() === SaasAccount::forCustomer( $cdb, 'altro' ) && null === SaasAccount::mine( $cdb, 'altro', $ord['id'] ) && null !== SaasAccount::mine( $cdb, $custA['id'], $ord['id'] ) );
$ssoTok = function ( array $over = array() ) use ( $oseed, $ord ) {
	return PortalAttestation::sign( $over + array( 'iss' => 'https://app.associazionesemplice.it', 'aud' => 'https://portale.test', 'typ' => 'sso', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'sso' . bin2hex( random_bytes( 6 ) ), 'sub' => 'anna@esempio.it', 'ref' => $ord['org_ref'], 'org_id' => 'x', 'role' => 'owner' ), $oseed );
};
$t1 = $ssoTok();
check( 'da-gestionale: token valido → il cliente', ( SaasAccount::ssoCustomer( $cdb, $t1 )['id'] ?? '' ) === $custA['id'] );
check( 'da-gestionale: stesso token una seconda volta → rifiutato (jti monouso)', null === SaasAccount::ssoCustomer( $cdb, $t1 ) );
check( 'da-gestionale: email diversa dall\'intestatario → rifiutato', null === SaasAccount::ssoCustomer( $cdb, $ssoTok( array( 'sub' => 'altra@esempio.it' ) ) ) );
check( 'da-gestionale: riferimento sconosciuto, tipo sbagliato o scaduto → rifiutato', null === SaasAccount::ssoCustomer( $cdb, $ssoTok( array( 'ref' => 'zzz' ) ) ) && null === SaasAccount::ssoCustomer( $cdb, $ssoTok( array( 'typ' => 'catalog' ) ) ) && null === SaasAccount::ssoCustomer( $cdb, $ssoTok( array( 'iat' => time() - 900, 'exp' => time() - 800 ) ) ) );
check( 'da-gestionale: vita oltre 2 minuti → rifiutato', null === SaasAccount::ssoCustomer( $cdb, $ssoTok( array( 'exp' => time() + 300 ) ) ) );
Config::set( 'stripe.secret_key', 'sk_test_abc' );
$cancelled = array();
Http::$transport = function ( $m, $u, $h, $b ) use ( &$cancelled ) {
	$cancelled[] = array( $m, $u, $b );
	return array( 200, '{}' );
};
$mails = array();
SaasAccount::cancel( $cdb, SaasAccount::mine( $cdb, $custA['id'], $ord['id'] ) );
check( 'email disdetta: inviata alla cliente', 1 === count( $mails ) && 'anna@esempio.it' === $mails[0][0] && false === strpos( $mails[0][2], '{' ) );
check( 'disdetta: a fine periodo su Stripe e ordine segnato', 1 === count( $cancelled ) && false !== strpos( $cancelled[0][1], '/subscriptions/sub_1' ) && 'true' === ( function ( $s ) { parse_str( $s, $o ); return $o['cancel_at_period_end'] ?? ''; } )( $cancelled[0][2] ) && 1 === (int) $cdb->one( 'SELECT cancel_at_period_end c FROM orders WHERE id = ?', array( $ord['id'] ) )['c'] );
Config::set( 'stripe.secret_key', '' );

// Elenco centrale dello staff (docs/UTENTI_E_ACCESSI.md): copia, livelli, super amministratore, registro
use Portale\Plugin\Directory;
$ddb = testDb();
$ddb->migrate();
$ddb->run( 'INSERT INTO staff (email, role, saas, created_at) VALUES (?,?,?,?)', array( 'vecchio@esempio.it', 'support', 'admin', Db::now() ) );
$ddb->run( 'INSERT INTO staff (email, role, saas, created_at) VALUES (?,?,?,?)', array( 'solosaas@esempio.it', 'none', 'admin', Db::now() ) );
$ddb->migrate();
check( 'elenco staff: la copia porta i ruoli di oggi (plugin e SaaS) con i livelli nuovi', Directory::SUPPORT === Directory::level( $ddb, 'vecchio@esempio.it', Directory::AREA_WORDPRESS ) && Directory::ADMIN === Directory::level( $ddb, 'vecchio@esempio.it', Directory::AREA_SAAS ) && Directory::ADMIN === Directory::level( $ddb, 'solosaas@esempio.it', Directory::AREA_SAAS ) && null === Directory::level( $ddb, 'solosaas@esempio.it', Directory::AREA_WORDPRESS ) );
check( 'elenco staff: nessun ruolo nell\'area di supporto finché non lo si dà', null === Directory::level( $ddb, 'vecchio@esempio.it', Directory::AREA_SUPPORT ) && array( 'wordpress' => 'supporto', 'saas' => 'admin' ) === Directory::levels( $ddb, 'vecchio@esempio.it' ) );
$ddb->migrate();
check( 'elenco staff: la copia è ripetibile e non rimette un ruolo cambiato qui', 1 === (int) $ddb->one( 'SELECT COUNT(*) AS n FROM staff_people WHERE email = ?', array( 'vecchio@esempio.it' ) )['n'] );
Directory::put( $ddb, 'vecchio@esempio.it', Directory::AREA_WORDPRESS, Directory::SALES, 'test' );
$ddb->migrate();
check( 'elenco staff: un livello cambiato nell\'elenco centrale resta dopo una nuova copia', Directory::SALES === Directory::level( $ddb, 'vecchio@esempio.it', Directory::AREA_WORDPRESS ) );
check( 'elenco staff: livello non ammesso nell\'area ignorato (agente non esiste in WordPress)', ( function () use ( $ddb ) { Directory::put( $ddb, 'vecchio@esempio.it', Directory::AREA_WORDPRESS, Directory::AGENT, 'test' ); return Directory::SALES === Directory::level( $ddb, 'vecchio@esempio.it', Directory::AREA_WORDPRESS ); } )() );
$ddb->run( 'UPDATE staff_people SET active = 0 WHERE email = ?', array( 'vecchio@esempio.it' ) );
check( 'elenco staff: persona disattivata = nessun accesso in nessuna area', array() === Directory::levels( $ddb, 'vecchio@esempio.it' ) );
Config::set( 'super_admins', array( 'titolare@esempio.it' ) );
check( 'elenco staff: la super amministratrice è admin di tutte le aree anche senza righe nel database', array( 'wordpress' => 'admin', 'saas' => 'admin', 'supporto' => 'admin' ) === Directory::levels( $ddb, 'Titolare@Esempio.it' ) && null === $ddb->one( 'SELECT 1 AS x FROM staff_people WHERE email = ?', array( 'titolare@esempio.it' ) ) );
check( 'elenco staff: compare per prima e bloccata nella pagina delle persone', 'titolare@esempio.it' === Directory::people( $ddb, 'saas' )[0]['email'] && true === Directory::people( $ddb, 'saas' )[0]['locked'] );
Config::set( 'super_admins', array() );
check( 'elenco staff: i gradi dei livelli (admin > vendita > supporto, admin > agente)', Directory::rank( 'saas', 'admin' ) > Directory::rank( 'saas', 'vendita' ) && Directory::rank( 'saas', 'vendita' ) > Directory::rank( 'saas', 'supporto' ) && Directory::rank( 'supporto', 'admin' ) > Directory::rank( 'supporto', 'agente' ) && 0 === Directory::rank( 'supporto', 'vendita' ) );
Directory::audit( $ddb, 'A@esempio.it', 'b@esempio.it', 'grant', 'wordpress:admin', '203.0.113.9' );
$aud = $ddb->one( 'SELECT actor, target, action, ip FROM staff_audit WHERE action = ?', array( 'grant' ) );
check( 'elenco staff: il registro annota chi, a chi, cosa e da dove', 'a@esempio.it' === $aud['actor'] && 'b@esempio.it' === $aud['target'] && '203.0.113.9' === $aud['ip'] );
$ddb->run( "DELETE FROM staff_people WHERE email = 'vecchio@esempio.it'" );
$ddb->run( "DELETE FROM staff_roles WHERE area = 'wordpress'" );
Directory::audit( $ddb, 'sistema', '', 'cutover' );
$ddb->migrate();
check( 'elenco staff: dopo il passaggio all\'accesso unico la copia dalle tabelle vecchie non si ripete', null === $ddb->one( 'SELECT 1 AS x FROM staff_people WHERE email = ?', array( 'vecchio@esempio.it' ) ) );
// Accesso staff unico, lato portale (docs/STAFF_SSO.md): token del SaaS, sessione dell'area, controllo ogni 5 minuti, richieste «Utenti»
use Portale\Plugin\StaffSso;
$ssSeed = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
$ssKid  = Portale\PortalAttestation::kid( $ssSeed );
$ssNow  = 1800000000;
$ssTok  = function ( array $over = array() ) use ( $ssSeed, $ssNow ) {
	$c = $over + array( 'iss' => 'https://associazionesemplice.it/saas', 'aud' => 'associazionesemplice-portale', 'typ' => 'staff_sso', 'iat' => $ssNow, 'exp' => $ssNow + 60, 'jti' => bin2hex( random_bytes( 8 ) ), 'sub' => 'mario@esempio.it', 'area' => 'wordpress', 'level' => 'vendita', 'mfa' => false, 'sid' => str_repeat( 'a', 32 ), 'areas' => array( 'wordpress' => 'vendita', 'saas' => 'admin' ) );
	return Portale\PortalAttestation::sign( $c, $ssSeed );
};
$ssdb = testDb();
$ssdb->migrate();
$_SESSION = array();
check( 'staff sso: spento di default, nessuna sessione', false === StaffSso::enabled() && false === StaffSso::receive( $ssdb, $ssTok(), $ssNow ) && ! isset( $_SESSION['staff_sso'] ) );
Config::set( 'staff_sso', array( 'enabled' => true, 'issuer' => 'https://associazionesemplice.it/saas', 'api_url' => 'https://associazionesemplice.it/saas', 'keys' => array( $ssKid => Portale\PortalAttestation::publicKey( $ssSeed ) ) ) );
check( 'staff sso: acceso con chiavi fissate', true === StaffSso::enabled() );
check( 'staff sso: token valido apre la sessione con livello, sid e aree (secondo fattore non richiesto)', true === StaffSso::receive( $ssdb, $ssTok(), $ssNow ) && 'vendita' === $_SESSION['staff_sso']['level'] && 'mario@esempio.it' === $_SESSION['staff_sso']['email'] && false === $_SESSION['staff_sso']['mfa'] && 'admin' === $_SESSION['staff_sso']['areas']['saas'] );
$ssJ = $ssTok();
check( 'staff sso: il jti vale una volta sola', true === StaffSso::receive( $ssdb, $ssJ, $ssNow ) && false === StaffSso::receive( $ssdb, $ssJ, $ssNow ) );
foreach ( array(
	'destinatario sbagliato' => $ssTok( array( 'aud' => 'associazionesemplice-saas' ) ),
	'tipo sbagliato'         => $ssTok( array( 'typ' => 'staff_session_check' ) ),
	'area sbagliata'         => $ssTok( array( 'area' => 'saas' ) ),
	'emittente sbagliato'    => $ssTok( array( 'iss' => 'https://altro.esempio.it' ) ),
	'scaduto'                => $ssTok( array( 'iat' => $ssNow - 300, 'exp' => $ssNow - 200 ) ),
	'vita oltre 60 secondi'  => $ssTok( array( 'exp' => $ssNow + 61 ) ),
	'campo sconosciuto'      => $ssTok( array( 'extra' => 'x' ) ),
	'livello sconosciuto'    => $ssTok( array( 'level' => 'super' ) ),
	'email non minuscola'    => $ssTok( array( 'sub' => 'Mario@Esempio.it' ) ),
	'sid non valido'         => $ssTok( array( 'sid' => 'xyz' ) ),
	'mfa non booleano'       => $ssTok( array( 'mfa' => 'si' ) ),
) as $ssWhy => $ssBad ) {
	check( 'staff sso: rifiutato — ' . $ssWhy, null === StaffSso::verify( $ssBad, $ssNow ) );
}
$ssOther = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
check( 'staff sso: rifiutato — firma di una chiave non fissata', null === StaffSso::verify( Portale\PortalAttestation::sign( array( 'iss' => 'https://associazionesemplice.it/saas', 'aud' => 'associazionesemplice-portale', 'typ' => 'staff_sso', 'iat' => $ssNow, 'exp' => $ssNow + 60, 'jti' => 'j1', 'sub' => 'mario@esempio.it', 'area' => 'wordpress', 'level' => 'admin', 'sid' => str_repeat( 'b', 32 ) ), $ssOther ), $ssNow ) );
$ssParts = explode( '.', $ssTok() );$ssBody  = json_decode( base64_decode( strtr( $ssParts[1], '-_', '+/' ) ), true );$ssBody['level'] = 'admin';$ssParts[1] = rtrim( strtr( base64_encode( json_encode( $ssBody ) ), '+/', '-_' ), '=' );check( 'staff sso: rifiutato — contenuto manomesso con la firma originale', null === StaffSso::verify( implode( '.', $ssParts ), $ssNow ) );
check( 'staff sso: ruoli — admin e supporto sì, vendita non ancora', 'admin' === StaffSso::roleFor( 'admin' ) && 'support' === StaffSso::roleFor( 'supporto' ) && null === StaffSso::roleFor( 'vendita' ) );
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
check( 'staff sso: la sessione resta entro 2 ore di inattività', null !== StaffSso::current( $ssNow + 3600 ) );
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
check( 'staff sso: oltre 2 ore di inattività si chiude', null === StaffSso::current( $ssNow + 7201 ) && ! isset( $_SESSION['staff_sso'] ) );
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
$ssT = $ssNow;
while ( $ssT < $ssNow + 28000 ) {
	$ssT += 3600;
	StaffSso::current( $ssT );
}
check( 'staff sso: oltre 8 ore di durata si chiude anche se attiva', null === StaffSso::current( $ssNow + 28801 ) );
// Controllo ogni 5 minuti e richieste firmate dal portale
Config::set( 'staff_area_sign_key', base64_encode( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
check( 'staff sso: chiave del portale per le richieste (kid e pubblica, mai la privata)', StaffSso::signingEnabled() && '' !== StaffSso::kid() && '' !== StaffSso::publicKey() );
$ssCalls = array();
$ssReply = array( 200, '{"valid":true,"level":"admin"}' );
$ssPrev  = Http::$transport;
Http::$transport = function ( $m, $url, $h, $body ) use ( &$ssCalls, &$ssReply ) {
	$ssCalls[] = array( $m, $url, $body );
	return $ssReply;
};
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
$ssS = $_SESSION['staff_sso'];
check( 'staff sso: prima di 5 minuti non si richiede nulla, dopo sì', false === StaffSso::due( $ssS, $ssNow + 299 ) && true === StaffSso::due( $ssS, $ssNow + 300 ) );
$ssR = StaffSso::recheck( $ssS, $ssNow + 300 );
check( 'staff sso: controllo — il livello nuovo si adotta subito', 'admin' === $ssR['level'] && 'POST' === $ssCalls[0][0] && 'https://associazionesemplice.it/saas/api/staff/v1/session-check' === $ssCalls[0][1] );
$ssClaims = json_decode( base64_decode( strtr( explode( '.', json_decode( $ssCalls[0][2], true )['token'] )[1], '-_', '+/' ) ), true );
check( 'staff sso: controllo — token firmato con tipo, sid, area e vita breve', 'staff_session_check' === $ssClaims['typ'] && str_repeat( 'a', 32 ) === $ssClaims['sid'] && 'wordpress' === $ssClaims['area'] && 60 === $ssClaims['exp'] - $ssClaims['iat'] );
$ssReply = array( 200, '{"valid":false,"level":null}' );
check( 'staff sso: controllo — sessione non più valida = chiusa', null === StaffSso::recheck( $_SESSION['staff_sso'], $ssNow + 600 ) && ! isset( $_SESSION['staff_sso'] ) );
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
$ssReply = array( 0, '' );
$ssR1 = StaffSso::recheck( $_SESSION['staff_sso'], $ssNow + 300 );
check( 'staff sso: un controllo senza risposta non chiude', null !== $ssR1 && 1 === $ssR1['fails'] );
check( 'staff sso: due controlli senza risposta di fila chiudono', null === StaffSso::recheck( $_SESSION['staff_sso'], $ssNow + 600 ) && ! isset( $_SESSION['staff_sso'] ) );
StaffSso::receive( $ssdb, $ssTok(), $ssNow );
$ssReply = array( 500, '' );
StaffSso::recheck( $_SESSION['staff_sso'], $ssNow + 300 );
$ssReply = array( 200, '{"valid":true,"level":"vendita"}' );
$ssR2 = StaffSso::recheck( $_SESSION['staff_sso'], $ssNow + 600 );
check( 'staff sso: una risposta valida azzera i fallimenti', 0 === $ssR2['fails'] );
$ssCalls = array();
$ssReply = array( 200, '{"ok":true}' );
list( $ssC, $ssO ) = StaffSso::people( 'grant', 'Mario@Esempio.it', array( 'email' => 'luca@esempio.it', 'level' => 'vendita' ), $ssNow );
$ssPc = json_decode( base64_decode( strtr( explode( '.', json_decode( $ssCalls[0][2], true )['token'] )[1], '-_', '+/' ) ), true );
check( 'staff sso: richiesta «Utenti» — path, tipo, area, act e dati firmati', 200 === $ssC && true === $ssO['ok'] && 'https://associazionesemplice.it/saas/api/staff/v1/people/grant' === $ssCalls[0][1] && 'staff_people' === $ssPc['typ'] && 'wordpress' === $ssPc['area'] && 'mario@esempio.it' === $ssPc['act'] && 'luca@esempio.it' === $ssPc['email'] );
check( 'staff sso: richiesta «Utenti» sconosciuta rifiutata', ( function () { try { StaffSso::people( 'elimina', 'a@b.it' ); } catch ( InvalidArgumentException $e ) { return true; } return false; } )() );
// Amministrazione del plugin con la sessione dell'accesso staff unico: ruoli admin / vendita / supporto
$ssBill = new Billing( $ssdb, new WegaClient( 'https://license.test/api/v1', 't' ) );
$ssRole = function ( string $level ) use ( $ssdb, $ssBill ) {
	$_SESSION = array();
	$adm = new Portale\Plugin\Admin( $ssdb, $ssBill, '', array( 'email' => 'mario@esempio.it', 'level' => $level, 'sid' => str_repeat( 'a', 32 ) ) );
	return $_SESSION['staff'];
};
check( 'staff sso: ruoli dell\'amministrazione — admin, vendita e supporto', 'admin' === $ssRole( 'admin' ) && 'sales' === $ssRole( 'vendita' ) && 'support' === $ssRole( 'supporto' ) );
check( 'staff sso: livello sconosciuto (p.es. agente) = nessun accesso alla gestione del plugin', null !== throwsUser( function () use ( $ssdb, $ssBill ) { new Portale\Plugin\Admin( $ssdb, $ssBill, '', array( 'email' => 'mario@esempio.it', 'level' => 'agente', 'sid' => str_repeat( 'a', 32 ) ) ); } ) );
$ssSales = new ReflectionMethod( Portale\Plugin\Admin::class, 'salesMayDo' );
$ssSales->setAccessible( true );
$ssAdm = new Portale\Plugin\Admin( $ssdb, $ssBill, '', array( 'email' => 'mario@esempio.it', 'level' => 'vendita', 'sid' => str_repeat( 'a', 32 ) ) );
check( 'staff sso: «vendita» può catalogo, prezzi, promo, licenze e clienti', $ssSales->invoke( $ssAdm, 'catalogo' ) && $ssSales->invoke( $ssAdm, 'prezzi' ) && $ssSales->invoke( $ssAdm, 'codici-promo' ) && $ssSales->invoke( $ssAdm, 'licenze' ) && $ssSales->invoke( $ssAdm, 'clienti' ) && $ssSales->invoke( $ssAdm, '' ) );
check( 'staff sso: «vendita» non può impostazioni, versioni, pagamenti, email, pagine, registro, utenti, Wega', ! array_filter( array( 'impostazioni', 'versioni', 'pagamenti', 'email', 'legale', 'cookie', 'registro', 'utenti', 'staff', 'wega', 'sicurezza' ), function ( $a ) use ( $ssSales, $ssAdm ) { return $ssSales->invoke( $ssAdm, $a ); } ) );
check( 'staff sso: «Vai a» — destinazioni fisse su https, solo SaaS e Supporto', array( 'saas', 'supporto' ) === array_keys( StaffSso::DEST ) && 0 === count( array_filter( StaffSso::DEST, function ( $u ) { return 0 !== strpos( $u, 'https://' ); } ) ) );
$_SESSION = array();
$ssCalls = array();
$ssReply = array( 200, json_encode( array( 'token' => $ssTok() ) ) );
check( 'staff sso: accesso dal modulo — il SaaS risponde col token e la sessione si apre', true === StaffSso::login( $ssdb, 'Mario@Esempio.it', 'segreta', '', '203.0.113.7', $ssNow ) && 'mario@esempio.it' === $_SESSION['staff_sso']['email'] );
$ssLc = json_decode( base64_decode( strtr( explode( '.', json_decode( $ssCalls[0][2], true )['token'] )[1], '-_', '+/' ) ), true );
check( 'staff sso: accesso — richiesta firmata staff_login con email, ip e area; codice assente se non dato', 'staff_login' === $ssLc['typ'] && 'mario@esempio.it' === $ssLc['email'] && '203.0.113.7' === $ssLc['ip'] && 'wordpress' === $ssLc['area'] && ! isset( $ssLc['code'] ) && 'https://associazionesemplice.it/saas/api/staff/v1/login' === $ssCalls[0][1] );
$_SESSION = array();
$ssReply = array( 401, '{"error":"credenziali"}' );
check( 'staff sso: accesso rifiutato dal SaaS = nessuna sessione', false === StaffSso::login( $ssdb, 'mario@esempio.it', 'sbagliata', '123456', '203.0.113.7', $ssNow ) && ! isset( $_SESSION['staff_sso'] ) );
check( 'staff sso: accesso senza email o password non esce nemmeno', false === StaffSso::login( $ssdb, '', 'x', '', '1.2.3.4', $ssNow ) && false === StaffSso::login( $ssdb, 'a@b.it', '', '', '1.2.3.4', $ssNow ) );
$ssReply = array( 200, json_encode( array( 'token' => $ssTok( array( 'aud' => 'associazionesemplice-saas' ) ) ) ) );
check( 'staff sso: accesso — un token non per questa area non apre la sessione', false === StaffSso::login( $ssdb, 'mario@esempio.it', 'segreta', '', '203.0.113.7', $ssNow ) );
$ssSess = array( 'sid' => str_repeat( 'a', 32 ), 'areas' => array( 'wordpress' => 'vendita', 'saas' => 'admin' ) );
$ssReply = array( 200, '{"token":"xyz.abc.def"}' );
check( 'staff sso: «Vai a» — il token per un\'altra area si chiede al SaaS, solo per aree in cui si ha un ruolo', 'xyz.abc.def' === StaffSso::tokenFor( $ssSess, 'saas', $ssNow ) && null === StaffSso::tokenFor( $ssSess, 'supporto', $ssNow ) && null === StaffSso::tokenFor( $ssSess, 'wordpress', $ssNow ) );
Http::$transport = $ssPrev;
Config::set( 'staff_sso', array() );
Config::set( 'staff_area_sign_key', '' );
$_SESSION = array();
// --- Proxy in PHP verso il SaaS (/saas/admin, /saas/staff, /saas/api, /saas/vai) ----------
use Portale\SaasProxy;
check( 'proxy: solo i quattro prefissi, non /saas né parole che iniziano allo stesso modo', SaasProxy::handles( '/saas/admin' ) && SaasProxy::handles( '/saas/admin/utenti' ) && SaasProxy::handles( '/saas/staff' ) && SaasProxy::handles( '/saas/api/v1/x' ) && SaasProxy::handles( '/saas/vai' ) && ! SaasProxy::handles( '/saas' ) && ! SaasProxy::handles( '/saas/administrator' ) && ! SaasProxy::handles( '/saas/acquista' ) && ! SaasProxy::handles( '/account' ) );
check( 'proxy: spento per default', false === SaasProxy::enabled() );
$pseen = array();
SaasProxy::$transport = function ( $m, $u, $h, $b ) use ( &$pseen ) {
	$pseen = array( $m, $u, $h, $b );
	return array( 302, array(
		'Location: https://app.associazionesemplice.it/saas/admin/dashboard', 'Set-Cookie: s=1; Path=/; Domain=app.associazionesemplice.it; HttpOnly',
		'Set-Cookie: PHPSESSID=evil; Path=/', 'Content-Type: text/html', 'Server: nginx', 'X-Powered-By: Laravel',
	), 'corpo' );
};
$srv = array(
	'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/saas/admin/login?x=1', 'QUERY_STRING' => 'x=1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
	'HTTP_COOKIE' => 'PHPSESSID=abc; laravel_session=zzz', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_HOST' => 'evil.test', 'HTTP_X_REAL_IP' => '6.6.6.6',
);
list( $pst, $phd, $pbody ) = SaasProxy::handle( $srv, 'a=1', '203.0.113.9' );
check( 'proxy: stesso metodo, percorso, query e corpo verso il SaaS', 'POST' === $pseen[0] && 'https://app.associazionesemplice.it/saas/admin/login?x=1' === $pseen[1] && 'a=1' === $pseen[3] );
check( 'proxy: il cookie di sessione del portale non esce, quello del SaaS passa', in_array( 'Cookie: laravel_session=zzz', $pseen[2], true ) && ! in_array( 'Cookie: PHPSESSID=abc; laravel_session=zzz', $pseen[2], true ) );
$ph = implode( "\n", $pseen[2] );
check( 'proxy: X-Forwarded-* decisi dal portale, quelli del client scartati', false !== strpos( $ph, 'X-Forwarded-For: 203.0.113.9' ) && false === strpos( $ph, '6.6.6.6' ) && false === strpos( $ph, 'evil.test' ) && false !== strpos( $ph, 'X-Forwarded-Host: portale.test' ) && false !== strpos( $ph, 'X-Forwarded-Proto: https' ) );
$hn = array();
foreach ( $phd as $h ) {
	$hn[ strtolower( $h[0] ) ][] = $h[1];
}
check( 'proxy: status e redirect passati (non seguiti), Location sul dominio del portale', 302 === $pst && array( '/saas/admin/dashboard' ) === $hn['location'] && 'corpo' === $pbody );
check( 'proxy: Set-Cookie senza Domain, quello di sessione del portale scartato, intestazioni interne non passate', array( 's=1; Path=/; HttpOnly' ) === $hn['set-cookie'] && ! isset( $hn['server'] ) && ! isset( $hn['x-powered-by'] ) && array( 'text/html' ) === $hn['content-type'] );
check( 'proxy: Location verso app. fuori dai prefissi → sotto /saas; indirizzo esterno invariato', '/saas/login' === SaasProxy::rewriteLocation( 'https://app.associazionesemplice.it/login' ) && 'https://example.org/x' === SaasProxy::rewriteLocation( 'https://example.org/x' ) && '/saas/api/v1/y?z=1' === SaasProxy::rewriteLocation( '/saas/api/v1/y?z=1' ) );
check( 'proxy: percorsi con .. (anche codificati) rifiutati senza chiamare il SaaS', ( function () use ( &$pseen ) { $pseen = array(); $a = SaasProxy::handle( array( 'REQUEST_URI' => '/saas/admin/../../etc/passwd' ), '', '1.1.1.1' )[0]; $b = SaasProxy::handle( array( 'REQUEST_URI' => '/saas/admin/%2e%2e/x' ), '', '1.1.1.1' )[0]; return 400 === $a && 400 === $b && array() === $pseen; } )() );
SaasProxy::$transport = function () { return array( 0, array(), '' ); };
check( 'proxy: SaaS irraggiungibile → 502 con messaggio, niente cache', 502 === SaasProxy::handle( array( 'REQUEST_URI' => '/saas/admin' ), '', '1.1.1.1' )[0] );
SaasProxy::$transport = function () { return array( 200, array( 'Content-Type: text/html' ), 'corpo' ); };
check( 'proxy: HEAD senza corpo', '' === SaasProxy::handle( array( 'REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/saas/staff' ), '', '1.1.1.1' )[2] );
SaasProxy::$transport = null;
Config::set( 'saas_proxy.enabled', true );
check( 'proxy acceso: il rinvio 302 di /saas/admin verso app. non c\'è più', SaasProxy::enabled() && null === Sections::legacy( 'GET', '/saas/admin/utenti', '' ) );
Config::set( 'saas_proxy.enabled', false );
check( 'proxy spento: il rinvio provvisorio torna', 302 === ( Sections::legacy( 'GET', '/saas/admin', '' )[0] ?? 0 ) || null === Sections::legacy( 'GET', '/saas/admin', '' ) && '' === trim( (string) Config::get( 'saas_admin_url', 'x' ) ) );

// Accesso unico lato portale (saas/docs/IDENTITA_API.md): richieste firmate, esiti, controllo della sessione centrale
use Portale\Identity;
check( 'identità: spenta di default', false === Identity::enabled() && array( 0, array() ) === Identity::call( 'session', array( 'sid' => str_repeat( 'a', 32 ) ) ) );
check( 'identità: tipo dall\'azione', 'identity_email_confirm' === Identity::typ( 'email-confirm' ) && 'identity_2fa_start' === Identity::typ( '2fa-start' ) && 'identity_login' === Identity::typ( 'login' ) );
check( 'identità: link nelle email su indirizzo fisso del portale', 'https://associazionesemplice.it/accedi/reimposta/' === Identity::linkBase( 'reimposta' ) );
Config::set( 'identity', array( 'enabled' => true ) );
Config::set( 'staff_area_sign_key', base64_encode( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
$idSent  = array();
$idReply = array( 200, '{}' );
$idPrev  = Http::$transport;
Http::$transport = function ( $m, $u, $h, $b ) use ( &$idSent, &$idReply ) {
	$t = json_decode( (string) $b, true )['token'] ?? '';
	$p = explode( '.', $t );
	$idSent = array( 'm' => $m, 'u' => $u, 'c' => json_decode( (string) base64_decode( strtr( $p[1] ?? '', '-_', '+/' ) ), true ) );
	return $idReply;
};
check( 'identità: acceso con chiave del portale', true === Identity::enabled() );
$idReply = array( 200, json_encode( array( 'status' => 'ok', 'sid' => str_repeat( 'a', 32 ), 'person' => array( 'email' => 'm@esempio.it' ), 'areas' => array( 'saas' => 'admin', 'altra' => 'x', 'wordpress' => 'BAD!' ) ) ) );
$idR = Identity::login( ' M@Esempio.it ', 'segreta', '203.0.113.9' );
check( 'identità: login firmata verso l\'indirizzo giusto con tipo, destinatario, scadenza breve, ip e jti', 'POST' === $idSent['m'] && 'https://associazionesemplice.it/saas/api/staff/v1/identity/login' === $idSent['u'] && 'identity_login' === $idSent['c']['typ'] && 'associazionesemplice-saas' === $idSent['c']['aud'] && 'wordpress' === $idSent['c']['area'] && 60 === $idSent['c']['exp'] - $idSent['c']['iat'] && 'm@esempio.it' === $idSent['c']['email'] && '203.0.113.9' === $idSent['c']['ip'] && 32 === strlen( $idSent['c']['jti'] ) );
check( 'identità: esito ok con sid e sole aree note', 'ok' === $idR['status'] && str_repeat( 'a', 32 ) === $idR['sid'] && array( 'saas' => 'admin' ) === $idR['areas'] );
$idReply = array( 200, json_encode( array( 'status' => 'ok', 'sid' => 'non-valido' ) ) );
check( 'identità: sid non valido = rifiutato', 'denied' === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' )['status'] );
$idReply = array( 200, json_encode( array( 'status' => 'code_required', 'ticket' => 'tk123' ) ) );
check( 'identità: serve il codice', array( 'status' => 'code_required', 'ticket' => 'tk123' ) === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' ) );
$idReply = array( 401, '{"error":"denied"}' );
check( 'identità: credenziali errate', 'denied' === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' )['status'] );
$idReply = array( 429, '{}' );
check( 'identità: troppi tentativi', 'limit' === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' )['status'] );
$idReply = array( 0, '' );
check( 'identità: SaaS non raggiungibile', 'down' === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' )['status'] );
$idReply = array( 422, json_encode( array( 'messages' => array( 'Troppo corta.' ) ) ) );
check( 'identità: errori di validazione', array( 'status' => 'invalid', 'messages' => array( 'Troppo corta.' ) ) === Identity::login( 'm@esempio.it', 'x', '1.1.1.1' ) );
$idReply = array( 200, '{}' );
Identity::code( 'tk123', '123 456', '1.1.1.1' );
check( 'identità: il codice si manda senza spazi, col ticket', 'identity_code' === $idSent['c']['typ'] && '123456' === $idSent['c']['code'] && 'tk123' === $idSent['c']['ticket'] );
Identity::resetRequest( 'M@Esempio.it', '1.1.1.1' );
check( 'identità: reimposta password — link_base fisso dal portale, mai dalla richiesta', 'identity_reset_request' === $idSent['c']['typ'] && 'https://associazionesemplice.it/accedi/reimposta/' === $idSent['c']['link_base'] && 'm@esempio.it' === $idSent['c']['email'] );
$idOk = Identity::resetComplete( 'abc', 'nuovapassword' );
$idReply = array( 422, '{}' );
check( 'identità: reimposta password — esiti', 'ok' === $idOk && 'invalid' === Identity::resetComplete( 'abc', 'x' ) );
$_SESSION = array();
Identity::open( array( 'sid' => str_repeat( 'b', 32 ), 'person' => array(), 'areas' => array( 'saas' => 'admin' ) ), 'M@Esempio.it', 1800000000 );
check( 'identità: sessione aperta lato server', str_repeat( 'b', 32 ) === Identity::current()['sid'] && 'm@esempio.it' === Identity::current()['email'] );
$idSent = array();
check( 'identità: nessun controllo prima dei 5 minuti', null !== Identity::check( false, 1800000100 ) && array() === $idSent );
$idReply = array( 200, json_encode( array( 'valid' => true, 'areas' => array( 'saas' => 'admin', 'supporto' => 'supporto' ) ) ) );
$idChk   = Identity::check( false, 1800000400 );
check( 'identità: dopo 5 minuti si riconferma e si aggiornano le aree', null !== $idChk && 'identity_session' === $idSent['c']['typ'] && isset( Identity::current()['areas']['supporto'] ) );
$idReply = array( 200, json_encode( array( 'valid' => false ) ) );
check( 'identità: sessione revocata = chiusa subito (controllo forzato)', null === Identity::check( true, 1800000500 ) && null === Identity::current() );
Identity::open( array( 'sid' => str_repeat( 'b', 32 ), 'person' => array(), 'areas' => array() ), 'm@esempio.it', 1800000000 );
$idReply = array( 0, '' );
$idFirst = Identity::check( true, 1800000600 );
check( 'identità: nessuna risposta una volta si tiene, due volte di fila si chiude', null !== $idFirst && null === Identity::check( true, 1800000700 ) );
Identity::open( array( 'sid' => str_repeat( 'b', 32 ), 'person' => array(), 'areas' => array( 'saas' => 'admin' ) ), 'm@esempio.it' );
$idReply = array( 200, json_encode( array( 'token' => 'jwt.x.y' ) ) );
$idTok   = Identity::tokenFor( Identity::current(), 'saas' );
check( 'identità: token per l\'area, firmato col sid', 'jwt.x.y' === $idTok && 'saas' === $idSent['c']['to'] && str_repeat( 'b', 32 ) === $idSent['c']['sid'] && null === Identity::tokenFor( Identity::current(), 'altra' ) );
Identity::logout();
check( 'identità: uscita revoca la sessione centrale e svuota quella del portale', 'identity_logout' === $idSent['c']['typ'] && null === Identity::current() );
// Profilo: letture e azioni che cambiano qualcosa (sessione riconfermata subito prima)
Identity::open( array( 'sid' => str_repeat( 'c', 32 ), 'person' => array(), 'areas' => array() ), 'm@esempio.it' );
$idReply = array( 200, json_encode( array( 'person' => array( 'email' => 'm@esempio.it' ), 'areas' => array( 'saas' => 'admin' ), 'two_factor' => true, 'sessions' => array( array( 'id' => 'abc123', 'ip' => '1.2.3.4', 'created_at' => '2026-11-11', 'last_used_at' => '2026-11-11 10:00', 'current' => true ), 'rotta' ) ) ) );
$idP = Identity::profile( Identity::current() );
check( 'profilo: dati, secondo livello e sole sessioni ben formate', true === $idP['two_factor'] && 1 === count( $idP['sessions'] ) && true === $idP['sessions'][0]['current'] && 'identity_profile' === $idSent['c']['typ'] );
$idCalls = array();
Http::$transport = function ( $m, $u, $h, $b ) use ( &$idCalls, &$idReply ) {
	$p = explode( '.', json_decode( (string) $b, true )['token'] ?? '' );
	$idCalls[] = json_decode( (string) base64_decode( strtr( $p[1] ?? '', '-_', '+/' ) ), true )['typ'] ?? '';
	return 'identity_session' === end( $idCalls ) ? array( 200, '{"valid":true,"areas":{}}' ) : $idReply;
};
$idReply = array( 200, '{"ok":true}' );
$idA = Identity::act( 'password', array( 'current' => 'a', 'new' => 'b' ) );
check( 'profilo: prima di un cambio si riconferma la sessione, poi parte la richiesta', 'ok' === $idA['status'] && array( 'identity_session', 'identity_password' ) === $idCalls );
$idReply = array( 422, json_encode( array( 'messages' => array( 'Troppo corta.' ) ) ) );
check( 'profilo: errori di validazione riportati', array( 'Troppo corta.' ) === Identity::act( 'password', array( 'current' => 'a', 'new' => 'b' ) )['messages'] );
$idReply = array( 401, '{}' );
check( 'profilo: password errata = rifiutato', 'denied' === Identity::act( '2fa-start', array( 'password' => 'x' ) )['status'] );
$idReply = array( 200, '{"valid":false}' );
Http::$transport = function () use ( &$idReply ) { return array( 200, '{"valid":false}' ); };
check( 'profilo: sessione non più valida = chiusa, nessun cambio', 'closed' === Identity::act( 'password', array( 'current' => 'a', 'new' => 'b' ) )['status'] && null === Identity::current() );
try {
	Identity::act( 'login', array() );
	check( 'profilo: azioni non ammesse rifiutate', false );
} catch ( \InvalidArgumentException $e ) {
	check( 'profilo: azioni non ammesse rifiutate', true );
}
Http::$transport = function ( $m, $u ) use ( &$idSent ) { $idSent['u'] = $u; return array( 200, '{"ok":true}' ); };
check( 'profilo: conferma email dal link', true === Identity::emailConfirm( 'codice' ) && false !== strpos( $idSent['u'], '/identity/email-confirm' ) );
Http::$transport = $idPrev;
Config::set( 'identity', array() );
Config::set( 'staff_area_sign_key', '' );
use Portale\SaasClient;
// Contratto v2 lato portale: «Gestisci» (stato, dominio, controllo cambio piano), spento finché il SaaS non ha gli endpoint in produzione
check( 'gestisci: spento di default', false === SaasAccount::manageEnabled() );
Config::set( 'saas_sign_key', base64_encode( str_repeat( 's', 32 ) ) );
check( 'gestisci: acceso solo con saas_v2.enabled e la chiave', false === SaasAccount::manageEnabled() && ( Config::set( 'saas_v2', array( 'enabled' => true ) ) || true ) && true === SaasAccount::manageEnabled() );
$v2Sent = array();
$v2Prev = Http::$transport;
Http::$transport = function ( $m, $u, $h, $b ) use ( &$v2Sent ) {
	$p = explode( '.', json_decode( (string) $b, true )['token'] ?? '' );
	$v2Sent = array( 'm' => $m, 'u' => $u, 'c' => json_decode( (string) base64_decode( strtr( $p[1] ?? '', '-_', '+/' ) ), true ) );
	return array( 200, '{"ok":true}' );
};
SaasClient::planChangeCheck( 'abcdef12-3456', 'o1', 'gestionale', 2 );
check( 'gestisci: cambio piano — percorso, tipo e claim del contratto', 'POST' === $v2Sent['m'] && false !== strpos( $v2Sent['u'], '/api/portale/v2/orgs/abcdef12-3456/plan-change/check' ) && 'plan_change_check' === $v2Sent['c']['typ'] && 'o1' === $v2Sent['c']['ref'] && 'gestionale' === $v2Sent['c']['to_plan'] && 2 === $v2Sent['c']['to_plan_version'] );
SaasClient::domainSet( 'abcdef12-3456', 'o1', null );
check( 'gestisci: dominio tolto = domain null', 'domain_set' === $v2Sent['c']['typ'] && array_key_exists( 'domain', $v2Sent['c'] ) && null === $v2Sent['c']['domain'] );
SaasClient::domainCheck( 'abcdef12-3456', 'o1', 'www.esempio.it' );
check( 'gestisci: controllo dominio', 'domain_check' === $v2Sent['c']['typ'] && 'www.esempio.it' === $v2Sent['c']['domain'] );
SaasClient::orgStatus( 'abcdef12-3456', 'o1' );
check( 'gestisci: stato', 'org_status' === $v2Sent['c']['typ'] && false !== strpos( $v2Sent['u'], '/orgs/abcdef12-3456/status' ) );
$v2Bad = false;
try {
	SaasClient::orgStatus( '../../x', 'o1' );
} catch ( UserError $e ) {
	$v2Bad = true;
}
check( 'gestisci: identificativo strano mai nell\'indirizzo', $v2Bad );
Http::$transport = $v2Prev;
check( 'gestisci: il dominio resta spento anche con «Gestisci» acceso', true === SaasAccount::manageEnabled() && false === SaasAccount::domainEnabled() );
check( 'gestisci: dominio normalizzato', 'www.esempio.it' === SaasAccount::cleanDomain( ' HTTPS://WWW.Esempio.it/pagina?x=1 ' ) && 'esempio.it' === SaasAccount::cleanDomain( 'esempio.it.' ) );
check( 'gestisci: domini non validi rifiutati', null === SaasAccount::cleanDomain( 'localhost' ) && null === SaasAccount::cleanDomain( 'a b.it' ) && null === SaasAccount::cleanDomain( '<script>.it' ) && null === SaasAccount::cleanDomain( '' ) );
check( 'gestisci: ragioni del dominio in italiano', false !== strpos( SaasAccount::domainReason( 'taken' ), 'un\'altra associazione' ) && '' !== SaasAccount::domainReason( 'boh' ) );
Config::set( 'saas_v2', array() );
Config::set( 'saas_sign_key', '' );
// Interruttori del portale (/account/impostazioni): salvati nel database, sostituiscono config.php, chiavi sconosciute ignorate
$swdb = testDb();
$swdb->migrate();
Config::set( 'saas_v2', array( 'enabled' => true, 'domain' => false ) );
check( 'interruttori: lo stato parte da config.php', array( 'saas_v2' => true, 'saas_v2_domain' => false ) === Portale\Switches::current() );
Portale\Switches::save( $swdb, array( 'saas_v2_domain' => '1', 'sconosciuta' => '1' ) );
check( 'interruttori: salvando, ciò che non è spuntato si spegne e il resto si accende', array( 'saas_v2' => false, 'saas_v2_domain' => true ) === Portale\Switches::current() );
Config::set( 'saas_v2', array( 'enabled' => true, 'domain' => false ) );
Portale\Switches::load( $swdb );
check( 'interruttori: all\'avvio il valore salvato vince su config.php', false === Config::get( 'saas_v2.enabled' ) && true === Config::get( 'saas_v2.domain' ) );
$swdb->run( 'DELETE FROM settings WHERE name = ?', array( 'switches' ) );
$swdb->run( 'INSERT INTO settings (name, val) VALUES (?,?)', array( 'switches', '{"saas_v2":"si","altro":true}' ) );
Config::set( 'saas_v2', array( 'enabled' => true, 'domain' => false ) );
Portale\Switches::load( $swdb );
check( 'interruttori: valori non booleani o chiavi ignote non toccano la configurazione', true === Config::get( 'saas_v2.enabled' ) && false === Config::get( 'saas_v2.domain' ) );
Config::set( 'saas_v2', array() );
Http::$transport = $prevTransport;
Config::set( 'saas_public_keys', array() );
echo $fails ? "\n$fails prove fallite\n" : "\nTutte le prove sono passate\n";
exit( $fails ? 1 : 0 );

<?php
// Lavori giornalieri: avvisi di scadenza per le licenze che non si rinnovano da sole.
// Da lanciare una volta al giorno, per esempio: 0 8 * * * /usr/local/bin/php ~/portale/bin/cron.php (con il php «cli»: da cron un semplice «php» è la versione CGI e lo script esce subito)
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrateOnStart();
Portale\Settings::load( $db );
$billing = new Portale\Billing( $db, new Portale\WegaClient( (string) Portale\Config::get( 'wega.base_url' ), (string) Portale\Config::get( 'wega.token' ) ) );
// Ogni lavoro è a sé: se uno fallisce l'errore finisce nel registro e gli altri partono lo stesso (prima un errore fermava tutto lo script).
// Un fallimento si vede: codice d'uscita 1 e una email all'indirizzo di contatto del portale (Pagine legali) con i lavori falliti.
$failed = array();
$step   = function ( string $label, callable $job ) use ( &$failed ): string {
	try {
		return date( 'c' ) . ' ' . $label . ': ' . (string) $job() . "\n";
	} catch ( \Throwable $e ) {
		$line     = date( 'c' ) . ' ERRORE in «' . $label . '»: ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		$failed[] = $line;
		return $line;
	}
};
echo $step( 'avvisi di scadenza inviati', function () use ( $billing ) {
	return $billing->sendReminders();
} );
echo $step( 'email del gestionale (scadenza del piano a pagamento)', function () use ( $db ) {
	return Portale\SaasCheckout::sendLifecycleMails( $db );
} );
echo $step( 'offerta di fine prova del gestionale', function () use ( $db ) {
	return Portale\SaasCheckout::sendTrialOffers( $db );
} );
echo $step( 'prove del gestionale finite (chiusura degli ordini)', function () use ( $db ) {
	return Portale\SaasCheckout::closeEndedTrials( $db );
} );
echo $step( 'licenze gratuite rinnovate / lasciate scadere per inattività', function () use ( $billing ) {
	list( $renewed, $lapsed ) = $billing->autoRenewFree();
	return $renewed . ' / ' . $lapsed;
} );
if ( $failed ) {
	try {
		Portale\Mailer::send( Portale\Legal::vars()['email'], 'Portale: lavori giornalieri non riusciti', "Il lavoro giornaliero del portale (bin/cron.php) ha avuto errori. Gli altri lavori sono partiti.\n\n" . implode( '', $failed ) . "\nIl registro completo è ~/logs/portale-cron.log sul server.\n" );
	} catch ( \Throwable $e ) {
		echo date( 'c' ), ' avviso non inviato: ', $e->getMessage(), "\n";
	}
	exit( 1 );
}

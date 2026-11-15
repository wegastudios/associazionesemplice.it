<?php
// Lavori giornalieri: avvisi di scadenza per le licenze che non si rinnovano da sole.
// Da lanciare una volta al giorno, per esempio: 0 8 * * * /usr/local/bin/php ~/portale/bin/cron.php (con il php «cli»: da cron un semplice «php» è la versione CGI e lo script esce subito)
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
$billing = new Portale\Billing( $db, new Portale\WegaClient( (string) Portale\Config::get( 'wega.base_url' ), (string) Portale\Config::get( 'wega.token' ) ) );
// Ogni lavoro è a sé: se uno fallisce l'errore finisce nel registro e gli altri partono lo stesso (prima un errore fermava tutto lo script).
$step = function ( string $label, callable $job ): string {
	try {
		return date( 'c' ) . ' ' . $label . ': ' . (string) $job() . "\n";
	} catch ( \Throwable $e ) {
		return date( 'c' ) . ' ERRORE in «' . $label . '»: ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
	}
};
echo $step( 'avvisi di scadenza inviati', function () use ( $billing ) {
	return $billing->sendReminders();
} );
echo $step( 'email del gestionale (scadenza, sospensione)', function () use ( $db ) {
	return Portale\SaasCheckout::sendLifecycleMails( $db );
} );
echo $step( 'email di fine prova del gestionale', function () use ( $db ) {
	return Portale\SaasCheckout::sendTrialMails( $db );
} );
echo $step( 'licenze gratuite rinnovate / lasciate scadere per inattività', function () use ( $billing ) {
	list( $renewed, $lapsed ) = $billing->autoRenewFree();
	return $renewed . ' / ' . $lapsed;
} );

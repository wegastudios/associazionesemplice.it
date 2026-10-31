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
echo date( 'c' ), ' avvisi di scadenza inviati: ', $billing->sendReminders(), "\n";
list( $renewed, $lapsed ) = $billing->autoRenewFree();
echo date( 'c' ), ' licenze gratuite rinnovate: ', $renewed, ', lasciate scadere per inattività: ', $lapsed, "
";

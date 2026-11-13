<?php
// Nuovi tentativi di pagamento del gestionale dopo un rifiuto: da lanciare ogni ora, per esempio: 0 * * * * /usr/local/bin/php ~/portale/bin/retry.php
// (con il php «cli»). Non fa nulla finché in config.php non c'è 'saas_retry' => true.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
echo date( 'c' ), ' nuovi tentativi di pagamento del gestionale: ', Portale\SaasCheckout::retryPayments( $db ), "\n";

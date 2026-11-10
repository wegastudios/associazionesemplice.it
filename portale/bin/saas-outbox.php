<?php
// Invia al SaaS i messaggi di abbonamento in coda e ritenta quelli falliti (1, 5, 30 minuti, poi ogni ora).
// Da cron ogni 5 minuti: */5 * * * * /usr/local/bin/php ~/portale/bin/saas-outbox.php >> ~/logs/portale-saas-outbox.log 2>&1
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
list( $sent, $left ) = Portale\Outbox::run( $db );
if ( $sent || $left ) {
	echo date( 'c' ), ' abbonamenti inviati al SaaS: ', $sent, ', da ritentare: ', $left, "\n";
}

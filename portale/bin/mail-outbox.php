<?php
// Smaltisce la coda di uscita delle email (tetto orario del server di posta, ritentativi): gli urgenti prima, poi i massivi.
// Da cron ogni 5 minuti: */5 * * * * /usr/local/bin/php ~/portale/bin/mail-outbox.php >> ~/logs/portale-mail-outbox.log 2>&1
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrateOnStart();
Portale\Mailer::$db = $db;
list( $sent, $left, $gone ) = Portale\Mailer::drain( $db );
if ( $sent || $gone || $left > 25 ) {
	echo date( 'c' ), ' email inviate: ', $sent, ', in coda: ', $left, ', abbandonate: ', $gone, "\n";
}

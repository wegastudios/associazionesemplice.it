<?php
// Lascia un solo accesso alla gestione del plugin (/wordpress/admin) e toglie tutti gli altri. Non cancella nessun cliente né i suoi dati:
// toglie solo i ruoli di staff. Il livello SaaS (colonna `saas` di `staff`) non si tocca.
//   php bin/staff-keep-only.php indirizzo@esempio.it            mostra cosa farebbe (non scrive nulla)
//   php bin/staff-keep-only.php indirizzo@esempio.it --apply    lo fa
// Gli amministratori elencati in `admins` di config.php NON si cambiano da qui: il programma li mostra e vanno tolti a mano dal file.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$keep  = strtolower( trim( (string) ( $argv[1] ?? '' ) ) );
$apply = in_array( '--apply', $argv, true );
if ( '' === $keep || false === strpos( $keep, '@' ) ) {
	fwrite( STDERR, "Uso: php bin/staff-keep-only.php indirizzo@esempio.it [--apply]\n" );
	exit( 2 );
}
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
if ( Portale\Plugin\Staff::ADMIN !== Portale\Plugin\Staff::role( $db, $keep ) ) {
	fwrite( STDERR, "L'indirizzo indicato non è oggi un amministratore della gestione del plugin: non tolgo nessuno per non restare senza accesso.\n" );
	exit( 1 );
}
$others = array();
foreach ( $db->all( 'SELECT email FROM plugin_staff WHERE email <> ?', array( $keep ) ) as $r ) {
	$others[ $r['email'] ] = true;
}
foreach ( $db->all( "SELECT email FROM staff WHERE role IN ('admin','support') AND email <> ?", array( $keep ) ) as $r ) {
	$others[ $r['email'] ] = true;
}
echo count( $others ), ' accessi alla gestione del plugin da togliere', $apply ? '' : ' (prova: non scrivo nulla)', ":\n";
foreach ( array_keys( $others ) as $e ) {
	echo ' - ', $e, "\n";
}
$cfg = array_filter( (array) Portale\Config::get( 'admins', array() ), function ( $a ) use ( $keep ) {
	return strtolower( trim( (string) $a ) ) !== $keep;
} );
if ( $cfg ) {
	echo "Attenzione: in config.php, voce `admins`, restano questi indirizzi con accesso pieno (da togliere a mano dal file):\n";
	foreach ( $cfg as $a ) {
		echo ' - ', $a, "\n";
	}
}
if ( ! $apply ) {
	exit( 0 );
}
$db->run( 'DELETE FROM plugin_staff WHERE email <> ?', array( $keep ) );
$db->run( "UPDATE staff SET role = 'none' WHERE role IN ('admin','support') AND email <> ?", array( $keep ) );
$db->run( "DELETE FROM staff_roles WHERE area = 'wordpress' AND person_id <> ?", array( Portale\Plugin\Directory::personId( $keep ) ) );
Portale\Plugin\Directory::audit( $db, 'server', '', 'keep_only', 'wordpress: lasciato un solo accesso (' . count( $others ) . ' tolti)' );
echo "Fatto: ora l'unico accesso alla gestione del plugin è quello indicato.\n";

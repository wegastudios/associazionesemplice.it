<?php
// Aggiunge o firma una versione del plugin ai pacchetti scaricabili (di solito lanciato da release.sh, che firma sul computer di chi pubblica):
//   php bin/release.php free|pro file.zip ["nota"] [firma kid]      pubblica una versione (la firma Ed25519 base64url è facoltativa ma senza non si aggiorna da WordPress)
//   php bin/release.php sign free|pro VERSIONE firma kid             aggiunge la firma a una versione già pubblicata
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
if ( $argc < 3 ) {
	fwrite( STDERR, "Uso: php bin/release.php free|pro file.zip [nota] [firma kid] | php bin/release.php sign free|pro versione firma kid\n" );
	exit( 2 );
}
try {
	if ( 'sign' === $argv[1] ) {
		if ( $argc < 6 ) {
			fwrite( STDERR, "Uso: php bin/release.php sign free|pro versione firma kid\n" );
			exit( 2 );
		}
		Portale\Releases::sign( $db, $argv[2], $argv[3], $argv[4], $argv[5], 'cli' );
		echo 'Firmata ', $argv[2], ' ', $argv[3], "\n";
		exit( 0 );
	}
	$row = Portale\Releases::add( $db, $argv[1], $argv[2], $argv[3] ?? '', 'cli', null, $argv[4] ?? null, $argv[5] ?? null );
	echo 'Pubblicata ', $argv[1], ' ', $row['version'], ' (', $row['size'], ' byte, sha256 ', $row['sha256'], ! empty( $row['signature'] ) ? ', firmata' : ', NON firmata: niente aggiornamento diretto', ")\n";
} catch ( Portale\UserError $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

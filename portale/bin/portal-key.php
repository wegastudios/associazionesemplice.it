<?php
// Crea la chiave con cui il portale firma il livello delle licenze (data/portal-sign.key, permessi 600) se non esiste già,
// e stampa SOLO l'identificativo (kid) e la chiave pubblica (`x`, base64url) da fissare nel plugin. La chiave privata non si stampa mai.
//   php bin/portal-key.php          crea (se manca) e mostra kid e chiave pubblica
//   php bin/portal-key.php --show   mostra senza creare
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$file = (string) Portale\Config::get( 'portal_sign_key_file', dirname( __DIR__ ) . '/data/portal-sign.key' );
if ( ! is_file( $file ) && '' === (string) Portale\Config::get( 'portal_sign_key', '' ) ) {
	if ( in_array( '--show', $argv, true ) ) {
		fwrite( STDERR, "Nessuna chiave: lancia senza --show per crearla.\n" );
		exit( 2 );
	}
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0750, true );
	}
	$old = umask( 0177 );
	file_put_contents( $file, base64_encode( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
	umask( $old );
	chmod( $file, 0600 );
	echo "Chiave creata in $file\n";
}
if ( ! Portale\PortalAttestation::enabled() ) {
	fwrite( STDERR, "La chiave esiste ma non è valida (servono 32 byte in base64).\n" );
	exit( 1 );
}
echo 'kid: ', Portale\PortalAttestation::kid(), "\n";
echo 'x:   ', Portale\PortalAttestation::publicKey(), "\n";

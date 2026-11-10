<?php
// Crea la chiave con cui il portale firma il catalogo del plugin (data/catalog-sign.key, permessi 600) se non esiste già,
// e stampa SOLO l'identificativo (kid) e la chiave pubblica (base64url) da fissare in chi legge il catalogo. La privata non si stampa mai.
// È una chiave NUOVA e separata da quella delle licenze (portal-sign.key), del contratto col SaaS e di admin_hop.
//   php bin/catalog-key.php          crea (se manca) e mostra kid e chiave pubblica
//   php bin/catalog-key.php --show   mostra senza creare
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$file = (string) Portale\Config::get( 'catalog_sign_key_file', dirname( __DIR__ ) . '/data/catalog-sign.key' );
if ( ! is_file( $file ) && '' === (string) Portale\Config::get( 'catalog_sign_key', '' ) ) {
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
if ( ! Portale\Plugin\CatalogSigner::enabled() ) {
	fwrite( STDERR, "La chiave esiste ma non è valida (servono 32 byte in base64).\n" );
	exit( 1 );
}
echo 'kid: ', Portale\Plugin\CatalogSigner::kid(), "\n";
echo 'x:   ', Portale\Plugin\CatalogSigner::publicKey(), "\n";

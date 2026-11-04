<?php
// Custodia nel vault di Wega una copia di riserva già CIFRATA di un file (per esempio la chiave dei pacchetti):
//   php bin/vault.php store /percorso/file.cifrata.pem [nome]     carica il file (max 5 MB); stampa id e sha256 per il confronto
//   php bin/vault.php list                                          elenca i file custoditi (solo metadati)
// Il contenuto non si scarica via API: il recupero è manuale, su richiesta all'amministratore Wega. La passphrase non passa mai da qui.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
$wega = new Portale\WegaClient( (string) Portale\Config::get( 'wega.base_url' ), (string) Portale\Config::get( 'wega.token' ) );
try {
	if ( 'list' === ( $argv[1] ?? '' ) ) {
		echo json_encode( $wega->vaultList(), JSON_PRETTY_PRINT ), "\n";
		exit( 0 );
	}
	if ( 'store' !== ( $argv[1] ?? '' ) || empty( $argv[2] ) || ! is_file( $argv[2] ) ) {
		fwrite( STDERR, "Uso: php bin/vault.php store file [nome] | list\n" );
		exit( 2 );
	}
	$content = (string) file_get_contents( $argv[2] );
	if ( false === strpos( $content, 'ENCRYPTED' ) && false === strpos( $content, 'age-encryption' ) && false === strpos( $content, 'Salted__' ) ) {
		fwrite( STDERR, "Il file non sembra cifrato: nel vault va solo materiale già cifrato.\n" );
		exit( 3 );
	}
	$r = $wega->vaultStore( $argv[3] ?? basename( $argv[2] ), $content );
	echo 'Custodito: id ', $r['id'] ?? '?', ', ', $r['size'] ?? '?', ' byte, sha256 ', $r['sha256'] ?? '?', ', in data ', $r['stored_at'] ?? '?', "\n";
} catch ( Portale\WegaException $e ) {
	fwrite( STDERR, 'Wega: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ') ' . $e->getMessage() . "\n" );
	exit( 1 );
}

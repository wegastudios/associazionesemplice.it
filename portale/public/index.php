<?php
require __DIR__ . '/../src/bootstrap.php';

$file = __DIR__ . '/../config.php';
if ( ! is_file( $file ) ) {
	http_response_code( 500 );
	exit( 'Configurazione mancante: copia config.example.php in config.php.' );
}
Portale\Config::load( require $file );

$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );
session_set_cookie_params( array( 'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax' ) );
// Le API per i plugin, i webhook e le chiavi pubbliche non usano la sessione: niente cookie né file di sessione per ogni chiamata (chiunque può farne molte).
$apiPath = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( 0 !== strpos( $apiPath, '/api/' ) && 0 !== strpos( $apiPath, '/webhook/' ) && 0 !== strpos( $apiPath, '/.well-known/' ) ) {
	session_start();
} else {
	$_SESSION = array();
}

$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
$billing = new Portale\Billing( $db, new Portale\WegaClient( (string) Portale\Config::get( 'wega.base_url' ), (string) Portale\Config::get( 'wega.token' ) ) );

( new Portale\App( $db, $billing, new Portale\Auth( $db ) ) )->run(
	$_SERVER['REQUEST_METHOD'] ?? 'GET',
	(string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH )
);

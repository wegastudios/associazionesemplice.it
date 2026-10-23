<?php
require __DIR__ . '/../src/bootstrap.php';

$file = __DIR__ . '/../config.php';
if ( ! is_file( $file ) ) {
	http_response_code( 500 );
	exit( 'Configurazione mancante: copia config.example.php in config.php.' );
}
Portale\Config::load( require $file );

// Altri nomi del sito (dominio con il trattino, www): si va sempre all'indirizzo principale, mantenendo percorso e parametri.
$canonical = (string) parse_url( (string) Portale\Config::get( 'base_url', '' ), PHP_URL_HOST );
$reqHost   = strtolower( preg_replace( '/:\d+$/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
$aliases   = (array) Portale\Config::get( 'redirect_hosts', array( 'associazione-semplice.it', 'www.associazione-semplice.it', 'www.associazionesemplice.it' ) );
if ( '' !== $canonical && '' !== $reqHost && $reqHost !== $canonical && in_array( $reqHost, $aliases, true ) ) {
	header( 'Location: ' . rtrim( (string) Portale\Config::get( 'base_url' ), '/' ) . (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), true, 301 );
	exit;
}

$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );
session_set_cookie_params( array( 'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax' ) );
// Le API per i plugin, i webhook e le chiavi pubbliche non usano la sessione: niente cookie né file di sessione per ogni chiamata (chiunque può farne molte).
$apiPath = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( 0 !== strpos( $apiPath, '/api/' ) && 0 !== strpos( $apiPath, '/webhook/' ) && 0 !== strpos( $apiPath, '/.well-known/' ) ) {
	ini_set( 'session.use_strict_mode', '1' ); // un identificativo di sessione scelto da altri non viene accettato
	session_start();
} else {
	$_SESSION = array();
}

$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
// Una sessione senza attività per più di «session_hours» ore si chiude (una scheda dimenticata aperta non resta valida per sempre).
if ( ! empty( $_SESSION['customer'] ) ) {
	if ( ! empty( $_SESSION['seen'] ) && time() - (int) $_SESSION['seen'] > Portale\Settings::limit( 'session_hours' ) * 3600 ) {
		$_SESSION = array();
		session_regenerate_id( true );
	} else {
		$_SESSION['seen'] = time();
	}
}
$billing = new Portale\Billing( $db, new Portale\WegaClient( (string) Portale\Config::get( 'wega.base_url' ), (string) Portale\Config::get( 'wega.token' ) ) );

( new Portale\App( $db, $billing, new Portale\Auth( $db ) ) )->run(
	$_SERVER['REQUEST_METHOD'] ?? 'GET',
	(string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH )
);

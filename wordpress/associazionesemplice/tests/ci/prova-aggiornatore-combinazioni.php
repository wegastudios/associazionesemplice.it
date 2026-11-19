<?php
/**
 * Prova delle combinazioni gratuito + Pro (si lancia con `wp eval-file`, con i due plugin attivi):
 *  a) gratuito del portale (con il suo Updater) + Pro: un solo aggiornatore, la classe è del gratuito, si aggiornano entrambi dal portale;
 *  b) gratuito come esce per WordPress.org (senza Updater) + Pro: l'aggiornatore è la copia del Pro, tratta solo il Pro, mai il gratuito;
 *  c) Pro con livello di compatibilità diverso (qualunque gratuito): avviso con il pulsante e il Pro si controlla e si aggiorna senza errori.
 * La combinazione attesa (a, b o c) è il primo argomento di eval-file, così il test non si adatta a ciò che trova.
 */
defined( 'ABSPATH' ) || exit;

$expect = isset( $args[0] ) ? (string) $args[0] : ''; // argomento di wp eval-file: a, b o c
$fails  = 0;
$ok     = function ( bool $cond, string $what ) use ( &$fails ) {
	echo ( $cond ? 'ok' : 'NON ok' ) . ' - ' . $what . "\n";
	if ( ! $cond ) {
		++$fails;
	}
};

$free_has_updater = is_readable( ASEM_DIR . 'includes/Updater.php' );
$mismatch         = ASEM_API !== ASEM_PRO_API;
$ok( in_array( $expect, array( 'a', 'b', 'c' ), true ), 'combinazione attesa: ' . $expect );
$free_kind = isset( $args[1] ) ? (string) $args[1] : ''; // secondo argomento: portal (con Updater) o wporg (senza)
$ok( 'wporg' === $free_kind ? ! $free_has_updater : ( 'portal' === $free_kind && $free_has_updater ), 'il gratuito ' . ( $free_has_updater ? 'ha' : 'non ha' ) . ' il proprio Updater, come atteso per «' . $free_kind . '»' );
$ok( ( 'c' === $expect ) === $mismatch, 'livelli di compatibilità ' . ( $mismatch ? 'diversi' : 'uguali' ) . ' (gratuito ' . ASEM_API . ', Pro ' . ASEM_PRO_API . ')' );

// la classe c'è una volta sola e viene dal posto giusto
$ok( class_exists( 'AssociazioneSemplice\Updater', false ), 'l\'aggiornatore è caricato (nessun errore di classe dichiarata due volte)' );
$file = ( new ReflectionClass( 'AssociazioneSemplice\Updater' ) )->getFileName();
$ok( $free_has_updater ? realpath( ASEM_DIR . 'includes/Updater.php' ) === realpath( $file ) : realpath( ASEM_PRO_DIR . 'updater/Updater.php' ) === realpath( $file ), 'la classe viene da ' . basename( dirname( $file ) ) . '/' . basename( $file ) );
$n = 0;
global $wp_filter;
foreach ( (array) ( $wp_filter['pre_set_site_transient_update_plugins']->callbacks ?? array() ) as $cbs ) {
	foreach ( $cbs as $cb ) {
		if ( is_array( $cb['function'] ) && 'AssociazioneSemplice\Updater' === ( is_object( $cb['function'][0] ) ? get_class( $cb['function'][0] ) : $cb['function'][0] ) ) {
			++$n;
		}
	}
}
$ok( 1 === $n, 'l\'aggiornatore è agganciato una sola volta (' . $n . ')' );

// prodotti presi in carico
$prod = array_keys( \AssociazioneSemplice\Updater::products() );
sort( $prod );
$ok( $free_has_updater ? array( 'free', 'pro' ) === $prod : array( 'pro' ) === $prod, 'prodotti presi in carico: ' . implode( ', ', $prod ) );

// portale finto: registra le richieste
$calls = array();
add_filter( 'asem_portal_is_local', '__return_false' );
add_filter( 'home_url', function () { return 'https://www.Prova-Sito.it'; } ); // il dominio della licenza non può essere «localhost»
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls ) {
		if ( false === strpos( $url, '/api/plugin/update' ) ) {
			return $pre;
		}
		$b       = json_decode( (string) ( $args['body'] ?? '' ), true );
		$calls[] = $b;
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( array( 'product' => $b['product'], 'version' => '99.0', 'update' => true, 'package' => 'https://associazionesemplice.it/api/plugin/package?t=abc', 'url' => 'https://associazionesemplice.it/accedi' ) ),
			'headers'  => array(), 'cookies' => array(), 'filename' => null,
		);
	},
	10,
	3
);
\AssociazioneSemplice\Settings::update( array( 'limits' => array( 'portal_updates' => 1 ) ) );
if ( $mismatch ) { // senza le classi del Pro la chiave sta nell'opzione: la mette la prova come la metterebbe il Pro
	$opt                = (array) get_option( \AssociazioneSemplice\Settings::OPTION, array() );
	$opt['license_key'] = 'ASEM-PROVA-CHIAVE';
	update_option( \AssociazioneSemplice\Settings::OPTION, $opt );
	\AssociazioneSemplice\Settings::forget();
} else {
	\AssociazioneSemplice\Settings::update( array( 'license_key' => 'ASEM-PROVA-CHIAVE' ) );
}
wp_set_current_user( 1 );
delete_option( \AssociazioneSemplice\Updater::OPT );
delete_option( \AssociazioneSemplice\Updater::FORCE_OPT );
$res = \AssociazioneSemplice\Updater::check_now();
$ok( 'new' === $res, 'controlla aggiornamenti: il Pro chiede il suo aggiornamento al portale, con la chiave (esito ' . $res . ')' );
$pro_calls = array_values( array_filter( $calls, function ( $c ) { return 'pro' === $c['product']; } ) );
$ok( 1 === count( $pro_calls ) && 'ASEM-PROVA-CHIAVE' === ( $pro_calls[0]['license_key'] ?? '' ) && '' !== ( $pro_calls[0]['domain'] ?? '' ), 'la richiesta del Pro porta chiave e dominio' );

$tr = \AssociazioneSemplice\Updater::inject( (object) array( 'response' => array() ) );
delete_option( \AssociazioneSemplice\Updater::FORCE_OPT );
$pro_bn  = plugin_basename( ASEM_PRO_DIR . 'associazionesemplice-pro.php' );
$free_bn = plugin_basename( ASEM_FILE );
$ok( isset( $tr->response[ $pro_bn ] ) && 'asem-portal://pro' === $tr->response[ $pro_bn ]->package, 'il Pro compare tra gli aggiornamenti di WordPress' );
if ( $free_has_updater ) {
	\AssociazioneSemplice\Updater::refresh();
	$tr = \AssociazioneSemplice\Updater::inject( (object) array( 'response' => array() ) );
	$ok( isset( $tr->response[ $free_bn ], $tr->response[ $pro_bn ] ), 'a): gratuito e Pro si aggiornano entrambi dal portale' );
} else {
	$free_asked = array_filter( $calls, function ( $c ) { return 'free' === $c['product']; } );
	$ok( ! $free_asked && ! isset( $tr->response[ $free_bn ] ), 'b/c): il gratuito non viene mai preso in carico: nessuna richiesta, nessun aggiornamento proposto' );
	$ok( is_wp_error( \AssociazioneSemplice\Updater::download( false, 'asem-portal://free' ) ), 'b/c): lo scarico del gratuito dal portale non esiste' );
}

// avviso di incompatibilità con il pulsante
if ( $mismatch ) {
	$ok( ! class_exists( 'AssociazioneSemplice\ProBoot', false ), 'c): le funzioni del Pro restano sospese' );
	ob_start();
	do_action( 'admin_notices' );
	$html = (string) ob_get_clean();
	$ok( false !== strpos( $html, 'non è compatibile' ) && false !== strpos( $html, 'Aggiorna il Pro' ) && false !== strpos( $html, 'upgrade-plugin' ), 'c): con un aggiornamento pronto l\'avviso ha il pulsante «Aggiorna il Pro»' );
	update_option( \AssociazioneSemplice\Updater::OPT, array( 'checked_at' => \AssociazioneSemplice\Db::now(), 'pro' => array( 'version' => ASEM_PRO_VERSION, 'update' => false ) ), false );
	ob_start();
	do_action( 'admin_notices' );
	$html = (string) ob_get_clean();
	$ok( false !== strpos( $html, 'Controlla aggiornamenti' ) && false !== strpos( $html, 'asem_check_updates' ), 'c): senza aggiornamento l\'avviso ha il pulsante «Controlla aggiornamenti»' );
}

delete_option( \AssociazioneSemplice\Updater::OPT );
delete_option( \AssociazioneSemplice\Updater::FORCE_OPT );
echo $fails ? "\n$fails prove non riuscite\n" : "\nTutte le prove sono riuscite\n";
exit( $fails ? 1 : 0 );

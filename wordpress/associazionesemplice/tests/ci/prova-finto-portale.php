<?php
/**
 * Prova degli aggiornamenti contro un FINTO portale (solo CI), dentro un WordPress reale:
 *   wp eval-file wp-content/prove/prova-finto-portale.php
 * Presuppone (lo prepara il workflow plugin-prove-aggiornamenti.yml): free e Pro installati da una COPIA con versione 1.3.0 e con la chiave pubblica
 * dei pacchetti sostituita da quella di prova (keys.json); nessun codice di produzione cambia. Il finto portale (fake-portal/router.php) parte qui,
 * in un processo figlio, e risponde con le stesse forme del portale vero.
 */

use AssociazioneSemplice\License;
use AssociazioneSemplice\LicensePolicy;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Texts;
use AssociazioneSemplice\Updater;

define( 'ASEM_TESTING', true ); // abilita le scorciatoie dei test già previste dal plugin (sito «non locale», chiavi di prova)

function prova_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

$dir  = __DIR__;
$keys = json_decode( (string) file_get_contents( $dir . '/keys.json' ), true );
prova_ok( is_array( $keys ) && ! empty( $keys['pkg']['pub'] ) && ! empty( $keys['portal']['pub'] ), 'chiavi di prova lette' );
$scenario = sys_get_temp_dir() . '/asem-scenario.json';
function prova_scenario( array $s ): void {
	global $scenario;
	file_put_contents( $scenario, json_encode( $s ) );
}
prova_scenario( array() );

// ---------- Avvio del finto portale (processo figlio, solo su 127.0.0.1) ----------
$port = 18099;
$proc = proc_open(
	array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir . '/fake-portal', $dir . '/fake-portal/router.php' ),
	array( 1 => array( 'file', '/tmp/fake-portal.log', 'w' ), 2 => array( 'file', '/tmp/fake-portal.log', 'a' ) ),
	$pipes,
	$dir,
	array_merge( getenv(), array( 'FAKE_KEYS' => $dir . '/keys.json', 'FAKE_SCENARIO' => $scenario, 'FAKE_PKG' => $dir . '/pacchetto.zip' ) )
);
register_shutdown_function(
	function () use ( $proc ) {
		if ( is_resource( $proc ) ) {
			proc_terminate( $proc );
		}
	}
);
$up = false;
for ( $i = 0; $i < 50 && ! $up; $i++ ) {
	$fp = @fsockopen( '127.0.0.1', $port, $en, $es, 0.2 );
	if ( $fp ) {
		fclose( $fp );
		$up = true;
	} else {
		usleep( 100000 );
	}
}
prova_ok( $up, 'il finto portale risponde su 127.0.0.1:' . $port );

// ---------- Il plugin parla col finto portale (solo in questo processo di prova) ----------
add_filter( 'asem_portal_update_api', function () {
	return 'https://portal.fake.test/api/plugin/update';
} );
add_filter( 'asem_portal_is_local', '__return_false', 99 );
add_filter( 'home_url', function () {
	return 'https://prova.example.org';
}, 99 );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		foreach ( array( 'https://portal.fake.test/' => 'http://127.0.0.1:18099/', 'https://associazionesemplice.it/' => 'http://127.0.0.1:18099/' ) as $from => $to ) {
			if ( 0 === strpos( $url, $from ) ) {
				$args['reject_unsafe_urls'] = false;
				return wp_remote_request( $to . substr( $url, strlen( $from ) ), $args );
			}
		}
		return $pre;
	},
	10,
	3
);
define(
	'ASEM_PORTAL_JWKS',
	json_encode( array( 'keys' => array( array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $keys['portal']['kid'], 'x' => $keys['portal']['pub'], 'use' => 'sig', 'alg' => 'EdDSA' ) ) ) )
);

// La copia di prova porta la chiave pubblica di prova al posto di quella vera (la sostituzione la fa il workflow, solo nella copia).
$ref = new ReflectionClassConstant( Updater::class, 'PACKAGE_KEYS' );
prova_ok( array_keys( $ref->getValue() ) === array( $keys['pkg']['kid'] ), 'la copia di prova accetta solo la chiave di prova per i pacchetti' );
prova_ok( '1.3.0' === ASEM_VERSION && '1.3.0' === ASEM_PRO_VERSION, 'free e Pro di prova sono alla 1.3.0' );

function prova_reset(): void {
	delete_option( Updater::SEEN_OPT );
	delete_option( Updater::OPT );
}
function prova_dl( string $p ) {
	return Updater::download( false, 'asem-portal://' . $p );
}
function prova_accepted( $r, string $msg ): void {
	prova_ok( is_string( $r ) && is_file( $r ), $msg );
	wp_delete_file( $r );
}
function prova_refused( $r, string $why, string $msg ): void {
	static $texts = array(
		'invalid'    => 'Il pacchetto offerto dal portale non ha una firma valida: non è stato installato. Scaricalo dall\'area clienti e scrivici.',
		'min'        => 'La versione offerta dal portale è sotto la soglia minima ammessa: non è stata installata.',
		'rollback'   => 'La versione offerta dal portale è più vecchia di una già vista: non è stata installata.',
		'missing_v2' => 'Il pacchetto offerto dal portale non ha la firma del nuovo formato, richiesta dopo il primo pacchetto così firmato: non è stato installato. Scaricalo dall\'area clienti e scrivici.',
		'future'     => 'La firma del pacchetto ha una data nel futuro: non è stato installato. Controlla data e ora del server.',
	);
	prova_ok( is_wp_error( $r ) && 'asem_update_unsigned' === $r->get_error_code() && Texts::t( $texts[ $why ] ) === $r->get_error_message(), $msg . ' (motivo: ' . $why . ')' );
}
function prova_free( array $s ): void {
	prova_scenario( array( 'update' => array( 'free' => $s ) ) );
}
function prova_v2( string $version, string $min, array $extra = array() ): array {
	return $extra + array( 'version' => $version, 'v2' => true, 'signed_at' => time(), 'min_version' => $min );
}
function prova_seen( string $p ): array {
	$all = get_option( Updater::SEEN_OPT, array() );
	return is_array( $all ) && isset( $all[ $p ] ) ? $all[ $p ] : array();
}

// ---------- (a) il primo pacchetto con firma v2 è accettato (anche nell'elenco degli aggiornamenti di WordPress) ----------
prova_reset();
prova_free( prova_v2( '1.3.20', '1.3.0' ) );
$t = apply_filters( 'pre_set_site_transient_update_plugins', (object) array( 'response' => array() ) );
$b = plugin_basename( ASEM_FILE );
prova_ok( isset( $t->response[ $b ] ) && '1.3.20' === $t->response[ $b ]->new_version && 'asem-portal://free' === $t->response[ $b ]->package, '(a) Bacheca → Aggiornamenti: 1.3.20 offerta dal finto portale' );
prova_reset();
prova_accepted( prova_dl( 'free' ), '(a) primo pacchetto v2 accettato e scaricato' );
$seen = prova_seen( 'free' );
prova_ok( '1.3.20' === ( $seen['version'] ?? '' ) && '1.3.0' === ( $seen['min_version'] ?? '' ) && ! empty( $seen['v2'] ), '(a) ricordati versione, soglia e «v2 già visto»' );

// ---------- (b) dopo il primo v2, un pacchetto senza v2 è rifiutato ----------
prova_free( array( 'version' => '1.3.21' ) ); // solo firma v1, valida
prova_refused( prova_dl( 'free' ), 'missing_v2', '(b) pacchetto senza v2 dopo il primo v2 rifiutato' );

// ---------- (c) soglie e versioni già viste ----------
prova_free( prova_v2( '1.3.19', '1.3.0' ) );
prova_refused( prova_dl( 'free' ), 'rollback', '(c) versione sotto la più alta già vista rifiutata (1.3.19 < 1.3.20)' );
prova_free( prova_v2( '1.3.30', '1.3.25' ) );
prova_accepted( prova_dl( 'free' ), '(c) versione più alta con soglia 1.3.25 accettata' );
prova_free( prova_v2( '1.3.24', '1.3.0' ) );
prova_refused( prova_dl( 'free' ), 'min', '(c) versione sotto la soglia già vista rifiutata (1.3.24 < 1.3.25)' );
prova_free( prova_v2( '1.3.31', '1.3.32' ) );
prova_refused( prova_dl( 'free' ), 'min', '(c) versione sotto il proprio min_version rifiutata (1.3.31 < 1.3.32)' );
prova_free( prova_v2( '1.3.30', '1.3.25' ) );
prova_accepted( prova_dl( 'free' ), '(c) stessa versione ripubblicata accettata (1.3.30)' );
prova_free( prova_v2( '1.3.40', '1.3.0', array( 'bad_sig' => true ) ) );
prova_refused( prova_dl( 'free' ), 'invalid', '(c) firma v2 di una chiave estranea rifiutata' );
prova_free( prova_v2( '1.3.41', '1.3.0', array( 'signed_at' => time() + 3 * 86400 ) ) );
prova_refused( prova_dl( 'free' ), 'future', '(c) firma datata nel futuro rifiutata' );
$seen = prova_seen( 'free' );
prova_ok( '1.3.30' === ( $seen['version'] ?? '' ) && '1.3.25' === ( $seen['min_version'] ?? '' ), '(c) i rifiuti non hanno abbassato né alzato quanto già visto' );

// ---------- Pro: la chiave di licenza (finta) viaggia solo per il Pro; senza o con chiave sbagliata non si scarica ----------
prova_reset();
$licence = 'PROVA-' . bin2hex( random_bytes( 8 ) );
Settings::update( array( 'license_key' => $licence ) );
prova_scenario( array( 'license_key' => $licence, 'update' => array( 'pro' => prova_v2( '1.3.20', '1.3.0' ) ) ) );
prova_accepted( prova_dl( 'pro' ), 'Pro: con la chiave giusta il pacchetto v2 si scarica' );
prova_scenario( array( 'license_key' => 'un-altra-chiave', 'update' => array( 'pro' => prova_v2( '1.3.21', '1.3.0' ) ) ) );
$r = prova_dl( 'pro' );
prova_ok( is_wp_error( $r ) && 'asem_update_unavailable' === $r->get_error_code(), 'Pro: con la chiave sbagliata il portale dice 403 e non si scarica' );

// ---------- (d) esito negativo firmato: vale solo al secondo, in giorni UTC diversi (si forza iat) ----------
\AssociazioneSemplice\LicenseClient::set_local_override( false ); // sito «non locale»: la verifica della licenza è attiva
prova_ok( \AssociazioneSemplice\LicenseClient::enabled() && '' !== \AssociazioneSemplice\LicenseClient::normalize_domain( home_url() ), '(d) verifica della licenza attiva per il dominio di prova' );
function prova_deny( int $iat, array $extra = array() ): array {
	prova_scenario( array( 'license' => $extra + array( 'rc' => 'EXPIRED', 'iat' => $iat ) ) );
	return License::sync();
}
$unpaid = function () {
	return LicensePolicy::STATUS_UNPAID === License::state()['status'];
};
$day0 = strtotime( gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) . ' 12:00:00 UTC' ); // due giorni fa, a mezzogiorno UTC (la firma dura 3 giorni: è ancora valida)
delete_option( License::OPT_DENIAL );
delete_option( License::OPT_STATE );
$r = prova_deny( $day0, array( 'bad_sig' => true ) );
prova_ok( false === $r['ok'] && false !== strpos( $r['message'], 'non ha una firma valida' ) && ! get_option( License::OPT_DENIAL ) && ! $unpaid(), '(d) esito negativo con firma sbagliata: non vale e non si ricorda' );
$r = prova_deny( $day0 );
$st = get_option( License::OPT_DENIAL, array() );
prova_ok( false === $r['ok'] && ( $st['day'] ?? '' ) === gmdate( 'Y-m-d', $day0 ) && ! $unpaid(), '(d) primo esito negativo firmato: solo ricordato, la licenza non cambia' );
$r = prova_deny( $day0 + 10 );
prova_ok( false === $r['ok'] && ! $unpaid(), '(d) secondo esito negativo nello stesso giorno UTC: ancora non si applica' );
$r = prova_deny( time() - 5 );
prova_ok( $unpaid(), '(d) secondo esito negativo in un giorno UTC diverso: la licenza risulta non in regola (unpaid)' );

WP_CLI::success( 'Prove contro il finto portale: tutte superate.' );

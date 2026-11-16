<?php
/**
 * Finto portale per le prove di aggiornamento (solo CI). Si lancia con `php -S 127.0.0.1:PORTA router.php` e legge:
 *   FAKE_KEYS     keys.json (chiavi di prova, vedi genkeys.php)
 *   FAKE_SCENARIO scenario.json (cosa rispondere adesso, scritto dalla prova)
 *   FAKE_PKG      lo zip da servire come «pacchetto»
 * Imita la forma del portale vero: POST /api/plugin/update (version, update, package, sha256, signature, kid, signature_v2, kid_v2, signed_at,
 * min_version), GET /api/plugin/package, POST /api/license/validate (esito negativo con portal_attestation firmata).
 */
function asem_b64u( string $s ): string {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}
function asem_sign( string $msg, array $k ): string {
	return asem_b64u( sodium_crypto_sign_detached( $msg, base64_decode( $k['secret'] ) ) );
}
function asem_out( int $code, array $data ): void {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data, JSON_UNESCAPED_SLASHES );
	exit;
}

$keys = json_decode( (string) file_get_contents( (string) getenv( 'FAKE_KEYS' ) ), true );
$sc   = json_decode( (string) @file_get_contents( (string) getenv( 'FAKE_SCENARIO' ) ), true );
$sc   = is_array( $sc ) ? $sc : array();
$path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$in   = json_decode( (string) file_get_contents( 'php://input' ), true );
$in   = is_array( $in ) ? $in : array();
$post = 'POST' === $_SERVER['REQUEST_METHOD'];

if ( '/api/plugin/package' === $path ) {
	header( 'Content-Type: application/zip' );
	readfile( (string) getenv( 'FAKE_PKG' ) );
	exit;
}

if ( '/api/plugin/update' === $path && $post ) {
	$p   = (string) ( $in['product'] ?? '' );
	$cur = (string) ( $in['version'] ?? '' );
	$s   = $sc['update'][ $p ] ?? null;
	if ( ! is_array( $s ) ) {
		asem_out( 404, array( 'error' => array( 'code' => 'NO_RELEASE', 'message' => 'Nessuna versione firmata pubblicata.' ) ) );
	}
	if ( 'pro' === $p && (string) ( $in['license_key'] ?? '' ) !== (string) ( $sc['license_key'] ?? "\0" ) ) {
		asem_out( 403, array( 'error' => array( 'code' => 'LICENSE_REQUIRED', 'message' => 'Per aggiornare il Pro serve una licenza in regola.' ) ) );
	}
	$latest = (string) $s['version'];
	$newer  = '' === $cur || version_compare( $latest, $cur, '>' );
	$sha    = hash_file( 'sha256', (string) getenv( 'FAKE_PKG' ) );
	$k      = ! empty( $s['bad_sig'] ) ? $keys['other'] : $keys['pkg']; // chiave estranea: la firma non torna
	$o      = array(
		'product'   => $p,
		'version'   => $latest,
		'update'    => $newer,
		'package'   => $newer ? 'https://portal.fake.test/api/plugin/package?t=' . $p : null,
		'sha256'    => $newer ? $sha : null,
		'signature' => $newer ? asem_sign( $p . '|' . $latest . '|' . $sha, $k ) : null,
		'kid'       => $newer ? $keys['pkg']['kid'] : null,
		'url'       => 'https://portal.fake.test/accedi',
	);
	if ( $newer && ! empty( $s['v2'] ) ) {
		$at                = (int) $s['signed_at'];
		$min               = (string) $s['min_version'];
		$o['signature_v2'] = asem_sign( 'asem-pkg-v2|' . $p . '|' . $latest . '|' . $sha . '|' . $at . '|' . $min, $k );
		$o['kid_v2']       = $keys['pkg']['kid'];
		$o['signed_at']    = $at;
		$o['min_version']  = $min;
	}
	asem_out( 200, $o );
}

if ( '/api/license/validate' === $path && $post ) {
	$l = $sc['license'] ?? null;
	if ( ! is_array( $l ) ) {
		asem_out( 404, array( 'error' => array( 'code' => 'NOT_CONFIGURED' ) ) );
	}
	$key    = (string) ( $in['license_key'] ?? '' );
	$claims = array(
		'iss' => 'https://associazionesemplice.it',
		'aud' => 'asem-test',
		'bid' => (string) ( $in['domain'] ?? '' ),
		'res' => 'deny',
		'rc'  => (string) $l['rc'],
		'kh'  => substr( hash( 'sha256', $key ), 0, 16 ),
		'iat' => (int) $l['iat'],
		'exp' => (int) $l['iat'] + 259200,
	);
	$head   = asem_b64u( json_encode( array( 'typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $keys['portal']['kid'] ) ) );
	$body   = asem_b64u( json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
	$k      = ! empty( $l['bad_sig'] ) ? $keys['other'] : $keys['portal'];
	asem_out( 200, array( 'valid' => false, 'reason_code' => (string) $l['rc'], 'product_code' => 'asem-test', 'plan_name' => 'free', 'portal_attestation' => $head . '.' . $body . '.' . asem_sign( $head . '.' . $body, $k ) ) );
}

asem_out( 404, array( 'error' => array( 'code' => 'NOT_FOUND' ) ) );

<?php
/**
 * Genera le chiavi Ed25519 DI PROVA per il finto portale (solo CI): mai chiavi vere, mai committate.
 * Uso: php genkeys.php /percorso/keys.json
 * pkg = firma dei pacchetti, portal = firma dell'attestazione del portale, other = chiave estranea (per le firme sbagliate).
 */
$out = $argv[1] ?? '';
if ( '' === $out ) {
	fwrite( STDERR, "Uso: php genkeys.php keys.json\n" );
	exit( 1 );
}
function asem_prova_gen( string $prefix ): array {
	$kp = sodium_crypto_sign_keypair();
	return array(
		'kid'    => $prefix . bin2hex( random_bytes( 4 ) ),
		'secret' => base64_encode( sodium_crypto_sign_secretkey( $kp ) ),
		'pub'    => rtrim( strtr( base64_encode( sodium_crypto_sign_publickey( $kp ) ), '+/', '-_' ), '=' ),
	);
}
file_put_contents( $out, json_encode( array( 'pkg' => asem_prova_gen( 'pkg-test-' ), 'portal' => asem_prova_gen( 'portale-test-' ), 'other' => asem_prova_gen( 'altra-' ) ) ) );

<?php
/**
 * Solo per la CI (costo-fisso.sh): stampa i cookie di accesso del primo amministratore del sito di prova, per misurare anche le richieste di chi ha fatto l'accesso.
 * Uso: wp eval-file costo-fisso-cookie.php
 */
defined( 'ABSPATH' ) || exit;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
$uid    = (int) ( $admins[0] ?? 0 );
if ( ! $uid ) {
	fwrite( STDERR, "Nessun amministratore nel sito di prova\n" );
	exit( 1 );
}
$exp = time() + 3600;
echo 'ASEMCOOKIE=' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( $uid, $exp, 'auth' ) . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $uid, $exp, 'logged_in' ) . "\n";

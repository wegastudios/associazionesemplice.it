<?php
/**
 * Plugin Name: Demo di Associazione Semplice
 * Description: Protezioni della demo pubblica: nessuna email in uscita, profili e password dei due utenti di prova bloccati, nessun caricamento di file, avviso in ogni pagina.
 */
defined( 'ABSPATH' ) || exit;

// Nessuna email esce da qui (promemoria, link di accesso, comunicazioni): la demo non scrive mai a nessuno.
add_filter( 'pre_wp_mail', '__return_true' );

// Le credenziali sono pubbliche: nessuno deve poterle cambiare.
add_filter( 'allow_password_reset', '__return_false' );
add_action( 'user_profile_update_errors', function ( $errors ) {
	$errors->add( 'asem_demo', 'Nella demo il profilo e la password non si possono cambiare.' );
} );
add_filter( 'send_password_change_email', '__return_false' );
add_filter( 'send_email_change_email', '__return_false' );
add_action( 'password_reset', function () {
	wp_die( 'Nella demo la password non si può cambiare.', '', array( 'response' => 403 ) );
} );
add_action( 'init', function () {
	if ( ! is_user_logged_in() || empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( isset( $_POST['pass1'] ) || isset( $_POST['pass2'] ) || isset( $_POST['new_password'] ) || isset( $_POST['password'] ) && isset( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_die( 'Nella demo la password non si può cambiare.', '', array( 'response' => 403, 'back_link' => true ) );
	}
} );

// La segreteria entra direttamente nella Bacheca del plugin (il profilo non serve a niente qui).
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'asem_secretary', (array) $user->roles, true ) && ( '' === (string) $requested || false !== strpos( (string) $requested, 'wp-admin/' ) && false === strpos( (string) $requested, 'page=' ) ) ) {
		return admin_url( 'admin.php?page=asem' );
	}
	return $to;
}, 20, 3 );

// Nessun file caricato dai visitatori (ricevute, allegati, immagini).
add_filter( 'wp_check_filetype_and_ext', function ( $data ) {
	return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
}, 99 );

// Niente indicizzazione e un avviso ben visibile.
add_filter( 'wp_robots', function ( $robots ) {
	return array( 'noindex' => true, 'nofollow' => true );
} );
$asem_demo_note = 'Questa è una <strong>demo pubblica</strong> di Associazione Semplice: i dati sono inventati, non vengono inviate email e tutto si azzera ogni notte alle 4.';
add_action( 'admin_notices', function () use ( $asem_demo_note ) {
	echo '<div class="notice notice-info"><p>' . wp_kses( $asem_demo_note, array( 'strong' => array() ) ) . '</p></div>';
} );
add_action( 'wp_footer', function () use ( $asem_demo_note ) {
	echo '<div style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#1f218c;color:#fff;padding:8px 16px;font:14px/1.4 system-ui,sans-serif;text-align:center">' . wp_kses( $asem_demo_note, array( 'strong' => array() ) ) . '</div>';
} );

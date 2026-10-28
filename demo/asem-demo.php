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

// La licenza della demo è nostra: chi ha tutti i permessi del plugin non la vede né la cambia (la chiave si nasconde da ogni pagina del pannello).
add_action( 'admin_post_asem_save_license', function () {
	wp_die( 'Nella demo la licenza non si cambia.', '', array( 'response' => 403, 'back_link' => true ) );
}, 0 );
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['page'] ) || ! class_exists( '\AssociazioneSemplice\License' ) ) { // solo le pagine del pannello: mai gli scarichi (come la copia di sicurezza)
		return;
	}
	$k = (string) \AssociazioneSemplice\License::key();
	if ( strlen( $k ) < 8 ) {
		return;
	}
	ob_start( function ( $html ) use ( $k ) {
		return str_replace( array( $k, esc_attr( $k ), rawurlencode( $k ) ), '••••••••••••', (string) $html );
	} );
}, 0 );
// Nessuna chiave vera in una demo pubblica: avviso nelle schermate dei pagamenti e delle impostazioni.
add_action( 'admin_notices', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( in_array( $page, array( 'asem-settings', 'asem-payments', 'asem-tech', 'asem-wpai' ), true ) ) {
		echo '<div class="notice notice-warning"><p><strong>Non inserire chiavi vere</strong> (Stripe, PayPal, Brevo, Google…): la demo è pubblica e le chiavi resterebbero salvate fino all\'azzeramento di notte.</p></div>';
	}
} );

// La segreteria entra direttamente nella Bacheca del plugin (il profilo non serve a niente qui).
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( $user instanceof WP_User && array_intersect( array( 'asem_secretary', 'asem_demo_admin' ), (array) $user->roles ) && ( '' === (string) $requested || false !== strpos( (string) $requested, 'wp-admin/' ) && false === strpos( (string) $requested, 'page=' ) ) ) {
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
// Uscire dalla demo: un collegamento sempre in vista (per cambiare accesso, da segreteria a socio e viceversa) che riporta alla pagina iniziale.
$asem_demo_exit = function (): string {
	if ( ! is_user_logged_in() ) {
		return ' <a href="' . esc_url( wp_login_url( home_url( '/' ) ) ) . '" style="color:inherit;font-weight:700">Entra</a>';
	}
	$u = wp_get_current_user();
	return ' Sei entrato come <strong>' . esc_html( $u->user_login ) . '</strong> · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '" style="color:inherit;font-weight:700">Esci</a>';
};
add_action( 'admin_notices', function () use ( $asem_demo_note, $asem_demo_exit ) {
	echo '<div class="notice notice-info"><p>' . wp_kses( $asem_demo_note, array( 'strong' => array() ) ) . wp_kses( $asem_demo_exit(), array( 'strong' => array(), 'a' => array( 'href' => array(), 'style' => array() ) ) ) . '</p></div>';
} );
add_action( 'wp_footer', function () use ( $asem_demo_note, $asem_demo_exit ) {
	echo '<div style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#1f218c;color:#fff;padding:8px 16px;font:14px/1.4 system-ui,sans-serif;text-align:center">' . wp_kses( $asem_demo_note, array( 'strong' => array() ) ) . wp_kses( $asem_demo_exit(), array( 'strong' => array(), 'a' => array( 'href' => array(), 'style' => array() ) ) ) . '</div>';
} );
// Anche nella barra in alto un «Esci» diretto, senza aprire il menu del profilo.
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( is_user_logged_in() ) {
		$bar->add_node( array( 'id' => 'asem-demo-exit', 'title' => 'Esci dalla demo', 'href' => wp_logout_url( home_url( '/' ) ), 'parent' => 'top-secondary' ) );
	}
}, 5 );
// Il logout non chiede conferma: nella demo si passa spesso da un ruolo all'altro.
add_action( 'login_form_logout', function () {
	if ( isset( $_REQUEST['_wpnonce'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	wp_logout();
	wp_safe_redirect( home_url( '/' ) );
	exit;
}, 1 );

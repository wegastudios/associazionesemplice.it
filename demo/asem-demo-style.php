<?php
/**
 * Plugin Name: Aspetto della demo di Associazione Semplice
 * Description: Colori, carattere e logo del portale al posto del tema di serie: sito, accesso e pannello.
 */
defined( 'ABSPATH' ) || exit;

function asem_demo_asset( string $file ): string {
	return content_url( 'mu-plugins/asem-demo-assets/' . $file );
}

function asem_demo_css(): string {
	$f = function ( string $file, int $w ): string {
		return '@font-face{font-family:"Montserrat";font-weight:' . $w . ';font-style:normal;font-display:swap;src:url("' . esc_url( asem_demo_asset( $file ) ) . '") format("truetype")}';
	};
	return $f( 'Montserrat-Medium.ttf', 500 ) . $f( 'Montserrat-SemiBold.ttf', 600 ) . $f( 'Montserrat-Bold.ttf', 700 ) . '
:root{--asem-brand:#1f218c;--asem-brand-2:#15176b;--asem-soft:#e7e8f7;--asem-sage:#5f8579;--asem-sage-soft:#e3ece8;--asem-gold:#efca5c;--asem-ink:#14172e;--asem-muted:#566069;--asem-line:#dbe3de}
body{--wp--preset--color--base:#f6f8f6;--wp--preset--color--contrast:#14172e;--wp--preset--color--accent-1:#1f218c;--wp--preset--color--accent-2:#5f8579;--wp--preset--color--accent-3:#15176b;--wp--preset--color--accent-4:#566069;--wp--preset--color--accent-5:#e3ece8;--wp--preset--color--accent-6:#dbe3de;
	--wp--style--global--content-size:880px;--wp--style--global--wide-size:1160px;
	font-family:"Montserrat",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-weight:500;color:var(--asem-ink);background:#f6f8f6}
h1,h2,h3,h4,.wp-block-heading,.wp-block-post-title{font-family:"Montserrat",system-ui,sans-serif;font-weight:700;letter-spacing:-.02em;color:var(--asem-ink)}
a{color:var(--asem-brand)}
.wp-site-blocks>header{background:#fff;border-bottom:1px solid var(--asem-line);position:sticky;top:0;z-index:50}
.wp-site-blocks>header .wp-block-site-title{margin:0}
.wp-site-blocks>header .wp-block-site-title a{display:block;line-height:0}
.wp-site-blocks>header .asem-logo{height:44px;width:auto;display:block}
.wp-site-blocks>header .wp-block-navigation a{font-weight:600;color:var(--asem-muted);text-decoration:none;padding:8px 12px;border-radius:10px}
.wp-site-blocks>header .wp-block-navigation a:hover{background:var(--asem-soft);color:var(--asem-brand)}
.wp-block-button__link,.wp-element-button,button[type=submit],input[type=submit]{background:var(--asem-brand);color:#fff;border-radius:12px;font-weight:600;padding:.7em 1.3em;border:0;text-decoration:none}
.wp-block-button__link:hover,.wp-element-button:hover{background:var(--asem-brand-2);color:#fff}
.wp-block-button.is-style-outline .wp-block-button__link{background:transparent;color:var(--asem-brand);border:2px solid var(--asem-brand)}
.asem-hero{background:linear-gradient(135deg,#1f218c 0%,#15176b 60%,#3a3db8 100%);color:#fff;border-radius:24px;padding:56px 40px!important}
.asem-hero h1,.asem-hero p,.asem-hero a:not(.wp-block-button__link){color:#fff}
.asem-hero .wp-block-button__link{background:var(--asem-gold);color:#14172e}
.asem-hero .is-style-outline .wp-block-button__link{background:transparent;color:#fff;border:2px solid #fff}
.asem-card{background:#fff;border:1px solid var(--asem-line);border-radius:18px;padding:26px!important;box-shadow:0 1px 2px rgba(20,23,46,.06),0 8px 24px rgba(20,23,46,.06);height:100%}
.asem-card h3{margin-top:0}
.asem-card p{font-size:.98rem;line-height:1.6}
.wp-site-blocks>main{margin-top:0!important}
.wp-site-blocks>main>.wp-block-group{padding-top:24px!important}
body.home .wp-site-blocks>main>.wp-block-group{padding-top:12px!important}
.asem-cred{background:var(--asem-soft);border-radius:14px;padding:14px 16px;font-size:.95rem}
.asem-cred code{font-weight:700;background:#fff;border-radius:8px;padding:2px 8px}
.asem-foot{border-top:1px solid var(--asem-line);color:var(--asem-muted);font-size:.9rem;padding:28px 0 70px}
.asem-acc{background:#e7e8f7;border-radius:14px;padding:12px 16px;margin:0 0 12px;font-size:.95rem;color:#14172e;display:grid;grid-template-columns:1fr auto;gap:4px 12px;align-items:center}
.asem-acc-t{grid-column:1;align-self:center}
.asem-acc-r{grid-column:1/-1}
.asem-acc-k{display:inline-block;min-width:4.6em;color:#566069}
.asem-acc code{font-weight:700;background:#fff;border-radius:8px;padding:2px 8px;user-select:all;overflow-wrap:anywhere;font-size:.9em}
.asem-use{grid-column:2;grid-row:1;background:#1f218c;color:#fff;border:0;border-radius:12px;font-weight:700;font-size:1rem;min-height:44px;min-width:64px;padding:0 18px;cursor:pointer}
.asem-use:hover{background:#15176b}
.asem-use:focus-visible{outline:3px solid #efca5c;outline-offset:2px}
.asem-use[hidden]{display:none}
body .asem-acc{padding:14px 16px;margin:0 0 12px}
body .asem-acc code{padding:2px 8px}
body .asem-use{padding:0 18px}
body .asem-acc:last-of-type{margin-bottom:16px}
.asem-demo-login{margin:0 0 18px}
.asem-demo-login form{display:grid;gap:10px}
.asem-demo-login label{display:block;font-weight:600}
.asem-demo-login input[type=text],.asem-demo-login input[type=password]{width:100%;max-width:420px;min-height:44px;padding:6px 10px;border:1px solid var(--asem-line);border-radius:10px;box-sizing:border-box}
.asem-demo-login input[type=submit]{min-height:46px;font-size:1.05rem}
.wp-site-blocks>footer{display:none}
body.home .wp-block-post-title,body.home .entry-title{display:none}
@media (max-width:781px){.asem-hero{padding:36px 22px!important}.wp-site-blocks>header .asem-logo{height:36px}}
/* Pagine del plugin sul sito: un po\' di aria e il carattere del marchio. */
.asem-wrap,.asem-front,[class*="associazionesemplice"]{font-family:inherit}
';
}

add_action( 'wp_head', function () {
	echo '<style id="asem-demo-style">' . asem_demo_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<link rel="icon" href="' . esc_url( asem_demo_asset( 'favicon.svg' ) ) . '" type="image/svg+xml">';
}, 99 );

// Il titolo del sito nell'intestazione diventa il logo.
add_filter( 'render_block_core/site-title', function ( $html ) {
	return '<p class="wp-block-site-title"><a href="https://associazionesemplice.it/" rel="home"><img class="asem-logo" src="' . esc_url( asem_demo_asset( 'logo.svg' ) ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></a></p>';
} );

// Piè di pagina: una riga sola (quello di serie non serve).
add_action( 'wp_footer', function () {
	echo '<div class="asem-foot" style="text-align:center">Demo di Associazione Semplice · <a href="https://associazionesemplice.it/prova">come si prova</a> · <a href="https://associazionesemplice.it">associazionesemplice.it</a></div>';
}, 1 );

// Accesso: logo e colori del marchio.
add_action( 'login_enqueue_scripts', function () {
	echo '<style>' . asem_demo_css() . '
body.login{background:#f6f8f6}
.login h1 a{background:url("' . esc_url( asem_demo_asset( 'logo.svg' ) ) . '") center/contain no-repeat!important;width:300px!important;height:90px!important}
.login form{border:1px solid var(--asem-line);border-radius:18px;box-shadow:0 8px 24px rgba(20,23,46,.07);padding:26px}
.login .button-primary,.wp-core-ui .button-primary{background:#1f218c!important;border:0!important;border-radius:12px!important;min-height:40px;font-weight:600;color:#fff!important}
.language-switcher{display:none!important}
.login .button-primary:hover{background:var(--asem-brand-2)}
.login #nav,.login #backtoblog{display:none}
.login .message,.login .notice{border-left-color:var(--asem-brand)}
</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
} );
add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );
add_filter( 'login_headertext', function () {
	return get_bloginfo( 'name' );
} );
add_filter( 'login_message', function ( $m ) {
	// Il rinvio all'accesso dei soci lo mostra già il plugin sotto questo avviso.
	return $m . '<p class="message">Accessi di prova: scegli e premi «Usa».</p>' . asem_demo_wp_accesses();
} );

// Pannello: barra e menu nei colori del marchio.
add_action( 'admin_head', function () {
	echo '<style>
#wpadminbar{background:#1f218c}
#adminmenuback,#adminmenuwrap,#adminmenu{background:#15176b}
#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li a:focus,#adminmenu li.current a.menu-top,#adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head,#adminmenu .wp-has-current-submenu.opensub .wp-submenu .wp-submenu-head{background:#1f218c;color:#fff}
#adminmenu .wp-submenu{background:#0f1157}
#adminmenu .wp-submenu a{color:#c9caf0}#adminmenu .wp-submenu a:hover,#adminmenu .wp-submenu li.current a{color:#efca5c}
#adminmenu div.wp-menu-image:before,#adminmenu a{color:#e7e8f7}
.wp-core-ui .button-primary{background:#1f218c;border-color:#1f218c;border-radius:8px}
.wp-core-ui .button-primary:hover{background:#15176b;border-color:#15176b}
</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
} );

// Il logo, sul sito e nell'accesso, riporta sempre alla home del dominio principale (non alla home della demo né a WordPress.org).
add_filter( 'login_headerurl', function () {
	return 'https://associazionesemplice.it/';
} );
add_filter( 'login_headertext', function () {
	return 'Associazione Semplice';
} );

// Dati di accesso di prova: una riga per accesso, «Email» e «Password» separate e copiabili da sole, e un «Usa» che compila i campi (non invia da solo).
// Il testo si legge anche senza JavaScript: il pulsante compare solo se c'è JavaScript e nella pagina ci sono i campi da compilare. Nessuna credenziale nell'indirizzo.
function asem_demo_cred_box( string $title, string $user_label, string $user, string $pass ): string {
	return '<div class="asem-cred asem-acc" role="group" aria-label="' . esc_attr( $title ) . '"><strong class="asem-acc-t">' . esc_html( $title ) . '</strong>'
		. '<div class="asem-acc-r"><span class="asem-acc-k">' . esc_html( $user_label ) . '</span> <code>' . esc_html( $user ) . '</code></div>'
		. '<div class="asem-acc-r"><span class="asem-acc-k">Password</span> <code>' . esc_html( $pass ) . '</code></div>'
		. '<button type="button" class="asem-use" hidden data-email="' . esc_attr( $user ) . '" data-pass="' . esc_attr( $pass ) . '" aria-label="' . esc_attr( 'Usa questi dati: ' . $title ) . '">Usa</button></div>';
}
function asem_demo_wp_accesses(): string {
	return asem_demo_cred_box( 'Segreteria', 'Email', 'segreteria@demo.invalid', 'Segreteria-Demo-2026' )
		. asem_demo_cred_box( 'Tesoriere', 'Email', 'tesoriere@demo.invalid', 'Tesoriere-Demo-2026' )
		. asem_demo_cred_box( 'Amministratore del plugin', 'Email', 'amministratore@demo.invalid', 'Amministratore-Demo-2026' );
}
/** Modulo di accesso di WordPress dentro la pagina (per le aree del personale): i campi che «Usa» compila stanno lì. */
function asem_demo_login_form( int $page_id, string $label ): string {
	return '<div class="asem-demo-login">' . wp_login_form( array( 'echo' => false, 'redirect' => get_permalink( $page_id ), 'label_username' => 'Email', 'label_password' => 'Password', 'label_log_in' => $label, 'remember' => false ) ) . '</div>';
}
function asem_demo_use_script( bool $return = false ) {
	$js = '<script id="asem-demo-use">(function(){var S="input[name=email],#user_login",P="input[name=password],#user_pass";'
		. 'function f(){return[document.querySelector(S),document.querySelector(P)];}'
		. 'function set(e,v){e.value=v;e.dispatchEvent(new Event("input",{bubbles:true}));}'
		. 'var t=f();if(t[0]&&t[1]){document.querySelectorAll(".asem-use").forEach(function(b){b.hidden=false;});}'
		. 'document.addEventListener("click",function(e){var b=e.target.closest?e.target.closest(".asem-use"):null;if(!b){return;}var x=f();if(!x[0]||!x[1]){return;}'
		. 'set(x[0],b.getAttribute("data-email"));set(x[1],b.getAttribute("data-pass"));var m=x[1].closest("form"),s=m&&m.querySelector("[type=submit]");(s||x[1]).focus();});})();</script>';
	if ( $return ) {
		return $js;
	}
	echo $js; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_footer', 'asem_demo_use_script', 5 );
add_action( 'login_footer', 'asem_demo_use_script', 5 );
/** Pulsante ben visibile (colori del marchio), uguale su computer e telefono. */
function asem_demo_button( string $label, string $url ): string {
	return '<p style="margin:0 0 18px"><a class="wp-element-button" href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f218c;color:#fff;border-radius:12px;font-weight:700;padding:.9em 1.6em;text-decoration:none;font-size:1.05rem">' . esc_html( $label ) . '</a></p>';
}
function asem_demo_member_box(): string {
	return asem_demo_cred_box( 'Socio', 'Email', 'socio@demo.invalid', 'Socio-Demo-2026' );
}
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['asem_member_login'] ) || ( class_exists( '\AssociazioneSemplice\MemberSession' ) && \AssociazioneSemplice\MemberSession::current() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	ob_start( function ( $html ) {
		$pos = strpos( $html, '<form' );
		if ( false === $pos ) {
			return $html;
		}
		$html = substr( $html, 0, $pos ) . asem_demo_member_box() . substr( $html, $pos );
		if ( false === strpos( $html, 'id="asem-demo-use"' ) ) { // la pagina di accesso dei soci non sempre carica il piè di pagina del sito
			$js   = asem_demo_use_script( true );
			$body = strripos( $html, '</body>' );
			$html = false === $body ? $html . $js : substr( $html, 0, $body ) . $js . substr( $html, $body );
		}
		return $html;
	} );
}, 0 );
add_filter( 'the_content', function ( $content ) {
	if ( ! is_page() || ! class_exists( '\AssociazioneSemplice\Pages' ) ) {
		return $content;
	}
	$have = \AssociazioneSemplice\Pages::existing();
	$id   = get_queried_object_id();
	if ( isset( $have['segreteria'] ) && (int) $have['segreteria'] === $id && ! is_user_logged_in() ) {
		return asem_demo_cred_box( 'Segreteria', 'Email', 'segreteria@demo.invalid', 'Segreteria-Demo-2026' )
			. asem_demo_login_form( $id, 'Entra come segreteria' )
			. '<p style="color:#566069;font-size:.9rem">Lavori dal sito invece che dal pannello: da qui apri soci, incassi, attività e registri. Per entrare come socio vai all\'<a href="' . esc_url( home_url( '/area-soci/' ) ) . '">area soci</a>.</p>';
	}
	// Tesoriere: è un socio con un utente WordPress e la carica di tesoriere: entra da wp-login (come la segreteria), non dal modulo dei soci.
	if ( isset( $have['tesoriere'] ) && (int) $have['tesoriere'] === $id && ! is_user_logged_in() ) {
		return asem_demo_cred_box( 'Tesoriere', 'Email', 'tesoriere@demo.invalid', 'Tesoriere-Demo-2026' )
			. asem_demo_login_form( $id, 'Entra come tesoriere' )
			. '<p style="color:#566069;font-size:.9rem">Il tesoriere è un socio che registra incassi e spese (anche con la foto dello scontrino) e iscrive i soci, ma non vede le impostazioni. Per entrare come socio qualunque vai all\'<a href="' . esc_url( home_url( '/area-soci/' ) ) . '">area soci</a>.</p>';
	}
	if ( isset( $have['area'] ) && (int) $have['area'] === $id && class_exists( '\AssociazioneSemplice\MemberSession' ) && ! \AssociazioneSemplice\MemberSession::current() && ! is_user_logged_in() ) {
		return asem_demo_member_box() . $content;
	}
	return $content;
}, 5 );

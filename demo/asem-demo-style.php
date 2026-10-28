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
	return '<p class="wp-block-site-title"><a href="' . esc_url( home_url( '/' ) ) . '" rel="home"><img class="asem-logo" src="' . esc_url( asem_demo_asset( 'logo.svg' ) ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></a></p>';
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
	return $m . '<p class="message">Accessi di prova: <strong>segreteria</strong> / Segreteria-Demo-2026 · <strong>socio</strong> / Socio-Demo-2026</p>';
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

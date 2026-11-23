<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avvio del front-end: shortcode, contenuti riservati, azioni dei soci, blocchi Gutenberg, widget Elementor. */
final class Front {

	/**
	 * Pagine che rispondono a un parametro dell'indirizzo (link delle email, verifica dei QR, calendario…): parametro => [classe, metodo].
	 * Si controllano qui, in un solo punto, all'inizio di template_redirect: la classe si carica solo se il parametro c'è.
	 * Un'estensione aggiunge le sue con il filtro `asem_front_links`. L'ordine è quello in cui partono.
	 */
	const LINKS = array(
		'asem_card'          => array( CardVerify::class, 'handle' ),
		'asem_card_pdf'      => array( CardPdf::class, 'handle' ),
		'asem_ticket'        => array( TicketVerify::class, 'handle' ),
		'asem_activate'      => array( Activation::class, 'handle' ),
		'asem_first_access'  => array( FirstAccess::class, 'handle' ),
		'asem_member_login'  => array( MemberLoginPage::class, 'handle' ),
		'asem_ml'            => array( MemberLoginPage::class, 'handle' ),
		'asem_apply_confirm' => array( Application::class, 'handle_confirm' ),
		'asem_apply_pay'     => array( Application::class, 'handle_pay' ),
		'asem_guardian_cf'   => array( \AssociazioneSemplice\GuardianConsent::class, 'handle' ),
		'asem_ical'          => array( \AssociazioneSemplice\Calendar::class, 'serve' ),
		'asem_evreq'         => array( \AssociazioneSemplice\EventRequests::class, 'handle_confirm' ),
	);

	public static function init(): void {
		Shortcodes::register();
		Restrict::register();
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'early_enqueue' ) ); // gli stili del plugin nell'intestazione, non nel piede
		if ( \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_front_' ) ) { // i moduli del sito passano da admin-post.php: le loro azioni (asem_front_…) si registrano solo lì
			Actions::register();
		}
		add_filter( 'asem_area_section', array( BlockBViews::class, 'area_section' ), 10, 3 ); // votazioni e documenti nell'area soci
		add_filter( 'asem_area_section', array( BlockFViews::class, 'area_section' ), 10, 3 ); // convenzioni nell'area soci
		do_action( 'asem_front_init' ); // le estensioni registrano qui le loro parti del sito
		add_action( 'template_redirect', array( __CLASS__, 'links' ), 1 );
		add_filter( 'login_message', array( FirstAccess::class, 'login_message' ) ); // avviso «Primo accesso» sopra il modulo di accesso di WordPress
		add_action( 'init', array( Blocks::class, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( Blocks::class, 'editor_data' ), 5 );
		if ( defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) ) {
			ElementorSupport::register(); // widget di Elementor: solo se Elementor c'è
		}
	}

	/** Parametro dell'indirizzo => gestore, con quelli delle estensioni. @return array<string,callable> */
	public static function link_handlers(): array {
		return (array) apply_filters( 'asem_front_links', self::LINKS );
	}

	/** template_redirect: parte solo il gestore dei parametri presenti nell'indirizzo (ognuno una volta), gli altri non si caricano. */
	public static function links(): void {
		if ( empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- si guarda solo quali parametri ci sono; ogni gestore controlla i suoi
			return;
		}
		$done = array();
		foreach ( self::link_handlers() as $param => $handler ) {
			if ( ! isset( $_GET[ $param ] ) || ! is_callable( $handler ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$key = is_array( $handler ) ? implode( '::', array_map( 'strval', $handler ) ) : ( is_string( $handler ) ? $handler : spl_object_hash( (object) $handler ) );
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			$done[ $key ] = true;
			call_user_func( $handler );
		}
	}
}

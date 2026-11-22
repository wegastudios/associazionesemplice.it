<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avvio del front-end: shortcode, contenuti riservati, azioni dei soci, blocchi Gutenberg, widget Elementor. */
final class Front {

	public static function init(): void {
		Shortcodes::register();
		Restrict::register();
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'early_enqueue' ) ); // gli stili del plugin nell'intestazione, non nel piede
		Actions::register();
		BlockBViews::register();
		BlockFViews::register();
		do_action( 'asem_front_init' ); // le estensioni registrano qui le loro parti del sito
		CardVerify::register();
		CardPdf::register();
		TicketVerify::register();
		Activation::register();
		FirstAccess::register();
		MemberLoginPage::register();
		Application::register();
		\AssociazioneSemplice\Calendar::register();
		Blocks::register();
		ElementorSupport::register();
	}
}

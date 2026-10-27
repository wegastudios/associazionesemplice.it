<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avvio del front-end: shortcode, contenuti riservati, azioni dei soci, blocchi Gutenberg, widget Elementor. */
final class Front {

	public static function init(): void {
		Shortcodes::register();
		Restrict::register();
		Actions::register();
		BlockBViews::register();
		AssembliesFront::register();
		do_action( 'asem_front_init' ); // le estensioni registrano qui le loro parti del sito
		CardVerify::register();
		CardPdf::register();
		TicketVerify::register();
		Activation::register();
		FirstAccess::register();
		Application::register();
		\AssociazioneSemplice\Calendar::register();
		Blocks::register();
		ElementorSupport::register();
	}
}

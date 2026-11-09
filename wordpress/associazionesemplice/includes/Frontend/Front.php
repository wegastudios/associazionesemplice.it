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
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** Con «Torna alla grafica precedente» il sito non usa il nuovo stile: il foglio di stile lo capisce da questa classe. */
	public static function body_class( array $classes ): array {
		if ( \AssociazioneSemplice\Settings::get( 'ui_classic' ) ) {
			$classes[] = 'asem-classic';
		}
		return $classes;
	}
}

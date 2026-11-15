<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Phone;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pulsante WhatsApp fisso in basso a destra su tutto il sito, quando nelle impostazioni c'è il numero della segreteria.
 * Un semplice collegamento (apre WhatsApp solo se si clicca): nessuno script, nessuna richiesta a siti esterni.
 */
final class WhatsappFloat {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'style' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/** Numero per wa.me, vuoto se il pulsante è spento (impostazione a parte) o il telefono non è valido. */
	private static function number(): string {
		return empty( Settings::get( 'whatsapp_button' ) ) ? '' : Phone::whatsapp( (string) Settings::get( 'secretary_phone' ) );
	}

	public static function style(): void {
		if ( '' === self::number() ) {
			return;
		}
		wp_register_style( 'asem-wa-float', false, array(), ASEM_VERSION );
		wp_enqueue_style( 'asem-wa-float' );
		wp_add_inline_style(
			'asem-wa-float',
			'.asem-wa-float{position:fixed;right:16px;bottom:16px;z-index:9998;display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:999px;background:#25d366;color:#fff;font:600 15px/1 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.25)}'
			. '.asem-wa-float:hover,.asem-wa-float:focus{background:#1ebe5b;color:#fff}'
			. '@media (max-width:480px){.asem-wa-float span{display:none}.asem-wa-float{padding:14px}}'
			. '@media (max-width:700px){body:has(.asemf-jump) .asem-wa-float{bottom:calc(76px + env(safe-area-inset-bottom,0px))}}'
		);
	}

	public static function render(): void {
		$wa = self::number();
		if ( '' === $wa || is_admin() ) {
			return;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$href  = 'https://wa.me/' . $wa . '?text=' . rawurlencode( 'Ciao, vorrei informazioni' . ( '' !== $assoc ? ' su ' . $assoc : '' ) );
		echo '<a class="asem-wa-float" href="' . esc_url( $href, array( 'https' ) ) . '" target="_blank" rel="noopener noreferrer" aria-label="Scrivi alla segreteria su WhatsApp">💬 <span>Scrivici su WhatsApp</span></a>';
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Una sola banda fissa (non chiudibile) in cima a tutte le pagine del plugin, con l'elenco di ciò che manca e il link alla pagina dove si imposta.
 * Oggi ci sono i contatti della segreteria; altre voci si aggiungono con il filtro `asem_missing_items` (o in {@see items()}).
 *
 * Ogni voce: array( 'text' => frase per chi può modificare le impostazioni, 'text_other' => frase per gli altri,
 *                   'url' => pagina dove si imposta, 'page' => slug della pagina (lì il pulsante non compare), 'button' => testo del pulsante ).
 */
final class MissingBand {

	/** @return array<int,array{text:string,text_other:string,url:string,page:string,button:string}> ciò che manca, in ordine */
	public static function items(): array {
		$items = array();
		$c     = Contacts::missing_item();
		if ( null !== $c ) {
			$items[] = $c;
		}
		return array_values( (array) apply_filters( 'asem_missing_items', $items ) );
	}

	/**
	 * La banda (vuota se non manca nulla o se chi guarda non usa il gestionale).
	 *
	 * @param string $page pagina del plugin che si sta aprendo: nelle voci che si impostano proprio lì il pulsante non compare
	 */
	public static function html( string $page = '' ): string {
		$items = self::items();
		if ( ! $items ) {
			return '';
		}
		$can = current_user_can( Plugin::CAP );
		if ( ! $can && ! current_user_can( Plugin::CAP_OPS ) ) {
			return '';
		}
		$html = '<div class="notice notice-warning inline asem-missing-band">';
		foreach ( $items as $it ) {
			$html .= '<p>' . esc_html( $can ? $it['text'] : $it['text_other'] );
			if ( $can && '' !== $it['url'] && $page !== $it['page'] ) {
				$html .= ' <a class="button" href="' . esc_url( $it['url'] ) . '">' . esc_html( $it['button'] ) . '</a>';
			}
			$html .= '</p>';
		}
		return $html . '</div>';
	}
}

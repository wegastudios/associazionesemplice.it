<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Una sola banda fissa (non chiudibile) in cima a tutte le pagine del plugin, con l'elenco di ciò che manca e il link alla pagina dove si imposta.
 * Oggi ci sono i dati dell'associazione (dati dell'ente e contatti della segreteria, vedi {@see EntityRules}); altre voci si aggiungono con il filtro `asem_missing_items` (o in {@see items()}).
 *
 * Ogni voce: array( 'text' => frase per chi può modificare le impostazioni, 'text_other' => frase per gli altri,
 *                   'url' => pagina dove si imposta, 'page' => slug della pagina (lì il pulsante non compare), 'button' => testo del pulsante,
 *                   'extra' => (facoltativo) altri pulsanti: array( array( 'url' => …, 'button' => … ) ) ).
 */
final class MissingBand {

	/** La voce dei dati dell'associazione (una sola frase con l'elenco), o null se non manca nulla. */
	public static function entity_item(): ?array {
		$miss = EntityRules::missing();
		if ( ! $miss ) {
			return null;
		}
		$keys     = array_column( $miss, 'key' );
		$list     = implode( ', ', array_column( $miss, 'label' ) );
		$contacts = (bool) array_intersect( $keys, array( 'email', 'phone' ) );
		$text     = Texts::t( 'Mancano dei dati dell\'associazione: {elenco}.', array( 'elenco' => $list ) );
		$other    = Texts::t( 'Mancano dei dati dell\'associazione: {elenco}. Chiedi a chi amministra di completarli.', array( 'elenco' => $list ) );
		if ( $contacts ) { // il blocco degli invii manuali riguarda solo i contatti, non i dati fiscali
			$text  .= ' ' . Texts::t( 'Finché mancano email e telefono della segreteria, la segreteria non può inviare email ai soci (comunicazioni, avvisi e ricevute inviate a mano). Gli invii automatici, come il link di accesso, partono lo stesso.' );
			$other .= ' ' . Texts::t( 'Finché mancano email e telefono della segreteria, la segreteria non può inviare email ai soci.' );
		}
		$people = array( array( 'url' => Admin\Ui::url( 'asem-people' ), 'button' => Texts::t( 'Indica il presidente' ) ) );
		$only_p = array( 'president' ) === $keys; // se manca solo il presidente, il pulsante porta alle persone
		return array(
			'text'       => $text,
			'text_other' => $other,
			'url'        => $only_p ? Admin\Ui::url( 'asem-people' ) : Admin\Ui::url( 'asem-entity' ),
			'page'       => $only_p ? 'asem-people' : 'asem-entity',
			'button'     => $only_p ? Texts::t( 'Indica il presidente' ) : Texts::t( 'Completali ora' ),
			'extra'      => ( ! $only_p && in_array( 'president', $keys, true ) ) ? $people : array(),
		);
	}

	/** @return array<int,array{text:string,text_other:string,url:string,page:string,button:string}> ciò che manca, in ordine */
	public static function items(): array {
		$items = array();
		$e     = self::entity_item();
		if ( null !== $e ) {
			$items[] = $e;
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
			if ( $can ) {
				foreach ( (array) ( $it['extra'] ?? array() ) as $x ) {
					$html .= ' <a class="button" href="' . esc_url( (string) $x['url'] ) . '">' . esc_html( (string) $x['button'] ) . '</a>';
				}
			}
			$html .= '</p>';
		}
		return $html . '</div>';
	}
}

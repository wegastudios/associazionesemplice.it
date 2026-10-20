<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Levels;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\WooBridge;
use AssociazioneSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/** Sezioni delle funzioni aggiuntive nella pagina «Integrazioni». */
final class ProIntegrations {

	public static function register(): void {
		add_action( 'asem_integrations_top', array( __CLASS__, 'woo' ) );
		add_action( 'asem_integrations_list', array( __CLASS__, 'list_items' ) );
	}

	/** WooCommerce: collegamento di quote, corsi ed eventi ai prodotti. */
	public static function woo(): void {
		$pay    = \AssociazioneSemplice\Edition::has( 'payments' ); // WooCommerce e i gateway sono dei pagamenti online
		$woo    = $pay && WooBridge::active();
		$on     = PaymentConfig::WOOCOMMERCE === (string) Settings::get( 'payment_provider' );
		$prods  = $pay ? WooBridge::products() : array();
		$map    = $pay ? WooLinks::map() : array();
		$select = function ( string $name, int $current ) use ( $prods ) {
			$html = '<select name="' . esc_attr( $name ) . '"><option value="0">— nessun prodotto —</option>';
			foreach ( $prods as $id => $label ) {
				$html .= '<option value="' . (int) $id . '"' . selected( $current, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			if ( $current && ! isset( $prods[ $current ] ) ) {
				$html .= '<option value="' . (int) $current . '" selected>Prodotto n. ' . (int) $current . ' (non trovato)</option>';
			}
			return $html . '</select>';
		};
		if ( $pay ) {
		echo '<h2>WooCommerce</h2>';
		if ( ! $woo ) {
			echo '<p class="description">WooCommerce non è attivo su questo sito: installalo e attivalo per far pagare quote e contributi dal negozio.</p>';
		} else {
			echo '<p class="description">I soci pagano quote e contributi dal carrello e dal checkout del negozio. Ogni quota (per livello di socio) e ogni corso o evento si collega a un prodotto semplice, preferibilmente virtuale: '
				. 'nel carrello il prezzo è quello calcolato da Associazione Semplice (quota del livello, sconto familiare, mensilità dovute) e quando l\'ordine risulta pagato l\'incasso entra in prima nota con la sua ricevuta. '
				. 'Gli ordini senza voci Associazione Semplice non vengono toccati.</p>';
			Ui::form_open( 'asem_save_woo', Ui::url( 'asem-tech' ) );
			echo '<p><label><input type="checkbox" name="woo_enabled" value="1"' . checked( $on, true, false ) . '> <strong>Usa WooCommerce per i pagamenti online</strong></label> '
				. '<span class="description">Al posto di Stripe e PayPal (il gateway scelto cambia).</span></p>';
			echo '<h3>Quote associative</h3><table class="widefat striped"><thead><tr><th>Livello</th><th>Prodotto</th></tr></thead><tbody>';
			foreach ( Levels::all() as $lv ) {
				echo '<tr><td>' . esc_html( $lv['name'] ) . ' <span class="description">(' . esc_html( \AssociazioneSemplice\MemberType::label( $lv['base_type'] ) ) . ')</span></td><td>' . $select( 'link_level[' . (int) $lv['id'] . ']', (int) ( $map[ 'level:' . (int) $lv['id'] ] ?? 0 ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			echo '<h3>Corsi ed eventi</h3>';
			$acts = Plugin::activities()->for_year( Settings::social_year()->label() );
			if ( ! $acts ) {
				echo '<p class="description">Nessuna attività in questo anno sociale.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Attività</th><th>Prodotto</th></tr></thead><tbody>';
				foreach ( $acts as $a ) {
					echo '<tr><td>' . esc_html( $a['name'] ) . '</td><td>' . $select( 'link_activity[' . (int) $a['id'] . ']', (int) ( $map[ 'activity:' . (int) $a['id'] ] ?? 0 ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			}
			echo '<h3>Prodotto generico</h3><p>' . $select( 'woo_default_product', (int) Settings::get( 'woo_default_product' ) ) . ' <span class="description">Per le voci senza un prodotto proprio (ad esempio le mensilità di un corso). Senza prodotto generico quelle voci non si possono pagare dal negozio.</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><button class="button button-primary">Salva</button></p>';
			Ui::form_close();
			echo '<p class="description">I rimborsi di un ordine si registrano a mano in prima nota.</p>';
		}
		}
	}

	/** Voci dell'elenco «Altre integrazioni». */
	public static function list_items(): void {
		$pay = \AssociazioneSemplice\Edition::has( 'payments' );
		if ( $pay ) echo '<li><strong>Stripe e PayPal</strong> — carta e PayPal senza negozio: <a href="' . esc_url( Ui::url( 'asem-payments' ) ) . '">Pagamenti online</a>.</li>';
		echo '<li><strong>Apple Wallet, Google Wallet, QR e calendario</strong>: <a href="' . esc_url( Ui::url( 'asem-card' ) ) . '">Tessera, QR e Wallet</a>.</li>';
	}
}

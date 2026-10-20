<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Fiscal;
use AssociazioneSemplice\Levels;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Terms;
use AssociazioneSemplice\WooBridge;
use AssociazioneSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/** Passi e domande delle funzioni aggiuntive nella configurazione guidata (tipo di ente, partita IVA, livelli, pagamenti online, WooCommerce, 5x1000). */
final class ProWizard {

	public static function register(): void {
		add_filter( 'asem_wizard_entity_intro', array( __CLASS__, 'entity_intro' ) );
		add_action( 'asem_wizard_entity_rows', array( __CLASS__, 'entity_rows' ) );
		add_action( 'asem_wizard_steps_entity', array( __CLASS__, 'steps_entity' ) );
		add_action( 'asem_wizard_module_extra', array( __CLASS__, 'module_extra' ), 10, 2 );
		add_action( 'asem_wizard_levels_rows', array( __CLASS__, 'levels_rows' ) );
		add_action( 'asem_wizard_payment_choices', array( __CLASS__, 'payment_choices_form' ) );
		add_action( 'asem_wizard_steps_payments', array( __CLASS__, 'steps_payments' ) );
		add_filter( 'asem_wizard_own_step', array( __CLASS__, 'own_step' ) );
		add_filter( 'asem_wizard_feature_conditions', array( __CLASS__, 'feature_conditions' ) );
		add_filter( 'asem_wizard_page_if', array( __CLASS__, 'page_if' ) );
		add_filter( 'asem_wizard_values', array( __CLASS__, 'values' ), 10, 2 );
		add_filter( 'asem_wizard_done', array( __CLASS__, 'done' ), 10, 2 );
	}

	public static function own_step( array $keys ): array {
		return array_merge( $keys, array( 'pwa_enabled', 'push_enabled', 'fivepm_enabled' ) );
	}

	public static function feature_conditions( array $c ): array {
		return $c + array( 'wallet_enabled' => 'card_enabled=1' );
	}

	public static function page_if( array $c ): array {
		return $c + array( 'cinquemille' => 'fivepm_enabled=1' );
	}

	public static function entity_intro( string $text ): string {
		return Edition::has( 'texts' ) ? ': tipo di ente e come chiami chi partecipa adattano da soli tutti i testi.' : $text;
	}

	/** Tipo di ente e come si chiamano le persone che partecipano. */
	public static function entity_rows( array $s ): void {
		if ( ! Edition::has( 'texts' ) ) {
			return;
		}
		$ents = array_keys( Terms::entity_types( (string) $s['entity_types_custom'] ) );
		$mems = array_keys( Terms::member_terms( (string) $s['member_terms_custom'] ) );
			echo '<tr><th>Di che tipo è</th><td><select name="entity_type">' . Ui::options( array_combine( $ents, $ents ), (string) $s['entity_type'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Chi partecipa si chiama</th><td><select name="member_term">' . Ui::options( array_combine( $mems, $mems ), (string) $s['member_term'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Partita IVA. */
	public static function steps_entity( array $s ): void {
		if ( \AssociazioneSemplice\Edition::has( 'vat' ) ) {
		// 2. Partita IVA
		WizardPage::step( 'Partita IVA', 'Senza partita IVA non compare nulla di fiscale.', '', 'ente' );
		echo WizardPage::yes_no( 'has_vat', 'L\'ente ha la partita IVA?', ! empty( $s['has_vat'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$rates = array();
		foreach ( Fiscal::RATES as $r ) {
			$rates[ $r ] = $r . '%';
		}
		echo '<div data-if="has_vat=1"><table class="form-table"><tbody>';
		echo '<tr><th>Partita IVA</th><td><input type="text" name="vat_number" value="' . esc_attr( (string) $s['vat_number'] ) . '" class="regular-text" maxlength="13" placeholder="11 cifre"></td></tr>';
		echo '<tr><th>Regime</th><td><select name="fiscal_regime">' . Ui::options( Fiscal::regimes(), (string) $s['fiscal_regime'] ) . '</select><p class="description">Nel regime forfettario l\'IVA non si applica.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table><details class="asem-wiz-more"><summary>Più dettagli: aliquote e importi</summary><table class="form-table"><tbody>';
		echo '<tr><th>Aliquota proposta</th><td><select name="vat_default_rate">' . Ui::options( $rates, (int) $s['vat_default_rate'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Gli importi si inseriscono</th><td><select name="vat_prices_mode">' . Ui::options( array( Fiscal::INCLUDED => 'IVA compresa', Fiscal::EXCLUDED => 'IVA esclusa' ), (string) $s['vat_prices_mode'] ) . '</select><p class="description">Scelta iniziale: su ogni attività, incasso e spesa si può indicare diversamente.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$mr = Fiscal::membership_rate();
		echo '<tr><th>IVA sulle quote associative</th><td><select name="vat_membership_rate">' . Ui::options( Fiscal::rate_options(), null === $mr ? 'none' : (string) $mr ) . '</select><p class="description">Di norma le quote dei soci sono fuori campo IVA.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice destinatario (SDI)</th><td><input type="text" name="sdi_code" value="' . esc_attr( (string) $s['sdi_code'] ) . '" size="9" maxlength="7"> <span class="description">facoltativo</span></td></tr>';
		echo '</tbody></table></details></div>';
		WizardPage::end_step();
		}
	}

	/** Domande in più sotto alcune parti del gestionale. */
	public static function module_extra( string $key, array $s ): void {
			if ( 'ledger' === $key ) {
				echo '<p data-if="mod[ledger]=1" style="margin-left:24px"><label>Riga in fondo alle ricevute (ad esempio il riferimento normativo; facoltativa)<br><input type="text" name="receipt_footer" value="' . esc_attr( (string) $s['receipt_footer'] ) . '" class="large-text" maxlength="300"></label></p>';
			}
			if ( 'accounting' === $key ) {
				echo '<div data-if="mod[accounting]=1" style="margin-left:24px"><input type="hidden" name="adempimenti_present" value="1">'
					. WizardPage::yes_no( 'fivepm_enabled', 'Raccogli il 5x1000? Si attiva il messaggio per i soci, il promemoria e il registro dei contributi ricevuti.', ! empty( $s['fivepm_enabled'] ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
	}

	/** Altri tipi di socio. */
	public static function levels_rows( array $s ): void {
		$have = array();
		foreach ( Levels::all( true ) as $lv ) {
			$have[] = $lv['name'] . ( null === $lv['fee_cents'] ? '' : ' (' . Money::format( (int) $lv['fee_cents'] ) . ')' );
		}
		if ( \AssociazioneSemplice\Edition::has( 'levels' ) ) { // l'edizione gratuita ha un'unica quota
		echo '<tr><th>Tipi già presenti</th><td>' . esc_html( implode( ', ', $have ) ) . '</td></tr>';
		echo '<tr><th>Altri tipi di socio</th><td><textarea name="extra_levels" rows="3" class="large-text" placeholder="Ridotto; 15&#10;Sostenitore; 100"></textarea><p class="description">Una riga per tipo: «Nome; quota». Senza quota si usa quella proposta. Con la partita IVA le quote dei livelli sono IVA compresa. Altri livelli e quote si gestiscono in Impostazioni → Soci e quote.</p></td></tr>';
		}
	}

	/** Come pagano i soci. */
	public static function payment_choices_form( array $s ): void {
		$woo  = Edition::has( 'payments' ) && WooBridge::active();
		$euro = $woo && WooBridge::currency_is_euro();
		$current = (string) $s['payment_provider'];
		$pay_choices = \AssociazioneSemplice\Edition::has( 'payments' ) ? self::payment_choices() : array(); // senza pagamenti online resta solo il bonifico
		if ( ! $pay_choices ) {
			echo '<input type="hidden" name="payment_choice" value="none">';
		}
		foreach ( $pay_choices as $val => $c ) {
			$off  = PaymentConfig::WOOCOMMERCE === $val && ! $euro;
			$note = '';
			if ( PaymentConfig::WOOCOMMERCE === $val ) {
				$note = ! $woo ? ' <em>WooCommerce non è stato rilevato su questo sito: installalo e attivalo per usare questa opzione.</em>' : ( ! $euro ? ' <em>WooCommerce è attivo, ma il negozio non usa l\'euro: non è utilizzabile.</em>' : ' <strong>WooCommerce è attivo su questo sito.</strong>' );
			}
			$links = '';
			foreach ( PaymentConfig::BOTH === $val ? array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ) : ( in_array( $val, array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL, PaymentConfig::WOOCOMMERCE ), true ) ? array( $val ) : array() ) as $pv ) {
				$links .= ' ' . self::tutorial_links( $pv );
			}
			echo '<p><label><input type="radio" name="payment_choice" value="' . esc_attr( $val ) . '"' . checked( $current === $val, true, false ) . disabled( $off, true, false ) . '> <strong>' . esc_html( $c[0] ) . '</strong></label><br><span class="description">' . esc_html( $c[1] ) . '</span>' . $note . $links . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** Prodotti di WooCommerce da collegare. */
	public static function steps_payments( array $s ): void {
		$woo  = Edition::has( 'payments' ) && WooBridge::active();
		$euro = $woo && WooBridge::currency_is_euro();
		if ( $euro ) {
			$products = WooBridge::products();
			$map      = WooLinks::map();
			WizardPage::step( 'Prodotti di WooCommerce', 'Ogni quota deve corrispondere a un prodotto del negozio: collegane uno esistente oppure crealo ora (prodotto virtuale, non visibile in vetrina; il prezzo applicato lo calcola il plugin).', 'payment_choice=woocommerce', 'pagamenti' );
			echo '<table class="form-table"><tbody>';
			foreach ( Levels::all( true ) as $lv ) {
				$cur = (int) ( $map[ 'level:' . (int) $lv['id'] ] ?? 0 );
				echo '<tr><th>Quota «' . esc_html( (string) $lv['name'] ) . '»</th><td>' . self::product_select( 'product_level[' . (int) $lv['id'] . ']', $cur, $products ) . ( $cur ? ' <span class="description">già collegata</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$def = (int) $s['woo_default_product'];
			echo '<tr><th>Altre voci (corsi, eventi…)</th><td>' . self::product_select( 'product_default', $def, $products ) . ( $def ? ' <span class="description">già impostato</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tbody></table>';
			WizardPage::end_step();
		}
	}

	/** Collegamenti ai tutorial: si aprono in una nuova finestra. */
	public static function tutorial_links( string $provider ): string {
		$out = array();
		foreach ( self::tutorials()[ $provider ] ?? array() as $l ) {
			$out[] = '<a href="' . esc_url( $l[1] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $l[0] ) . ' ↗</a>';
		}
		return $out ? '<span class="description asem-wiz-links">Guide: ' . implode( ' · ', $out ) . '</span>' : '';
	}

	private static function product_select( string $name, int $current, array $products ): string {
		$opts = array( '' => $current ? 'Lascia il collegamento attuale' : 'Non collegare adesso', 'new' => 'Crea un prodotto nuovo' );
		foreach ( $products as $id => $label ) {
			$opts[ (string) $id ] = $label;
		}
		return '<select name="' . esc_attr( $name ) . '">' . Ui::options( $opts, '' ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}



	/** Scelte di pagamento: valore => [etichetta, descrizione]. */
	public static function payment_choices(): array {
		return array(
			'none'                    => array( 'Nessun pagamento online', 'I pagamenti si registrano a mano (contanti, POS, bonifico).' ),
			PaymentConfig::STRIPE     => array( 'Carta con Stripe', 'Il socio paga con carta direttamente dal sito.' ),
			PaymentConfig::PAYPAL     => array( 'PayPal', 'Il socio paga con PayPal (anche a rate, se il tuo account lo prevede).' ),
			PaymentConfig::BOTH       => array( 'Stripe e PayPal insieme', 'Il socio sceglie come pagare; le diciture dei pulsanti si personalizzano in Pagamenti online.' ),
			PaymentConfig::WOOCOMMERCE => array( 'Checkout di WooCommerce', 'I pagamenti passano dal carrello e dal checkout del tuo negozio.' ),
		);
	}

	/** Guide dei fornitori, da aprire in una nuova finestra. @return array<string,array<int,array{0:string,1:string}>> */
	public static function tutorials(): array {
		return array(
			PaymentConfig::STRIPE      => array(
				array( 'Creare un account Stripe', 'https://dashboard.stripe.com/register' ),
				array( 'Dove trovare le chiavi API', 'https://docs.stripe.com/keys' ),
				array( 'Configurare un webhook', 'https://docs.stripe.com/webhooks' ),
			),
			PaymentConfig::PAYPAL      => array(
				array( 'Creare un\'app PayPal e ottenere le credenziali', 'https://developer.paypal.com/api/rest/' ),
				array( 'Account di prova (sandbox)', 'https://developer.paypal.com/tools/sandbox/' ),
			),
			PaymentConfig::WOOCOMMERCE => array(
				array( 'Primi passi con WooCommerce', 'https://woocommerce.com/document/woocommerce-getting-started/' ),
				array( 'Metodi di pagamento di WooCommerce', 'https://woocommerce.com/document/payments/' ),
			),
		);
	}

	/** @var string[] cosa è stato fatto prima di salvare le impostazioni (riferito da {@see ProWizard::done()}) */
	private static $notes = array();

	/** @var array dati del modulo se si è scelto WooCommerce (per collegare i prodotti dopo il salvataggio) */
	private static $woo_products = array();

	/** Scelte delle funzioni aggiuntive: 5x1000, riga delle ricevute, pagamenti online, altri tipi di socio. */
	public static function values( array $vals, array $p ): array {
		self::$notes        = array();
		self::$woo_products = array();
		// Adempimenti (solo con la contabilità): 5x1000
		if ( ! empty( $p['adempimenti_present'] ) ) {
			$vals['fivepm_enabled'] = ! empty( $p['fivepm_enabled'] ) ? 1 : 0;
		}
		if ( isset( $p['receipt_footer'] ) ) {
			$vals['receipt_footer'] = (string) $p['receipt_footer'];
		}
		// Pagamenti
		if ( isset( $p['payment_choice'] ) ) {
			$choice  = (string) $p['payment_choice'];
			$choices = self::payment_choices();
			if ( ! isset( $choices[ $choice ] ) ) {
				throw new \InvalidArgumentException( 'Scelta dei pagamenti non valida.' );
			}
			if ( PaymentConfig::WOOCOMMERCE === $choice ) {
				if ( ! WooBridge::active() ) {
					throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito: installalo e attivalo, poi riapri la configurazione guidata.' );
				}
				if ( ! WooBridge::currency_is_euro() ) {
					throw new \InvalidArgumentException( 'Il negozio WooCommerce deve usare l\'euro come valuta: le quote sono in euro.' );
				}
			}
			$vals['payment_provider'] = 'none' === $choice ? PaymentConfig::NONE : $choice;
			self::$woo_products       = PaymentConfig::WOOCOMMERCE === $choice ? $p : array();
		}
		$new_levels = Edition::has( 'levels' ) ? self::parse_levels( (string) ( $p['extra_levels'] ?? '' ) ) : array();
		if ( $new_levels ) {
			$rows  = array();
			$known = array();
			foreach ( Levels::all() as $lv ) {
				$rows[]                                  = array( 'id' => (int) $lv['id'], 'name' => $lv['name'], 'base_type' => $lv['base_type'], 'fee' => null === $lv['fee_cents'] ? '' : Money::plain( (int) $lv['fee_cents'] ), 'active' => (int) $lv['active'] );
				$known[ mb_strtolower( $lv['name'], 'UTF-8' ) ] = true;
			}
			$added = 0;
			foreach ( $new_levels as $nl ) {
				if ( isset( $known[ mb_strtolower( $nl[0], 'UTF-8' ) ] ) ) {
					continue;
				}
				$rows[] = array( 'id' => 0, 'name' => $nl[0], 'base_type' => MemberType::ORDINARY, 'fee' => $nl[1], 'active' => 1 );
				$added++;
			}
			if ( $added ) {
				Levels::save( $rows );
				self::$notes[] = $added . ( 1 === $added ? ' tipo di socio aggiunto.' : ' tipi di socio aggiunti.' );
			}
		}
		return $vals;
	}

	/** Prodotti di WooCommerce da collegare o creare, e cosa è stato fatto prima del salvataggio. */
	public static function done( array $done, array $p ): array {
		$done = array_merge( self::$notes, $done );
		if ( self::$woo_products ) {
			$done = array_merge( $done, self::link_products( self::$woo_products ) );
		}
		self::$notes = array();
		self::$woo_products = array();
		return $done;
	}

	/** Righe «Nome; quota» (la quota è facoltativa) => [[nome, quota], …]. */
	public static function parse_levels( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$parts = array_map( 'trim', explode( ';', $line, 2 ) );
			$name  = mb_substr( sanitize_text_field( $parts[0] ), 0, 80 );
			if ( '' === $name ) {
				continue;
			}
			$fee = $parts[1] ?? '';
			if ( '' !== $fee && null === Money::parse( $fee ) ) {
				throw new \InvalidArgumentException( 'La quota di «' . $name . '» non è un importo valido.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$out[] = array( $name, $fee );
		}
		return array_slice( $out, 0, 20 );
	}

	/** Per ogni livello e per la voce generica: lascia com'è, crea un prodotto nuovo o collega uno esistente. @return string[] */
	private static function link_products( array $p ): array {
		$done = array();
		$fee  = (int) Settings::get( 'membership_fee_cents' );
		foreach ( Levels::all( true ) as $lv ) {
			$choice = (string) ( $p['product_level'][ (int) $lv['id'] ] ?? '' );
			if ( '' === $choice ) {
				continue;
			}
			$pid = 'new' === $choice ? WooBridge::create_product( 'Quota associativa: ' . $lv['name'], null === $lv['fee_cents'] ? $fee : (int) $lv['fee_cents'] ) : (int) $choice;
			WooLinks::set( WooLinks::LEVEL, (int) $lv['id'], $pid );
			$done[] = 'Quota «' . $lv['name'] . '» collegata a un prodotto' . ( 'new' === $choice ? ' (creato ora)' : '' ) . '.';
		}
		$gen = (string) ( $p['product_default'] ?? '' );
		if ( '' !== $gen ) {
			$pid = 'new' === $gen ? WooBridge::create_product( 'Contributo ' . ( '' !== (string) Settings::get( 'association_name' ) ? (string) Settings::get( 'association_name' ) : 'Associazione Semplice' ), 0 ) : (int) $gen;
			WooBridge::assert_product( $pid );
			Settings::update( array( 'woo_default_product' => $pid ) );
			$done[] = 'Prodotto generico per le altre voci (corsi ed eventi) impostato' . ( 'new' === $gen ? ' (creato ora)' : '' ) . '.';
		}
		return $done;
	}
}

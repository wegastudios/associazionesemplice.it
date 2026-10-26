<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Impostazioni delle funzioni aggiuntive: valori predefiniti, controlli e chiavi segrete, innestati in Settings con i suoi filtri
 * (`asem_settings_defaults`, `asem_settings_clean`, `asem_secret_keys`, `asem_feature_switches`).
 */
final class ProSettings {

	/** Chiavi segrete: nel database restano cifrate e non vengono mai mostrate. */
	const SECRET_KEYS = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret', 'sumup_api_key', 'wallet_apple_key_pem', 'wallet_google_key_pem' );

	public static function register(): void {
		add_filter( 'asem_secret_keys', array( __CLASS__, 'secret_keys' ) );
		add_filter( 'asem_feature_switches', array( __CLASS__, 'switches' ), 10, 2 );
		add_action( 'asem_settings_page_end', array( __CLASS__, 'levels_section' ), 20 );
	}

	public static function secret_keys( array $keys ): array {
		return array_merge( $keys, self::SECRET_KEYS );
	}

	/** Sezione dei livelli di socio, sotto le impostazioni. */
	public static function levels_section(): void {
		if ( Edition::has( 'levels' ) ) {
			Admin\LevelsEditor::section();
		}
	}

	/** Interruttori delle funzioni aggiuntive; con $visible_only solo quelle disponibili. */
	public static function switches( array $list, bool $visible_only ): array {
		$extra = array(
			'wallet_enabled'        => array( 'Pulsanti Apple Wallet e Google Wallet', 'wallet', 'ticket_qr_enabled' ),
			'insurance_volunteers'  => array( 'Assicurazioni dei volontari', 'insurance', 'reminders_enabled' ),
			'insurance_association' => array( 'Polizze dell\'associazione (responsabilità civile, infortuni)', 'insurance', 'insurance_volunteers' ),
			'fivepm_enabled'        => array( '5x1000', 'fivepm', 'insurance_association' ),
			'pwa_enabled'           => array( 'App installabile sul telefono (PWA)', 'pwa', 'fivepm_enabled' ),
			'push_enabled'          => array( 'Notifiche push ai dispositivi dei soci', 'pwa', 'pwa_enabled' ),
		);
		foreach ( $extra as $key => $row ) { // ognuno va dopo l'interruttore indicato
			if ( $visible_only && ! Edition::has( $row[1] ) ) {
				continue;
			}
			$out = array();
			foreach ( $list as $k => $label ) {
				$out[ $k ] = $label;
				if ( $row[2] === $k ) {
					$out[ $key ] = $row[0];
				}
			}
			$list = $out;
		}
		return $list;
	}

	public static function wallet_enabled(): bool {
		return ! empty( Settings::get( 'wallet_enabled' ) );
	}

	/** Registro delle assicurazioni dei volontari attivo? */
	public static function insurance_volunteers(): bool {
		return (bool) Settings::get( 'insurance_volunteers' );
	}

	/** Polizze dell'associazione attive? */
	public static function insurance_association(): bool {
		return (bool) Settings::get( 'insurance_association' );
	}

	/** Configurazione dei pagamenti con i segreti in chiaro (solo per uso interno: mai da stampare). */
	public static function payment_config(): array {
		$c = array( 'payment_provider' => (string) Settings::get( 'payment_provider' ), 'site_https' => 0 === strpos( home_url(), 'https://' ) ? 1 : 0 );
		foreach ( array( 'stripe_mode', 'stripe_publishable_key', 'paypal_mode', 'paypal_client_id', 'sumup_merchant_code' ) as $k ) {
			$c[ $k ] = (string) Settings::get( $k );
		}
		foreach ( self::SECRET_KEYS as $k ) {
			$c[ $k ] = Settings::secret( $k );
		}
		return $c;
	}

	/** Valori predefiniti delle impostazioni aggiuntive. */
	public static function defaults( array $d ): array {
		return $d + array(
			'pwa_enabled'             => 0,     // app installabile (PWA): spenta di default
			'push_enabled'            => 0,     // notifiche push: spente di default
			'pwa_name'                => '',    // nome dell'app (vuoto = denominazione)
			'pwa_short_name'          => '',    // nome breve sotto l'icona
			'pwa_icon_id'             => 0,     // icona dell'app (immagine PNG della libreria media)
			'woo_default_product'     => 0,     // prodotto WooCommerce per le voci senza un prodotto proprio
			'fivepm_enabled'          => 0,     // 5x1000: spento di default
			'fivepm_text'             => '',    // messaggio personalizzato (vuoto = quello standard)
			'insurance_volunteers'    => 0,     // registro delle assicurazioni dei volontari: spento di default
			'insurance_association'   => 0,     // polizze dell'associazione (responsabilità civile, infortuni): spente di default
			'receipt_footer'          => '',    // riga in fondo alle ricevute (es. riferimento normativo): la decide l'associazione
			'payment_provider'        => PaymentConfig::NONE,
			'stripe_mode'             => 'test',
			'stripe_publishable_key'  => '',
			'stripe_secret_key'       => '',   // cifrata
			'stripe_webhook_secret'   => '',   // cifrata
			'paypal_mode'             => 'sandbox',
			'paypal_client_id'        => '',
			'paypal_client_secret'    => '',   // cifrata
			'sumup_api_key'           => '',   // cifrata
			'sumup_merchant_code'     => '',
			'pay_fee_book'            => 1,    // registra in prima nota, a ogni incasso online, la commissione stimata come spesa
			'pay_fee_fixed_stripe'    => 25,   // commissioni di incasso per metodo, di partenza le tariffe standard pubblicate dai gestori (Italia, carte europee standard): fisso (centesimi), percentuale (punti base: 150 = 1,50%), ribaltare su chi paga (spento: la legge vieta il supplemento sulle carte dei consumatori)
			'pay_fee_pct_stripe'      => 150,
			'pay_fee_pass_stripe'     => 0,
			'pay_fee_fixed_paypal'    => 35,
			'pay_fee_pct_paypal'      => 340,
			'pay_fee_pass_paypal'     => 0,
			'pay_fee_fixed_sumup'     => 0,
			'pay_fee_pct_sumup'       => 250,
			'pay_fee_pass_sumup'      => 0,
			'pay_label_sumup'         => '',
			'pay_note_sumup'          => '',
			'wallet_enabled'          => 0,     // pulsanti Apple/Google Wallet
			'wallet_apple_pass_type'  => '',    // Apple Wallet: identificativo del tipo di pass (dal certificato)
			'wallet_apple_team'       => '',
			'wallet_apple_cert_pem'   => '',    // certificato (pubblico)
			'wallet_apple_wwdr_pem'   => '',    // certificato intermedio di Apple (pubblico)
			'wallet_apple_key_pem'    => '',    // cifrata
			'wallet_google_issuer'    => '',    // Google Wallet: ID dell'emittente
			'wallet_google_email'     => '',    // account di servizio
			'wallet_google_key_pem'   => '',    // cifrata
			'has_vat'                 => 0,         // l'ente ha la partita IVA: spento di default, senza non compaiono aliquote e importi IVA
			'vat_number'              => '',        // partita IVA (11 cifre)
			'vat_default_rate'        => 22,        // aliquota IVA proposta (%)
			'vat_prices_mode'         => 'incl',    // importi inseriti IVA compresa ('incl') o esclusa ('escl')
			'vat_membership_rate'     => '',        // aliquota IVA delle quote associative: vuoto = fuori campo IVA
			'pay_label_stripe'        => '',        // diciture dei pulsanti di pagamento (vuoto = predefinita, vedi PaymentConfig)
			'pay_note_stripe'         => '',
			'pay_label_paypal'        => '',
			'pay_note_paypal'         => '',
			'pay_label_woocommerce'   => '',
			'pay_note_woocommerce'    => '',
		);
	}

	/** Controlli delle impostazioni aggiuntive. */
	public static function clean( array $clean, array $values ): array {
		$clean['pwa_enabled']             = empty( $clean['pwa_enabled'] ) ? 0 : 1;
		$clean['push_enabled']            = empty( $clean['push_enabled'] ) ? 0 : 1;
		$clean['pwa_name']                = mb_substr( trim( (string) $clean['pwa_name'] ), 0, 45 );
		$clean['pwa_short_name']          = mb_substr( trim( (string) $clean['pwa_short_name'] ), 0, 12 );
		$clean['pwa_icon_id']             = max( 0, (int) $clean['pwa_icon_id'] );
		$clean['woo_default_product']     = max( 0, (int) $clean['woo_default_product'] );
		$clean['fivepm_enabled']          = empty( $clean['fivepm_enabled'] ) ? 0 : 1;
		$clean['fivepm_text']             = mb_substr( trim( (string) $clean['fivepm_text'] ), 0, 1000 );
		$clean['insurance_volunteers']     = empty( $clean['insurance_volunteers'] ) ? 0 : 1;
		$clean['insurance_association']    = empty( $clean['insurance_association'] ) ? 0 : 1;
		$clean['receipt_footer']          = mb_substr( trim( (string) $clean['receipt_footer'] ), 0, 300 );
		$clean['payment_provider']        = PaymentConfig::is_valid( (string) $clean['payment_provider'] ) ? (string) $clean['payment_provider'] : PaymentConfig::NONE;
		$clean['stripe_mode']             = 'live' === $clean['stripe_mode'] ? 'live' : 'test';
		$clean['paypal_mode']             = 'live' === $clean['paypal_mode'] ? 'live' : 'sandbox';
		$clean['wallet_enabled']          = empty( $clean['wallet_enabled'] ) ? 0 : 1;
		foreach ( array( 'wallet_apple_cert_pem', 'wallet_apple_wwdr_pem' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 12000 );
		}
		foreach ( array( 'wallet_apple_pass_type', 'wallet_apple_team', 'wallet_google_issuer', 'wallet_google_email' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 200 );
		}
		$clean['has_vat']                 = empty( $clean['has_vat'] ) ? 0 : 1;
		$clean['vat_number']              = mb_substr( Fiscal::normalize_vat( (string) $clean['vat_number'] ), 0, 11 );
		$clean['vat_default_rate']        = max( 0, min( 30, (int) $clean['vat_default_rate'] ) );
		$clean['vat_membership_rate']     = null === Fiscal::clean_rate( $clean['vat_membership_rate'] ) ? '' : Fiscal::clean_rate( $clean['vat_membership_rate'] );
		$clean['vat_prices_mode']       = Fiscal::EXCLUDED === (string) $clean['vat_prices_mode'] ? Fiscal::EXCLUDED : Fiscal::INCLUDED;
		foreach ( array( 'stripe', 'paypal', 'sumup', 'woocommerce' ) as $g ) {
			$clean[ 'pay_label_' . $g ] = mb_substr( trim( (string) $clean[ 'pay_label_' . $g ] ), 0, 60 );
			$clean[ 'pay_note_' . $g ]  = mb_substr( trim( (string) $clean[ 'pay_note_' . $g ] ), 0, 300 );
		}
		foreach ( array( 'stripe_publishable_key', 'paypal_client_id', 'sumup_merchant_code' ) as $k ) {
			$clean[ $k ] = mb_substr( trim( (string) $clean[ $k ] ), 0, 200 );
		}
		$clean['pay_fee_book'] = empty( $clean['pay_fee_book'] ) ? 0 : 1;
		foreach ( PaymentConfig::fee_gateways() as $g ) { // commissioni: fisso fino a 20 €, percentuale fino al 25%
			$clean[ 'pay_fee_fixed_' . $g ] = max( 0, min( 2000, (int) $clean[ 'pay_fee_fixed_' . $g ] ) );
			$clean[ 'pay_fee_pct_' . $g ]   = max( 0, min( 2500, (int) $clean[ 'pay_fee_pct_' . $g ] ) );
			$clean[ 'pay_fee_pass_' . $g ]  = empty( $clean[ 'pay_fee_pass_' . $g ] ) ? 0 : 1;
		}
		return $clean;
	}
}

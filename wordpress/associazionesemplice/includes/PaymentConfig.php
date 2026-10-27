<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Configurazione dei pagamenti online: nessuno, WooCommerce oppure Stripe, PayPal o entrambi insieme.
 * Questa parte è solo CONFIGURAZIONE e verifica (pura, senza rete): l'incasso vero è un passo successivo.
 */
final class PaymentConfig {

	const NONE        = 'none';
	const WOOCOMMERCE = 'woocommerce';
	const STRIPE      = 'stripe';
	const PAYPAL      = 'paypal';
	const BOTH        = 'stripe_paypal';
	const SUMUP       = 'sumup';
	const STRIPE_SUMUP = 'stripe_sumup';
	const PAYPAL_SUMUP = 'paypal_sumup';
	const ALL         = 'stripe_paypal_sumup';

	/** Diciture predefinite dei pulsanti e delle note: si possono personalizzare da Impostazioni → Pagamenti online. */
	const DEFAULT_LABELS = array(
		self::STRIPE      => 'Paga con carta',
		self::PAYPAL      => 'Paga con PayPal',
		self::SUMUP       => 'Paga con carta (SumUp)',
		self::WOOCOMMERCE => 'Paga nel negozio',
	);

	public static function providers(): array {
		$out = array(
			self::NONE        => 'Nessuno (pagamento in sede o con bonifico)',
			self::WOOCOMMERCE => 'WooCommerce (negozio del sito)',
			self::STRIPE      => 'Stripe (carta)',
			self::PAYPAL      => 'PayPal',
			self::BOTH        => 'Stripe e PayPal insieme',
			self::SUMUP       => 'SumUp (carta)',
			self::STRIPE_SUMUP => 'Stripe e SumUp insieme',
			self::PAYPAL_SUMUP => 'PayPal e SumUp insieme',
			self::ALL         => 'Stripe, PayPal e SumUp insieme',
		);
		$extra = (array) self::filter( 'asem_direct_gateways', array() ); // un'estensione aggiunge altri gateway: id => nome
		if ( $extra ) {
			$names = array( self::STRIPE => 'Stripe', self::PAYPAL => 'PayPal', self::SUMUP => 'SumUp' ) + $extra;
			$ids   = array_keys( $names );
			$count = count( $ids );
			for ( $mask = 1; $mask < ( 1 << $count ); $mask++ ) { // ogni combinazione che contiene almeno un gateway aggiunto
				$set = array();
				foreach ( $ids as $i => $g ) {
					if ( $mask & ( 1 << $i ) ) {
						$set[] = $g;
					}
				}
				if ( ! array_intersect( $set, array_keys( $extra ) ) ) {
					continue;
				}
				$labels = array_map( function ( $g ) use ( $names ) {
					return $names[ $g ];
				}, $set );
				$last = array_pop( $labels );
				$out[ implode( '_', $set ) ] = $labels ? implode( ', ', $labels ) . ' e ' . $last . ' insieme' : $last;
			}
		}
		return $out;
	}

	public static function is_valid( string $provider ): bool {
		return isset( self::providers()[ $provider ] );
	}

	/** Gateway diretti previsti dalla scelta: stripe e/o paypal (WooCommerce e «nessuno» non ne hanno). @return string[] */
	public static function gateways_of( string $provider ): array {
		switch ( $provider ) {
			case self::STRIPE:
				return array( self::STRIPE );
			case self::PAYPAL:
				return array( self::PAYPAL );
			case self::BOTH:
				return array( self::STRIPE, self::PAYPAL );
			case self::SUMUP:
				return array( self::SUMUP );
			case self::STRIPE_SUMUP:
				return array( self::STRIPE, self::SUMUP );
			case self::PAYPAL_SUMUP:
				return array( self::PAYPAL, self::SUMUP );
			case self::ALL:
				return array( self::STRIPE, self::PAYPAL, self::SUMUP );
		}
		if ( self::is_valid( $provider ) && ! in_array( $provider, array( self::NONE, self::WOOCOMMERCE ), true ) ) { // combinazione con gateway aggiunti da un'estensione
			return array_values( array_intersect( array_merge( array( self::STRIPE, self::PAYPAL, self::SUMUP ), array_keys( (array) self::filter( 'asem_direct_gateways', array() ) ) ), explode( '_', $provider ) ) );
		}
		return array();
	}

	/** Diciture predefinite dei pulsanti, comprese quelle di un'estensione. */
	public static function default_labels(): array {
		return (array) self::filter( 'asem_payment_default_labels', self::DEFAULT_LABELS );
	}

	/** Un punto di estensione neutro: senza WordPress (prove) o senza gestori restituisce il valore di partenza. */
	private static function filter( string $tag, $value, ...$args ) {
		return function_exists( 'apply_filters' ) ? apply_filters( $tag, $value, ...$args ) : $value;
	}

	/** Metodi con commissioni configurabili (il negozio WooCommerce ha le sue). */
	public static function fee_gateways(): array {
		return (array) self::filter( 'asem_fee_gateways', array( self::STRIPE, self::PAYPAL, self::SUMUP ) );
	}

	/** Commissione di incasso stimata su un importo: fisso + percentuale (punti base: 150 = 1,50%), arrotondata al centesimo. */
	public static function fee_cents( int $amount_cents, int $fixed_cents, int $bp ): int {
		if ( $amount_cents <= 0 ) {
			return 0;
		}
		return max( 0, $fixed_cents ) + (int) round( $amount_cents * max( 0, $bp ) / 10000 );
	}

	/**
	 * Importo da addebitare perché, tolta la commissione (fissa + percentuale sul totale addebitato), restino $base_cents: la commissione si calcola
	 * sul totale pagato, quindi il supplemento è più della commissione calcolata sul solo importo dovuto.
	 */
	public static function gross_up( int $base_cents, int $fixed_cents, int $bp ): int {
		if ( $base_cents <= 0 ) {
			return 0;
		}
		$bp = max( 0, min( 2500, $bp ) );
		return (int) ceil( ( $base_cents + max( 0, $fixed_cents ) ) * 10000 / ( 10000 - $bp ) );
	}

	/** Commissioni di un metodo dalle impostazioni: [fisso in centesimi, percentuale in punti base, ribaltare sul pagante]. */
	public static function fee_of( array $settings, string $gateway ): array {
		return array(
			max( 0, min( 2000, (int) ( $settings[ 'pay_fee_fixed_' . $gateway ] ?? 0 ) ) ),
			max( 0, min( 2500, (int) ( $settings[ 'pay_fee_pct_' . $gateway ] ?? 0 ) ) ),
			! empty( $settings[ 'pay_fee_pass_' . $gateway ] ),
		);
	}

	/** Frase sotto il pulsante quando la commissione è a carico di chi paga ('' se no). */
	public static function fee_note( array $settings, string $gateway ): string {
		list( $fixed, $bp, $pass ) = self::fee_of( $settings, $gateway );
		if ( ! $pass || ( 0 === $fixed && 0 === $bp ) ) {
			return '';
		}
		$parts = array();
		if ( $bp > 0 ) {
			$parts[] = rtrim( rtrim( number_format( $bp / 100, 2, ',', '' ), '0' ), ',' ) . '%';
		}
		if ( $fixed > 0 ) {
			$parts[] = Money::format( $fixed );
		}
		return 'Con questo metodo si aggiunge una commissione di incasso (' . implode( ' + ', $parts ) . ') al totale.';
	}

	/**
	 * Controlla la configurazione del gateway scelto (o di entrambi).
	 *
	 * @param array $c payment_provider, stripe_mode (test|live), stripe_publishable_key, stripe_secret_key, stripe_webhook_secret,
	 *                 paypal_mode (sandbox|live), paypal_client_id, paypal_client_secret   (segreti in chiaro),
	 *                 site_https (0|1, facoltativo: se 0 la modalità reale è rifiutata)
	 * @return array ['errors' => string[], 'warnings' => string[]]
	 */
	public static function validate( array $c ): array {
		$provider = (string) ( $c['payment_provider'] ?? self::NONE );
		if ( ! self::is_valid( $provider ) ) {
			return array( 'errors' => array( 'Gateway di pagamento non valido.' ), 'warnings' => array() );
		}
		$errors   = array();
		$warnings = array();
		foreach ( self::gateways_of( $provider ) as $g ) {
			$r        = self::validate_gateway( $c, $g );
			$errors   = array_merge( $errors, $r['errors'] );
			$warnings = array_merge( $warnings, $r['warnings'] );
		}
		if ( self::WOOCOMMERCE === $provider ) {
			$warnings[] = 'WooCommerce: collega le quote ai prodotti in Strumenti → Integrazioni; le voci senza prodotto non si possono pagare online. Il negozio deve usare l\'euro.';
		}
		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	/** Controllo di un solo gateway diretto (stripe o paypal). @return array ['errors' => string[], 'warnings' => string[]] */
	public static function validate_gateway( array $c, string $gateway ): array {
		$errors   = array();
		$warnings = array();
		$https    = ! array_key_exists( 'site_https', $c ) || ! empty( $c['site_https'] );
		if ( self::STRIPE === $gateway ) {
			$mode = 'live' === ( $c['stripe_mode'] ?? 'test' ) ? 'live' : 'test';
			$pk   = trim( (string) ( $c['stripe_publishable_key'] ?? '' ) );
			$sk   = trim( (string) ( $c['stripe_secret_key'] ?? '' ) );
			$wh   = trim( (string) ( $c['stripe_webhook_secret'] ?? '' ) );
			if ( '' === $pk ) {
				$errors[] = 'Stripe: manca la chiave pubblicabile (pk_…).';
			} elseif ( ! preg_match( '/^pk_(test|live)_[A-Za-z0-9]+$/', $pk ) ) {
				$errors[] = 'Stripe: la chiave pubblicabile deve iniziare con pk_test_ o pk_live_.';
			} elseif ( 0 !== strpos( $pk, 'pk_' . $mode . '_' ) ) {
				$errors[] = 'Stripe: la chiave pubblicabile non corrisponde alla modalità ' . ( 'live' === $mode ? 'reale (live)' : 'di prova (test)' ) . '.';
			}
			if ( '' === $sk ) {
				$errors[] = 'Stripe: manca la chiave segreta (sk_… o rk_…).';
			} elseif ( ! preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $sk ) ) {
				$errors[] = 'Stripe: la chiave segreta deve iniziare con sk_test_, sk_live_ (o rk_ per le chiavi con restrizioni).';
			} elseif ( ! preg_match( '/^(sk|rk)_' . $mode . '_/', $sk ) ) {
				$errors[] = 'Stripe: la chiave segreta non corrisponde alla modalità ' . ( 'live' === $mode ? 'reale (live)' : 'di prova (test)' ) . '.';
			}
			if ( '' === $wh ) {
				$warnings[] = 'Stripe: manca il segreto del webhook (whsec_…): senza di esso i pagamenti non possono essere confermati automaticamente.';
			} elseif ( 0 !== strpos( $wh, 'whsec_' ) ) {
				$errors[] = 'Stripe: il segreto del webhook deve iniziare con whsec_.';
			}
			if ( 'live' === $mode && ! $https ) {
				$errors[] = 'Stripe in modalità reale richiede che il sito sia servito in https: oggi l\'indirizzo del sito è http. Attiva https e aggiorna l\'indirizzo del sito, oppure usa la modalità di prova.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'Stripe è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::PAYPAL === $gateway ) {
			$mode = 'live' === ( $c['paypal_mode'] ?? 'sandbox' ) ? 'live' : 'sandbox';
			$id   = trim( (string) ( $c['paypal_client_id'] ?? '' ) );
			$sec  = trim( (string) ( $c['paypal_client_secret'] ?? '' ) );
			if ( '' === $id ) {
				$errors[] = 'PayPal: manca il Client ID.';
			} elseif ( ! preg_match( '/^[A-Za-z0-9_\-]{20,}$/', $id ) ) {
				$errors[] = 'PayPal: il Client ID non sembra valido.';
			}
			if ( '' === $sec ) {
				$errors[] = 'PayPal: manca il Client Secret.';
			} elseif ( ! preg_match( '/^[A-Za-z0-9_\-]{20,}$/', $sec ) ) {
				$errors[] = 'PayPal: il Client Secret non sembra valido.';
			}
			if ( 'live' === $mode && ! $https ) {
				$errors[] = 'PayPal in modalità reale richiede che il sito sia servito in https: oggi l\'indirizzo del sito è http. Attiva https e aggiorna l\'indirizzo del sito, oppure usa la modalità sandbox.';
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'PayPal è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::SUMUP === $gateway ) {
			$key  = trim( (string) ( $c['sumup_api_key'] ?? '' ) );
			$code = trim( (string) ( $c['sumup_merchant_code'] ?? '' ) );
			if ( '' === $key ) {
				$errors[] = 'SumUp: manca la chiave API (da SumUp → Sviluppatori → Chiavi API).';
			} elseif ( ! preg_match( '/^[A-Za-z0-9_\-]{16,}$/', $key ) ) {
				$errors[] = 'SumUp: la chiave API non sembra valida.';
			}
			if ( '' === $code ) {
				$errors[] = 'SumUp: manca il codice commerciante (merchant code, tipo MABCD123).';
			} elseif ( ! preg_match( '/^[A-Za-z0-9]{4,20}$/', $code ) ) {
				$errors[] = 'SumUp: il codice commerciante non sembra valido (solo lettere e cifre).';
			}
			if ( ! $https ) {
				$warnings[] = 'SumUp: l\'indirizzo del sito è http. Per i pagamenti veri il sito deve essere servito in https: il ritorno dal pagamento potrebbe non funzionare.';
			}
			if ( empty( $errors ) ) {
				$warnings[] = 'SumUp non ha una modalità di prova separata sul sito: con una chiave di un account reale i pagamenti sono veri (per provare usa un account di prova SumUp).';
			}
		}
		return (array) self::filter( 'asem_payment_validate', array( 'errors' => $errors, 'warnings' => $warnings ), $c, $gateway ); // un'estensione controlla i suoi metodi
	}

	/** Dicitura del pulsante di un gateway (personalizzata o predefinita). @param array $settings impostazioni (Settings::all()) */
	public static function label( string $gateway, array $settings = array() ): string {
		$v = trim( (string) ( $settings[ 'pay_label_' . $gateway ] ?? '' ) );
		return '' !== $v ? $v : (string) ( self::default_labels()[ $gateway ] ?? '' );
	}

	/** Nota sotto il pulsante di un gateway (personalizzata o predefinita). */
	public static function note( string $gateway, array $settings = array() ): string {
		$v = trim( (string) ( $settings[ 'pay_note_' . $gateway ] ?? '' ) );
		if ( '' !== $v ) {
			return $v;
		}
		switch ( $gateway ) {
			case self::STRIPE:
				return 'Paghi su una pagina sicura di Stripe: i dati della carta non passano da questo sito.';
			case self::PAYPAL:
				return 'Paghi su una pagina sicura di PayPal: i dati della carta non passano da questo sito.';
			case self::SUMUP:
				return 'Paghi su una pagina sicura di SumUp: i dati della carta non passano da questo sito.';
			case self::WOOCOMMERCE:
				return 'Completi il pagamento nel negozio del sito: non appena risulta pagato, lo registriamo.';
		}
		return (string) self::filter( 'asem_payment_note', '', $gateway ); // un'estensione aggiunge la nota dei suoi metodi
	}
}

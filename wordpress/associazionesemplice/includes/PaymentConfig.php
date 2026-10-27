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
	const SATISPAY    = 'satispay';

	/** Gateway diretti, nell'ordine in cui compongono il nome di una scelta multipla (ad esempio stripe_paypal_satispay). */
	const DIRECT = array( self::STRIPE, self::PAYPAL, self::SUMUP, self::SATISPAY );

	/** Diciture predefinite dei pulsanti e delle note: si possono personalizzare da Impostazioni → Pagamenti online. */
	const DEFAULT_LABELS = array(
		self::STRIPE      => 'Paga con carta',
		self::PAYPAL      => 'Paga con PayPal',
		self::SUMUP       => 'Paga con carta (SumUp)',
		self::SATISPAY    => 'Paga con Satispay',
		self::WOOCOMMERCE => 'Paga nel negozio',
	);

	public static function providers(): array {
		$names = array( self::STRIPE => 'Stripe', self::PAYPAL => 'PayPal', self::SUMUP => 'SumUp', self::SATISPAY => 'Satispay' );
		$out   = array(
			self::NONE        => 'Nessuno (pagamento in sede o con bonifico)',
			self::WOOCOMMERCE => 'WooCommerce (negozio del sito)',
		);
		$by_size = array();
		for ( $mask = 1; $mask < 16; $mask++ ) { // tutte le combinazioni dei gateway diretti, prima i singoli poi le coppie…
			$set = array();
			foreach ( self::DIRECT as $i => $g ) {
				if ( $mask & ( 1 << $i ) ) {
					$set[] = $g;
				}
			}
			$by_size[ count( $set ) ][] = $set;
		}
		ksort( $by_size );
		foreach ( $by_size as $size => $list ) {
			foreach ( $list as $set ) {
				$labels = array();
				foreach ( $set as $g ) {
					$labels[] = $names[ $g ];
				}
				$last = array_pop( $labels );
				if ( 1 === $size ) {
					$text = $last . ( in_array( $set[0], array( self::STRIPE, self::SUMUP ), true ) ? ' (carta)' : '' );
				} else {
					$text = implode( ', ', $labels ) . ' e ' . $last . ' insieme';
				}
				$out[ implode( '_', $set ) ] = $text;
			}
		}
		return $out;
	}

	public static function is_valid( string $provider ): bool {
		return isset( self::providers()[ $provider ] );
	}

	/** Gateway diretti previsti dalla scelta (WooCommerce e «nessuno» non ne hanno). @return string[] */
	public static function gateways_of( string $provider ): array {
		if ( ! self::is_valid( $provider ) || self::NONE === $provider || self::WOOCOMMERCE === $provider ) {
			return array();
		}
		return array_values( array_intersect( self::DIRECT, explode( '_', $provider ) ) );
	}

	/** Metodi con commissioni configurabili (il negozio WooCommerce ha le sue). */
	public static function fee_gateways(): array {
		return array( self::STRIPE, self::PAYPAL, self::SUMUP, self::SATISPAY );
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
		if ( self::SATISPAY === $gateway ) {
			$mode = 'live' === ( $c['satispay_mode'] ?? 'sandbox' ) ? 'live' : 'sandbox';
			if ( '' === trim( (string) ( $c['satispay_key_id'] ?? '' ) ) || '' === trim( (string) ( $c['satispay_private_key'] ?? '' ) ) ) {
				$errors[] = 'Satispay: non è ancora collegato. Inserisci il codice di attivazione che trovi nell\'area Satispay Business e premi «Collega Satispay».';
			}
			if ( ! $https ) {
				$msg = 'Satispay richiede che il sito sia servito in https: oggi l\'indirizzo del sito è http (serve per la conferma automatica dei pagamenti).';
				if ( 'live' === $mode ) {
					$errors[] = $msg;
				} else {
					$warnings[] = $msg;
				}
			}
			if ( 'live' === $mode && empty( $errors ) ) {
				$warnings[] = 'Satispay è in modalità REALE: i pagamenti saranno veri.';
			}
		}
		if ( self::STRIPE === $gateway && ! empty( $c['stripe_klarna'] ) ) {
			$warnings[] = 'Klarna: per comparire tra i metodi di Stripe va attivato anche nel tuo account Stripe (Impostazioni → Metodi di pagamento). Funziona solo in euro, per importi da ' . Money::format( KlarnaRules::MIN_CENTS ) . ' a ' . Money::format( KlarnaRules::MAX_CENTS ) . ' e con l\'email di chi paga.';
		}
		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	/** Dicitura del pulsante di un gateway (personalizzata o predefinita). @param array $settings impostazioni (Settings::all()) */
	public static function label( string $gateway, array $settings = array() ): string {
		$v = trim( (string) ( $settings[ 'pay_label_' . $gateway ] ?? '' ) );
		return '' !== $v ? $v : (string) ( self::DEFAULT_LABELS[ $gateway ] ?? '' );
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
			case self::SATISPAY:
				return 'Confermi il pagamento nell\'app Satispay: i dati del tuo conto non passano da questo sito.';
			case self::WOOCOMMERCE:
				return 'Completi il pagamento nel negozio del sito: non appena risulta pagato, lo registriamo.';
		}
		return '';
	}
}

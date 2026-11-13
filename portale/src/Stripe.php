<?php
namespace Portale;

/** Stripe: abbonamenti con Checkout, portale di fatturazione, annullamento e verifica dei webhook. */
final class Stripe {

	const API = 'https://api.stripe.com/v1';

	/** Errore dell'ultima richiesta non riuscita (`code`, `decline_code`, `advice_code`…), vuoto se è andata bene. */
	public static $lastError = array();

	/** Rifiuti definitivi della carta (carta rubata o smarrita, numero o conto non validi, scaduta…): non si riprova mai. */
	const HARD_DECLINES = array( 'stolen_card', 'lost_card', 'pickup_card', 'invalid_account', 'invalid_number', 'expired_card', 'fraudulent', 'restricted_card' );

	/** L'ultimo errore è un rifiuto definitivo: lo dice la banca (`do_not_try_again`) o il motivo è tra quelli elencati. */
	public static function hardDecline(): bool {
		return 'do_not_try_again' === ( self::$lastError['advice_code'] ?? '' ) || in_array( (string) ( self::$lastError['decline_code'] ?? '' ), self::HARD_DECLINES, true );
	}

	/**
	 * @param int    $cents    importo, usato quando non c'è un prezzo già creato su Stripe (il prezzo ricorrente si crea da solo)
	 * @param string $interval `month` o `year`
	 * @return string indirizzo del pagamento ospitato da Stripe
	 */
	public static function checkoutUrl( string $subscriptionId, string $email, string $priceId, int $cents = 0, string $interval = 'month', string $name = '' ): string {
		$line = '' !== $priceId
			? array( 'line_items[0][price]' => $priceId )
			: array(
				'line_items[0][price_data][currency]'            => 'eur',
				'line_items[0][price_data][unit_amount]'         => $cents,
				'line_items[0][price_data][recurring][interval]' => 'year' === $interval ? 'year' : 'month',
				'line_items[0][price_data][product_data][name]'  => '' !== $name ? $name : 'Associazione Semplice',
			);
		$r = self::call( 'POST', '/checkout/sessions', $line + array(
			'mode'                                 => 'subscription',
			'line_items[0][quantity]'              => 1,
			'customer_email'                       => $email,
			'client_reference_id'                  => $subscriptionId,
			'subscription_data[metadata][sub_id]'  => $subscriptionId,
			'success_url'                          => Config::get( 'base_url' ) . '/grazie',
			'cancel_url'                           => Config::get( 'base_url' ) . '/',
		) );
		if ( empty( $r['url'] ) ) {
			throw new \RuntimeException( 'Stripe non ha restituito l\'indirizzo di pagamento.' );
		}
		return (string) $r['url'];
	}

	/** Pagamento singolo (senza rinnovo): l'importo arriva dal listino del pannello, non serve un prezzo creato su Stripe. */
	public static function oneTimeUrl( string $subscriptionId, string $email, int $cents, string $name ): string {
		$r = self::call( 'POST', '/checkout/sessions', array(
			'mode'                                                  => 'payment',
			'line_items[0][price_data][currency]'                   => 'eur',
			'line_items[0][price_data][unit_amount]'                => $cents,
			'line_items[0][price_data][product_data][name]'         => $name,
			'line_items[0][quantity]'                               => 1,
			'customer_email'                                        => $email,
			'client_reference_id'                                   => $subscriptionId,
			'payment_intent_data[metadata][sub_id]'                 => $subscriptionId,
			'success_url'                                           => Config::get( 'base_url' ) . '/grazie',
			'cancel_url'                                            => Config::get( 'base_url' ) . '/',
		) );
		if ( empty( $r['url'] ) ) {
			throw new \RuntimeException( 'Stripe non ha restituito l\'indirizzo di pagamento.' );
		}
		return (string) $r['url'];
	}

	/** Cambia il prezzo di un abbonamento dal prossimo addebito, senza calcoli proporzionali né addebiti immediati (la differenza si incassa a parte). */
	public static function switchPrice( string $stripeSubscription, string $priceId, int $cents = 0, string $interval = 'month' ): void {
		$sub  = self::call( 'GET', '/subscriptions/' . rawurlencode( $stripeSubscription ), array() );
		$item = (string) ( $sub['items']['data'][0]['id'] ?? '' );
		if ( '' === $priceId && $cents > 0 && ! empty( $sub['items']['data'][0]['price']['product'] ) ) {
			// Nessun prezzo creato a mano: se ne crea uno con l'importo del piano, sullo stesso prodotto dell'abbonamento.
			$new     = self::call( 'POST', '/prices', array(
				'currency'           => 'eur',
				'unit_amount'        => $cents,
				'recurring[interval]' => 'year' === $interval ? 'year' : 'month',
				'product'            => (string) $sub['items']['data'][0]['price']['product'],
			) );
			$priceId = (string) ( $new['id'] ?? '' );
		}
		if ( '' === $item || '' === $priceId ) {
			throw new \RuntimeException( 'Stripe: abbonamento o prezzo non trovato per il cambio piano.' );
		}
		self::call( 'POST', '/subscriptions/' . rawurlencode( $stripeSubscription ), array(
			'items[0][id]'       => $item,
			'items[0][price]'    => $priceId,
			'proration_behavior' => 'none',
		) );
	}

	/**
	 * «Riattiva» su un abbonamento con pagamento non riuscito: paga la fattura aperta con il metodo di pagamento già salvato.
	 * Premuto due volte non addebita due volte: una fattura già pagata non si ripaga.
	 * @return string `paid` (pagata ora o già pagata) | `declined` (la carta ha rifiutato) | `hard` (rifiuto definitivo: non si riprova) | `unavailable` (niente da pagare: abbonamento chiuso o senza fattura aperta)
	 */
	public static function payOpenInvoice( string $stripeSubscription ): string {
		$s = self::call( 'GET', '/subscriptions/' . rawurlencode( $stripeSubscription ) . '?expand%5B%5D=latest_invoice', array() );
		if ( in_array( (string) ( $s['status'] ?? '' ), array( 'canceled', 'incomplete_expired' ), true ) || ! is_array( $s['latest_invoice'] ?? null ) ) {
			return 'unavailable';
		}
		$inv = $s['latest_invoice'];
		if ( 'paid' === ( $inv['status'] ?? '' ) ) {
			return 'paid';
		}
		if ( 'open' !== ( $inv['status'] ?? '' ) || empty( $inv['id'] ) ) {
			return 'unavailable';
		}
		try {
			self::call( 'POST', '/invoices/' . rawurlencode( (string) $inv['id'] ) . '/pay', array() );
		} catch ( \RuntimeException $e ) {
			return self::hardDecline() ? 'hard' : 'declined';
		}
		return 'paid';
	}

	public static function portalUrl( string $stripeCustomer ): string {
		$r = self::call( 'POST', '/billing_portal/sessions', array( 'customer' => $stripeCustomer, 'return_url' => Config::get( 'base_url' ) . '/account' ) );
		return (string) ( $r['url'] ?? '' );
	}

	/** Disdetta a fine periodo: l'abbonamento resta attivo fino alla scadenza già pagata. */
	public static function cancelAtPeriodEnd( string $stripeSubscription ): void {
		self::call( 'POST', '/subscriptions/' . rawurlencode( $stripeSubscription ), array( 'cancel_at_period_end' => 'true' ) );
	}

	/**
	 * Verifica l'intestazione Stripe-Signature (HMAC-SHA256 su "t.corpo", tolleranza 5 minuti).
	 *
	 * @param int|null $now solo per i test
	 */
	public static function verify( string $payload, string $header, string $secret, ?int $now = null ): bool {
		$t   = null;
		$sig = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( null === $t || ! $sig || '' === $secret || abs( ( $now ?? time() ) - $t ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	private static function call( string $method, string $path, array $form ): array {
		if ( null !== Config::get( 'stripe.live', null ) && Settings::stripeLive() !== (bool) preg_match( '/^(sk|rk)_live_/', (string) Config::get( 'stripe.secret_key', '' ) ) ) {
			throw new UserError( 'La modalità di Stripe scelta in Pagamenti non corrisponde alla chiave inserita: correggila prima di incassare.' );
		}
		$headers = array( 'Authorization: Bearer ' . Config::get( 'stripe.secret_key' ), 'Content-Type: application/x-www-form-urlencoded' );
		list( $status, $raw ) = Http::send( $method, self::API . $path, $headers, http_build_query( $form ) );
		$data = json_decode( $raw, true );
		self::$lastError = ( $status >= 400 && is_array( $data ) && is_array( $data['error'] ?? null ) ) ? $data['error'] : array();
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new \RuntimeException( 'Stripe: richiesta non riuscita (' . $status . ').' );
		}
		return $data;
	}
}

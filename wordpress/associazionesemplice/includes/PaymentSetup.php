<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Collegamento di Stripe e PayPal dalla configurazione guidata: prova subito le chiavi incollate (la stessa prova di Pagamenti online) e,
 * per Stripe, crea da solo il webhook e ne tiene il segreto. Non scrive nulla: restituisce i valori da salvare e le frasi da mostrare.
 */
final class PaymentSetup {

	/**
	 * @param string   $provider    PaymentConfig::STRIPE o PaymentConfig::PAYPAL
	 * @param array    $in          campi scritti dall'utente (stripe_mode, stripe_publishable_key, stripe_secret_key, paypal_mode, paypal_client_id, paypal_client_secret)
	 * @param array    $saved       configurazione già salvata, con i segreti in chiaro: serve quando un campo segreto resta vuoto
	 * @param callable $http        function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
	 * @param string   $webhook_url indirizzo pubblico del webhook di Stripe
	 * @param bool     $webhook     creare il webhook di Stripe se la prova riesce
	 * @return array ['values' => impostazioni da salvare, 'notes' => frasi per l'utente, 'ok' => true prova riuscita, false fallita, null niente da provare]
	 */
	public static function connect( string $provider, array $in, array $saved, callable $http, string $webhook_url, bool $webhook ): array {
		if ( PaymentConfig::STRIPE === $provider ) {
			return self::stripe( $in, $saved, $http, $webhook_url, $webhook );
		}
		return self::paypal( $in, $saved, $http );
	}

	private static function clean( $v ): string {
		return trim( (string) $v );
	}

	private static function stripe( array $in, array $saved, callable $http, string $webhook_url, bool $webhook ): array {
		$mode   = 'live' === ( $in['stripe_mode'] ?? '' ) ? 'live' : 'test';
		$pk     = self::clean( $in['stripe_publishable_key'] ?? '' );
		$new    = self::clean( $in['stripe_secret_key'] ?? '' );
		$secret = '' !== $new ? $new : self::clean( $saved['stripe_secret_key'] ?? '' );
		if ( '' === $secret ) {
			return array( 'values' => array(), 'notes' => array(), 'ok' => null ); // chiavi rimandate: si compilano dopo in Pagamenti online
		}
		$values = array( 'stripe_mode' => $mode );
		if ( '' !== $pk ) {
			$values['stripe_publishable_key'] = $pk;
		}
		if ( '' !== $new ) {
			$values['stripe_secret_key'] = $new;
		}
		$res = Gateways::test( PaymentConfig::STRIPE, array( 'stripe_secret_key' => $secret, 'stripe_mode' => $mode ), $http );
		if ( empty( $res['ok'] ) ) {
			return array( 'values' => $values, 'notes' => array( (string) $res['message'] . ' Le chiavi restano salvate, ma Stripe non è stato attivato: correggile in Pagamenti online.' ), 'ok' => false );
		}
		$notes = array( (string) $res['message'] );
		if ( $webhook ) {
			$notes[] = self::make_webhook( $secret, $webhook_url, self::clean( $saved['stripe_webhook_secret'] ?? '' ), $http, $values );
		}
		return array( 'values' => $values, 'notes' => $notes, 'ok' => true );
	}

	/** Crea il webhook di Stripe se serve; restituisce la frase da mostrare e aggiunge il segreto ai valori. */
	private static function make_webhook( string $secret, string $url, string $have_secret, callable $http, array &$values ): string {
		if ( '' !== $have_secret ) {
			return 'Stripe: il segreto del collegamento per la conferma dei pagamenti (webhook) è già inserito e resta quello.';
		}
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return 'Stripe: il collegamento per la conferma dei pagamenti (webhook) si crea solo su un sito raggiungibile da internet con https. Crealo a mano come spiegato in Pagamenti online.';
		}
		try {
			if ( StripeApi::find_webhook( $http, $secret, $url ) ) {
				return 'Stripe: nel tuo account il collegamento per la conferma dei pagamenti (webhook) esiste già. Stripe mostra il suo segreto solo alla creazione: copialo dalla pagina dei webhook di Stripe e incollalo in Pagamenti online.';
			}
			$w                              = StripeApi::create_webhook( $http, $secret, $url, StripeApi::webhook_events() );
			$values['stripe_webhook_secret'] = $w['secret'];
			return 'Stripe: il collegamento per la conferma dei pagamenti (webhook) è stato creato e il suo segreto è salvato.';
		} catch ( \RuntimeException $e ) {
			return 'Stripe: il collegamento per la conferma dei pagamenti (webhook) non si è creato da solo: ' . $e->getMessage() . ' Se usi una chiave con restrizioni, la chiave deve poter scrivere su «Checkout Sessions», «Refunds» e «Webhook Endpoints»: correggi e riprova; altrimenti crealo a mano come spiegato in Pagamenti online. I pagamenti si confermano comunque quando il socio torna sul sito e con il controllo automatico ogni ora.';
		}
	}

	private static function paypal( array $in, array $saved, callable $http ): array {
		$mode = 'live' === ( $in['paypal_mode'] ?? '' ) ? 'live' : 'sandbox';
		$id   = self::clean( $in['paypal_client_id'] ?? '' );
		$new  = self::clean( $in['paypal_client_secret'] ?? '' );
		$id   = '' !== $id ? $id : self::clean( $saved['paypal_client_id'] ?? '' );
		$sec  = '' !== $new ? $new : self::clean( $saved['paypal_client_secret'] ?? '' );
		if ( '' === $id && '' === $sec ) {
			return array( 'values' => array(), 'notes' => array(), 'ok' => null );
		}
		$values = array( 'paypal_mode' => $mode, 'paypal_client_id' => $id );
		if ( '' !== $new ) {
			$values['paypal_client_secret'] = $new;
		}
		$res = Gateways::test( PaymentConfig::PAYPAL, array( 'paypal_client_id' => $id, 'paypal_client_secret' => $sec, 'paypal_mode' => $mode ), $http );
		if ( empty( $res['ok'] ) ) {
			return array( 'values' => $values, 'notes' => array( (string) $res['message'] . ' Le credenziali restano salvate, ma PayPal non è stato attivato: correggile in Pagamenti online.' ), 'ok' => false );
		}
		return array( 'values' => $values, 'notes' => array( (string) $res['message'] ), 'ok' => true );
	}
}

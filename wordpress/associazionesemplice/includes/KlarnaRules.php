<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Klarna tramite Stripe: quando si può offrire (regole pure, senza rete).
 * Klarna funziona solo in euro, per importi tra un minimo e un massimo, e vuole l'email di chi paga. I limiti qui sono prudenti:
 * l'ultima parola è di Klarna e di Stripe, che rifiutano comunque ciò che non accettano.
 */
final class KlarnaRules {

	const MIN_CENTS = 3500;    // 35 €: prudente per Klarna via Stripe in Italia, così non si chiede a Stripe una sessione che rifiuterebbe
	const MAX_CENTS = 1000000; // 10.000 €

	/**
	 * @return array{ok:bool,reason:string} ok = Klarna si può offrire per questo pagamento; reason = perché no (vuoto se sì)
	 */
	public static function check( bool $enabled, int $amount_cents, string $payer_email, string $currency = 'EUR' ): array {
		if ( ! $enabled ) {
			return array( 'ok' => false, 'reason' => 'Klarna non è attivo.' );
		}
		if ( 'EUR' !== strtoupper( $currency ) ) {
			return array( 'ok' => false, 'reason' => 'Klarna accetta solo pagamenti in euro.' );
		}
		if ( $amount_cents < self::MIN_CENTS || $amount_cents > self::MAX_CENTS ) {
			return array( 'ok' => false, 'reason' => 'Klarna accetta importi da ' . Money::format( self::MIN_CENTS ) . ' a ' . Money::format( self::MAX_CENTS ) . '.' );
		}
		$email = trim( $payer_email );
		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( 'ok' => false, 'reason' => 'Klarna richiede l\'indirizzo email di chi paga.' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}

	/** Metodi di pagamento della Checkout Session di Stripe: con Klarna si elencano a mano (la carta resta sempre). @return string[] */
	public static function payment_method_types( bool $klarna ): array {
		return $klarna ? array( 'card', 'klarna' ) : array( 'card' );
	}

	/**
	 * Una sessione Checkout completata ma non ancora pagata (Klarna e altri metodi differiti): si attende l'esito.
	 *
	 * @param array $session oggetto checkout.session di Stripe
	 */
	public static function is_deferred( array $session ): bool {
		return 'unpaid' === ( $session['payment_status'] ?? '' ) && 'complete' === ( $session['status'] ?? 'complete' );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Donazione aggiunta al pagamento («Aggiungi X € per una donazione a …»): spenta di default, la propone l'ente con un importo suggerito
 * che chi paga può cambiare. È una voce di pagamento a sé: in prima nota va come «Erogazione liberale», ha una ricevuta sua e sulla
 * donazione non si ribalta la commissione di incasso.
 */
final class PaymentDonation {

	const MIN_CENTS = 100;
	const MAX_CENTS = 100000;

	/** La spunta compare: pagamenti online attivi, opzione accesa. Il checkout di WooCommerce non la supporta (le voci devono essere prodotti). */
	public static function enabled(): bool {
		return Edition::has( 'payments' ) && ! empty( Settings::get( 'donation_on' ) ) && PaymentConfig::WOOCOMMERCE !== (string) Settings::get( 'payment_provider' );
	}

	public static function suggested_cents(): int {
		return self::clamp( (int) Settings::get( 'donation_amount_cents' ) );
	}

	public static function clamp( int $cents ): int {
		return max( self::MIN_CENTS, min( self::MAX_CENTS, $cents ) );
	}

	/** Importo scritto da chi paga («5», «5,50») in centesimi; null se non è valido o fuori dai limiti. */
	public static function parse_amount( string $text ): ?int {
		$cents = Money::parse( $text );
		return null !== $cents && $cents >= self::MIN_CENTS && $cents <= self::MAX_CENTS ? $cents : null;
	}

	/** «È anche detraibile.» solo se l'ente lo ha dichiarato nelle impostazioni fiscali. */
	public static function deductible_note(): string {
		return ! empty( Settings::get( 'donation_deductible' ) ) ? 'È anche detraibile.' : '';
	}

	/** Voce di pagamento della donazione, intestata a chi paga. */
	public static function item( array $payer, int $cents ): array {
		$assoc = trim( (string) Settings::get( 'association_name' ) );
		return array(
			'type'         => PaymentItems::DONATION,
			'person_id'    => (int) $payer['id'],
			'person_name'  => trim( (string) $payer['first_name'] . ' ' . (string) $payer['last_name'] ),
			'amount_cents' => $cents,
			'label'        => '' !== $assoc ? 'Donazione a ' . $assoc : 'Donazione',
		);
	}
}

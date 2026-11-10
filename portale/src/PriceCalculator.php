<?php
namespace Portale;

/**
 * Regole di calcolo dei prezzi del catalogo (saas/docs/CONTRATTO_PORTALE.md §2.1), uguali a quelle del SaaS.
 * Importi in centesimi interi, IVA esclusa: nessun arrotondamento tranne quello dell'IVA, calcolata una sola volta sul totale.
 * Casi comuni nei due progetti: tests/fixtures/catalogo-casi.json (copia di saas/tests/Fixtures/catalogo-casi.json).
 */
final class PriceCalculator {

	/** Scaglioni marginali sui soci; `up_to` è il numero TOTALE di soci in cui la fascia finisce. Importo del periodo = mensile × months_billed. */
	public static function members( array $price, int $members ): int {
		$from    = self::int( $price['base_members'] ?? null );
		$monthly = self::int( $price['base_cents'] ?? null );
		$tiers   = is_array( $price['tiers'] ?? null ) ? $price['tiers'] : array();
		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) || $members <= $from ) {
				break;
			}
			$upTo     = $tier['up_to'] ?? null;
			$top      = is_int( $upTo ) ? min( $members, $upTo ) : $members;
			$monthly += ( $top - $from ) * self::int( $tier['unit_cents'] ?? null );
			$from     = $top;
		}
		return $monthly * max( 1, self::int( $price['months_billed'] ?? 1 ) );
	}

	/** Scatto per fascia; oltre l'ultima fascia chiusa il prezzo non è a listino (null, su preventivo). */
	public static function steps( array $price, int $quantity ): ?int {
		$steps = is_array( $price['steps'] ?? null ) ? $price['steps'] : array();
		foreach ( $steps as $step ) {
			if ( is_array( $step ) && ( ! isset( $step['up_to'] ) || ( is_int( $step['up_to'] ) && $quantity <= $step['up_to'] ) ) ) {
				return self::int( $step['amount_cents'] ?? null );
			}
		}
		return null;
	}

	/** Imponibile di un prezzo del catalogo (flat, members_tiers, step_tiers); $quantity = soci o quantità. Null se non a listino. */
	public static function net( array $price, int $quantity = 1 ): ?int {
		switch ( (string) ( $price['kind'] ?? '' ) ) {
			case 'flat':
				return self::int( $price['amount_cents'] ?? null );
			case 'members_tiers':
				return self::members( $price, $quantity );
			case 'step_tiers':
				return self::steps( $price, $quantity );
		}
		return null;
	}

	/** IVA di un imponibile, al centesimo più vicino (metà in su). */
	public static function vat( int $netCents, int $percent ): int {
		return intdiv( $netCents * $percent * 2 + 100, 200 );
	}

	/** @param mixed $value */
	private static function int( $value ): int {
		return is_int( $value ) ? $value : 0;
	}
}

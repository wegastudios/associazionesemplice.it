<?php
namespace Portale;

/**
 * Preventivo di un acquisto SaaS calcolato dal catalogo (mai da prezzi propri del portale), con le regole di
 * saas/docs/CONTRATTO_PORTALE.md §2.1: importi in centesimi IVA esclusa, sconto sull'imponibile, IVA una sola volta sul totale.
 * Il risultato è l'istantanea che entra nell'ordine: piano, `plan_version`, regole di prezzo e `rev` del catalogo.
 */
final class Quote {

	/**
	 * @param array      $doc    catalogo `catalogo/v1`
	 * @param array      $addons elenco di [code => qty]
	 * @param array|null $promo  risposta valida di promo.check: ['code' => …, 'discount' => ['type' => percent|amount_cents, 'value' => int]]
	 * @throws UserError se il piano, il periodo o il numero di soci non sono a listino
	 */
	public static function build( array $doc, string $plan, string $period, int $members, array $addons = array(), ?array $promo = null ): array {
		if ( ! in_array( $period, array( 'month', 'year' ), true ) || $members < 1 ) {
			throw new UserError( 'Scelta non valida.' );
		}
		$found = null;
		foreach ( Catalog::plans( $doc ) as $p ) {
			if ( ( $p['code'] ?? '' ) === $plan ) {
				$found = $p;
			}
		}
		$price = $found ? Catalog::price( $found, $period ) : null;
		$net   = $price ? PriceCalculator::net( $price, $members ) : null;
		if ( ! $found || ! $price || null === $net ) {
			throw new UserError( 'Questo piano non è a listino per le scelte fatte: scrivici per un preventivo.' );
		}
		$terms      = Catalog::terms( $doc );
		$exempt     = 'exempt' === $terms['vat_mode'];
		// Regime forfettario: nessuna IVA, il totale è l'importo dei prezzi (il vat_percent del catalogo si ignora).
		$vatPercent = $exempt ? 0 : (int) ( $price['vat_percent'] ?? 0 );
		$lines      = array( array( 'code' => $plan, 'qty' => 1, 'net' => $net ) );
		$total      = $net;
		$byCode     = array();
		foreach ( (array) ( $doc['addons'] ?? array() ) as $a ) {
			if ( is_array( $a ) && is_string( $a['code'] ?? null ) && false !== ( $a['visible'] ?? true ) ) {
				$byCode[ $a['code'] ] = $a;
			}
		}
		ksort( $addons );
		foreach ( $addons as $code => $qty ) {
			$qty = (int) $qty;
			$ap  = isset( $byCode[ $code ] ) ? Catalog::price( $byCode[ $code ], $period ) : null;
			$why = isset( $byCode[ $code ] ) ? Catalog::addonBlock( $doc, $plan, $byCode[ $code ] ) : null;
			if ( null !== $why ) {
				throw new UserError( $why . ': togli questo componente dal carrello.' );
			}
			if ( $qty < 1 || $qty > 1000 || ! $ap || ( ! $exempt && (int) ( $ap['vat_percent'] ?? 0 ) !== $vatPercent ) ) {
				throw new UserError( 'Un componente scelto non è disponibile per questo piano.' );
			}
			// Prezzo fisso: per unità × quantità; a scaglioni (step_tiers) l'importo dipende già dalla quantità intera.
			$an = PriceCalculator::net( $ap, $qty );
			if ( null === $an ) {
				throw new UserError( 'Un componente scelto non è a listino per questa quantità: scrivici per un preventivo.' );
			}
			$line    = 'flat' === ( $ap['kind'] ?? '' ) ? $an * $qty : $an;
			$lines[] = array( 'code' => (string) $code, 'qty' => $qty, 'net' => $line );
			$total  += $line;
		}
		$discount = 0;
		$pd       = null;
		if ( $promo && isset( $promo['discount']['type'], $promo['discount']['value'] ) && in_array( $promo['discount']['type'], array( 'percent', 'amount_cents' ), true ) ) {
			$v        = (int) $promo['discount']['value'];
			$pd       = array( 'type' => $promo['discount']['type'], 'value' => $v );
			$discount = 'percent' === $pd['type'] ? intdiv( $total * min( 100, max( 0, $v ) ) * 2 + 100, 200 ) : max( 0, $v );
			$discount = min( $total, $discount );
		}
		$taxable = $total - $discount;
		$vat     = PriceCalculator::vat( $taxable, $vatPercent );
		return array(
			'plan' => $plan, 'plan_version' => (int) ( $found['plan_version'] ?? 0 ), 'period' => $period, 'members' => $members,
			'rev' => (int) ( $doc['rev'] ?? 0 ), 'lines' => $lines, 'subtotal' => $total,
			'promo' => $pd ? (string) ( $promo['code'] ?? '' ) : '', 'promo_discount' => $pd,
			'discount' => $discount, 'net' => $taxable, 'vat_percent' => $vatPercent, 'vat' => $vat, 'total' => $taxable + $vat,
			'vat_mode' => $terms['vat_mode'], 'vat_note' => $terms['vat_note'],
			'price_rules' => $price, 'currency' => (string) ( $doc['currency'] ?? 'EUR' ),
		);
	}
}

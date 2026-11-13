<?php
namespace Portale;

/**
 * Ordini del SaaS: il prezzo e la `plan_version` sono fissati al momento dell'ordine (istantanea) e non cambiano se il catalogo cambia dopo;
 * i rinnovi restano su quell'istantanea (contratto §2.1). Al pagamento si invia `subscription` al SaaS (vedi {@see Outbox}).
 */
final class Orders {

	const PENDING = 'pending';
	const PAID    = 'paid';

	/**
	 * Apre l'ordine dal catalogo CORRENTE (ricaricato ora): la `rev` e i prezzi entrano nell'istantanea.
	 * @throws UserError se il catalogo non è disponibile o la scelta non è a listino
	 */
	public static function open( Db $db, string $customerId, string $plan, string $period, int $members, array $addons = array(), ?array $promo = null, ?int $now = null ): array {
		self::currentCatalog( $db, $now );
		$cat   = (array) Catalog::stored( $db, $now );
		$quote = Quote::build( $cat['doc'], $plan, $period, $members, $addons, $promo );
		$id    = Db::id();
		$at    = gmdate( 'Y-m-d H:i:s', $now ?? time() );
		$db->run( 'INSERT INTO orders (id, customer_id, product, plan, plan_version, period, members, catalog_rev, quote, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array(
			$id, $customerId, 'saas', $plan, $quote['plan_version'], $period, $members, $quote['rev'], (string) json_encode( $quote ), self::PENDING, $at, $at,
		) );
		return array( 'id' => $id ) + $quote;
	}

	/**
	 * Prima di incassare: se nel frattempo il catalogo è passato a una `rev` diversa l'ordine si ricalcola (nuova istantanea) e, se il totale
	 * è cambiato, il cliente vede «i prezzi sono stati aggiornati» e deve confermare di nuovo.
	 * @throws UserError
	 */
	public static function confirm( Db $db, string $orderId, ?int $now = null ): array {
		$o = self::get( $db, $orderId );
		if ( ! $o || self::PENDING !== $o['status'] ) {
			throw new UserError( 'Ordine non trovato.' );
		}
		self::currentCatalog( $db, $now );
		$cat = (array) Catalog::stored( $db, $now );
		if ( (int) $cat['rev'] !== (int) $o['catalog_rev'] ) {
			$q   = $o['quote'];
			$new = Quote::build( $cat['doc'], (string) $o['plan'], (string) $o['period'], (int) $o['members'], self::addonsOf( $q ), self::promoOf( $q ) );
			$db->run( 'UPDATE orders SET plan_version = ?, catalog_rev = ?, quote = ?, updated_at = ? WHERE id = ?', array( $new['plan_version'], $new['rev'], (string) json_encode( $new ), gmdate( 'Y-m-d H:i:s', $now ?? time() ), $orderId ) );
			if ( $new['total'] !== (int) $q['total'] ) {
				throw new UserError( 'I prezzi sono stati aggiornati: il nuovo totale è ' . money( $new['total'] ) . ' (IVA inclusa). Controlla e conferma di nuovo.' );
			}
		}
		return (array) self::get( $db, $orderId );
	}

	public static function get( Db $db, string $id ): ?array {
		$o = $db->one( 'SELECT * FROM orders WHERE id = ?', array( $id ) );
		if ( ! $o ) {
			return null;
		}
		$o['quote'] = (array) json_decode( (string) $o['quote'], true );
		return $o;
	}

	/** Catalogo corrente (ricaricato ora) per calcolare un preventivo. @return array ['rev', 'doc', …] @throws UserError */
	public static function catalog( Db $db, ?int $now = null ): array {
		self::currentCatalog( $db, $now );
		return (array) Catalog::stored( $db, $now );
	}

	/** Per acquistare serve il catalogo corrente: lo si ricarica ora; senza risposta valida non si vende. @throws UserError */
	private static function currentCatalog( Db $db, ?int $now ): void {
		$r = Catalog::refresh( $db, $now );
		if ( 'off' === $r || 0 === strpos( $r, 'error' ) || ! Catalog::stored( $db, $now ) ) {
			throw new UserError( 'Il listino non è raggiungibile in questo momento: riprova tra poco.' );
		}
	}

	/** Addon dell'ordine (tutte le righe tranne il piano). */
	public static function addonsOf( array $quote ): array {
		$out = array();
		foreach ( array_slice( (array) ( $quote['lines'] ?? array() ), 1 ) as $l ) {
			$out[ (string) $l['code'] ] = (int) $l['qty'];
		}
		return $out;
	}

	private static function promoOf( array $quote ): ?array {
		return ! empty( $quote['promo'] ) && ! empty( $quote['promo_discount'] ) ? array( 'code' => $quote['promo'], 'discount' => $quote['promo_discount'] ) : null;
	}
}

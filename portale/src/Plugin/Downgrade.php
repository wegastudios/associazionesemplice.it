<?php
namespace Portale\Plugin;

use Portale\Db;
use Portale\Site;

/**
 * Downgrade bloccato se l'uso supera i limiti del piano di arrivo (decisione 10/11/2026: downgrade e disdetta a fine periodo).
 * Per il plugin l'unico limite del catalogo è «siti ammessi» (`limits.sites`): i siti in uso sono le licenze ATTIVE del cliente con il
 * piano attuale. Se sono più di quelli ammessi dal piano di arrivo, il passaggio non si programma finché il cliente non ne disdice o
 * non ne sposta in eccesso. Senza limite nel piano di arrivo (o senza dati) non si blocca nulla.
 */
final class Downgrade {

	/** @return string[] motivi del blocco (vuoto = si può) */
	public static function blockers( Db $db, Catalog $catalog, array $sub, string $toPlanCode, ?int $now = null ): array {
		$to = $catalog->plan( Site::slug( $toPlanCode ) );
		if ( ! $to ) {
			return array();
		}
		$limits = (array) ( $to['limits'] ?? array() );
		if ( ! isset( $limits['sites'] ) ) {
			return array();
		}
		$max   = (int) $limits['sites'];
		$inUse = self::activeSites( $db, (string) $sub['customer_id'], (string) $sub['plan_code'], $now );
		if ( $inUse <= $max ) {
			return array();
		}
		return array( 'Hai ' . $inUse . ' siti attivi con il piano attuale e «' . $to['name'] . '» ne ammette ' . $max . ': disdici o sposta prima i siti in eccesso, poi potrai passare a questo piano.' );
	}

	/** Licenze attive (non scadute, non annullate) del cliente con quel piano. */
	public static function activeSites( Db $db, string $customerId, string $planCode, ?int $now = null ): int {
		$n = 0;
		$t = $now ?? time();
		foreach ( $db->all( "SELECT * FROM subscriptions WHERE customer_id = ? AND plan_code = ? AND status = 'active'", array( $customerId, $planCode ) ) as $s ) {
			$exp = ! empty( $s['expires_at'] ) ? strtotime( $s['expires_at'] . ' UTC' ) : 0;
			if ( 0 !== $exp && $exp > $t ) {
				++$n;
			}
		}
		return $n;
	}
}

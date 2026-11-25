<?php
namespace Portale;

/**
 * Consumi dal gestionale (contratto §4.1): POST /api/saas/v1/usage con {"token": JWT di tipo `usage`}, una volta al giorno.
 * Solo numeri per associazione (soci, operatori, fatture elettroniche dell'anno), nessun dato personale. Si tiene l'ultimo dato per abbonamento;
 * il prezzo non si ricalcola qui. Un rifiuto dice sempre il motivo (campo) nella risposta e nel log, senza i numeri.
 */
final class Usage {

	/** Dimensione massima del corpo e delle associazioni in un invio. */
	public const MAX_BODY = 262144;
	public const MAX_ORGS = 2000;

	/**
	 * @return array{0:int,1:array} codice HTTP e corpo della risposta
	 */
	public static function accept( Db $db, string $payload, ?int $now = null ): array {
		$now = $now ?? time();
		$b   = json_decode( $payload, true );
		$c   = is_array( $b ) && is_string( $b['token'] ?? null ) ? Catalog::verifyToken( $b['token'], 'usage', $now, self::MAX_BODY ) : null;
		if ( ! $c ) {
			return array( 401, array( 'error' => 'invalid_token' ) );
		}
		$bad = self::problem( $c );
		if ( null !== $bad ) {
			error_log( '[portale] consumi rifiutati: ' . $bad );
			return array( 422, array( 'error' => 'invalid_usage', 'field' => $bad ) );
		}
		$db->run( 'DELETE FROM seen_jti WHERE exp < ?', array( $now - 3600 ) );
		try {
			$db->run( 'INSERT INTO seen_jti (jti, exp) VALUES (?,?)', array( substr( $c['jti'], 0, 64 ), (int) $c['exp'] ) );
		} catch ( \PDOException $e ) {
			return array( 401, array( 'error' => 'token_used' ) );
		}
		$saved   = 0;
		$unknown = 0;
		foreach ( $c['orgs'] as $o ) {
			if ( ! $db->one( 'SELECT 1 AS x FROM saas_orgs WHERE ref = ?', array( $o['ref'] ) ) ) {
				++$unknown;
				continue;
			}
			$old = $db->one( 'SELECT day FROM saas_usage WHERE ref = ?', array( $o['ref'] ) );
			if ( $old && (string) $old['day'] > $c['date'] ) {
				continue; // un invio più vecchio di quello già salvato non lo sostituisce
			}
			$vals = array( $o['org_id'], $o['members'], $o['operators'], $o['einvoices_year'], $c['date'], Db::now(), $o['ref'] );
			if ( $old ) {
				$db->run( 'UPDATE saas_usage SET org_id = ?, members = ?, operators = ?, einvoices_year = ?, day = ?, updated_at = ? WHERE ref = ?', $vals );
			} else {
				$db->run( 'INSERT INTO saas_usage (org_id, members, operators, einvoices_year, day, updated_at, ref) VALUES (?,?,?,?,?,?,?)', $vals );
			}
			++$saved;
		}
		return array( 200, array( 'ok' => true, 'saved' => $saved, 'unknown' => $unknown ) );
	}

	/** Il primo campo che non va (nome, senza valori), o null se i claim sono a posto. */
	private static function problem( array $c ): ?string {
		$d = $c['date'] ?? null;
		if ( ! is_string( $d ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 'date';
		}
		$orgs = $c['orgs'] ?? null;
		if ( ! is_array( $orgs ) || count( $orgs ) > self::MAX_ORGS || array_values( $orgs ) !== $orgs ) {
			return 'orgs';
		}
		foreach ( $orgs as $i => $o ) {
			if ( ! is_array( $o ) ) {
				return 'orgs[' . $i . ']';
			}
			foreach ( array( 'org_id', 'ref' ) as $k ) {
				if ( ! is_string( $o[ $k ] ?? null ) || '' === $o[ $k ] || strlen( $o[ $k ] ) > 64 ) {
					return 'orgs[' . $i . '].' . $k;
				}
			}
			foreach ( array( 'members', 'operators', 'einvoices_year' ) as $k ) {
				if ( ! is_int( $o[ $k ] ?? null ) || $o[ $k ] < 0 || $o[ $k ] > 100000000 ) {
					return 'orgs[' . $i . '].' . $k;
				}
			}
		}
		return null;
	}
}

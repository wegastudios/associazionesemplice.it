<?php
namespace Portale\Plugin;

use Portale\Db;
use Portale\UserError;

/**
 * Codici promozionali del plugin (ARCHITETTURA_DOMINI §4.2–4.3). Decisione 10/11/2026: una promo è sempre di UN prodotto e vale solo
 * per i suoi piani (niente offerte combinate plugin+SaaS). Operazioni per il negozio, in processo:
 *   check()  = «il codice vale?» (sconto e scadenza, non consuma nulla)        redeem() = conferma l'uso a pagamento riuscito, una volta sola per ordine
 * Le promozioni sono dati vivi (non passano da bozza/Pubblica): quelle attive compaiono nel catalogo (`promos[]`).
 */
final class Promos {

	const KINDS = array( 'percent', 'amount' );

	/** Pulisce e controlla un codice dal modulo. @return array riga pronta per la tabella @throws UserError */
	public static function validate( array $in, array $planCodes ): array {
		$code = strtoupper( trim( (string) ( $in['code'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[A-Z0-9_-]{3,30}$/', $code ) ) {
			throw new UserError( 'Codice promo: da 3 a 30 caratteri (lettere, cifre, trattino).' );
		}
		$kind  = (string) ( $in['kind'] ?? '' );
		$value = (int) ( $in['value'] ?? 0 );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new UserError( 'Tipo di sconto non valido.' );
		}
		if ( 'percent' === $kind && ( $value < 1 || $value > 100 ) ) {
			throw new UserError( 'Lo sconto in percentuale va da 1 a 100.' );
		}
		if ( 'amount' === $kind && ( $value < 1 || $value > 1000000 ) ) {
			throw new UserError( 'Lo sconto in euro deve essere maggiore di zero.' );
		}
		$date = function ( $v ) {
			$v = trim( (string) $v );
			if ( '' !== $v && ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) || false === strtotime( $v . ' UTC' ) ) ) {
				throw new UserError( 'Data non valida: usa il formato aaaa-mm-gg.' );
			}
			return $v;
		};
		$start = $date( $in['starts_on'] ?? '' );
		$end   = $date( $in['ends_on'] ?? '' );
		if ( '' === $end ) {
			throw new UserError( 'Serve la data di fine della promo.' );
		}
		if ( '' !== $start && $start > $end ) {
			throw new UserError( 'La data di inizio è dopo quella di fine.' );
		}
		$plans = array_values( array_unique( array_map( 'strval', (array) ( $in['plans'] ?? array() ) ) ) );
		if ( array_diff( $plans, $planCodes ) ) {
			throw new UserError( 'Piano non valido: la promo vale solo per i piani del plugin.' );
		}
		$max = $in['max_uses'] ?? null;
		$max = ( null === $max || '' === $max ) ? null : (int) $max;
		if ( null !== $max && $max < 1 ) {
			throw new UserError( 'Il limite d\'uso deve essere almeno 1 (o vuoto = illimitato).' );
		}
		return array( 'code' => $code, 'kind' => $kind, 'value' => $value, 'plans' => $plans, 'starts_on' => $start, 'ends_on' => $end, 'max_uses' => $max );
	}

	public static function save( Db $db, array $row ): void {
		$plans = (string) json_encode( $row['plans'] );
		if ( self::get( $db, $row['code'] ) ) {
			$db->run( 'UPDATE plugin_promos SET kind = ?, value = ?, plans = ?, starts_on = ?, ends_on = ?, max_uses = ?, updated_at = ? WHERE code = ?', array( $row['kind'], $row['value'], $plans, $row['starts_on'], $row['ends_on'], $row['max_uses'], Db::now(), $row['code'] ) );
		} else {
			$db->run( 'INSERT INTO plugin_promos (code, kind, value, plans, starts_on, ends_on, max_uses, used, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,0,1,?,?)', array( $row['code'], $row['kind'], $row['value'], $plans, $row['starts_on'], $row['ends_on'], $row['max_uses'], Db::now(), Db::now() ) );
		}
	}

	public static function setActive( Db $db, string $code, bool $active ): void {
		$db->run( 'UPDATE plugin_promos SET active = ?, updated_at = ? WHERE code = ?', array( $active ? 1 : 0, Db::now(), strtoupper( trim( $code ) ) ) );
	}

	public static function get( Db $db, string $code ): ?array {
		return $db->one( 'SELECT * FROM plugin_promos WHERE code = ?', array( strtoupper( trim( $code ) ) ) );
	}

	public static function all( Db $db ): array {
		return $db->all( 'SELECT * FROM plugin_promos ORDER BY ends_on DESC, code' );
	}

	/** Attiva adesso (ora italiana), non esaurita? */
	public static function isLive( array $p, ?int $now = null ): bool {
		if ( empty( $p['active'] ) ) {
			return false;
		}
		if ( null !== $p['max_uses'] && (int) $p['used'] >= (int) $p['max_uses'] ) {
			return false;
		}
		$tz    = new \DateTimeZone( 'Europe/Rome' );
		$now   = $now ?? time();
		$end   = ( new \DateTime( $p['ends_on'] . ' 23:59:59', $tz ) )->getTimestamp();
		$start = '' !== (string) $p['starts_on'] ? ( new \DateTime( $p['starts_on'] . ' 00:00:00', $tz ) )->getTimestamp() : 0;
		return $now >= $start && $now <= $end;
	}

	/**
	 * «Il codice vale?» per un piano e un periodo. Non consuma nulla.
	 * @return array{valid:bool,reason:string,code:string,discount_cents:int,final_cents:int,currency:string,ends_on:string}
	 */
	public static function check( Db $db, Catalog $catalog, string $code, string $plan, string $period, ?int $now = null ): array {
		$out = array( 'valid' => false, 'reason' => '', 'code' => strtoupper( trim( $code ) ), 'discount_cents' => 0, 'final_cents' => 0, 'currency' => 'EUR', 'ends_on' => '' );
		$p   = self::get( $db, $code );
		$pr  = $catalog->price( $plan, $period );
		if ( ! $p ) {
			$out['reason'] = 'unknown_code';
		} elseif ( ! $pr ) {
			$out['reason'] = 'unknown_plan';
		} elseif ( ! self::isLive( $p, $now ) ) {
			$out['reason'] = empty( $p['active'] ) ? 'inactive' : ( null !== $p['max_uses'] && (int) $p['used'] >= (int) $p['max_uses'] ? 'exhausted' : 'out_of_dates' );
		} else {
			$plans = (array) json_decode( (string) $p['plans'], true );
			if ( $plans && ! in_array( $plan, $plans, true ) ) {
				$out['reason'] = 'plan_not_covered';
			} else {
				$cents                 = (int) $pr['cents'];
				$disc                  = 'percent' === $p['kind'] ? (int) round( $cents * (int) $p['value'] / 100 ) : (int) $p['value'];
				$disc                  = max( 0, min( $cents, $disc ) );
				$out['valid']          = true;
				$out['discount_cents'] = $disc;
				$out['final_cents']    = $cents - $disc;
				$out['currency']       = (string) $pr['currency'];
				$out['ends_on']        = (string) $p['ends_on'];
			}
		}
		return $out;
	}

	/**
	 * Conferma l'uso a pagamento riuscito: una volta sola per ordine (richiamarla con lo stesso ordine non consuma altro).
	 * @throws UserError se il codice è esaurito nel frattempo
	 */
	public static function redeem( Db $db, string $code, string $orderRef, ?int $now = null ): bool {
		$code = strtoupper( trim( $code ) );
		if ( '' === $orderRef || ! self::get( $db, $code ) ) {
			throw new UserError( 'unknown_code: codice promo sconosciuto.' );
		}
		try {
			$db->run( 'INSERT INTO plugin_promo_uses (code, order_ref, at) VALUES (?,?,?)', array( $code, $orderRef, gmdate( 'Y-m-d H:i:s', $now ?? time() ) ) );
		} catch ( \PDOException $e ) {
			return false; // già confermato per questo ordine
		}
		$n = $db->run( 'UPDATE plugin_promos SET used = used + 1 WHERE code = ? AND (max_uses IS NULL OR used < max_uses)', array( $code ) )->rowCount();
		if ( 0 === $n ) {
			$db->run( 'DELETE FROM plugin_promo_uses WHERE code = ? AND order_ref = ?', array( $code, $orderRef ) );
			throw new UserError( 'exhausted: il codice promo è esaurito.' );
		}
		return true;
	}

	/** Promozioni attive per il catalogo pubblico (senza dati interni). */
	public static function publicList( Db $db, ?int $now = null ): array {
		$out = array();
		foreach ( self::all( $db ) as $p ) {
			if ( self::isLive( $p, $now ) ) {
				$out[] = array(
					'code'      => (string) $p['code'],
					'kind'      => (string) $p['kind'],
					'value'     => (int) $p['value'],
					'plans'     => (array) json_decode( (string) $p['plans'], true ),
					'starts_on' => (string) $p['starts_on'],
					'ends_on'   => (string) $p['ends_on'],
					'remaining' => null === $p['max_uses'] ? null : max( 0, (int) $p['max_uses'] - (int) $p['used'] ),
				);
			}
		}
		return $out;
	}
}

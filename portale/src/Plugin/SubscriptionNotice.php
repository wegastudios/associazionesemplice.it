<?php
namespace Portale\Plugin;

use Portale\Billing;
use Portale\Config;
use Portale\Db;
use Portale\Site;
use Portale\UserError;

/**
 * Notifica di abbonamento dal negozio al plugin (docs/ARCHITETTURA_DOMINI.md §5): il negozio dice SOLO il fatto commerciale
 * («questa licenza ha questo piano, in questa versione, fino a questa data, per questi siti»); prezzi e limiti li ricava il plugin
 * dal proprio catalogo. Si chiama in processo dal negozio (stessa applicazione), con questo messaggio:
 *
 *   ref           id della licenza (obbligatorio)
 *   rev           numero crescente per licenza: un messaggio con rev ≤ all'ultimo applicato è ignorato («stale»)
 *   plan          codice del piano nel catalogo (es. `profiscale`)
 *   plan_version  versione del piano conosciuta dal catalogo
 *   until         scadenza `YYYY-MM-DD`
 *   sites         siti ammessi (≥ 1)
 *
 * Se il piano o la sua versione non sono nel catalogo: nessuna modifica, errore `unknown_plan`.
 */
final class SubscriptionNotice {

	const APPLIED = 'applied';
	const STALE   = 'stale';

	/**
	 * @return string `applied` o `stale`
	 * @throws UserError con codice nel messaggio: invalid, unknown_subscription, unknown_plan
	 */
	public static function apply( Db $db, Billing $billing, Catalog $catalog, array $msg ): string {
		$ref   = (string) ( $msg['ref'] ?? '' );
		$rev   = (int) ( $msg['rev'] ?? 0 );
		$plan  = (string) ( $msg['plan'] ?? '' );
		$ver   = (int) ( $msg['plan_version'] ?? 0 );
		$until = (string) ( $msg['until'] ?? '' );
		$sites = (int) ( $msg['sites'] ?? 0 );
		if ( '' === $ref || $rev < 1 || $ver < 1 || $sites < 1 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) || false === strtotime( $until . ' UTC' ) ) {
			throw new UserError( 'invalid: messaggio di abbonamento incompleto o non valido.' );
		}
		$sub = $billing->subscription( $ref );
		if ( ! $sub || empty( $sub['license_id'] ) ) {
			throw new UserError( 'unknown_subscription: licenza non trovata.' );
		}
		$known = $catalog->plan( $plan );
		if ( ! $known || $ver > (int) $known['plan_version'] ) { // le versioni passate restano valide per chi le ha già comprate
			throw new UserError( 'unknown_plan: piano o versione del piano sconosciuti, non applico nulla.' );
		}
		$code = Site::code( $plan );
		if ( null === Config::get( 'plans.' . $code ) ) {
			throw new UserError( 'unknown_plan: piano non configurato.' );
		}
		if ( $rev <= (int) ( $sub['notice_rev'] ?? 0 ) ) {
			return self::STALE;
		}
		// Prima il server licenze (se fallisce l'errore sale e il negozio ritenta); poi il database locale.
		$expires = $until . ' 23:59:59';
		$res     = $billing->wega()->patch( (string) $sub['license_id'], array(
			'plan_name'  => (string) Config::get( 'plans.' . $code . '.plan_name', $code ),
			'expires_at' => str_replace( ' ', 'T', $expires ) . 'Z',
		) );
		$iso = $res['data']['expires_at'] ?? null;
		$t   = is_string( $iso ) ? strtotime( $iso ) : false;
		$db->run(
			"UPDATE subscriptions SET plan_code = ?, plan_version = ?, sites_allowed = ?, notice_rev = ?, expires_at = ?, status = CASE WHEN status IN ('canceled','pending','merged') THEN status ELSE 'active' END, next_plan = NULL, updated_at = ? WHERE id = ?",
			array( $code, $ver, $sites, $rev, false === $t ? $expires : gmdate( 'Y-m-d H:i:s', $t ), Db::now(), $ref )
		);
		return self::APPLIED;
	}
}

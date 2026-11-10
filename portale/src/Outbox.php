<?php
namespace Portale;

/**
 * Coda dei messaggi `subscription` verso il SaaS (contratto §3.2): si accodano a ogni cambio (acquisto, rinnovo, cambio piano, disdetta,
 * pagamento non riuscito) e si ritentano dopo 1, 5, 30 minuti, poi ogni ora, finché il SaaS risponde 200, 409 `stale_revision` (già applicata)
 * o 409 `unknown_plan` (si ricarica il catalogo e si riprova). Si svuota con `php bin/saas-outbox.php` (da cron ogni 5 minuti).
 */
final class Outbox {

	const BACKOFF = array( 60, 300, 1800 );
	const REPEAT  = 3600;

	/** Accoda il messaggio; @param array $claims quelli di `subscription` (ref, rev, status, until, plan, plan_version, members, addons…) */
	public static function enqueue( Db $db, string $orgId, array $claims, ?int $now = null ): string {
		$id = Db::id();
		$at = gmdate( 'Y-m-d H:i:s', $now ?? time() );
		$db->run( 'INSERT INTO saas_outbox (id, org_id, rev, body, attempts, next_at, created_at) VALUES (?,?,?,?,0,?,?)', array( $id, $orgId, (int) ( $claims['rev'] ?? 0 ), (string) json_encode( $claims ), $at, $at ) );
		return $id;
	}

	/** Invia i messaggi in scadenza. @return array [inviati, da ritentare] */
	public static function run( Db $db, ?int $now = null ): array {
		$now  = $now ?? time();
		$sent = 0;
		$left = 0;
		$rows = $db->all( 'SELECT * FROM saas_outbox WHERE done_at IS NULL AND next_at <= ? ORDER BY rev, created_at LIMIT 50', array( gmdate( 'Y-m-d H:i:s', $now ) ) );
		foreach ( $rows as $r ) {
			$claims = (array) json_decode( (string) $r['body'], true );
			try {
				$res = SaasClient::subscription( (string) $r['org_id'], $claims, $now );
			} catch ( UserError $e ) {
				$res = array( 0, array( 'error' => 'not_configured' ) ); // manca la chiave di firma: il messaggio resta in coda
			}
			$err    = (string) ( $res[1]['error'] ?? '' );
			if ( 200 === $res[0] || ( 409 === $res[0] && 'stale_revision' === $err ) ) {
				$db->run( 'UPDATE saas_outbox SET done_at = ?, last_error = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $now ), 200 === $res[0] ? null : 'stale_revision', $r['id'] ) );
				++$sent;
				continue;
			}
			if ( 409 === $res[0] && 'unknown_plan' === $err ) {
				Catalog::refresh( $db, $now );
			}
			$n    = (int) $r['attempts'];
			$wait = self::BACKOFF[ $n ] ?? self::REPEAT;
			$db->run( 'UPDATE saas_outbox SET attempts = attempts + 1, next_at = ?, last_error = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $now + $wait ), '' !== $err ? $err : 'http ' . $res[0], $r['id'] ) );
			++$left;
		}
		return array( $sent, $left );
	}
}

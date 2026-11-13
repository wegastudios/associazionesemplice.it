<?php
namespace Portale;

/**
 * Il gestionale nell'area clienti: elenco degli abbonamenti SaaS del cliente (`orders` pagati + `saas_orgs`), disdetta, portale di
 * fatturazione e accesso dal gestionale (contratto §4.3: token `sso`, `jti` monouso, destinazione fissa).
 */
final class SaasAccount {

	/** Abbonamenti al gestionale del cliente, dal più recente. Le richieste mai pagate non compaiono. */
	public static function forCustomer( Db $db, string $customerId ): array {
		$rows = $db->all(
			"SELECT o.*, g.org_id, g.slug, g.name AS org_name FROM orders o JOIN saas_orgs g ON g.ref = o.org_ref
			 WHERE o.customer_id = ? AND o.product = 'saas' AND o.status IN ('paid','active') ORDER BY o.created_at DESC",
			array( $customerId )
		);
		foreach ( $rows as &$r ) {
			$q          = (array) json_decode( (string) $r['quote'], true );
			$last       = $r['org_id'] ? (array) json_decode( (string) ( $db->one( 'SELECT body FROM saas_outbox WHERE org_id = ? ORDER BY rev DESC LIMIT 1', array( $r['org_id'] ) )['body'] ?? '[]' ), true ) : array();
			$r['state'] = 'paid' === $r['status'] ? 'pending' : (string) ( $last['status'] ?? 'active' );
			$r['until'] = (string) ( $last['until'] ?? '' );
			$r['total'] = (int) ( $q['total'] ?? 0 );
			// Regime dell'ordine al momento dell'acquisto (gli ordini di prima non lo hanno: IVA inclusa come sempre).
			$r['vat_mode'] = 'exempt' === ( $q['vat_mode'] ?? '' ) ? 'exempt' : 'standard';
			$r['vat_note'] = (string) ( $q['vat_note'] ?? '' );
			// «Apri il gestionale» è sempre su app. (operatori e commercialisti); il sottodominio dell'associazione è il suo sito pubblico e l'area soci.
			$r['url']      = $r['slug'] ? rtrim( (string) Config::get( 'saas_app_url', 'https://app.associazionesemplice.it/gestionale' ), '/' ) : '';
			$r['site_url'] = $r['slug'] ? 'https://' . $r['slug'] . '.' . Config::get( 'saas_tenant_domain', 'associazionesemplice.it' ) : '';
		}
		unset( $r );
		return $rows;
	}

	public static function stateLabel( string $state ): array {
		return array(
			'pending'   => array( 'In attivazione', 'warn' ), 'active' => array( 'Attivo', 'ok' ), 'trial' => array( 'In prova', 'ok' ),
			'past_due'  => array( 'Pagamento non riuscito', 'warn' ), 'suspended' => array( 'Sospeso', 'warn' ), 'cancelled' => array( 'Annullato', 'warn' ),
		)[ $state ] ?? array( $state, '' );
	}

	/** Pagina «Gestisci» con stato, dominio e controllo del cambio piano: spenta finché il SaaS non ha in produzione gli endpoint v2 (`saas_v2.enabled`). */
	public static function manageEnabled(): bool {
		return true === Config::get( 'saas_v2.enabled', false ) && SaasClient::enabled();
	}

	/** Dominio personalizzato offerto ai clienti: spento (`saas_v2.domain`) finché non è deciso come si emettono i certificati sull'hosting. */
	public static function domainEnabled(): bool {
		return self::manageEnabled() && true === Config::get( 'saas_v2.domain', false );
	}

	/** Associazione del cliente per la pagina «Gestisci»: ordine attivo con associazione già creata. */
	public static function org( Db $db, string $customerId, string $orderId ): ?array {
		$o = $db->one(
			"SELECT o.*, g.org_id, g.name AS org_name FROM orders o JOIN saas_orgs g ON g.ref = o.org_ref
			 WHERE o.id = ? AND o.customer_id = ? AND o.product = 'saas' AND o.status IN ('paid','active') AND g.org_id IS NOT NULL",
			array( $orderId, $customerId )
		);
		return $o ?: null;
	}

	/** Dominio scritto dal cliente → forma normalizzata (minuscolo, senza schema né percorso) o null. */
	public static function cleanDomain( string $d ): ?string {
		$d = strtolower( trim( $d ) );
		$d = preg_replace( '#^https?://#', '', $d );
		$d = rtrim( (string) preg_replace( '#[/?].*$#', '', $d ), '.' );
		return 1 === preg_match( '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d ) ? $d : null;
	}

	/** Parole italiane per le ragioni di rifiuto del dominio. */
	public static function domainReason( string $reason ): string {
		return array(
			'invalid' => 'L\'indirizzo non sembra valido: scrivilo come www.tuaassociazione.it.', 'taken' => 'Questo indirizzo è già usato da un\'altra associazione.',
			'reserved' => 'Questo indirizzo non si può usare.', 'no_package' => 'Il tuo abbonamento non comprende il dominio personalizzato.',
		)[ $reason ] ?? 'Questo indirizzo non si può usare.';
	}

	/** Ordine del cliente (per i pulsanti dell'area clienti). */
	public static function mine( Db $db, string $customerId, string $orderId ): ?array {
		$o = $db->one( "SELECT * FROM orders WHERE id = ? AND customer_id = ? AND product = 'saas' AND status = 'active'", array( $orderId, $customerId ) );
		return $o ?: null;
	}

	/** Disdetta a fine periodo: il gestionale resta attivo fino alla scadenza già pagata. */
	public static function cancel( Db $db, array $order ): void {
		if ( 'stripe' === $order['provider'] && ! empty( $order['provider_ref'] ) ) {
			Stripe::cancelAtPeriodEnd( (string) $order['provider_ref'] );
		} elseif ( 'paypal' === $order['provider'] && ! empty( $order['provider_ref'] ) ) {
			PayPal::cancel( (string) $order['provider_ref'] );
		} else {
			throw new UserError( 'Questo abbonamento non si può disdire da qui: scrivici.' );
		}
		$db->run( 'UPDATE orders SET cancel_at_period_end = 1, updated_at = ? WHERE id = ?', array( Db::now(), $order['id'] ) );
		$org = $db->one( 'SELECT * FROM saas_orgs WHERE ref = ?', array( $order['org_ref'] ?? '' ) );
		if ( $org ) {
			SaasCheckout::mail( $db, 'saas_cancelled', $order, $org );
		}
	}

	/**
	 * Accesso dal gestionale («Gestisci abbonamento»): firma, tipo, emittente e destinatario come ogni messaggio del SaaS, `jti` usato una volta,
	 * `ref` = un suo abbonamento e `sub` = l'intestatario. @return array|null il cliente, o null (allora si passa dall'accesso normale, senza dire perché)
	 */
	public static function ssoCustomer( Db $db, string $token, ?int $now = null ): ?array {
		$now = $now ?? time();
		$c   = Catalog::verifyToken( $token, 'sso', $now );
		if ( ! $c || $c['exp'] - $c['iat'] > 120 || ! is_string( $c['sub'] ?? null ) || ! is_string( $c['ref'] ?? null ) ) {
			return null;
		}
		$db->run( 'DELETE FROM seen_jti WHERE exp < ?', array( $now - 3600 ) );
		try {
			$db->run( 'INSERT INTO seen_jti (jti, exp) VALUES (?,?)', array( 'sso:' . substr( $c['jti'], 0, 58 ), (int) $c['exp'] ) );
		} catch ( \PDOException $e ) {
			return null;
		}
		$row = $db->one( 'SELECT c.* FROM saas_orgs g JOIN customers c ON c.id = g.customer_id WHERE g.ref = ? AND c.email = ?', array( $c['ref'], strtolower( trim( $c['sub'] ) ) ) );
		return $row ?: null;
	}
}

<?php
namespace Portale;

/**
 * Acquisto del gestionale (SaaS): dal modulo al pagamento, e dal pagamento riuscito al SaaS.
 *
 *  1. {@see start()}  — ricalcola il prezzo dal catalogo corrente, controlla l'eventuale codice promo (`promo.check`), apre l'ordine
 *     (prezzo e `plan_version` fissati) e manda il cliente a pagare su Stripe o PayPal (abbonamento con rinnovo automatico).
 *  2. {@see paid()}   — al pagamento riuscito (webhook): crea l'associazione (`provision`, ripetibile), consuma la promo (`promo.redeem`,
 *     una volta per ordine) e mette in coda `subscription` per il SaaS. Ripetibile senza effetti doppi.
 *  3. {@see event()}  — rinnovo, pagamento non riuscito, disdetta: nuovo `subscription` con `rev` crescente.
 */
final class SaasCheckout {

	const ACTIVE = 'active';

	/** Sottodominio proposto dal nome dell'associazione: minuscole, cifre e trattini, 3–40 caratteri. */
	public static function slugFrom( string $name ): string {
		$s = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name ) ) );
		$s = trim( substr( trim( $s, '-' ), 0, 40 ), '-' );
		return $s;
	}

	public static function validSlug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug ) && false === strpos( $slug, '--' );
	}

	/** Metodi di pagamento attivi per il gestionale: sempre con rinnovo automatico. @return string[] stripe|paypal */
	public static function providers(): array {
		$out = array();
		if ( '' !== (string) Config::get( 'stripe.secret_key', '' ) ) {
			$out[] = 'stripe';
		}
		if ( '' !== (string) Config::get( 'paypal.client_id', '' ) && '' !== (string) Config::get( 'paypal.secret', '' ) ) {
			$out[] = 'paypal';
		}
		return $out;
	}

	/**
	 * Codice promo: «vale?» al SaaS (non consuma nulla).
	 * @return array|null null se il codice è vuoto; altrimenti ['code', 'discount']
	 * @throws UserError se non vale
	 */
	public static function promo( string $code, string $plan, int $planVersion, string $period ): ?array {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}
		list( $status, $res ) = SaasClient::promoCheck( $code, $plan, $planVersion, $period );
		if ( 200 !== $status ) {
			throw new UserError( 'Non riusciamo a controllare il codice in questo momento: riprova tra poco o procedi senza.' );
		}
		if ( empty( $res['valid'] ) || empty( $res['discount']['type'] ) ) {
			$why = array(
				'expired' => 'Questo codice è scaduto.', 'wrong_plan' => 'Questo codice non vale per il piano scelto.',
				'exhausted' => 'Questo codice è stato usato troppe volte.',
			);
			throw new UserError( $why[ (string) ( $res['reason'] ?? '' ) ] ?? 'Codice non valido.' );
		}
		return array( 'code' => $code, 'discount' => array( 'type' => (string) $res['discount']['type'], 'value' => (int) ( $res['discount']['value'] ?? 0 ) ) );
	}

	/**
	 * Apre l'ordine e restituisce l'indirizzo del pagamento.
	 *
	 * @param array $in email, name, org_name, slug, plan, period, members, addons [code => qty], promo, provider, seen_total (centesimi, quello mostrato al cliente)
	 * @throws UserError
	 */
	public static function start( Db $db, Billing $billing, array $in, ?int $now = null ): string {
		$provider = (string) ( $in['provider'] ?? '' );
		if ( ! in_array( $provider, self::providers(), true ) ) {
			throw new UserError( 'Questo metodo di pagamento non è disponibile.' );
		}
		// «Scegli un piano» alla fine della prova: stessa associazione, nome, indirizzo e email sono quelli della prova (chi chiama ha già controllato che l'ordine è del cliente).
		$trialOrder = null;
		if ( '' !== (string) ( $in['convert'] ?? '' ) ) {
			$trialOrder = Orders::get( $db, (string) $in['convert'] );
			$tg         = $trialOrder ? $db->one( 'SELECT g.name, g.slug, c.email FROM saas_orgs g JOIN customers c ON c.id = g.customer_id WHERE g.ref = ?', array( $trialOrder['org_ref'] ) ) : null;
			if ( ! $trialOrder || 'trial' !== $trialOrder['status'] || ! $tg ) {
				throw new UserError( 'Questa prova non è più attiva: scegli un piano da capo.' );
			}
			$in['org_name'] = $tg['name'];
			$in['slug']     = $tg['slug'];
			$in['email']    = $tg['email'];
		}
		$orgName = Billing::cleanName( (string) ( $in['org_name'] ?? '' ) );
		$slug    = strtolower( trim( (string) ( $in['slug'] ?? '' ) ) );
		if ( mb_strlen( $orgName ) < 2 ) {
			throw new UserError( 'Scrivi il nome dell\'associazione.' );
		}
		if ( ! self::validSlug( $slug ) ) {
			throw new UserError( 'L\'indirizzo scelto non è valido: usa da 3 a 40 tra lettere minuscole, cifre e trattini.' );
		}
		$cust    = $billing->customer( (string) ( $in['email'] ?? '' ) );
		$plan    = (string) ( $in['plan'] ?? '' );
		$period  = (string) ( $in['period'] ?? '' );
		$members = (int) ( $in['members'] ?? 0 );
		$addons  = self::cleanAddons( (array) ( $in['addons'] ?? array() ) );

		$cat   = Orders::catalog( $db, $now );
		$base  = Quote::build( $cat['doc'], $plan, $period, $members, $addons );
		$promo = self::promo( (string) ( $in['promo'] ?? '' ), $plan, $base['plan_version'], $period );
		$order = Orders::open( $db, $cust['id'], $plan, $period, $members, $addons, $promo, $now );
		$seen  = isset( $in['seen_total'] ) && '' !== $in['seen_total'] ? (int) $in['seen_total'] : null;
		if ( null !== $seen && $seen !== $order['total'] ) {
			$db->run( 'DELETE FROM orders WHERE id = ?', array( $order['id'] ) );
			throw new UserError( 'I prezzi sono stati aggiornati: il nuovo totale è ' . money( $order['total'] ) . ( 'exempt' === Catalog::terms( $cat['doc'] )['vat_mode'] ? ' (senza IVA)' : ' (IVA inclusa)' ) . '. Controlla e conferma di nuovo.' );
		}
		if ( $order['total'] < 100 ) {
			$db->run( 'DELETE FROM orders WHERE id = ?', array( $order['id'] ) );
			throw new UserError( 'Con questo codice il totale è troppo basso per il pagamento online: scrivici e lo sistemiamo.' );
		}
		$ref = $trialOrder ? (string) $trialOrder['org_ref'] : 'o' . $order['id'];
		if ( ! $trialOrder ) {
			$db->run( 'INSERT INTO saas_orgs (ref, customer_id, name, slug, rev, created_at) VALUES (?,?,?,?,0,?)', array( $ref, $cust['id'], $orgName, $slug, gmdate( 'Y-m-d H:i:s', $now ?? time() ) ) );
		}
		$db->run( 'UPDATE orders SET org_ref = ?, provider = ? WHERE id = ?', array( $ref, $provider, $order['id'] ) );
		if ( '' !== trim( (string) ( $in['name'] ?? '' ) ) ) {
			$billing->setName( $cust['id'], (string) $in['name'] );
		}

		$planName = $plan;
		foreach ( Catalog::plans( $cat['doc'] ) as $p ) {
			if ( ( $p['code'] ?? '' ) === $plan ) {
				$planName = (string) ( $p['name'] ?? $plan );
			}
		}
		$label = 'Associazione Semplice · ' . $planName . ( 'year' === $period ? ' · annuale' : ' · mensile' );
		if ( 'stripe' === $provider ) {
			// Regime forfettario: la dicitura di legge sulla ricevuta (nome del prodotto su Stripe, al massimo 250 caratteri).
			$note = (string) ( Catalog::terms( $cat['doc'] )['vat_note'] ?? '' );
			return Stripe::checkoutUrl( $order['id'], $cust['email'], '', $order['total'], $period, mb_substr( '' !== $note ? $label . ' · ' . $note : $label, 0, 250 ) );
		}
		return PayPal::approvalUrl( $order['id'], $cust['email'], $billing->paypalPlan( array( 'cents' => $order['total'] ), $period, $label ) );
	}

	/** La prova senza carta si offre solo quando è acceso `saas_trial` in config (dopo che il SaaS la gestisce in produzione). */
	public static function trialEnabled(): bool {
		return true === Config::get( 'saas_trial', false );
	}

	/** Giorni di prova del piano nel catalogo (`trial_days`): 0 = quel piano non ha la prova. */
	public static function trialDays( array $doc, string $plan ): int {
		foreach ( Catalog::plans( $doc ) as $p ) {
			if ( ( $p['code'] ?? '' ) === $plan ) {
				return max( 0, min( 90, (int) ( $p['trial_days'] ?? 0 ) ) );
			}
		}
		return 0;
	}

	/**
	 * Avvia la prova gratuita del gestionale, senza metodo di pagamento (contratto: l'abbonamento nasce `trial` con `trial_ends`).
	 * Una prova sola per cliente. Se l'associazione non si può creare adesso non resta nulla in sospeso.
	 * @param array $in email, name, org_name, slug, plan, period, members, addons
	 * @return string email del cliente (per la pagina «Grazie»)
	 * @throws UserError
	 */
	public static function startTrial( Db $db, Billing $billing, array $in, ?int $now = null ): string {
		if ( ! self::trialEnabled() ) {
			throw new UserError( 'La prova gratuita non è ancora disponibile.' );
		}
		$orgName = Billing::cleanName( (string) ( $in['org_name'] ?? '' ) );
		$slug    = strtolower( trim( (string) ( $in['slug'] ?? '' ) ) );
		if ( mb_strlen( $orgName ) < 2 ) {
			throw new UserError( 'Scrivi il nome dell\'associazione.' );
		}
		if ( ! self::validSlug( $slug ) ) {
			throw new UserError( 'L\'indirizzo scelto non è valido: usa da 3 a 40 tra lettere minuscole, cifre e trattini.' );
		}
		$plan   = (string) ( $in['plan'] ?? '' );
		$period = 'year' === ( $in['period'] ?? '' ) ? 'year' : 'month';
		$cat    = Orders::catalog( $db, $now );
		$days   = self::trialDays( $cat['doc'], $plan );
		if ( $days < 1 ) {
			throw new UserError( 'Questo piano non ha la prova gratuita.' );
		}
		$cust = $billing->customer( (string) ( $in['email'] ?? '' ) );
		if ( (int) $db->one( "SELECT COUNT(*) n FROM orders WHERE customer_id = ? AND product = 'saas' AND trial_ends IS NOT NULL", array( $cust['id'] ) )['n'] > 0 ) {
			throw new UserError( 'Con questa email hai già fatto la prova: scegli un piano da /saas/acquista oppure scrivici.' );
		}
		$order = Orders::open( $db, $cust['id'], $plan, $period, max( 1, (int) ( $in['members'] ?? 50 ) ), self::cleanAddons( (array) ( $in['addons'] ?? array() ) ), null, $now );
		$end   = gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', $now ?? time() ) );
		$ref   = 'o' . $order['id'];
		$db->run( 'INSERT INTO saas_orgs (ref, customer_id, name, slug, rev, created_at) VALUES (?,?,?,?,0,?)', array( $ref, $cust['id'], $orgName, $slug, gmdate( 'Y-m-d H:i:s', $now ?? time() ) ) );
		$db->run( "UPDATE orders SET org_ref = ?, status = 'trial', trial_ends = ?, updated_at = ? WHERE id = ?", array( $ref, $end, gmdate( 'Y-m-d H:i:s', $now ?? time() ), $order['id'] ) );
		if ( '' !== trim( (string) ( $in['name'] ?? '' ) ) ) {
			$billing->setName( $cust['id'], (string) $in['name'] );
		}
		try {
			$org = self::provision( $db, (array) $db->one( 'SELECT * FROM saas_orgs WHERE ref = ?', array( $ref ) ), $now );
		} catch ( \RuntimeException $e ) {
			$db->run( 'DELETE FROM orders WHERE id = ?', array( $order['id'] ) );
			$db->run( 'DELETE FROM saas_orgs WHERE ref = ?', array( $ref ) );
			error_log( '[portale] prova non avviata: ' . $e->getMessage() );
			throw new UserError( 'Non riusciamo a creare il gestionale in questo momento: riprova tra poco.' );
		}
		$o = (array) Orders::get( $db, (string) $order['id'] );
		self::push( $db, $o, $org, 'trial', $end, $now );
		self::mail( $db, 'saas_trial_started', $o, $org, $now, array( 'giorni' => (string) $days, 'fine_prova' => date_it( $end . ' 00:00:00' ), 'piani_url' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/account#gestionale' ) );
		self::mail( $db, 'saas_ready', $o, $org, $now );
		return (string) $cust['email'];
	}

	/**
	 * Pagamento riuscito. @return bool falso se l'identificativo non è un ordine del gestionale (allora è affare delle licenze del plugin)
	 * @throws \RuntimeException con errore da ritentare (il provider riconsegna il webhook)
	 */
	public static function paid( Db $db, string $orderId, ?string $providerRef = null, ?string $providerCustomer = null, ?int $now = null ): bool {
		$o = Orders::get( $db, $orderId );
		if ( ! $o || 'saas' !== $o['product'] ) {
			return false;
		}
		$at = gmdate( 'Y-m-d H:i:s', $now ?? time() );
		$db->run(
			"UPDATE orders SET status = 'paid', provider_ref = COALESCE(?, provider_ref), provider_customer = COALESCE(?, provider_customer), paid_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
			array( $providerRef, $providerCustomer, $at, $at, $orderId )
		);
		$o = (array) Orders::get( $db, $orderId );
		if ( 'paid' !== $o['status'] ) {
			return true; // già attivato: consegna ripetuta
		}
		$org = $db->one( 'SELECT * FROM saas_orgs WHERE ref = ?', array( $o['org_ref'] ) );
		if ( ! $org ) {
			throw new \RuntimeException( 'Ordine senza associazione: ' . $orderId );
		}
		// Associazione già creata da un ordine precedente: è una «Riattiva» (stessa associazione, nuovo pagamento), non un primo acquisto.
		$again = ! empty( $org['org_id'] );
		if ( ! $again ) {
			$org = self::provision( $db, $org, $now );
		}
		$promo = (string) ( $o['quote']['promo'] ?? '' );
		if ( '' !== $promo ) {
			list( $code ) = SaasClient::promoRedeem( $promo, $o['id'], (string) $org['ref'], $now );
			if ( 0 === $code || $code >= 500 ) {
				throw new \RuntimeException( 'promo.redeem non riuscita (' . $code . '): da riprovare.' );
			}
			// Esaurita nel frattempo (409): il cliente ha già pagato lo sconto che gli avevamo promesso, non si blocca nulla.
		}
		self::push( $db, $o, $org, 'active', self::until( (string) $o['period'], $now ), $now );
		$db->run( "UPDATE orders SET status = ?, updated_at = ? WHERE id = ? AND status = 'paid'", array( self::ACTIVE, $at, $orderId ) );
		// Una sola volta (il ramo «già attivato» sopra esce prima): un errore di posta non deve far ripetere il pagamento.
		if ( $again ) {
			// Dopo una prova il primo piano non è un «rinnovo»: la ricevuta la manda il provider, qui nessuna email in più.
			$wasTrial = (int) $db->one( "SELECT COUNT(*) n FROM orders WHERE org_ref = ? AND id <> ? AND product = 'saas' AND status = 'trial'", array( $o['org_ref'], $orderId ) )['n'] > 0;
			// L'ordine vecchio non conta più (l'elenco dell'area clienti mostra l'associazione una volta sola).
			$db->run( "UPDATE orders SET status = 'replaced', updated_at = ? WHERE org_ref = ? AND id <> ? AND product = 'saas'", array( $at, $o['org_ref'], $orderId ) );
			if ( ! $wasTrial ) {
				self::mail( $db, 'saas_renewed', $o, $org, $now );
			}
			return true;
		}
		self::mail( $db, 'saas_welcome', $o, $org, $now );
		self::mail( $db, 'saas_ready', $o, $org, $now );
		return true;
	}

	/**
	 * «Riattiva» dall'area clienti. Con un pagamento non riuscito su Stripe si paga la fattura aperta con il metodo già salvato (il webhook
	 * riporta il gestionale attivo); altrimenti si apre un nuovo ordine per la stessa associazione, allo stesso piano ai prezzi di oggi.
	 * @param int|null $seen centesimi mostrati al cliente: se il totale è cambiato non si addebita nulla
	 * @return string|null indirizzo del pagamento da aprire, o null se il pagamento è già stato fatto
	 * @throws UserError
	 */
	public static function reactivate( Db $db, Billing $billing, array $order, ?int $seen = null, ?int $now = null ): ?string {
		$org = $db->one( 'SELECT * FROM saas_orgs WHERE ref = ?', array( $order['org_ref'] ?? '' ) );
		if ( ! $org || empty( $org['org_id'] ) || 'active' !== $order['status'] ) {
			throw new UserError( 'Abbonamento non trovato.' );
		}
		if ( 'stripe' === $order['provider'] && ! empty( $order['provider_ref'] ) && 'past_due' === ( SaasAccount::stateOf( $db, (string) $org['org_id'] ) ) ) {
			$total = (int) ( json_decode( (string) $order['quote'], true )['total'] ?? 0 );
			if ( null !== $seen && $seen !== $total ) {
				throw new UserError( 'L\'importo è cambiato: controlla e conferma di nuovo.' );
			}
			$res = Stripe::payOpenInvoice( (string) $order['provider_ref'] );
			if ( 'paid' === $res ) {
				return null;
			}
			if ( in_array( $res, array( 'declined', 'hard' ), true ) ) {
				throw new UserError( 'Il pagamento non è andato a buon fine. Controlla o cambia il metodo di pagamento da «Fatture e metodo di pagamento» e riprova.' );
			}
			// «unavailable»: l'abbonamento su Stripe è già chiuso, si riparte con un nuovo ordine.
		}
		$provider = (string) $order['provider'];
		if ( ! in_array( $provider, self::providers(), true ) ) {
			throw new UserError( 'Questo metodo di pagamento non è disponibile.' );
		}
		$cat = Orders::catalog( $db, $now );
		$q   = (array) json_decode( (string) $order['quote'], true );
		$new = Quote::build( $cat['doc'], (string) $order['plan'], (string) $order['period'], (int) $order['members'], Orders::addonsOf( $q ) );
		if ( null !== $seen && $seen !== $new['total'] ) {
			throw new UserError( 'I prezzi sono stati aggiornati: il nuovo totale è ' . money( $new['total'] ) . '. Controlla e conferma di nuovo.' );
		}
		if ( $new['total'] < 100 ) {
			throw new UserError( 'Non riusciamo a riattivare da qui: scrivici e lo sistemiamo.' );
		}
		$ord = Orders::open( $db, (string) $order['customer_id'], (string) $order['plan'], (string) $order['period'], (int) $order['members'], Orders::addonsOf( $q ), null, $now );
		$db->run( 'UPDATE orders SET org_ref = ?, provider = ? WHERE id = ?', array( $order['org_ref'], $provider, $ord['id'] ) );
		$cust     = $db->one( 'SELECT email FROM customers WHERE id = ?', array( $order['customer_id'] ) );
		$planName = (string) $order['plan'];
		foreach ( Catalog::plans( $cat['doc'] ) as $p ) {
			if ( ( $p['code'] ?? '' ) === $order['plan'] ) {
				$planName = (string) ( $p['name'] ?? $planName );
			}
		}
		$label = 'Associazione Semplice · ' . $planName . ( 'year' === $order['period'] ? ' · annuale' : ' · mensile' );
		if ( 'stripe' === $provider ) {
			$note = (string) ( Catalog::terms( $cat['doc'] )['vat_note'] ?? '' );
			return Stripe::checkoutUrl( $ord['id'], (string) $cust['email'], '', $ord['total'], (string) $order['period'], mb_substr( '' !== $note ? $label . ' · ' . $note : $label, 0, 250 ) );
		}
		return PayPal::approvalUrl( $ord['id'], (string) $cust['email'], $billing->paypalPlan( array( 'cents' => $ord['total'] ), (string) $order['period'], $label ) );
	}

	/**
	 * Email del gestionale al cliente dell'ordine (rispetta i modelli e l'interruttore del pannello). Mai un'eccezione: la posta non blocca i pagamenti.
	 * Il link per scegliere la password porta alla pagina «Scegli o recupera la password» (la email con il link la manda il sistema di accesso).
	 */
	public static function mail( Db $db, string $event, array $order, array $org, ?int $now = null, array $extra = array() ): bool {
		try {
			$cust = $db->one( 'SELECT email, name FROM customers WHERE id = ?', array( $order['customer_id'] ) );
			if ( ! $cust ) {
				return false;
			}
			$q    = (array) ( is_array( $order['quote'] ?? null ) ? $order['quote'] : json_decode( (string) ( $order['quote'] ?? '' ), true ) );
			$stored = Catalog::stored( $db, $now );
			$plan = (string) $order['plan'];
			foreach ( $stored ? Catalog::plans( $stored['doc'] ) : array() as $p ) {
				if ( ( $p['code'] ?? '' ) === $plan ) {
					$plan = (string) ( $p['name'] ?? $plan );
				}
			}
			$last  = self::lastUntil( $db, (string) ( $org['org_id'] ?? '' ) );
			$until = $last ?? self::until( (string) $order['period'], $now );
			$name  = trim( (string) ( $cust['name'] ?? '' ) );
			$total = (int) ( $q['total'] ?? 0 );
			$vars  = array(
				'nome' => $name,'associazione' => (string) ( $org['name'] ?? '' ), 'piano' => $plan,
				'periodo' => 'year' === $order['period'] ? 'annuale' : 'mensile', 'scadenza' => date_it( $until . ' 00:00:00' ),
				// Sospeso il giorno dopo la scadenza; dati conservati 180 giorni dalla sospensione (decisione della titolare, 13/11/2026).
				'data_cancellazione' => date_it( gmdate( 'Y-m-d', strtotime( $until . ' UTC +181 days' ) ) . ' 00:00:00' ),
				'importo' => money( $total ) . ( 'exempt' === ( $q['vat_mode'] ?? '' ) ? ' (senza IVA)' : ' (IVA inclusa)' ),
				'gestionale_url' => rtrim( (string) Config::get( 'saas_app_url', 'https://app.associazionesemplice.it/gestionale' ), '/' ),
				'link_password' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/accedi/password-dimenticata',
			);
			return Emails::send( $db, $event, (string) $cust['email'], $extra + $vars );
		} catch ( \Throwable $e ) {
			error_log( '[portale] email ' . $event . ' non inviata (' . $e->getMessage() . ')' );
			return false;
		}
	}

	/**
	 * Lavoro giornaliero (bin/cron.php): avviso «scade e non si rinnova» entro i giorni di preavviso delle impostazioni (solo per le disdette)
	 * e avviso «sospeso» dal giorno dopo la scadenza. Una email per scadenza (colonne reminded_for / suspended_for). @return int email inviate
	 */
	public static function sendLifecycleMails( Db $db, ?int $now = null ): int {
		$now   = $now ?? time();
		$today = gmdate( 'Y-m-d', $now );
		$days  = Settings::limit( 'reminder_days' );
		$sent  = 0;
		$rows  = $db->all( "SELECT o.*, g.org_id, g.name AS org_name FROM orders o JOIN saas_orgs g ON g.ref = o.org_ref WHERE o.product = 'saas' AND o.status = ? AND g.org_id IS NOT NULL", array( self::ACTIVE ) );
		foreach ( $rows as $o ) {
			$until = self::lastUntil( $db, (string) $o['org_id'] );
			if ( null === $until ) {
				continue;
			}
			$org = array( 'org_id' => $o['org_id'], 'name' => $o['org_name'] );
			if ( $until < $today ) {
				if ( $until < gmdate( 'Y-m-d', $now - 3 * 86400 ) ) {
					continue; // scaduto da tempo (dati di prova o già gestito a mano): niente email a sorpresa
				}
				if ( $o['suspended_for'] !== $until ) {
					$db->run( 'UPDATE orders SET suspended_for = ? WHERE id = ?', array( $until, $o['id'] ) );
					$sent += self::mail( $db, 'saas_suspended', $o, $org, $now ) ? 1 : 0;
				}
			} elseif ( $days > 0 && (int) $o['cancel_at_period_end'] && $until <= gmdate( 'Y-m-d', $now + $days * 86400 ) && $o['reminded_for'] !== $until ) {
				$db->run( 'UPDATE orders SET reminded_for = ? WHERE id = ?', array( $until, $o['id'] ) );
				$sent += self::mail( $db, 'saas_expiring', $o, $org, $now ) ? 1 : 0;
			}
		}
		return $sent;
	}

	/** Giorni (rispetto alla fine della prova) in cui parte ciascuna email P1…P6; P6 a `purge_days` dalla fine. */
	private static function trialDue( string $trialEnds, int $purge ): array {
		$d = function ( int $days ) use ( $trialEnds ) {
			return gmdate( 'Y-m-d', strtotime( $trialEnds . ' UTC ' . ( $days >= 0 ? '+' : '' ) . $days . ' days' ) );
		};
		return array( 1 => $d( -7 ), 2 => $d( -2 ), 3 => $d( 0 ), 4 => $d( 3 ), 5 => $d( $purge - 1 ), 6 => $d( $purge ) );
	}

	/**
	 * Lavoro giornaliero (bin/cron.php): la sequenza di email della prova che finisce senza un piano (P1 sette giorni prima… P6 a cancellazione
	 * fatta), e la chiusura dell'ordine alla cancellazione dei dati. Dopo la fine NON si manda «suspended» (per il SaaS sarebbe un abbonamento scaduto, con 180 giorni):
	 * resta «trial» con `until` = fine prova, e la sospensione (sola lettura) e la cancellazione dopo `purge_days` le fa il SaaS da sé.
	 * Ogni email parte una volta sola e solo nel giorno previsto (o il giorno dopo, se il lavoro è saltato): niente arretrati. Lo sconto compare solo
	 * se è impostato nel catalogo (`terms.trial.end_discount_percent`) e c'è il codice da usare (`saas_trial_promo` in config).
	 * @return int email inviate
	 */
	public static function sendTrialMails( Db $db, ?int $now = null ): int {
		$now   = $now ?? time();
		$today = gmdate( 'Y-m-d', $now );
		$stored = Catalog::stored( $db, $now );
		$terms = Catalog::terms( $stored ? $stored['doc'] : array() );
		$purge = $terms['trial_purge_days'];
		$pct   = $terms['trial_end_discount_percent'];
		$code  = trim( (string) Config::get( 'saas_trial_promo', '' ) );
		$sent  = 0;
		$rows  = $db->all( "SELECT o.*, g.org_id, g.name AS org_name FROM orders o JOIN saas_orgs g ON g.ref = o.org_ref WHERE o.product = 'saas' AND o.status = 'trial' AND o.trial_ends IS NOT NULL AND g.org_id IS NOT NULL" );
		foreach ( $rows as $o ) {
			$org  = array( 'org_id' => $o['org_id'], 'name' => $o['org_name'] );
			$end  = (string) $o['trial_ends'];
			$del  = gmdate( 'Y-m-d', strtotime( $end . ' UTC +' . $purge . ' days' ) );
			$base = rtrim( (string) Config::get( 'base_url' ), '/' );
			$vars = array(
				'fine_prova' => date_it( $end . ' 00:00:00' ), 'data_cancellazione_prova' => date_it( $del . ' 00:00:00' ), 'piani_url' => $base . '/account#gestionale',
				'sconto' => $pct . '%', 'codice_sconto' => $code, 'scadenza_sconto' => date_it( $del . ' 00:00:00' ),
			);
			$offer = $pct > 0 && '' !== $code ? array(
				3 => 'Se scegli un piano entro il {scadenza_sconto} hai uno sconto del {sconto} sul primo pagamento: usa il codice {codice_sconto} al momento dell\'acquisto.',
				4 => 'Lo sconto del {sconto} con il codice {codice_sconto} vale fino a quel giorno.',
				5 => 'Il codice {codice_sconto} ({sconto} di sconto) vale fino a domani.',
			) : array();
			foreach ( self::trialDue( $end, $purge ) as $step => $due ) {
				if ( $step <= (int) $o['trial_step'] || $today < $due ) {
					continue;
				}
				$db->run( 'UPDATE orders SET trial_step = ? WHERE id = ?', array( $step, $o['id'] ) );
				if ( $today > gmdate( 'Y-m-d', strtotime( $due . ' UTC +1 day' ) ) ) {
					continue; // arretrato: il lavoro non è girato in quel giorno, l'avviso non ha più senso
				}
				$vars['offerta'] = isset( $offer[ $step ] ) ? Emails::render( $offer[ $step ], $vars ) : '';
				$sent += self::mail( $db, 'saas_trial_p' . $step, $o, $org, $now, $vars ) ? 1 : 0;
			}
			// Cancellazione dei dati (la fa il SaaS): l'ordine di prova si chiude qui.
			if ( $today >= $del ) {
				$db->run( "UPDATE orders SET status = 'trial_purged', updated_at = ? WHERE id = ?", array( gmdate( 'Y-m-d H:i:s', $now ), $o['id'] ) );
			}
		}
		return $sent;
	}

	/**
	 * Nuovi tentativi dopo un pagamento non riuscito (decisione della titolare, 13/11/2026): 3 al giorno nei primi 2 giorni, poi 1 al giorno per altri
	 * 5, cioè 11 in 7 giorni, poi basta. Ore dal primo rifiuto. Solo Stripe: PayPal riprova da sé.
	 */
	const RETRY_HOURS = array( 8, 16, 24, 32, 40, 48, 72, 96, 120, 144, 168 );

	private static function scheduleRetries( Db $db, array $o, ?int $now ): void {
		if ( 'stripe' !== ( $o['provider'] ?? '' ) ) {
			return;
		}
		$t = $now ?? time();
		$db->run( 'UPDATE orders SET retry_from = ?, retry_n = 0, retry_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', $t ), gmdate( 'Y-m-d H:i:s', $t + self::RETRY_HOURS[0] * 3600 ), $o['id'] ) );
	}

	private static function clearRetries( Db $db, string $orderId ): void {
		$db->run( 'UPDATE orders SET retry_at = NULL, retry_n = 0 WHERE id = ?', array( $orderId ) );
	}

	/**
	 * Lavoro frequente (bin/cron.php, ogni ora): fa i nuovi tentativi arrivati a scadenza. Si ferma per ordine al pagamento riuscito, a un rifiuto
	 * definitivo della carta (carta rubata, numero non valido… mai riprovare), all'abbonamento chiuso o all'undicesimo tentativo.
	 * Spento finché non è acceso `saas_retry` (prima vanno spenti i nuovi tentativi automatici di Stripe, per non sommarli ai nostri).
	 * @return int tentativi fatti
	 */
	public static function retryPayments( Db $db, ?int $now = null ): int {
		if ( true !== Config::get( 'saas_retry', false ) ) {
			return 0;
		}
		$t     = $now ?? time();
		$done  = 0;
		$rows  = $db->all( "SELECT * FROM orders WHERE product = 'saas' AND provider = 'stripe' AND status = ? AND retry_at IS NOT NULL AND retry_at <= ?", array( self::ACTIVE, gmdate( 'Y-m-d H:i:s', $t ) ) );
		foreach ( $rows as $o ) {
			if ( empty( $o['provider_ref'] ) ) {
				self::clearRetries( $db, (string) $o['id'] );
				continue;
			}
			$res = Stripe::payOpenInvoice( (string) $o['provider_ref'] );
			++$done;
			if ( 'declined' !== $res ) {
				self::clearRetries( $db, (string) $o['id'] ); // pagata (ci pensa il webhook), definitivo o chiusa: basta
				continue;
			}
			$n = (int) $o['retry_n'] + 1;
			if ( $n >= count( self::RETRY_HOURS ) ) {
				self::clearRetries( $db, (string) $o['id'] );
				continue;
			}
			$db->run( 'UPDATE orders SET retry_n = ?, retry_at = ? WHERE id = ?', array( $n, gmdate( 'Y-m-d H:i:s', strtotime( $o['retry_from'] . ' UTC' ) + self::RETRY_HOURS[ $n ] * 3600 ), $o['id'] ) );
		}
		return $done;
	}

	/**
	 * Novità del provider su un abbonamento già attivo: renewed | failed | cancelled.
	 * @return bool falso se il riferimento non è di un ordine del gestionale
	 */
	public static function event( Db $db, string $provider, string $providerRef, string $kind, ?int $now = null ): bool {
		if ( '' === $providerRef ) {
			return false;
		}
		$row = $db->one( "SELECT id FROM orders WHERE product = 'saas' AND provider = ? AND provider_ref = ?", array( $provider, $providerRef ) );
		if ( ! $row ) {
			return false;
		}
		$o = (array) Orders::get( $db, (string) $row['id'] );
		if ( self::ACTIVE !== $o['status'] ) {
			return true; // il pagamento iniziale non è ancora concluso: la consegna successiva penserà a tutto
		}
		$org = $db->one( 'SELECT * FROM saas_orgs WHERE ref = ?', array( $o['org_ref'] ) );
		if ( ! $org || empty( $org['org_id'] ) ) {
			return true;
		}
		if ( 'renewed' === $kind ) {
			self::clearRetries( $db, (string) $o['id'] );
			self::push( $db, $o, $org, 'active', self::until( (string) $o['period'], $now ), $now );
			self::mail( $db, 'saas_renewed', $o, $org, $now );
		} elseif ( 'failed' === $kind ) {
			// Ogni nuovo tentativo andato male riconsegna «pagamento non riuscito»: il cliente è già stato avvisato una volta, niente email a ogni tentativo.
			if ( 'past_due' === SaasAccount::stateOf( $db, (string) $org['org_id'] ) ) {
				return true;
			}
			self::scheduleRetries( $db, $o, $now );
			self::push( $db, $o, $org, 'past_due', self::lastUntil( $db, (string) $org['org_id'] ) ?? self::until( (string) $o['period'], $now ), $now );
			self::mail( $db, 'saas_payment_failed', $o, $org, $now );
		} elseif ( 'cancelled' === $kind ) {
			self::clearRetries( $db, (string) $o['id'] );
			self::push( $db, $o, $org, 'cancelled', self::lastUntil( $db, (string) $org['org_id'] ) ?? gmdate( 'Y-m-d', $now ?? time() ), $now );
		}
		return true;
	}

	/** Crea l'associazione sul SaaS; se il sottodominio è già preso ne prova uno con un suffisso. */
	private static function provision( Db $db, array $org, ?int $now ): array {
		$cust = (array) $db->one( 'SELECT email, name FROM customers WHERE id = ?', array( $org['customer_id'] ) );
		$slug = (string) $org['slug'];
		for ( $i = 0; $i < 3; $i++ ) {
			list( $code, $res ) = SaasClient::provision( (string) $org['ref'], (string) $org['name'], $slug, (string) $cust['email'], (string) ( $cust['name'] ?? '' ) ?: (string) $cust['email'], $now );
			if ( in_array( $code, array( 200, 201 ), true ) && ! empty( $res['org_id'] ) ) {
				$db->run( 'UPDATE saas_orgs SET org_id = ?, slug = ? WHERE ref = ?', array( (string) $res['org_id'], $slug, $org['ref'] ) );
				return array( 'org_id' => (string) $res['org_id'], 'slug' => $slug ) + $org;
			}
			if ( 409 === $code && 'slug_taken' === ( $res['error'] ?? '' ) ) {
				$slug = substr( (string) $org['slug'], 0, 35 ) . '-' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 );
				continue;
			}
			throw new \RuntimeException( 'provision non riuscita (' . $code . '): da riprovare.' );
		}
		throw new \RuntimeException( 'provision: sottodominio non assegnabile.' );
	}

	/** Mette in coda `subscription` con la `rev` successiva dell'associazione. */
	private static function push( Db $db, array $o, array $org, string $status, string $until, ?int $now ): void {
		$db->run( 'UPDATE saas_orgs SET rev = rev + 1 WHERE ref = ?', array( $org['ref'] ) );
		$rev    = (int) ( $db->one( 'SELECT rev FROM saas_orgs WHERE ref = ?', array( $org['ref'] ) )['rev'] ?? 1 );
		$addons = array();
		foreach ( array_slice( (array) ( $o['quote']['lines'] ?? array() ), 1 ) as $l ) {
			$addons[] = array( 'code' => (string) $l['code'], 'qty' => (int) $l['qty'] );
		}
		$claims = array(
			'ref' => (string) $org['ref'], 'rev' => $rev, 'status' => $status, 'until' => $until, 'trial_ends' => ( 'active' !== $status && ! empty( $o['trial_ends'] ) ) ? (string) $o['trial_ends'] : null,
			'plan' => (string) $o['plan'], 'plan_version' => (int) $o['plan_version'], 'members' => (int) $o['members'], 'addons' => $addons,
			'price_cents' => (int) ( $o['quote']['net'] ?? 0 ),
		);
		if ( ! empty( $o['quote']['promo'] ) ) {
			$claims['promo'] = (string) $o['quote']['promo'];
		}
		Outbox::enqueue( $db, (string) $org['org_id'], $claims, $now );
	}

	private static function until( string $period, ?int $now ): string {
		return gmdate( 'Y-m-d', strtotime( 'year' === $period ? '+1 year' : '+1 month', $now ?? time() ) );
	}

	private static function lastUntil( Db $db, string $orgId ): ?string {
		$r = $db->one( 'SELECT body FROM saas_outbox WHERE org_id = ? ORDER BY rev DESC LIMIT 1', array( $orgId ) );
		$u = $r ? ( (array) json_decode( (string) $r['body'], true ) )['until'] ?? null : null;
		return is_string( $u ) && '' !== $u ? $u : null;
	}

	/** Quantità dei componenti scelti: solo codici validi e interi positivi. */
	public static function cleanAddons( array $raw ): array {
		$out = array();
		foreach ( $raw as $code => $qty ) {
			$qty = (int) $qty;
			if ( is_string( $code ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $code ) && $qty > 0 ) {
				$out[ $code ] = min( 1000, $qty );
			}
		}
		return $out;
	}
}

<?php
namespace Portale;

/**
 * Cambio di piano su una licenza già pagata, senza cambiare chiave né scadenza.
 *  - Upgrade (a un piano più caro): subito. Si paga la differenza di prezzo calcolata sui giorni che mancano alla scadenza; la scadenza resta quella.
 *  - Downgrade (a un piano più economico): dal prossimo rinnovo. La licenza resta com'è fino alla scadenza pagata, poi cambia tipo.
 * Il piano e il periodo (mese/anno) si leggono dal listino; il provider è quello con cui è stata acquistata la licenza.
 */
final class PlanChange {

	/** Importo minimo accettato da Stripe, in centesimi. */
	const MIN_CENTS = 50;

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;

	public function __construct( Db $db, Billing $billing ) {
		$this->db      = $db;
		$this->billing = $billing;
	}

	/** Differenza da pagare ora: (nuovo − vecchio) in proporzione ai giorni residui del periodo, mai meno del minimo del provider. */
	public static function diffCents( int $oldCents, int $newCents, string $expiresAt, int $periodDays, ?int $now = null ): int {
		$left  = max( 0, (int) strtotime( $expiresAt . ' UTC' ) - ( $now ?? time() ) );
		$days  = min( max( 1, $periodDays ), (int) ceil( $left / 86400 ) );
		$cents = (int) round( ( $newCents - $oldCents ) * $days / max( 1, $periodDays ) );
		return max( self::MIN_CENTS, $cents );
	}

	private function periodDays( string $period ): int {
		return Settings::limit( 'year' === $period ? 'duration_year' : 'duration_month' );
	}

	/** Perché il piano non si può cambiare da qui, o null se si può. */
	public function reason( array $sub ): ?string {
		if ( ! in_array( $sub['provider'], array( 'stripe', 'paypal' ), true ) || empty( $sub['license_id'] ) || empty( $sub['license_key_enc'] ) ) {
			return 'Il cambio piano è disponibile per le licenze acquistate con carta o PayPal.';
		}
		if ( 'active' !== $sub['status'] || empty( $sub['expires_at'] ) || strtotime( $sub['expires_at'] . ' UTC' ) <= time() ) {
			return 'La licenza non è attiva: per cambiare piano scegline uno nuovo.';
		}
		if ( ! empty( $sub['cancel_at_period_end'] ) ) {
			return 'Il rinnovo è disdetto: alla scadenza potrai scegliere un altro piano.';
		}
		if ( 'paypal' === $sub['provider'] && $this->billing->isRecurring( $sub ) ) {
			return 'Con l\'abbonamento PayPal ricorrente il cambio piano non è ancora automatico: scrivici dal supporto.';
		}
		return null;
	}

	/**
	 * Piani verso cui si può passare con lo stesso periodo.
	 *
	 * @return array elenco di [plan, label, up, price (centesimi del nuovo piano), pay (da pagare ora, solo upgrade)]
	 */
	public function options( array $sub ): array {
		$cur = Config::price( $sub['plan_code'], $sub['period'] );
		if ( ! $cur || $this->reason( $sub ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( (array) Config::get( 'plans', array() ) ) as $code ) {
			if ( 'free' === $code || $code === $sub['plan_code'] ) {
				continue;
			}
			$p = Config::price( $code, $sub['period'] );
			if ( ! $p || empty( $p['cents'] ) ) {
				continue;
			}
			if ( $this->billing->isRecurring( $sub ) && 'stripe' === $sub['provider'] && empty( $p['stripe_price'] ) ) {
				continue;
			}
			$up  = (int) $p['cents'] > (int) $cur['cents'];
			$row = array( 'plan' => $code, 'label' => (string) Config::get( 'plans.' . $code . '.label', $code ), 'up' => $up, 'price' => (int) $p['cents'] );
			if ( $up ) {
				$row['pay'] = self::diffCents( (int) $cur['cents'], (int) $p['cents'], (string) $sub['expires_at'], $this->periodDays( $sub['period'] ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	private function mine( string $subId, string $customerId, string $toPlan, bool $up ): array {
		$sub = $this->billing->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId ) {
			throw new UserError( 'Licenza non trovata.' );
		}
		if ( $r = $this->reason( $sub ) ) {
			throw new UserError( $r );
		}
		if ( ! empty( $sub['next_plan'] ) ) {
			throw new UserError( 'C\'è già un cambio di piano programmato: annullalo prima di sceglierne un altro.' );
		}
		foreach ( $this->options( $sub ) as $o ) {
			if ( $o['plan'] === $toPlan && $o['up'] === $up ) {
				return array( $sub, $o );
			}
		}
		throw new UserError( 'Questo cambio di piano non è disponibile.' );
	}

	/** Upgrade: crea il pagamento della differenza e restituisce l'indirizzo a cui mandare il cliente. */
	public function startUpgrade( string $subId, string $customerId, string $toPlan ): string {
		list( $sub, $opt ) = $this->mine( $subId, $customerId, $toPlan, true );
		$cust  = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $customerId ) );
		$id    = Db::id();
		$label = $opt['label'] . ' · differenza fino al ' . date( 'd/m/Y', strtotime( $sub['expires_at'] . ' UTC' ) );
		$this->db->run(
			'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, renews, change_to, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
			array( $id, $customerId, $toPlan, $sub['period'], $sub['provider'], 'pending', $sub['domain'], 'once', $sub['id'], $toPlan, Db::now(), Db::now() )
		);
		if ( 'stripe' === $sub['provider'] ) {
			return Stripe::oneTimeUrl( $id, (string) $cust['email'], $opt['pay'], $label );
		}
		list( $orderId, $url ) = PayPal::createOrder( $id, $opt['pay'], $label );
		$this->db->run( 'UPDATE subscriptions SET provider_ref = ? WHERE id = ?', array( $orderId, $id ) );
		return $url;
	}

	/**
	 * Pagamento della differenza andato a buon fine: la licenza passa al nuovo piano (stessa chiave, stessa scadenza).
	 * Ripetibile senza effetti. Se un passaggio esterno fallisce l'errore sale e il provider ritenta il webhook.
	 */
	public function applyUpgrade( array $change ): void {
		$old = $this->billing->subscription( (string) $change['renews'] );
		$to  = (string) $change['change_to'];
		if ( ! $old || empty( $old['license_id'] ) ) {
			throw new UserError( 'Licenza da aggiornare non trovata.' );
		}
		$this->billing->wega()->patch( (string) $old['license_id'], array( 'plan_name' => (string) Config::get( 'plans.' . $to . '.plan_name', $to ) ) );
		$this->switchProviderPrice( $old, $to );
		$this->db->run( 'UPDATE subscriptions SET plan_code = ?, next_plan = NULL, updated_at = ? WHERE id = ?', array( $to, Db::now(), $old['id'] ) );
		$this->billing->notify( 'plan_upgraded', $this->billing->subscription( $old['id'] ) );
	}

	/** Downgrade: la licenza cambia tipo al prossimo rinnovo; su Stripe il prezzo del prossimo addebito è già quello nuovo. */
	public function scheduleDowngrade( string $subId, string $customerId, string $toPlan ): void {
		list( $sub, $opt ) = $this->mine( $subId, $customerId, $toPlan, false );
		$this->switchProviderPrice( $sub, $toPlan );
		$this->db->run( 'UPDATE subscriptions SET next_plan = ?, updated_at = ? WHERE id = ?', array( $toPlan, Db::now(), $sub['id'] ) );
		$this->billing->notify( 'plan_downgrade_scheduled', $this->billing->subscription( $sub['id'] ), array( 'nuovo_piano' => $opt['label'] ) );
	}

	public function cancelScheduled( string $subId, string $customerId ): void {
		$sub = $this->billing->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId || empty( $sub['next_plan'] ) ) {
			throw new UserError( 'Non c\'è un cambio di piano da annullare.' );
		}
		$this->switchProviderPrice( $sub, (string) $sub['plan_code'] );
		$this->db->run( 'UPDATE subscriptions SET next_plan = NULL, updated_at = ? WHERE id = ?', array( Db::now(), $sub['id'] ) );
	}

	/** Chiamata dopo il rinnovo: se c'era un downgrade programmato, ora la licenza cambia tipo. Non lancia errori (il rinnovo è già avvenuto). */
	public function applyScheduled( array $sub ): void {
		$to = (string) ( $sub['next_plan'] ?? '' );
		if ( '' === $to ) {
			return;
		}
		$this->db->run( 'UPDATE subscriptions SET plan_code = ?, next_plan = NULL, updated_at = ? WHERE id = ?', array( $to, Db::now(), $sub['id'] ) );
		try {
			$this->billing->wega()->patch( (string) $sub['license_id'], array( 'plan_name' => (string) Config::get( 'plans.' . $to . '.plan_name', $to ) ) );
		} catch ( \Throwable $e ) {
			error_log( '[portale] downgrade: il server licenze non ha cambiato il tipo della licenza ' . $sub['id'] . ' (' . $e->getMessage() . ')' );
		}
		$this->billing->notify( 'plan_downgraded', $this->billing->subscription( $sub['id'] ) );
	}

	/** Su Stripe ricorrente il prezzo dei prossimi addebiti segue il piano; negli altri casi non c'è nulla da cambiare presso il provider. */
	private function switchProviderPrice( array $sub, string $plan ): void {
		if ( 'stripe' !== $sub['provider'] || ! $this->billing->isRecurring( $sub ) || empty( $sub['provider_ref'] ) ) {
			return;
		}
		$price = Config::price( $plan, $sub['period'] );
		Stripe::switchPrice( (string) $sub['provider_ref'], (string) ( $price['stripe_price'] ?? '' ) );
	}
}

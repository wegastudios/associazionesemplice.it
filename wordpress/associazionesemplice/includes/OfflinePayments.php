<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ciò che c'è da pagare, senza pagamenti online: l'elenco delle voci dovute dal socio e dai suoi ospiti (quota, mensilità dei corsi,
 * contributi degli eventi), con gli importi sempre decisi dal server. Un'estensione può costruirci sopra il servizio dei
 * pagamenti online; senza estensioni {@see Plugin::payments()} restituisce questa classe così com'è:
 * nessun gateway attivo, nessun pagamento in archivio.
 */
class OfflinePayments {

	protected function db(): \wpdb {
		return Db::db();
	}

	/** Gateway utilizzabili: nessuno senza pagamenti online. @return string[] */
	public function providers(): array {
		return array();
	}

	public function provider(): string {
		return $this->providers()[0] ?? '';
	}

	public function enabled(): bool {
		return false;
	}

	/** Pagamenti online in archivio: nessuno senza pagamenti online. */
	public function list( array $f = array(), int $limit = 100 ): array {
		return array();
	}

	// ---------- Cosa c'è da pagare ----------

	private function month_label( string $ym ): string {
		return Frontend\Views::MONTHS[ (int) substr( $ym, 5, 2 ) ] . ' ' . substr( $ym, 0, 4 );
	}

	/**
	 * Voci dovute dal socio e dai suoi ospiti, indicizzate per chiave. Gli importi sono sempre quelli del server.
	 *
	 * @return array[] chiave => voce
	 */
	public function dues_for( array $actor ): array {
		$people  = Plugin::people();
		$acts    = Plugin::activities();
		$persons = array_merge( array( $actor ), $people->managed_by( (int) $actor['id'] ) ); // lui, i suoi ospiti e le persone di cui è tutore
		$dependents = array_map( 'intval', array_column( $people->dependents_of( (int) $actor['id'] ), 'id' ) );
		$items   = array();
		foreach ( $persons as $p ) {
			$pid  = (int) $p['id'];
			$name = trim( $p['first_name'] . ' ' . $p['last_name'] );
			// Quota associativa: per il socio stesso e per chi è sotto la sua tutela (i fondatori e gli ospiti non la pagano)
			$fee = \AssociazioneSemplice\Levels::fee_for( $p );
			if ( ( $pid === (int) $actor['id'] || in_array( $pid, $dependents, true ) ) && MemberType::is_member( $p['type'] ) && ! MemberType::is_auto_renewed( $p['type'] ) && $fee > 0 ) {
				$plan = $people->membership_plan( $pid, Db::today() ); // anno più recente (o quello in corso, se è scaduto)
				if ( ! $people->has_membership( $pid, $plan['year'] ) ) {
					$missing            = $people->membership_missing( $p, $plan['year'] ); // quota a rate: si paga il residuo
					$i                  = array( 'type' => PaymentItems::MEMBERSHIP, 'person_id' => $pid, 'person_name' => $name, 'social_year' => $plan['year'], 'amount_cents' => $missing > 0 ? $missing : $fee, 'label' => 'Quota associativa ' . $plan['year'] . ( $missing > 0 ? ' (saldo)' : '' ) . ( $plan['free'] ? ' (' . $plan['free'] . ' in omaggio)' : '' ) );
					$i['key']           = PaymentItems::key( $i );
					$items[ $i['key'] ] = $i;
				}
			}
			foreach ( $acts->status_for_person( $pid ) as $s ) {
				foreach ( $s['summary']['unpaid_months'] as $m ) {
					if ( $m['missing'] <= 0 ) {
						continue;
					}
					$i        = array(
						'type' => PaymentItems::COURSE_MONTH, 'person_id' => $pid, 'person_name' => $name, 'activity_id' => (int) $s['enrollment']['activity_id'],
						'month' => $m['month'], 'amount_cents' => (int) $m['missing'], 'label' => $s['activity']['name'] . ' — ' . $this->month_label( $m['month'] ),
					);
					$i['key'] = PaymentItems::key( $i );
					$items[ $i['key'] ] = $i;
				}
			}
			foreach ( $acts->unpaid_bookings_for_person( $pid ) as $b ) {
				$i        = array(
					'type' => PaymentItems::BOOKING, 'person_id' => $pid, 'person_name' => $name, 'session_id' => (int) $b['session_id'], 'activity_id' => (int) $b['activity_id'],
					'amount_cents' => (int) $b['remaining'], 'label' => $b['activity_name'] . ' — ' . ( new \DateTimeImmutable( $b['session_date'] ) )->format( 'd/m/Y' ),
				);
				$i['key'] = PaymentItems::key( $i );
				$items[ $i['key'] ] = $i;
			}
		}
		return (array) apply_filters( 'asem_online_dues', $items, $actor ); // un'estensione aggiunge altre voci da pagare
	}

	// ---------- Quota della domanda di adesione ----------
	// Senza pagamenti online non c'è niente da fare: la quota si paga in sede. Un'estensione che li porta ridefinisce questi quattro metodi.

	/** Chi chiede di iscriversi può pagare la quota online (c'è un metodo che non richiede un accesso)? */
	public function application_online(): bool {
		return false;
	}

	/**
	 * Avvia il pagamento online della quota di chi ha chiesto di iscriversi e non ha ancora un accesso. @return string indirizzo della pagina di pagamento
	 *
	 * @throws \InvalidArgumentException
	 */
	public function application_checkout( array $person, int $cents, string $back_url, string $provider = '' ): string {
		throw new \InvalidArgumentException( 'I pagamenti online non sono attivi: la quota si paga in sede.' );
	}

	/** La quota di questa domanda è già stata pagata online (e non ancora registrata in prima nota)? @return array|null ['cents' => importo versato] */
	public function application_paid( int $person_id ): ?array {
		return null;
	}

	/** Domanda approvata: registra in prima nota la quota pagata online e l'iscrizione. @return bool true se c'era un pagamento da registrare */
	public function application_settle( int $person_id, string $social_year ): bool {
		return false;
	}

	/** Domanda rifiutata: il pagamento già fatto va restituito. @return int pagamenti segnati «da rimborsare» */
	public function application_refund_due( int $person_id ): int {
		return 0;
	}

	// ---------- Archivio ----------
}

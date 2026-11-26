<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tolleranza per il pagamento: per gli eventi con i posti limitati e un contributo, chi prenota e paga con bonifico o contanti ha un
 * certo numero di ore (si sceglie alla creazione dell'evento) per versare. Scaduto il termine senza nessun pagamento il posto si libera
 * e passa a chi è in lista d'attesa. Non si libera mai un posto con un pagamento già registrato, con un pagamento online in corso,
 * con l'ingresso già registrato o per una data già passata.
 */
final class Holds {

	/** Lavoro periodico, pianificato da Plugin::schedule_jobs(). */
	const HOOK = 'asem_release_holds';

	/** Ha un pagamento online avviato da poco per questa prenotazione? */
	private static function online_in_progress( int $person_id, int $session_id ): bool {
		return (bool) apply_filters( 'asem_online_payment_pending', false, $person_id, $session_id ); // chi incassa online lo dice qui
	}

	/**
	 * Libera i posti delle prenotazioni non pagate entro il termine.
	 *
	 * @param ?int $session_id solo questa data (null = tutte)
	 * @return int prenotazioni liberate
	 */
	public static function release_expired( ?int $session_id = null ): int {
		$db   = Db::db();
		$sql  = 'SELECT b.session_id, b.person_id, b.fee_due_cents, a.hold_hours, a.name AS activity_name, a.id AS activity_id, s.session_date FROM ' . Db::t( 'bookings' ) . ' b '
			. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL AND s.capacity IS NOT NULL AND s.session_date >= %s '
			. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.hold_hours > 0 WHERE b.status = 'booked' AND b.fee_due_cents > 0 AND b.checked_in_at IS NULL "
			. 'AND b.created_at <= DATE_SUB(%s, INTERVAL a.hold_hours HOUR)';
		$args = array( Db::today(), Db::now() );
		if ( $session_id ) {
			$sql   .= ' AND b.session_id = %d';
			$args[] = $session_id;
		}
		$n    = 0;
		$cand = $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
		$read = $cand ? Plugin::activities()->bookings_for_sessions( array_map( 'intval', array_column( $cand, 'session_id' ) ) ) : array(); // prenotazioni e versamenti di tutte le date insieme, non due letture per posto
		foreach ( $cand as $b ) {
			$sid = (int) $b['session_id'];
			$pid = (int) $b['person_id'];
			$row = null;
			foreach ( $read[ $sid ] ?? array() as $r ) {
				if ( (int) $r['person_id'] === $pid ) {
					$row = $r;
					break;
				}
			}
			$debtor = $row ? ActivityService::debtor_id( $row ) : $pid; // chi deve pagare: chi ha prenotato per altri, o la persona stessa
			if ( ! $row || 'booked' !== $row['status'] || (int) $row['paid'] > 0 || self::online_in_progress( $debtor, $sid ) || ( $debtor !== $pid && self::online_in_progress( $pid, $sid ) ) ) {
				continue; // ha già versato (anche in parte) o sta pagando online: il posto resta
			}
			Plugin::activities()->cancel_booking( $sid, $pid ); // libera il posto e fa entrare il primo della lista d'attesa
			Audit::log( 'booking.released', 'activity', (int) $b['activity_id'], array( 'session' => $sid, 'person_id' => $pid, 'hours' => (int) $b['hold_hours'] ) );
			$person = Plugin::people()->get( $pid );
			$payer  = $debtor !== $pid ? Plugin::people()->get( $debtor ) : null;
			if ( $payer ) { // a chi ha prenotato il motivo; a chi era prenotato solo che il posto non c'è più
				$what = '«' . $b['activity_name'] . '» di ' . Frontend\Views::date_words( (string) $b['session_date'] );
				if ( is_email( (string) $payer['email'] ) ) {
					Texts::mail( $payer['email'], Texts::subject( 'Posto liberato: ' . $b['activity_name'] ), 'Ciao ' . $payer['first_name'] . ",\n\nil posto che hai prenotato per " . ( $person ? ActivityService::participant_name( $person, $debtor ) : 'un\'altra persona' ) . ', ' . $what . ', è stato liberato perché il contributo non è arrivato entro ' . Texts::n( (int) $b['hold_hours'], '{n} ora', '{n} ore' ) . ' dalla prenotazione. Se hai già pagato, scrivi subito alla segreteria.' );
				}
				if ( $person && MemberType::is_member( (string) $person['type'] ) && is_email( (string) $person['email'] ) ) {
					Texts::mail( $person['email'], Texts::subject( 'Posto liberato: ' . $b['activity_name'] ), 'Ciao ' . $person['first_name'] . ",\n\nla prenotazione fatta per te per " . $what . ' non è più valida. Se vuoi partecipare, puoi prenotare dall\'area soci (se ci sono ancora posti).' );
				}
			} elseif ( $person && is_email( (string) $person['email'] ) ) {
				Texts::mail(
					$person['email'],
					Texts::subject( 'Posto liberato: ' . $b['activity_name'] ),
					'Ciao ' . $person['first_name'] . ",\n\nil tuo posto per «" . $b['activity_name'] . '» di ' . Frontend\Views::date_words( (string) $b['session_date'] ) . ' è stato liberato perché il contributo non è arrivato entro ' . Texts::n( (int) $b['hold_hours'], '{n} ora', '{n} ore' ) . ' dalla prenotazione. Se hai già pagato, scrivi subito alla segreteria.'
					. "\n\nSe vuoi partecipare, prenota di nuovo (se ci sono ancora posti) o mettiti in lista d'attesa."
				);
			}
			$n++;
		}
		return $n;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole (pure) dei rimborsi ai volontari: stati, tipi di rimborso, limiti dell'autocertificazione e chi può approvare.
 * L'art. 17 c. 3 del Codice del Terzo settore ammette il rimborso delle spese effettivamente sostenute e documentate; l'autocertificazione
 * vale fino a 10 euro al giorno e 150 euro al mese, solo se l'assemblea ha deliberato i tipi di spesa e le attività. Il resto sta in {@see Refunds}.
 */
final class RefundRules {

	const REQUESTED = 'requested';
	const APPROVED  = 'approved';
	const REJECTED  = 'rejected';
	const PAID      = 'paid';
	const CANCELLED = 'cancelled';

	const DOCUMENTED = 'documented';
	const SELFCERT   = 'selfcert';
	const KM         = 'km';

	const DAY_LIMIT_CENTS   = 1000;
	const MONTH_LIMIT_CENTS = 15000;
	const MAX_CENTS         = 100000000;

	public static function statuses(): array {
		return array( self::REQUESTED => 'Da approvare', self::APPROVED => 'Approvato, da pagare', self::REJECTED => 'Rifiutato', self::PAID => 'Pagato', self::CANCELLED => 'Annullato' );
	}

	public static function kinds(): array {
		return array(
			self::DOCUMENTED => 'Spesa documentata (scontrino o fattura)',
			self::SELFCERT   => 'Autocertificazione (fino a 10 € al giorno e 150 € al mese)',
			self::KM         => 'Rimborso chilometrico',
		);
	}

	/** Tipi proposti: il chilometrico solo se l'ente ha indicato la tariffa. */
	public static function available_kinds( int $km_rate_cents ): array {
		$k = self::kinds();
		if ( $km_rate_cents <= 0 ) {
			unset( $k[ self::KM ] );
		}
		return $k;
	}

	public static function can_move( string $from, string $to ): bool {
		$map = array(
			self::REQUESTED => array( self::APPROVED, self::REJECTED, self::CANCELLED ),
			self::APPROVED  => array( self::PAID, self::REJECTED, self::REQUESTED ),
			self::REJECTED  => array(),
			self::PAID      => array(),
			self::CANCELLED => array(),
		);
		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/** Il giustificativo (file) è obbligatorio per le spese documentate. */
	public static function needs_receipt( string $kind ): bool {
		return self::DOCUMENTED === $kind;
	}

	/** Importo del rimborso chilometrico: chilometri per tariffa al chilometro (in centesimi). */
	public static function km_amount( int $km, int $rate_cents ): int {
		return max( 0, $km ) * max( 0, $rate_cents );
	}

	/**
	 * Controlla l'importo di una richiesta. Restituisce il motivo del rifiuto, vuoto se va bene.
	 *
	 * @param int $day_total   autocertificazioni già chieste dalla stessa persona nello stesso giorno (stati non rifiutati)
	 * @param int $month_total idem nello stesso mese
	 */
	public static function amount_refusal( string $kind, int $cents, int $day_total = 0, int $month_total = 0 ): string {
		if ( $cents <= 0 ) {
			return 'Indica l\'importo.';
		}
		if ( $cents > self::MAX_CENTS ) {
			return 'Importo troppo alto.';
		}
		if ( self::SELFCERT === $kind ) {
			if ( $day_total + $cents > self::DAY_LIMIT_CENTS ) {
				return 'L\'autocertificazione vale fino a 10 € al giorno per volontario: con questa richiesta si supera il limite.';
			}
			if ( $month_total + $cents > self::MONTH_LIMIT_CENTS ) {
				return 'L\'autocertificazione vale fino a 150 € al mese per volontario: con questa richiesta si supera il limite.';
			}
		}
		return '';
	}

	/** Chi approva non approva (né paga) il proprio rimborso, salvo che l'ente lo consenta. */
	public static function self_approval_blocked( int $actor_person_id, int $requester_person_id, bool $allowed ): bool {
		return ! $allowed && $actor_person_id > 0 && $actor_person_id === $requester_person_id;
	}

	/** La data della spesa non può essere futura (date «Y-m-d»). @return string|null messaggio d'errore */
	public static function check_expense_date( string $date, string $today ): ?string {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			return 'Indica la data della spesa.';
		}
		return $date > $today ? 'La data della spesa non può essere futura.' : null;
	}
}

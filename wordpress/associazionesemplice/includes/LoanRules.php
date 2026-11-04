<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) dell'inventario e dei prestiti: stato di un bene, prestito scaduto, date, quantità disponibili. Il resto sta in Inventory. */
final class LoanRules {

	const OUT      = 'out';
	const OVERDUE  = 'overdue';
	const RETURNED = 'returned';

	public static function item_states(): array {
		return array( 'good' => 'Buono', 'worn' => 'Usurato', 'broken' => 'Da riparare', 'lost' => 'Perso', 'retired' => 'Dismesso' );
	}

	/** Stati in cui il bene si può prestare. */
	public static function lendable_state( string $state ): bool {
		return in_array( $state, array( 'good', 'worn' ), true );
	}

	public static function loan_labels(): array {
		return array( self::OUT => 'In prestito', self::OVERDUE => 'Rientro scaduto', self::RETURNED => 'Rientrato' );
	}

	/** Un prestito non rientrato è scaduto dal giorno dopo la data di rientro prevista. */
	public static function is_overdue( string $due_on, ?string $returned_on, string $today ): bool {
		return empty( $returned_on ) && $due_on < $today;
	}

	/** @return string out | overdue | returned */
	public static function status( string $due_on, ?string $returned_on, string $today ): string {
		if ( ! empty( $returned_on ) ) {
			return self::RETURNED;
		}
		return $due_on < $today ? self::OVERDUE : self::OUT;
	}

	/** Giorni di ritardo (0 se non è scaduto o è già rientrato). */
	public static function days_late( string $due_on, ?string $returned_on, string $today ): int {
		if ( ! self::is_overdue( $due_on, $returned_on, $today ) ) {
			return 0;
		}
		return (int) round( ( strtotime( $today . ' 12:00:00 UTC' ) - strtotime( $due_on . ' 12:00:00 UTC' ) ) / 86400 );
	}

	public static function is_date( string $v ): bool {
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v;
	}

	/** @return string|null messaggio d'errore per le date di un prestito */
	public static function check_dates( string $lent_on, string $due_on ): ?string {
		if ( ! self::is_date( $lent_on ) ) {
			return 'Indica la data di uscita.';
		}
		if ( ! self::is_date( $due_on ) ) {
			return 'Indica la data di rientro prevista.';
		}
		return $due_on < $lent_on ? 'Il rientro previsto non può venire prima dell\'uscita.' : null;
	}

	/** @return string|null messaggio d'errore per la data del rientro effettivo */
	public static function check_return( string $lent_on, string $returned_on ): ?string {
		if ( ! self::is_date( $returned_on ) ) {
			return 'Indica la data del rientro.';
		}
		return $returned_on < $lent_on ? 'Il rientro non può essere prima dell\'uscita.' : null;
	}

	/** Pezzi ancora disponibili: quantità totale meno quelli fuori. */
	public static function available( int $quantity, int $out ): int {
		return max( 0, $quantity - max( 0, $out ) );
	}

	/** @return string|null messaggio d'errore se non si può prestare quella quantità */
	public static function check_quantity( int $wanted, int $available, string $state ): ?string {
		if ( ! self::lendable_state( $state ) ) {
			return 'Questo bene non si può prestare (' . mb_strtolower( self::item_states()[ $state ] ?? $state ) . ').';
		}
		if ( $wanted < 1 ) {
			return 'Indica quanti pezzi escono.';
		}
		return $wanted > $available ? ( 0 === $available ? 'Tutti i pezzi sono già in prestito.' : 'Ne restano disponibili solo ' . $available . '.' ) : null;
	}
}

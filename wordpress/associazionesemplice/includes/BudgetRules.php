<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) del bilancio preventivo: confronto di una voce con il consuntivo, totali, copia dall'anno prima. Il resto sta in {@see Budget}. */
final class BudgetRules {

	const INCOME  = 'income';
	const EXPENSE = 'expense';

	const OK        = 'ok';
	const NEAR      = 'near';
	const OVER      = 'over';
	const UNPLANNED = 'unplanned';
	const NONE      = 'none';

	/** Oltre questa quota del preventivo una spesa risulta «quasi esaurita». */
	const NEAR_PERCENT = 90;

	const DRAFT    = 'draft';
	const APPROVED = 'approved';

	public static function statuses(): array {
		return array( self::DRAFT => 'In preparazione', self::APPROVED => 'Approvato' );
	}

	public static function status_labels(): array {
		return array( self::OK => 'nei limiti', self::NEAR => 'quasi esaurita', self::OVER => 'oltre il preventivo', self::UNPLANNED => 'non prevista', self::NONE => '—' );
	}

	/** Date di inizio e fine dell'anno. @return array{0:string,1:string} */
	public static function period( int $year ): array {
		return FiscalYears::range( $year );
	}

	/** Anno valido per un preventivo (2000–2100). */
	public static function valid_year( int $year ): bool {
		return $year >= 2000 && $year <= 2100;
	}

	/**
	 * Una voce messa a confronto con il consuntivo.
	 * La differenza è sempre «a favore» se positiva: per le entrate è quanto si è incassato in più, per le uscite quanto resta da spendere.
	 *
	 * @return array{diff:int,percent:?int,status:string}
	 */
	public static function compare( string $type, int $planned, int $actual ): array {
		$diff = self::INCOME === $type ? $actual - $planned : $planned - $actual;
		if ( $planned <= 0 ) {
			return array( 'diff' => $diff, 'percent' => null, 'status' => $actual > 0 ? self::UNPLANNED : self::NONE );
		}
		$percent = (int) round( $actual * 100 / $planned );
		$status  = self::OK;
		if ( self::EXPENSE === $type ) {
			if ( $actual > $planned ) {
				$status = self::OVER;
			} elseif ( $percent >= self::NEAR_PERCENT ) {
				$status = self::NEAR;
			}
		}
		return array( 'diff' => $diff, 'percent' => $percent, 'status' => $status );
	}

	/**
	 * Unisce le voci del preventivo con il consuntivo per categoria: ogni categoria con spese o entrate reali compare anche se non prevista.
	 *
	 * @param array[] $lines  category_id, type, planned_cents
	 * @param array   $actual category_id => cents
	 * @param array   $types  category_id => income|expense (per le voci non previste)
	 * @return array[] category_id, type, planned, actual + compare()
	 */
	public static function merge( array $lines, array $actual, array $types ): array {
		$rows = array();
		foreach ( $lines as $l ) {
			$cid          = (int) $l['category_id'];
			$rows[ $cid ] = array( 'category_id' => $cid, 'type' => (string) $l['type'], 'planned' => (int) $l['planned_cents'], 'actual' => (int) ( $actual[ $cid ] ?? 0 ) );
		}
		foreach ( $actual as $cid => $cents ) {
			$cid = (int) $cid;
			if ( ! isset( $rows[ $cid ] ) && (int) $cents > 0 && isset( $types[ $cid ] ) ) {
				$rows[ $cid ] = array( 'category_id' => $cid, 'type' => (string) $types[ $cid ], 'planned' => 0, 'actual' => (int) $cents );
			}
		}
		foreach ( $rows as &$r ) {
			$r += self::compare( $r['type'], $r['planned'], $r['actual'] );
		}
		unset( $r );
		return array_values( $rows );
	}

	/** @return array{income_planned:int,income_actual:int,expense_planned:int,expense_actual:int,result_planned:int,result_actual:int} */
	public static function totals( array $rows ): array {
		$t = array( 'income_planned' => 0, 'income_actual' => 0, 'expense_planned' => 0, 'expense_actual' => 0 );
		foreach ( $rows as $r ) {
			$k = self::INCOME === $r['type'] ? 'income' : 'expense';
			$t[ $k . '_planned' ] += (int) $r['planned'];
			$t[ $k . '_actual' ]  += (int) $r['actual'];
		}
		$t['result_planned'] = $t['income_planned'] - $t['expense_planned'];
		$t['result_actual']  = $t['income_actual'] - $t['expense_actual'];
		return $t;
	}

	/** Un importo cambiato di una percentuale (positiva o negativa), arrotondato al centesimo e mai sotto zero. */
	public static function scale( int $cents, int $percent ): int {
		return max( 0, (int) round( $cents * ( 100 + max( -100, min( 1000, $percent ) ) ) / 100 ) );
	}

	/** Entrata o uscita per il tipo di categoria; null per le rettifiche di cassa e i tipi sconosciuti. */
	public static function type_of_kind( string $kind ): ?string {
		$kinds = Labels::category_kinds();
		if ( ! isset( $kinds[ $kind ] ) || 'adjustment' === $kind ) {
			return null;
		}
		return $kinds[ $kind ][1] ? self::INCOME : ( $kinds[ $kind ][2] ? self::EXPENSE : null );
	}
}

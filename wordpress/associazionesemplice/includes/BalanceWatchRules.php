<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole (pure, senza database) del controllo di bilancio: tendenza a fine anno stimata dai giorni trascorsi, avvisi sulle soglie
 * (spese non coperte, utile eccessivo), mesi dell'anno fiscale. Il resto sta in {@see BalanceWatch}.
 */
final class BalanceWatchRules {

	const UNCOVERED = 'uncovered';
	const SURPLUS   = 'surplus';

	/** Giorni di un mese «tipo» per trasformare i mesi minimi dei limiti in giorni. */
	const DAYS_PER_MONTH = 30;

	/**
	 * Stima di fine anno: quanto già registrato, riportato all'anno intero in proporzione ai giorni trascorsi.
	 *
	 * @return array{income:int,expense:int}
	 */
	public static function projection( int $income, int $expense, int $elapsed_days, int $total_days ): array {
		if ( $elapsed_days <= 0 || $total_days <= 0 ) {
			return array( 'income' => 0, 'expense' => 0 );
		}
		$scale = $total_days / min( $elapsed_days, $total_days );
		return array( 'income' => (int) round( $income * $scale ), 'expense' => (int) round( $expense * $scale ) );
	}

	/** Sono passati abbastanza giorni per fidarsi della tendenza? */
	public static function enough_data( int $elapsed_days, int $min_months ): bool {
		return $elapsed_days >= max( 1, $min_months ) * self::DAYS_PER_MONTH;
	}

	/**
	 * Gli avvisi sulla tendenza di fine anno.
	 * Spese non coperte: le uscite superano le entrate di più di $over_pct per cento delle entrate.
	 * Utile eccessivo: le entrate superano le uscite di più di $surplus_pct per cento delle uscite.
	 *
	 * @return array[] kind, gap (centesimi), percent (null se non si può calcolare)
	 */
	public static function alerts( int $income, int $expense, int $over_pct, int $surplus_pct ): array {
		$out = array();
		$gap = $expense - $income;
		if ( $gap > 0 && ( $income <= 0 || $gap * 100 > max( 0, $over_pct ) * $income ) ) {
			$out[] = array( 'kind' => self::UNCOVERED, 'gap' => $gap, 'percent' => $income > 0 ? (int) round( $gap * 100 / $income ) : null );
		}
		$gap = $income - $expense;
		if ( $gap > 0 && ( $expense <= 0 || $gap * 100 > max( 0, $surplus_pct ) * $expense ) ) {
			$out[] = array( 'kind' => self::SURPLUS, 'gap' => $gap, 'percent' => $expense > 0 ? (int) round( $gap * 100 / $expense ) : null );
		}
		return $out;
	}

	/** Il messaggio per chi legge: cosa succede a fine anno e cosa fare. */
	public static function message( array $alert ): string {
		$gap = Money::format( (int) $alert['gap'] );
		$pct = null === $alert['percent'] ? '' : ' (' . (int) $alert['percent'] . '% in più)';
		if ( self::UNCOVERED === $alert['kind'] ) {
			return 'Di questo passo l\'anno si chiude con uscite superiori alle entrate di ' . $gap . $pct . ': le spese non sono coperte. Conviene rimandare o ridurre le spese non urgenti e cercare nuove entrate (quote, eventi, contributi) prima della fine dell\'anno.';
		}
		return 'Di questo passo l\'anno si chiude con entrate superiori alle uscite di ' . $gap . $pct . ': un utile molto più alto del necessario. Conviene destinare le risorse alle attività dei soci (corsi, eventi, attrezzature) o rivedere le quote, e parlarne in consiglio direttivo.';
	}

	/** Titolo breve di un avviso. */
	public static function title( string $kind ): string {
		return self::UNCOVERED === $kind ? 'Spese non coperte' : 'Utile troppo alto';
	}

	/** Posizione del mese nell'anno fiscale: 0 = il primo mese, 11 = l'ultimo. */
	public static function month_offset( string $date, int $start_month ): int {
		return ( (int) substr( $date, 5, 2 ) - max( 1, min( 12, $start_month ) ) + 12 ) % 12;
	}

	/** I 12 mesi («AAAA-MM») dell'anno fiscale che inizia nell'anno e nel mese indicati. @return string[] */
	public static function months( int $year, int $start_month ): array {
		$out = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$n     = $start_month - 1 + $i;
			$out[] = sprintf( '%04d-%02d', $year + intdiv( $n, 12 ), $n % 12 + 1 );
		}
		return $out;
	}

	/** Primo e ultimo giorno di un mese «AAAA-MM». @return array{0:string,1:string} */
	public static function month_range( string $month ): array {
		$first = new \DateTimeImmutable( $month . '-01' );
		return array( $first->format( 'Y-m-d' ), $first->modify( 'last day of this month' )->format( 'Y-m-d' ) );
	}

	/** Giorni da una data all'altra, entrambe comprese. */
	public static function days_between( string $from, string $to ): int {
		$a = new \DateTimeImmutable( $from );
		$b = new \DateTimeImmutable( $to );
		return $b < $a ? 0 : (int) $a->diff( $b )->days + 1;
	}

	/** Quanto sta già dentro il preventivo annuale in proporzione ai mesi conclusi. */
	public static function planned_share( int $annual_cents, int $completed_months ): int {
		return (int) round( $annual_cents * max( 0, min( 12, $completed_months ) ) / 12 );
	}

	/**
	 * Come dividere una spesa tra più fondi, i più vecchi prima, ognuno al massimo per quanto gli manca.
	 *
	 * @param int   $cents    importo da attribuire
	 * @param array $capacity fund_id => centesimi ancora da coprire, già nell'ordine di precedenza
	 * @return array<int,int> fund_id => centesimi
	 */
	public static function split( int $cents, array $capacity ): array {
		$out = array();
		foreach ( $capacity as $fund_id => $room ) {
			if ( $cents <= 0 ) {
				break;
			}
			$part = min( $cents, max( 0, (int) $room ) );
			if ( $part > 0 ) {
				$out[ (int) $fund_id ] = $part;
				$cents                -= $part;
			}
		}
		return $out;
	}

	/** Ordine dei fondi candidati: prima quelli della stessa attività della spesa, poi i più vecchi. @param array[] $funds id, activity_id, created_at */
	public static function order_funds( array $funds, int $activity_id ): array {
		usort(
			$funds,
			function ( $a, $b ) use ( $activity_id ) {
				$sa = $activity_id && (int) $a['activity_id'] === $activity_id ? 0 : 1;
				$sb = $activity_id && (int) $b['activity_id'] === $activity_id ? 0 : 1;
				return array( $sa, (string) $a['created_at'], (int) $a['id'] ) <=> array( $sb, (string) $b['created_at'], (int) $b['id'] );
			}
		);
		return $funds;
	}

	const RECON_OK        = 'ok';
	const RECON_UNCOVERED = 'uncovered'; // rimborsato senza documentazione: da regolarizzare
	const RECON_UNPAID    = 'unpaid';    // documentato e non rimborsato: da rimborsare

	/**
	 * Quadratura per persona: rimborsato dai fondi contro spese documentate attribuite ai suoi fondi.
	 *
	 * @param array<int,int> $reimbursed person_id => centesimi rimborsati
	 * @param array<int,int> $documented person_id => centesimi documentati
	 * @param int            $tolerance  differenza (centesimi) sotto cui una persona risulta in pari
	 * @return array[] person_id, reimbursed, documented, diff (rimborsato − documentato), status; prima chi ha differenze più grandi
	 */
	public static function reconcile( array $reimbursed, array $documented, int $tolerance ): array {
		$rows = array();
		foreach ( array_unique( array_merge( array_keys( $reimbursed ), array_keys( $documented ) ) ) as $pid ) {
			$r      = (int) ( $reimbursed[ $pid ] ?? 0 );
			$d      = (int) ( $documented[ $pid ] ?? 0 );
			$diff   = $r - $d;
			$status = self::RECON_OK;
			if ( $diff > max( 0, $tolerance ) ) {
				$status = self::RECON_UNCOVERED;
			} elseif ( -$diff > max( 0, $tolerance ) ) {
				$status = self::RECON_UNPAID;
			}
			$rows[] = array( 'person_id' => (int) $pid, 'reimbursed' => $r, 'documented' => $d, 'diff' => $diff, 'status' => $status );
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return abs( $b['diff'] ) <=> abs( $a['diff'] ) ?: $a['person_id'] <=> $b['person_id'];
			}
		);
		return $rows;
	}

	/** Le persone con una differenza oltre la tolleranza, solo quelle da regolarizzare (rimborsato senza documenti) o anche quelle da rimborsare. */
	public static function gaps( array $rows, bool $only_uncovered = false ): array {
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $only_uncovered ) {
					return self::RECON_UNCOVERED === $r['status'] || ( ! $only_uncovered && self::RECON_UNPAID === $r['status'] );
				}
			)
		);
	}
}

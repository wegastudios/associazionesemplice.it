<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole delle risorse prenotabili, senza database: sovrapposizioni, fasce orarie, ospiti, dovuto.
 * Date e ore sono testi nel fuso del sito: «Y-m-d H:i:s» (si confrontano come testi) e «H:i».
 */
final class ResourceRules {

	const SLOT   = 'slot';   // a fasce orarie (campi, sale)
	const ASSIGN = 'assign'; // assegnazione a periodo (posti auto, armadietti, posti barca)

	const KIND_SLOT   = 'slot';
	const KIND_ASSIGN = 'assign';
	const KIND_BLOCK  = 'block';

	public static function categories(): array {
		return array(
			'court'   => 'Campo da gioco',
			'room'    => 'Sala',
			'berth'   => 'Posto barca',
			'parking' => 'Posto auto',
			'locker'  => 'Armadietto',
			'other'   => 'Altro',
		);
	}

	public static function modes(): array {
		return array(
			self::SLOT   => 'A fasce orarie (si prenota un orario)',
			self::ASSIGN => 'Assegnazione a periodo (a un socio per una stagione o un anno)',
		);
	}

	/** I due intervalli si sovrappongono? Una fine vuota vale «senza scadenza». Fine di uno = inizio dell'altro non è sovrapposizione. */
	public static function overlaps( string $a_start, ?string $a_end, string $b_start, ?string $b_end ): bool {
		return ( null === $b_end || $a_start < $b_end ) && ( null === $a_end || $b_start < $a_end );
	}

	/** Minuti dalla mezzanotte di un orario «H:i» (null se non valido). */
	public static function minutes( string $hhmm ): ?int {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	public static function hhmm( int $minutes ): string {
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * Controlla una richiesta a fasce e ne calcola inizio e fine.
	 *
	 * @param array  $res       risorsa: open_time, close_time, slot_minutes, min_slots, max_slots, advance_days
	 * @param string $date      Y-m-d
	 * @param string $start     H:i
	 * @param int    $slots     numero di fasce di seguito
	 * @param string $now       Y-m-d H:i:s (adesso, nel fuso del sito)
	 * @param bool   $backdate  true per la segreteria: ammesso anche nel passato e oltre l'anticipo
	 * @return array{0:string,1:string} [starts_at, ends_at]
	 * @throws \InvalidArgumentException
	 */
	public static function slot_range( array $res, string $date, string $start, int $slots, string $now, bool $backdate = false ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) ) {
			throw new \InvalidArgumentException( 'Data non valida.' );
		}
		$open  = self::minutes( (string) ( $res['open_time'] ?? '' ) );
		$close = self::minutes( (string) ( $res['close_time'] ?? '' ) );
		$step  = (int) ( $res['slot_minutes'] ?? 0 );
		$from  = self::minutes( $start );
		if ( null === $open || null === $close || $step < 5 || $close <= $open ) {
			throw new \InvalidArgumentException( 'La risorsa non ha orari di apertura validi.' );
		}
		if ( null === $from ) {
			throw new \InvalidArgumentException( 'Orario di inizio non valido.' );
		}
		$min = max( 1, (int) ( $res['min_slots'] ?? 1 ) );
		$max = max( $min, (int) ( $res['max_slots'] ?? $min ) );
		if ( $slots < $min || $slots > $max ) {
			throw new \InvalidArgumentException( $min === $max ? 'Si prenota per ' . $min . ' ' . ( 1 === $min ? 'fascia' : 'fasce' ) . '.' : 'Si prenotano da ' . $min . ' a ' . $max . ' fasce di seguito.' );
		}
		if ( $from < $open || ( $from - $open ) % $step !== 0 ) {
			throw new \InvalidArgumentException( 'L\'orario di inizio non corrisponde alle fasce della risorsa.' );
		}
		$to = $from + $slots * $step;
		if ( $to > $close ) {
			throw new \InvalidArgumentException( 'La prenotazione supera l\'orario di chiusura (' . self::hhmm( $close ) . ').' );
		}
		$starts = $date . ' ' . self::hhmm( $from ) . ':00';
		$ends   = $date . ' ' . self::hhmm( $to ) . ':00';
		if ( ! $backdate ) {
			if ( $starts < $now ) {
				throw new \InvalidArgumentException( 'Questa fascia è già iniziata o passata.' );
			}
			$days = (int) ( $res['advance_days'] ?? 0 );
			if ( $days > 0 && $date > gmdate( 'Y-m-d', strtotime( substr( $now, 0, 10 ) . ' +' . $days . ' days' ) ) ) {
				throw new \InvalidArgumentException( 'Si può prenotare al massimo ' . $days . ' ' . ( 1 === $days ? 'giorno' : 'giorni' ) . ' prima.' );
			}
		}
		return array( $starts, $ends );
	}

	/**
	 * Ospiti: nomi puliti e controllo del massimo ammesso dalla risorsa.
	 *
	 * @param string[] $names
	 * @return string[] nomi senza vuoti e senza doppi spazi
	 * @throws \InvalidArgumentException
	 */
	public static function guests( int $max, array $names ): array {
		$out = array();
		foreach ( $names as $n ) {
			$n = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $n ) ) );
			if ( '' !== $n ) {
				$out[] = function_exists( 'mb_substr' ) ? mb_substr( $n, 0, 120 ) : substr( $n, 0, 120 );
			}
		}
		if ( $out && $max <= 0 ) {
			throw new \InvalidArgumentException( 'Questa risorsa non ammette ospiti.' );
		}
		if ( count( $out ) > $max ) {
			throw new \InvalidArgumentException( 'Questa risorsa ammette al massimo ' . $max . ( 1 === $max ? ' ospite' : ' ospiti' ) . '.' );
		}
		return $out;
	}

	/** Dovuto: la tariffa per ogni fascia (o il canone del periodo) più il supplemento di ogni ospite. */
	public static function fee( array $res, int $slots, int $guests ): int {
		$units = self::ASSIGN === ( $res['mode'] ?? self::SLOT ) ? 1 : max( 1, $slots );
		return max( 0, (int) ( $res['fee_cents'] ?? 0 ) ) * $units + max( 0, (int) ( $res['guest_fee_cents'] ?? 0 ) ) * max( 0, $guests );
	}

	/** Le fasce di un giorno: [ ['start'=>'18:00','end'=>'19:00'], … ]. Vuoto se gli orari non sono validi. */
	public static function day_slots( array $res ): array {
		$open  = self::minutes( (string) ( $res['open_time'] ?? '' ) );
		$close = self::minutes( (string) ( $res['close_time'] ?? '' ) );
		$step  = (int) ( $res['slot_minutes'] ?? 0 );
		$out   = array();
		if ( null === $open || null === $close || $step < 5 ) {
			return $out;
		}
		for ( $t = $open; $t + $step <= $close; $t += $step ) {
			$out[] = array( 'start' => self::hhmm( $t ), 'end' => self::hhmm( $t + $step ) );
		}
		return $out;
	}
}

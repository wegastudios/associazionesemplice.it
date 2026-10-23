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

	/**
	 * Dovuto: la tariffa per ogni fascia (o il canone del periodo) più il supplemento di ogni ospite.
	 * Con l'inizio della prenotazione si applicano le tariffe a fasce (feriale/weekend, diurno/serale) della risorsa, se ce ne sono.
	 */
	public static function fee( array $res, int $slots, int $guests, ?string $starts_at = null ): int {
		$extra = max( 0, (int) ( $res['guest_fee_cents'] ?? 0 ) ) * max( 0, $guests );
		if ( self::ASSIGN === ( $res['mode'] ?? self::SLOT ) ) {
			return max( 0, (int) ( $res['fee_cents'] ?? 0 ) ) + $extra;
		}
		if ( null !== $starts_at ) {
			return self::slot_price( $res, $starts_at, max( 1, $slots ) ) + $extra;
		}
		return max( 0, (int) ( $res['fee_cents'] ?? 0 ) ) * max( 1, $slots ) + $extra;
	}

	const DAYS_ALL      = 'all';
	const DAYS_WEEKDAYS = 'weekdays'; // lunedì–venerdì
	const DAYS_WEEKEND  = 'weekend';  // sabato e domenica

	/** Parole usate nel modulo per i giorni delle tariffe. */
	public static function rate_words(): array {
		return array( 'tutti' => self::DAYS_ALL, 'feriali' => self::DAYS_WEEKDAYS, 'weekend' => self::DAYS_WEEKEND );
	}

	/**
	 * Tariffe a fasce dal testo del modulo, una per riga: «feriali 18:00-22:00 15,00», «weekend 08:00-22:00 20».
	 * Vale la prima riga che corrisponde a giorno e ora della fascia; fuori da tutte vale la tariffa base della risorsa.
	 *
	 * @return array[] days, from, to, fee_cents
	 * @throws \InvalidArgumentException
	 */
	public static function parse_rates( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^(tutti|feriali|weekend)\s+((?:[01]\d|2[0-3]):[0-5]\d)\s*-\s*((?:[01]\d|2[0-3]):[0-5]\d)\s+(\d+(?:[.,]\d{1,2})?)\s*€?$/u', $line, $m ) ) {
				throw new \InvalidArgumentException( 'Tariffe a fasce, riga ' . ( $n + 1 ) . ': scrivi ad esempio «feriali 18:00-22:00 15,00» (giorni: tutti, feriali, weekend).' );
			}
			if ( $m[2] >= $m[3] ) {
				throw new \InvalidArgumentException( 'Tariffe a fasce, riga ' . ( $n + 1 ) . ': l\'ora di fine deve essere dopo quella di inizio.' );
			}
			$out[] = array( 'days' => self::rate_words()[ $m[1] ], 'from' => $m[2], 'to' => $m[3], 'fee_cents' => (int) round( (float) str_replace( ',', '.', $m[4] ) * 100 ) );
		}
		if ( count( $out ) > 20 ) {
			throw new \InvalidArgumentException( 'Al massimo 20 tariffe a fasce.' );
		}
		return $out;
	}

	/** Le tariffe a fasce come testo del modulo (l'inverso di {@see parse_rates}). */
	public static function rates_text( array $bands ): string {
		$words = array_flip( self::rate_words() );
		$lines = array();
		foreach ( $bands as $b ) {
			$c       = (int) $b['fee_cents'];
			$lines[] = ( $words[ $b['days'] ] ?? 'tutti' ) . ' ' . $b['from'] . '-' . $b['to'] . ' ' . intdiv( $c, 100 ) . ',' . sprintf( '%02d', $c % 100 );
		}
		return implode( "\n", $lines );
	}

	/** Tariffe a fasce memorizzate (JSON) in un array sicuro; testo non valido = nessuna fascia. */
	public static function clean_rates( $stored ): array {
		$list = is_array( $stored ) ? $stored : json_decode( (string) $stored, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( is_array( $b ) && isset( $b['days'], $b['from'], $b['to'], $b['fee_cents'] ) && in_array( $b['days'], array( self::DAYS_ALL, self::DAYS_WEEKDAYS, self::DAYS_WEEKEND ), true )
				&& null !== self::minutes( (string) $b['from'] ) && null !== self::minutes( (string) $b['to'] ) ) {
				$out[] = array( 'days' => $b['days'], 'from' => (string) $b['from'], 'to' => (string) $b['to'], 'fee_cents' => max( 0, (int) $b['fee_cents'] ) );
			}
		}
		return $out;
	}

	/** Prezzo di una fascia che comincia a $starts_at (Y-m-d H:i:s): la prima tariffa a fasce che corrisponde, altrimenti la base. */
	public static function band_fee( array $res, string $starts_at ): int {
		$d   = new \DateTimeImmutable( $starts_at );
		$dow = (int) $d->format( 'N' );
		$min = self::minutes( $d->format( 'H:i' ) );
		foreach ( self::clean_rates( $res['rates'] ?? '' ) as $b ) {
			$day_ok = self::DAYS_ALL === $b['days'] || ( self::DAYS_WEEKDAYS === $b['days'] && $dow <= 5 ) || ( self::DAYS_WEEKEND === $b['days'] && $dow >= 6 );
			if ( $day_ok && $min >= self::minutes( $b['from'] ) && $min < self::minutes( $b['to'] ) ) {
				return $b['fee_cents'];
			}
		}
		return max( 0, (int) ( $res['fee_cents'] ?? 0 ) );
	}

	/** Prezzo di $slots fasce di seguito a partire da $starts_at, ognuna alla sua tariffa. */
	public static function slot_price( array $res, string $starts_at, int $slots ): int {
		$step  = max( 1, (int) ( $res['slot_minutes'] ?? 60 ) );
		$start = new \DateTimeImmutable( $starts_at );
		$sum   = 0;
		for ( $i = 0; $i < $slots; $i++ ) {
			$sum += self::band_fee( $res, $start->modify( '+' . ( $i * $step ) . ' minutes' )->format( 'Y-m-d H:i:s' ) );
		}
		return $sum;
	}

	const PAY_DESK    = 'desk';    // si paga in segreteria
	const PAY_UPFRONT = 'upfront'; // si paga subito, online: la prenotazione vale solo se pagata
	const PAY_CHOICE  = 'choice';  // sceglie il socio: online subito o in segreteria

	/** Minuti in cui una fascia resta riservata mentre il socio completa il pagamento online (non è una tolleranza: scaduti, si libera). */
	const HOLD_MINUTES = 15;

	public static function pay_modes(): array {
		return array(
			self::PAY_DESK    => 'In segreteria (il socio non paga online)',
			self::PAY_UPFRONT => 'In anticipo, online (la prenotazione vale solo se pagata)',
			self::PAY_CHOICE  => 'A scelta del socio: online subito o in segreteria',
		);
	}

	/**
	 * Come può pagare il socio una prenotazione, secondo la regola della risorsa. Vuoto = non si può prenotare (pagamento in anticipo ma
	 * senza pagamenti online né crediti). Una prenotazione gratuita non ha pagamento. Se la risorsa si può pagare in crediti, i crediti
	 * sono sempre una via in più (e la prima: si pagano subito, senza gateway).
	 *
	 * @param int $credit_cost crediti per fascia (0 = non si paga in crediti, o la funzione non c'è)
	 * @return string[] 'free', 'credits', 'desk' (in segreteria) e/o 'online'
	 */
	public static function pay_options( string $mode, bool $online_available, int $fee_cents, int $credit_cost = 0 ): array {
		if ( $fee_cents <= 0 ) {
			return array( 'free' );
		}
		switch ( $mode ) {
			case self::PAY_UPFRONT:
				$base = $online_available ? array( 'online' ) : array();
				break;
			case self::PAY_CHOICE:
				$base = $online_available ? array( 'online', 'desk' ) : array( 'desk' );
				break;
			default:
				$base = array( 'desk' );
		}
		return $credit_cost > 0 ? array_merge( array( 'credits' ), $base ) : $base;
	}

	// ---------- Allestimento: il tempo in cui la risorsa non è disponibile prima e dopo un'attività ----------

	/** Allestimento previsto per un evento se non si indica altro: 3 ore prima e 3 dopo. I corsi non ne hanno di loro. */
	const SETUP_EVENT_MINUTES = 180;

	/** Il massimo di un allestimento: 24 ore. */
	const SETUP_MAX_MINUTES = 1440;

	/**
	 * Una durata scritta in modo naturale, in minuti: «45», «20 minuti», «mezz'ora», «1,5 ore», «3h», «1h30», «1 ora e 30». Vuoto = nessuna indicazione.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse_duration( string $text ): ?int {
		$t = strtolower( trim( $text ) );
		if ( '' === $t ) {
			return null;
		}
		$t = str_replace( array( "mezz'ora", 'mezz’ora', 'mezzora', 'mezza ora' ), '0,5 ore', $t );
		$n = '(\d+(?:[.,]\d+)?)';
		$f = function ( string $v ): float {
			return (float) str_replace( ',', '.', $v );
		};
		$min = null;
		if ( preg_match( '/^(\d+)$/', $t, $m ) ) {
			$min = (int) $m[1];
		} elseif ( preg_match( '/^' . $n . '\s*(?:m|min|minuti|minuto)$/', $t, $m ) ) {
			$min = (int) round( $f( $m[1] ) );
		} elseif ( preg_match( '/^' . $n . '\s*(?:h|ore|ora)$/', $t, $m ) ) {
			$min = (int) round( $f( $m[1] ) * 60 );
		} elseif ( preg_match( '/^(\d+)\s*(?:h|ore|ora)\s*(?:e\s*)?(\d{1,2})\s*(?:m|min|minuti)?$/', $t, $m ) ) {
			$min = (int) $m[1] * 60 + (int) $m[2];
		}
		if ( null === $min ) {
			throw new \InvalidArgumentException( 'Durata non valida: scrivi ad esempio «20 minuti», «mezz\'ora», «3 ore» o «1h30».' );
		}
		if ( $min > self::SETUP_MAX_MINUTES ) {
			throw new \InvalidArgumentException( 'L\'allestimento può durare al massimo 24 ore.' );
		}
		return $min;
	}

	/** Una durata in minuti come testo: 0 → «nessuno», 20 → «20 minuti», 180 → «3 ore», 90 → «1 ora e 30 minuti». */
	public static function format_duration( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return 'nessuno';
		}
		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;
		$parts = array();
		if ( $h > 0 ) {
			$parts[] = $h . ( 1 === $h ? ' ora' : ' ore' );
		}
		if ( $m > 0 ) {
			$parts[] = $m . ( 1 === $m ? ' minuto' : ' minuti' );
		}
		return implode( ' e ', $parts );
	}

	/** Allestimento previsto per un tipo di attività quando non è indicato: eventi 3 ore, corsi niente. */
	public static function setup_default( string $kind ): int {
		return 'event' === $kind || 'recurring' === $kind ? self::SETUP_EVENT_MINUTES : 0;
	}

	/**
	 * Quanto tempo la risorsa resta indisponibile prima (o dopo) un'attività: l'esigenza della risorsa e quella dell'attività si sommano.
	 * Esempio: la risorsa vuole 20 minuti prima e 20 dopo, lo yoga ne vuole 30 dopo → 20 minuti prima e 50 dopo.
	 *
	 * @param int      $resource_minutes allestimento della risorsa (prima o dopo)
	 * @param ?int     $activity_minutes allestimento indicato per l'attività (null = quello previsto per il suo tipo)
	 */
	public static function setup_total( int $resource_minutes, ?int $activity_minutes, string $kind ): int {
		return max( 0, $resource_minutes ) + ( null !== $activity_minutes ? max( 0, $activity_minutes ) : self::setup_default( $kind ) );
	}

	/**
	 * La finestra in cui la risorsa è occupata da un'attività: dall'ora di inizio meno l'allestimento prima all'ora di fine più l'allestimento dopo.
	 * Senza orario l'attività occupa tutto il giorno.
	 *
	 * @return array{starts:string,ends:string,core_starts:string,core_ends:string} Y-m-d H:i:s; core_* = l'attività senza gli allestimenti
	 */
	public static function busy_window( string $date, ?string $start, ?string $end, int $before_min, int $after_min ): array {
		$s = $start ?: '00:00';
		$e = $end ?: '23:59';
		if ( $e <= $s ) {
			$e = '23:59';
		}
		$core_s = new \DateTimeImmutable( $date . ' ' . $s . ':00' );
		$core_e = new \DateTimeImmutable( $date . ' ' . $e . ':00' );
		return array(
			'starts'      => $core_s->modify( '-' . max( 0, $before_min ) . ' minutes' )->format( 'Y-m-d H:i:s' ),
			'ends'        => $core_e->modify( '+' . max( 0, $after_min ) . ' minutes' )->format( 'Y-m-d H:i:s' ),
			'core_starts' => $core_s->format( 'Y-m-d H:i:s' ),
			'core_ends'   => $core_e->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Le date di una ripetizione settimanale: la prima e le altre $count - 1 a distanza di una settimana. @return string[] Y-m-d */
	public static function series_dates( string $first, int $count ): array {
		$out = array();
		$d   = new \DateTimeImmutable( $first );
		for ( $i = 0; $i < max( 1, $count ); $i++ ) {
			$out[] = $d->modify( '+' . ( 7 * $i ) . ' days' )->format( 'Y-m-d' );
		}
		return $out;
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

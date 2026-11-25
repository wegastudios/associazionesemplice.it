<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Giorni senza lezione: festività nazionali (calcolate, nessun servizio esterno), patrono e chiusure dell'associazione.
 * Valgono solo per i corsi che lo scelgono (activities.red_days) e solo per le lezioni settimanali; una data unica l'ha scritta la segreteria apposta.
 */
final class RedDays {

	/** Chiusure al massimo, e giorni al massimo di una chiusura. */
	const MAX_CLOSURES = 60;
	const MAX_DAYS     = 400;

	/** Feste a data fissa (legge 27 maggio 1949, n. 260, e successive; il 4 ottobre dal 2026: legge 8 ottobre 2025, n. 151). */
	const FIXED = array(
		'01-01' => 'Capodanno',
		'01-06' => 'Epifania',
		'04-25' => 'Liberazione',
		'05-01' => 'Festa del lavoro',
		'06-02' => 'Festa della Repubblica',
		'08-15' => 'Ferragosto',
		'11-01' => 'Ognissanti',
		'12-08' => 'Immacolata',
		'12-25' => 'Natale',
		'12-26' => 'Santo Stefano',
	);

	/** La domenica di Pasqua (calendario gregoriano). */
	public static function easter( int $year ): string {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$n = $h + $l - 7 * $m + 114;
		return sprintf( '%04d-%02d-%02d', $year, intdiv( $n, 31 ), $n % 31 + 1 );
	}

	/** Le festività nazionali di un anno. @return array<string,string> data => nome */
	public static function national( int $year ): array {
		$out = array();
		foreach ( self::FIXED as $md => $label ) {
			$out[ $year . '-' . $md ] = $label;
		}
		if ( $year >= 2026 ) {
			$out[ $year . '-10-04' ] = 'San Francesco d\'Assisi';
		}
		$easter = new \DateTimeImmutable( self::easter( $year ) );
		$out[ $easter->format( 'Y-m-d' ) ]                       = 'Pasqua';
		$out[ $easter->modify( '+1 day' )->format( 'Y-m-d' ) ] = 'Lunedì dell\'Angelo';
		ksort( $out );
		return $out;
	}

	/** «29/6» → «06-29»; vuoto se non c'è. @throws \InvalidArgumentException */
	public static function clean_patron( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/^(\d{2})-(\d{2})$/', $text, $m ) && checkdate( (int) $m[1], (int) $m[2], 2024 ) ) {
			return $text; // già nel formato salvato
		}
		if ( ! preg_match( '#^(\d{1,2})[/.](\d{1,2})$#', $text, $m ) || ! checkdate( (int) $m[2], (int) $m[1], 2024 ) ) {
			throw new \InvalidArgumentException( 'Festa del patrono: scrivi giorno e mese, ad esempio 29/06.' );
		}
		return sprintf( '%02d-%02d', (int) $m[2], (int) $m[1] );
	}

	/**
	 * Chiusure dal testo, una per riga: «24/12/2026 - 06/01/2027 Vacanze di Natale» oppure «14/08/2026 Ponte».
	 *
	 * @return array[] from, to, note
	 * @throws \InvalidArgumentException
	 */
	public static function parse_closures( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = 'Chiusure, riga ' . ( $n + 1 ) . ': ';
			if ( ! preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s*[-–]\s*(\d{1,2})/(\d{1,2})/(\d{4}))?(?:\s+(.*))?$#u', $line, $m ) ) {
				throw new \InvalidArgumentException( $row . 'scrivi ad esempio «24/12/2026 - 06/01/2027 Vacanze di Natale».' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$ok = checkdate( (int) $m[2], (int) $m[1], (int) $m[3] );
			$f  = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
			$t  = $f;
			if ( ! empty( $m[6] ) ) {
				$ok = $ok && checkdate( (int) $m[5], (int) $m[4], (int) $m[6] );
				$t  = sprintf( '%04d-%02d-%02d', (int) $m[6], (int) $m[5], (int) $m[4] );
			}
			if ( ! $ok || $t < $f ) {
				throw new \InvalidArgumentException( $row . 'le date non sono valide.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			if ( (int) ( new \DateTimeImmutable( $f ) )->diff( new \DateTimeImmutable( $t ) )->days > self::MAX_DAYS ) {
				throw new \InvalidArgumentException( $row . 'il periodo è troppo lungo.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$out[] = array( 'from' => $f, 'to' => $t, 'note' => mb_substr( trim( str_replace( array( '<', '>' ), '', (string) ( $m[7] ?? '' ) ) ), 0, 80 ) );
		}
		if ( count( $out ) > self::MAX_CLOSURES ) {
			throw new \InvalidArgumentException( 'Al massimo ' . (int) self::MAX_CLOSURES . ' chiusure: togli quelle passate.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $out;
	}

	/** Le chiusure come testo del modulo (l'inverso di parse_closures). */
	public static function closures_text( array $closures ): string {
		$lines = array();
		foreach ( $closures as $c ) {
			$f       = ( new \DateTimeImmutable( $c['from'] ) )->format( 'd/m/Y' );
			$t       = ( new \DateTimeImmutable( $c['to'] ) )->format( 'd/m/Y' );
			$lines[] = trim( $f . ( $t === $f ? '' : ' - ' . $t ) . ' ' . $c['note'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Regola pura: i giorni senza lezione tra due date.
	 *
	 * @param string  $patron   «MM-GG» o vuoto
	 * @param array[] $closures from, to, note
	 * @return array<string,string> data => motivo
	 */
	public static function between( string $from, string $to, string $patron, array $closures ): array {
		$out = array();
		if ( $from > $to ) {
			return $out;
		}
		foreach ( $closures as $c ) {
			$d   = new \DateTimeImmutable( max( $from, (string) $c['from'] ) );
			$end = min( $to, (string) $c['to'] );
			while ( $d->format( 'Y-m-d' ) <= $end ) {
				$out[ $d->format( 'Y-m-d' ) ] = '' !== (string) $c['note'] ? (string) $c['note'] : 'Chiusura';
				$d = $d->modify( '+1 day' );
			}
		}
		for ( $y = (int) substr( $from, 0, 4 ); $y <= (int) substr( $to, 0, 4 ); $y++ ) {
			if ( '' !== $patron && checkdate( (int) substr( $patron, 0, 2 ), (int) substr( $patron, 3, 2 ), $y ) ) {
				$out[ $y . '-' . $patron ] = 'Festa del patrono';
			}
			$out = self::national( $y ) + $out;
		}
		foreach ( array_keys( $out ) as $d ) {
			if ( $d < $from || $d > $to ) {
				unset( $out[ $d ] );
			}
		}
		ksort( $out );
		return $out;
	}

	/** I giorni senza lezione tra due date, con le impostazioni dell'associazione. @return array<string,string> data => motivo */
	public static function in_range( string $from, string $to ): array {
		return self::between( $from, $to, (string) Settings::get( 'patron_day' ), self::closures() );
	}

	/** Le chiusure salvate (testo rovinato = nessuna). @return array[] */
	public static function closures(): array {
		try {
			return self::parse_closures( (string) Settings::get( 'closures' ) );
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}
	}

	/** Cambia quando cambiano patrono e chiusure: entra nelle chiavi delle letture ricordate. */
	public static function signature(): string {
		return md5( (string) Settings::get( 'patron_day' ) . '|' . (string) Settings::get( 'closures' ) );
	}
}

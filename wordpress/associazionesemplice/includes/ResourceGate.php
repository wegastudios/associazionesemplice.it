<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ingresso con il QR di una prenotazione di una risorsa (un campo, una sala con la porta chiusa…): il socio mostra il QR dalla sua area, lo staff
 * lo inquadra con la fotocamera e registra l'ingresso. Il QR contiene solo "prenotazione.persona.firma": la firma (HMAC) non si indovina né si
 * costruisce senza il segreto del sito. Regole senza database: firma e stato dell'ingresso.
 */
final class ResourceGate {

	/** Minuti prima dell'inizio da cui si può entrare. */
	const EARLY_MINUTES = 30;

	public static function make( int $reservation_id, int $person_id, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'gate:' . $reservation_id . ':' . $person_id, $secret ), 0, 20 );
	}

	/** "PRENOTAZIONE.PERSONA.firma": quello che sta nell'indirizzo del QR. */
	public static function param( int $reservation_id, int $person_id, string $secret ): string {
		return $reservation_id . '.' . $person_id . '.' . self::make( $reservation_id, $person_id, $secret );
	}

	public static function valid( int $reservation_id, int $person_id, string $token, string $secret ): bool {
		return '' !== $token && hash_equals( self::make( $reservation_id, $person_id, $secret ), $token );
	}

	/** @return array|null [prenotazione, persona, firma] oppure null se il formato non è giusto */
	public static function parse( string $value ): ?array {
		if ( ! preg_match( '/^(\d{1,9})\.(\d{1,9})\.([a-f0-9]{20})$/', trim( $value ), $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2], $m[3] );
	}

	/**
	 * A che punto è una prenotazione all'ingresso.
	 *
	 * @param string  $status     booked | cancelled
	 * @param ?string $checked_in quando è già entrato (null = non ancora)
	 * @param string  $now        Y-m-d H:i:s
	 * @return string 'ok' (si può entrare), 'used' (già entrato), 'early' (troppo presto), 'late' (finita), 'cancelled'
	 */
	public static function state( string $status, string $starts_at, ?string $ends_at, ?string $checked_in, string $now ): string {
		if ( 'booked' !== $status ) {
			return 'cancelled';
		}
		if ( null !== $checked_in && '' !== $checked_in ) {
			return 'used';
		}
		if ( $now < gmdate( 'Y-m-d H:i:s', strtotime( $starts_at . ' UTC' ) - self::EARLY_MINUTES * 60 ) ) {
			return 'early';
		}
		if ( null !== $ends_at && '' !== $ends_at && $now > $ends_at ) {
			return 'late';
		}
		return 'ok';
	}

	/** Il messaggio per ogni stato (il testo della fascia colorata, poi i dettagli). */
	public static function label( string $state ): string {
		switch ( $state ) {
			case 'ok':
				return 'Prenotazione valida';
			case 'used':
				return 'Già entrato';
			case 'early':
				return 'Troppo presto';
			case 'late':
				return 'Prenotazione scaduta';
			case 'cancelled':
				return 'Prenotazione annullata';
		}
		return 'Non valida';
	}

	/** Nome per chi non è dello staff: il nome e l'iniziale del cognome («Mario R.»). */
	public static function short_name( string $first, string $last ): string {
		$last = trim( $last );
		return trim( $first ) . ( '' !== $last ? ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : '' );
	}
}

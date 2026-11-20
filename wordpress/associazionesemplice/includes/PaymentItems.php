<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le "voci da pagare" di un pagamento online. Ogni voce è un importo dovuto da un partecipante (il socio o un suo ospite):
 *
 *  - membership    quota associativa dell'anno sociale           chiave  q:{persona}:{anno sociale}
 *  - course_month  mensilità (o quota) di un corso per un mese    chiave  m:{attività}:{persona}:{YYYY-MM}
 *  - booking       contributo di una prenotazione a un evento    chiave  b:{data}:{persona}
 *  - reservation   prenotazione di una risorsa (campo, sala…)    chiave  r:{prenotazione}:{persona}
 *  - credits       pacchetto di crediti da comprare              chiave  c:{persona}:{crediti}
 *
 * Il browser invia solo le CHIAVI scelte; importi e descrizioni si ricalcolano sempre sul server.
 */
final class PaymentItems {

	const MEMBERSHIP   = 'membership';
	const COURSE_MONTH = 'course_month';
	const BOOKING      = 'booking';
	const RESERVATION  = 'reservation';
	const CREDITS      = 'credits';
	const APPLICATION  = 'application';
	const FEE          = 'fee';          // commissione di incasso a carico di chi paga (solo se il metodo la ribalta)
	const DONATION     = 'donation';     // donazione aggiunta da chi paga (voce a sé, con ricevuta sua)

	/** Importo minimo accettato dai gateway (Stripe: 0,50 €). */
	const MIN_TOTAL_CENTS = 50;

	/** Identificativo pubblico di un pagamento: UUID versione 4 fatto con byte casuali del sistema operativo. */
	public static function new_public_id(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		$h    = bin2hex( $b );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	public static function key( array $i ): string {
		switch ( $i['type'] ) {
			case self::MEMBERSHIP:
				return 'q:' . (int) $i['person_id'] . ':' . $i['social_year'];
			case self::COURSE_MONTH:
				return 'm:' . (int) $i['activity_id'] . ':' . (int) $i['person_id'] . ':' . $i['month'];
			case self::RESERVATION:
				return 'r:' . (int) $i['reservation_id'] . ':' . (int) $i['person_id'];
			case self::APPLICATION:
				return 'a:' . (int) $i['person_id'] . ':' . $i['social_year'];
			case self::DONATION:
				return 'd:' . (int) $i['person_id'] . ':' . (int) $i['amount_cents'];
			case self::FEE:
				return 'f:' . (int) $i['person_id'];
			case self::CREDITS:
				return 'c:' . (int) $i['person_id'] . ':' . (int) $i['credits'];
			default:
				return 'b:' . (int) $i['session_id'] . ':' . (int) $i['person_id'];
		}
	}

	/** @return array|null ['type', 'person_id', + attività / data / mese / anno sociale], null se la chiave non è valida */
	public static function parse( string $key ): ?array {
		$p = explode( ':', $key );
		if ( 'q' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && preg_match( '#^\d{4}(/\d{4})?$#', $p[2] ) ) {
			return array( 'type' => self::MEMBERSHIP, 'person_id' => (int) $p[1], 'social_year' => $p[2] );
		}
		if ( 'm' === $p[0] && 4 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) && preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $p[3] ) ) {
			return array( 'type' => self::COURSE_MONTH, 'activity_id' => (int) $p[1], 'person_id' => (int) $p[2], 'month' => $p[3] );
		}
		if ( 'a' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && preg_match( '#^\d{4}(/\d{4})?$#', $p[2] ) ) {
			return array( 'type' => self::APPLICATION, 'person_id' => (int) $p[1], 'social_year' => $p[2] );
		}
		if ( 'c' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) ) {
			return array( 'type' => self::CREDITS, 'person_id' => (int) $p[1], 'credits' => (int) $p[2] );
		}
		if ( 'r' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) ) {
			return array( 'type' => self::RESERVATION, 'reservation_id' => (int) $p[1], 'person_id' => (int) $p[2] );
		}
		if ( 'b' === $p[0] && 3 === count( $p ) && ctype_digit( $p[1] ) && ctype_digit( $p[2] ) ) {
			return array( 'type' => self::BOOKING, 'session_id' => (int) $p[1], 'person_id' => (int) $p[2] );
		}
		return null;
	}

	/** Questa voce si incassa come erogazione liberale? Solo eventi e corsi pagati da un socio e solo con l'opzione fiscale accesa (la stessa regola della prima nota). */
	public static function is_donation( array $i, array $payer ): bool {
		if ( self::DONATION === (string) $i['type'] ) {
			return true; // la donazione aggiunta è sempre un'erogazione liberale
		}
		return in_array( (string) $i['type'], array( self::COURSE_MONTH, self::BOOKING ), true ) && Fiscal::member_events_donation() && MemberType::is_member( (string) ( $payer['type'] ?? '' ) );
	}

	public static function total( array $items ): int {
		return (int) array_sum( array_map( function ( $i ) {
			return (int) $i['amount_cents'];
		}, $items ) );
	}

	/** Voci raggruppate per partecipante: [person_id => [voci]]. */
	public static function group_by_person( array $items ): array {
		$out = array();
		foreach ( $items as $i ) {
			$out[ (int) $i['person_id'] ][] = $i;
		}
		return $out;
	}

	/** Voci da registrare insieme, una ricevuta per gruppo: per ogni persona le sue voci e, a parte, l'eventuale donazione. @return array<int,array{0:int,1:array}> */
	public static function receipt_batches( array $items ): array {
		$out = array();
		foreach ( self::group_by_person( $items ) as $person_id => $group ) {
			$gift = array_values( array_filter( $group, function ( $i ) {
				return self::DONATION === $i['type'];
			} ) );
			$rest = array_values( array_filter( $group, function ( $i ) {
				return self::DONATION !== $i['type'];
			} ) );
			if ( $rest ) {
				$out[] = array( (int) $person_id, $rest );
			}
			if ( $gift ) {
				$out[] = array( (int) $person_id, $gift );
			}
		}
		return $out;
	}

	/** 1250 => "12.50" (formato dei gateway). */
	public static function decimal( int $cents ): string {
		return intdiv( $cents, 100 ) . '.' . sprintf( '%02d', $cents % 100 );
	}

	/** Testo breve per la riga di un gateway o per la ricevuta. */
	public static function line_name( array $i ): string {
		$name = trim( (string) ( $i['label'] ?? '' ) );
		$who  = trim( (string) ( $i['person_name'] ?? '' ) );
		$txt  = '' !== $who ? $name . ' — ' . $who : $name;
		return mb_substr( '' !== $txt ? $txt : 'Pagamento', 0, 120 );
	}

	/** Descrizione complessiva del pagamento. */
	public static function summary( array $items, string $association = '' ): string {
		$n   = count( $items );
		$txt = 1 === $n ? self::line_name( $items[0] ) : $n . ' voci';
		return mb_substr( ( '' !== $association ? $association . ': ' : '' ) . $txt, 0, 120 );
	}
}

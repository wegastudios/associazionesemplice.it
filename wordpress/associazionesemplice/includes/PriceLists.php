<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Listini di un corso o di un evento (individuale, di gruppo, pacchetto da 4…) e prenotazioni «ospite» della segreteria.
 *
 * Un listino ha un nome, un prezzo e il numero di ingressi che copre (1 = singolo, 4 = pacchetto da 4). Senza listini vale il contributo base.
 * L'ospite dell'associazione non paga la parte che resta all'associazione; l'ospite del volontario non paga la parte del volontario
 * (e il suo fondo non si alimenta). Le due parti derivano dalla quota del referente impostata sull'attività.
 */
final class PriceLists {

	const GUEST_ASSOC     = 'association';
	const GUEST_VOLUNTEER = 'volunteer';
	const MAX             = 8;

	public static function guest_kinds(): array {
		return array(
			''                    => 'Nessuno (paga il listino)',
			self::GUEST_ASSOC     => 'Ospite dell\'associazione',
			self::GUEST_VOLUNTEER => 'Ospite del volontario',
		);
	}

	/** Righe dal modulo o dal database → elenco pulito [{key,label,cents,entries}]. @param mixed $raw */
	public static function clean( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$label = mb_substr( trim( (string) ( $r['label'] ?? '' ) ), 0, 60 );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( $r['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = substr( sanitize_key( str_replace( ' ', '_', $label ) ), 0, 30 ); // chiave stabile dal nome: le prenotazioni la ricordano
			}
			if ( '' === $key ) {
				$key = 'l' . ( count( $out ) + 1 );
			}
			while ( isset( $out[ $key ] ) ) {
				$key .= 'x';
			}
			$out[ $key ] = array(
				'key'     => $key,
				'label'   => $label,
				'cents'   => max( 0, (int) ( $r['cents'] ?? 0 ) ),
				'entries' => max( 1, min( 50, (int) ( $r['entries'] ?? 1 ) ) ),
			);
		}
		return array_slice( array_values( $out ), 0, self::MAX );
	}

	public static function encode( array $lists ): ?string {
		$lists = self::clean( $lists );
		return $lists ? (string) wp_json_encode( $lists ) : null;
	}

	/** Listini di un'attività (vuoto = solo contributo base). */
	public static function for_activity( array $activity ): array {
		return self::clean( $activity['price_lists'] ?? null );
	}

	public static function find( array $activity, string $key ): ?array {
		foreach ( self::for_activity( $activity ) as $l ) {
			if ( $l['key'] === $key ) {
				return $l;
			}
		}
		return null;
	}

	/** Prezzo lordo di un listino e quanti ingressi copre. @return array{0:int,1:int} */
	public static function price( array $list ): array {
		return array( (int) $list['cents'], (int) $list['entries'] );
	}

	/**
	 * Parti di un prezzo: quella del volontario (secondo la quota dell'attività) e quella che resta all'associazione.
	 *
	 * @return array{volunteer:int,association:int}
	 */
	public static function split( array $activity, int $price ): array {
		$v = empty( $activity['instructor_person_id'] ) ? 0 : FundShare::cents( (string) ( $activity['fund_mode'] ?? '' ), (int) ( $activity['fund_value'] ?? 0 ), $price );
		return array( 'volunteer' => $v, 'association' => max( 0, $price - $v ) );
	}

	/**
	 * Importo da pagare e parte condonata per un ospite.
	 *
	 * @return array{due:int,waived:int}
	 */
	public static function guest_due( array $activity, int $price, string $guest_of ): array {
		$s = self::split( $activity, $price );
		if ( self::GUEST_ASSOC === $guest_of ) {
			return array( 'due' => $s['volunteer'], 'waived' => $s['association'] );
		}
		if ( self::GUEST_VOLUNTEER === $guest_of ) {
			return array( 'due' => $s['association'], 'waived' => $s['volunteer'] );
		}
		return array( 'due' => $price, 'waived' => 0 );
	}

	/** Quota da accantonare nel fondo del volontario per un incasso di una prenotazione (null = vale la regola normale). */
	public static function fund_override( ?array $booking, int $line_cents ): ?int {
		$guest = (string) ( $booking['guest_of'] ?? '' );
		if ( self::GUEST_VOLUNTEER === $guest ) {
			return 0; // ospita il volontario: nessuna quota a suo fondo
		}
		if ( self::GUEST_ASSOC === $guest ) {
			return max( 0, $line_cents ); // quanto paga è già solo la parte del volontario
		}
		return null;
	}

	/** Etichetta breve di una prenotazione («Pacchetto 4 · ospite dell'associazione»). */
	public static function booking_label( array $activity, array $b ): string {
		$parts = array();
		if ( ! empty( $b['price_list'] ) ) {
			$l = self::find( $activity, (string) $b['price_list'] );
			if ( $l ) {
				$parts[] = $l['label'];
			}
		}
		if ( ! empty( $b['package_of'] ) ) {
			$parts[] = 'dal pacchetto';
		}
		$g = (string) ( $b['guest_of'] ?? '' );
		if ( '' !== $g && isset( self::guest_kinds()[ $g ] ) ) {
			$parts[] = lcfirst( self::guest_kinds()[ $g ] );
		}
		return implode( ' · ', $parts );
	}
}

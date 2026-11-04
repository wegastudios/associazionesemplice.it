<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Promemoria alla segreteria (certificati medici, affiliazioni, tessere degli enti): un solo messaggio con l'elenco, e ogni riga una sola volta per
 * tipo e riferimento. Gli invii si ricordano nella stessa tabella dei promemoria ai soci, tramite {@see Reminders}.
 */
final class StaffReminders {

	/** Prenota l'invio di una riga (persona 0 se non riguarda un socio). @return bool true se la riga è nuova */
	public static function claim( int $person_id, string $kind, string $ref ): bool {
		return Reminders::claim( $person_id, $kind, $ref );
	}

	/** L'email non è partita: la riga torna in coda per la prossima esecuzione. */
	public static function release( int $person_id, string $kind, string $ref ): void {
		Reminders::release( $person_id, $kind, $ref );
	}

	/** La riga è già stata mandata? */
	public static function seen( int $person_id, string $kind, string $ref ): bool {
		return Reminders::sent( $person_id, $kind, $ref );
	}

	/** Prenota la riga se si invia davvero; senza invio dice solo se sarebbe nuova. */
	public static function fresh( int $person_id, string $kind, string $ref, bool $send ): bool {
		return $send ? self::claim( $person_id, $kind, $ref ) : ! self::seen( $person_id, $kind, $ref );
	}

	/** Messaggio alla segreteria (stesso indirizzo degli avvisi di nuova adesione). */
	public static function mail( string $subject, string $text ): bool {
		$assoc = (string) Settings::get( 'association_name' );
		return (bool) Texts::mail( Applications::notify_email(), ( '' !== $assoc ? '[' . $assoc . '] ' : '' ) . $subject, $text );
	}

	/**
	 * Manda l'elenco alla segreteria; se l'email non parte le righe tornano in coda.
	 *
	 * @param string[]                    $lines  testo di ogni riga
	 * @param array<int,array{0:int,1:string,2:string}> $keys persona, tipo e riferimento di ogni riga
	 * @return int righe mandate (o, senza invio, da mandare)
	 */
	public static function digest( string $subject, string $intro, string $outro, array $lines, array $keys, bool $send ): int {
		if ( ! $lines ) {
			return 0;
		}
		if ( ! $send ) {
			return count( $lines );
		}
		if ( self::mail( $subject, $intro . "\n\n- " . implode( "\n- ", $lines ) . "\n\n" . $outro ) ) {
			return count( $lines );
		}
		foreach ( $keys as $k ) {
			self::release( $k[0], $k[1], $k[2] );
		}
		return 0;
	}
}

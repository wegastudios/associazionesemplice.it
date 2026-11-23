<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure dell'avviso «da invitare ad associarsi» che nasce quando si segna l'ingresso di un ospite non socio (senza WordPress: si provano con i test unitari).
 * L'avviso non è un blocco e non manda email: lo vede chi registra l'ingresso e resta tra i «Da fare» della Bacheca finché lo staff non lo segna come fatto.
 */
final class GuestInviteRules {

	/** Separatore tra l'esito dell'azione e l'avviso, dentro lo stesso messaggio firmato (le pagine lo dividono per evidenziare l'avviso). */
	const SEP = ' ⚑ ';

	/** Avvisi tenuti in coda: i più recenti. */
	const MAX = 200;

	/**
	 * I motivi per cui l'ospite è da invitare, nell'ordine in cui si scrivono.
	 *
	 * @param bool   $is_guest ospite non socio
	 * @param int    $total    partecipazioni dell'ospite (gemelli compresi, come per la soglia)
	 * @param int    $limit    soglia di segnalazione (0 = nessuna)
	 * @param string $host     chi lo ha invitato (vuoto se nessuno)
	 * @param string $event    nome dell'evento a cui entra
	 * @return array<int,array<string,mixed>> code: threshold (n), host (who), event (name)
	 */
	public static function reasons( bool $is_guest, int $total, int $limit, string $host, string $event ): array {
		if ( ! $is_guest ) {
			return array();
		}
		$out = array();
		if ( $limit > 0 && $total >= $limit ) {
			$out[] = array( 'code' => 'threshold', 'n' => $total );
		}
		if ( '' !== trim( $host ) ) {
			$out[] = array( 'code' => 'host', 'who' => trim( $host ) );
		}
		if ( '' !== trim( $event ) ) {
			$out[] = array( 'code' => 'event', 'name' => trim( $event ) );
		}
		return $out;
	}

	/** Messaggio dell'azione con l'avviso in coda (senza avviso resta com'è). */
	public static function join( string $message, string $notice ): string {
		return '' === $notice ? $message : $message . self::SEP . $notice;
	}

	/** @return array{0:string,1:string} esito, avviso (vuoto se non c'è) */
	public static function split( string $message ): array {
		$pos = strpos( $message, self::SEP );
		return false === $pos ? array( $message, '' ) : array( substr( $message, 0, $pos ), substr( $message, $pos + strlen( self::SEP ) ) );
	}

	/** Codice stabile dell'avviso per una persona a una data: lo stesso ingresso non crea due voci. */
	public static function notice_id( int $person_id, int $session_id ): string {
		return substr( md5( 'invite|' . $person_id . '|' . $session_id ), 0, 12 );
	}

	/**
	 * Tiene i più recenti.
	 *
	 * @param array<string,array{at:int}> $all
	 * @return array<string,array>
	 */
	public static function trim( array $all, int $max ): array {
		uasort(
			$all,
			function ( $a, $b ) {
				return (int) $b['at'] <=> (int) $a['at'];
			}
		);
		return array_slice( $all, 0, max( 1, $max ), true );
	}
}

<?php
/**
 * Limite di frequenza per socio nell'area soci: prenota, annulla, profilo, PDF, codice della tessera.
 * Finestra fissa di 10 minuti per scheda; la segreteria (che lavora dal pannello) non passa da qui.
 *
 * @package AssociazioneSemplice
 */

namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class MemberThrottle {

	const WINDOW = 600; // secondi

	/**
	 * Conta un'azione del socio e la rifiuta se ne ha già fatte troppe in questa finestra.
	 *
	 * @param string $bucket    gruppo di azioni (prenota, annulla, profilo, pdf, qr)
	 * @param int    $person_id scheda del socio
	 * @param string $limit_key chiave del limite in Limiti
	 * @throws \InvalidArgumentException
	 */
	public static function hit( string $bucket, int $person_id, string $limit_key, int $weight = 1 ): void {
		self::strike( 'asem_mt_' . $bucket . '_' . $person_id, Limits::get( $limit_key ), self::WINDOW, $weight );
	}

	/**
	 * Contatore a finestra fissa: rifiuta se con questo peso si supera il limite (le azioni che pesano di più, come prenotare più persone, contano di più).
	 * Con $weight 0 controlla soltanto.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function strike( string $key, int $limit, int $window, int $weight = 1 ): void {
		$now = time();
		$v   = Counters::get( $key );
		if ( ! is_array( $v ) || ! isset( $v[0], $v[1] ) || (int) $v[1] + $window <= $now ) {
			$v = array( 0, $now );
		}
		if ( (int) $v[0] >= $limit || ( $weight > 0 && (int) $v[0] + $weight > $limit ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Hai fatto troppe richieste in poco tempo: riprova tra qualche minuto.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $weight > 0 ) {
			Counters::set( $key, array( (int) $v[0] + $weight, (int) $v[1] ), max( 1, (int) $v[1] + $window - $now ) );
		}
	}
}

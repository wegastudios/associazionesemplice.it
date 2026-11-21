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
	public static function hit( string $bucket, int $person_id, string $limit_key ): void {
		$key = 'asem_mt_' . $bucket . '_' . $person_id;
		$now = time();
		$v   = get_transient( $key );
		if ( ! is_array( $v ) || ! isset( $v[0], $v[1] ) || (int) $v[1] + self::WINDOW <= $now ) {
			$v = array( 0, $now );
		}
		if ( (int) $v[0] >= Limits::get( $limit_key ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Hai fatto troppe richieste in poco tempo: riprova tra qualche minuto.' ) );
		}
		set_transient( $key, array( (int) $v[0] + 1, (int) $v[1] ), max( 1, (int) $v[1] + self::WINDOW - $now ) );
	}
}

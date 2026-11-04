<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole dei crediti senza database: pacchetti in vendita, stato di un addebito dal vivo, saldo sufficiente. Specifica: docs/CREDITI.md.
 */
final class CreditRules {

	const CHARGE_PENDING   = 'pending';
	const CHARGE_PAID      = 'paid';
	const CHARGE_CANCELLED = 'cancelled';

	/** Minuti di validità del QR di un addebito dal vivo. */
	const CHARGE_MINUTES = 5;

	/** Crediti massimi di un movimento o di un pacchetto (un errore di battitura non deve diventare una cifra enorme). */
	const MAX_CREDITS = 100000;

	/**
	 * Pacchetti dal testo del modulo, uno per riga: «10 = 10,00» (crediti = prezzo in euro). Ordinati per crediti.
	 *
	 * @return array[] credits, price_cents
	 * @throws \InvalidArgumentException
	 */
	public static function parse_packs( string $text ): array {
		$out  = array();
		$seen = array();
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^(\d{1,6})\s*(?:crediti|credito)?\s*[=:]\s*(\d+(?:[.,]\d{1,2})?)\s*€?$/iu', $line, $m ) ) {
				throw new \InvalidArgumentException( 'Pacchetti, riga ' . ( $n + 1 ) . ': scrivi ad esempio «10 = 10,00» (crediti = prezzo in euro).' );
			}
			$credits = (int) $m[1];
			$price   = (int) round( (float) str_replace( ',', '.', $m[2] ) * 100 );
			if ( $credits < 1 || $credits > self::MAX_CREDITS ) {
				throw new \InvalidArgumentException( 'Pacchetti, riga ' . ( $n + 1 ) . ': i crediti vanno da 1 a ' . self::MAX_CREDITS . '.' );
			}
			if ( $price < 1 ) {
				throw new \InvalidArgumentException( 'Pacchetti, riga ' . ( $n + 1 ) . ': il prezzo deve essere maggiore di zero.' );
			}
			if ( isset( $seen[ $credits ] ) ) {
				throw new \InvalidArgumentException( 'Pacchetti, riga ' . ( $n + 1 ) . ': c\'è già un pacchetto da ' . $credits . ' crediti.' );
			}
			$seen[ $credits ] = true;
			$out[]            = array( 'credits' => $credits, 'price_cents' => $price );
		}
		if ( count( $out ) > 12 ) {
			throw new \InvalidArgumentException( 'Al massimo 12 pacchetti.' );
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['credits'] <=> $b['credits'];
			}
		);
		return $out;
	}

	/** I pacchetti come testo del modulo (l'inverso di {@see parse_packs}). */
	public static function packs_text( array $packs ): string {
		$lines = array();
		foreach ( $packs as $p ) {
			$c       = (int) $p['price_cents'];
			$lines[] = (int) $p['credits'] . ' = ' . intdiv( $c, 100 ) . ',' . sprintf( '%02d', $c % 100 );
		}
		return implode( "\n", $lines );
	}

	/** Pacchetti memorizzati (JSON o array) in un array sicuro, ordinato per crediti; testo non valido = nessun pacchetto. */
	public static function clean_packs( $stored ): array {
		$list = is_array( $stored ) ? $stored : json_decode( (string) $stored, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			if ( is_array( $p ) && isset( $p['credits'], $p['price_cents'] ) && (int) $p['credits'] >= 1 && (int) $p['credits'] <= self::MAX_CREDITS && (int) $p['price_cents'] >= 1 ) {
				$out[ (int) $p['credits'] ] = array( 'credits' => (int) $p['credits'], 'price_cents' => (int) $p['price_cents'] );
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Cosa si può fare con un addebito dal vivo in questo momento.
	 *
	 * @param array  $charge status, expires_at (Y-m-d H:i:s)
	 * @param string $now    Y-m-d H:i:s
	 * @return string 'ok' | 'paid' | 'cancelled' | 'expired'
	 */
	public static function charge_state( array $charge, string $now ): string {
		switch ( (string) ( $charge['status'] ?? '' ) ) {
			case self::CHARGE_PAID:
				return 'paid';
			case self::CHARGE_CANCELLED:
				return 'cancelled';
		}
		return (string) ( $charge['expires_at'] ?? '' ) >= $now ? 'ok' : 'expired';
	}

	/** Il messaggio per chi prova a pagare un addebito che non si può più pagare. */
	public static function charge_message( string $state ): string {
		switch ( $state ) {
			case 'paid':
				return 'Questo addebito è già stato pagato.';
			case 'cancelled':
				return 'Questo addebito è stato annullato.';
			case 'expired':
				return 'Questo QR è scaduto: chiedi allo staff di mostrarne uno nuovo.';
		}
		return '';
	}

	/** Crediti dovuti per una cosa che costa $unit crediti per ciascuna di $units (fasce, partecipanti…). */
	public static function cost( int $unit, int $units ): int {
		return max( 0, $unit ) * max( 1, $units );
	}

	/** Il codice casuale di un addebito: 24 caratteri esadecimali. */
	public static function is_code( string $code ): bool {
		return 1 === preg_match( '/^[a-f0-9]{24}$/', $code );
	}
}

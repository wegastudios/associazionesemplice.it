<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Scadenze con preavviso (certificati medici, affiliazioni, tessere degli enti): stato a una data. Logica pura, senza WordPress. */
final class ExpiryRules {

	const VALID    = 'valid';
	const EXPIRING = 'expiring';
	const EXPIRED  = 'expired';
	const NONE     = 'none';

	const DEFAULT_WARN = 30;
	const MIN_WARN     = 1;
	const MAX_WARN     = 365;

	public static function is_date( string $v ): bool {
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v;
	}

	/** Giorni di preavviso: un numero tra 1 e 365 (altrimenti 30). */
	public static function warn_days( $v ): int {
		$n = (int) $v;
		return $n < self::MIN_WARN || $n > self::MAX_WARN ? self::DEFAULT_WARN : $n;
	}

	/** Giorni da oggi alla scadenza (negativi se già scaduta). */
	public static function days_left( string $expires_on, string $today ): int {
		return (int) round( ( strtotime( $expires_on . ' 12:00:00 UTC' ) - strtotime( $today . ' 12:00:00 UTC' ) ) / 86400 );
	}

	/** Valida fino al giorno di scadenza compreso; in scadenza negli ultimi $warn giorni; senza data = nessuna. */
	public static function status( ?string $expires_on, string $today, int $warn ): string {
		if ( null === $expires_on || '' === $expires_on ) {
			return self::NONE;
		}
		$left = self::days_left( $expires_on, $today );
		if ( $left < 0 ) {
			return self::EXPIRED;
		}
		return $left <= $warn ? self::EXPIRING : self::VALID;
	}

	/** Etichette dello stato (la scheda e gli elenchi). */
	public static function labels(): array {
		return array( self::VALID => 'Valido', self::EXPIRING => 'In scadenza', self::EXPIRED => 'Scaduto', self::NONE => 'Mancante' );
	}

	/** Un anno dopo la data (suggerimento per la scadenza: i certificati e le affiliazioni durano di solito un anno). */
	public static function plus_year( string $ymd ): string {
		return ( new \DateTimeImmutable( $ymd ) )->modify( '+1 year' )->format( 'Y-m-d' );
	}

	/** Data in giorno/mese/anno. */
	public static function it_date( string $ymd ): string {
		return ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' );
	}
}

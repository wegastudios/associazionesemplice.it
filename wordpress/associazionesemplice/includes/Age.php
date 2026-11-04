<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Età e data di nascita: regole pure (nessun accesso al database), testabili senza WordPress. */
final class Age {

	const ADULT_AT = 18;

	/** La data è scritta come AAAA-MM-GG ed esiste davvero? */
	public static function is_valid_date( string $ymd ): bool {
		$dt = \DateTime::createFromFormat( '!Y-m-d', $ymd );
		return $dt && $dt->format( 'Y-m-d' ) === $ymd && (int) $dt->format( 'Y' ) >= 1900;
	}

	/** Riconosce una data di nascita scritta come 31/12/2010, 31-12-2010, 2010-12-31 o 31.12.2010. @return string|null AAAA-MM-GG, null se non è una data valida */
	public static function parse( string $text ): ?string {
		$text = trim( $text );
		if ( preg_match( '#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $text, $m ) ) {
			$out = sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		} elseif ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $text, $m ) ) {
			$out = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		} else {
			return null;
		}
		return self::is_valid_date( $out ) ? $out : null;
	}

	/** Anni compiuti alla data $today (null se la nascita manca o non è valida o è nel futuro). */
	public static function years( ?string $birth, string $today ): ?int {
		if ( null === $birth || '' === $birth || ! self::is_valid_date( $birth ) || ! self::is_valid_date( $today ) || $birth > $today ) {
			return null;
		}
		return (int) ( new \DateTime( $birth ) )->diff( new \DateTime( $today ) )->y;
	}

	/** Minorenne alla data $today? Senza data di nascita non si sa: false. */
	public static function is_minor( ?string $birth, string $today ): bool {
		$y = self::years( $birth, $today );
		return null !== $y && $y < self::ADULT_AT;
	}

	/** Giorno del compleanno in cui si compiono i 18 anni (AAAA-MM-GG). Chi è nato il 29 febbraio li compie il 1° marzo se l'anno non è bisestile. */
	public static function adult_on( string $birth ): ?string {
		if ( ! self::is_valid_date( $birth ) ) {
			return null;
		}
		return ( new \DateTimeImmutable( $birth ) )->modify( '+' . self::ADULT_AT . ' years' )->format( 'Y-m-d' );
	}

	/** Ha compiuto i 18 anni nei $days giorni fino a $today (oggi compreso)? */
	public static function became_adult_within( ?string $birth, string $today, int $days ): bool {
		if ( null === $birth || '' === $birth ) {
			return false;
		}
		$on = self::adult_on( $birth );
		if ( null === $on || ! self::is_valid_date( $today ) ) {
			return false;
		}
		$from = ( new \DateTimeImmutable( $today ) )->modify( '-' . max( 0, $days ) . ' days' )->format( 'Y-m-d' );
		return $on <= $today && $on >= $from;
	}

	/** 2010-12-31 => 31/12/2010 (vuoto se manca). */
	public static function display( ?string $ymd ): string {
		return $ymd && self::is_valid_date( $ymd ) ? substr( $ymd, 8, 2 ) . '/' . substr( $ymd, 5, 2 ) . '/' . substr( $ymd, 0, 4 ) : '';
	}
}

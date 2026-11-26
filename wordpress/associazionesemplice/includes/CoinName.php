<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Il nome della moneta interna (i crediti che i soci comprano a pacchetti): lo sceglie l'associazione, singolare e plurale; senza un nome è
 * «AssoCredito». Si usa in ogni testo a schermo, nelle email, nei PDF e nelle esportazioni; tabelle, opzioni e funzioni restano «credit».
 * Le frasi sono scritte senza articolo davanti al nome («Saldo: 20 AssoCrediti»), perché il genere del nome scelto non si conosce.
 */
final class CoinName {

	const SINGULAR = 'AssoCredito';
	const PLURAL   = 'AssoCrediti';
	const MAX      = 24;

	public static function singular(): string {
		$s = (string) Settings::get( 'coin_singular' );
		$p = (string) Settings::get( 'coin_plural' );
		return '' !== $s ? $s : ( '' !== $p ? $p : self::SINGULAR );
	}

	public static function plural(): string {
		$s = (string) Settings::get( 'coin_singular' );
		$p = (string) Settings::get( 'coin_plural' );
		return '' !== $p ? $p : ( '' !== $s ? $s : self::PLURAL );
	}

	/** «1 AssoCredito», «20 AssoCrediti». */
	public static function n( int $n ): string {
		return $n . ' ' . ( 1 === abs( $n ) ? self::singular() : self::plural() );
	}

	/**
	 * Regola pura: il nome scritto nel modulo, pulito. Vuoto = nome di partenza.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function clean( string $name ): string {
		if ( false !== strpos( $name, '€' ) || preg_match( '/[<>"{}]/', $name ) ) { // prima di togliere i tag: «Punti <b>» si rifiuta, non diventa «Punti»
			throw new \InvalidArgumentException( 'Nel nome della moneta non si usano il simbolo dell\'euro né i caratteri < > " { }.' );
		}
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? '' );
		if ( '' === $name ) {
			return '';
		}
		if ( mb_strlen( $name ) > self::MAX ) {
			throw new \InvalidArgumentException( 'Il nome della moneta può avere al massimo ' . self::MAX . ' caratteri.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! preg_match( '/\p{L}/u', $name ) ) {
			throw new \InvalidArgumentException( 'Il nome della moneta deve contenere almeno una lettera.' );
		}
		return $name;
	}

	/** Regola pura: un avviso se il nome somiglia a una valuta vera ('' se no). */
	public static function warning( string $name ): string {
		return preg_match( '/\b(euro|eur|dollar\w*|usd|bitcoin|btc|sterlin\w*|franch\w*)\b|\$/iu', $name ) ? 'Attenzione: «' . $name . '» somiglia a una valuta vera. Chi legge potrebbe pensare a denaro: meglio un nome che non si confonda.' : '';
	}
}

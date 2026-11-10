<?php
namespace Portale\Plugin;

/** Codici a tempo (TOTP, RFC 6238: HMAC-SHA1, 6 cifre, 30 secondi) per la verifica in due passaggi. Compatibili con le app di autenticazione comuni. */
final class Totp {

	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** Segreto casuale di 20 byte in base32 (32 caratteri). */
	public static function generate(): string {
		return self::base32( random_bytes( 20 ) );
	}

	public static function base32( string $raw ): string {
		$bits = '';
		foreach ( str_split( $raw ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	public static function decode32( string $s ): string {
		$s    = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $s ) );
		$bits = '';
		foreach ( str_split( $s ) as $c ) {
			$bits .= str_pad( decbin( (int) strpos( self::ALPHABET, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	public static function code( string $secret32, int $time ): string {
		$counter = pack( 'N2', 0, intdiv( $time, 30 ) );
		$hash    = hash_hmac( 'sha1', $counter, self::decode32( $secret32 ), true );
		$off     = ord( $hash[19] ) & 0x0f;
		$num     = ( ( ord( $hash[ $off ] ) & 0x7f ) << 24 ) | ( ord( $hash[ $off + 1 ] ) << 16 ) | ( ord( $hash[ $off + 2 ] ) << 8 ) | ord( $hash[ $off + 3 ] );
		return str_pad( (string) ( $num % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/** Accetta il codice dell'intervallo attuale e di quelli adiacenti (±30 secondi, per gli orologi non perfetti). */
	public static function verify( string $secret32, string $input, ?int $now = null ): bool {
		$input = preg_replace( '/\s+/', '', $input );
		if ( 1 !== preg_match( '/^\d{6}$/', $input ) ) {
			return false;
		}
		$now = $now ?? time();
		$ok  = false;
		foreach ( array( -30, 0, 30 ) as $d ) {
			$ok = hash_equals( self::code( $secret32, $now + $d ), $input ) || $ok; // senza uscite anticipate
		}
		return $ok;
	}

	/** Indirizzo otpauth per le app di autenticazione. */
	public static function uri( string $secret32, string $account ): string {
		return 'otpauth://totp/' . rawurlencode( 'Associazione Semplice (gestione plugin):' . $account ) . '?secret=' . $secret32 . '&issuer=' . rawurlencode( 'Associazione Semplice' ) . '&digits=6&period=30';
	}
}

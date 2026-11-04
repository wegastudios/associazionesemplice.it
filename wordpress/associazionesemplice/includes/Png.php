<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Immagini PNG tinta unita (per le icone obbligatorie delle tessere nei wallet), senza GD. */
final class Png {

	public static function solid( int $w, int $h, int $r, int $g, int $b ): string {
		$row = "\0" . str_repeat( chr( $r ) . chr( $g ) . chr( $b ), $w );
		$raw = str_repeat( $row, $h );
		return "\x89PNG\r\n\x1a\n"
			. self::chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 ) )
			. self::chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. self::chunk( 'IEND', '' );
	}

	/** Caratteri 5x7 (righe da 5 bit) per l'iniziale dell'icona. */
	private const FONT = array(
		'A' => '01110 10001 10001 11111 10001 10001 10001', 'B' => '11110 10001 10001 11110 10001 10001 11110',
		'C' => '01110 10001 10000 10000 10000 10001 01110', 'D' => '11110 10001 10001 10001 10001 10001 11110',
		'E' => '11111 10000 10000 11110 10000 10000 11111', 'F' => '11111 10000 10000 11110 10000 10000 10000',
		'G' => '01110 10001 10000 10111 10001 10001 01111', 'H' => '10001 10001 10001 11111 10001 10001 10001',
		'I' => '01110 00100 00100 00100 00100 00100 01110', 'J' => '00111 00010 00010 00010 00010 10010 01100',
		'K' => '10001 10010 10100 11000 10100 10010 10001', 'L' => '10000 10000 10000 10000 10000 10000 11111',
		'M' => '10001 11011 10101 10101 10001 10001 10001', 'N' => '10001 11001 10101 10011 10001 10001 10001',
		'O' => '01110 10001 10001 10001 10001 10001 01110', 'P' => '11110 10001 10001 11110 10000 10000 10000',
		'Q' => '01110 10001 10001 10001 10101 10010 01101', 'R' => '11110 10001 10001 11110 10100 10010 10001',
		'S' => '01111 10000 10000 01110 00001 00001 11110', 'T' => '11111 00100 00100 00100 00100 00100 00100',
		'U' => '10001 10001 10001 10001 10001 10001 01110', 'V' => '10001 10001 10001 10001 10001 01010 00100',
		'W' => '10001 10001 10001 10101 10101 10101 01010', 'X' => '10001 10001 01010 00100 01010 10001 10001',
		'Y' => '10001 10001 01010 00100 00100 00100 00100', 'Z' => '11111 00001 00010 00100 01000 10000 11111',
		'0' => '01110 10001 10011 10101 11001 10001 01110', '1' => '00100 01100 00100 00100 00100 00100 01110',
		'2' => '01110 10001 00001 00010 00100 01000 11111', '3' => '11110 00001 00001 01110 00001 00001 11110',
		'4' => '00010 00110 01010 10010 11111 00010 00010', '5' => '11111 10000 11110 00001 00001 10001 01110',
		'6' => '00110 01000 10000 11110 10001 10001 01110', '7' => '11111 00001 00010 00100 01000 01000 01000',
		'8' => '01110 10001 10001 01110 10001 10001 01110', '9' => '01110 10001 10001 01111 00001 00010 01100',
	);

	/**
	 * Icona quadrata a tinta unita con l'iniziale al centro (nella zona sicura delle icone «maskable»: il carattere occupa il 42% dell'altezza).
	 * Un carattere senza disegno (accenti, simboli) diventa «A».
	 */
	public static function initial( int $size, array $rgb, string $letter ): string {
		$ch = strtoupper( function_exists( 'remove_accents' ) ? remove_accents( $letter ) : $letter );
		$ch = '' !== $ch ? $ch[0] : 'A';
		if ( ! isset( self::FONT[ $ch ] ) ) {
			$ch = 'A';
		}
		$bg   = chr( $rgb[0] ) . chr( $rgb[1] ) . chr( $rgb[2] );
		$fgc  = self::is_light( $rgb ) ? array( 0x1d, 0x23, 0x27 ) : array( 255, 255, 255 );
		$fg   = chr( $fgc[0] ) . chr( $fgc[1] ) . chr( $fgc[2] );
		$cell = max( 1, (int) floor( 0.42 * $size / 7 ) );
		$left = (int) floor( ( $size - 5 * $cell ) / 2 );
		$top  = (int) floor( ( $size - 7 * $cell ) / 2 );
		$grid = array();
		foreach ( explode( ' ', self::FONT[ $ch ] ) as $r => $bits ) {
			for ( $c = 0; $c < 5; $c++ ) {
				$grid[ $r ][ $c ] = '1' === $bits[ $c ] ? 1.0 : 0.0;
			}
		}
		// Interpolazione tra le celle e bordo sfumato: l'iniziale ha gli angoli arrotondati invece dei quadrettoni.
		$x0     = max( 0, $left - $cell );
		$x1     = min( $size, $left + 6 * $cell );
		$bg_row = "\0" . str_repeat( $bg, $size );
		$raw    = '';
		for ( $y = 0; $y < $size; $y++ ) {
			if ( $y < $top - $cell || $y >= $top + 8 * $cell ) {
				$raw .= $bg_row;
				continue;
			}
			$v  = ( $y + 0.5 - $top ) / $cell - 0.5;
			$r0 = (int) floor( $v );
			$fv = $v - $r0;
			$row = str_repeat( $bg, $x0 );
			for ( $x = $x0; $x < $x1; $x++ ) {
				$u   = ( $x + 0.5 - $left ) / $cell - 0.5;
				$c0  = (int) floor( $u );
				$fu  = $u - $c0;
				$val = ( ( $grid[ $r0 ][ $c0 ] ?? 0.0 ) * ( 1 - $fu ) + ( $grid[ $r0 ][ $c0 + 1 ] ?? 0.0 ) * $fu ) * ( 1 - $fv )
					+ ( ( $grid[ $r0 + 1 ][ $c0 ] ?? 0.0 ) * ( 1 - $fu ) + ( $grid[ $r0 + 1 ][ $c0 + 1 ] ?? 0.0 ) * $fu ) * $fv;
				$t   = min( 1.0, max( 0.0, ( $val - 0.35 ) / 0.3 ) );
				$a   = $t * $t * ( 3 - 2 * $t );
				if ( $a <= 0.0 ) {
					$row .= $bg;
				} elseif ( $a >= 1.0 ) {
					$row .= $fg;
				} else {
					$row .= chr( (int) round( $rgb[0] * ( 1 - $a ) + $fgc[0] * $a ) ) . chr( (int) round( $rgb[1] * ( 1 - $a ) + $fgc[1] * $a ) ) . chr( (int) round( $rgb[2] * ( 1 - $a ) + $fgc[2] * $a ) );
				}
			}
			$raw .= "\0" . $row . str_repeat( $bg, $size - $x1 );
		}
		return "\x89PNG\r\n\x1a\n"
			. self::chunk( 'IHDR', pack( 'NNCCCCC', $size, $size, 8, 2, 0, 0, 0 ) )
			. self::chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. self::chunk( 'IEND', '' );
	}

	private static function chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) & 0xFFFFFFFF );
	}

	/** "#RRGGBB" => [r, g, b] (blu predefinito se non valido). */
	public static function rgb( string $hex ): array {
		if ( ! preg_match( '/^#?([0-9a-fA-F]{6})$/', trim( $hex ), $m ) ) {
			return array( 34, 113, 177 );
		}
		return array( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) );
	}

	/** True se il colore è chiaro (serve testo scuro). */
	public static function is_light( array $rgb ): bool {
		return ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) > 160;
	}
}

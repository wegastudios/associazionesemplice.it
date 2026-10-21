<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Generatore minimo di PDF (A4, testo e linee, font Helvetica standard): serve per ricevute e attestazioni, senza librerie esterne.
 * Le coordinate sono in punti (1/72 di pollice) misurati dall'angolo in ALTO a sinistra della pagina.
 */
final class Pdf {

	const W = 595.0;
	const H = 842.0;

	/** Larghezze dei caratteri ASCII 32..126 di Helvetica (millesimi di corpo). */
	const WIDTHS = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	/** @var string[] contenuto di ogni pagina */
	private $pages = array();
	/** @var string */
	private $cur = '';
	/** @var string operatore di colore del testo ('' = nero) */
	private $fill = '';
	/** @var bool è stato scritto un colore diverso dal nero? */
	private $dirty = false;
	/** @var array[] immagini incorporate (vedi load_image) */
	private $images = array();
	/** @var array<string,int> immagini già incorporate, per nome */
	private $image_keys = array();

	public function __construct() {
		$this->add_page();
	}

	public function add_page(): void {
		if ( '' !== $this->cur || $this->pages ) {
			$this->pages[] = $this->cur;
		}
		$this->cur = '';
	}

	/** Testo in UTF-8 => byte Windows-1252 (accenti ed €), con le parentesi e la barra protette. */
	public static function encode( string $s ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$e = (string) @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		} elseif ( function_exists( 'iconv' ) ) {
			$e = (string) @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
		} else {
			$e = (string) preg_replace( '/[^\x20-\x7E]/', '?', $s );
		}
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $e );
	}

	/** Larghezza approssimata di un testo, in punti (per allineare a destra o al centro). */
	public static function width( string $s, float $size, bool $bold = false ): float {
		$w = 0;
		$n = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch = function_exists( 'mb_substr' ) ? mb_substr( $s, $i, 1, 'UTF-8' ) : $s[ $i ];
			$o  = 1 === strlen( $ch ) ? ord( $ch ) : 0;
			$w += ( $o >= 32 && $o <= 126 ) ? self::WIDTHS[ $o - 32 ] : 556;
		}
		return $w * $size / 1000 * ( $bold ? 1.06 : 1.0 );
	}

	/**
	 * @param string $align L (sinistra), R (destra: $x è il bordo destro) o C (centro: $x è il centro)
	 */
	/**  callable|null funzione che cambia ogni testo prima di scriverlo (testi personalizzati) */
	public static $filter = null;

	public function text( float $x, float $y, string $s, float $size = 11, bool $bold = false, string $align = 'L' ): void {
		if ( null !== self::$filter ) {
			$s = (string) call_user_func( self::$filter, $s );
		}
		if ( '' === $s ) {
			return;
		}
		if ( 'R' === $align ) {
			$x -= self::width( $s, $size, $bold );
		} elseif ( 'C' === $align ) {
			$x -= self::width( $s, $size, $bold ) / 2;
		}
		$op = '';
		if ( '' !== $this->fill ) {
			$op          = $this->fill . ' ';
			$this->dirty = true;
		} elseif ( $this->dirty ) {
			$op          = '0 g ';
			$this->dirty = false;
		}
		$this->cur .= sprintf( "BT %s/F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $op, $bold ? 2 : 1, $size, $x, self::H - $y, self::encode( $s ) );
	}

	/** Colore dei testi che seguono, in esadecimale (#rrggbb); vuoto = nero. */
	public function color( string $hex = '' ): void {
		$c = ltrim( $hex, '#' );
		if ( 6 === strlen( $c ) && ctype_xdigit( $c ) ) {
			$this->fill = sprintf( '%.3F %.3F %.3F rg', hexdec( substr( $c, 0, 2 ) ) / 255, hexdec( substr( $c, 2, 2 ) ) / 255, hexdec( substr( $c, 4, 2 ) ) / 255 );
		} else {
			$this->fill = '';
		}
	}

	/**
	 * Prepara un'immagine JPEG o PNG per incorporarla. Sono accettati: JPEG in grigi o RGB; PNG in grigi, RGB, a palette, anche con trasparenza
	 * (8 bit, non interlacciati, non troppo grandi). @return array|null null se il formato non è gestito
	 */
	public static function load_image( string $b ): ?array {
		if ( "\xFF\xD8" === substr( $b, 0, 2 ) ) {
			$pos = 2;
			$len = strlen( $b );
			while ( $pos + 9 < $len ) {
				if ( "\xFF" !== $b[ $pos ] ) {
					$pos++;
					continue;
				}
				$m = ord( $b[ $pos + 1 ] );
				if ( $m >= 0xC0 && $m <= 0xCF && ! in_array( $m, array( 0xC4, 0xC8, 0xCC ), true ) ) {
					$h = ( ord( $b[ $pos + 5 ] ) << 8 ) + ord( $b[ $pos + 6 ] );
					$w = ( ord( $b[ $pos + 7 ] ) << 8 ) + ord( $b[ $pos + 8 ] );
					$c = ord( $b[ $pos + 9 ] );
					if ( ! in_array( $c, array( 1, 3 ), true ) || $w < 1 || $h < 1 ) {
						return null;
					}
					return array( 'w' => $w, 'h' => $h, 'cs' => 1 === $c ? '/DeviceGray' : '/DeviceRGB', 'bpc' => 8, 'filter' => '/DCTDecode', 'parms' => '', 'data' => $b );
				}
				$pos += 2 + ( ( ord( $b[ $pos + 2 ] ) << 8 ) + ord( $b[ $pos + 3 ] ) );
			}
			return null;
		}
		if ( "\x89PNG\r\n\x1a\n" !== substr( $b, 0, 8 ) ) {
			return null;
		}
		$pos  = 8;
		$len  = strlen( $b );
		$idat = '';
		$plte = '';
		$w    = 0;
		$h    = 0;
		$bd   = 0;
		$ct   = -1;
		$il   = 0;
		while ( $pos + 8 <= $len ) {
			$n    = (int) unpack( 'N', substr( $b, $pos, 4 ) )[1];
			$type = substr( $b, $pos + 4, 4 );
			$data = substr( $b, $pos + 8, $n );
			$pos += 12 + $n;
			if ( 'IHDR' === $type ) {
				$d  = unpack( 'N2', substr( $data, 0, 8 ) );
				$w  = (int) $d[1];
				$h  = (int) $d[2];
				$bd = ord( $data[8] );
				$ct = ord( $data[9] );
				$il = ord( $data[12] );
			} elseif ( 'PLTE' === $type ) {
				$plte = $data;
			} elseif ( 'IDAT' === $type ) {
				$idat .= $data;
			} elseif ( 'IEND' === $type ) {
				break;
			}
		}
		if ( $w < 1 || $h < 1 || 0 !== $il || '' === $idat || $w * $h > 1500000 ) {
			return null;
		}
		if ( ( 0 === $ct || 2 === $ct ) && 8 === $bd ) {
			$c = 0 === $ct ? 1 : 3;
			return array( 'w' => $w, 'h' => $h, 'cs' => 1 === $c ? '/DeviceGray' : '/DeviceRGB', 'bpc' => 8, 'filter' => '/FlateDecode', 'parms' => '/DecodeParms << /Predictor 15 /Colors ' . $c . ' /BitsPerComponent 8 /Columns ' . $w . ' >>', 'data' => $idat );
		}
		if ( 3 === $ct && in_array( $bd, array( 1, 2, 4, 8 ), true ) && '' !== $plte ) {
			return array( 'w' => $w, 'h' => $h, 'cs' => '[/Indexed /DeviceRGB ' . ( intdiv( strlen( $plte ), 3 ) - 1 ) . ' <' . bin2hex( $plte ) . '>]', 'bpc' => $bd, 'filter' => '/FlateDecode', 'parms' => '/DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent ' . $bd . ' /Columns ' . $w . ' >>', 'data' => $idat );
		}
		if ( ( 4 === $ct || 6 === $ct ) && 8 === $bd && function_exists( 'gzuncompress' ) && function_exists( 'gzcompress' ) ) { // con trasparenza: colori e trasparenza vanno separati
			$raw = @gzuncompress( $idat );
			if ( false === $raw ) {
				return null;
			}
			$bpp    = 6 === $ct ? 4 : 2;
			$stride = $w * $bpp;
			if ( strlen( $raw ) < $h * ( $stride + 1 ) ) {
				return null;
			}
			$prev  = array_fill( 0, $stride, 0 );
			$color = '';
			$alpha = '';
			$o     = 0;
			for ( $y = 0; $y < $h; $y++ ) {
				$f   = ord( $raw[ $o ] );
				$row = array_values( unpack( 'C*', substr( $raw, $o + 1, $stride ) ) );
				$o  += $stride + 1;
				$cur = array();
				for ( $i = 0; $i < $stride; $i++ ) {
					$a = $i >= $bpp ? $cur[ $i - $bpp ] : 0;
					$u = $prev[ $i ];
					$c = $i >= $bpp ? $prev[ $i - $bpp ] : 0;
					switch ( $f ) {
						case 1:
							$v = $row[ $i ] + $a;
							break;
						case 2:
							$v = $row[ $i ] + $u;
							break;
						case 3:
							$v = $row[ $i ] + ( ( $a + $u ) >> 1 );
							break;
						case 4:
							$p  = $a + $u - $c;
							$pa = abs( $p - $a );
							$pb = abs( $p - $u );
							$pc = abs( $p - $c );
							$v  = $row[ $i ] + ( $pa <= $pb && $pa <= $pc ? $a : ( $pb <= $pc ? $u : $c ) );
							break;
						default:
							$v = $row[ $i ];
					}
					$cur[ $i ] = $v & 255;
				}
				$cn = $bpp - 1;
				for ( $x = 0; $x < $w; $x++ ) {
					for ( $k = 0; $k < $cn; $k++ ) {
						$color .= chr( $cur[ $x * $bpp + $k ] );
					}
					$alpha .= chr( $cur[ $x * $bpp + $cn ] );
				}
				$prev = $cur;
			}
			return array(
				'w' => $w, 'h' => $h, 'cs' => 6 === $ct ? '/DeviceRGB' : '/DeviceGray', 'bpc' => 8, 'filter' => '/FlateDecode', 'parms' => '', 'data' => gzcompress( $color ),
				'smask' => array( 'w' => $w, 'h' => $h, 'cs' => '/DeviceGray', 'bpc' => 8, 'filter' => '/FlateDecode', 'parms' => '', 'data' => gzcompress( $alpha ) ),
			);
		}
		return null;
	}

	/** Disegna un'immagine preparata con {@see Pdf::load_image()} nel rettangolo indicato (punti, dall'angolo in alto a sinistra). */
	public function image( array $img, float $x, float $y, float $w, float $h, string $key = '' ): void {
		if ( '' !== $key && isset( $this->image_keys[ $key ] ) ) { // la stessa immagine usata più volte (sfondo, logo) si incorpora una volta sola
			$idx = $this->image_keys[ $key ];
		} else {
			$this->images[] = $img;
			$idx            = count( $this->images );
			if ( '' !== $key ) {
				$this->image_keys[ $key ] = $idx;
			}
		}
		$this->cur .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q
", $w, $h, $x, self::H - $y - $h, $idx );
	}

	/** Spezza un testo lungo in righe che stanno in $width punti. @return string[] */
	public static function wrap( string $s, float $width, float $size = 11, bool $bold = false ): array {
		$lines = array();
		$line  = '';
		foreach ( preg_split( '/\s+/u', trim( $s ) ) ?: array() as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && self::width( $try, $size, $bold ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines ?: array( '' );
	}

	public function line( float $x1, float $y1, float $x2, float $y2, float $width = 0.6 ): void {
		$this->cur .= sprintf( "%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, self::H - $y1, $x2, self::H - $y2 );
	}

	/** Rettangolo pieno in un tono di grigio (0 nero … 1 bianco). */
	public function rect( float $x, float $y, float $w, float $h, float $gray = 0.93 ): void {
		$this->cur .= sprintf( "%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, self::H - $y - $h, $w, $h );
	}

	/** Il documento completo (byte del file PDF). */
	public function output(): string {
		$pages = $this->pages;
		$pages[] = $this->cur;
		$n     = count( $pages );
		$objs  = array();
		// 1 catalogo, 2 elenco pagine, 3 e 4 font, poi per ogni pagina: oggetto pagina e contenuto
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids    = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 5 + $i * 2 ) . ' 0 R';
		}
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$next = 5 + $n * 2; // le immagini vengono dopo le pagine
		$xobj = '';
		$stream = function ( array $im, string $extra = '' ): string {
			return '<< /Type /XObject /Subtype /Image /Width ' . (int) $im['w'] . ' /Height ' . (int) $im['h'] . ' /ColorSpace ' . $im['cs'] . ' /BitsPerComponent ' . (int) $im['bpc']
				. ' /Filter ' . $im['filter'] . ( '' !== $im['parms'] ? ' ' . $im['parms'] : '' ) . $extra . ' /Length ' . strlen( $im['data'] ) . " >>\nstream\n" . $im['data'] . "\nendstream";
		};
		foreach ( $this->images as $k => $im ) {
			$id = $next++;
			if ( ! empty( $im['smask'] ) ) {
				$mask_id       = $next++;
				$objs[ $mask_id ] = $stream( $im['smask'] );
				$objs[ $id ]      = $stream( $im, ' /SMask ' . $mask_id . ' 0 R' );
			} else {
				$objs[ $id ] = $stream( $im );
			}
			$xobj .= ' /Im' . ( $k + 1 ) . ' ' . $id . ' 0 R';
		}
		$res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ( '' !== $xobj ? ' /XObject <<' . $xobj . ' >>' : '' ) . ' >>';
		foreach ( $pages as $i => $content ) {
			$pg          = 5 + $i * 2;
			$objs[ $pg ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources ' . $res . ' /Contents ' . ( $pg + 1 ) . ' 0 R >>';
			$objs[ $pg + 1 ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . 'endstream';
		}
		ksort( $objs );
		$out     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		return $out . 'trailer << /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
	}
}

<?php
use AssociazioneSemplice\Pdf;
use PHPUnit\Framework\TestCase;

final class PdfTest extends TestCase {

	public function test_document_structure_and_offsets_are_consistent(): void {
		$pdf = new Pdf();
		$pdf->text( 50, 60, 'Ricevuta', 14, true );
		$pdf->line( 50, 70, 500, 70 );
		$pdf->add_page();
		$pdf->text( 50, 60, 'Seconda pagina' );
		$out = $pdf->output();
		$this->assertStringStartsWith( '%PDF-1.4', $out );
		$this->assertStringEndsWith( "%%EOF\n", $out );
		$this->assertStringContainsString( '/Count 2', $out );
		// startxref punta davvero alla tabella "xref"
		preg_match( '/startxref\n(\d+)\n/', $out, $m );
		$this->assertSame( 'xref', substr( $out, (int) $m[1], 4 ) );
		// ogni oggetto sta all'indirizzo dichiarato
		preg_match_all( '/^(\d{10}) 00000 n $/m', $out, $offs );
		foreach ( $offs[1] as $i => $off ) {
			$this->assertSame( ( $i + 1 ) . ' 0 obj', substr( $out, (int) $off, strlen( ( $i + 1 ) . ' 0 obj' ) ) );
		}
	}

	public function test_text_is_encoded_for_winansi_and_escaped(): void {
		$s = Pdf::encode( 'Perché (50 €) a\b' );
		$this->assertStringContainsString( "Perch\xE9", $s );
		$this->assertStringContainsString( "\x80", $s ); // €
		$this->assertStringContainsString( '\(50', $s );
		$this->assertStringContainsString( '\)', $s );
		$this->assertStringContainsString( 'a' . '\\' . '\\' . 'b', $s ); // la barra va raddoppiata
	}

	public function test_width_and_wrap(): void {
		$this->assertEqualsWithDelta( 5 * 0.556 * 10, Pdf::width( '12345', 10 ), 0.001 );
		$this->assertGreaterThan( Pdf::width( 'Totale', 12 ), Pdf::width( 'Totale', 12, true ) );
		$lines = Pdf::wrap( 'Quota associativa 2026 per Mario Rossi e famiglia numerosa', 120, 10 );
		$this->assertGreaterThan( 1, count( $lines ) );
		foreach ( $lines as $l ) {
			$this->assertLessThanOrEqual( 125, Pdf::width( $l, 10 ) );
		}
		$this->assertSame( array( '' ), Pdf::wrap( '', 100 ) );
	}

	public function test_decimal_separator_is_not_locale_dependent(): void {
		$pdf = new Pdf();
		$pdf->text( 50.5, 60.25, 'x' );
		$this->assertMatchesRegularExpression( '/ 50\.50 \d+\.\d{2} Td/', $pdf->output() );
	}

	/** Un PNG minuscolo: $rows è l'elenco delle righe già con il byte del filtro (0). */
	private static function png( int $w, int $h, int $color_type, string $raw ): string {
		$chunk = function ( string $type, string $data ): string {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, $color_type, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' );
	}

	public function test_png_without_transparency_is_embedded_as_is(): void {
		$img = Pdf::load_image( self::png( 2, 1, 2, "\x00\xFF\x00\x00\x00\xFF\x00" ) );
		$this->assertNotNull( $img );
		$this->assertSame( '/DeviceRGB', $img['cs'] );
		$this->assertArrayNotHasKey( 'smask', $img );
	}

	public function test_png_with_transparency_splits_colour_and_alpha(): void {
		$img = Pdf::load_image( self::png( 2, 1, 6, "\x00\xFF\x00\x00\x80\x00\x00\xFF\xFF" ) );
		$this->assertNotNull( $img );
		$this->assertSame( "\xFF\x00\x00\x00\x00\xFF", gzuncompress( $img['data'] ) );
		$this->assertSame( "\x80\xFF", gzuncompress( $img['smask']['data'] ) );
	}

	public function test_qr_is_a_one_bit_image_with_the_same_modules_as_the_matrix(): void {
		$m   = \AssociazioneSemplice\QrCode::matrix( 'https://example.org/?asem_card=0123456789abcdef.ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
		$n   = count( $m );
		$pdf = new Pdf();
		$pdf->qr( $m, 50, 50, 80 );
		$out = $pdf->output();
		$dim = $n + 8;
		$this->assertStringContainsString( '/Width ' . $dim . ' /Height ' . $dim . ' /ColorSpace /DeviceGray /BitsPerComponent 1', $out );
		$this->assertStringNotContainsString( ' re f', $out, 'nessun rettangolo per modulo' );
		$this->assertMatchesRegularExpression( '/\/Filter \/FlateDecode \/Interpolate false \/Length (\d+) >>\nstream\n/', $out );
		preg_match( '/\/Interpolate false \/Length (\d+) >>\nstream\n/', $out, $mm, PREG_OFFSET_CAPTURE );
		$len  = (int) $mm[1][0];
		$from = $mm[0][1] + strlen( $mm[0][0] );
		$raw  = gzuncompress( substr( $out, $from, $len ) );
		$rb   = intdiv( $dim + 7, 8 );
		$this->assertSame( $rb * $dim, strlen( $raw ) );
		for ( $r = 0; $r < $dim; $r++ ) {
			for ( $c = 0; $c < $dim; $c++ ) {
				$bit  = ( ord( $raw[ $r * $rb + intdiv( $c, 8 ) ] ) >> ( 7 - $c % 8 ) ) & 1; // 1 = bianco
				$dark = $r >= 4 && $c >= 4 && $r < 4 + $n && $c < 4 + $n && ! empty( $m[ $r - 4 ][ $c - 4 ] );
				$this->assertSame( $dark ? 0 : 1, $bit, "modulo $r,$c" );
			}
		}
	}

	public function test_compressed_pages_stay_a_valid_document_and_are_smaller(): void {
		$build = function ( bool $zip ): string {
			$pdf = new Pdf();
			$pdf->compress( $zip );
			for ( $p = 0; $p < 3; $p++ ) {
				for ( $i = 0; $i < 60; $i++ ) {
					$pdf->text( 50, 60 + $i * 10, 'Riga di prova numero ' . $i );
					$pdf->rect( 50, 60 + $i * 10, 100, 2, 0.0 );
				}
				$pdf->add_page();
			}
			return $pdf->output();
		};
		$raw = $build( false );
		$zip = $build( true );
		$this->assertStringNotContainsString( '/FlateDecode', $raw );
		$this->assertStringContainsString( '/Filter /FlateDecode', $zip );
		$this->assertLessThan( strlen( $raw ) / 2, strlen( $zip ) );
		$this->assertStringContainsString( '/Count 4', $zip );
		preg_match( '/startxref\n(\d+)\n/', $zip, $m );
		$this->assertSame( 'xref', substr( $zip, (int) $m[1], 4 ) );
		preg_match_all( '/^(\d{10}) 00000 n $/m', $zip, $offs );
		foreach ( $offs[1] as $i => $off ) {
			$this->assertSame( ( $i + 1 ) . ' 0 obj', substr( $zip, (int) $off, strlen( ( $i + 1 ) . ' 0 obj' ) ) );
		}
		// il contenuto della prima pagina, decompresso, è quello non compresso
		preg_match( '/6 0 obj\n<< \/Length (\d+) \/Filter \/FlateDecode >>\nstream\n/', $zip, $z, PREG_OFFSET_CAPTURE );
		preg_match( '/6 0 obj\n<< \/Length (\d+) >>\nstream\n/', $raw, $r, PREG_OFFSET_CAPTURE );
		$this->assertNotEmpty( $z );
		$this->assertNotEmpty( $r );
		$this->assertSame(
			substr( $raw, $r[0][1] + strlen( $r[0][0] ), (int) $r[1][0] ),
			gzuncompress( substr( $zip, $z[0][1] + strlen( $z[0][0] ), (int) $z[1][0] ) )
		);
	}

	public function test_unknown_images_are_refused_and_documents_with_images_stay_consistent(): void {
		$this->assertNull( Pdf::load_image( 'non è un\'immagine' ) );
		$pdf = new Pdf();
		$pdf->image( Pdf::load_image( self::png( 2, 1, 6, "\x00\xFF\x00\x00\x80\x00\x00\xFF\xFF" ) ), 50, 50, 100, 50 );
		$pdf->color( '#ff0000' );
		$pdf->text( 60, 80, 'Rosso' );
		$pdf->color( '' );
		$pdf->text( 60, 100, 'Nero' );
		$out = $pdf->output();
		$this->assertStringContainsString( '/XObject', $out );
		$this->assertStringContainsString( '/SMask', $out );
		$this->assertStringContainsString( '1.000 0.000 0.000 rg', $out );
		preg_match( '/startxref\n(\d+)\n/', $out, $m );
		$this->assertSame( 'xref', substr( $out, (int) $m[1], 4 ) );
		preg_match_all( '/^(\d{10}) 00000 n $/m', $out, $offs );
		foreach ( $offs[1] as $i => $off ) {
			$this->assertSame( ( $i + 1 ) . ' 0 obj', substr( $out, (int) $off, strlen( ( $i + 1 ) . ' 0 obj' ) ) );
		}
	}
}

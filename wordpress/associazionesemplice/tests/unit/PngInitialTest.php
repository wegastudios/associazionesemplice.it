<?php
use AssociazioneSemplice\Png;
use PHPUnit\Framework\TestCase;

final class PngInitialTest extends TestCase {

	public function test_icona_con_iniziale_e_un_png_della_misura_richiesta(): void {
		foreach ( array( 192, 512 ) as $size ) {
			$png = Png::initial( $size, array( 34, 113, 177 ), 'Associazione' );
			$this->assertSame( "\x89PNG\r\n\x1a\n", substr( $png, 0, 8 ) );
			$hdr = unpack( 'Nw/Nh', substr( $png, 16, 8 ) );
			$this->assertSame( $size, $hdr['w'] );
			$this->assertSame( $size, $hdr['h'] );
		}
	}

	public function test_il_carattere_si_vede_e_cambia_da_lettera_a_lettera(): void {
		$a = Png::initial( 192, array( 34, 113, 177 ), 'A' );
		$this->assertNotSame( Png::solid( 192, 192, 34, 113, 177 ), $a, 'l\'iniziale deve essere disegnata' );
		$this->assertNotSame( $a, Png::initial( 192, array( 34, 113, 177 ), 'B' ) );
		$this->assertSame( $a, Png::initial( 192, array( 34, 113, 177 ), '?' ), 'un simbolo senza disegno ripiega su A' );
		$this->assertSame( $a, Png::initial( 192, array( 34, 113, 177 ), 'a' ), 'minuscole come maiuscole' );
	}
}

<?php
use PHPUnit\Framework\TestCase;

/** (La Cassa non c'è: «Anni solari» sta lì nel gratuito e in Contabilità con il Pro.) Con il Pro il menu è quello di ProMenu: ogni scheda del menu gratuito deve esserci anche lì, altrimenti sparisce quando il Pro è attivo. */
final class ProMenuParityTest extends TestCase {

	private function pages( string $file, string $group ): array {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/' . $file );
		$this->assertSame( 1, preg_match( "/'" . $group . "'\s*=>\s*array\(\s*'[^']*',\s*array\((.*?)\)\s*\)/s", $src, $m ), $file . ': voce ' . $group );
		preg_match_all( "/'(asem-[a-z0-9-]+)'\s*=>/", $m[1], $keys );
		return $keys[1];
	}

	/** @dataProvider groups */
	public function test_every_free_tab_is_in_the_pro_menu( string $group ): void {
		$missing = array_diff( $this->pages( 'Admin.php', $group ), $this->pages( 'ProMenu.php', $group ) );
		$this->assertSame( array(), array_values( $missing ), 'schede del menu gratuito mancanti nel menu Pro (' . $group . ')' );
	}

	public static function groups(): array {
		return array( array( 'asem-book' ), array( 'asem-tools' ), array( 'asem-activities' ) );
	}
}

<?php
use AssociazioneSemplice\Edition;
use PHPUnit\Framework\TestCase;

final class EditionTest extends TestCase {

	public function test_unknown_feature_is_not_present(): void {
		$this->assertFalse( Edition::has( 'nonexistent' ) );
	}

	public function test_a_registered_feature_exists_only_if_its_file_does(): void {
		$dir = sys_get_temp_dir() . '/asem-edition-reg-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/includes', 0777, true );
		file_put_contents( $dir . '/includes/Feat.php', '<?php' );
		Edition::add_dir( $dir . '/includes' );
		$this->assertFalse( Edition::pro_present() && Edition::has( 'zz_feature' ), 'prima di registrare non c\'è' );
		Edition::register( array( 'zz_feature' => 'Feat.php', 'zz_missing' => 'Nope.php' ), array( 'zz_feature' => array( 'asem-zz' ), 'zz_missing' => array( 'asem-zz-missing' ) ), array( 'zz_feature' => array( 'asem_zz_action' ) ) );
		$this->assertTrue( Edition::pro_present() );
		$this->assertTrue( Edition::has( 'zz_feature' ) );
		$this->assertFalse( Edition::has( 'zz_missing' ) );
		$this->assertSame( array( 'asem_zz_action' ), Edition::actions()['zz_feature'] );
		$this->assertContains( 'asem-zz-missing', Edition::missing_pages() );
		$this->assertNotContains( 'asem-zz', Edition::missing_pages() );
		unlink( $dir . '/includes/Feat.php' );
		rmdir( $dir . '/includes' );
		rmdir( $dir );
	}

	public function test_extra_directory_is_searched_for_classes(): void {
		$dir = sys_get_temp_dir() . '/asem-edition-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/includes', 0777, true );
		file_put_contents( $dir . '/includes/Extra.php', '<?php' );
		$this->assertNull( Edition::locate( 'Extra.php' ) );
		Edition::add_dir( $dir . '/includes' );
		$this->assertSame( $dir . '/includes/Extra.php', Edition::locate( 'Extra.php' ) );
		unlink( $dir . '/includes/Extra.php' );
		rmdir( $dir . '/includes' );
		rmdir( $dir );
	}

	public function test_free_and_pro_versions_are_independent_only_the_compatibility_level_counts(): void {
		$this->assertTrue( Edition::compatible( 1, 1 ) );
		$this->assertFalse( Edition::compatible( 2, 1 ) );
		$this->assertFalse( Edition::compatible( 1, 2 ) );
	}
}

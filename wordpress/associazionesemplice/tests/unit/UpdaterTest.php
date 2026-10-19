<?php
use AssociazioneSemplice\Updater as U;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	public function test_valid_answer_is_parsed(): void {
		$r = U::parse( '{"product":"free","version":"1.2","update":true,"package":"https:\/\/associazionesemplice.it\/api\/plugin\/package?t=x","url":"https:\/\/associazionesemplice.it\/accedi"}' );
		$this->assertSame( '1.2', $r['version'] );
		$this->assertTrue( $r['update'] );
		$this->assertSame( 'https://associazionesemplice.it/api/plugin/package?t=x', $r['package'] );
	}

	public function test_no_update_has_no_package(): void {
		$r = U::parse( '{"version":"1.1.20","update":false,"package":null,"url":"https://associazionesemplice.it/accedi"}' );
		$this->assertFalse( $r['update'] );
		$this->assertSame( '', $r['package'] );
	}

	public function test_garbage_and_insecure_values_are_dropped(): void {
		$this->assertNull( U::parse( 'non json' ) );
		$this->assertNull( U::parse( '{"version":"<b>","update":true}' ) );
		$this->assertNull( U::parse( '{"update":true}' ) );
		$r = U::parse( '{"version":"1.3","update":true,"package":"http://x.it/a.zip","url":"javascript:alert(1)"}' );
		$this->assertSame( '', $r['package'], 'solo https' );
		$this->assertSame( '', $r['url'] );
	}
}

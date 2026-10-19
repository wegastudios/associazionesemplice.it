<?php
use AssociazioneSemplice\LicenseRules as R;
use PHPUnit\Framework\TestCase;

final class LicenseRulesTest extends TestCase {

	public function test_registrable_domain_groups_subdomains(): void {
		$this->assertSame( 'esempio.it', R::registrable_domain( 'https://www.esempio.it' ) );
		$this->assertSame( 'esempio.it', R::registrable_domain( 'https://staging.esempio.it/wp/' ) );
		$this->assertSame( 'esempio.it', R::registrable_domain( 'http://a.b.esempio.it:8080' ) );
		$this->assertSame( 'esempio.it', R::registrable_domain( 'ESEMPIO.IT' ) );
		$this->assertSame( 'esempio.co.uk', R::registrable_domain( 'https://staging.esempio.co.uk' ) );
		$this->assertSame( '', R::registrable_domain( '' ) );
	}

	public function test_same_domain(): void {
		$this->assertTrue( R::same_domain( 'https://esempio.it', 'https://staging.esempio.it' ) );
		$this->assertFalse( R::same_domain( 'https://esempio.it', 'https://altro.it' ) );
		$this->assertFalse( R::same_domain( 'https://esempio.it', 'https://esempio.com' ) );
		$this->assertFalse( R::same_domain( '', '' ) );
	}

	public function test_local_environments_are_free(): void {
		foreach ( array( 'http://localhost', 'http://localhost:8888', 'https://sito.local', 'http://app.test', 'http://127.0.0.1', 'http://192.168.1.5/wp', 'http://miosito' ) as $u ) {
			$this->assertTrue( R::is_local( $u ), $u );
		}
		$this->assertFalse( R::is_local( 'https://www.esempio.it' ) );
		$r = R::evaluate( 'esempio.it', array(), 'http://localhost', 'x' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( R::REASON_LOCAL, $r['reason'] );
	}

	public function test_first_activation_binds_the_domain(): void {
		$r = R::evaluate( null, array(), 'https://www.esempio.it', 'a' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( 'esempio.it', $r['domain'] );
		$this->assertSame( 1, $r['used'] );
	}

	public function test_production_plus_staging_are_allowed(): void {
		$installs = array( array( 'id' => 'a', 'url' => 'https://www.esempio.it' ) );
		$r        = R::evaluate( 'esempio.it', $installs, 'https://staging.esempio.it', 'b' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( 2, $r['used'] );
	}

	public function test_third_installation_on_the_same_domain_is_refused(): void {
		$installs = array(
			array( 'id' => 'a', 'url' => 'https://www.esempio.it' ),
			array( 'id' => 'b', 'url' => 'https://staging.esempio.it' ),
		);
		$r = R::evaluate( 'esempio.it', $installs, 'https://test.esempio.it', 'c' );
		$this->assertFalse( $r['allowed'] );
		$this->assertSame( R::REASON_LIMIT_REACHED, $r['reason'] );
	}

	public function test_reactivating_an_active_installation_does_not_use_a_slot(): void {
		$installs = array(
			array( 'id' => 'a', 'url' => 'https://www.esempio.it' ),
			array( 'id' => 'b', 'url' => 'https://staging.esempio.it' ),
		);
		$r = R::evaluate( 'esempio.it', $installs, 'https://staging.esempio.it', 'b' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( R::REASON_ALREADY_ACTIVE, $r['reason'] );
		$this->assertSame( 2, $r['used'] );
	}

	public function test_other_domain_is_refused(): void {
		$r = R::evaluate( 'esempio.it', array(), 'https://altrodominio.it', 'a' );
		$this->assertFalse( $r['allowed'] );
		$this->assertSame( R::REASON_DOMAIN_MISMATCH, $r['reason'] );
	}

	public function test_local_installs_do_not_count_toward_the_limit(): void {
		$installs = array(
			array( 'id' => 'a', 'url' => 'https://www.esempio.it' ),
			array( 'id' => 'dev', 'url' => 'http://localhost:8888' ),
		);
		$r = R::evaluate( 'esempio.it', $installs, 'https://staging.esempio.it', 'b' );
		$this->assertTrue( $r['allowed'], 'una copia locale non occupa un posto' );
		$this->assertSame( 2, $r['used'] );
	}

	/** Gli stessi casi del portale: la Public Suffix List completa (ICANN e privati, wildcard, eccezioni, punycode). */
	public function test_registrable_domain_uses_the_full_public_suffix_list(): void {
		$cases = array(
			'esempio.it' => 'esempio.it', 'staging.esempio.it' => 'esempio.it', 'a.b.c.esempio.it' => 'esempio.it',
			'www.esempio.co.uk' => 'esempio.co.uk', 'esempio.com.au' => 'esempio.com.au',
			'sito.altervista.org' => 'sito.altervista.org', 'staging.sito.altervista.org' => 'sito.altervista.org',
			'utente.github.io' => 'utente.github.io', 'x.blogspot.com' => 'x.blogspot.com',
			'foo.bar.ck' => 'foo.bar.ck', 'www.ck' => 'www.ck', 'altro.www.ck' => 'www.ck',
			'esempio.org.it' => 'org.it', 'esempio.roma.it' => 'esempio.roma.it', 'test.associazione.roma.it' => 'associazione.roma.it',
			'xn--bcher-kva.ch' => 'xn--bcher-kva.ch', 'esempio.xn--p1ai' => 'esempio.xn--p1ai',
			'esempio.com.co' => 'esempio.com.co', 'x.esempio.co.kr' => 'esempio.co.kr',
		);
		foreach ( $cases as $in => $want ) {
			$got = R::registrable_domain( $in );
			$this->assertSame( $want, $got, $in );
		}
		$this->assertNotSame( R::registrable_domain( 'a.altervista.org' ), R::registrable_domain( 'b.altervista.org' ), 'due clienti dello stesso ospite non condividono la licenza' );
		$this->assertFalse( R::same_domain( 'https://a.altervista.org', 'https://b.altervista.org' ) );
		$this->assertTrue( R::same_domain( 'https://staging.sito.altervista.org', 'https://sito.altervista.org' ) );
		$this->assertSame( '192.168.1.5', R::registrable_domain( 'http://192.168.1.5' ) );
		$this->assertSame( 'localhost', R::registrable_domain( 'localhost' ) );
	}
}

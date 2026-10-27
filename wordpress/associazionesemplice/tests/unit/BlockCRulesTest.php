<?php
use AssociazioneSemplice\AffiliationRules;
use AssociazioneSemplice\CertificateRules;
use AssociazioneSemplice\ExpiryRules;
use PHPUnit\Framework\TestCase;

final class BlockCRulesTest extends TestCase {

	public function test_status_around_expiry(): void {
		$this->assertSame( ExpiryRules::NONE, ExpiryRules::status( null, '2026-05-01', 30 ) );
		$this->assertSame( ExpiryRules::VALID, ExpiryRules::status( '2026-07-01', '2026-05-01', 30 ) );
		$this->assertSame( ExpiryRules::EXPIRING, ExpiryRules::status( '2026-05-31', '2026-05-01', 30 ) ); // esattamente 30 giorni
		$this->assertSame( ExpiryRules::VALID, ExpiryRules::status( '2026-06-01', '2026-05-01', 30 ) ); // 31 giorni
		$this->assertSame( ExpiryRules::EXPIRING, ExpiryRules::status( '2026-05-01', '2026-05-01', 30 ) ); // vale fino al giorno stesso
		$this->assertSame( ExpiryRules::EXPIRED, ExpiryRules::status( '2026-04-30', '2026-05-01', 30 ) );
	}

	public function test_warn_days(): void {
		$this->assertSame( 30, ExpiryRules::warn_days( 0 ) );
		$this->assertSame( 30, ExpiryRules::warn_days( 'x' ) );
		$this->assertSame( 30, ExpiryRules::warn_days( 400 ) );
		$this->assertSame( 45, ExpiryRules::warn_days( '45' ) );
		$this->assertSame( 1, ExpiryRules::warn_days( 1 ) );
	}

	public function test_dates(): void {
		$this->assertTrue( ExpiryRules::is_date( '2026-02-28' ) );
		$this->assertFalse( ExpiryRules::is_date( '2026-02-30' ) );
		$this->assertFalse( ExpiryRules::is_date( '' ) );
		$this->assertSame( '2027-03-10', ExpiryRules::plus_year( '2026-03-10' ) );
		$this->assertSame( '05/01/2026', ExpiryRules::it_date( '2026-01-05' ) );
		$this->assertSame( -3, ExpiryRules::days_left( '2026-04-28', '2026-05-01' ) );
	}

	public function test_certificate_clean(): void {
		$c = CertificateRules::clean( array( 'kind' => 'agonistic', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-10', 'doctor' => ' <b>Dott. Verdi</b> ' ) );
		$this->assertSame( 'Dott. Verdi', $c['doctor'] );
		$this->assertSame( 'agonistic', $c['kind'] );
	}

	/** @dataProvider bad_certificates */
	public function test_certificate_clean_rejects( array $in ): void {
		$this->expectException( \InvalidArgumentException::class );
		CertificateRules::clean( $in );
	}

	public function bad_certificates(): array {
		return array(
			'tipo' => array( array( 'kind' => 'x', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-10' ) ),
			'rilascio' => array( array( 'kind' => 'other', 'issued_on' => '', 'expires_on' => '2027-01-10' ) ),
			'scadenza' => array( array( 'kind' => 'other', 'issued_on' => '2026-01-10', 'expires_on' => '2027-13-10' ) ),
			'ordine' => array( array( 'kind' => 'other', 'issued_on' => '2026-01-10', 'expires_on' => '2026-01-09' ) ),
		);
	}

	public function test_current_certificate(): void {
		$certs = array(
			array( 'id' => 1, 'issued_on' => '2024-01-01', 'expires_on' => '2025-01-01' ),
			array( 'id' => 2, 'issued_on' => '2025-01-01', 'expires_on' => '2026-01-01' ),
			array( 'id' => 3, 'issued_on' => '2026-06-01', 'expires_on' => '2027-06-01' ), // non ancora rilasciato
		);
		$this->assertSame( 2, CertificateRules::current( $certs, '2025-06-01' )['id'] );
		$this->assertSame( 3, CertificateRules::current( $certs, '2026-06-01' )['id'] );
		$this->assertNull( CertificateRules::current( array(), '2026-06-01' ) );
		$this->assertNull( CertificateRules::current( array( $certs[2] ), '2026-05-01' ) );
		$this->assertSame( ExpiryRules::NONE, CertificateRules::status( array(), '2026-05-01', 30 ) );
		$this->assertSame( ExpiryRules::EXPIRED, CertificateRules::status( $certs, '2026-05-01', 30 ) );
	}

	public function test_certificate_reminders(): void {
		$this->assertSame( 'cert_soon', CertificateRules::reminder_kind( ExpiryRules::EXPIRING, 10 ) );
		$this->assertSame( 'cert_expired', CertificateRules::reminder_kind( ExpiryRules::EXPIRED, -5 ) );
		$this->assertSame( '', CertificateRules::reminder_kind( ExpiryRules::EXPIRED, -31 ) ); // vecchio: niente promemoria
		$this->assertSame( '', CertificateRules::reminder_kind( ExpiryRules::VALID, 200 ) );
		$this->assertSame( '', CertificateRules::reminder_kind( ExpiryRules::NONE, 0 ) );
		$this->assertNotSame( CertificateRules::reminder_ref( 4, '2026-01-01' ), CertificateRules::reminder_ref( 4, '2027-01-01' ) );
	}

	public function test_filter_by_status(): void {
		$rows = array( array( 'status' => 'none' ), array( 'status' => 'expired' ), array( 'status' => 'none' ) );
		$this->assertCount( 2, CertificateRules::filter_by_status( $rows, 'none' ) );
		$this->assertCount( 3, CertificateRules::filter_by_status( $rows, '' ) );
	}

	public function test_seasons(): void {
		$this->assertSame( '2026', AffiliationRules::season( ' 2026 ' ) );
		$this->assertSame( '2025/2026', AffiliationRules::season( '2025-2026' ) );
		$this->assertSame( '2025/2026', AffiliationRules::season( '2025/26' ) );
		$this->assertSame( '2025/2026', AffiliationRules::default_season( '2026-03-01' ) );
		$this->assertSame( '2026/2027', AffiliationRules::default_season( '2026-09-01' ) );
		$this->expectException( \InvalidArgumentException::class );
		AffiliationRules::season( '2025/2027' );
	}

	public function test_affiliation_clean(): void {
		$a = AffiliationRules::clean_affiliation( array( 'body' => 'uisp', 'code' => ' AB123 ', 'season' => '2025/26', 'expires_on' => '2026-08-31', 'cost' => '120,50' ) );
		$this->assertSame( 'AB123', $a['code'] );
		$this->assertSame( 12050, $a['cost_cents'] );
		$this->assertSame( '2025/2026', $a['season'] );
		$this->assertSame( '', $a['body_label'] );
		$b = AffiliationRules::clean_affiliation( array( 'body' => 'other', 'body_label' => 'FIT', 'code' => '1', 'season' => '2026' ) );
		$this->assertNull( $b['cost_cents'] );
		$this->assertNull( $b['expires_on'] );
		$this->assertSame( 'FIT', AffiliationRules::body_label( $b['body'], $b['body_label'] ) );
	}

	/** @dataProvider bad_affiliations */
	public function test_affiliation_rejects( array $in ): void {
		$this->expectException( \InvalidArgumentException::class );
		AffiliationRules::clean_affiliation( $in );
	}

	public function bad_affiliations(): array {
		return array(
			'ente' => array( array( 'body' => 'zzz', 'code' => '1', 'season' => '2026' ) ),
			'altro senza nome' => array( array( 'body' => 'other', 'code' => '1', 'season' => '2026' ) ),
			'codice' => array( array( 'body' => 'csen', 'code' => ' ', 'season' => '2026' ) ),
			'costo' => array( array( 'body' => 'csen', 'code' => '1', 'season' => '2026', 'cost' => 'tanto' ) ),
			'negativo' => array( array( 'body' => 'csen', 'code' => '1', 'season' => '2026', 'cost' => '-5' ) ),
			'scadenza' => array( array( 'body' => 'csen', 'code' => '1', 'season' => '2026', 'expires_on' => '31/12/2026' ) ),
		);
	}

	public function test_card_clean(): void {
		$c = AffiliationRules::clean_card( array( 'body' => 'acsi', 'card_number' => 'T-9', 'season' => '2026', 'issued_on' => '2026-01-02', 'expires_on' => '2026-12-31', 'card_type' => 'Atleta' ) );
		$this->assertSame( 'Atleta', $c['card_type'] );
		$this->assertSame( '2026-01-02', $c['issued_on'] );
		$this->expectException( \InvalidArgumentException::class );
		AffiliationRules::clean_card( array( 'body' => 'acsi', 'season' => '2026', 'issued_on' => '2026-02-01', 'expires_on' => '2026-01-01' ) );
	}

	public function test_bulk_targets_skip_existing_and_duplicates(): void {
		$this->assertSame( array( 3, 5 ), AffiliationRules::new_card_targets( array( 1, 2, 3, 3, 0, 5 ), array( 1, '2' ) ) );
		$this->assertSame( array(), AffiliationRules::new_card_targets( array(), array( 1 ) ) );
	}

	public function test_affiliation_reminders(): void {
		$this->assertSame( 'aff_soon', AffiliationRules::reminder_kind( 'aff', ExpiryRules::EXPIRING, 3 ) );
		$this->assertSame( 'card_expired', AffiliationRules::reminder_kind( 'card', ExpiryRules::EXPIRED, -1 ) );
		$this->assertSame( '', AffiliationRules::reminder_kind( 'card', ExpiryRules::EXPIRED, -60 ) );
		$this->assertSame( '', AffiliationRules::reminder_kind( 'aff', ExpiryRules::NONE, 0 ) );
	}

	public function test_tax_code_sex_and_birth_place(): void {
		$this->assertSame( 'M', AffiliationRules::sex_from_tax_code( 'RSSMRA85T10A562S' ) );
		$this->assertSame( 'A562', AffiliationRules::birth_place_code( 'rssmra85t10a562s' ) );
		$this->assertSame( 'F', AffiliationRules::sex_from_tax_code( 'RSSMRA85T50A562S' ) ); // giorno + 40
		$this->assertSame( 'M', AffiliationRules::sex_from_tax_code( 'RSSMRA85T1MA562S' ) ); // omocodia: la cifra «1» scritta come «M»
		$this->assertSame( 'A562', AffiliationRules::birth_place_code( 'RSSMRA85T10A56NS' ) ); // omocodia nel luogo («N» al posto di 2)
		$this->assertSame( '', AffiliationRules::sex_from_tax_code( 'ABC' ) );
		$this->assertSame( '', AffiliationRules::birth_place_code( '' ) );
	}

	public function test_csv_row(): void {
		$p = array(
			'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => ' rssmra85t10a562s ', 'birth_date' => '1985-12-10', 'address' => 'Via Roma 1',
			'zip' => '09100', 'city' => 'Cagliari', 'province' => 'ca', 'email' => 'm@example.org', 'phone' => '+39 333 1234567',
		);
		$row = AffiliationRules::csv_row( $p, 'Atleta' );
		$this->assertCount( count( AffiliationRules::CSV_HEADER ), $row );
		$this->assertSame( array( 'Mario', 'Rossi', 'RSSMRA85T10A562S', '10/12/1985', 'A562', 'M', 'Via Roma 1, 09100 Cagliari (CA)', 'm@example.org', '+39 333 1234567', 'Atleta' ), $row );
		$empty = AffiliationRules::csv_row( array( 'first_name' => 'A', 'last_name' => 'B' ), '' );
		$this->assertSame( array( 'A', 'B', '', '', '', '', '', '', '', '' ), $empty );
	}
}

<?php
use AssociazioneSemplice\RowClean;
use AssociazioneSemplice\Text;
use PHPUnit\Framework\TestCase;

final class ImportCleanTest extends TestCase {

	public function test_plain_removes_tags_and_script_content(): void {
		$this->assertSame( 'Mario', Text::plain( '  <b>Mario</b> ' ) );
		$this->assertSame( 'Ciao', Text::plain( 'Ciao<script>alert(1)</script>' ) );
		$this->assertSame( 'x', Text::plain( '<style>p{}</style>x' ) );
		$this->assertSame( 'a < b', Text::plain( 'a < b' ), 'un minore non è un tag' );
		foreach ( array( 'bambini <10 anni', 'ti amo <3', 'a<b', 'x <= y', 'sconto <5€', 'cena <20 euro, poi <30' ) as $keep ) {
			$this->assertSame( $keep, Text::plain( $keep ), 'non si perde testo: ' . $keep );
		}
		$this->assertSame( 'bambini <10 anni e adulti', Text::plain( 'bambini <10 anni <b>e</b> adulti' ) );
		$this->assertSame( 'ok', Text::plain( 'o<!-- x -->k' ) );
		$this->assertSame(
			\AssociazioneSemplice\LedgerImport::key( '2026-01-02', '1', 'income', 1000, 'Quota', 'R1' ),
			\AssociazioneSemplice\LedgerImport::key( '2026-01-02', '1', 'income', 1000, '<b>Quota</b>', '<i>R1</i>' ),
			'la chiave dei doppioni ignora i tag'
		);
		$this->assertSame( "riga 1\nriga 2", Text::plain( "riga 1\nriga 2" ) );
		$this->assertSame( 'Rossi & figli', Text::plain( 'Rossi & figli' ) );
	}

	public function test_restore_rows_are_cleaned_like_forms(): void {
		$row = RowClean::row(
			'asem_people',
			array( 'id' => '7', 'first_name' => '<img src=x onerror=alert(1)>Anna', 'notes' => 'ok<script>x()</script>', 'application_data' => '{"a":"<b>"}', 'city' => null )
		);
		$this->assertSame( 'Anna', $row['first_name'] );
		$this->assertSame( 'ok', $row['notes'] );
		$this->assertSame( '{"a":"<b>"}', $row['application_data'], 'le altre colonne non si toccano' );
		$this->assertNull( $row['city'] );
		$this->assertSame( '7', $row['id'] );

		$tx = RowClean::row( 'asem_transactions', array( 'description' => '<a href="x">Quota</a>', 'document_ref' => '<i>R1</i>', 'void_reason' => '<u>err</u>' ) );
		$this->assertSame( array( 'description' => 'Quota', 'document_ref' => 'R1', 'void_reason' => 'err' ), $tx );

		$act = RowClean::row( 'asem_activities', array( 'name' => 'Yoga<script>1</script>', 'location' => '<b>Sala</b>' ) );
		$this->assertSame( array( 'name' => 'Yoga', 'location' => 'Sala' ), $act );

		$other = array( 'body' => '<b>html ammesso</b>' );
		$this->assertSame( $other, RowClean::row( 'asem_mail_templates', $other ), 'tabelle non elencate invariate' );
	}
}

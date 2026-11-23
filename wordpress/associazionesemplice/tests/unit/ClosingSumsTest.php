<?php
use AssociazioneSemplice\LedgerService;
use PHPUnit\Framework\TestCase;

/** Saldi salvati alla chiusura dell'anno fiscale: formato e scelta di quello da cui partire (il calcolo sul database è provato da tests/smoke_saldi_chiusura.php). */
final class ClosingSumsTest extends TestCase {

	public function test_encode_and_decode_keep_every_account(): void {
		$raw = LedgerService::closing_encode( '2024-12-31', array( 3 => 1200, 1 => -450, 7 => 0 ) );
		$this->assertSame( array( 'to' => '2024-12-31', 'd' => array( 3 => 1200, 1 => -450, 7 => 0 ) ), LedgerService::closing_decode( $raw ) );
		$this->assertSame( array( 'to' => '2024-12-31', 'd' => array() ), LedgerService::closing_decode( LedgerService::closing_encode( '2024-12-31', array() ) ), 'nessun movimento: somma vuota, ma salvata' );
		$this->assertSame( array( 'to' => '2024-12-31', 'd' => array( 1 => 5 ) ), LedgerService::closing_decode( LedgerService::closing_encode( '2024-12-31', array( 1 => 5 ) ) ), 'un solo conto con id 1 (non diventa un elenco)' );
	}

	public function test_decode_refuses_anything_unexpected(): void {
		$this->assertSame( '2024-12-31|3:1200,1:-450', LedgerService::closing_encode( '2024-12-31', array( 3 => 1200, 1 => -450 ) ), 'testo breve: data di fine, poi conto:somma' );
		foreach ( array( null, '', 'non valido', '2024-12-31', '31/12/2024|', '2024-12-31|1', '2024-12-31|a:2', '2024-12-31|1:2,', '2024-12-31|1:2:3', '2024-12-31|1:2,1:3' ) as $bad ) {
			$this->assertNull( LedgerService::closing_decode( $bad ), (string) $bad );
		}
	}

	public function test_pick_uses_the_latest_saved_closing_not_after_the_date(): void {
		$snaps = array(
			2024 => array( 'to' => '2024-12-31', 'd' => array( 1 => 30 ) ),
			2023 => array( 'to' => '2023-12-31', 'd' => null ), // anno chiuso senza saldo salvato
			2022 => array( 'to' => '2022-12-31', 'd' => array( 1 => 10 ) ),
		);
		$this->assertSame( '2024-12-31', LedgerService::closing_pick( $snaps, null )['to'] );
		$this->assertSame( '2024-12-31', LedgerService::closing_pick( $snaps, '2024-12-31' )['to'], 'l\'ultimo giorno dell\'anno chiuso vale' );
		$this->assertSame( '2022-12-31', LedgerService::closing_pick( $snaps, '2024-12-30' )['to'], 'il 2023 non ha il saldo: si parte dal 2022' );
		$this->assertSame( '2022-12-31', LedgerService::closing_pick( $snaps, '2023-06-30' )['to'] );
		$this->assertNull( LedgerService::closing_pick( $snaps, '2022-12-30' ), 'prima della prima chiusura: tutta la storia' );
		$this->assertNull( LedgerService::closing_pick( array(), null ) );
		$this->assertNull( LedgerService::closing_pick( array( 2023 => array( 'to' => '2023-12-31', 'd' => null ) ), null ) );
	}

	public function test_schema_has_the_column_and_close_and_reopen_use_it(): void {
		$root = dirname( __DIR__, 2 ) . '/includes/';
		$this->assertMatchesRegularExpression( '/CREATE TABLE \{\$p\}fiscal_years \([^;]*\n  closing_sums text DEFAULT NULL,/', (string) file_get_contents( $root . 'Install.php' ) );
		$fy = (string) file_get_contents( $root . 'FiscalYears.php' );
		$this->assertStringContainsString( 'save_closing_sums(', $fy, 'la chiusura salva la somma' );
		$this->assertStringContainsString( "'closing_sums' => null", $fy, 'la riapertura la cancella' );
		$this->assertStringContainsString( 'forget_closing_sums()', (string) file_get_contents( $root . 'Backup.php' ), 'il ripristino di una copia la cancella' );
	}
}

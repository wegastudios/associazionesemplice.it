<?php
use AssociazioneSemplice\LongJob;
use PHPUnit\Framework\TestCase;

/** Motore dei lavori lunghi: tempo di una tappa, percentuale, chiavi e collegamenti (il resto lo prova smoke_long_job.php in WordPress). */
final class LongJobTest extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel );
	}

	public function test_a_slice_lasts_twenty_seconds_or_half_of_a_short_server_limit(): void {
		$this->assertSame( 20.0, LongJob::server_budget( 0 ) );    // nessun limite (riga di comando)
		$this->assertSame( 20.0, LongJob::server_budget( 300 ) );
		$this->assertSame( 15.0, LongJob::server_budget( 30 ) );   // hosting con 30 secondi
		$this->assertSame( 6.0, LongJob::server_budget( 12 ) );
		$this->assertSame( 5.0, LongJob::server_budget( 8 ) );     // mai meno di 5
	}

	public function test_percent_is_whole_and_reaches_100_only_at_the_end(): void {
		$this->assertSame( 0, LongJob::percent( 0, 0, false ) );   // totale non ancora noto
		$this->assertSame( 50, LongJob::percent( 5, 10, false ) );
		$this->assertSame( 99, LongJob::percent( 999, 1000, false ) );
		$this->assertSame( 100, LongJob::percent( 12, 10, false ) );
		$this->assertSame( 100, LongJob::percent( 0, 0, true ) );
	}

	public function test_keys_are_clean_and_fit_the_option_name(): void {
		$this->assertSame( 'asem_receipts_zip', LongJob::clean_key( 'asem_Receipts_ZIP' ) );
		$this->assertSame( 'asemx', LongJob::clean_key( "asem'<x>" ) );
		$this->assertSame( 64, strlen( LongJob::clean_key( str_repeat( 'a', 200 ) ) ) );
	}

	public function test_the_engine_is_wired_without_loading_on_every_request(): void {
		$plugin = self::src( 'includes/Plugin.php' );
		$this->assertMatchesRegularExpression( "/'asem_long_job_tick'\\s*=>\\s*array\\(\\s*'',\\s*0,\\s*'LongJob',\\s*'tick'/", $plugin, 'la rete di sicurezza si registra per nome: la classe si carica solo quando il cron parte' );
		$this->assertStringContainsString( "'wp_ajax_asem_long_job', array( \\AssociazioneSemplice\\LongJob::class, 'ajax' )", self::src( 'includes/Admin/Admin.php' ) );
		$this->assertStringContainsString( "LongJob::need(", self::src( 'includes/Employees.php' ) );
		$this->assertStringContainsString( "LongJob::acquire( self::OPT_LOCK, self::LOCK_SECONDS )", self::src( 'includes/ImportJob.php' ), 'l\'importazione usa lo stesso blocco con scadenza' );
		$this->assertStringContainsString( "LongJob::server_budget()", self::src( 'includes/ImportJob.php' ), 'e lo stesso tempo di una tappa' );
		$this->assertStringContainsString( "'asem_long_job'", self::src( 'assets/admin.js' ) );
	}

	public function test_screen_texts_say_operation_not_job(): void {
		$src = self::src( 'includes/LongJob.php' );
		$en  = json_decode( self::src( 'includes/languages/en.json' ), true );
		foreach ( array( 'Operazione completata.', 'Operazione annullata.', 'Questa operazione non esiste più: ricarica la pagina.', 'Nessuna operazione in corso.', 'Connessione interrotta: nuovo tentativo tra qualche secondo.' ) as $k ) {
			$this->assertStringContainsString( "'" . $k . "'", $src, $k );
			$this->assertArrayHasKey( $k, $en['strings'], $k );
		}
		preg_match_all( "/Texts::t\( '((?:[^'\\\\]|\\\\.)*)'/", $src, $m );
		foreach ( $m[1] as $t ) {
			$this->assertDoesNotMatchRegularExpression( '/\blavor[oi]\b/i', $t, 'testo a schermo con «lavoro»: ' . $t );
		}
		$this->assertArrayNotHasKey( 'Lavoro finito.', $en['strings'] );
		$this->assertStringNotContainsString( 'circa 6 ore', self::src( 'includes/Admin/LimitsPage.php' ) );
		$this->assertStringContainsString( 'circa 5 ore', self::src( 'includes/Admin/LimitsPage.php' ) );
		$this->assertStringContainsString( '250 email', self::src( 'includes/Admin/LimitsPage.php' ) );
		$this->assertStringContainsString( 'Nessuna persona trovata con quel nome: scrivi almeno tre lettere o il numero di tessera.', self::src( 'includes/Admin/PersonSearch.php' ) );
		$this->assertArrayNotHasKey( 'Non ho trovato nessuna persona con quel nome: scrivine almeno tre lettere, o il numero di tessera.', $en['strings'] );
	}

	public function test_new_texts_with_placeholders_are_translated(): void {
		$en = json_decode( self::src( 'includes/languages/en.json' ), true );
		foreach ( array( "L'operazione si è fermata per un errore: {error}", 'In corso: {done} di {total} ({percent}%).' ) as $k ) {
			$this->assertArrayHasKey( $k, $en['strings'], $k );
		}
	}
}

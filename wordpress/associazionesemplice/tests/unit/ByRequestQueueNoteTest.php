<?php
use PHPUnit\Framework\TestCase;

/** Nella scheda di un evento su richiesta la coda resta visibile e dice che non avanza da sola. */
final class ByRequestQueueNoteTest extends TestCase {

	private const PHRASE = "Evento su richiesta: la lista d'attesa non avanza da sola. Prenota tu chi vuoi far entrare.";

	public function test_queue_note_is_shown_only_for_by_request_events(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/ActivitiesPage.php' );
		$this->assertStringContainsString( "Evento su richiesta: la lista d\\'attesa non avanza da sola. Prenota tu chi vuoi far entrare.", $src );
		$this->assertStringContainsString( "PublicEvents::options( (int) \$activity['id'] )['approval']", $src );
		$this->assertStringContainsString( 'entrano da soli, nell\\\'ordine, se si libera un posto.', $src, 'negli altri eventi la riga di prima non cambia' );
		$this->assertStringContainsString( '$by_request ?', $src );
	}

	public function test_phrase_is_translated(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/languages/en.json' ), true );
		$this->assertIsArray( $json );
		$tr = $json['strings'][ self::PHRASE ] ?? $json[ self::PHRASE ] ?? null;
		$this->assertSame( 'By-request event: the waiting list does not move on its own. Book the people you want to let in.', $tr );
	}
}

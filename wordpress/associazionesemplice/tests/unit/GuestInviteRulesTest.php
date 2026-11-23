<?php
use AssociazioneSemplice\GuestInviteRules;
use PHPUnit\Framework\TestCase;

/** Quando l'ingresso di un ospite genera l'avviso «da invitare ad associarsi», e come il testo si aggancia al messaggio (parte pura; il resto è in tests/smoke_invito_ospiti.php). */
final class GuestInviteRulesTest extends TestCase {

	public function test_a_member_never_gets_the_notice(): void {
		$this->assertSame( array(), GuestInviteRules::reasons( false, 9, 2, 'Anna Rossi', 'Cena' ) );
	}

	public function test_a_guest_at_an_event_always_has_the_event_reason(): void {
		$r = GuestInviteRules::reasons( true, 1, 3, '', 'Cena sociale' );
		$this->assertSame( array( array( 'code' => 'event', 'name' => 'Cena sociale' ) ), $r );
	}

	public function test_threshold_needs_a_limit_and_a_total_that_reaches_it(): void {
		$this->assertSame( 'event', GuestInviteRules::reasons( true, 5, 0, '', 'X' )[0]['code'], 'soglia 0: nessuna segnalazione' );
		$this->assertSame( 'event', GuestInviteRules::reasons( true, 1, 2, '', 'X' )[0]['code'] );
		$r = GuestInviteRules::reasons( true, 2, 2, '', 'X' );
		$this->assertSame( array( 'code' => 'threshold', 'n' => 2 ), $r[0] );
	}

	public function test_reasons_come_in_a_fixed_order_and_all_together(): void {
		$r = GuestInviteRules::reasons( true, 4, 3, 'Bruno Verdi', 'Gita' );
		$this->assertSame( array( 'threshold', 'host', 'event' ), array_column( $r, 'code' ) );
		$this->assertSame( 'Bruno Verdi', $r[1]['who'] );
		$this->assertSame( 4, $r[0]['n'] );
	}

	public function test_blank_host_or_event_is_skipped(): void {
		$this->assertSame( array(), GuestInviteRules::reasons( true, 1, 3, '  ', ' ' ) );
	}

	public function test_message_is_joined_and_split_again(): void {
		$msg = GuestInviteRules::join( 'Ingresso registrato: Olga.', 'Olga è qui su invito di Bruno.' );
		$this->assertSame( array( 'Ingresso registrato: Olga.', 'Olga è qui su invito di Bruno.' ), GuestInviteRules::split( $msg ) );
		$this->assertSame( 'Ingresso registrato: Olga.', GuestInviteRules::join( 'Ingresso registrato: Olga.', '' ) );
		$this->assertSame( array( 'Solo questo.', '' ), GuestInviteRules::split( 'Solo questo.' ) );
	}

	public function test_notice_id_is_the_same_for_the_same_person_and_date(): void {
		$this->assertSame( GuestInviteRules::notice_id( 7, 12 ), GuestInviteRules::notice_id( 7, 12 ) );
		$this->assertNotSame( GuestInviteRules::notice_id( 7, 12 ), GuestInviteRules::notice_id( 7, 13 ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', GuestInviteRules::notice_id( 7, 12 ) );
	}

	public function test_queue_keeps_the_newest_up_to_the_maximum(): void {
		$all = array( 'a' => array( 'at' => 100 ), 'b' => array( 'at' => 300 ), 'c' => array( 'at' => 200 ) );
		$this->assertSame( array( 'b', 'c' ), array_keys( GuestInviteRules::trim( $all, 2 ) ) );
		$this->assertSame( array( 'b', 'c', 'a' ), array_keys( GuestInviteRules::trim( $all, 10 ) ) );
	}
}

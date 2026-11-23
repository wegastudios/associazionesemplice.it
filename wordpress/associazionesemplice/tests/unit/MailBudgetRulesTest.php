<?php
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MailBudgetRules;
use PHPUnit\Framework\TestCase;

/** Tetto orario delle email: le regole pure (il conteggio vero e l'invio a tappe sono provati da tests/smoke.php). */
final class MailBudgetRulesTest extends TestCase {

	public function test_the_cap_is_a_limit_with_default_200(): void {
		$this->assertSame( 200, Limits::default_of( 'mail_per_hour' ) );
		$defs = Limits::defs();
		$this->assertSame( 'comunicazioni', $defs['mail_per_hour']['group'] );
		$this->assertSame( 20, $defs['mail_per_hour']['min'] );
		$this->assertSame( 5000, $defs['mail_per_hour']['max'] );
	}

	public function test_hour_slots_follow_the_clock_hour(): void {
		$t = 1700000000; // 2023-11-14 22:13:20 UTC
		$this->assertSame( intdiv( $t, 3600 ), MailBudgetRules::slot( $t ) );
		$this->assertSame( MailBudgetRules::slot( $t ), MailBudgetRules::slot( $t - ( $t % 3600 ) ) );
		$this->assertSame( MailBudgetRules::slot( $t ) + 1, MailBudgetRules::slot( MailBudgetRules::next_slot_at( $t ) ) );
		$this->assertSame( 0, MailBudgetRules::next_slot_at( $t ) % 3600 );
		$this->assertGreaterThan( $t, MailBudgetRules::next_slot_at( $t ) );
		$this->assertLessThanOrEqual( $t + 3600, MailBudgetRules::next_slot_at( $t ) );
	}

	public function test_a_fifth_of_the_cap_stays_for_single_emails(): void {
		$this->assertSame( 40, MailBudgetRules::reserve( 200 ) );
		$this->assertSame( 5, MailBudgetRules::reserve( 20 ) );
		$this->assertSame( 8, MailBudgetRules::reserve( 40 ) );
		$this->assertSame( 2, MailBudgetRules::reserve( 3 ), 'la riserva non supera mai il tetto' );
		$this->assertSame( 0, MailBudgetRules::reserve( 1 ) );
	}

	public function test_bulk_room_is_the_cap_minus_reserve_minus_used_and_never_negative(): void {
		$this->assertSame( 160, MailBudgetRules::bulk_room( 200, 0 ) );
		$this->assertSame( 110, MailBudgetRules::bulk_room( 200, 50 ) );
		$this->assertSame( 0, MailBudgetRules::bulk_room( 200, 160 ) );
		$this->assertSame( 0, MailBudgetRules::bulk_room( 200, 900 ) );
		$this->assertSame( 15, MailBudgetRules::bulk_room( 20, 0 ) );
	}

	public function test_recipients_are_counted_one_per_address(): void {
		$this->assertSame( 1, MailBudgetRules::recipients( 'a@example.com' ) );
		$this->assertSame( 3, MailBudgetRules::recipients( array( 'a@example.com', 'b@example.com', 'c@example.com' ) ) );
		$this->assertSame( 2, MailBudgetRules::recipients( 'a@example.com, b@example.com' ) );
		$this->assertSame( 2, MailBudgetRules::recipients( array( 'Anna <a@example.com>', 'b@example.com' ) ) );
		$this->assertSame( 1, MailBudgetRules::recipients( '' ), 'un invio conta almeno uno' );
		$this->assertSame( 1, MailBudgetRules::recipients( array() ) );
	}

	public function test_external_service_is_recognised(): void {
		$this->assertTrue( MailBudgetRules::looks_external( 'smtp', 'smtp.fornitore.it', false, array() ) );
		$this->assertFalse( MailBudgetRules::looks_external( 'smtp', 'localhost', false, array() ), 'un SMTP sullo stesso server non è un servizio esterno' );
		$this->assertFalse( MailBudgetRules::looks_external( 'mail', '', false, array() ) );
		$this->assertTrue( MailBudgetRules::looks_external( 'mail', '', true, array() ), 'un plugin che prende in carico l\'invio' );
		$this->assertTrue( MailBudgetRules::looks_external( 'mail', '', false, array( 'akismet/akismet.php', 'wp-mail-smtp/wp_mail_smtp.php' ) ) );
		$this->assertFalse( MailBudgetRules::looks_external( 'mail', '', false, array( 'akismet/akismet.php' ) ) );
	}
}

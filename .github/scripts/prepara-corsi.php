<?php
/**
 * Dati per la prova della cassa dei corsi nel browser (workflow «Prove in sandbox»): un corso con lezione ogni giorno, in proporzione, e una persona non
 * iscritta. Stampa una riga JSON con gli importi calcolati dal server.
 */
use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 );
$slots = array();
for ( $d = 1; $d <= 7; $d++ ) {
	$slots[] = array( 'type' => 'weekly', 'day' => $d, 'start' => '18:00', 'end' => '19:00' );
}
$acts = Plugin::activities();
$base = array( 'social_year' => Settings::social_year()->label(), 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 6200, 'guest_fee_cents' => 9300, 'trial_days' => 1, 'lesson_slots' => $slots );
$c1   = (int) $acts->create( $base + array( 'name' => 'Prova cassa in proporzione', 'first_month' => 'share' ) );
$c2   = (int) $acts->create( $base + array( 'name' => 'Prova cassa mese intero', 'first_month' => 'full' ) );
$pid  = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Cassa', 'last_name' => 'Browser', 'email' => 'cassa.browser@example.com', 'birth_date' => '1985-01-01' ) );
Plugin::people()->set_membership( $pid, Settings::membership_year()->label(), true );
$p    = Plugin::people()->get( $pid );
$ym   = substr( Db::today(), 0, 7 );
echo wp_json_encode(
	array(
		'person'  => $pid,
		'share'   => array( 'id' => $c1, 'member' => $acts->course_month_fee( $acts->get( $c1 ), $p, $ym ), 'guest' => $acts->course_month_fee( $acts->get( $c1 ), array( 'id' => 0, 'type' => MemberType::GUEST ), $ym ) ),
		'full'    => array( 'id' => $c2, 'member' => $acts->course_month_fee( $acts->get( $c2 ), $p, $ym ) ),
		'fee'     => 6200,
		'group'   => class_exists( '\AssociazioneSemplice\Admin\GroupCashPage' ),
	)
) . "\n";

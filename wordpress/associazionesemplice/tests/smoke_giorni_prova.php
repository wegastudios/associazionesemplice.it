<?php
/**
 * Giorni di prova gratuiti dei corsi: la prova non crea debito; chi si iscrive nello stesso mese paga dal giorno della prova (proporzione fissata all'iscrizione),
 * chi si iscrive più tardi no; oltre i giorni previsti solo un avviso; senza prove nulla cambia. Si carica da smoke.php.
 */

use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Trials;

global $wpdb;
wp_set_current_user( 1 );
$pr_acts  = Plugin::activities();
$pr_peo   = Plugin::people();
$pr_sy    = Settings::social_year()->label();
$pr_today = current_time( 'Y-m-d' );
$pr_month = substr( $pr_today, 0, 7 );
$pr_tot   = (int) gmdate( 't', strtotime( $pr_month . '-01' ) );
$pr_left  = $pr_tot - (int) substr( $pr_today, 8, 2 ) + 1;
$pr_due   = (int) round( 4000 * $pr_left / $pr_tot );
$pr_n     = 0;
$pr_man   = function ( string $name ) use ( $pr_peo, $pr_sy, &$pr_n ): int {
	$pr_n++;
	$id = (int) $pr_peo->create( array( 'type' => 'ordinary', 'card_number' => '88' . $pr_n, 'first_name' => $name, 'last_name' => 'Provante', 'email' => 'provante' . $pr_n . '@example.com', 'birth_date' => '1985-01-01' ) );
	$pr_peo->set_membership( $id, $pr_sy, true );
	return $id;
};
$pr_course = function ( string $name, array $extra = array() ) use ( $pr_acts, $pr_sy ): int {
	return (int) $pr_acts->create( array_merge( array( 'name' => $name, 'social_year' => $pr_sy, 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'trial_days' => 1 ), $extra ) );
};
$pr_sum = function ( int $aid, int $pid ) use ( $pr_acts ): ?array {
	foreach ( $pr_acts->status_for_activity( $aid ) as $s ) {
		if ( (int) $s['enrollment']['person_id'] === $pid ) {
			return $s['summary'];
		}
	}
	return null;
};

// La prova è gratuita e non iscrive
$pr_c = $pr_course( 'Corso con prova' );
$pr_a = $pr_man( 'Anna' );
asem_ok( 1 === (int) $pr_acts->get( $pr_c )['trial_days'], 'prova: il corso ricorda i giorni di prova' );
asem_ok( '' === Trials::add( $pr_c, $pr_a, $pr_today ) && null === $pr_sum( $pr_c, $pr_a ), 'prova: registrata, senza iscrizione' );
asem_ok( array() === array_filter( Plugin::payments()->dues_for( $pr_peo->get( $pr_a ) ), function ( $i ) use ( $pr_c ) { return (int) ( $i['activity_id'] ?? 0 ) === $pr_c; } ), 'prova: nessun debito' );
asem_ok( 1 === count( array_filter( Trials::not_enrolled( $pr_today ), function ( $r ) use ( $pr_a ) { return (int) $r['person_id'] === $pr_a; } ) ), 'prova: in Bacheca tra chi non si è iscritto' );
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c, $pr_a, $pr_today ) { Trials::add( $pr_c, $pr_a, $pr_today ); } ), 'prova: lo stesso giorno non si registra due volte' );

// Iscritta nello stesso mese: paga dal giorno della prova, anche se quel mese in parte è passato
asem_ok( $pr_due === $pr_acts->course_month_fee( $pr_acts->get( $pr_c ), $pr_peo->get( $pr_a ), $pr_month ), 'prova: la quota prevista, prima di iscriversi, è dal giorno della prova' );
$pr_acts->enroll( $pr_c, $pr_a, $pr_month );
$pr_s = $pr_sum( $pr_c, $pr_a );
asem_ok( $pr_due === (int) $pr_s['total_due'] && $pr_due === (int) $pr_acts->enrollment_left( $pr_c, $pr_a, $pr_month ), 'prova: il primo mese si paga dal giorno della prova (' . $pr_due . ')' );
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c, $pr_a ) { Trials::remove( (int) Trials::of_activity( $pr_c )[0]['id'] ); } ), 'prova: usata per l\'iscrizione, non si toglie' );
$wpdb->update( Db::t( 'activities' ), array( 'fee_cents' => 4000, 'lesson_weekday' => 1 ), array( 'id' => $pr_c ) );
$pr_ledger = Plugin::ledger();
$pr_cash   = $pr_ledger->default_account_for( 'cash' );
$pr_rec    = function ( int $cents ) use ( $pr_ledger, $pr_cash, $pr_c, $pr_a, $pr_today, $pr_month ) {
	$pr_ledger->record_receipt( array( 'date' => $pr_today, 'account_id' => (int) $pr_cash['id'], 'method' => 'cash', 'person_id' => $pr_a, 'check_due' => true, 'lines' => array( array( 'category_id' => $pr_ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => $pr_c, 'competence_month' => $pr_month ) ) ) );
};
if ( $pr_due < 4000 ) {
	asem_ok( '' !== (string) asem_throws( function () use ( $pr_rec ) { $pr_rec( 4000 ); } ), 'prova: il mese intero non si incassa' );
}
$pr_rec( $pr_due );
asem_ok( 0 === (int) $pr_acts->enrollment_left( $pr_c, $pr_a, $pr_month ), 'prova: incassata la parte dovuta non resta nulla' );
$pr_next = gmdate( 'Y-m', strtotime( $pr_month . '-01 +1 month' ) );
asem_ok( 4000 === (int) $pr_acts->enrollment_left( $pr_c, $pr_a, $pr_next ), 'prova: dal mese dopo la quota è intera' );

// Senza prova: mese intero, come sempre
$pr_b = $pr_man( 'Bea' );
$pr_acts->enroll( $pr_c, $pr_b, $pr_month );
asem_ok( 4000 === (int) $pr_acts->enrollment_left( $pr_c, $pr_b, $pr_month ), 'prova: chi non l\'ha fatta paga il mese intero' );

// Iscritta in un mese dopo: prova gratuita e avviso
$pr_c2 = $pr_course( 'Corso con prova, mese dopo' );
$pr_d  = $pr_man( 'Dora' );
$wpdb->insert( Db::t( 'trials' ), array( 'activity_id' => $pr_c2, 'person_id' => $pr_d, 'trial_date' => gmdate( 'Y-m-d', strtotime( $pr_month . '-01 -3 days' ) ), 'created_at' => Db::now() ) );
asem_ok( 0 === strpos( Trials::note( $pr_c2, $pr_d ), 'Ha già fatto la prova il ' ), 'prova: chi iscrive il mese dopo legge che l\'ha già fatta' );
$pr_acts->enroll( $pr_c2, $pr_d, $pr_month );
asem_ok( 4000 === (int) $pr_acts->enrollment_left( $pr_c2, $pr_d, $pr_month ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT trial_from FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $pr_c2, $pr_d ) ), 'prova: iscritta il mese dopo, la prova resta gratuita e il mese è intero' );

// Seconda prova: solo un avviso; corso senza prova: si rifiuta; trimestre e pagamento unico: importo intero
$pr_c3 = $pr_course( 'Corso con due prove', array( 'trial_days' => 1 ) );
$pr_e  = $pr_man( 'Elsa' );
$wpdb->insert( Db::t( 'trials' ), array( 'activity_id' => $pr_c3, 'person_id' => $pr_e, 'trial_date' => gmdate( 'Y-m-d', strtotime( $pr_today . ' -1 day' ) ), 'created_at' => Db::now() ) );
asem_ok( 0 === strpos( Trials::add( $pr_c3, $pr_e, $pr_today ), 'Attenzione' ) && 2 === count( Trials::dates( $pr_c3, $pr_e ) ), 'prova: oltre i giorni previsti si registra con un avviso' );
$pr_c4 = $pr_course( 'Corso senza prova', array( 'trial_days' => 0 ) );
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c4, $pr_e, $pr_today ) { Trials::add( $pr_c4, $pr_e, $pr_today ); } ), 'prova: un corso senza prova la rifiuta' );
foreach ( array( 'once', 'quarterly' ) as $pr_bill ) {
	$pr_cb = $pr_course( 'Corso ' . $pr_bill, array( 'billing' => $pr_bill ) );
	$pr_f  = $pr_man( 'Fede' );
	Trials::add( $pr_cb, $pr_f, $pr_today );
	$pr_acts->enroll( $pr_cb, $pr_f, $pr_month );
	asem_ok( 4000 === (int) $pr_acts->enrollment_left( $pr_cb, $pr_f, $pr_month ), 'prova: con ' . $pr_bill . ' l\'importo resta intero' );
}

// Proporzione a lezioni: quattro lezioni settimanali, prova alla terza = metà
$pr_mon = $pr_month . '-01';
$pr_wd  = (int) gmdate( 'N', strtotime( $pr_mon ) );
$pr_c5  = $pr_course( 'Corso a lezioni', array( 'lesson_slots' => array( array( 'day' => $pr_wd, 'start' => '18:00', 'end' => '19:00' ) ) ) );
$pr_l   = ActivityReset::course_dates( $pr_acts->get( $pr_c5 ), $pr_mon, gmdate( 'Y-m-t', strtotime( $pr_mon ) ) );
asem_ok( count( $pr_l ) >= 4 && array( count( $pr_l ) - 2, count( $pr_l ) ) === Trials::ratio( $pr_acts->get( $pr_c5 ), $pr_l[2] ), 'prova: alla terza lezione restano le lezioni da quella compresa' );
$pr_nol = gmdate( 'Y-m-d', strtotime( $pr_l[0] . ' +1 day' ) );
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c5, $pr_e, $pr_nol ) { Trials::add( $pr_c5, $pr_e, $pr_nol ); } ), 'prova: in un giorno senza lezione si rifiuta' );

// Già iscritto: la prova non serve; togliere una prova; dati scaricati; presenze
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c, $pr_b, $pr_today ) { Trials::add( $pr_c, $pr_b, $pr_today ); } ), 'prova: chi è già iscritto non la fa' );
$pr_c6 = $pr_course( 'Corso da togliere' );
Trials::add( $pr_c6, $pr_e, $pr_today );
$pr_exp = (string) wp_json_encode( \AssociazioneSemplice\Privacy::export( $pr_e )['prove_gratuite'] );
asem_ok( false !== strpos( $pr_exp, 'Corso da togliere' ), 'prova: nei dati scaricati' );
if ( class_exists( '\AssociazioneSemplice\Attendance' ) ) {
	$pr_ros = array_column( \AssociazioneSemplice\Attendance::roster( $pr_acts->get( $pr_c6 ), $pr_today ), 'first_name' );
	asem_ok( array( 'Elsa (prova)' ) === $pr_ros, 'prova: nell\'elenco delle presenze è riconoscibile' );
}
Trials::remove( (int) Trials::of_activity( $pr_c6 )[0]['id'] );
asem_ok( array() === Trials::dates( $pr_c6, $pr_e ), 'prova: registrata per errore, si toglie' );
$_GET['id'] = (string) $pr_c;
ob_start();
\AssociazioneSemplice\Admin\ActivitiesPage::render_detail();
$pr_html = (string) ob_get_clean();
unset( $_GET['id'] );
asem_ok( false !== strpos( $pr_html, 'Prove gratuite' ) && false !== strpos( $pr_html, 'paga da questo giorno' ) && false !== strpos( $pr_html, 'name="trial_days"' ), 'prova: la scheda del corso ha il riquadro e l\'impostazione' );

// Nuovo ospite in prova dal pannello: se la prova non si può registrare non resta l'ospite
$pr_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$pr_m      = new \ReflectionMethod( \AssociazioneSemplice\Admin\Actions::class, 'trial_add' );
$pr_m->setAccessible( true );
asem_ok( '' !== (string) asem_throws( function () use ( $pr_m, $pr_c4, $pr_a, $pr_today ) { $pr_m->invoke( null, array( 'activity_id' => $pr_c4, 'new_first_name' => 'Osvaldo', 'new_last_name' => 'Prova', 'new_phone' => '333 4440001', 'new_host_id' => $pr_a, 'trial_date' => $pr_today ) ); } ) && $pr_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'prova: se non si può registrare non resta l\'ospite' );
$pr_m->invoke( null, array( 'activity_id' => $pr_c6, 'new_first_name' => 'Osvaldo', 'new_last_name' => 'Prova', 'new_phone' => '333 4440001', 'new_host_id' => $pr_a, 'trial_date' => $pr_today ) );
asem_ok( $pr_before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ) && 'Osvaldo' === Trials::of_activity( $pr_c6 )[0]['first_name'], 'prova: ospite nuovo e prova insieme' );

<?php
/**
 * Giorni di prova gratuiti dei corsi: la prova non crea debito; chi si iscrive nello stesso mese paga dal giorno della prova (proporzione fissata all'iscrizione),
 * chi si iscrive più tardi no; oltre i giorni previsti solo un avviso; senza prove nulla cambia. Si carica da smoke.php.
 */

use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\PaymentCalc;
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
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c, $pr_a ) { Trials::remove( (int) Trials::of_activity( $pr_c )[0]['id'], $pr_c ); } ), 'prova: usata per l\'iscrizione, non si toglie' );
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
	$pr_ros = \AssociazioneSemplice\Attendance::roster( $pr_acts->get( $pr_c6 ), $pr_today );
	asem_ok( 1 === count( $pr_ros ) && 'Elsa' === $pr_ros[0]['first_name'] && 1 === (int) $pr_ros[0]['trial'], 'prova: nell\'elenco delle presenze è riconoscibile' );
}
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c6, $pr_c5 ) { Trials::remove( (int) Trials::of_activity( $pr_c6 )[0]['id'], $pr_c5 ); } ) && 1 === count( Trials::of_activity( $pr_c6 ) ), 'prova: non si toglie passando un\'altra attività' );
Trials::remove( (int) Trials::of_activity( $pr_c6 )[0]['id'], $pr_c6 );
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

// ---------- Data futura, anno chiuso, persona sospesa, ospiti spenti ----------
$pr_c7 = $pr_course( 'Corso controlli' );
asem_ok( false !== strpos( (string) asem_throws( function () use ( $pr_c7, $pr_e, $pr_today ) { Trials::add( $pr_c7, $pr_e, gmdate( 'Y-m-d', strtotime( $pr_today . ' +1 day' ) ) ); } ), 'non in anticipo' ), 'prova: una data futura si rifiuta con la spiegazione' );
$pr_fyn  = \AssociazioneSemplice\FiscalYears::year_of( $pr_today );
\AssociazioneSemplice\FiscalYears::ensure( $pr_fyn );
$pr_fy   = \AssociazioneSemplice\FiscalYears::get( $pr_fyn );
Trials::add( $pr_c7, $pr_e, $pr_today );
$pr_tx_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $pr_fyn ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();

$pr_w1 = (string) asem_throws( function () use ( $pr_c7, $pr_a, $pr_today ) { Trials::add( $pr_c7, $pr_a, $pr_today ); } );
$pr_w2 = (string) asem_throws( function () use ( $pr_c7 ) { Trials::remove( (int) Trials::of_activity( $pr_c7 )[0]['id'], $pr_c7 ); } );
$pr_acts->enroll( $pr_c7, $pr_e, $pr_month );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => $pr_fy['status'] ), array( 'year' => $pr_fyn ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( '' !== $pr_w1 && '' !== $pr_w2 && 1 === count( Trials::of_activity( $pr_c7 ) ), 'prova: in un anno fiscale chiuso non si aggiunge e non si toglie: ' . $pr_w1 );
asem_ok( $pr_tx_n === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ), 'prova: iscrivere con l\'anno chiuso non scrive nulla in prima nota' );
$pr_h = $pr_man( 'Ines' );
$wpdb->update( Db::t( 'people' ), array( 'suspended_at' => Db::now() ), array( 'id' => $pr_h ) );
wp_cache_flush();
asem_ok( '' !== (string) asem_throws( function () use ( $pr_m, $pr_c7, $pr_h, $pr_today ) { $pr_m->invoke( null, array( 'activity_id' => $pr_c7, 'person_id' => $pr_h, 'trial_date' => $pr_today ) ); } ) && array() === Trials::dates( $pr_c7, $pr_h ), 'prova: una persona sospesa si rifiuta come nell\'iscrizione' );
Settings::update( array( 'guests_enabled' => 0 ) );
$pr_gn = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$pr_gw = (string) asem_throws( function () use ( $pr_m, $pr_c7, $pr_a, $pr_today ) { $pr_m->invoke( null, array( 'activity_id' => $pr_c7, 'new_first_name' => 'Ugo', 'new_last_name' => 'Spento', 'new_phone' => '333 4440009', 'new_host_id' => $pr_a, 'trial_date' => $pr_today ) ); } );
Settings::update( array( 'guests_enabled' => 1 ) );
asem_ok( '' !== $pr_gw && $pr_gn === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'prova: con gli ospiti spenti l\'ospite nuovo si rifiuta: ' . $pr_gw );

// Dipendente con i soli eventi: con l'id di un evento non tocca la prova di un corso; il permesso segue il corso
$pr_ev = (int) $pr_acts->create( array( 'name' => 'Evento per permessi', 'social_year' => $pr_sy, 'kind' => 'event', 'fee_cents' => 0, 'session' => array( 'session_date' => $pr_today, 'capacity' => 5 ) ) );
foreach ( array( 'asem_trial_add', 'asem_trial_remove' ) as $pr_act ) {
	asem_ok( array( \AssociazioneSemplice\EmployeeRules::COURSES ) === \AssociazioneSemplice\Employees::activities_need( array( 'activity_id' => $pr_c7, 'trial_id' => 1 ), $pr_act )['all'], 'prova: ' . $pr_act . ' chiede «Corsi»' );
	asem_ok( in_array( $pr_act, \AssociazioneSemplice\EmployeeRules::activity_actions(), true ), 'prova: ' . $pr_act . ' è tra le azioni controllate' );
}
asem_ok( '' !== (string) asem_throws( function () use ( $pr_c7, $pr_ev ) { Trials::remove( (int) Trials::of_activity( $pr_c7 )[0]['id'], $pr_ev ); } ) && 1 === count( Trials::of_activity( $pr_c7 ) ), 'prova: con l\'id di un evento la prova del corso non si tocca' );

// ---------- Cambio di mese: ciò che era dovuto per il mese della prova non cambia ----------
$pr_c8 = $pr_course( 'Corso cambio mese' );
$pr_i  = $pr_man( 'Lia' );
Trials::add( $pr_c8, $pr_i, $pr_today );
$pr_acts->enroll( $pr_c8, $pr_i, $pr_month );
$pr_old = (int) $pr_acts->enrollment_left( $pr_c8, $pr_i, $pr_month );
$pr_acts->enroll( $pr_c8, $pr_i, $pr_month );
asem_ok( $pr_old === (int) $pr_acts->enrollment_left( $pr_c8, $pr_i, $pr_month ), 'prova: riattivare con lo stesso mese non cambia il dovuto' );
$pr_prev = gmdate( 'Y-m', strtotime( $pr_month . '-01 -1 month' ) );
$wpdb->update( Db::t( 'enrollments' ), array( 'start_month' => $pr_month ), array( 'activity_id' => $pr_c8, 'person_id' => $pr_i ) );
$pr_acts->enroll( $pr_c8, $pr_i, $pr_prev );
asem_ok( $pr_old === (int) $pr_acts->enrollment_left( $pr_c8, $pr_i, $pr_month ) && 4000 === (int) $pr_acts->enrollment_left( $pr_c8, $pr_i, $pr_prev ), 'prova: ripartendo da un mese prima il mese della prova resta com\'era e quello prima è intero' );
$pr_acts->enroll( $pr_c8, $pr_i, $pr_next );
asem_ok( 4000 === (int) $pr_acts->enrollment_left( $pr_c8, $pr_i, $pr_next ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT trial_from FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $pr_c8, $pr_i ) ), 'prova: ripartendo da un mese dopo si paga intero' );

// ---------- Rimborso di un mese interrotto: solo le lezioni pagate; senza prova come prima ----------
$pr_rd  = $pr_month . '-01';
$pr_rl  = ActivityReset::course_dates( $pr_acts->get( $pr_c5 ), $pr_rd, gmdate( 'Y-m-t', strtotime( $pr_rd ) ) );
$pr_rk  = count( $pr_rl );
$pr_j   = $pr_man( 'Nora' );
$pr_acts->enroll( $pr_c5, $pr_j, $pr_month );
$pr_ctx = array( 'today' => gmdate( 'Y-m-d', strtotime( $pr_rl[2] . ' +1 day' ) ), 'now' => '23:59', 'held' => array_slice( $pr_rl, 0, 3 ), 'sessions' => array(), 'course' => $pr_acts->get( $pr_c5 ) );
$pr_mv  = array( 'amount_cents' => 4000, 'session_id' => 0, 'competence_month' => $pr_month, 'person_id' => $pr_j );
asem_ok( (int) round( 4000 * ( $pr_rk - 3 ) / $pr_rk ) === ActivityReset::refund_cents( $pr_mv, $pr_ctx ), 'prova: rimborso senza prova come prima (lezioni non tenute su tutte)' );
$wpdb->update( Db::t( 'enrollments' ), array( 'trial_from' => $pr_rl[1], 'trial_left' => $pr_rk - 1, 'trial_total' => $pr_rk ), array( 'activity_id' => $pr_c5, 'person_id' => $pr_j ) );
$pr_pd = PaymentCalc::share( 4000, $pr_rk - 1, $pr_rk );
asem_ok( (int) round( $pr_pd * ( $pr_rk - 3 ) / ( $pr_rk - 1 ) ) === ActivityReset::refund_cents( array( 'amount_cents' => $pr_pd ) + $pr_mv, $pr_ctx ), 'prova: rimborso con la prova, sulle sole lezioni pagate' );

// ---------- Date simulate: primo e ultimo del mese, fine e inizio anno ----------
$pr_sim = 0;
foreach ( array( '2031-12-01', '2031-12-31', '2032-01-01', '2032-02-29', '2031-04-01', '2031-04-30', '2031-11-18' ) as $pr_day ) {
	Db::$today_fake = $pr_day;
	$pr_lab = Settings::social_year( $pr_day )->label();
	$pr_sc  = (int) $pr_acts->create( array( 'name' => 'Simulato ' . $pr_day, 'social_year' => $pr_lab, 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'trial_days' => 1 ) );
	$pr_sp  = $pr_man( 'Simula' );
	Trials::add( $pr_sc, $pr_sp, $pr_day );
	$pr_sm = substr( $pr_day, 0, 7 );
	$pr_acts->enroll( $pr_sc, $pr_sp, $pr_sm );
	$pr_st = (int) gmdate( 't', strtotime( $pr_day ) );
	$pr_ex = (int) round( 4000 * ( $pr_st - (int) substr( $pr_day, 8, 2 ) + 1 ) / $pr_st );
	$pr_ss = $pr_sum( $pr_sc, $pr_sp );
	$pr_nx = gmdate( 'Y-m', strtotime( $pr_sm . '-01 +1 month' ) );
	$pr_sim += $pr_ex === (int) $pr_ss['total_due'] && $pr_ex === (int) $pr_acts->enrollment_left( $pr_sc, $pr_sp, $pr_sm ) && 4000 === (int) $pr_acts->enrollment_left( $pr_sc, $pr_sp, $pr_nx ) ? 1 : 0;
}
Db::$today_fake = '';
asem_ok( 7 === $pr_sim, 'prova: 1/12, 31/12, 1/1, 29/2, primo, ultimo e metà mese danno la quota giusta (' . $pr_sim . ' su 7)' );

// ---------- Conservazione, anonimizzazione, azzeramento, copia vecchia, aggiornamento 77 → 78 ----------
$pr_k = (int) $pr_peo->create( array( 'type' => 'guest', 'host_person_id' => $pr_a, 'first_name' => 'Vecchio', 'last_name' => 'Provato', 'phone' => '333 4440011' ) );
$wpdb->update( Db::t( 'people' ), array( 'created_at' => '2001-01-01 00:00:00', 'joined_on' => '2001-01-01' ), array( 'id' => $pr_k ) );
$pr_ids = function (): array {
	return array_map( 'intval', array_column( \AssociazioneSemplice\Privacy::retention_candidates( 5, 1000 ), 'id' ) );
};
asem_ok( in_array( $pr_k, $pr_ids(), true ), 'prova: un ospite fermo da anni è tra i candidati alla cancellazione' );
Trials::add( $pr_c6, $pr_k, $pr_today );
asem_ok( ! in_array( $pr_k, $pr_ids(), true ), 'prova: dopo una prova recente non lo è più' );
\AssociazioneSemplice\Privacy::anonymize( $pr_k );
asem_ok( 1 === count( Trials::dates( $pr_c6, $pr_k ) ) && array() === array_filter( Trials::not_enrolled( $pr_today ), function ( $r ) use ( $pr_k ) { return (int) $r['person_id'] === $pr_k; } ), 'prova: resa anonima la persona, la prova resta senza nome e non compare in Bacheca' );
asem_ok( in_array( 'asem_trials', \AssociazioneSemplice\Reset::tables( \AssociazioneSemplice\Reset::DATA ), true ) && in_array( 'asem_trials', \AssociazioneSemplice\Backup::tables(), true ), 'prova: la tabella è nella copia di sicurezza e nell\'azzeramento' );
$pr_tn = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) );
$wpdb->query( 'ALTER TABLE ' . Db::t( 'enrollments' ) . ' DROP COLUMN trial_from, DROP COLUMN trial_left, DROP COLUMN trial_total' );
$wpdb->query( 'ALTER TABLE ' . Db::t( 'activities' ) . ' DROP COLUMN trial_days' );
$wpdb->query( 'DROP TABLE ' . Db::t( 'trials' ) );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $pr_fyn ) ); // anche con un anno chiuso
$pr_sumtx = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) );
update_option( \AssociazioneSemplice\Install::DB_VERSION_OPTION, '77' );
\AssociazioneSemplice\Install::maybe_upgrade();
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => $pr_fy['status'] ), array( 'year' => $pr_fyn ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( '79' === (string) get_option( \AssociazioneSemplice\Install::DB_VERSION_OPTION ) && \AssociazioneSemplice\Install::table_exists( Db::t( 'trials' ) ) && $pr_tn === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE trial_from IS NULL' ) && 0 === (int) $wpdb->get_var( 'SELECT COALESCE(SUM(trial_days),0) FROM ' . Db::t( 'activities' ) ) && $pr_sumtx === (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) ), 'prova: aggiornamento 77 → 78 con dati e un anno chiuso, iscrizioni e prima nota intatte, campi nuovi vuoti' );

// Una copia di prima delle prove (senza la tabella): dopo il ripristino non restano prove che rimandano a persone o corsi di prima
Trials::add( $pr_c6, $pr_a, $pr_today );
$pr_zip = \AssociazioneSemplice\Backup::export( true );
$pr_z   = new ZipArchive();
$pr_z->open( $pr_zip );
$pr_z->deleteName( 'tabelle/asem_trials.jsonl' );
$pr_z->close();
asem_bk_edit_manifest( $pr_zip, function ( $m ) { unset( $m['tables']['asem_trials'], $m['entries'], $m['sig'], $m['kfp'] ); $m['db_version'] = '77'; return $m; } );
$pr_pn  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$pr_res = \AssociazioneSemplice\Backup::restore( $pr_zip, '', true );
asem_ok( $pr_res['rows'] > 0 && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'trials' ) ) && $pr_pn === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'prova: ripristinando una copia di prima delle prove la tabella si svuota e il resto torna' );
wp_delete_file( $pr_zip );

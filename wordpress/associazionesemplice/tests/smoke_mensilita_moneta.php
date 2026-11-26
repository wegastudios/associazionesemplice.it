<?php
/**
 * Mensilità di un corso pagata nella moneta interna: per intero, mai mista con gli euro, sotto blocco, niente in prima nota, quota dell'istruttore sul
 * valore in euro; genitore per il figlio minore; lezione annullata, iscrizione cancellata: torna moneta. Solo con le funzioni Pro.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\AssociazioneSemplice\CourseCoins' ) || ! \AssociazioneSemplice\Edition::has( 'credits' ) ) {
	return;
}

use AssociazioneSemplice\CourseCoins;
use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
$cm_acts = Plugin::activities();
$cm_peo  = Plugin::people();
$cm_lab  = Settings::social_year( '2031-05-13' )->label();
$cm_n    = 0;
$cm_man  = function ( array $extra = array() ) use ( &$cm_n, $cm_peo, $cm_lab ): int {
	$cm_n++;
	$id = (int) $cm_peo->create( $extra + array( 'type' => 'ordinary', 'card_number' => '44' . $cm_n, 'first_name' => 'Moneta', 'last_name' => 'Persona ' . $cm_n, 'email' => 'moneta' . $cm_n . '@example.com', 'birth_date' => '1985-01-01' ) );
	$cm_peo->set_membership( $id, Settings::membership_year()->label(), true );
	$cm_peo->set_membership( $id, Settings::membership_year( '2031-05-13' )->label(), true );
	return $id;
};
$cm_ins = (int) $cm_peo->create( array( 'type' => 'volunteer', 'first_name' => 'Istruttore', 'last_name' => 'Moneta', 'email' => 'istruttore.moneta@example.com' ) );
$cm_c   = (int) $cm_acts->create( array( 'name' => 'Corso in moneta', 'social_year' => $cm_lab, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'full', 'fee_cents' => 4000, 'starts_on' => '2031-05-01', 'instructor_person_id' => $cm_ins, 'fund_mode' => 'percent', 'fund_value' => 5000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
Credits::set_activity_price( $cm_c, 40 );
$cm_left = function ( int $pid, string $m ) use ( $cm_acts, $cm_c ): int {
	return (int) $cm_acts->enrollment_left( $cm_c, $pid, $m );
};
$cm_tx = function () use ( $wpdb ): string {
	return (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) );
};
Db::$today_fake = '2031-05-13';

// ---------- Il mese intero in moneta: chiuso, nessun incasso, quota dell'istruttore sul valore in euro ----------
$cm_m = $cm_man();
$cm_acts->enroll( $cm_c, $cm_m, '2031-05' );
Credits::adjust( $cm_m, 100, 'prova' );
$cm_t0 = $cm_tx();
asem_ok( 40 === CourseCoins::price( $cm_acts->get( $cm_c ), $cm_peo->get( $cm_m ), '2031-05' ), 'moneta: il mese costa 40 (prezzo del corso)' );
asem_ok( 40 === CourseCoins::pay( $cm_c, $cm_m, '2031-05', $cm_m ) && 60 === Credits::balance( $cm_m ) && 0 === $cm_left( $cm_m, '2031-05' ), 'moneta: pagato il mese, saldo scalato, niente da pagare in euro' );
asem_ok( $cm_t0 === $cm_tx(), 'moneta: in prima nota non entra nulla' );
$cm_fid = Plugin::funds()->instructor_fund_id( $cm_c );
asem_ok( $cm_fid && 2000 === (int) Plugin::funds()->get( $cm_fid )['balance'], 'moneta: la quota dell\'istruttore si accantona sul valore in euro (20,00)' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_m ) { CourseCoins::pay( $cm_c, $cm_m, '2031-05', $cm_m ); } ) && 60 === Credits::balance( $cm_m ), 'moneta: lo stesso mese non si paga due volte' );
$cm_cash = Plugin::ledger()->default_account_for( 'cash' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_cash, $cm_c, $cm_m ) { Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cm_cash['id'], 'method' => 'cash', 'person_id' => $cm_m, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 4000, 'activity_id' => $cm_c, 'competence_month' => '2031-05' ) ) ) ); } ), 'moneta: un mese pagato in moneta non accetta euro' );

// ---------- Niente misto, saldo insufficiente, ospiti, checkout aperto, anno chiuso ----------
$cm_acts->enroll( $cm_c, $cm_m, '2031-05' );
Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cm_cash['id'], 'method' => 'cash', 'person_id' => $cm_m, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 1000, 'activity_id' => $cm_c, 'competence_month' => '2031-06' ) ) ) );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_m ) { CourseCoins::pay( $cm_c, $cm_m, '2031-06', $cm_m ); } ) && 60 === Credits::balance( $cm_m ), 'moneta: un mese già pagato in parte in euro non si completa in moneta' );
$cm_poor = $cm_man();
$cm_acts->enroll( $cm_c, $cm_poor, '2031-05' );
Credits::adjust( $cm_poor, 10, 'poco' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_poor ) { CourseCoins::pay( $cm_c, $cm_poor, '2031-05', $cm_poor ); } ) && 10 === Credits::balance( $cm_poor ) && 4000 === $cm_left( $cm_poor, '2031-05' ), 'moneta: saldo insufficiente, nulla cambia' );
$cm_g = (int) $cm_peo->create( array( 'type' => 'guest', 'host_person_id' => $cm_m, 'first_name' => 'Ospite', 'last_name' => 'Moneta', 'phone' => '333 4440055' ) );
$cm_acts->enroll( $cm_c, $cm_g, '2031-05' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_g ) { CourseCoins::pay( $cm_c, $cm_g, '2031-05', $cm_g ); } ), 'moneta: gli ospiti non pagano in moneta' );
$cm_o = $cm_man();
$cm_acts->enroll( $cm_c, $cm_o, '2031-05' );
Credits::adjust( $cm_o, 100, 'prova' );
if ( \AssociazioneSemplice\Install::table_exists( Db::t( 'payments' ) ) ) {
	$wpdb->insert( Db::t( 'payments' ), array( 'public_id' => 'prova-moneta', 'provider' => 'stripe', 'status' => 'pending', 'amount_cents' => 4000, 'payer_person_id' => $cm_o, 'payer_user_id' => 0, 'items' => wp_json_encode( array( array( 'key' => 'm:' . $cm_c . ':' . $cm_o . ':2031-05' ) ) ), 'created_at' => Db::now() ) );
	asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_o ) { CourseCoins::pay( $cm_c, $cm_o, '2031-05', $cm_o ); } ) && 100 === Credits::balance( $cm_o ), 'moneta: con un pagamento online aperto sullo stesso mese si rifiuta' );
	$wpdb->delete( Db::t( 'payments' ), array( 'public_id' => 'prova-moneta' ) );
}
$cm_fy = \AssociazioneSemplice\FiscalYears::year_of( '2031-05-01' );
\AssociazioneSemplice\FiscalYears::ensure( $cm_fy );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $cm_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$cm_cl = (string) asem_throws( function () use ( $cm_c, $cm_o ) { CourseCoins::pay( $cm_c, $cm_o, '2031-05', $cm_o ); } );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'open' ), array( 'year' => $cm_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( '' !== $cm_cl && 100 === Credits::balance( $cm_o ), 'moneta: in un anno fiscale chiuso non si paga' );

// ---------- Genitore per il figlio minore; chi non è genitore no ----------
$cm_par = $cm_man( array( 'birth_date' => '1980-01-01' ) );
$cm_kid = $cm_man( array( 'birth_date' => gmdate( 'Y-m-d', strtotime( '-10 years' ) ), 'email' => '' ) );
\AssociazioneSemplice\Guardians::link( $cm_kid, $cm_par );
$cm_acts->enroll( $cm_c, $cm_kid, '2031-05' );
Credits::adjust( $cm_par, 50, 'prova' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_kid, $cm_o ) { CourseCoins::pay( $cm_c, $cm_kid, '2031-05', $cm_o ); } ) && 100 === Credits::balance( $cm_o ), 'moneta: chi non è genitore non paga per il minore' );
asem_ok( 40 === CourseCoins::pay( $cm_c, $cm_kid, '2031-05', $cm_par ) && 10 === Credits::balance( $cm_par ) && 0 === $cm_left( $cm_kid, '2031-05' ), 'moneta: il genitore paga il mese del figlio dal suo saldo' );
$cm_html = (string) apply_filters( 'asem_pay_section_extra', '', $cm_peo->get( $cm_o ) );
asem_ok( false !== strpos( $cm_html, 'Paga 40 ' ) && false !== strpos( $cm_html, 'Corso in moneta' ), 'moneta: nell\'area soci il mese si paga con un pulsante' );

// ---------- Iscrizione cancellata: come in euro, il mese già cominciato resta pagato, torna la moneta dei mesi non cominciati ----------
Credits::adjust( $cm_par, 40, 'prova' );
CourseCoins::pay( $cm_c, $cm_kid, '2031-06', $cm_par );
$cm_fb   = (int) Plugin::funds()->get( $cm_fid )['balance'];
$cm_line = implode( ' ', \AssociazioneSemplice\ActivityReset::other_payments( $cm_c, $cm_kid, 0, '' ) );
asem_ok( false !== strpos( $cm_line, '2 mesi pagati' ) && false !== strpos( $cm_line, 'tornano ' . \AssociazioneSemplice\CoinName::n( 40 ) ), 'moneta: la pagina di cancellazione dell\'iscrizione dice cosa torna (' . $cm_line . ')' );
\AssociazioneSemplice\CourseCoins::registration_deleted( $cm_c, $cm_kid, \AssociazioneSemplice\ActivityReset::REFUND );
asem_ok( 50 === Credits::balance( $cm_par ) && 2000 === $cm_fb - (int) Plugin::funds()->get( $cm_fid )['balance'] && 0 === $cm_left( $cm_kid, '2031-05' ) && 4000 === $cm_left( $cm_kid, '2031-06' ), 'moneta: iscrizione cancellata a metà maggio: maggio resta pagato, tornano i 40 di giugno e la sua quota esce dal fondo' );

// ---------- Figlio appena maggiorenne: il genitore non paga più per lui ----------
$cm_big = $cm_man( array( 'birth_date' => '2015-01-01', 'email' => '' ) );
\AssociazioneSemplice\Guardians::link( $cm_big, $cm_par );
$cm_acts->enroll( $cm_c, $cm_big, '2031-05' );
$wpdb->update( Db::t( 'people' ), array( 'birth_date' => '2013-05-13' ), array( 'id' => $cm_big ) ); // compie 18 anni oggi (data finta 13/05/2031)
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$cm_b0 = Credits::balance( $cm_par );
$cm_ad = (string) asem_throws( function () use ( $cm_c, $cm_big, $cm_par ) { CourseCoins::pay( $cm_c, $cm_big, '2031-06', $cm_par ); } );
$cm_pl = array_filter( CourseCoins::payable( $cm_peo->get( $cm_par ) ), function ( $x ) use ( $cm_big ) { return (int) $x['person']['id'] === $cm_big; } );
asem_ok( '' !== $cm_ad && $cm_b0 === Credits::balance( $cm_par ) && ! $cm_pl, 'moneta: il figlio che compie 18 anni oggi non si paga più dal saldo del genitore, né compare tra i suoi mesi da pagare' );

// ---------- Corso eliminato: con «Restituisci» torna anche la parte non goduta del mese in corso, con «Annulla» solo i mesi non cominciati ----------
$cm_del = function ( string $mode ) use ( $cm_acts, $cm_lab, $cm_man ): array {
	$aid = (int) $cm_acts->create( array( 'name' => 'Corso da eliminare ' . $mode, 'social_year' => $cm_lab, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'full', 'fee_cents' => 4000, 'starts_on' => '2031-05-01', 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
	Credits::set_activity_price( $aid, 40 );
	$pid = $cm_man();
	$cm_acts->enroll( $aid, $pid, '2031-05' );
	Credits::adjust( $pid, 80, 'prova' );
	CourseCoins::pay( $aid, $pid, '2031-05', $pid );
	CourseCoins::pay( $aid, $pid, '2031-06', $pid );
	$free  = \AssociazioneSemplice\ActivityReset::activity_is_free( $aid );
	$line  = implode( ' ', \AssociazioneSemplice\ActivityReset::other_payments( $aid, 0, 0, $mode ) );
	\AssociazioneSemplice\ActivityReset::delete( $aid, $mode );
	return array( 'free' => $free, 'line' => $line, 'back' => Credits::balance( $pid ), 'rows' => (int) Db::db()->get_var( Db::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credit_payments' ) . ' WHERE activity_id = %d', $aid ) ) );
};
$cm_rf = $cm_del( \AssociazioneSemplice\ActivityReset::REFUND );
$cm_vd = $cm_del( \AssociazioneSemplice\ActivityReset::VOID );
asem_ok( ! $cm_rf['free'] && 0 === $cm_rf['rows'] && 0 === $cm_vd['rows'], 'moneta: un corso con mesi pagati in moneta chiede la scelta sulle somme e, eliminato, non lascia righe appese' );
asem_ok( 40 === $cm_vd['back'] && false !== strpos( $cm_vd['line'], '«Annulla» tornano ' . \AssociazioneSemplice\CoinName::n( 40 ) ), 'moneta: corso eliminato con «Annulla»: tornano solo i 40 di giugno, maggio resta pagato (' . $cm_vd['line'] . ')' );
asem_ok( $cm_rf['back'] > 40 && $cm_rf['back'] < 80 && false !== strpos( $cm_rf['line'], '«Restituisci» tornano ' . \AssociazioneSemplice\CoinName::n( $cm_rf['back'] ) ), 'moneta: corso eliminato con «Restituisci»: giugno intero e le lezioni non tenute di maggio (' . $cm_rf['back'] . ', ' . $cm_rf['line'] . ')' );


// ---------- Corso a lezioni: lezione annullata in un mese pagato in moneta, torna la moneta di quella lezione ----------
$cm_l = (int) $cm_acts->create( array( 'name' => 'Lezioni in moneta', 'social_year' => $cm_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
Credits::set_activity_price( $cm_l, 10 );
$cm_q = $cm_man();
Credits::adjust( $cm_q, 100, 'prova' );
Db::$today_fake = '2031-05-02';
$cm_acts->enroll( $cm_l, $cm_q, '2031-05', '2031-05-01' );
asem_ok( 40 === CourseCoins::pay( $cm_l, $cm_q, '2031-05', $cm_q ) && 60 === Credits::balance( $cm_q ), 'moneta: a lezioni, quattro lezioni da 10' );
\AssociazioneSemplice\LessonExceptions::cancel( $cm_l, '2031-05-20' );
asem_ok( 70 === Credits::balance( $cm_q ) && 0 === (int) $cm_acts->enrollment_left( $cm_l, $cm_q, '2031-05' ), 'moneta: lezione annullata, tornano 10 e il mese resta pagato' );
$cm_cr = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d AND lesson_date = %s', $cm_l, $cm_q, '2031-05-20' ), ARRAY_A );
asem_ok( $cm_cr && 0 === \AssociazioneSemplice\CourseCredits::available( $cm_cr ), 'moneta: nessun importo a favore in euro per un mese pagato in moneta' );
asem_ok( in_array( 'asem_course_credit_payments', \AssociazioneSemplice\Backup::tables(), true ) && isset( \AssociazioneSemplice\Privacy::export( $cm_q )['mensilita_moneta_interna'] ), 'moneta: nella copia e nei dati scaricati' );

// ---------- Nome con apostrofo e accenti: ricevuta PDF, pagina del socio ed email scritte bene ----------
Db::$today_fake = '2031-05-13'; // dopo la prima lezione di maggio: il mese è da pagare
$cm_keep  = array( 'coin_singular' => (string) Settings::get( 'coin_singular' ), 'coin_plural' => (string) Settings::get( 'coin_plural' ) );
$cm_packs = get_option( Credits::PACKS_OPTION, '' );
Settings::update( array( 'coin_singular' => "Credito d'oro \"Lucà\"", 'coin_plural' => "Crediti d'oro \"Lucà\"" ) );
asem_ok( "2 Crediti d'oro \"Lucà\"" === \AssociazioneSemplice\CoinName::n( 2 ), 'moneta: il nome con apostrofo, accento e virgolette resta com\'è' );
Credits::save_packs( '10 = 10,00' );
Credits::sell_at_desk( $cm_q, 10, (int) $cm_cash['id'], 'cash' );
$cm_rk = '';
foreach ( \AssociazioneSemplice\Receipts::list_for_payer( $cm_q ) as $cm_r ) {
	if ( 1000 === (int) $cm_r['cents'] ) {
		$cm_rk = (string) $cm_r['key'];
	}
}
$cm_pdf = '' !== $cm_rk ? (string) \AssociazioneSemplice\Receipts::build( $cm_rk )['pdf'] : '';
asem_ok( 0 === strpos( $cm_pdf, '%PDF' ) && false !== strpos( $cm_pdf, (string) mb_convert_encoding( "Crediti d'oro \"Lucà\"", 'Windows-1252', 'UTF-8' ) ), 'moneta: la ricevuta PDF dell\'acquisto riporta il nome con apostrofo, accento e virgolette' );
$cm_html = (string) apply_filters( 'asem_pay_section_extra', '', $cm_peo->get( $cm_o ) );
$cm_at = strpos( $cm_html, 'Crediti d' );
asem_ok( false !== strpos( $cm_html, 'Crediti d&#039;oro &quot;Lucà&quot;' ) && false === strpos( $cm_html, "d'oro" ) && false === strpos( $cm_html, '"Lucà' ), 'moneta: nell\'area soci il nome con apostrofo, accento e virgolette è scritto ed escapato bene' . ( false === $cm_at ? ' [nessun «Crediti d» in ' . strlen( $cm_html ) . ' caratteri]' : ' [' . substr( $cm_html, $cm_at, 40 ) . ']' ) );
$cm_mails = array();
$cm_trap  = function ( $r, $atts ) use ( &$cm_mails ) {
	$cm_mails[] = array( 'to' => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'], 'subject' => (string) $atts['subject'], 'text' => html_entity_decode( wp_strip_all_tags( (string) $atts['message'] ), ENT_QUOTES, 'UTF-8' ), 'raw' => (string) $atts['subject'] . (string) $atts['message'] );
	return true;
};
add_filter( 'pre_wp_mail', $cm_trap, 10, 2 );
$cm_mail = function ( string $to, string $needle ) use ( &$cm_mails ): ?array {
	foreach ( $cm_mails as $m ) {
		if ( false !== strpos( $m['to'], $to ) && false !== strpos( $m['subject'] . ' ' . $m['text'], $needle ) ) {
			return $m;
		}
	}
	return null;
};
$cm_ok_text = function ( ?array $m ): bool { // il nome scritto bene: niente entità rimaste nell'oggetto, niente doppio escape nel testo
	return $m && false === strpos( $m['subject'], '&#' ) && false === strpos( $m['subject'], '&quot;' ) && false === strpos( $m['raw'], '&amp;#039;' ) && false === strpos( $m['raw'], '&amp;amp;' ) && false === strpos( $m['raw'], '&amp;quot;' );
};
$cm_oe = (string) $cm_peo->get( $cm_o )['email'];
CourseCoins::pay( $cm_c, $cm_o, '2031-06', $cm_o ); // giugno non è cominciato: cancellando l'iscrizione torna tutto
$cm_m1 = $cm_mail( $cm_oe, 'Mese pagato' );
asem_ok( $cm_ok_text( $cm_m1 ) && false !== strpos( $cm_m1['subject'], "Mese pagato in Crediti d'oro \"Lucà\"" ) && false !== strpos( $cm_m1['text'], "è pagato con 40 Crediti d'oro \"Lucà\"" ), 'moneta: email del mese pagato con il nome con apostrofo e accento' );
CourseCoins::registration_deleted( $cm_c, $cm_o );
$cm_m2 = $cm_mail( $cm_oe, 'Iscrizione cancellata' );
asem_ok( $cm_ok_text( $cm_m2 ) && false !== strpos( $cm_m2['text'], "ti tornano 40 Crediti d'oro \"Lucà\"" ), 'moneta: email della moneta restituita per iscrizione cancellata' );
\AssociazioneSemplice\LessonExceptions::cancel( $cm_l, '2031-05-27' );
$cm_m3 = $cm_mail( (string) $cm_peo->get( $cm_q )['email'], 'Lezione annullata' );
asem_ok( $cm_ok_text( $cm_m3 ) && false !== strpos( $cm_m3['text'], "27/05/2031" ) && false !== strpos( $cm_m3['text'], "ti tornano 10 Crediti d'oro \"Lucà\"" ) && false === strpos( $cm_m3['text'], 'a tuo favore' ), 'moneta: email della lezione annullata con la moneta che torna, senza importo in euro' );
$cm_par_mail = (string) $cm_peo->get( $cm_par )['email'];
Credits::adjust( $cm_par, 40, 'prova' );
CourseCoins::pay( $cm_c, $cm_kid, '2031-06', $cm_par );
$cm_m4 = $cm_mail( $cm_par_mail, 'Mese pagato' );
asem_ok( $cm_ok_text( $cm_m4 ) && false !== strpos( $cm_m4['text'], 'per Moneta Persona' ), 'moneta: il genitore riceve l\'email del mese pagato per il figlio' );
$cm_ps = Plugin::payments();
if ( $cm_ps instanceof \AssociazioneSemplice\PaymentService ) { // pagamento online in euro arrivato per un mese già pagato in moneta
	$wpdb->insert(
		Db::t( 'payments' ),
		array(
			'public_id' => 'prova-moneta-online', 'provider' => 'stripe', 'status' => 'pending', 'amount_cents' => 4000, 'payer_person_id' => $cm_m, 'payer_user_id' => 0, 'provider_ref' => 'cs_prova_moneta_online',
			'items' => wp_json_encode( array( array( 'type' => \AssociazioneSemplice\PaymentItems::COURSE_MONTH, 'activity_id' => $cm_c, 'person_id' => $cm_m, 'month' => '2031-05', 'amount_cents' => 4000, 'label' => 'Corso in moneta — 2031-05', 'key' => 'm:' . $cm_c . ':' . $cm_m . ':2031-05' ) ) ),
			'created_at' => Db::now(),
		)
	);
	$cm_fin = new \ReflectionMethod( \AssociazioneSemplice\PaymentService::class, 'finalize' );
	$cm_fin->setAccessible( true );
	$cm_fin->invoke( $cm_ps, $cm_ps->get_by_public( 'prova-moneta-online' ), 4000, 'pi_prova_moneta' );
	$cm_on  = $cm_ps->get_by_public( 'prova-moneta-online' );
	$cm_pm  = new \ReflectionMethod( \AssociazioneSemplice\ActivityService::class, 'paid_map' );
	$cm_pm->setAccessible( true );
	\AssociazioneSemplice\RequestMemo::forget();
	$cm_paid = (int) ( $cm_pm->invoke( $cm_acts, array( $cm_c ) )[ $cm_c ][ $cm_m ]['2031-05'] ?? 0 );
	asem_ok( 'paid' === $cm_on['status'] && 1 === (int) $cm_on['review'] && false !== strpos( (string) $cm_on['error'], 'Già pagata' ), 'moneta: pagamento online arrivato dopo il mese pagato in moneta: finisce «da controllare» per la segreteria' );
	asem_ok( 4000 === $cm_paid && 0 === $cm_left( $cm_m, '2031-05' ), 'moneta: il mese pagato in moneta e poi online risulta pagato una volta sola (' . $cm_paid . ')' );
	$cm_m5 = $cm_mail( (string) $cm_peo->get( $cm_m )['email'], 'Mese già pagato' );
	asem_ok( $cm_ok_text( $cm_m5 ) && false !== strpos( $cm_m5['text'], 'restituire gli euro' ), 'moneta: chi ha pagato online riceve l\'avviso che il mese era già pagato in moneta' );
}
remove_filter( 'pre_wp_mail', $cm_trap, 10 );
update_option( Credits::PACKS_OPTION, $cm_packs, false );
Settings::update( $cm_keep );
Db::$today_fake = '';

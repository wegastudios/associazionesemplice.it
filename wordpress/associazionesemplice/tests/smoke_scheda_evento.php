<?php
/**
 * Prove della scheda di un evento con molte persone in anagrafica: nessuna tendina con tutti i nomi (per ogni prenotato, per ogni data, per l'ingresso
 * sul posto), la pagina non cresce con il numero dei prenotati, le date si mostrano a blocchi e senza JavaScript la persona si riconosce dal testo scritto.
 * Si carica da smoke.php.
 */

use AssociazioneSemplice\Admin;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;

wp_set_current_user( 1 );

$se_acts  = Plugin::activities();
$se_peo   = Plugin::people();
$se_today = current_time( 'Y-m-d' );

// un'anagrafica oltre la soglia delle tendine
$se_ids = array();
for ( $i = 1; $i <= Admin\Ui::PERSON_LIST_MAX + 60; $i++ ) {
	$se_ids[] = (int) $se_peo->create( array( 'type' => 'ordinary', 'first_name' => 'Persona', 'last_name' => 'Zeta' . sprintf( '%04d', $i ) ) );
}
asem_ok( $se_peo->count_people() > Admin\Ui::PERSON_LIST_MAX, 'scheda evento: l\'anagrafica di prova supera la soglia delle tendine' );
asem_ok( Admin\Ui::person_search_preferred( $se_peo->count_people() ), 'scheda evento: oltre la soglia si usa la ricerca' );
asem_ok( ! Admin\Ui::person_search_preferred( 3 ), 'scheda evento: con poche persone resta la tendina' );

$se_mk = function ( string $name, int $n ) use ( $se_acts, $se_ids, $se_today ) {
	$aid = (int) $se_acts->create( array( 'name' => $name, 'social_year' => Settings::social_year()->label(), 'kind' => 'event', 'fee_cents' => 0, 'session' => array( 'session_date' => gmdate( 'Y-m-d', strtotime( $se_today . ' +7 days' ) ), 'capacity' => 80 ) ) );
	$sid = (int) $se_acts->sessions( $aid )[0]['id'];
	for ( $k = 0; $k < $n; $k++ ) {
		$se_acts->book( $sid, $se_ids[ $k ] );
	}
	return array( $aid, $sid );
};
list( $se_few, ) = $se_mk( 'Serata con pochi prenotati', 5 );
list( $se_many, $se_many_s ) = $se_mk( 'Serata con molti prenotati', 40 );

$se_q0   = $wpdb->num_queries;
$se_few_html = asem_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cedi il posto a un\'altra persona', array( 'id' => $se_few ) );
$se_q1   = $wpdb->num_queries;
$se_html = asem_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cedi il posto a un\'altra persona', array( 'id' => $se_many ) );
$se_q2   = $wpdb->num_queries;

$se_max = 0;
$se_sel = 0;
$se_big = '';
if ( preg_match_all( '~<select\b([^>]*)>(.*?)</select>~s', $se_html, $se_m, PREG_SET_ORDER ) ) {
	foreach ( $se_m as $one ) {
		if ( 1 !== preg_match( '~name="(person_id|to_person_id|host_person_id)"~', $one[1] ) ) { // solo le tendine di persone (non il referente, i conti, gli sport…)
			continue;
		}
		$se_sel++;
		$n = substr_count( $one[2], '<option' );
		if ( $n > $se_max ) {
			$se_max = $n;
			$se_big = $one[1];
		}
	}
}
asem_ok( $se_sel > 0 && $se_max <= 40, 'scheda evento: nessuna tendina con più di 40 voci con ' . $se_peo->count_people() . ' persone (la più lunga ne ha ' . $se_max . ': ' . $se_big . ')' );
asem_ok( false === strpos( $se_html, 'Zeta0100' ), 'scheda evento: i nomi delle persone non prenotate non sono nella pagina' );
asem_ok( substr_count( $se_html, 'asem-minsearch' ) >= 40, 'scheda evento: ogni prenotato ha la ricerca per cedere il posto' );
asem_ok( false !== strpos( $se_html, 'name="to_person_id_q"' ) && false !== strpos( $se_html, 'name="person_id_q"' ), 'scheda evento: i campi di ricerca portano il testo anche senza JavaScript' );
asem_ok( strlen( $se_html ) < 250000, 'scheda evento: con 40 prenotati la pagina resta sotto i 250 KB (' . strlen( $se_html ) . ' byte)' );
asem_ok( ( $se_q2 - $se_q1 ) - ( $se_q1 - $se_q0 ) <= 3, 'scheda evento: le query non crescono con i prenotati (5: ' . ( $se_q1 - $se_q0 ) . ', 40: ' . ( $se_q2 - $se_q1 ) . ')' );
asem_ok( strlen( $se_html ) - strlen( $se_few_html ) < 35 * 6000, 'scheda evento: ogni prenotato in più costa pochi KB, non una tendina intera (' . ( strlen( $se_html ) - strlen( $se_few_html ) ) . ' byte per 35 prenotati)' );

// senza JavaScript: il testo scritto nel campo di ricerca vale se indica una sola persona
$se_one = Admin\PersonSearch::resolve_typed( array( 'person_id' => '', 'person_id_q' => 'Zeta0007' ), array( 'person_id' ) );
asem_ok( (int) $se_one['person_id'] === $se_ids[6], 'senza JavaScript: il nome scritto per intero sceglie la persona' );
$se_set = Admin\PersonSearch::resolve_typed( array( 'person_id' => '5', 'person_id_q' => 'Zeta0007' ), array( 'person_id' ) );
asem_ok( '5' === $se_set['person_id'], 'senza JavaScript: se la persona è già scelta il testo non conta' );
asem_ok( null !== asem_throws( function () { Admin\PersonSearch::resolve_typed( array( 'to_person_id_q' => 'Zeta00' ), array( 'to_person_id' ) ); } ), 'senza JavaScript: un testo che indica più persone non sceglie nessuno' );
asem_ok( null !== asem_throws( function () { Admin\PersonSearch::resolve_typed( array( 'person_id_q' => 'Qq' ), array( 'person_id' ) ); } ), 'senza JavaScript: un testo troppo corto non sceglie nessuno' );
$se_none = Admin\PersonSearch::resolve_typed( array( 'person_id' => '' ), array( 'person_id' ) );
asem_ok( '' === $se_none['person_id'], 'senza JavaScript: senza testo il modulo resta com\'è' );

// date a blocchi: prima le prossime (dalla più vicina), poi le passate (dalla più recente)
$se_dates = array();
foreach ( array( -30, -20, -10, -1, 0, 3, 9, 20 ) as $i => $d ) {
	$se_dates[] = array( 'id' => $i + 1, 'session_date' => gmdate( 'Y-m-d', strtotime( $se_today . ' ' . ( $d >= 0 ? '+' : '' ) . $d . ' days' ) ), 'end_date' => null );
}
$se_b = Admin\ActivitiesPage::session_block( $se_dates, $se_today, 4 );
asem_ok( array( 5, 6, 7, 8 ) === array_map( 'intval', array_column( $se_b['shown'], 'id' ) ) && 4 === $se_b['hidden'], 'date a blocchi: le prossime per prime, dalla più vicina' );
$se_b = Admin\ActivitiesPage::session_block( $se_dates, $se_today, 6 );
asem_ok( array( 5, 6, 7, 8, 4, 3 ) === array_map( 'intval', array_column( $se_b['shown'], 'id' ) ) && 2 === $se_b['hidden'], 'date a blocchi: poi le passate, dalla più recente' );
$se_b = Admin\ActivitiesPage::session_block( $se_dates, $se_today, 50 );
asem_ok( 8 === count( $se_b['shown'] ) && 0 === $se_b['hidden'], 'date a blocchi: con pochi date si mostrano tutte' );

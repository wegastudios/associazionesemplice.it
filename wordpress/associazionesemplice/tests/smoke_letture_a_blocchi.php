<?php
/**
 * Prove delle letture a blocchi di Bacheca, elenco e scheda di corsi ed eventi: ogni risposta data per più righe insieme deve essere uguale, riga per riga,
 * a quella data dalla lettura singola di prima (che resta qui come riferimento). Si carica da smoke.php.
 */

use AssociazioneSemplice\Admin;
use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Levels;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Notices;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;

wp_set_current_user( 1 );

/** La lista dei pagamenti da incassare come si calcolava prima: una lettura per persona. @return array<int,array{what:string[],cents:int}> */
function asem_lb_old_payments(): array {
	$acts   = Plugin::activities();
	$people = Plugin::people();
	$today  = current_time( 'Y-m-d' );
	$by     = array();
	$member_due = function ( array $p ) use ( $people, $today ) {
		$fee = \AssociazioneSemplice\Levels::fee_for( $p );
		if ( $fee <= 0 || ! MemberType::is_member( $p['type'] ) || MemberType::is_auto_renewed( $p['type'] ) || ! empty( $p['suspended_at'] ) ) {
			return null;
		}
		$until = $people->active_until( (int) $p['id'], $today );
		if ( $until && $until >= $today ) {
			return null;
		}
		$plan    = $people->membership_plan( (int) $p['id'], $today );
		$missing = $people->membership_missing( $p, $plan['year'] );
		if ( $missing > 0 ) {
			return array( 'Quota associativa ' . $plan['year'] . ' (versati ' . Money::format( $fee - $missing ) . ' su ' . Money::format( $fee ) . ')', $missing );
		}
		return array( 'Quota associativa ' . $plan['year'] . ( $plan['free'] ? ' (' . $plan['free'] . ' in omaggio)' : '' ), $fee );
	};
	$add = function ( array $p, string $what, int $cents ) use ( &$by ) {
		$pid = (int) $p['id'];
		if ( ! isset( $by[ $pid ] ) ) {
			$by[ $pid ] = array( 'what' => array(), 'cents' => 0 );
		}
		$by[ $pid ]['what'][] = $what;
		$by[ $pid ]['cents'] += $cents;
	};
	foreach ( $people->search_light( array(), true ) as $p ) {
		if ( empty( $p['active_until'] ) && ( $m = $member_due( $p ) ) ) {
			$add( $p, $m[0], $m[1] );
		}
	}
	foreach ( $acts->for_year( Settings::social_year()->label() ) as $a ) {
		if ( 'course' !== $a['kind'] ) {
			continue;
		}
		foreach ( $acts->status_for_activity( (int) $a['id'] ) as $s ) {
			if ( null !== $s['enrollment']['end_month'] || ! $s['summary']['unpaid_months'] ) {
				continue;
			}
			$p = $people->get( (int) $s['enrollment']['person_id'] );
			if ( ! $p ) {
				continue;
			}
			if ( ! isset( $by[ (int) $p['id'] ] ) && ( $m = $member_due( $p ) ) ) {
				$add( $p, $m[0], $m[1] );
			}
			$months = implode( ', ', array_map( function ( $mm ) {
				return Admin\Ui::month( $mm['month'] );
			}, $s['summary']['unpaid_months'] ) );
			$add( $p, $a['name'] . ' · ' . $months, max( 0, -$s['summary']['balance'] ) );
		}
	}
	return $by;
}

/** Le voci di un elenco a scelta come le scriveva prima Ui::options (nessuna memoria delle voci già preparate). */
function asem_lb_old_options( array $map, $selected = null, ?string $empty = null ): string {
	$html = '';
	if ( null !== $empty ) {
		$html .= '<option value="">' . esc_html( $empty ) . '</option>';
	}
	foreach ( $map as $v => $label ) {
		$html .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $selected, (string) $v, false ) . '>' . esc_html( (string) $label ) . '</option>';
	}
	return $html;
}

$lb_people = Plugin::people();
$lb_acts   = Plugin::activities();
$lb_ledger = Plugin::ledger();
$lb_today  = Db::today();
$lb_sy     = Settings::social_year();
$lb_month  = $lb_sy->clamp( substr( $lb_today, 0, 7 ) );
$lb_cash   = $lb_ledger->default_account_for( 'cash' );
$lb_flag0  = Settings::get( 'limits' );
$lb_flag0  = is_array( $lb_flag0 ) ? $lb_flag0 : array();
Settings::update( array( 'limits' => array_merge( $lb_flag0, array( 'membership_on_balance' => 1 ) ) ) ); // i versamenti parziali della quota non danno la tessera

// ---------- Dati di prova: un corso con iscritti (alcuni senza tessera, uno poi eliminato) e due eventi con prenotazioni ----------
$lb_mk = function ( string $first, bool $member ) use ( $lb_people ) {
	$id = $lb_people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => 'Blocchi', 'email' => strtolower( $first ) . '.blocchi@example.com' ) );
	if ( $member ) {
		$lb_people->set_membership( $id, Settings::membership_year()->label(), true );
	}
	return $id;
};
$lb_a = $lb_mk( 'Anna', true );
$lb_b = $lb_mk( 'Bruno', true );
$lb_c = $lb_mk( 'Carla', false );
$lb_d = $lb_mk( 'Dario', false );
$lb_e = $lb_mk( 'Elisa', false );
$lb_f = $lb_mk( 'Fabio', true );
$lb_g = $lb_mk( 'Gina', false );
$lb_h = $lb_mk( 'Ivo', true ); // non iscritto a nulla

$lb_course = $lb_acts->create( array( 'name' => 'Corso a blocchi', 'social_year' => $lb_sy->label(), 'kind' => 'course', 'monthly_fee_cents' => 2000 ) );
foreach ( array( $lb_a, $lb_b, $lb_c, $lb_d, $lb_e, $lb_f, $lb_g ) as $lb_p ) {
	$lb_acts->enroll( $lb_course, $lb_p, $lb_month );
}
$lb_acts->cancel( $lb_course, $lb_f, $lb_month ); // un'iscrizione già finita
$wpdb->update( Db::t( 'people' ), array( 'deleted_at' => Db::now() ), array( 'id' => $lb_g ) ); // una persona non più in anagrafica
$lb_cat = array();
foreach ( $lb_ledger->categories() as $lb_c0 ) {
	$lb_cat[ $lb_c0['kind'] ] = (int) $lb_c0['id'];
}
try { // un incasso della mensilità di Bruno e un versamento parziale della quota di Carla (non per forza accettato: serve solo a variare i dati)
	$lb_ledger->record_receipt( array( 'date' => $lb_today, 'account_id' => (int) $lb_cash['id'], 'method' => 'cash', 'person_id' => $lb_b, 'lines' => array( array( 'category_id' => $lb_cat['activity_fee'], 'amount_cents' => 2000, 'activity_id' => $lb_course, 'competence_month' => $lb_month ) ) ) );
	$lb_ledger->record_receipt( array( 'date' => $lb_today, 'account_id' => (int) $lb_cash['id'], 'method' => 'cash', 'person_id' => $lb_c, 'lines' => array( array( 'category_id' => $lb_cat['membership'], 'amount_cents' => 300, 'social_year' => Settings::membership_year()->label() ) ) ) );
} catch ( \Throwable $lb_x ) {
	WP_CLI::log( 'nota: incasso di prova non registrato (' . $lb_x->getMessage() . ')' );
}
$lb_ev1 = $lb_acts->create( array( 'name' => 'Serata a blocchi', 'social_year' => $lb_sy->label(), 'kind' => 'event', 'fee_cents' => 1000, 'session' => array( 'session_date' => $lb_today, 'capacity' => 20 ) ) );
$lb_ev2 = $lb_acts->create( array( 'name' => 'Ricorrente a blocchi', 'social_year' => $lb_sy->label(), 'kind' => 'recurring', 'fee_cents' => 500 ) );
$lb_acts->add_session( $lb_ev2, array( 'session_date' => $lb_today ) );
$lb_s1 = (int) $lb_acts->sessions( $lb_ev1 )[0]['id'];
$lb_s2 = (int) $lb_acts->sessions( $lb_ev2 )[0]['id'];
$lb_acts->book( $lb_s1, $lb_a );
$lb_acts->book( $lb_s1, $lb_b );
$lb_acts->book( $lb_s1, $lb_h );
$lb_acts->cancel_booking( $lb_s1, $lb_h ); // prenotazione annullata
$lb_acts->book( $lb_s2, $lb_a );

$lb_all     = $lb_acts->for_year( $lb_sy->label() );
$lb_courses = array_values( array_filter( $lb_all, function ( $a ) {
	return 'course' === $a['kind'];
} ) );
$lb_events  = array_values( array_filter( $lb_all, function ( $a ) {
	return 'course' !== $a['kind'];
} ) );

// ---------- Partecipanti, iscritti, date e prenotazioni: lo stesso risultato della lettura singola ----------
$lb_many = $lb_acts->active_participants_many( $lb_all );
$lb_same = true;
foreach ( $lb_all as $lb_x ) {
	$lb_same = $lb_same && ( $lb_many[ (int) $lb_x['id'] ] ?? -1 ) === $lb_acts->active_participants( (int) $lb_x['id'] );
}
asem_ok( $lb_same && count( $lb_all ) >= 3 && 6 === $lb_many[ $lb_course ] && 2 === $lb_many[ $lb_ev1 ], 'letture a blocchi: i partecipanti di tutte le attività sono quelli della lettura una per una' );

$lb_st   = $lb_acts->status_for_activities( $lb_courses );
$lb_same = true;
foreach ( $lb_courses as $lb_x ) {
	$one  = $lb_acts->status_for_activity( (int) $lb_x['id'] );
	$many = $lb_st[ (int) $lb_x['id'] ] ?? array();
	$lb_same = $lb_same && count( $one ) === count( $many );
	foreach ( $one as $i => $row ) {
		$lb_same = $lb_same && isset( $many[ $i ] ) && $row['enrollment'] === $many[ $i ]['enrollment'] && $row['summary'] == $many[ $i ]['summary']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- confronto tra liste
	}
}
asem_ok( $lb_same && count( $lb_st[ $lb_course ] ) >= 5, 'letture a blocchi: gli iscritti con la situazione pagamenti di più corsi sono uguali e nello stesso ordine' );

$lb_sm   = $lb_acts->sessions_many( array( $lb_ev1, $lb_ev2, $lb_course ) );
$lb_same = array() === $lb_sm[ $lb_course ];
foreach ( array( $lb_ev1, $lb_ev2 ) as $lb_x ) {
	$lb_same = $lb_same && $lb_sm[ $lb_x ] === $lb_acts->sessions( $lb_x );
}
asem_ok( $lb_same, 'letture a blocchi: le date di più attività sono uguali alla lettura una per una' );

$lb_bm   = $lb_acts->bookings_for_sessions( array( $lb_s1, $lb_s2 ) );
$lb_same = true;
foreach ( array( $lb_s1, $lb_s2 ) as $lb_x ) {
	$lb_same = $lb_same && $lb_bm[ $lb_x ] === $lb_acts->bookings_for_session( $lb_x );
}
asem_ok( $lb_same && 3 === count( $lb_bm[ $lb_s1 ] ) && 'booked' === $lb_bm[ $lb_s1 ][0]['status'], 'letture a blocchi: le prenotazioni di più date sono uguali alla lettura una per una (attive per prime)' );

$lb_en   = $lb_acts->current_enrollees_many( array( $lb_course ), current_time( 'Y-m' ) );
$lb_old  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND (end_month IS NULL OR end_month >= %s)', $lb_course, current_time( 'Y-m' ) ) ) );
$lb_new  = $lb_en[ $lb_course ];
sort( $lb_old );
asem_ok( $lb_old === $lb_new && count( $lb_new ) >= 5, 'letture a blocchi: gli iscritti in corso di un corso sono quelli di sempre' );

// ---------- Cancellazione di un'iscrizione: la risposta per più persone insieme è quella per persona ----------
$lb_ids  = array( $lb_a, $lb_b, $lb_c, $lb_d, $lb_e, $lb_f, $lb_g, $lb_h );
$lb_free = ActivityReset::free_registrations( $lb_course, $lb_ids );
$lb_same = true;
foreach ( $lb_ids as $lb_x ) {
	$lb_same = $lb_same && ( $lb_free[ $lb_x ] ?? null ) === ActivityReset::registration_is_free( $lb_course, $lb_x );
}
asem_ok( $lb_same && in_array( true, $lb_free, true ) && in_array( false, $lb_free, true ) && false === $lb_free[ $lb_h ] && false === $lb_free[ $lb_g ], 'letture a blocchi: quali iscrizioni di un corso si cancellano senza toccare la prima nota (stessa risposta di una per una; non iscritto ed eliminato: no)' );
$lb_free = ActivityReset::free_registrations( $lb_ev1, $lb_ids, $lb_s1 );
$lb_same = true;
foreach ( $lb_ids as $lb_x ) {
	$lb_same = $lb_same && ( $lb_free[ $lb_x ] ?? null ) === ActivityReset::registration_is_free( $lb_ev1, $lb_x, $lb_s1 );
}
asem_ok( $lb_same && false === $lb_free[ $lb_c ] && true === $lb_free[ $lb_a ], 'letture a blocchi: lo stesso per una data di evento (chi non ha prenotato no)' );
asem_ok( array() === ActivityReset::free_registrations( $lb_ev1, array() ) && false === ActivityReset::free_registrations( $lb_ev1, array( $lb_a ), $lb_s2 )[ $lb_a ], 'letture a blocchi: nessuna persona o una data di un\'altra attività non cancella nulla' );

// ---------- Tessere: scadenza, anno della quota e già versato per più persone insieme ----------
$lb_end   = $lb_sy->end()->modify( '-5 days' )->format( 'Y-m-d' ); // dentro il periodo di rinnovo
$lb_until = $lb_people->active_until_many( $lb_ids, $lb_today );
$lb_same  = true;
foreach ( $lb_ids as $lb_x ) {
	$lb_same = $lb_same && ( array_key_exists( $lb_x, $lb_until ) ? $lb_until[ $lb_x ] : 'manca' ) === $lb_people->active_until( $lb_x, $lb_today );
}
asem_ok( $lb_same && null !== $lb_until[ $lb_a ] && null === $lb_until[ $lb_c ], 'letture a blocchi: la scadenza della tessera di più persone è quella di una per una' );
foreach ( array( $lb_today, $lb_end ) as $lb_date ) {
	$lb_plans = $lb_people->membership_plans_many( $lb_ids, $lb_date );
	$lb_same  = true;
	foreach ( $lb_ids as $lb_x ) {
		$lb_same = $lb_same && $lb_plans[ $lb_x ] === $lb_people->membership_plan( $lb_x, $lb_date );
	}
	asem_ok( $lb_same, 'letture a blocchi: l\'anno della quota di più persone è quello di una per una (' . $lb_date . ')' );
}
$lb_years = array();
foreach ( $lb_people->membership_plans_many( $lb_ids, $lb_end ) as $lb_x => $lb_pl ) {
	$lb_years[ $lb_x ] = $lb_pl['year'];
}
$lb_paid = $lb_people->membership_paid_many( $lb_years );
$lb_same = true;
foreach ( $lb_years as $lb_x => $lb_yl ) {
	$lb_same = $lb_same && ( $lb_paid[ $lb_x ] ?? -1 ) === $lb_people->membership_paid( $lb_x, $lb_yl );
}
asem_ok( $lb_same && array() === $lb_people->membership_paid_many( array() ), 'letture a blocchi: il già versato per la quota di più persone è quello di una per una' );

// ---------- Bacheca: «Pagamenti da incassare» uguale al calcolo di prima, con la tessera al saldo accesa e spenta ----------
$lb_pd = new \ReflectionMethod( Admin\DashboardPage::class, 'payments_due' );
$lb_pd->setAccessible( true );
foreach ( array( 1, 0 ) as $lb_flag ) {
	Settings::update( array( 'limits' => array_merge( $lb_flag0, array( 'membership_on_balance' => $lb_flag ) ) ) );
	$lb_ref = asem_lb_old_payments();
	ob_start();
	$lb_pd->invoke( null );
	$lb_html = (string) ob_get_clean();
	$lb_rows = array_values( $lb_ref );
	$lb_pos  = 0;
	$lb_same = true;
	foreach ( array_slice( $lb_rows, 0, 12 ) as $lb_r ) {
		$lb_i = strpos( $lb_html, esc_html( implode( ' + ', $lb_r['what'] ) ), $lb_pos );
		if ( false === $lb_i || false === strpos( $lb_html, esc_html( Money::format( (int) $lb_r['cents'] ) ), $lb_i ) ) {
			$lb_same = false;
			break;
		}
		$lb_pos = $lb_i + 1;
	}
	asem_ok( $lb_rows && $lb_same && false !== strpos( $lb_html, 'Pagamenti da incassare (' . count( $lb_rows ) . ')' ), 'bacheca: pagamenti da incassare uguali al calcolo di prima, stessi importi e stesso ordine (quota al saldo ' . ( $lb_flag ? 'accesa' : 'spenta' ) . ', ' . count( $lb_rows ) . ' persone)' );
}
Settings::update( array( 'limits' => $lb_flag0 ) );

// ---------- Elenco e scheda: stesso HTML con e senza le letture comuni ----------
$lb_summ = Plugin::reports()->social_year( $lb_sy )['activities'];
$lb_prim = new \ReflectionMethod( Admin\ActivitiesPage::class, 'prime_list' );
$lb_prim->setAccessible( true );
$lb_pre = new \ReflectionProperty( Admin\ActivitiesPage::class, 'pre' );
$lb_pre->setAccessible( true );
$lb_same = true;
foreach ( $lb_summ as $lb_s ) {
	$lb_pre->setValue( null, null );
	$lb_plain = Admin\ActivitiesPage::participants_html( $lb_s['activity'], 1, false );
	$lb_prim->invoke( null, $lb_summ );
	$lb_fast = Admin\ActivitiesPage::participants_html( $lb_s['activity'], 1, false );
	$lb_pre->setValue( null, null );
	$lb_same = $lb_same && $lb_plain === $lb_fast && (int) $lb_s['participants'] === $lb_acts->active_participants( (int) $lb_s['activity']['id'] );
}
asem_ok( $lb_same && count( $lb_summ ) >= 3, 'elenco attività: i partecipanti attesi di ogni riga escono identici con le letture comuni e con quelle singole' );

foreach ( array( $lb_course => array( $lb_a, $lb_b, $lb_c, $lb_d, $lb_e ), $lb_ev1 => array( $lb_a, $lb_b ) ) as $lb_id => $lb_who ) {
	$lb_act  = $lb_acts->get( $lb_id );
	$lb_html = Admin\ActivitiesPage::participants_html( $lb_act, 8, true );
	$lb_same = true;
	foreach ( $lb_who as $lb_x ) {
		$lb_sid  = $lb_id === $lb_ev1 ? $lb_s1 : 0;
		$lb_link = false !== strpos( $lb_html, esc_url( Admin\DeleteBookingPage::url( $lb_id, $lb_x, $lb_sid ) ) ); // il collegamento alla procedura c'è solo se l'iscrizione ha incassi
		$lb_same = $lb_same && $lb_link === ! ActivityReset::registration_is_free( $lb_id, $lb_x, $lb_sid );
	}
	asem_ok( $lb_same, 'scheda attività: il comando «Cancella» di ogni partecipante (pulsante o procedura con le somme) segue la regola di una per una (attività ' . $lb_id . ')' );
}

$lb_old = array_map( 'intval', array_column( $wpdb->get_results( $wpdb->prepare( 'SELECT person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NULL', $lb_course ), ARRAY_A ), 'person_id' ) );
$lb_old = array_values( array_filter( $lb_old, function ( $pid ) use ( $lb_people ) {
	return null !== $lb_people->get( $pid );
} ) );
$lb_new = array_map( 'intval', array_column( Notices::participants( $lb_course ), 'id' ) );
asem_ok( $lb_old === $lb_new && count( $lb_new ) >= 4, 'avvisi agli iscritti: i destinatari di un corso sono gli stessi e nello stesso ordine' );

$lb_page = asem_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'page' => 'asem-activity', 'id' => (string) $lb_course ) );
asem_ok( false !== strpos( $lb_page, 'Anna Blocchi' ) && false !== strpos( $lb_page, 'Carla Blocchi' ) && false === strpos( $lb_page, 'Gina Blocchi' ), 'scheda del corso: iscritti e pagamenti (la persona eliminata non compare)' );
$lb_page = asem_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Data e prenotazioni', array( 'page' => 'asem-activity', 'id' => (string) $lb_ev1 ) );
asem_ok( false !== strpos( $lb_page, 'Anna Blocchi' ) && false !== strpos( $lb_page, 'Bruno Blocchi' ), 'scheda dell\'evento: date e prenotazioni' );
$lb_page = asem_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Corso a blocchi', array( 'page' => 'asem-activities' ) );
asem_ok( false !== strpos( $lb_page, 'Serata a blocchi' ) && false !== strpos( $lb_page, 'Ricorrente a blocchi' ), 'elenco attività: corsi ed eventi dell\'anno' );

// ---------- Elenchi a scelta: le voci già preparate non cambiano il risultato ----------
$lb_map  = array( 1 => 'Uno', 2 => 'Due <b>', '3' => 'Tre & "quattro" \'cinque\'', 'x' => '', 7 => 'n.7 · Settimo' );
$lb_same = true;
foreach ( array( null, 2, '3', 'x', 99 ) as $lb_sel ) {
	foreach ( array( null, '— scegli —' ) as $lb_empty ) {
		for ( $lb_pass = 0; $lb_pass < 2; $lb_pass++ ) { // la seconda volta le voci vengono dalla memoria
			$lb_same = $lb_same && asem_lb_old_options( $lb_map, $lb_sel, $lb_empty ) === Admin\Ui::options( $lb_map, $lb_sel, $lb_empty );
		}
	}
}
asem_ok( $lb_same, 'elenchi a scelta: le voci escono identiche a prima, scelte o no, anche ripetute' );

// ---------- Capofamiglia letto una volta per richiesta, ma sempre aggiornato ----------
$lb_head = $lb_mk( 'Capo', true );
$lb_fam  = array( 'id' => 999999, 'family_head_id' => $lb_head );
asem_ok( Levels::is_family_member( $lb_fam ) && Levels::is_family_member( $lb_fam ), 'nucleo familiare: il familiare di un capofamiglia socio è riconosciuto (anche alla seconda lettura)' );
$wpdb->update( Db::t( 'people' ), array( 'deleted_at' => Db::now() ), array( 'id' => $lb_head ) );
asem_ok( ! Levels::is_family_member( $lb_fam ), 'nucleo familiare: se il capofamiglia esce dall\'anagrafica la memoria si aggiorna da sola' );

// ---------- Modulo del corso: il primo giorno giusto di una lezione settimanale ----------
$lb_same = true;
foreach ( array( '2026-01-01', '2026-02-28', '2026-12-31', '2024-02-29', '2026-09-07' ) as $lb_from ) {
	for ( $lb_day = 0; $lb_day <= 8; $lb_day++ ) {
		$lb_old = \AssociazioneSemplice\Schedule::weekly_dates( $lb_from, '2032-12-31', $lb_day ); // come prima ma fino a un orizzonte vicino: il primo elemento è lo stesso
		$lb_new = \AssociazioneSemplice\Schedule::weekly_dates( $lb_from, ( new \DateTimeImmutable( $lb_from ) )->modify( '+6 days' )->format( 'Y-m-d' ), $lb_day );
		$lb_same = $lb_same && ( $lb_old ? $lb_old[0] : null ) === ( $lb_new ? $lb_new[0] : null );
	}
}
asem_ok( $lb_same, 'modulo del corso: il primo giorno di una lezione settimanale è quello di sempre per ogni giorno della settimana (e nessun giorno non valido)' );

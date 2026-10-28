<?php
/**
 * Prova dell'edizione gratuita dentro un WordPress reale: il plugin SENZA i file elencati in tests/pro-files.txt deve funzionare.
 *   wp eval-file wp-content/plugins/associazionesemplice/tests/smoke_free.php
 * Si lancia dopo aver cancellato quei file dalla cartella del plugin (lo fa il workflow).
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Fiscal;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

$GLOBALS['asem_warnings'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( ! ( error_reporting() & $no ) ) {
			return false;
		}
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/associazionesemplice/' ) ) {
			$GLOBALS['asem_warnings'][] = "$str ($file:$line)";
		}
		return false;
	}
);

function free_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

function free_render( callable $page, array $get = array() ): string {
	$old  = $_GET;
	$_GET = $get;
	ob_start();
	$page();
	$html = ob_get_clean();
	$_GET = $old;
	return $html;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
\AssociazioneSemplice\Admin\Admin::init();

// ---------- Cosa c'è e cosa no ----------
$lines = array_filter( array_map( 'trim', file( __DIR__ . '/pro-files.txt' ) ?: array() ), function ( $l ) {
	return '' !== $l && '#' !== $l[0];
} );
free_ok( count( $lines ) >= 1, 'elenco dei file avanzati letto' );
foreach ( $lines as $rel ) {
	free_ok( ! file_exists( ASEM_DIR . 'includes/' . $rel ), "assente nell'edizione gratuita: $rel" );
}
free_ok( ! Edition::has( 'vat' ) && ! Edition::has( 'levels' ) && ! Edition::pro_present(), 'senza estensioni nessuna funzione aggiunta è presente' );
free_ok( ! Edition::has( 'medical' ) && ! Edition::has( 'affiliations' ) && ! class_exists( 'AssociazioneSemplice\\Certificates' ), 'senza il Pro non ci sono certificati medici né affiliazioni agli enti' );
free_ok( Edition::allows( 'export' ) && Edition::allows( 'member_area' ), 'senza licenza le funzioni presenti sono consentite' );

// ---------- IVA: anche con la partita IVA accesa non compare nulla di fiscale ----------
Settings::update( array( 'has_vat' => 1, 'vat_number' => '12345678903', 'fiscal_regime' => 'ordinary' ) );
free_ok( ! Fiscal::vat_applies(), 'l\'IVA non si applica nemmeno se le impostazioni la dicono accesa' );
Settings::update( array( 'has_vat' => 0, 'vat_number' => '' ) );

// ---------- Pagine di amministrazione ----------
$pages = array(
	'Bacheca'               => array( array( \AssociazioneSemplice\Admin\DashboardPage::class, 'render' ), array() ),
	'Soci e quote'          => array( array( \AssociazioneSemplice\Admin\SettingsPage::class, 'render' ), array() ),
	'Dati dell\'ente'       => array( array( \AssociazioneSemplice\Admin\EntityPage::class, 'render' ), array() ),
	'Configurazione guidata' => array( array( \AssociazioneSemplice\Admin\WizardPage::class, 'render' ), array() ),
	'Rubrica'               => array( array( \AssociazioneSemplice\Admin\PeoplePage::class, 'render_list' ), array() ),
	'Corsi ed eventi'       => array( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_list' ), array() ),
	'Soldi'                 => array( array( \AssociazioneSemplice\Admin\MoneyPage::class, 'render' ), array() ),
	'Prima nota'            => array( array( \AssociazioneSemplice\Admin\LedgerPage::class, 'render' ), array() ),
	'Nuovo incasso'         => array( array( \AssociazioneSemplice\Admin\IncomePage::class, 'render' ), array() ),
	'Nuova spesa'           => array( array( \AssociazioneSemplice\Admin\ExpensePage::class, 'render' ), array() ),
	'Anni solari'           => array( array( \AssociazioneSemplice\Admin\YearsPage::class, 'render' ), array() ),
	'Widget da lavorare'    => array( array( \AssociazioneSemplice\Admin\WpDashboard::class, 'todo' ), array() ),
	'Widget settimana'      => array( array( \AssociazioneSemplice\Admin\WpDashboard::class, 'week' ), array() ),
);
$html = array();
foreach ( $pages as $name => $p ) {
	$html[ $name ] = free_render( $p[0], $p[1] );
	free_ok( '' !== $html[ $name ], "pagina: $name" );
}
// nessun collegamento a pagine che in questa edizione non ci sono
foreach ( array( 'Bacheca', 'Soldi', 'Prima nota', 'Soci e quote' ) as $pn ) {
	foreach ( array( 'asem-accounts', 'asem-group', 'asem-transfer', 'asem-reports', 'asem-statement', 'asem-volunteers', 'asem-attendance', 'asem-app', 'asem-acct', 'asem-fivepm', 'asem-payments', 'asem-messages', 'asem-texts', 'asem-resources', 'asem-credits', 'asem-accounting' ) as $slug ) {
		free_ok( false === strpos( $html[ $pn ], 'page=' . $slug . '"' ) && false === strpos( $html[ $pn ], 'page=' . $slug . '&' ), "pagina $pn: nessun collegamento a $slug" );
	}
}
free_ok( false === strpos( $html['Soci e quote'], 'Livelli di socio' ) && false === strpos( $html['Soci e quote'], 'Chiave di licenza' ), 'impostazioni: niente livelli e niente sezione licenza (è del Pro)' );
free_ok( false === strpos( $html['Dati dell\'ente'], 'Partita IVA' ), 'dati dell\'ente: niente partita IVA' );
free_ok( false === strpos( $html['Configurazione guidata'], 'name="has_vat"' ) && false === strpos( $html['Configurazione guidata'], 'name="extra_levels"' ), 'configurazione guidata: niente IVA né tipi di socio extra' );
free_ok( false === strpos( $html['Nuova spesa'], 'name="vat_rate"' ) && false === strpos( $html['Nuovo incasso'], 'name="vat_rate"' ), 'incasso e spesa: nessun campo IVA' );

// ---------- Un giro vero: socio, evento, prenotazione, incasso ----------
$people = Plugin::people();
$acts   = Plugin::activities();
$ledger = Plugin::ledger();
$sy     = Settings::social_year( current_time( 'Y-m-d' ) )->label();
$pid    = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Prova', 'last_name' => 'Gratuita', 'email' => 'prova.gratuita@example.com' ) );
$people->set_membership( $pid, Settings::membership_year()->label(), true );
$aid = $acts->create( array( 'name' => 'Cena di prova', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 1500, 'session' => array( 'session_date' => current_time( 'Y-m-d' ), 'capacity' => 10 ) ) );
$sid = (int) $acts->sessions( $aid )[0]['id'];
$acts->book( $sid, $pid );
$cash = $ledger->accounts()[0];
$tx   = $ledger->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $pid, 'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 1500, 'activity_id' => $aid, 'session_id' => $sid ) ) ) );
free_ok( $tx > 0, 'incasso registrato in prima nota' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT vat_rate, vat_cents FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx ), ARRAY_A );
free_ok( null === $row['vat_rate'] && 0 === (int) $row['vat_cents'], 'nessuna IVA sull\'incasso' );

// ---------- Tutte le pagine di amministrazione si aprono (anche quelle delle funzioni assenti, con l'avviso) ----------
$src = (string) file_get_contents( ASEM_DIR . 'includes/Admin/Admin.php' );
preg_match_all( "/array\( '(asem[a-z-]*)', '(?:[^'\\\\]|\\\\.)*', array\( ([A-Za-z]+)::class, '([a-z_]+)' \) \)/", $src, $m, PREG_SET_ORDER );
free_ok( count( $m ) > 20, 'elenco delle pagine di amministrazione letto (' . count( $m ) . ')' );
$skip = array( 'asem-activity-delete', 'asem-booking-delete', 'asem-reset', 'asem-activity' );
$n_pages = 0;
foreach ( $m as $pg ) {
	if ( in_array( $pg[1], $skip, true ) ) {
		continue;
	}
	$cb   = array( 'AssociazioneSemplice\\Admin\\' . $pg[2], $pg[3] );
	$wrap = \AssociazioneSemplice\Admin\Admin::guard( $pg[1], $cb );
	$out  = free_render( $wrap, array( 'page' => $pg[1] ) );
	free_ok( '' !== $out, 'pagina ' . $pg[1] . ' si apre' );
	$n_pages++;
}
free_ok( $n_pages > 20, "$n_pages pagine provate" );

// ---------- Pagamenti: solo l'elenco di ciò che c'è da pagare ----------
free_ok( ! Edition::has( 'payments' ) && get_class( Plugin::payments() ) === 'AssociazioneSemplice\OfflinePayments' && ! Plugin::payments()->enabled() && array() === Plugin::payments()->providers(), 'pagamenti online assenti' );
$aid2 = $acts->create( array( 'name' => 'Gita da pagare', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 1000, 'session' => array( 'session_date' => current_time( 'Y-m-d' ), 'capacity' => 10 ) ) );
$acts->book( (int) $acts->sessions( $aid2 )[0]['id'], $pid );
$actor = $people->get( $pid );
$dues  = Plugin::payments()->dues_for( $actor );
free_ok( is_array( $dues ) && count( $dues ) >= 1, 'le voci da pagare si calcolano lo stesso' );
$area = \AssociazioneSemplice\Frontend\Views::section_pay( $actor );
free_ok( false !== strpos( $area, 'Pagamenti' ) && false === strpos( $area, 'asem_front_pay' ), 'area soci: voci da pagare senza pulsanti di pagamento online' );
$threw = null !== ( function () use ( $actor ) {
	try {
		\AssociazioneSemplice\Frontend\Actions::do_pay( array() );
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
	return null;
} )();
free_ok( $threw, 'il pagamento online è rifiutato' );
// pagina informativa sul Pro: c'è, spiega i livelli e non ha funzioni finte
free_ok( \AssociazioneSemplice\Admin\ProPage::needed(), 'nel gratuito la pagina «Scopri il Pro» è disponibile' );
ob_start();
\AssociazioneSemplice\Admin\ProPage::render();
$pro_page = (string) ob_get_clean();
free_ok( false !== strpos( $pro_page, 'Pagamenti online' ) && false !== strpos( $pro_page, 'Pro Full' ) && false !== strpos( $pro_page, 'IVA' ) && false === strpos( $pro_page, 'attiva</span>' ), 'pagina Pro: mostra le funzioni dei due livelli, nessuna già attiva' );

// ---------- Il salvataggio dei livelli è rifiutato ----------
$threw = false;
try {
	$m = new ReflectionMethod( \AssociazioneSemplice\Admin\Actions::class, 'save_levels' );
	$m->setAccessible( true );
	$m->invoke( null, array( 'level' => array() ) );
} catch ( \Throwable $e ) {
	$threw = true;
}
free_ok( $threw, 'livelli: il salvataggio non è consentito' );

// ---------- Domanda di adesione e minori: funzionano anche senza le funzioni avanzate ----------
$fr_mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$fr_mails ) {
		$fr_mails[] = $atts;
		return true;
	},
	10,
	2
);
Settings::update( array( 'apply_enabled' => 1, 'apply_pay_online' => 1, 'membership_fee_cents' => 1000, 'rules_enabled' => 0, 'apply_email' => 'segreteria.free@example.com' ) );
free_ok( ! \AssociazioneSemplice\Applications::online_payment(), 'domanda: senza pagamenti online la quota si paga in sede (anche con l\'impostazione accesa)' );
$fr_form = \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'adesione' );
free_ok( false !== strpos( $fr_form, 'name="birth_date"' ) && false !== strpos( $fr_form, 'si paga in sede' ), 'domanda: il modulo funziona nel gratuito' );
$fr_in = array(
	'first_name' => 'Carla', 'last_name' => 'Gratis', 'email' => 'carla.gratis@example.com', 'phone' => '333 4040404', 'tax_code' => 'RSSMRA79A01H501B',
	'birth_date' => '1985-05-05', 'type' => 'ordinary', 'privacy_ok' => '1', 'rules_ok' => '1',
);
free_ok( 'ok' === \AssociazioneSemplice\Applications::submit( $fr_in )['status'] && 1 === \AssociazioneSemplice\Applications::pending_count(), 'domanda: registrata nel gratuito' );
$fr_app = \AssociazioneSemplice\Applications::pending()[0];
free_ok( null === $fr_app['paid'] && 1000 === (int) $fr_app['fee_cents'], 'domanda: nessun pagamento online, quota da pagare in sede' );
\AssociazioneSemplice\Applications::approve( (int) $fr_app['id'], 'free100' );
free_ok( ! $people->is_active_member( (int) $fr_app['id'] ) && 'free100' === $people->get( (int) $fr_app['id'] )['card_number'] && isset( Plugin::payments()->dues_for( $people->get( (int) $fr_app['id'] ) )[ 'q:' . (int) $fr_app['id'] . ':' . $people->membership_plan( (int) $fr_app['id'], current_time( 'Y-m-d' ) )['year'] ] ), 'domanda: approvata con la tessera; l\'iscrizione vale all\'incasso della quota, che resta da pagare' );
\AssociazioneSemplice\Applications::submit( array_merge( $fr_in, array( 'first_name' => 'Rino', 'last_name' => 'Rifiutato', 'email' => 'rino.rifiutato@example.com', 'tax_code' => 'RSSMRA79A07H501S' ) ) );
\AssociazioneSemplice\Applications::reject( (int) \AssociazioneSemplice\Applications::pending()[0]['id'] );
free_ok( 0 === \AssociazioneSemplice\Applications::pending_count(), 'domanda: rifiutata, senza pagamento non resta nulla' );
$fr_mum = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Mara', 'last_name' => 'Tutrice', 'email' => 'mara.tutrice@example.com' ) );
$fr_kid = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Pio', 'last_name' => 'Piccolo', 'birth_date' => gmdate( 'Y-m-d', strtotime( '-9 years' ) ) ) );
\AssociazioneSemplice\Guardians::link( $fr_kid, $fr_mum );
free_ok( \AssociazioneSemplice\Guardians::is_guardian_of( $fr_mum, $fr_kid ) && array_key_exists( $fr_kid, \AssociazioneSemplice\Guardians::names_by_person() ), 'minori: il tutore si collega anche nel gratuito' );
free_ok( false !== strpos( \AssociazioneSemplice\Frontend\Views::section_minors( $people->get( $fr_mum ) ), 'Pio' ), 'minori: l\'area del tutore mostra il figlio' );
$fr_dues = Plugin::payments()->dues_for( $people->get( $fr_mum ) );
free_ok( isset( $fr_dues[ "q:$fr_kid:" . $people->membership_plan( $fr_kid, current_time( 'Y-m-d' ) )['year'] ] ), 'minori: la quota del figlio è tra le voci del tutore' );
remove_all_filters( 'pre_wp_mail' );
Settings::update( array( 'apply_enabled' => 0 ) );

// ---------- Blocco B: votazioni, inventario e prestiti, archivio documenti ----------
$bb_throws = function ( callable $fn ): bool {
	try {
		$fn();
	} catch ( \InvalidArgumentException $e ) {
		return true;
	}
	return false;
};
$bb_local = function ( string $mod ): string {
	return gmdate( 'Y-m-d\TH:i', strtotime( \AssociazioneSemplice\Db::now() . ' ' . $mod ) );
};
$bb_label = Settings::membership_year()->label();
$bb_a = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Vera', 'last_name' => 'Votante', 'email' => 'vera.votante@example.com' ) );
$bb_b = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Nino', 'last_name' => 'Sospeso', 'email' => 'nino.sospeso@example.com' ) );
$bb_g = $people->create( array( 'type' => 'guardian', 'first_name' => 'Gina', 'last_name' => 'Genitore', 'email' => 'gina.genitore@example.com' ) );
$people->set_membership( $bb_a, $bb_label, true );
$people->set_membership( $bb_b, $bb_label, true );
$people->suspend( $bb_b );
$bb_poll = \AssociazioneSemplice\Polls::save( 0, array( 'kind' => 'vote', 'title' => 'Bilancio', 'question' => 'Approvi?', 'options' => array( 'Favorevole', 'Contrario' ), 'secret' => 1, 'opens_at' => $bb_local( '-1 hour' ), 'closes_at' => $bb_local( '+1 day' ), 'show_results' => 1 ), $admin->ID );
$bb_opts = array_column( \AssociazioneSemplice\Polls::options( $bb_poll ), 'id' );
free_ok( 2 === count( $bb_opts ) && \AssociazioneSemplice\PollRules::OPEN === \AssociazioneSemplice\Polls::get( $bb_poll )['status'], 'votazioni: creata con due risposte e aperta' );
free_ok( $bb_throws( function () use ( $bb_local, $admin ) { \AssociazioneSemplice\Polls::save( 0, array( 'title' => 'Storta', 'options' => array( 'Una' ), 'opens_at' => $bb_local( '-1 hour' ), 'closes_at' => $bb_local( '+1 day' ) ), $admin->ID ); } ), 'votazioni: servono almeno due risposte' );
free_ok( $bb_throws( function () use ( $bb_local, $admin ) { \AssociazioneSemplice\Polls::save( 0, array( 'title' => 'Storta', 'options' => array( 'Una', 'Due' ), 'opens_at' => $bb_local( '+2 day' ), 'closes_at' => $bb_local( '+1 day' ) ), $admin->ID ); } ), 'votazioni: la chiusura deve venire dopo l\'apertura' );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_g, $bb_opts ) { \AssociazioneSemplice\Polls::vote( $bb_poll, $bb_g, array( (int) $bb_opts[0] ) ); } ), 'votazioni: un genitore o tutore non vota' );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_b, $bb_opts ) { \AssociazioneSemplice\Polls::vote( $bb_poll, $bb_b, array( (int) $bb_opts[0] ) ); } ), 'votazioni: un socio sospeso non vota' );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_a, $bb_opts ) { \AssociazioneSemplice\Polls::vote( $bb_poll, $bb_a, array( (int) $bb_opts[0], (int) $bb_opts[1] ) ); } ), 'votazioni: a scelta singola non si scelgono due risposte' );
\AssociazioneSemplice\Polls::vote( $bb_poll, $bb_a, array( (int) $bb_opts[0] ) );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_a, $bb_opts ) { \AssociazioneSemplice\Polls::vote( $bb_poll, $bb_a, array( (int) $bb_opts[1] ) ); } ), 'votazioni: un voto solo per persona' );
$bb_res = \AssociazioneSemplice\Polls::results( $bb_poll, true );
free_ok( 1 === $bb_res['voters'] && 1 === $bb_res['options'][0]['count'] && 0 === $bb_res['options'][1]['count'] && $bb_res['eligible'] >= 1, 'votazioni: risultati e numero degli aventi diritto' );
$bb_sum = function () use ( $wpdb, $bb_poll ): int {
	return (int) $wpdb->get_var( 'SELECT SUM(votes) FROM ' . Db::t( 'poll_options' ) . ' WHERE poll_id = ' . (int) $bb_poll );
};
free_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'poll_votes' ) . ' WHERE poll_id = ' . (int) $bb_poll ) && \AssociazioneSemplice\Polls::has_voted( $bb_poll, $bb_a ) && 1 === $bb_sum(), 'votazioni: nel voto segreto nessuna scheda collega persona e scelta, restano chi ha votato e i contatori' );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_a, $bb_opts ) { \AssociazioneSemplice\Polls::vote( $bb_poll, $bb_a, array( (int) $bb_opts[1] ) ); } ) && 1 === $bb_sum() && 1 === \AssociazioneSemplice\Polls::vote_count( $bb_poll ), 'votazioni: un secondo tentativo non lascia schede o contatori a metà' );
$bb_win = function ( string $open, string $close ) use ( $bb_local ) {
	return array( 'title' => 'Bilancio', 'question' => 'Approvi?', 'opens_at' => $bb_local( $open ), 'closes_at' => $bb_local( $close ), 'show_results' => 1 );
};
free_ok( $bb_throws( function () use ( $bb_poll, $bb_win, $admin ) { \AssociazioneSemplice\Polls::save( $bb_poll, $bb_win( '-2 hour', '+1 day' ), $admin->ID ); } ), 'votazioni: con voti già dati la data di apertura non si sposta' );
$bb_keep = array( 'title' => 'Bilancio', 'question' => 'Approvi?', 'opens_at' => \AssociazioneSemplice\Polls::get( $bb_poll )['opens_at'], 'closes_at' => $bb_local( '+2 day' ), 'show_results' => 1 );
\AssociazioneSemplice\Polls::save( $bb_poll, $bb_keep, $admin->ID );
free_ok( substr( (string) \AssociazioneSemplice\Polls::get( $bb_poll )['closes_at'], 0, 10 ) === substr( (string) $bb_keep['closes_at'], 0, 10 ), 'votazioni: finché è aperta la chiusura si può prorogare' );
$wpdb->update( Db::t( 'polls' ), array( 'opens_at' => $bb_local( '-3 day' ), 'closes_at' => $bb_local( '-1 day' ) ), array( 'id' => $bb_poll ) );
free_ok( $bb_throws( function () use ( $bb_poll, $bb_win, $admin ) { \AssociazioneSemplice\Polls::save( $bb_poll, $bb_win( '-3 day', '+5 day' ), $admin->ID ); } ), 'votazioni: una votazione chiusa con voti non si riapre spostando la chiusura' );
$wpdb->update( Db::t( 'polls' ), array( 'opens_at' => $bb_local( '-1 hour' ), 'closes_at' => $bb_local( '+1 day' ) ), array( 'id' => $bb_poll ) );
free_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s', 'poll.voted' ) ), 'votazioni: il voto non finisce nel registro azioni' );
$bb_c = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Ugo', 'last_name' => 'Avvisato', 'email' => 'ugo.avvisato@example.com' ) );
$people->set_membership( $bb_c, $bb_label, true );
add_filter( 'pre_wp_mail', '__return_true' );
\AssociazioneSemplice\Reminders::notify( $people->get( $bb_c ), 'poll_open', (string) $bb_poll, 'Votazione', 'testo' );
free_ok( \AssociazioneSemplice\Polls::announce( $bb_poll, 'poll_open', true, true ) >= 1 && 0 === \AssociazioneSemplice\Polls::announce( $bb_poll, 'poll_open', true, true ), 'votazioni: «Avvisa i soci ora» parte anche se l\'avviso automatico c\'era già, ma una volta al giorno' );
remove_filter( 'pre_wp_mail', '__return_true' );
free_ok( $bb_throws( function () use ( $bb_poll ) { \AssociazioneSemplice\Docs::poll( $bb_poll, 'csv' ); } ), 'votazioni: nel voto segreto i risultati non si scaricano finché è aperto' );
$wpdb->update( Db::t( 'polls' ), array( 'opens_at' => $bb_local( '-3 day' ), 'closes_at' => $bb_local( '-1 day' ) ), array( 'id' => $bb_poll ) );
free_ok( false !== strpos( \AssociazioneSemplice\Docs::poll( $bb_poll, 'csv' )['body'], 'Favorevole' ) && 0 === strpos( \AssociazioneSemplice\Docs::poll( $bb_poll, 'pdf' )['body'], '%PDF' ), 'votazioni: CSV e PDF del verbale dei risultati' );
$wpdb->update( Db::t( 'polls' ), array( 'opens_at' => $bb_local( '-1 hour' ), 'closes_at' => $bb_local( '+1 day' ) ), array( 'id' => $bb_poll ) );
$bb_exp = \AssociazioneSemplice\Privacy::export( $bb_a );
free_ok( 1 === count( $bb_exp['votazioni_a_cui_ha_partecipato'] ) && ! $bb_exp['scelte_nelle_votazioni_palesi'], 'privacy: l\'esportazione dei dati elenca le votazioni, senza la scelta nel voto segreto' );

$bb_item = \AssociazioneSemplice\Inventory::save_item( 0, array( 'name' => 'Proiettore', 'category' => 'Audio e video', 'code' => 'PRJ-1', 'quantity' => 2, 'state' => 'good', 'location' => 'Armadio', 'value' => '350,00' ) );
free_ok( $bb_throws( function () { \AssociazioneSemplice\Inventory::save_item( 0, array( 'name' => 'Doppione', 'code' => 'PRJ-1', 'quantity' => 1, 'state' => 'good' ) ); } ), 'inventario: il codice è unico' );
$bb_today = current_time( 'Y-m-d' );
$bb_loan  = \AssociazioneSemplice\Inventory::lend( $bb_item, $bb_a, 1, gmdate( 'Y-m-d', strtotime( $bb_today . ' -10 days' ) ), gmdate( 'Y-m-d', strtotime( $bb_today . ' -3 days' ) ), 'Funziona bene' );
free_ok( 1 === \AssociazioneSemplice\Inventory::items( 'Proiettore' )[0]['available'], 'inventario: un pezzo è fuori, uno è disponibile' );
free_ok( $bb_throws( function () use ( $bb_item, $bb_a, $bb_today ) { \AssociazioneSemplice\Inventory::lend( $bb_item, $bb_a, 2, $bb_today, $bb_today ); } ), 'inventario: non si prestano più pezzi di quelli disponibili' );
free_ok( $bb_throws( function () use ( $bb_item, $bb_g, $bb_today ) { \AssociazioneSemplice\Inventory::lend( $bb_item, $bb_g, 1, $bb_today, $bb_today ); } ), 'inventario: si presta solo a soci e volontari' );
$bb_late = \AssociazioneSemplice\Inventory::overdue();
free_ok( 1 === count( $bb_late ) && 3 === $bb_late[0]['days_late'], 'prestiti: il rientro scaduto compare tra quelli da recuperare' );
free_ok( in_array( 'prestito con il rientro scaduto', array_column( \AssociazioneSemplice\Admin\WpDashboard::todo_items(), 1 ), true ), 'prestiti: riquadro «Da lavorare»' );
free_ok( $bb_throws( function () use ( $bb_a ) { \AssociazioneSemplice\Privacy::anonymize( $bb_a ); } ), 'privacy: non si anonimizza chi ha un bene in prestito' );
Settings::update( array( 'reminders_enabled' => 1, 'reminders_loans' => 1 ) );
add_filter( 'pre_wp_mail', '__return_true' );
$bb_rem = \AssociazioneSemplice\Reminders::run();
free_ok( $bb_rem['loans'] >= 1, 'prestiti: promemoria per email al rientro scaduto' );
free_ok( 0 === \AssociazioneSemplice\Reminders::run()['loans'], 'prestiti: il promemoria parte una volta sola' );
remove_filter( 'pre_wp_mail', '__return_true' );
Settings::update( array( 'reminders_enabled' => 0 ) );
\AssociazioneSemplice\Inventory::return_loan( $bb_loan, $bb_today, 'Tutto a posto', 'worn' );
free_ok( ! \AssociazioneSemplice\Inventory::overdue() && 2 === \AssociazioneSemplice\Inventory::items( 'Proiettore' )[0]['available'] && 'worn' === \AssociazioneSemplice\Inventory::item( $bb_item )['state'], 'prestiti: rientro registrato, bene di nuovo disponibile' );
free_ok( false !== strpos( \AssociazioneSemplice\Inventory::items_csv(), 'PRJ-1' ) && false !== strpos( \AssociazioneSemplice\Inventory::loans_csv(), 'Proiettore' ), 'inventario: esportazione CSV di beni e prestiti' );

$wpdb->insert( Db::t( 'documents' ), array( 'title' => 'Statuto pubblico', 'category' => 'Statuto', 'visibility' => 'public', 'file_name' => 'statuto.pdf', 'file_stored' => str_repeat( 'a', 32 ), 'file_size' => 10, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$bb_d_pub = (int) $wpdb->insert_id;
$wpdb->insert( Db::t( 'documents' ), array( 'title' => 'Verbale interno', 'category' => 'Verbali firmati', 'visibility' => 'staff', 'file_name' => 'verbale.pdf', 'file_stored' => str_repeat( 'b', 32 ), 'file_size' => 10, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$bb_d_staff = (int) $wpdb->insert_id;
$wpdb->insert( Db::t( 'documents' ), array( 'title' => 'Modulo soci', 'category' => 'Moduli', 'visibility' => 'members', 'file_name' => 'modulo.docx', 'file_stored' => str_repeat( 'c', 32 ), 'file_size' => 10, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$bb_d_mem = (int) $wpdb->insert_id;
free_ok( \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_staff ) ), 'documenti: la segreteria apre tutto' );
$bb_user = (int) email_exists( 'vera.votante@example.com' );
if ( ! $bb_user ) {
	$bb_user = (int) wp_insert_user( array( 'user_login' => 'vera_votante', 'user_pass' => wp_generate_password(), 'user_email' => 'vera.votante@example.com', 'role' => Plugin::ROLE_MEMBER ) );
}
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $bb_user ), array( 'id' => $bb_a ) );
wp_set_current_user( $bb_user );
free_ok( \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_mem ) ) && \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_pub ) ) && ! \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_staff ) ), 'documenti: un socio in regola apre quelli per i soci e quelli pubblici, non quelli della segreteria' );
free_ok( false !== strpos( \AssociazioneSemplice\Frontend\BlockBViews::section_polls( $people->get( $bb_a ) ), 'Bilancio' ) && false !== strpos( \AssociazioneSemplice\Frontend\BlockBViews::section_documents( $people->get( $bb_a ) ), 'Modulo soci' ), 'area soci: votazioni e documenti' );
wp_set_current_user( 0 );
free_ok( \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_pub ) ) && ! \AssociazioneSemplice\Access::can_read_document( \AssociazioneSemplice\Documents::get( $bb_d_mem ) ), 'documenti: chi non ha fatto l\'accesso apre solo i pubblici' );
$bb_pub = \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'documenti' );
free_ok( false !== strpos( $bb_pub, 'Statuto pubblico' ) && false === strpos( $bb_pub, 'Modulo soci' ) && false === strpos( $bb_pub, 'Verbale interno' ), 'documenti: lo shortcode pubblico mostra solo i documenti pubblici' );
wp_set_current_user( $admin->ID );

// ---------- Blocco F: convenzioni per i soci ----------
$cf_today = Db::today();
$cf_day   = function ( string $mod ) use ( $cf_today ): string {
	return gmdate( 'Y-m-d', strtotime( $cf_today . ' ' . $mod ) );
};
$cf_vol = $people->create( array( 'type' => 'volunteer', 'first_name' => 'Livia', 'last_name' => 'Volontaria', 'email' => 'livia.volontaria@example.com' ) );
$people->set_membership( $cf_vol, $bb_label, true );
$cf_all  = \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Palestra Forma', 'category' => 'Sport', 'benefit' => 'Sconto del 10%', 'terms' => 'Non cumulabile con altre offerte', 'audience' => 'all', 'is_public' => 1, 'code' => 'FORMA-SOCI-10', 'website' => 'palestraforma.example.com', 'valid_from' => $cf_day( '-10 days' ), 'valid_to' => $cf_day( '+20 days' ) ) );
$cf_vols = \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Libreria Solo Volontari', 'category' => 'Cultura', 'benefit' => 'Libro in omaggio', 'audience' => 'types', 'types' => array( 'volunteer' ), 'code' => 'LIBRI-VOL' ) );
$cf_old  = \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Bar Scaduto', 'category' => 'Sport', 'benefit' => 'Caffè in omaggio', 'is_public' => 1, 'valid_to' => $cf_day( '-1 day' ) ) );
$cf_arch = \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Cinema Archiviato', 'category' => 'Cultura', 'benefit' => 'Biglietto ridotto', 'is_public' => 1 ) );
\AssociazioneSemplice\Conventions::set_archived( $cf_arch, true );
free_ok( $bb_throws( function () { \AssociazioneSemplice\Conventions::save( 0, array( 'name' => '', 'benefit' => 'x' ) ); } ), 'convenzioni: serve il nome' );
free_ok( $bb_throws( function () { \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Storta', 'benefit' => 'x', 'valid_from' => '2026-05-02', 'valid_to' => '2026-05-01' ) ); } ), 'convenzioni: la fine non può precedere l\'inizio' );
free_ok( $bb_throws( function () { \AssociazioneSemplice\Conventions::save( 0, array( 'name' => 'Storta', 'benefit' => 'x', 'audience' => 'types' ) ); } ), 'convenzioni: «alcuni tipi» richiede almeno un tipo' );
free_ok( 'https://palestraforma.example.com' === \AssociazioneSemplice\Conventions::get( $cf_all )['website'], 'convenzioni: il sito senza https:// lo prende' );
free_ok( \AssociazioneSemplice\ConventionRules::STATUS_EXPIRED === \AssociazioneSemplice\Conventions::get( $cf_old )['status'] && \AssociazioneSemplice\ConventionRules::STATUS_ARCHIVED === \AssociazioneSemplice\Conventions::get( $cf_arch )['status'], 'convenzioni: scaduta e archiviata hanno lo stato giusto' );
$cf_ids = function ( array $list ): array {
	return array_map( 'intval', array_column( $list, 'id' ) );
};
free_ok( in_array( $cf_old, $cf_ids( \AssociazioneSemplice\Conventions::all() ), true ) && in_array( $cf_arch, $cf_ids( \AssociazioneSemplice\Conventions::all() ), true ), 'convenzioni: in admin restano anche quelle scadute e archiviate' );
$cf_mine = $cf_ids( \AssociazioneSemplice\Conventions::for_person( $people->get( $bb_a ) ) );
free_ok( in_array( $cf_all, $cf_mine, true ) && ! in_array( $cf_vols, $cf_mine, true ) && ! in_array( $cf_old, $cf_mine, true ) && ! in_array( $cf_arch, $cf_mine, true ), 'convenzioni: un socio in regola vede quelle valide del suo tipo, non quelle scadute, archiviate o di altri tipi' );
free_ok( in_array( $cf_vols, $cf_ids( \AssociazioneSemplice\Conventions::for_person( $people->get( $cf_vol ) ) ), true ), 'convenzioni: un socio volontario vede quella riservata ai volontari' );
free_ok( ! \AssociazioneSemplice\Conventions::for_person( $people->get( $bb_b ) ) && ! \AssociazioneSemplice\Conventions::for_person( $people->get( $bb_g ) ), 'convenzioni: un socio sospeso e un tutore non vedono nulla' );
$cf_pub = \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'convenzioni' );
free_ok( false !== strpos( $cf_pub, 'Palestra Forma' ) && false !== strpos( $cf_pub, 'Sconto del 10%' ) && false === strpos( $cf_pub, 'FORMA-SOCI-10' ) && false === strpos( $cf_pub, 'Non cumulabile' ) && false === strpos( $cf_pub, 'Bar Scaduto' ) && false === strpos( $cf_pub, 'Cinema Archiviato' ) && false === strpos( $cf_pub, 'Libreria Solo Volontari' ), 'convenzioni: l\'elenco pubblico mostra solo le pubbliche valide, senza codice né condizioni' );
free_ok( false === strpos( \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'convenzioni', array( 'categoria' => 'Cultura' ) ), 'Palestra Forma' ), 'convenzioni: il filtro per categoria dello shortcode pubblico' );
free_ok( shortcode_exists( 'convenzioni' ) && shortcode_exists( 'convenzioni_soci' ) && isset( \AssociazioneSemplice\Frontend\Shortcodes::views()['convenzioni'] ), 'convenzioni: gli shortcode [convenzioni] e [convenzioni_soci] esistono' );
$cf_sec = \AssociazioneSemplice\Frontend\BlockFViews::section_members( $people->get( $bb_a ) );
free_ok( false !== strpos( $cf_sec, 'Palestra Forma' ) && false !== strpos( $cf_sec, 'FORMA-SOCI-10' ) && false !== strpos( $cf_sec, 'Vera Votante' ) && false !== strpos( $cf_sec, 'Non cumulabile' ) && false === strpos( $cf_sec, 'LIBRI-VOL' ), 'convenzioni: l\'area soci mostra codice, condizioni e i dati per farsi riconoscere' );
wp_set_current_user( $bb_user );
free_ok( false !== strpos( \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'convenzioni_soci' ), 'Palestra Forma' ), 'convenzioni: lo shortcode per i soci mostra le convenzioni a chi ha fatto l\'accesso' );
wp_set_current_user( 0 );
free_ok( false === strpos( \AssociazioneSemplice\Frontend\Shortcodes::render_view( 'convenzioni_soci' ), 'FORMA-SOCI-10' ), 'convenzioni: senza accesso lo shortcode per i soci non mostra il codice' );
wp_set_current_user( $admin->ID );
free_ok( \AssociazioneSemplice\Conventions::announce( $cf_all, false ) >= 2, 'convenzioni: l\'avviso raggiungerebbe i soci a cui spetta' );
add_filter( 'pre_wp_mail', '__return_true' );
$cf_sent = \AssociazioneSemplice\Conventions::announce( $cf_all, true );
remove_filter( 'pre_wp_mail', '__return_true' );
free_ok( $cf_sent >= 2 && ! empty( \AssociazioneSemplice\Conventions::get( $cf_all )['announced_at'] ), 'convenzioni: l\'avviso parte ai soci a cui spetta' );
free_ok( $bb_throws( function () use ( $cf_all ) { \AssociazioneSemplice\Conventions::announce( $cf_all, true ); } ), 'convenzioni: l\'avviso parte una sola volta per convenzione' );
add_filter( 'pre_wp_mail', '__return_false' ); // la posta non parte
$cf_fail = \AssociazioneSemplice\Conventions::announce( $cf_vols, true );
remove_filter( 'pre_wp_mail', '__return_false' );
free_ok( 0 === $cf_fail && empty( \AssociazioneSemplice\Conventions::get( $cf_vols )['announced_at'] ), 'convenzioni: se nessuna email parte l\'avviso non risulta fatto e si può riprovare' );
add_filter( 'pre_wp_mail', '__return_true' );
free_ok( \AssociazioneSemplice\Conventions::announce( $cf_vols, true ) >= 1 && ! empty( \AssociazioneSemplice\Conventions::get( $cf_vols )['announced_at'] ), 'convenzioni: al secondo tentativo l\'avviso parte' );
remove_filter( 'pre_wp_mail', '__return_true' );
free_ok( $bb_throws( function () use ( $cf_old ) { \AssociazioneSemplice\Conventions::announce( $cf_old, true ); } ), 'convenzioni: non si avvisa per una convenzione scaduta' );
free_ok( in_array( $cf_all, $cf_ids( \AssociazioneSemplice\Conventions::expiring() ), true ) && ! in_array( $cf_vols, $cf_ids( \AssociazioneSemplice\Conventions::expiring() ), true ), 'convenzioni: «in scadenza» comprende quelle che finiscono entro 30 giorni' );
$cf_csv = \AssociazioneSemplice\Conventions::csv();
free_ok( false !== strpos( $cf_csv, 'Palestra Forma' ) && false !== strpos( $cf_csv, 'Bar Scaduto' ) && false !== strpos( $cf_csv, 'FORMA-SOCI-10' ), 'convenzioni: il CSV comprende tutte le convenzioni' );
\AssociazioneSemplice\Conventions::save_categories( "Sport\nSalute" );
free_ok( in_array( 'Salute', \AssociazioneSemplice\Conventions::categories(), true ) && in_array( 'Cultura', \AssociazioneSemplice\Conventions::categories(), true ), 'convenzioni: le categorie salvate si uniscono a quelle in uso' );
$cf_page = free_render( array( \AssociazioneSemplice\Admin\ConventionsPage::class, 'render' ) );
free_ok( false !== strpos( $cf_page, 'Palestra Forma' ) && false !== strpos( $cf_page, 'Bar Scaduto' ) && false !== strpos( $cf_page, 'Cinema Archiviato' ), 'convenzioni: la pagina di amministrazione elenca anche scadute e archiviate' );
free_ok( false !== strpos( free_render( array( \AssociazioneSemplice\Admin\ConventionsPage::class, 'render' ), array( 'view' => $cf_vols ) ), 'Libreria Solo Volontari' ), 'convenzioni: la scheda si apre' );
free_ok( isset( \AssociazioneSemplice\Admin\Admin::groups()['asem-book'][1]['asem-conventions'] ) && has_action( 'admin_post_asem_convention_save' ), 'convenzioni: menu e azioni registrati' );
\AssociazioneSemplice\Conventions::delete( $cf_old );
free_ok( null === \AssociazioneSemplice\Conventions::get( $cf_old ), 'convenzioni: eliminata' );

// ---------- Blocco D: nel gratuito non c'è nulla di assemblee, preventivo e rimborsi ----------
free_ok( ! Edition::has( 'assemblies' ) && ! Edition::has( 'budget' ) && ! Edition::has( 'refunds' ), 'senza il Pro non ci sono assemblee, preventivo né rimborsi' );
free_ok( ! class_exists( 'AssociazioneSemplice\Assemblies', false ) && ! class_exists( 'AssociazioneSemplice\Budget', false ) && ! class_exists( 'AssociazioneSemplice\Refunds', false ), 'senza il Pro le classi di assemblee, preventivo e rimborsi non ci sono' );
free_ok( ! isset( \AssociazioneSemplice\Frontend\Shortcodes::views()['assemblee'] ) && ! isset( \AssociazioneSemplice\Frontend\Shortcodes::views()['rimborsi'] ), 'senza il Pro gli shortcode «assemblee» e «rimborsi» non ci sono' );
free_ok( ! in_array( 'assemblee', \AssociazioneSemplice\Areas::groups()['soci'][1], true ) && ! isset( \AssociazioneSemplice\Admin\Admin::groups()['asem-book'][1]['asem-assemblies'] ), 'senza il Pro né l\'area soci né il menu mostrano le assemblee' );
free_ok( ! has_action( 'admin_post_asem_assembly_save' ) && ! has_action( 'admin_post_asem_front_proxy' ), 'senza il Pro le azioni delle assemblee non sono registrate' );

free_ok( array( 'none', 'woocommerce', 'stripe', 'paypal', 'stripe_paypal', 'sumup', 'stripe_sumup', 'paypal_sumup', 'stripe_paypal_sumup' ) === array_keys( \AssociazioneSemplice\PaymentConfig::providers() ) && array( 'stripe', 'paypal', 'sumup' ) === \AssociazioneSemplice\PaymentConfig::fee_gateways() && array() === \AssociazioneSemplice\PaymentConfig::gateways_of( 'satispay' ), 'pagamenti: senza le funzioni avanzate le scelte sono le nove di sempre, nessun Satispay' );
free_ok( ! $GLOBALS['asem_warnings'], 'nessun warning/notice PHP dal plugin' .( $GLOBALS['asem_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['asem_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Edizione gratuita: tutti i controlli sono passati.' );

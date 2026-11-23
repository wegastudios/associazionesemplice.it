<?php
/**
 * Prove dell'avviso «da invitare ad associarsi» al check-in di un ospite non socio (motivi, testi, Bacheca, «Fatto», registro azioni, nessuna email)
 * e dell'elenco «ospiti da invitare» limitato a chi ha prenotazioni in arrivo. Si carica da smoke.php.
 */

use AssociazioneSemplice\Admin;
use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\GuestInvite;
use AssociazioneSemplice\GuestInviteRules;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;

wp_set_current_user( 1 );

$gi_acts  = Plugin::activities();
$gi_peo   = Plugin::people();
$gi_today = current_time( 'Y-m-d' );
$gi_sy    = Settings::social_year()->label();
Settings::update( array( 'guest_max_events' => 2 ) );
delete_option( GuestInvite::OPTION );

/** Un evento con una data di oggi. @return int[] attività, data */
$gi_event = function ( string $name ) use ( $gi_acts, $gi_sy, $gi_today ): array {
	$aid = (int) $gi_acts->create( array( 'name' => $name, 'social_year' => $gi_sy, 'kind' => 'event', 'fee_cents' => 0, 'session' => array( 'session_date' => $gi_today, 'capacity' => 20 ) ) );
	return array( $aid, (int) $gi_acts->sessions( $aid )[0]['id'] );
};

$gi_host = $gi_peo->create( array( 'type' => 'ordinary', 'first_name' => 'Marco', 'last_name' => 'Ospitante', 'email' => 'marco.ospitante@example.com' ) );
list( $gi_a1, $gi_s1 ) = $gi_event( 'Serata invito uno' );
list( $gi_a2, $gi_s2 ) = $gi_event( 'Serata invito due' );

// Un socio che entra: nessun avviso.
$gi_member = $gi_peo->create( array( 'type' => 'ordinary', 'first_name' => 'Sonia', 'last_name' => 'Socia', 'email' => 'sonia.socia@example.com' ) );
$gi_acts->book( $gi_s1, $gi_member );
$gi_r = $gi_acts->check_in( $gi_s1, $gi_member );
asem_ok( 'recorded' === $gi_r['status'] && '' === $gi_r['invite'] && array() === GuestInvite::all(), 'invito ospiti: l\'ingresso di un socio non genera nessun avviso' );

// Un ospite invitato da un socio, alla prima volta: due motivi (invito e evento), nell'ordine, in un solo avviso.
$gi_g = $gi_peo->create( array( 'type' => 'guest', 'first_name' => 'Olga', 'last_name' => 'Invitata', 'phone' => '333 7770001', 'host_person_id' => $gi_host ) );
$gi_acts->book( $gi_s1, $gi_g );
$gi_r = $gi_acts->check_in( $gi_s1, $gi_g );
$gi_t = (string) $gi_r['invite'];
asem_ok( 'recorded' === $gi_r['status'] && '' !== $gi_t, 'invito ospiti: l\'ingresso di un ospite non socio dà un avviso' );
asem_ok( false !== strpos( $gi_t, 'Olga Invitata è qui su invito di Marco Ospitante: puoi proporre l\'adesione.' ) && false !== strpos( $gi_t, 'Olga Invitata è qui per «Serata invito uno»: puoi proporre l\'adesione.' ) && false === strpos( $gi_t, 'ha già' ), 'invito ospiti: i motivi (invito di un terzo, evento) sono nel testo, senza la soglia che non c\'è' );
asem_ok( strpos( $gi_t, 'su invito' ) < strpos( $gi_t, 'è qui per' ), 'invito ospiti: i motivi stanno in fila nell\'ordine stabilito' );
asem_ok( 1 === count( GuestInvite::all() ) && 1 === count( GuestInvite::pending() ), 'invito ospiti: un solo avviso, anche con più motivi' );
asem_ok( in_array( 'guest.invite_notice', array_column( Audit::recent( 400 ), 'action' ), true ), 'invito ospiti: riga nel registro azioni' );

// Lo stesso ingresso ripetuto o annullato e rifatto non crea un secondo avviso.
$gi_again = $gi_acts->check_in( $gi_s1, $gi_g );
asem_ok( 'already' === $gi_again['status'] && empty( $gi_again['invite'] ), 'invito ospiti: un ingresso già registrato non rifà l\'avviso' );
$gi_acts->check_in( $gi_s1, $gi_g, true );
$gi_acts->check_in( $gi_s1, $gi_g );
asem_ok( 1 === count( GuestInvite::all() ), 'invito ospiti: annullare e rifare l\'ingresso non duplica la voce' );

// Alla seconda partecipazione la soglia (2) è raggiunta: tre motivi, con la soglia per prima.
$gi_acts->book( $gi_s2, $gi_g );
$gi_t2 = (string) $gi_acts->check_in( $gi_s2, $gi_g )['invite'];
asem_ok( false !== strpos( $gi_t2, 'Olga Invitata è da invitare ad associarsi: ha già 2 ingressi.' ) && 0 === strpos( $gi_t2, 'Olga Invitata è da invitare' ) && false !== strpos( $gi_t2, 'Serata invito due' ), 'invito ospiti: raggiunta la soglia, il motivo compare per primo, con il numero degli ingressi' );
asem_ok( 2 === count( GuestInvite::pending() ), 'invito ospiti: una voce per ogni ingresso' );

// Un ospite senza chi lo ha invitato: resta il solo motivo dell'evento.
$gi_g2 = $gi_peo->create( array( 'type' => 'guest', 'first_name' => 'Pino', 'last_name' => 'Solo', 'phone' => '333 7770002' ) );
$gi_acts->book( $gi_s1, $gi_g2 );
$gi_t3 = (string) $gi_acts->check_in( $gi_s1, $gi_g2 )['invite'];
asem_ok( 'Pino Solo è qui per «Serata invito uno»: puoi proporre l\'adesione.' === $gi_t3, 'invito ospiti: senza invito e sotto la soglia resta il motivo dell\'evento, anche gratuito' );

// Nessuna email: l'avviso è solo per chi gestisce.
$gi_mails = 0;
$gi_cb    = function ( $pre ) use ( &$gi_mails ) {
	$gi_mails++;
	return $pre;
};
add_filter( 'pre_wp_mail', $gi_cb );
list( $gi_a3, $gi_s3 ) = $gi_event( 'Serata invito tre' );
$gi_g3 = $gi_peo->create( array( 'type' => 'guest', 'first_name' => 'Gina', 'last_name' => 'Senzamail', 'phone' => '333 7770003', 'host_person_id' => $gi_host ) );
$gi_acts->book( $gi_s3, $gi_g3 );
$gi_mails = 0;
$gi_acts->check_in( $gi_s3, $gi_g3 );
remove_filter( 'pre_wp_mail', $gi_cb );
asem_ok( 0 === $gi_mails, 'invito ospiti: al check-in non parte nessuna email' );

// Il messaggio dell'azione porta l'avviso in coda e le pagine lo mettono in evidenza.
$gi_adm = new ReflectionMethod( Admin\Actions::class, 'checkin' );
list( $gi_a4, $gi_s4 ) = $gi_event( 'Serata invito quattro' );
$gi_g4 = $gi_peo->create( array( 'type' => 'guest', 'first_name' => 'Rita', 'last_name' => 'Evidenza', 'phone' => '333 7770004', 'host_person_id' => $gi_host ) );
$gi_acts->book( $gi_s4, $gi_g4 );
$gi_res = $gi_adm->invoke( null, array( 'activity_id' => $gi_a4, 'session_id' => $gi_s4, 'person_id' => $gi_g4 ) );
list( $gi_done, $gi_inv ) = GuestInviteRules::split( $gi_res[1] );
asem_ok( 'Ingresso registrato.' === $gi_done && false !== strpos( $gi_inv, 'Rita Evidenza è qui su invito di Marco Ospitante' ), 'invito ospiti: l\'azione di amministrazione dà l\'esito e l\'avviso separati' );
$gi_get = $_GET;
parse_str( (string) wp_parse_url( \AssociazioneSemplice\Flash::url( 'http://example.org/', 'asem', $gi_res[1] ), PHP_URL_QUERY ), $_GET );
ob_start();
Admin\Ui::notices();
$gi_notice = (string) ob_get_clean();
$_GET = $gi_get;
asem_ok( false !== strpos( $gi_notice, 'notice-success' ) && false !== strpos( $gi_notice, 'notice-warning asem-invite' ) && false !== strpos( $gi_notice, 'Rita Evidenza è qui su invito di Marco Ospitante' ), 'invito ospiti: nella pagina di amministrazione l\'avviso è un riquadro in evidenza' );
$gi_front = \AssociazioneSemplice\Frontend\Actions::do_checkin( array( 'session_id' => $gi_s3, 'person_id' => $gi_g3, 'undo' => '1' ) );
$gi_front = \AssociazioneSemplice\Frontend\Actions::do_checkin( array( 'session_id' => $gi_s3, 'person_id' => $gi_g3 ) );
asem_ok( false !== strpos( $gi_front, 'Ingresso registrato' ) && false !== strpos( $gi_front, GuestInviteRules::SEP ) && false !== strpos( $gi_front, 'Gina Senzamail è qui su invito di Marco Ospitante' ), 'invito ospiti: anche dall\'area soci l\'avviso segue il messaggio dell\'ingresso' );

// Bacheca: la voce con il link alla scheda e «Fatto».
$gi_dash = asem_render( array( Admin\DashboardPage::class, 'render' ), 'Da invitare ad associarsi: ' );
asem_ok( false !== strpos( $gi_dash, 'asem_invite_done' ) && false !== strpos( $gi_dash, 'Fatto' ) && false !== strpos( $gi_dash, 'Olga Invitata' ) && false !== strpos( $gi_dash, 'su invito di Marco Ospitante' ) && false !== strpos( $gi_dash, 'asem-person' ), 'invito ospiti: la Bacheca elenca chi è da invitare, con la scheda, i motivi in breve e il pulsante Fatto' );

// «Fatto»: la voce sparisce e resta nel registro.
$gi_before = count( GuestInvite::pending() );
$gi_first  = GuestInvite::pending()[0];
$gi_msg    = new ReflectionMethod( Admin\Actions::class, 'invite_done' );
$gi_ret    = $gi_msg->invoke( null, array( 'id' => $gi_first['id'] ) );
asem_ok( $gi_before - 1 === count( GuestInvite::pending() ) && false === strpos( wp_json_encode( array_keys( GuestInvite::all() ) ), $gi_first['id'] ) && 'Segnato come fatto.' === $gi_ret[1], 'invito ospiti: «Fatto» toglie la voce dalla Bacheca' );
asem_ok( in_array( 'guest.invite_done', array_column( Audit::recent( 400 ), 'action' ), true ), 'invito ospiti: «Fatto» si legge nel registro azioni' );
asem_ok( false === GuestInvite::done( 'abcdefabcdef' ), 'invito ospiti: un codice che non esiste non fa nulla' );
asem_ok( has_action( 'admin_post_asem_invite_done' ), 'invito ospiti: l\'azione «Fatto» è registrata' );
asem_ok( null !== \AssociazioneSemplice\EmployeeRules::action_need( 'asem_invite_done' ), 'invito ospiti: l\'azione «Fatto» è dell\'area persone' );

// Diventare socio chiude l'avviso.
$gi_p = (int) $gi_g4;
$gi_peo->promote_guest( $gi_p, array( 'email' => 'rita.evidenza@example.com', 'type' => 'ordinary' ) );
asem_ok( array() === array_filter( GuestInvite::all(), function ( $it ) use ( $gi_p ) { return (int) $it['person_id'] === $gi_p; } ), 'invito ospiti: iscritto come socio, l\'avviso sparisce' );

// ---------- «Da invitare»: solo chi ha prenotazioni in arrivo ----------
Settings::update( array( 'guest_max_events' => 1 ) );
$gi_yest = gmdate( 'Y-m-d', strtotime( $gi_today . ' -1 day' ) );
$gi_tom  = gmdate( 'Y-m-d', strtotime( $gi_today . ' +1 day' ) );
$gi_mk   = function ( string $first, string $phone ) use ( $gi_peo, $gi_host ) {
	return (int) $gi_peo->create( array( 'type' => 'guest', 'first_name' => $first, 'last_name' => 'Futuro', 'phone' => $phone, 'host_person_id' => $gi_host ) );
};
$gi_fut   = $gi_mk( 'Fabrizio', '333 7771001' );
$gi_past  = $gi_mk( 'Pasquale', '333 7771002' );
$gi_multi = $gi_mk( 'Marilena', '333 7771003' );
$gi_canc  = $gi_mk( 'Cesare', '333 7771004' );
$gi_bcanc = $gi_mk( 'Beatrice', '333 7771005' );
list( , $gi_sf ) = $gi_event( 'Prova futuro' );
list( , $gi_sp ) = $gi_event( 'Prova passato' );
list( , $gi_sm ) = $gi_event( 'Prova più giorni' );
list( , $gi_sc ) = $gi_event( 'Prova annullata' );
list( , $gi_sb ) = $gi_event( 'Prova prenotazione annullata' );
$gi_acts->book( $gi_sf, $gi_fut );
$gi_acts->book( $gi_sp, $gi_past );
$gi_acts->book( $gi_sm, $gi_multi );
$gi_acts->book( $gi_sc, $gi_canc );
$gi_acts->book( $gi_sb, $gi_bcanc );
// le date si spostano dopo le prenotazioni (nel passato non si prenota): una futura, una passata, una su più giorni ancora in corso, una annullata
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $gi_tom ), array( 'id' => $gi_sf ) );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $gi_yest ), array( 'id' => $gi_sp ) );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $gi_yest, 'end_date' => $gi_tom ), array( 'id' => $gi_sm ) );
$wpdb->update( Db::t( 'sessions' ), array( 'cancelled_at' => Db::now() ), array( 'id' => $gi_sc ) );
$gi_acts->cancel_booking( $gi_sb, $gi_bcanc );
$gi_all = array( $gi_fut, $gi_past, $gi_multi, $gi_canc, $gi_bcanc, 999999 );
$gi_with = $gi_acts->with_future_booking( $gi_all );
sort( $gi_with );
$gi_want = array( $gi_fut, $gi_multi );
sort( $gi_want );
asem_ok( $gi_want === $gi_with && array() === $gi_acts->with_future_booking( array() ), 'ospiti da invitare: ha una prenotazione in arrivo chi ha una data futura o un evento su più giorni ancora in corso, non chi ha solo date passate, annullate o prenotazioni annullate' );
$gi_ids = $gi_peo->guest_ids_to_invite();
asem_ok( in_array( $gi_fut, $gi_ids, true ) && in_array( $gi_multi, $gi_ids, true ) && ! in_array( $gi_past, $gi_ids, true ) && ! in_array( $gi_canc, $gi_ids, true ) && ! in_array( $gi_bcanc, $gi_ids, true ), 'ospiti da invitare: l\'elenco comprende chi ha una prenotazione in arrivo e non chi è venuto solo in passato' );
asem_ok( array_map( 'intval', array_column( $gi_peo->guests_to_invite(), 'id' ) ) === $gi_ids, 'ospiti da invitare: le schede e gli id sono gli stessi e nello stesso ordine' );
$gi_list = asem_render( array( Admin\PeoplePage::class, 'render_list' ), 'Futuro', array( 'type' => 'guest', 'at_limit' => '1' ) );
asem_ok( false !== strpos( $gi_list, 'Fabrizio' ) && false !== strpos( $gi_list, 'Marilena' ) && false === strpos( $gi_list, 'Pasquale' ) && false === strpos( $gi_list, 'Cesare' ), 'ospiti da invitare: il filtro dell\'elenco persone segue la stessa regola' );

Settings::update( array( 'guest_max_events' => 2 ) );
delete_option( GuestInvite::OPTION );

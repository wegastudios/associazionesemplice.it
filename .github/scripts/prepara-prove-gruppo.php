<?php
/**
 * Dati finti per le prove della prenotazione di gruppo dentro wp-env (solo persone e utenti di prova). Stampa GRUPPO{...}.
 */
use AssociazioneSemplice\MemberLogin;
use AssociazioneSemplice\MemberSession;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PublicEvents;
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 );
Settings::update( array( 'guests_enabled' => 1, 'limits' => array( 'member_book_per_10min' => 500, 'member_cancel_per_10min' => 100, 'member_profile_per_10min' => 100, 'member_other_fail_per_10min' => 100, 'ip_other_fail_per_hour' => 500 ) ) );
$page = function ( string $title, string $body ): string {
	return (string) get_permalink( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $body ) ) );
};
$sy  = Settings::social_year()->label();
$act = Plugin::activities();
$peo = Plugin::people();
$new = function ( string $first, string $last, string $card ) use ( $peo, $sy ): int {
	$id = (int) $peo->create( array( 'type' => 'ordinary', 'card_number' => $card, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first ) . '.prova@example.com', 'birth_date' => '1980-05-05', 'phone' => '333 100' . $card ) );
	$peo->set_membership( $id, $sy, true );
	$err = MemberLogin::set_password( $id, wp_generate_password( 24, true ) );
	if ( '' !== $err ) {
		WP_CLI::error( $err );
	}
	return $id;
};
$paola = $new( 'Paola', 'Prenota', '9201' );
$gino  = $new( 'Gino', 'Socio', '9202' );
$rina  = $new( 'Rina', 'Corre', '9203' );
$event = function ( string $name, ?int $cap ) use ( $act, $sy ): array {
	$aid = (int) $act->create( array( 'name' => $name, 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 500, 'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + 20 * 86400 ), 'start_time' => '18:30', 'capacity' => $cap ) ) );
	$pid = PublicEvents::ensure( $aid );
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
	return array( 'id' => $aid, 'sid' => (int) $act->sessions( $aid )[0]['id'], 'url' => PublicEvents::page_url( $aid ) );
};
$out = array( 'versione' => ASEM_VERSION, 'paola' => $paola, 'gino' => $gino, 'rina' => $rina, 'gare' => array() );
foreach ( array( 1440, 375 ) as $w ) {
	foreach ( array( 'senzajs', 'tastiera', 'conferma', 'paga' ) as $k ) {
		$out[ $k . $w ] = $event( 'Prova ' . $k . ' ' . $w, 30 );
	}
	$act->book_many( $out[ 'conferma' . $w ]['sid'], array( $gino ), $paola, array() );
	$act->book_many( $out[ 'paga' . $w ]['sid'], array( $gino ), $paola, array() );
}
for ( $i = 0; $i < 6; $i++ ) {
	$out['gare'][] = $event( 'Gara ' . $i, 2 ); // due posti: due soci prenotano insieme sé e un ospite nuovo
}
$out['mie']      = $page( 'Le mie attivita', '[associazionesemplice_mie_attivita]' );
$out['profilo']  = $page( 'Profilo', '[associazionesemplice_profilo]' );
$out['incasso']  = admin_url( 'admin.php?page=asem-income' );
$out['s_paola']  = MemberSession::start( $paola );
$out['s_gino']   = MemberSession::start( $gino );
$out['s_rina']   = MemberSession::start( $rina );
update_option( 'prove_gruppo', $out, false );
echo 'GRUPPO' . wp_json_encode( $out ) . "\n";

<?php
/**
 * Dati finti per le prove visive delle pagine di ruolo, dentro wp-env (solo utenti di prova locali):
 *   wp eval-file wp-content/sandbox-scripts/prepara-prove-1338.php
 * Stampa una riga PROVE1338{...} con gli indirizzi e il token di sessione di un socio di prova.
 */
use AssociazioneSemplice\Access;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberSession;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
Settings::update( array( 'guests_enabled' => 1 ) );
$page = function ( string $title, string $body ): string {
	return (string) get_permalink( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $body ) ) );
};
$sy  = Settings::social_year()->label();
$act = Plugin::activities();
$peo = Plugin::people();
$new = function ( string $first, string $last, string $card ) use ( $peo ): int {
	return (int) $peo->create( array( 'type' => 'ordinary', 'card_number' => $card, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first ) . '.prova@example.com', 'birth_date' => '1980-05-05' ) );
};
$uno = $new( 'Ada', 'Prova', '9101' );
$due = $new( 'Bruno', 'Prova', '9102' );
foreach ( array( $uno, $due ) as $id ) {
	$peo->set_membership( $id, $sy, true );
}
// Un evento con un solo partecipante
$aid = (int) $act->create( array( 'name' => 'Evento di prova', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 500, 'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + 20 * 86400 ), 'start_time' => '18:30', 'capacity' => 30 ) ) );
$act->book( (int) $act->sessions( $aid )[0]['id'], $uno );
// Segreteria e tesoriere: utenti WordPress di prova
wp_insert_user( array( 'user_login' => 'prova_segreteria', 'user_pass' => 'password', 'user_email' => 'segreteria.prova@example.com', 'role' => Plugin::ROLE_SECRETARY ) );
$tes = wp_insert_user( array( 'user_login' => 'prova_tesoriere', 'user_pass' => 'password', 'user_email' => 'tesoriere.prova@example.com', 'role' => Plugin::ROLE_MEMBER ) );
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $tes ), array( 'id' => $due ) );
Access::set_treasurer( (int) $tes, true );
wp_cache_flush();
$pid = \AssociazioneSemplice\PublicEvents::ensure( $aid );
wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
$err = \AssociazioneSemplice\MemberLogin::set_password( $uno, wp_generate_password( 24, true ) ); // senza password scelta il socio non vede l'area soci
if ( '' !== $err ) {
	WP_CLI::error( $err );
}
echo 'PROVE1338' . wp_json_encode(
	array(
		'segreteria'     => $page( 'Area segreteria', '[associazionesemplice_segreteria]' ),
		'tesoriere'      => $page( 'Area tesoriere', '[associazionesemplice_tesoriere]' ),
		'attivita'       => $page( 'Attivita di prova', '[associazionesemplice_attivita id="' . $aid . '"]' ),
		'persona'        => admin_url( 'admin.php?page=asem-person&id=' . $uno ),
		'attivita_admin' => admin_url( 'admin.php?page=asem-activities' ),
		'pubblica'       => \AssociazioneSemplice\PublicEvents::page_url( $aid ),
		'sessione'       => MemberSession::enabled() ? MemberSession::start( $uno ) : '',
		'versione'       => ASEM_VERSION,
	)
) . "\n";

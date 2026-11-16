<?php
/**
 * Prima dell'aggiornamento (free + Pro vecchi installati): crea soci, risorse con prenotazioni, crediti, un pagamento in prima nota e un
 * documento cifrato; salva l'impronta dei dati e della chiave dei file.
 *   wp eval-file wp-content/prove/prova-pro-crea.php
 */

use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\FileCrypt;
use AssociazioneSemplice\PrivateFiles;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Resources;
use AssociazioneSemplice\Settings;

require_once __DIR__ . '/prova-pro-comune.php';

dati_ok( defined( 'ASEM_PRO_VERSION' ) && class_exists( 'AssociazioneSemplice\\Resources' ), 'Pro attivo (versione ' . ( defined( 'ASEM_PRO_VERSION' ) ? ASEM_PRO_VERSION : '?' ) . ') accanto al gratuito ' . ASEM_VERSION );
dati_ok( FileCrypt::available(), 'la cifratura dei file è disponibile (sodium)' );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$people = Plugin::people();
$ledger = Plugin::ledger();
$cash   = $ledger->accounts()[0];
$today  = current_time( 'Y-m-d' );

$m1 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Rita', 'last_name' => 'Risorsa', 'email' => 'rita.prova@example.com' ) );
$m2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Remo', 'last_name' => 'Risorsa', 'email' => 'remo.prova@example.com' ) );
$people->set_membership( $m1, Settings::membership_year()->label(), true );
$people->set_membership( $m2, Settings::membership_year()->label(), true );

// Risorse con prenotazioni e un pagamento in prima nota
$court = Resources::save( 0, array( 'name' => 'Campo 1', 'category' => 'court', 'mode' => 'slot', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'guests_max' => 1, 'guest_fee_cents' => 200, 'max_per_person' => 1, 'advance_days' => 30 ) );
$park  = Resources::save( 0, array( 'name' => 'Posto auto 7', 'category' => 'parking', 'mode' => 'assign', 'fee_cents' => 12000 ) );
$day   = gmdate( 'Y-m-d', strtotime( $today . ' +3 days' ) );
$b1    = Resources::book_slot( $court, $m1, $day, '18:00', 1, array( 'Ospite Uno' ) );
$b2    = Resources::book_slot( $court, $m2, $day, '19:00', 1 );
Resources::assign( $park, $m1, $today, '' );
Resources::record_payment( $b1, (int) $cash['id'], 'cash', 500, $today );
dati_ok( $court > 0 && $park > 0 && $b1 > 0 && $b2 > 0, 'due risorse, due prenotazioni, un\'assegnazione e un pagamento di 5,00 € creati' );

// Crediti: pacchetto venduto in segreteria (entra in prima nota) e una rettifica
Credits::save_packs( "10 = 10,00\n50 = 45,00" );
Credits::sell_at_desk( $m2, 50, (int) $cash['id'], 'cash' );
Credits::adjust( $m2, -5, 'Rettifica di prova' );
dati_ok( 45 === Credits::balance( $m2 ), 'crediti: 50 venduti e 5 tolti, saldo 45' );

// Documento cifrato
$marker = 'MARCATORE-' . bin2hex( random_bytes( 4 ) );
$text   = "%PDF-1.4\n" . $marker . "\n" . str_repeat( 'x', 70000 );
$tmp    = wp_tempnam( 'asem-pro' );
file_put_contents( $tmp, $text );
$doc = Documents::save( 0, array( 'title' => 'Documento cifrato di prova', 'visibility' => 'staff' ), array( 'name' => 'prova.pdf', 'tmp_name' => $tmp, 'size' => (int) filesize( $tmp ), 'mime' => 'application/pdf' ), 1 );
$row = Documents::get( $doc );
dati_ok( 1 === (int) $row['enc_ver'] && hash( 'sha256', $text ) === $row['file_sha256'], 'documento salvato cifrato (enc_ver 1, impronta del contenuto in chiaro)' );

$imp   = pro_impronta();
$chiav = pro_chiave();
dati_ok( 'ok' === $chiav['stato'] && $chiav['id_chiave'] > 0, 'la chiave dei file è in stato ok' );
dati_ok( $imp['risorse'] >= 2 && $imp['prenotazioni'] >= 2 && $imp['crediti_voci'] >= 2 && $imp['movimenti'] >= 2, 'impronta prima dell\'aggiornamento: ' . wp_json_encode( $imp ) );
update_option(
	'asem_prova_pro',
	array(
		'impronta'   => $imp,
		'chiave'     => $chiav,
		'doc'        => $doc,
		'marker'     => $marker,
		'sha'        => $row['file_sha256'],
		'saldo'      => Credits::balance( $m2 ),
		'socio'      => $m2,
		'free'       => ASEM_VERSION,
		'pro'        => ASEM_PRO_VERSION,
		'db'         => (string) get_option( 'asem_db_version', '' ),
		'pro_db'     => (string) get_option( 'asem_pro_db_version', '' ),
	),
	false
);
WP_CLI::success( 'Dati creati con free ' . ASEM_VERSION . ' + Pro ' . ASEM_PRO_VERSION . ' (DB ' . get_option( 'asem_db_version' ) . ', schema Pro ' . get_option( 'asem_pro_db_version' ) . ').' );

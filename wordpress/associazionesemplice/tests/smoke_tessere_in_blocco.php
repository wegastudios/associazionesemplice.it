<?php
/**
 * Tessere in PDF per molti soci: i dati si leggono in blocco (le query non crescono con il numero dei soci), il QR è un'immagine a un bit, le
 * pagine sono compresse, al più 500 tessere per file. Sta in un file a parte, caricato alla fine di smoke.php, perché crea 50 persone e non deve
 * spostare gli identificativi su cui contano le prove più indietro.
 */

use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
$cd_qr_before = (int) Settings::get( 'card_qr_enabled' );
Settings::update( array( 'card_qr_enabled' => 1 ) );
$cd_50 = array();
for ( $i = 0; $i < 50; $i++ ) {
	$cd_id = Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Massa' . $i, 'last_name' => 'Tessera' . $i ) );
	Plugin::people()->set_membership( $cd_id, Settings::membership_year()->label(), true );
	$cd_50[] = Plugin::people()->get( $cd_id );
}
$cd_q     = $wpdb->num_queries;
$cd_pdf10 = \AssociazioneSemplice\CardLayout::pdf_sheets( array_slice( $cd_50, 0, 10 ) );
$cd_n10   = $wpdb->num_queries - $cd_q;
$cd_q     = $wpdb->num_queries;
$cd_pdf50 = \AssociazioneSemplice\CardLayout::pdf_sheets( $cd_50 );
$cd_n50   = $wpdb->num_queries - $cd_q;
asem_ok( $cd_n50 <= 20 && $cd_n50 <= $cd_n10 + 2, 'tessere PDF: 50 tessere fanno ' . $cd_n50 . ' query (10 ne fanno ' . $cd_n10 . '): non una serie per ogni socio' );
asem_ok( false !== strpos( $cd_pdf50, '/Filter /FlateDecode' ) && false !== strpos( $cd_pdf50, '/BitsPerComponent 1' ) && 0 === substr_count( $cd_pdf50, ' re f' ), 'tessere PDF: pagine compresse e QR come immagine a un bit, non come rettangoli' );
$cd_data = \AssociazioneSemplice\CardLayout::card_data( array( $cd_50[0] ) );
$cd_u    = Plugin::people()->active_until( (int) $cd_50[0]['id'] );
$cd_old  = array(
	'valid' => $cd_u ? 'Valida fino al ' . ( new \DateTimeImmutable( $cd_u ) )->format( 'd/m/Y' ) : '',
	'type'  => \AssociazioneSemplice\Levels::label( $cd_50[0] ),
	'roles' => \AssociazioneSemplice\CardLayout::roles_text( $cd_50[0] ),
);
asem_ok( $cd_data[ (int) $cd_50[0]['id'] ]['valid'] === $cd_old['valid'] && $cd_data[ (int) $cd_50[0]['id'] ]['type'] === $cd_old['type'] && $cd_data[ (int) $cd_50[0]['id'] ]['roles'] === $cd_old['roles'], 'tessere PDF: scadenza, tipo e incarichi letti in blocco sono quelli di prima' );
$cd_u0 = \AssociazioneSemplice\QrCode::matrix( Settings::card_url( (int) $cd_50[0]['id'] ) );
asem_ok( count( $cd_u0 ) >= 21 && $cd_u0 === \AssociazioneSemplice\QrCode::matrix( Settings::card_url( (int) $cd_50[0]['id'] ) ), 'tessere PDF: il QR ha lo stesso contenuto di sempre (matrice dell\'indirizzo della tessera)' );
asem_ok( 1 === \AssociazioneSemplice\Docs::card_parts( 500 ) && 2 === \AssociazioneSemplice\Docs::card_parts( 501 ) && 5 === \AssociazioneSemplice\Docs::card_parts( 2100 ), 'tessere PDF: al più 500 tessere per file' );
$cd_all = \AssociazioneSemplice\Docs::card_ids( '' );
$cd_np  = \AssociazioneSemplice\Docs::card_parts( count( $cd_all ) );
$cd_doc = \AssociazioneSemplice\Docs::cards( '', 6, 1 );
asem_ok( count( $cd_all ) >= 50 && ( 1 === $cd_np ? 'tessere.pdf' : 'tessere-parte-1-di-' . $cd_np . '.pdf' ) === $cd_doc['filename'] && 0 === strpos( $cd_doc['body'], '%PDF-1.4' ), 'tessere PDF: ' . count( $cd_all ) . ' soci fanno ' . $cd_np . ' file; la parte 1 è un PDF con il nome giusto' );
Settings::update( array( 'card_qr_enabled' => $cd_qr_before ) );

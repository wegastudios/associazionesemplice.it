<?php
/**
 * Prova di lettura del QR della tessera (workflow «Prove in sandbox»), dentro wp-env:
 *   wp eval-file wp-content/sandbox-scripts/prova-qr-tessera.php
 * Genera con il codice vero del plugin il PDF di una tessera, un foglio di più tessere e il QR in SVG di quella digitale, e li stampa in base64
 * con un prefisso per riga (EXP = indirizzo atteso nel QR, PDF1, PDFS, SVG); la lettura con pdftoppm e zbarimg la fa il passo del workflow.
 */
use AssociazioneSemplice\CardLayout;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\QrCode;
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 );
Settings::update( array( 'license_key' => 'SANDBOX-0001', 'card_qr_enabled' => 1 ) );

$db   = Db::db();
$in   = "'" . implode( "','", array_map( 'esc_sql', MemberType::member_types() ) ) . "'";
$rows = $db->get_results( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE type IN (' . $in . ') ORDER BY id LIMIT 6', ARRAY_A ); // phpcs:ignore
if ( ! $rows ) {
	WP_CLI::error( 'Nessun socio nei dati di esempio: niente tessera da provare.' );
}

$first = $rows[0];
WP_CLI::line( 'EXP1 ' . Settings::card_url( (int) $first['id'] ) );
WP_CLI::line( 'PDF1 ' . base64_encode( CardLayout::pdf( $first ) ) );
foreach ( $rows as $p ) {
	WP_CLI::line( 'EXPS ' . Settings::card_url( (int) $p['id'] ) );
}
WP_CLI::line( 'PDFS ' . base64_encode( CardLayout::pdf_sheets( $rows ) ) );
WP_CLI::line( 'SVG ' . base64_encode( QrCode::svg( Settings::card_url( (int) $first['id'] ) ) ) );

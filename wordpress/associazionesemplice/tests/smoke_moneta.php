<?php
/**
 * Moneta interna con il nome scelto dall'associazione (di partenza AssoCredito / AssoCrediti): impostazione, controlli e uso nei testi.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\CoinName;
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 );
$mo_keep = array( 'coin_singular' => (string) Settings::get( 'coin_singular' ), 'coin_plural' => (string) Settings::get( 'coin_plural' ) );
Settings::update( array( 'coin_singular' => '', 'coin_plural' => '' ) );
asem_ok( 'AssoCredito' === CoinName::singular() && 'AssoCrediti' === CoinName::plural() && '1 AssoCredito' === CoinName::n( 1 ) && '20 AssoCrediti' === CoinName::n( 20 ), 'moneta: senza nome è AssoCredito / AssoCrediti' );
Settings::update( array( 'coin_singular' => '  Stella ', 'coin_plural' => 'Stelle' ) );
asem_ok( 'Stella' === CoinName::singular() && '3 Stelle' === CoinName::n( 3 ), 'moneta: il nome scelto, pulito' );
Settings::update( array( 'coin_singular' => 'Gettone', 'coin_plural' => '' ) );
asem_ok( 'Gettone' === CoinName::plural(), 'moneta: con un solo nome vale per entrambi' );
Settings::update( array( 'coin_singular' => 'Credito "Lucà"', 'coin_plural' => 'Crediti "Lucà"' ) );
asem_ok( '2 Crediti "Lucà"' === CoinName::n( 2 ) && false !== strpos( esc_attr( CoinName::plural() ), '&quot;' ), 'moneta: un nome commemorativo con virgolette e accento si accetta e si scrive bene negli attributi' );
Settings::update( array( 'coin_singular' => 'Gettone', 'coin_plural' => '' ) );
$mo_bad = 0;
foreach ( array( str_repeat( 'a', 25 ), '123', '10 €', 'Punti <b>', '{moneta}' ) as $mo_n ) {
	$mo_bad += '' !== (string) asem_throws( function () use ( $mo_n ) { Settings::update( array( 'coin_singular' => $mo_n ) ); } ) ? 1 : 0;
}
asem_ok( 5 === $mo_bad && 'Gettone' === CoinName::singular(), 'moneta: nomi troppo lunghi, senza lettere, con € o caratteri speciali si rifiutano e non cambiano nulla (' . $mo_bad . ' su 5)' );
asem_ok( '' !== CoinName::warning( 'Euro sociali' ) && '' !== CoinName::warning( 'Dollari' ) && '' === CoinName::warning( 'Stelle' ), 'moneta: avviso se il nome somiglia a una valuta vera' );
// i testi usano il nome scelto, i dati restano dove sono
if ( class_exists( '\AssociazioneSemplice\Admin\CreditsActions' ) ) {
	$mo_msg = \AssociazioneSemplice\Admin\CreditsActions::name( array( 'coin_singular' => 'Euro', 'coin_plural' => 'Euri' ) );
	asem_ok( false !== strpos( $mo_msg, 'valuta vera' ) && 'Euri' === CoinName::plural(), 'moneta: salvato dal pannello, con l\'avviso' );
	\AssociazioneSemplice\Admin\CreditsActions::name( array( 'coin_singular' => 'Stella', 'coin_plural' => 'Stelle' ) );
	asem_render( array( \AssociazioneSemplice\Admin\CreditsPage::class, 'render' ), 'Stelle' );
	asem_render( array( \AssociazioneSemplice\Admin\CreditsPage::class, 'render' ), 'Nome della moneta' );
	$mo_items = \AssociazioneSemplice\Credits::pack_items( array( 'id' => 1, 'type' => 'ordinary', 'first_name' => 'A', 'last_name' => 'B' ) );
	asem_ok( ! $mo_items || false !== strpos( (string) reset( $mo_items )['label'], 'Stell' ), 'moneta: i pacchetti in vendita online portano il nome' );
}
asem_ok( false !== strpos( \AssociazioneSemplice\Admin\Ui::pay_status( array( 'balance' => 1200, 'upcoming' => null ) ), 'a favore' ), 'moneta: l\'importo in euro in più si chiama «a favore», non «credito»' );
Settings::update( $mo_keep );

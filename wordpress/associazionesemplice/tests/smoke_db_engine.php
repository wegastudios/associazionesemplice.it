<?php
/**
 * Prove del motore delle tabelle (InnoDB o no) dentro un WordPress reale: il controllo trova una tabella MyISAM messa lì apposta,
 * l'avviso della Bacheca la dice, la conversione a tappe la porta a InnoDB senza perdere righe e un secondo giro non fa nulla.
 * Si carica da smoke.php.
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\DbEngine;

wp_set_current_user( 1 );
$de_t = Db::t( 'zz_engine_probe' );
$wpdb->query( "DROP TABLE IF EXISTS $de_t" );
$wpdb->query( "CREATE TABLE $de_t (id int NOT NULL AUTO_INCREMENT, v varchar(20) NOT NULL, PRIMARY KEY  (id)) ENGINE=MyISAM" );
$wpdb->query( "INSERT INTO $de_t (v) VALUES ('a'), ('b'), ('c')" );

$de_rows = array();
foreach ( DbEngine::tables() as $de_r ) {
	$de_rows[ $de_r['name'] ] = $de_r['engine'];
}
asem_ok( isset( $de_rows[ Db::t( 'people' ) ] ) && 0 === strcasecmp( 'InnoDB', (string) $de_rows[ Db::t( 'people' ) ] ), 'motore: le tabelle del plugin sono InnoDB (e sono tutte elencate)' );
asem_ok( isset( $de_rows[ $de_t ] ) && 0 === strcasecmp( 'MyISAM', (string) $de_rows[ $de_t ] ), 'motore: la tabella di prova risulta MyISAM' );
asem_ok( DbEngine::innodb_available(), 'motore: il server offre InnoDB' );

$de_chk = DbEngine::check();
asem_ok( array( $de_t ) === $de_chk['bad'] && DbEngine::last()['bad'] === $de_chk['bad'] && $de_chk['total'] === count( $de_rows ), 'motore: il controllo trova solo la tabella MyISAM e ne ricorda l\'esito' );
asem_ok( false !== strpos( DbEngine::alerts_html(), 'asem-db-engine' ), 'motore: la Bacheca avvisa dell\'unica tabella senza transazioni, con il link al pulsante' );
wp_set_current_user( 0 );
asem_ok( '' === DbEngine::alerts_html(), 'motore: l\'avviso non è per chi non è amministratore' );
wp_set_current_user( 1 );

$de_res = DbEngine::convert_batch();
asem_ok( array( $de_t ) === $de_res['done'] && array() === $de_res['failed'] && 0 === $de_res['left'] && ! $de_res['busy'], 'motore: la conversione porta a InnoDB la tabella MyISAM' );
asem_ok( 'InnoDB' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $de_t ) ) && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $de_t" ), 'motore: dopo la conversione la tabella è InnoDB e le righe ci sono tutte' );
asem_ok( array() === DbEngine::last()['bad'] && '' === DbEngine::alerts_html(), 'motore: finita la conversione l\'avviso sparisce' );
$de_again = DbEngine::convert_batch();
asem_ok( array() === $de_again['done'] && array() === $de_again['failed'] && 0 === $de_again['left'], 'motore: un secondo giro non cambia nulla' );

// A tappe: con un massimo di una tabella per giro, due tabelle MyISAM si convertono in due giri, la più piccola per prima.
$de_t2 = Db::t( 'zz_engine_probe2' );
$wpdb->query( "DROP TABLE IF EXISTS $de_t2" );
$wpdb->query( "CREATE TABLE $de_t2 (id int NOT NULL AUTO_INCREMENT, v varchar(20) NOT NULL, PRIMARY KEY  (id)) ENGINE=MyISAM" );
$wpdb->query( "ALTER TABLE $de_t ENGINE=MyISAM" );
for ( $de_i = 0; $de_i < 200; $de_i++ ) {
	$wpdb->query( "INSERT INTO $de_t (v) VALUES ('riga $de_i')" );
}
$de_s1 = DbEngine::convert_batch( 1 );
asem_ok( array( $de_t2 ) === $de_s1['done'] && 1 === $de_s1['left'], 'motore: a tappe, il primo giro converte la più piccola e ne resta una' );
$de_s2 = DbEngine::convert_batch( 1 );
asem_ok( array( $de_t ) === $de_s2['done'] && 0 === $de_s2['left'], 'motore: il secondo giro converte l\'altra e non resta nulla' );

// Un altro lavoro già in corso: la conversione non parte e lo dice.
Db::$lock_fake = '0';
$wpdb->query( "ALTER TABLE $de_t2 ENGINE=MyISAM" );
$de_busy = DbEngine::convert_batch();
Db::$lock_fake = 'x';
asem_ok( $de_busy['busy'] && array() === $de_busy['done'] && 'MyISAM' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $de_t2 ) ), 'motore: con un\'altra conversione in corso non si tocca nulla' );

// Il riquadro chiede prima la copia di sicurezza: avviso con il link e la spunta «Ho una copia recente».
$de_panel = asem_render( array( \AssociazioneSemplice\Admin\DbEnginePanel::class, 'render' ), 'Converti in InnoDB' );
asem_ok( false !== strpos( $de_panel, 'Prima di convertire scarica una copia di sicurezza: la conversione cambia le tabelle del database.' ) && false !== strpos( $de_panel, 'asem-backup' ) && false !== strpos( $de_panel, 'name="have_backup"' ) && false !== strpos( $de_panel, 'Ho una copia recente' ), 'motore: prima di convertire il riquadro chiede la copia di sicurezza, con il link e la spunta' );

// Senza la spunta la conversione non parte (controllo anche lato server, per una richiesta scritta a mano).
$de_no = (string) asem_throws( function () {
	\AssociazioneSemplice\Admin\TechActions::db_convert( array() );
} );
asem_ok( false !== strpos( $de_no, 'Ho una copia recente' ) && 'MyISAM' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $de_t2 ) ), 'motore: senza la spunta «Ho una copia recente» non si converte nulla' );
$de_no2 = (string) asem_throws( function () {
	\AssociazioneSemplice\Admin\TechActions::db_convert( array( 'have_backup' => '' ) );
} );
asem_ok( '' !== $de_no2 && 'MyISAM' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $de_t2 ) ), 'motore: anche con la spunta vuota non si converte nulla' );

// L'azione dell'amministratore dà un messaggio chiaro e finisce il lavoro.
$de_act = \AssociazioneSemplice\Admin\TechActions::db_convert( array( 'have_backup' => '1' ) );
asem_ok( false !== strpos( $de_act[0], 'asem-db-engine' ) && false !== strpos( $de_act[1], 'InnoDB' ), 'motore: l\'azione del pulsante riferisce l\'esito e torna al riquadro' );

$wpdb->query( "DROP TABLE IF EXISTS $de_t" );
$wpdb->query( "DROP TABLE IF EXISTS $de_t2" );
DbEngine::check();
asem_ok( array() === DbEngine::last()['bad'], 'motore: tolte le tabelle di prova, nessuna tabella è fuori dal motore giusto' );

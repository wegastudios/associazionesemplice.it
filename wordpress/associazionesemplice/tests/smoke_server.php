<?php
/**
 * Stato del server (richiamato da tests/smoke.php e da tests/smoke_free.php): la scheda legge davvero il server, le prove entrano nella
 * «Salute del sito» di WordPress, la pagina è solo per gli amministratori e sta negli Strumenti.
 */

defined( 'ABSPATH' ) || exit;
$ss_ok = function_exists( 'free_ok' ) ? 'free_ok' : 'asem_ok';

wp_set_current_user( 1 );
$ss_e = \AssociazioneSemplice\ServerStatus::collect( true );
$ss_ok( PHP_VERSION === $ss_e['php'] && is_int( $ss_e['memory'] ) && is_int( $ss_e['time'] ) && '' !== $ss_e['db_version'] && is_array( $ss_e['db_engine'] ) && $ss_e['db_engine']['total'] > 0, 'server: i valori vengono letti dal server vero (PHP, memoria, database, motore delle tabelle)' );
$ss_ok( $ss_e['packet'] > 0, 'server: la dimensione massima delle richieste al database si legge' );
$ss_ok( $ss_e['zip'] === class_exists( '\ZipArchive' ) && $ss_e['sodium'] === \AssociazioneSemplice\FileCrypt::available(), 'server: zip e sodium risultano come sono' );

$ss_rows = \AssociazioneSemplice\ServerStatusRules::rows( $ss_e );
$ss_ok( count( $ss_rows ) >= 15 && \AssociazioneSemplice\ServerStatusRules::find( $ss_rows, 'cron' ) && \AssociazioneSemplice\ServerStatusRules::find( $ss_rows, 'opcache' ), 'server: ogni controllo della tabella ha la sua riga' );

// il giro dei lavori è partito (smoke_lavori) o no: la riga lo dice con la data vera
$ss_cron = \AssociazioneSemplice\ServerStatusRules::find( $ss_rows, 'cron' );
$ss_ok( ( 0 === \AssociazioneSemplice\Jobs::last_run() ) === ( \AssociazioneSemplice\ServerStatusRules::WARN === $ss_cron['status'] && ! empty( $ss_cron['code'] ) ), 'server: la riga dei lavori programmati segue l\'ultimo giro registrato' );

// Salute del sito: cinque prove, nessuna interroga il database oltre alle opzioni
$ss_tests = \AssociazioneSemplice\ServerStatus::health_tests( array( 'direct' => array() ) );
$ss_ok( 5 === count( $ss_tests['direct'] ) && isset( $ss_tests['direct']['asem_cron'], $ss_tests['direct']['asem_extensions'] ), 'server: la Salute del sito riceve le prove del plugin' );
foreach ( $ss_tests['direct'] as $ss_id => $ss_t ) {
	$ss_res = call_user_func( $ss_t['test'] );
	$ss_ok( in_array( $ss_res['status'], array( 'good', 'recommended', 'critical' ), true ) && $ss_res['test'] === $ss_id && '' !== $ss_res['label'] && is_array( $ss_res['badge'] ), "server: la prova $ss_id restituisce un risultato valido" );
}

// la pagina
$ss_ok( 'asem-tools' === \AssociazioneSemplice\Admin\Admin::menu_item_of( 'asem-server' ) && in_array( 'asem-server', \AssociazioneSemplice\Admin\Admin::admin_only(), true ), 'server: la pagina sta negli Strumenti ed è solo per gli amministratori' );
ob_start();
\AssociazioneSemplice\Admin\ServerPage::render();
$ss_html = (string) ob_get_clean();
$ss_ok( false !== strpos( $ss_html, 'Stato del server' ) && false !== strpos( $ss_html, 'OPcache' ) && false !== strpos( $ss_html, 'Operazioni programmate' ) && false !== strpos( $ss_html, 'Cosa fare' ), 'server: la pagina elenca i controlli con stato e consiglio' );
ob_start();
\AssociazioneSemplice\Admin\ToolsPage::render();
$ss_tools = (string) ob_get_clean();
$ss_ok( false !== strpos( $ss_tools, 'Stato del server' ), 'server: la panoramica degli Strumenti la elenca' );

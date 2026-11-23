<?php
/**
 * Copia di sicurezza a tappe (L10): stessa copia di prima, ripresa dopo una tappa interrotta, ripristino, annullamento, nessun segreto nello stato.
 * Si carica da smoke.php (dopo le prove del motore dei lavori lunghi).
 */

use AssociazioneSemplice\Backup;
use AssociazioneSemplice\LongJob;

global $wpdb;

$bj_user = get_current_user_id();
wp_set_current_user( 1 );
LongJob::forget( Backup::JOB );
\AssociazioneSemplice\Attachments::prepare_dir();
$bj_att = \AssociazioneSemplice\Attachments::dir() . '/' . str_repeat( 'b', 32 );
file_put_contents( $bj_att, 'allegato della prova a tappe' );
$wpdb->insert( \AssociazioneSemplice\Db::t( 'attachments' ), array( 'transaction_id' => 0, 'original_name' => 'prova-tappe.txt', 'stored_name' => str_repeat( 'b', 32 ), 'mime' => 'text/plain', 'size_bytes' => 28, 'sha256' => hash( 'sha256', 'allegato della prova a tappe' ), 'created_at' => \AssociazioneSemplice\Db::now() ) );
$bj_att_row = (int) $wpdb->insert_id;
$bj_work    = function (): array {
	return glob( Backup::dir() . '/' . Backup::WORK_PREFIX . '*', GLOB_ONLYDIR ) ?: array();
};

// ---------- In una richiesta: la stessa copia di prima, con meno letture ----------
$bj_sql = array();
$bj_cap = function ( $sql ) use ( &$bj_sql ) {
	$bj_sql[] = (string) $sql;
	return $sql;
};
$bj_measure = function ( callable $fn ) use ( &$bj_sql, $bj_cap ): array {
	$bj_sql = array();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$m0 = memory_get_usage();
	$t0 = microtime( true );
	add_filter( 'query', $bj_cap );
	$path = $fn();
	remove_filter( 'query', $bj_cap );
	return array( $path, count( $bj_sql ), round( ( microtime( true ) - $t0 ) * 1000 ), round( ( memory_get_peak_usage() - $m0 ) / 1048576, 1 ), $bj_sql );
};
list( $bj_old, $bj_old_q, $bj_old_ms, $bj_old_mb ) = $bj_measure( function () {
	return asem_bk_export_legacy( true );
} );
list( $bj_new, $bj_new_q, $bj_new_ms, $bj_new_mb, $bj_new_sql ) = $bj_measure( function () {
	return Backup::export( true );
} );
$bj_d = asem_bk_compare( $bj_old, $bj_new );
asem_ok( ! $bj_d, 'copia a tappe: fatta in una richiesta, la copia è uguale a quella di prima (voci, ordine, contenuto, impronte)' . ( $bj_d ? ': ' . implode( '; ', array_slice( $bj_d, 0, 5 ) ) : '' ) );
asem_ok( ! preg_grep( '/asem_(people|transactions|audit_log)`.*OFFSET/i', $bj_new_sql ), 'copia a tappe: le tabelle si leggono per chiave, senza OFFSET' );
asem_ok( true, sprintf( 'copia a tappe, misura in una richiesta: prima %d query, %d ms, %s MB in più; ora %d query, %d ms, %s MB in più', $bj_old_q, $bj_old_ms, $bj_old_mb, $bj_new_q, $bj_new_ms, $bj_new_mb ) );
asem_ok( ! $bj_work(), 'copia a tappe: in una richiesta non resta nessuna cartella di lavoro' );
wp_delete_file( $bj_new );
wp_delete_file( $bj_old );

// ---------- A tappe, con una tappa interrotta in due punti ----------
Backup::$test_chunk     = 50; // tante tappe anche con pochi dati
Backup::$test_zip_bytes = 1;  // lo ZIP si chiude dopo ogni voce
$bj_old = asem_bk_export_legacy( true );
$bj_st  = Backup::start_job( true );
asem_ok( 'running' === $bj_st['status'] && $bj_st['total'] > 0 && 1 === count( $bj_work() ), 'copia a tappe: avviata, con il totale da fare e la sua cartella di lavoro' );
asem_ok( null !== asem_throws( function () { Backup::start_job( false ); } ), 'copia a tappe: una seconda copia non parte mentre la prima è in corso' );
$bj_html = asem_render( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ), 'data-asem-job="' . Backup::JOB . '"' );
asem_ok( false !== strpos( $bj_html, 'asem-longjob-cancel' ) && false === strpos( $bj_html, 'name="files" value="1"' ), 'copia a tappe: la pagina mostra barra e «Annulla», non il modulo per un\'altra copia' );
$bj_opt   = LongJob::OPT_PREFIX . Backup::JOB;
$bj_raw   = function () use ( $wpdb, $bj_opt ): string {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $bj_opt ) );
};
$bj_crash = function () use ( $wpdb, $bj_opt, $bj_raw ): void { // una tappa che muore dopo aver lavorato, prima di salvare il punto: il passo si rifà
	$saved = $bj_raw();
	LongJob::run( Backup::JOB, 0.0 );
	$wpdb->update( $wpdb->options, array( 'option_value' => $saved ), array( 'option_name' => $bj_opt ) );
};
$bj_steps   = 0;
$bj_crashed = array();
$bj_secret  = true;
$bj_bundle  = wp_json_encode( \AssociazioneSemplice\FileKey::bundle() );
$bj_seal    = bin2hex( \AssociazioneSemplice\BackupSeal::key( wp_salt( 'auth' ) ) );
$bj_lic     = (string) \AssociazioneSemplice\Settings::get( 'license_key' );
while ( $bj_steps < 20000 ) {
	$bj_s = (array) LongJob::state( Backup::JOB );
	if ( 'running' !== ( LongJob::status( Backup::JOB )['status'] ?? '' ) ) {
		break;
	}
	if ( ! isset( $bj_crashed['tables'] ) && 'tables' === ( $bj_s['phase'] ?? '' ) && $bj_steps >= 2 ) {
		$bj_crash();
		$bj_crashed['tables'] = true;
	} elseif ( ! isset( $bj_crashed['zip'] ) && 'zip' === ( $bj_s['phase'] ?? '' ) && (int) ( $bj_s['zi'] ?? 0 ) >= 2 ) {
		$bj_crash();
		$bj_crashed['zip'] = true;
	}
	LongJob::run( Backup::JOB, 0.0 );
	$bj_steps++;
	$r = $bj_raw();
	if ( false !== strpos( $r, $bj_seal ) || false !== strpos( $r, wp_salt( 'auth' ) ) || ( '' !== $bj_lic && false !== strpos( $r, $bj_lic ) ) || false !== strpos( $r, 'chiavi.json' ) ) {
		$bj_secret = false;
	}
}
$bj_st = LongJob::status( Backup::JOB );
asem_ok( 'finished' === $bj_st['status'] && $bj_steps > 5 && 2 === count( $bj_crashed ), 'copia a tappe: si arriva in fondo a tappe (' . $bj_steps . '), anche dopo due tappe interrotte' );
asem_ok( $bj_secret && ( '[]' === $bj_bundle || false === strpos( $bj_raw(), (string) $bj_bundle ) ) && array() === array_diff( array_keys( (array) $bj_st['result'] ), array( 'size', 'tables', 'attachments', 'files' ) ), 'copia a tappe: nello stato salvato e nel risultato per la pagina non c\'è nessuna chiave, frase o firma' );
$bj_ready = Backup::ready_file();
$bj_d     = '' !== $bj_ready ? asem_bk_compare( $bj_old, $bj_ready ) : array( 'nessun file pronto' );
asem_ok( ! $bj_d, 'copia a tappe: la copia fatta a tappe è uguale a quella di prima' . ( $bj_d ? ': ' . implode( '; ', array_slice( $bj_d, 0, 5 ) ) : '' ) );
asem_ok( '' === Backup::ready_file( 999999 ), 'copia a tappe: la copia pronta la scarica solo chi l\'ha chiesta' );
$bj_left = glob( dirname( $bj_ready ) . '/*' ) ?: array();
asem_ok( array( 'copia.zip' ) === array_values( array_diff( array_map( 'basename', $bj_left ), array( 'index.php' ) ) ),'copia a tappe: a lavoro finito nella cartella resta solo la copia (i file intermedi sono cancellati)' );
asem_render( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ), 'asem_backup_get' );
$bj_origin = '';
Backup::inspect( $bj_ready, $bj_origin );
asem_ok( 'same' === $bj_origin, 'copia a tappe: la copia si riconosce come fatta da questo sito (firma e impronte valide)' );
$bj_people = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \AssociazioneSemplice\Db::t( 'people' ) );
wp_delete_file( $bj_att );
$bj_res = Backup::restore( $bj_ready );
asem_ok( $bj_res['rows'] > 0 && $bj_people === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \AssociazioneSemplice\Db::t( 'people' ) ) && is_file( $bj_att ) && 'allegato della prova a tappe' === file_get_contents( $bj_att ), 'copia a tappe: la copia fatta a tappe si ripristina (dati e allegati)' );
wp_delete_file( $bj_old );

// ---------- Annullamento ----------
$bj_prev = dirname( $bj_ready );
Backup::start_job( false );
asem_ok( ! is_dir( $bj_prev ) && 1 === count( $bj_work() ), 'copia a tappe: una copia nuova toglie quella pronta e mai scaricata' );
LongJob::run( Backup::JOB, 0.0 );
LongJob::run( Backup::JOB, 0.0 );
$bj_st = LongJob::cancel( Backup::JOB );
asem_ok( 'cancelled' === $bj_st['status'] && ! $bj_work() && '' === Backup::ready_file(), 'copia a tappe: «Annulla» ferma la copia e cancella i file già scritti' );
$bj_html = asem_render( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ), 'name="files" value="1"' );
asem_ok( false !== strpos( $bj_html, 'Lavoro annullato.' ), 'copia a tappe: dopo l\'annullamento la pagina lo dice e si può fare una copia nuova' );

// ---------- Pulizia delle cartelle di lavoro abbandonate ----------
$bj_stale = Backup::dir() . '/' . Backup::WORK_PREFIX . str_repeat( 'c', 32 );
wp_mkdir_p( $bj_stale );
file_put_contents( $bj_stale . '/t-0.jsonl', '{}' );
touch( $bj_stale, time() - 400 * HOUR_IN_SECONDS );
Backup::start_job( false );
$bj_live = $bj_work();
touch( (string) reset( $bj_live ), time() - 400 * HOUR_IN_SECONDS );
Backup::purge_old();
asem_ok( ! is_dir( $bj_stale ) && 1 === count( $bj_work() ), 'copia a tappe: la pulizia oraria toglie le cartelle di lavoro abbandonate, non quella della copia in corso' );
LongJob::cancel( Backup::JOB );
LongJob::forget( Backup::JOB );

Backup::$test_chunk     = 0;
Backup::$test_zip_bytes = 0;
$wpdb->delete( \AssociazioneSemplice\Db::t( 'attachments' ), array( 'id' => $bj_att_row ) );
wp_delete_file( $bj_att );
wp_set_current_user( $bj_user );

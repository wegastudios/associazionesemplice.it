<?php
/**
 * Dopo l'aggiornamento (build corrente installata sopra la 1.3.25, senza disinstallare): i dati ci sono tutti e lo schema è al livello della build.
 *   wp eval-file wp-content/prove/prova-dati-verifica.php
 */

use AssociazioneSemplice\Install;

require_once __DIR__ . '/prova-dati-comune.php';

$prima = get_option( 'asem_prova_impronta' );
dati_ok( is_array( $prima ) && ! empty( $prima['impronta'] ), 'impronta salvata prima dell\'aggiornamento trovata (versione ' . ( $prima['versione'] ?? '?' ) . ', DB ' . ( $prima['db_version'] ?? '?' ) . ')' );
$ora = dati_impronta();
dati_ok( $ora === $prima['impronta'], 'soci, iscrizioni, movimenti, importi e prenotazioni identici a prima: ' . wp_json_encode( $ora ) );
dati_ok( (string) get_option( 'asem_db_version', '' ) === Install::DB_VERSION, 'schema aggiornato: DB ' . $prima['db_version'] . ' → ' . get_option( 'asem_db_version' ) . ' (livello della build: ' . Install::DB_VERSION . ')' );
dati_ok( version_compare( $prima['db_version'], Install::DB_VERSION, '<=' ), 'lo schema non è mai tornato indietro' );
$indici_ok = true;
foreach ( Install::INDEXES as $t_ix => $lista_ix ) {
	if ( ! Install::table_exists( \AssociazioneSemplice\Db::t( $t_ix ) ) ) {
		continue; // tabella di un'estensione non presente in questa edizione
	}
	foreach ( array_keys( $lista_ix ) as $n_ix ) {
		$indici_ok = $indici_ok && Install::index_exists( \AssociazioneSemplice\Db::t( $t_ix ), $n_ix );
	}
}
dati_ok( $indici_ok, 'indici delle ricerche frequenti presenti dopo l\'aggiornamento' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
\AssociazioneSemplice\Admin\Admin::init();
ob_start();
\AssociazioneSemplice\Admin\PeoplePage::render_list();
$html = (string) ob_get_clean();
dati_ok( false !== strpos( $html, 'Rossi' ) && false !== strpos( $html, 'Bianchi' ) && false !== strpos( $html, 'Verdi' ), 'la Rubrica mostra i soci creati prima' );
// Lavori periodici: i vecchi eventi, uno per lavoro, sono passati nel giro dei due eventi (orario e giornaliero) con la loro prossima scadenza.
$lav_vecchi = array();
$lav_stato  = (array) get_option( \AssociazioneSemplice\Jobs::STATE, array() );
$lav_senza  = array();
foreach ( \AssociazioneSemplice\Plugin::jobs() as $lav_h => $lav_j ) {
	if ( '' === $lav_j[0] ) {
		continue;
	}
	$lav_ev = wp_get_scheduled_event( $lav_h );
	if ( $lav_ev && ! empty( $lav_ev->schedule ) ) {
		$lav_vecchi[] = $lav_h;
	}
}
foreach ( (array) get_option( 'asem_prova_lavori', array() ) as $lav_h => $lav_ts ) { // quelli che la versione di prima aveva programmato
	if ( isset( \AssociazioneSemplice\Plugin::jobs()[ $lav_h ] ) && (int) ( $lav_stato['done'][ $lav_h ] ?? 0 ) <= 0 ) {
		$lav_senza[] = $lav_h;
	}
}
dati_ok( ! $lav_vecchi && false !== wp_next_scheduled( \AssociazioneSemplice\Plugin::JOBS_HOURLY ) && false !== wp_next_scheduled( \AssociazioneSemplice\Plugin::JOBS_DAILY ), 'lavori periodici: dopo l\'aggiornamento restano solo i due eventi del giro' . ( $lav_vecchi ? ' (vecchi ancora presenti: ' . implode( ', ', $lav_vecchi ) . ')' : '' ) );
dati_ok( ! $lav_senza, 'lavori periodici: ogni vecchio evento ha passato la sua prossima scadenza al giro' . ( $lav_senza ? ' (senza: ' . implode( ', ', $lav_senza ) . ')' : '' ) );
WP_CLI::success( 'Aggiornamento da ' . $prima['versione'] . ' alla build corrente (' . ASEM_VERSION . '): dati conservati.' );

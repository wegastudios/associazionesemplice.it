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
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
\AssociazioneSemplice\Admin\Admin::init();
ob_start();
\AssociazioneSemplice\Admin\PeoplePage::render_list();
$html = (string) ob_get_clean();
dati_ok( false !== strpos( $html, 'Rossi' ) && false !== strpos( $html, 'Bianchi' ) && false !== strpos( $html, 'Verdi' ), 'la Rubrica mostra i soci creati prima' );
WP_CLI::success( 'Aggiornamento da ' . $prima['versione'] . ' alla build corrente (' . ASEM_VERSION . '): dati conservati.' );

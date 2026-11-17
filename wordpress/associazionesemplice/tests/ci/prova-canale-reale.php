<?php
/**
 * Prova del canale REALE degli aggiornamenti (solo lettura, nessuna chiave, nessun dato del sito): il gratuito del portale, con la versione
 * abbassata a 1.3.0 nella copia di prova, chiede al portale vero l'ultima versione pubblicata, WordPress la vede in Bacheca → Aggiornamenti
 * (transient update_plugins) e la installa. Non pubblica niente.
 *   wp eval-file wp-content/prove/prova-canale-reale.php
 * Il gratuito manda al portale solo il nome del prodotto e la versione installata: nessun segreto.
 */

use AssociazioneSemplice\Updater;

function reale_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

// WordPress gira su localhost: il plugin lo considera un sito di sviluppo e non chiamerebbe il portale. Qui lo si vuole chiamare.
add_filter( 'asem_portal_is_local', '__return_false', 99 );

$basename = plugin_basename( ASEM_FILE );
reale_ok( '1.3.0' === ASEM_VERSION, 'copia di prova alla versione 1.3.0' );
$hdr = get_plugin_data( ASEM_FILE, false, false );
reale_ok( 'https://associazionesemplice.it/' === ( $hdr['UpdateURI'] ?? '' ), 'l\'intestazione ha Update URI: https://associazionesemplice.it/' );
reale_ok( Updater::enabled(), 'aggiornamenti dal portale attivi (non è un sito locale, impostazione accesa)' );

// Il portale vero risponde? Se è irraggiungibile la prova si salta (non è un difetto del plugin); se risponde, deve rispondere bene.
$r = null;
for ( $i = 0; $i < 3 && ! $r; $i++ ) {
	$r = Updater::ask( 'free' );
	if ( ! $r ) {
		sleep( 5 );
	}
}
if ( ! $r ) {
	echo "::warning::Prova del canale reale saltata: il portale non ha risposto in modo valido per tre volte.\n";
	WP_CLI::warning( 'portale non raggiungibile o troppe richieste: prova saltata' );
	exit( 0 );
}
reale_ok( ! empty( $r['update'] ) && version_compare( $r['version'], '1.3.0', '>' ), 'il portale dichiara un aggiornamento: ' . $r['version'] . ' (installata 1.3.0)' );
WP_CLI::log( 'info - firma v2 presente: ' . ( $r['v2'] ? 'sì' : 'no' ) . ', pacchetto https: ' . ( 0 === strpos( $r['package'], 'https://associazionesemplice.it/' ) ? 'sì' : 'no' ) );

// Bacheca → Aggiornamenti: il transient `update_plugins` contiene l'aggiornamento.
delete_option( Updater::OPT );
delete_option( Updater::SEEN_OPT );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
reale_ok( is_object( $t ) && isset( $t->response[ $basename ] ), 'WordPress vede l\'aggiornamento in update_plugins' );
$new = (string) $t->response[ $basename ]->new_version;
reale_ok( version_compare( $new, '1.3.0', '>' ) && $new === $r['version'], 'versione offerta = ultima pubblicata dal portale: ' . $new );
reale_ok( 'asem-portal://free' === $t->response[ $basename ]->package, 'il pacchetto si chiede al portale al momento dello scarico' );

// Installazione vera: scarico firmato, impronta, estrazione.
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
WP_Filesystem();
$skin     = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$res      = $upgrader->upgrade( $basename );
if ( true !== $res ) {
	$why = is_wp_error( $res ) ? $res->get_error_message() : implode( ' | ', array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
	WP_CLI::error( 'FAIL: installazione dell\'aggiornamento non riuscita: ' . $why );
}
wp_clean_plugins_cache();
$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
reale_ok( $new === $after['Version'], 'installato: la versione sul disco è ora ' . $after['Version'] );
// La prima firma v2 vera è stata accettata: la memoria v2 del gratuito è accesa e la versione vista è quella installata.
$seen_all = get_option( Updater::SEEN_OPT, array() );
$seen     = is_array( $seen_all ) && isset( $seen_all['free'] ) && is_array( $seen_all['free'] ) ? $seen_all['free'] : array();
reale_ok( ! empty( $seen['v2'] ), 'dopo l\'installazione la memoria v2 del gratuito è accesa' );
reale_ok( isset( $seen['version'] ) && $seen['version'] === $new, 'la versione vista con firma v2 è ' . ( $seen['version'] ?? '(nessuna)' ) . ' (installata ' . $new . ')' );
// Informazione, non errore: lo zip pubblicato dal portale può essere più vecchio dell'introduzione di Update URI nella build.
if ( 'https://associazionesemplice.it/' !== ( $after['UpdateURI'] ?? '' ) ) {
	echo "::warning::Il pacchetto pubblicato sul portale (versione {$after['Version']}) non dichiara Update URI: lo dichiarerà dal prossimo rilascio costruito con build.sh.\n";
} else {
	WP_CLI::log( 'ok - il pacchetto installato dichiara Update URI' );
}
WP_CLI::success( 'Canale reale: aggiornamento visto e installato.' );

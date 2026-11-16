<?php
/**
 * Dati di esempio per le schermate dei pannelli (workflow «Prove in sandbox»), dentro wp-env:
 *   wp eval-file wp-content/sandbox-scripts/prepara-demo.php
 * Accende le funzioni Pro (licenza di prova in «standby», come nelle prove del plugin) e carica i dati della demo del repository (demo/provision.php e
 * demo/extra-data.php, montati in wp-content/demo-seed). Un passo che non riesce si segnala e non ferma gli altri.
 */
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 ); // amministratore d'installazione, come --user=... nella demo vera
Settings::update( array( 'license_key' => 'SANDBOX-0001' ) );

foreach ( array( 'provision.php', 'extra-data.php' ) as $file ) {
	$path = WP_CONTENT_DIR . '/demo-seed/' . $file;
	if ( ! is_readable( $path ) ) {
		WP_CLI::warning( 'Dati di esempio non trovati: ' . $file );
		continue;
	}
	try {
		require $path;
	} catch ( \Throwable $e ) {
		WP_CLI::warning( $file . ': ' . $e->getMessage() );
	}
}

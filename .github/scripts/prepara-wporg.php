<?php
/**
 * Dati finti per le schermate di WordPress.org, dentro wp-env con il SOLO plugin gratuito:
 *   wp eval-file wp-content/sandbox-scripts/prepara-wporg.php
 * Riusa i dati della demo del repository (demo/provision.php e demo/extra-data.php, montati in wp-content/demo-seed: i passi che richiedono il Pro
 * si saltano da soli) e poi dà importi veri ad ogni attività, così le schede di «Corsi ed eventi» non mostrano 0,00 €.
 * Non imposta nessuna licenza: il Pro non c'è.
 */
use AssociazioneSemplice\Plugin;

wp_set_current_user( 1 );

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

// Importi veri su ogni attività: contributi incassati e qualche costo.
try {
	global $wpdb;
	$ledger = Plugin::ledger();
	$cash   = $ledger->default_account_for( 'cash' );
	$bank   = $ledger->default_account_for( 'bank' );
	$fee    = $ledger->category_id_of_kind( 'activity_fee' );
	$cost   = $ledger->category_id_of_kind( 'activity_cost' );
	$ids    = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . \AssociazioneSemplice\Db::t( 'activities' ) . ' ORDER BY id LIMIT 24' ) );
	$n      = 0;
	$people = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . \AssociazioneSemplice\Db::t( 'people' ) . ' ORDER BY id LIMIT 40' ) );
	foreach ( $ids as $aid ) {
		++$n;
		try {
			if ( $cash && $fee ) {
				$sess = Plugin::activities()->sessions( $aid );
				$line = array( "category_id" => $fee, "amount_cents" => 4000 + ( $n % 7 ) * 1500, "description" => "Contributi", "activity_id" => $aid );
				if ( $sess ) {
					$line["session_id"] = (int) $sess[0]["id"];
				}
				$ledger->record_receipt( array(
					'date' => gmdate( 'Y-m-d', time() - ( 3 + $n ) * 86400 ), 'account_id' => (int) $cash['id'], 'method' => 'cash',
					'person_id' => $people ? $people[ $n % count( $people ) ] : null,
					'lines' => array( $line ),
				) );
			}
			if ( $bank && $cost ) {
				$ledger->record_expense( array(
					'date' => gmdate( 'Y-m-d', time() - ( 2 + $n ) * 86400 ), 'account_id' => (int) $bank['id'], 'method' => 'bank_transfer',
					'category_id' => $cost, 'amount_cents' => 1500 + ( $n % 5 ) * 900, 'description' => 'Materiale e spese', 'activity_id' => $aid,
				) );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::warning( 'attività ' . $aid . ': ' . $e->getMessage() );
		}
	}
	WP_CLI::log( 'Importi sulle attività: ' . count( $ids ) );
} catch ( \Throwable $e ) {
	WP_CLI::warning( 'importi: ' . $e->getMessage() );
}

// La pagina pubblica degli eventi, se i dati della demo non l'hanno creata.
if ( ! get_page_by_path( 'attivita-ed-eventi' ) ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Attività ed eventi', 'post_name' => 'attivita-ed-eventi', 'post_content' => '[associazionesemplice_eventi]' ) );
}
WP_CLI::success( 'Dati per le schermate di WordPress.org pronti.' );

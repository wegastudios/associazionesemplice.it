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

// Nome dell'utente che appare nella barra di WordPress.
wp_update_user( array( 'ID' => 1, 'display_name' => 'Segreteria', 'nickname' => 'Segreteria' ) );

// Attività a pagamento con più partecipanti e importi che tornano: ogni partecipante ha pagato il contributo, i costi sono una parte degli incassi.
try {
	global $wpdb;
	$ledger = Plugin::ledger();
	$act    = Plugin::activities();
	$cash   = $ledger->default_account_for( 'cash' );
	$bank   = $ledger->default_account_for( 'bank' );
	$fee    = $ledger->category_id_of_kind( 'activity_fee' );
	$cost   = $ledger->category_id_of_kind( 'activity_cost' );
	$people = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . \AssociazioneSemplice\Db::t( 'people' ) . ' ORDER BY id LIMIT 40' ) );
	$ids    = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . \AssociazioneSemplice\Db::t( 'activities' ) . ' ORDER BY id LIMIT 80' ) );
	$n      = 0;
	foreach ( $ids as $aid ) {
		$a = $act->get( $aid );
		if ( ! $a || (int) $a['fee_cents'] <= 0 || $n >= 10 || count( $people ) < 14 ) {
			continue;
		}
		++$n;
		$k      = 7 + ( $n % 6 );
		$who    = array_slice( $people, $n, $k );
		$income = 0;
		$sess   = 'event' === $a['kind'] ? $act->sessions( $aid ) : array();
		foreach ( $who as $i => $pid ) {
			try {
				$line = array( 'category_id' => $fee, 'amount_cents' => (int) $a['fee_cents'], 'description' => 'Contributo', 'activity_id' => $aid );
				if ( $sess ) {
					$act->book( (int) $sess[0]['id'], $pid );
					$line['session_id'] = (int) $sess[0]['id'];
				} else {
					$act->enroll( $aid, $pid, gmdate( 'Y-m' ) );
				}
				$ledger->record_receipt( array(
					'date' => gmdate( 'Y-m-d', time() - ( 2 + $i ) * 86400 ), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $pid, 'lines' => array( $line ),
				) );
				$income += (int) $a['fee_cents'];
			} catch ( \Throwable $e ) {
				WP_CLI::log( 'attività ' . $aid . ' socio ' . $pid . ': ' . $e->getMessage() );
			}
		}
		if ( $income > 0 && $bank && $cost ) {
			try {
				$ledger->record_expense( array(
					'date' => gmdate( 'Y-m-d', time() - ( 1 + $n ) * 86400 ), 'account_id' => (int) $bank['id'], 'method' => 'bank_transfer',
					'category_id' => $cost, 'amount_cents' => (int) round( $income * ( 0.25 + ( $n % 4 ) * 0.08 ) / 100 ) * 100, 'description' => 'Materiale e spese', 'activity_id' => $aid,
				) );
			} catch ( \Throwable $e ) {
				WP_CLI::log( 'costo ' . $aid . ': ' . $e->getMessage() );
			}
		}
	}
	WP_CLI::log( 'Attività a pagamento con partecipanti e importi: ' . $n );
} catch ( \Throwable $e ) {
	WP_CLI::warning( 'importi: ' . $e->getMessage() );
}

// Locandine: sulle pagine pubbliche restano gli eventi che ne hanno una (foto rimpicciolite in locandine-wporg), gli altri si mettono in bozza.
try {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( get_posts( array( 'post_type' => \AssociazioneSemplice\PublicEvents::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200 ) ) as $p ) {
		$file = WP_CONTENT_DIR . '/sandbox-scripts/locandine-wporg/' . sanitize_title( $p->post_title ) . '.webp';
		if ( ! is_readable( $file ) ) {
			wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
			continue;
		}
		$up = wp_upload_bits( basename( $file ), null, (string) file_get_contents( $file ) );
		if ( ! empty( $up['error'] ) ) {
			continue;
		}
		$att = wp_insert_attachment( array( 'post_mime_type' => 'image/webp', 'post_title' => $p->post_title, 'post_status' => 'inherit' ), $up['file'], $p->ID );
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		set_post_thumbnail( $p->ID, $att );
	}
} catch ( \Throwable $e ) {
	WP_CLI::warning( 'locandine: ' . $e->getMessage() );
}

// La pagina pubblica degli eventi, se i dati della demo non l'hanno creata.
if ( ! get_page_by_path( 'attivita-ed-eventi' ) ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Attività ed eventi', 'post_name' => 'attivita-ed-eventi', 'post_content' => '[associazionesemplice_eventi]' ) );
}
WP_CLI::success( 'Dati per le schermate di WordPress.org pronti.' );

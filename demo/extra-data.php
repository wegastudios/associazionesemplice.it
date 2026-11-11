<?php
/**
 * Dati in più per la demo (associazionesemplice.it/demo): soci con tessere in regola, scadute, sospese e senza tessera, ospiti,
 * più movimenti di prima nota, certificati, prestiti, richieste di accesso, un'assemblea, rimborsi e domande di adesione.
 * Serve a valutare l'aspetto delle pagine con elenchi pieni. Si aggiunge ai dati di provision.php, non cancella niente.
 * Si lancia una volta: wp eval-file extra-data.php --user=amministratore   (un segnaposto impedisce di lanciarlo due volte)
 * Tutti i dati sono inventati.
 */
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

/** Ogni passo riuscito si ricorda: rilanciando lo script si ripetono solo quelli rimasti (niente doppioni). */
function extra_step( string $what, callable $fn ) {
	$done = (array) get_option( 'asem_demo_extra_steps', array() );
	if ( in_array( $what, $done, true ) ) {
		WP_CLI::log( '  già fatto: ' . $what );
		return null;
	}
	try {
		$r = $fn();
		$done[] = $what;
		update_option( 'asem_demo_extra_steps', $done, false );
		WP_CLI::log( '  ok ' . $what );
		return $r;
	} catch ( \Throwable $e ) {
		WP_CLI::warning( $what . ': ' . $e->getMessage() );
		return null;
	}
}

/** Codice fiscale di forma valida (lettera di controllo calcolata) per i dati inventati. */
function extra_cf( string $first, string $last, int $n ): string {
	$cons = function ( string $s ): string { return strtoupper( substr( preg_replace( '/[^BCDFGHJKLMNPQRSTVWXYZ]/i', '', $s ) . 'XXX', 0, 3 ) ); };
	$year = 1955 + ( $n * 7 ) % 45;
	$day  = 1 + ( $n * 5 ) % 27;
	$base = $cons( $last ) . $cons( $first ) . sprintf( '%02d', $year % 100 ) . 'M' . sprintf( '%02d', $day ) . 'H501';
	foreach ( range( 'A', 'Z' ) as $c ) {
		if ( \AssociazioneSemplice\TaxCode::is_valid( $base . $c ) ) {
			return $base . $c;
		}
	}
	return $base;
}

$people = Plugin::people();
$ledger = Plugin::ledger();
$y      = (int) gmdate( 'Y' );
$cur    = Settings::membership_year( gmdate( 'Y-m-d' ) );
$prev   = $cur->previous()->label();
$ids    = array();
$by_name = function ( string $n ) use ( $people ) {
	foreach ( $people->search() as $p ) {
		if ( $p['first_name'] . ' ' . $p['last_name'] === $n ) {
			return (int) $p['id'];
		}
	}
	return 0;
};

// 1. Soci: in regola (con incasso in prima nota), scaduti, senza tessera, sospesi
extra_step( 'soci', function () use ( $people, $ledger, $cur, $prev, $y, &$ids ) {
	$roster = array(
		// nome, cognome, tipo, città, stato: paid | expired | none | suspended
		array( 'Giulia', 'Esposito', 'ordinary', 'Roma', 'paid' ), array( 'Matteo', 'Romano', 'ordinary', 'Roma', 'paid' ),
		array( 'Francesca', 'Colombo', 'ordinary', 'Frascati', 'paid' ), array( 'Alessandro', 'Ricci', 'volunteer', 'Roma', 'paid' ),
		array( 'Valentina', 'Marino', 'ordinary', 'Tivoli', 'paid' ), array( 'Lorenzo', 'Greco', 'ordinary', 'Roma', 'paid' ),
		array( 'Silvia', 'Bruno', 'ordinary', 'Ciampino', 'paid' ), array( 'Andrea', 'Gallo', 'volunteer', 'Roma', 'paid' ),
		array( 'Federica', 'Rizzo', 'ordinary', 'Roma', 'paid' ), array( 'Stefano', 'Lombardi', 'ordinary', 'Marino', 'paid' ),
		array( 'Martina', 'Barbieri', 'ordinary', 'Roma', 'paid' ), array( 'Simone', 'Fabbri', 'ordinary', 'Roma', 'paid' ),
		array( 'Roberta', 'Mancini', 'ordinary', 'Roma', 'expired' ), array( 'Daniele', 'Pellegrini', 'ordinary', 'Frascati', 'expired' ),
		array( 'Alice', 'Vitale', 'ordinary', 'Roma', 'expired' ), array( 'Gabriele', 'Leone', 'ordinary', 'Roma', 'expired' ),
		array( 'Beatrice', 'Longo', 'ordinary', 'Tivoli', 'expired' ), array( 'Tommaso', 'Martini', 'ordinary', 'Roma', 'expired' ),
		array( 'Elisa', 'Giordano', 'ordinary', 'Roma', 'none' ), array( 'Riccardo', 'Testa', 'ordinary', 'Roma', 'none' ),
		array( 'Camilla', 'Sala', 'ordinary', 'Ciampino', 'none' ), array( 'Nicola', 'De Luca', 'ordinary', 'Roma', 'suspended' ),
		array( 'Monica', 'Valentini', 'ordinary', 'Roma', 'suspended' ),
	);
	$acc = $ledger->default_account_for( 'cash' );
	$cat = $ledger->category_id_of_kind( 'membership' );
	$day = 85;
	$existing = array();
	foreach ( $people->search() as $p ) {
		$existing[ $p['first_name'] . ' ' . $p['last_name'] ] = (int) $p['id'];
	}
	foreach ( $roster as $i => $r ) {
		if ( isset( $existing[ $r[0] . ' ' . $r[1] ] ) && ( 'paid' !== $r[4] || $people->active_until( $existing[ $r[0] . ' ' . $r[1] ] ) ) ) {
			continue; // già inserito (anche da un tentativo precedente interrotto)
		}
		$id = isset( $existing[ $r[0] . ' ' . $r[1] ] ) ? $existing[ $r[0] . ' ' . $r[1] ] : $people->create( array(
			'type' => $r[2], 'first_name' => $r[0], 'last_name' => $r[1], 'email' => 0 === $i % 3 ? strtolower( $r[0] . '.' . str_replace( ' ', '', $r[1] ) ) . '@demo.invalid' : null,
			'phone' => '+39 06 556' . sprintf( '%04d', 2000 + $i * 41 ), 'city' => $r[3], 'province' => 'RM', 'joined_on' => ( $y - 1 - ( $i % 3 ) ) . '-0' . ( 1 + $i % 9 ) . '-1' . ( $i % 9 ),
			'card_number' => $people->next_free_card(), 'tax_code' => $i % 2 ? extra_cf( $r[0], $r[1], $i + 40 ) : '',
			'birth_date' => ( 1960 + ( $i * 3 ) % 40 ) . '-0' . ( 1 + $i % 9 ) . '-1' . ( $i % 9 ),
		) );
		$ids[ $r[0] . ' ' . $r[1] ] = $id;
		if ( 'paid' === $r[4] && $acc && $cat ) {
			$ledger->record_receipt( array(
				'date' => gmdate( 'Y-m-d', time() - ( $day -= 6 ) * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 0 === $i % 2 ? 'cash' : 'bank_transfer', 'person_id' => $id,
				'lines' => array( array( 'category_id' => $cat, 'amount_cents' => 2000, 'description' => 'Quota associativa' ) ),
			) );
		} elseif ( 'expired' === $r[4] || 'suspended' === $r[4] ) {
			$people->set_membership( $id, $prev, true );
		}
		if ( 'suspended' === $r[4] ) {
			$people->suspend( $id );
		}
	}
} );

// 2. Ospiti dei soci
extra_step( 'ospiti', function () use ( $people, $by_name ) {
	$hosts = array( 'Mario Demo', 'Luca Neri', 'Sara Gallo' );
	foreach ( array( array( 'Ilaria', 'Piras' ), array( 'Enrico', 'Sanna' ), array( 'Paola', 'Deiana' ) ) as $i => $g ) {
		$host = $by_name( $hosts[ $i ] );
		if ( $host ) {
			$people->create( array( 'type' => 'guest', 'first_name' => $g[0], 'last_name' => $g[1], 'phone' => '+39 333 55' . sprintf( '%05d', 12000 + $i * 777 ), 'host_person_id' => $host, 'city' => 'Roma', 'province' => 'RM', 'joined_on' => gmdate( 'Y-m-d' ) ) );
		}
	}
} );

// 3. Più prima nota: donazioni, spese e un incasso
extra_step( 'prima nota', function () use ( $ledger ) {
	$acc  = $ledger->default_account_for( 'cash' );
	$don  = $ledger->category_id_of_kind( 'donation' );
	$cost = $ledger->category_id_of_kind( 'general_cost' );
	if ( ! $acc ) {
		return;
	}
	foreach ( array( array( 70, 7500, 'Erogazione liberale di un socio' ), array( 41, 2500, 'Offerta al banchetto' ), array( 3, 4000, 'Offerta alla serata di giochi' ) ) as $d ) {
		if ( $don ) {
			$ledger->record_receipt( array( 'date' => gmdate( 'Y-m-d', time() - $d[0] * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'lines' => array( array( 'category_id' => $don, 'amount_cents' => $d[1], 'description' => $d[2] ) ) ) );
		}
	}
	foreach ( array( array( 80, 18000, 'Affitto sala per l\'assemblea' ), array( 64, 5400, 'Stampa dei volantini' ), array( 52, 2200, 'Bollette del mese' ), array( 33, 7600, 'Compenso per il corso di fotografia' ), array( 27, 1900, 'Materiale per la gita' ), array( 11, 4300, 'Riparazione dell\'impianto audio' ), array( 2, 2900, 'Acqua e bibite per la serata' ) ) as $s ) {
		if ( $cost ) {
			$ledger->record_expense( array( 'date' => gmdate( 'Y-m-d', time() - $s[0] * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'category_id' => $cost, 'amount_cents' => $s[1], 'description' => $s[2] ) );
		}
	}
} );

// 4. Certificati medici: scaduti, in scadenza e validi
extra_step( 'certificati', function () use ( $by_name ) {
	$rows = array(
		array( 'Luca Neri', -400, -35, 'agonistic' ), array( 'Sara Gallo', -340, 25, 'non_agonistic' ), array( 'Elena Conti', -100, 265, 'non_agonistic' ),
		array( 'Davide Greco', -200, 12, 'agonistic' ), array( 'Marta Serra', -30, 335, 'non_agonistic' ), array( 'Paolo Ferri', -380, -15, 'non_agonistic' ),
		array( 'Giulia Esposito', -60, 305, 'non_agonistic' ), array( 'Matteo Romano', -355, 10, 'agonistic' ),
	);
	foreach ( $rows as $r ) {
		$pid = $by_name( $r[0] );
		if ( $pid ) {
			\AssociazioneSemplice\Certificates::add( $pid, array( 'kind' => $r[3], 'issued_on' => gmdate( 'Y-m-d', time() + $r[1] * 86400 ), 'expires_on' => gmdate( 'Y-m-d', time() + $r[2] * 86400 ), 'doctor' => 'Dott.ssa Rossi, Poliambulatorio Roma Est' ) );
		}
	}
} );

// 5. Inventario e prestiti, uno scaduto
extra_step( 'inventario e prestiti', function () use ( $by_name ) {
	$items = array();
	foreach ( array( array( 'Proiettore', 1 ), array( 'Casse audio', 2 ), array( 'Sedie pieghevoli', 40 ), array( 'Tavolo da ping pong', 1 ) ) as $it ) {
		$items[ $it[0] ] = \AssociazioneSemplice\Inventory::save_item( 0, array( 'name' => $it[0], 'quantity' => $it[1], 'state' => 'good', 'location' => 'Magazzino della sede' ) );
	}
	$lend = array( array( 'Proiettore', 'Lorenzo Greco', 1, -20, -5 ), array( 'Casse audio', 'Alessandro Ricci', 2, -6, 8 ), array( 'Sedie pieghevoli', 'Silvia Bruno', 12, -2, 12 ) );
	foreach ( $lend as $l ) {
		$pid = $by_name( $l[1] );
		if ( $pid && ! empty( $items[ $l[0] ] ) ) {
			\AssociazioneSemplice\Inventory::lend( (int) $items[ $l[0] ], $pid, $l[2], gmdate( 'Y-m-d', time() + $l[3] * 86400 ), gmdate( 'Y-m-d', time() + $l[4] * 86400 ), 'Per un evento' );
		}
	}
} );

// 6. Richieste di accesso dal sito (non riconosciuti)
extra_step( 'richieste di accesso', function () {
	\AssociazioneSemplice\AccessRequests::add( array( 'kind' => 'unknown', 'name' => 'Cristina Fadda', 'email' => 'cristina.fadda@demo.invalid', 'phone' => '+39 333 5501122' ) );
	\AssociazioneSemplice\AccessRequests::add( array( 'kind' => 'unknown', 'name' => 'Walter Orrù', 'email' => '', 'phone' => '+39 347 5509988' ) );
} );

// 7. Domande di adesione (dal modulo «Diventa socio»)
extra_step( 'domande di adesione', function () {
	Settings::update( array( 'apply_enabled' => 1 ) );
	foreach ( array( array( 'Veronica', 'Caruso', 'veronica.caruso@demo.invalid' ), array( 'Samuele', 'Ferrara', 'samuele.ferrara@demo.invalid' ), array( 'Noemi', 'Basile', 'noemi.basile@demo.invalid' ) ) as $i => $a ) {
		$r = \AssociazioneSemplice\Applications::submit( array(
			'type' => 'ordinary', 'first_name' => $a[0], 'last_name' => $a[1], 'email' => $a[2], 'phone' => '+39 06 557' . sprintf( '%04d', 3100 + $i * 53 ),
			'tax_code' => extra_cf( $a[0], $a[1], 90 + $i ), 'birth_date' => ( 1985 + $i * 4 ) . '-05-1' . ( 2 + $i ), 'birth_place' => 'Roma', 'address' => 'Via Appia ' . ( 10 + $i ),
			'zip' => '00179', 'city' => 'Roma', 'province' => 'RM', 'rules_ok' => 1, 'privacy_ok' => 1, 'image_ok' => 1,
		) );
		if ( 'ok' !== ( $r['status'] ?? '' ) ) {
			WP_CLI::warning( 'domanda ' . $a[1] . ': ' . wp_json_encode( $r ) );
		}
	}
} );

// 8. Un'assemblea in programma e due richieste di rimborso
extra_step( 'assemblea', function () {
	\AssociazioneSemplice\Assemblies::save( 0, array( 'title' => 'Assemblea ordinaria per l\'approvazione del bilancio', 'kind' => 'ordinary', 'first_call_at' => gmdate( 'Y-m-d', time() + 20 * 86400 ) . ' 08:00', 'second_call_at' => gmdate( 'Y-m-d', time() + 21 * 86400 ) . ' 18:00', 'place' => 'Sede dell\'associazione', 'agenda' => "1. Bilancio consuntivo\n2. Bilancio preventivo\n3. Varie ed eventuali" ) );
} );
extra_step( 'rimborsi', function () use ( $by_name ) {
	$pid = $by_name( 'Paolo Ferri' );
	$p2  = $by_name( 'Chiara Moretti' );
	if ( $pid ) {
		\AssociazioneSemplice\Refunds::request( $pid, array( 'kind' => 'selfcert', 'expense_date' => gmdate( 'Y-m-d', time() - 9 * 86400 ), 'description' => 'Pedaggio e parcheggio per la gita al lago', 'amount' => '9,00' ) );
	}
	if ( $p2 ) {
		\AssociazioneSemplice\Refunds::request( $p2, array( 'kind' => 'selfcert', 'expense_date' => gmdate( 'Y-m-d', time() - 4 * 86400 ), 'description' => 'Acquisto di materiale di consumo', 'amount' => '6,00' ) );
	}
} );

// Eventi sul sito: le pagine pubbliche degli eventi (descrizione, categoria, locandina) e qualche evento in più nei prossimi mesi.
extra_step( 'eventi sul sito', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	$act  = Plugin::activities();
	$year = Settings::social_year()->label();
	// Le attività di prova create prima portavano l'anno delle tessere («2026») al posto dell'anno sociale («2026/2027»): l'elenco pubblico non le trovava.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . \AssociazioneSemplice\Db::t( 'activities' ) . ' SET social_year = %s WHERE social_year = %s', $year, Settings::membership_year( gmdate( 'Y-m-d' ) )->label() ) ); // phpcs:ignore WordPress.DB
	// Locandina inventata (sfumatura con GD, se c'è); senza GD le pagine restano senza immagine.
	$poster = function ( string $title, array $rgb ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$w = 800; $h = 1200;
		$im = imagecreatetruecolor( $w, $h );
		for ( $y = 0; $y < $h; $y++ ) {
			$k = $y / $h;
			imagefilledrectangle( $im, 0, $y, $w, $y, imagecolorallocate( $im, (int) ( $rgb[0] * ( 1 - $k * .5 ) ), (int) ( $rgb[1] * ( 1 - $k * .5 ) ), (int) ( $rgb[2] * ( 1 - $k * .5 ) ) ) );
		}
		imagefilledellipse( $im, 400, 420, 360, 360, imagecolorallocatealpha( $im, 255, 255, 255, 90 ) );
		$tmp = wp_tempnam( 'locandina.png' );
		imagepng( $im, $tmp );
		imagedestroy( $im );
		$id = media_handle_sideload( array( 'name' => sanitize_title( $title ) . '.png', 'tmp_name' => $tmp ), 0, $title );
		return is_wp_error( $id ) ? 0 : (int) $id;
	};
	$cat = function ( string $name ) {
		$t = term_exists( $name, \AssociazioneSemplice\PublicEvents::TAXONOMY );
		if ( ! $t ) {
			$t = wp_insert_term( $name, \AssociazioneSemplice\PublicEvents::TAXONOMY );
		}
		return is_wp_error( $t ) ? 0 : (int) $t['term_id'];
	};
	$texts = array(
		'Cena sociale'    => array( 'Serate', 'Una cena tutti insieme per ritrovarci, conoscere i nuovi soci e raccontare i progetti dell\'anno. Menù fisso, con opzione vegetariana su richiesta.', array( 205, 92, 60 ) ),
		'Gita al lago'    => array( 'Gite', 'Una giornata all\'aria aperta: passeggiata lungo la riva, pranzo al sacco e giochi per grandi e piccoli. Ritrovo davanti alla sede, si parte in auto condivise.', array( 40, 140, 160 ) ),
		'Concerto di primavera' => array( 'Musica', 'Il coro e la banda dell\'associazione si esibiscono in un concerto a ingresso libero. Prenota il posto per essere sicuro di trovarlo.', array( 120, 80, 170 ) ),
		'Laboratorio di cucina'  => array( 'Laboratori', 'Impariamo a fare la pasta fresca con le nostre cuoche volontarie. Si porta il grembiule, al resto pensiamo noi.', array( 200, 150, 50 ) ),
		'Mercatino solidale'     => array( 'Solidarietà', 'Banchi di libri, giochi e oggetti usati: il ricavato sostiene i progetti dell\'associazione. Ingresso libero.', array( 60, 150, 90 ) ),
	);
	$more = array(
		array( 'Concerto di primavera', 9, '21:00', 'Teatro parrocchiale', 120, 0 ),
		array( 'Laboratorio di cucina', 35, '16:00', 'Sede dell\'associazione', 15, 1800 ),
		array( 'Mercatino solidale', 48, '10:00', 'Piazza del paese', 0, 0 ),
	);
	foreach ( $more as $e ) {
		$session = array( 'session_date' => gmdate( 'Y-m-d', time() + $e[1] * 86400 ), 'start_time' => $e[2], 'location' => $e[3] );
		if ( $e[4] > 0 ) {
			$session['capacity'] = $e[4];
		}
		$act->create( array( 'name' => $e[0], 'social_year' => $year, 'kind' => 'event', 'fee_cents' => $e[5], 'session' => $session ) );
	}
	foreach ( $texts as $name => $t ) {
		foreach ( $act->all_for_select() as $a ) {
			if ( $a['name'] !== $name ) {
				continue;
			}
			$pid = \AssociazioneSemplice\PublicEvents::ensure( (int) $a['id'] );
			if ( ! $pid ) {
				continue;
			}
			$thumb = $poster( $name, $t[2] );
			wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish', 'post_content' => $t[1], 'post_excerpt' => wp_trim_words( $t[1], 18 ) ) );
			wp_set_object_terms( $pid, array( $cat( $t[0] ) ), \AssociazioneSemplice\PublicEvents::TAXONOMY );
			if ( $thumb ) {
				set_post_thumbnail( $pid, $thumb );
			}
		}
	}
	// Pagina «Eventi» con l'elenco a card.
	if ( ! get_page_by_path( 'eventi-in-programma' ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Eventi in programma', 'post_name' => 'eventi-in-programma', 'post_content' => '[associazionesemplice_eventi]' ) );
	}
} );

update_option( 'asem_demo_extra_done', gmdate( 'c' ), false );
WP_CLI::success( 'Dati in più della demo inseriti.' );

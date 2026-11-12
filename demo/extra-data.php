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

/** Locandina inventata (sfumatura con GD, se c'è); senza GD le pagine restano senza immagine. */
function extra_poster( string $title, array $rgb ) {
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
	// Niente controllo del tipo di file di WordPress: su alcuni server il riconoscimento del tipo non funziona e rifiuta anche i PNG appena generati.
	ob_start();
	imagepng( $im );
	$bytes = (string) ob_get_clean();
	imagedestroy( $im );
	$up = wp_upload_bits( sanitize_title( $title ) . '.png', null, $bytes );
	if ( ! empty( $up['error'] ) ) {
		return 0;
	}
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $title, 'post_status' => 'inherit' ), $up['file'] );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $up['file'] ) );
	return (int) $id;
}

/** Testo completo di una pagina evento di prova: presentazione, programma, cosa portare, come arrivare e informazioni utili (tutto inventato). */
function extra_event_html( string $intro, string $category, string $where ): string {
	$prog  = array(
		'Serate'      => array( 'Accoglienza e registrazione dei partecipanti', 'Apertura della serata con il saluto del presidente', 'Momento conviviale e musica', 'Estrazione dei premi e saluti finali' ),
		'Gite'        => array( 'Ritrovo e partenza in auto condivise', 'Passeggiata guidata dai volontari', 'Pranzo al sacco e giochi di gruppo', 'Rientro previsto nel tardo pomeriggio' ),
		'Musica'      => array( 'Apertura porte e accoglienza', 'Prima parte del concerto', 'Breve pausa con rinfresco offerto', 'Seconda parte e bis' ),
		'Laboratori'  => array( 'Presentazione dell\'attività e dei materiali', 'Lavoro a gruppi guidato dai volontari', 'Pausa merenda', 'Risultati e foto di gruppo' ),
		'Solidarietà' => array( 'Allestimento dei banchi e apertura al pubblico', 'Giochi e animazione per i bambini', 'Estrazione della lotteria solidale', 'Chiusura e conteggio del ricavato' ),
	);
	$bring = array(
		'Serate'      => 'Basta la tessera da socio (o il nome con cui hai prenotato). Se hai allergie o intolleranze scrivilo nelle note della prenotazione.',
		'Gite'        => 'Scarpe comode, acqua, cappellino e qualcosa per il pranzo. Per i bambini un cambio e una giacca leggera.',
		'Musica'      => 'Niente: l\'ingresso è con la prenotazione. Se vuoi, porta un cuscino per stare più comodo.',
		'Laboratori'  => 'Un grembiule o dei vestiti che puoi sporcare. Tutto il resto lo mettiamo noi.',
		'Solidarietà' => 'Se vuoi donare libri, giochi o oggetti in buono stato, portali il giorno prima in sede: li prepariamo insieme.',
	);
	$p = $prog[ $category ] ?? $prog['Serate'];
	$h  = '<p>' . esc_html( $intro ) . '</p>';
	$h .= '<h2>Programma</h2><ul>';
	foreach ( $p as $row ) {
		$h .= '<li>' . esc_html( $row ) . '</li>';
	}
	$h .= '</ul><h2>Cosa portare</h2><p>' . esc_html( $bring[ $category ] ?? $bring['Serate'] ) . '</p>';
	$h .= '<h2>Come arrivare</h2><p>' . esc_html( '' !== $where ? 'Il ritrovo è presso: ' . $where . '. ' : '' ) . 'Parcheggio gratuito nelle vie vicine; chi viene a piedi o in bicicletta trova i posti per le bici davanti all\'ingresso. Per chi ha difficoltà di spostamento possiamo organizzare il passaggio: scrivici in anticipo.</p>';
	$h .= '<h2>Informazioni utili</h2><ul><li><strong>Posti limitati:</strong> prenota per essere sicuro di trovare posto; se si riempie puoi metterti in lista d\'attesa.</li><li><strong>Soci e ospiti:</strong> i soci prenotano con un clic dall\'area soci, gli ospiti sono i benvenuti dove indicato nel contributo.</li><li><strong>Disdetta:</strong> se non puoi venire annulla la prenotazione dall\'area soci, così libero il posto a qualcun altro.</li><li><strong>Foto:</strong> durante l\'evento potremmo scattare foto per il racconto dell\'associazione; chi preferisce non comparire può dirlo agli organizzatori.</li></ul>';
	return $h;
}

extra_step( 'eventi sul sito', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	$act  = Plugin::activities();
	$year = Settings::social_year()->label();
	// Le attività di prova create prima portavano l'anno delle tessere («2026») al posto dell'anno sociale («2026/2027»): l'elenco pubblico non le trovava.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . \AssociazioneSemplice\Db::t( 'activities' ) . ' SET social_year = %s WHERE social_year = %s', $year, Settings::membership_year( gmdate( 'Y-m-d' ) )->label() ) ); // phpcs:ignore WordPress.DB
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
	$texts['Concerto d\'autunno']       = array( 'Musica', 'Il coro e la banda dell\'associazione aprono la stagione con un concerto a ingresso libero. Prenota il posto per essere sicuro di trovarlo.', array( 150, 90, 60 ) );
	$texts['Festa di Natale']           = array( 'Serate', 'Panettone, musica e la tombola dei soci: una serata per scambiarci gli auguri e conoscere i nuovi arrivati.', array( 170, 50, 60 ) );
	$texts['Tombolata dell\'Epifania']  = array( 'Serate', 'Cartelle, premi e tanta allegria per grandi e piccoli: la tombola che chiude le feste.', array( 90, 110, 150 ) );
	$texts['Carnevale dei bambini']     = array( 'Laboratori', 'Maschere, coriandoli e giochi per i più piccoli, con merenda offerta dall\'associazione.', array( 220, 100, 140 ) );
	$texts['Gita di primavera']         = array( 'Gite', 'Una giornata in campagna tra passeggiata, pranzo al sacco e giochi all\'aperto. Si parte in auto condivise.', array( 70, 160, 80 ) );
	$texts['Torneo di burraco']         = array( 'Serate', 'Una serata di carte tra soci e amici: iscrizioni aperte, premi per i primi tre.', array( 130, 70, 110 ) );
	$texts['Cena di fine anno sociale'] = array( 'Serate', 'Una cena per chiudere l\'anno insieme, con il bilancio di quanto fatto e le prime idee per il prossimo.', array( 190, 120, 70 ) );
	$texts['Cena di primavera']  = array( 'Serate', 'La cena di primavera dell\'associazione: menù di stagione, musica dal vivo e posti a sedere assegnati all\'arrivo.', array( 110, 150, 90 ) );
	$texts['Gita in montagna']   = array( 'Gite', 'Una camminata facile tra i prati, adatta anche ai bambini, con pranzo al sacco e rientro nel pomeriggio.', array( 60, 120, 140 ) );
	$texts['Concerto d\'estate'] = array( 'Musica', 'Musica all\'aperto sotto le stelle con il coro e la banda dell\'associazione. Ingresso libero, porta un cuscino.', array( 220, 130, 70 ) );
	$texts['Mercatino di Natale'] = array( 'Solidarietà', 'Decorazioni, dolci e idee regalo preparate dai volontari: il ricavato finanzia le attività dell\'anno.', array( 170, 60, 70 ) );
	// Date relative a oggi, ma sempre dentro l'anno sociale (un evento inizia e finisce nello stesso anno sociale): le più lontane si fermano a fine anno.
	$last_day = Settings::social_year()->end()->format( 'Y-m-d' );
	$more     = array(
		array( 'Concerto d\'autunno', 9, '21:00', 'Teatro parrocchiale', 120, 0 ),
		array( 'Laboratorio di cucina', 35, '16:00', 'Sede dell\'associazione', 15, 1800 ),
		array( 'Mercatino solidale', 48, '10:00', 'Piazza del paese', 0, 0 ),
		array( 'Festa di Natale', 40, '20:30', 'Sede dell\'associazione', 80, 1000 ),
		array( 'Tombolata dell\'Epifania', 57, '16:00', 'Sede dell\'associazione', 60, 500 ),
		array( 'Carnevale dei bambini', 95, '15:30', 'Oratorio', 50, 0 ),
		array( 'Gita di primavera', 140, '09:00', 'Ritrovo davanti alla sede', 30, 1200 ),
		array( 'Concerto di primavera', 160, '21:00', 'Teatro parrocchiale', 120, 0 ),
		array( 'Torneo di burraco', 185, '20:30', 'Sede dell\'associazione', 40, 800 ),
		array( 'Cena di fine anno sociale', 230, '20:00', 'Sede dell\'associazione', 70, 2000 ),
		array( 'Cena di primavera', 265, '20:00', 'Sede dell\'associazione', 70, 1800 ),
		array( 'Gita in montagna', 300, '08:00', 'Ritrovo davanti alla sede', 30, 1500 ),
		array( 'Concerto d\'estate', 345, '21:00', 'Giardino pubblico', 150, 0 ),
		array( 'Mercatino di Natale', 380, '10:00', 'Piazza del paese', 0, 0 ),
	);
	foreach ( $more as $e ) {
		$want    = gmdate( 'Y-m-d', time() + $e[1] * 86400 );
		$sy      = $want > $last_day ? Settings::social_year()->next() : Settings::social_year(); // oltre fine anno: anno sociale successivo
		$day     = min( $want, $sy->end()->format( 'Y-m-d' ) );
		$session = array( 'session_date' => $day, 'start_time' => $e[2], 'end_time' => gmdate( 'H:i', (int) strtotime( $e[2] ) + 9000 ), 'location' => $e[3] );
		if ( $e[4] > 0 ) {
			$session['capacity'] = $e[4];
		}
		$act->create( array( 'name' => $e[0], 'social_year' => $sy->label(), 'kind' => 'event', 'fee_cents' => $e[5], 'guest_fee_cents' => $e[5] > 0 ? $e[5] + 500 : null, 'session' => $session ) );
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
			$thumb = extra_poster( $name, $t[2] );
			wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish', 'post_content' => extra_event_html( $t[1], $t[0], \AssociazioneSemplice\PublicEvents::where_text( (array) $act->get( (int) $a['id'] ) ) ), 'post_excerpt' => wp_trim_words( $t[1], 18 ) ) );
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

// Locandine: le pagine degli eventi senza immagine in evidenza ne ricevono una (la prima volta il caricamento era stato rifiutato dal server).
extra_step( 'locandine degli eventi', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	$colors = array( array( 205, 92, 60 ), array( 40, 140, 160 ), array( 120, 80, 170 ), array( 200, 150, 50 ), array( 60, 150, 90 ) );
	$i      = 0;
	foreach ( get_posts( array( 'post_type' => \AssociazioneSemplice\PublicEvents::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 50 ) ) as $p ) {
		if ( ! has_post_thumbnail( $p ) ) {
			$id = extra_poster( get_the_title( $p ), $colors[ $i++ % count( $colors ) ] );
			if ( $id ) {
				set_post_thumbnail( $p, $id );
			}
		}
	}
} );

// Pagina con il calendario mensile degli eventi.
extra_step( 'pagina calendario eventi', function () {
	if ( ! get_page_by_path( 'calendario-eventi' ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Calendario eventi', 'post_name' => 'calendario-eventi', 'post_content' => '[associazionesemplice_eventi modo="mese"]' ) );
	}
} );

// Pagine evento complete: testo con programma, cosa portare, come arrivare e informazioni utili, e contributo ospiti (anche per le pagine create prima).
extra_step( 'testi completi degli eventi', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	global $wpdb;
	$t = \AssociazioneSemplice\Db::t( 'activities' );
	$wpdb->query( "UPDATE $t SET guest_fee_cents = fee_cents + 500 WHERE kind = 'event' AND fee_cents > 0 AND guest_fee_cents IS NULL AND deleted_at IS NULL" ); // phpcs:ignore WordPress.DB
	foreach ( get_posts( array( 'post_type' => \AssociazioneSemplice\PublicEvents::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 100 ) ) as $p ) {
		if ( false !== strpos( (string) $p->post_content, '<h2>' ) ) {
			continue;
		}
		$aid   = \AssociazioneSemplice\PublicEvents::activity_of( (int) $p->ID );
		$a     = $aid ? Plugin::activities()->get( $aid ) : null;
		$terms = get_the_terms( $p, \AssociazioneSemplice\PublicEvents::TAXONOMY );
		if ( ! $a ) {
			continue;
		}
		$intro = trim( wp_strip_all_tags( (string) $p->post_content ) );
		wp_update_post( array( 'ID' => $p->ID, 'post_content' => extra_event_html( $intro, ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->name : 'Serate', \AssociazioneSemplice\PublicEvents::where_text( $a ) ) ) );
	}
} );

// Un evento ricorrente (serie settimanale) e uno annullato, per vedere come si comportano elenco, calendario e pagina.
extra_step( 'evento ricorrente e annullato', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	$act  = Plugin::activities();
	$sy   = Settings::social_year();
	$from = gmdate( 'Y-m-d', time() + 3 * 86400 );
	$to   = min( gmdate( 'Y-m-d', time() + 100 * 86400 ), $sy->end()->format( 'Y-m-d' ) );
	$term = function ( string $name ) {
		$t = term_exists( $name, \AssociazioneSemplice\PublicEvents::TAXONOMY );
		if ( ! $t ) {
			$t = wp_insert_term( $name, \AssociazioneSemplice\PublicEvents::TAXONOMY );
		}
		return is_wp_error( $t ) ? 0 : (int) $t['term_id'];
	};
	$publish = function ( int $aid, string $title, string $cat, string $intro, array $rgb ) use ( $term ) {
		$pid = \AssociazioneSemplice\PublicEvents::ensure( $aid );
		if ( ! $pid ) {
			return;
		}
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish', 'post_content' => extra_event_html( $intro, $cat, \AssociazioneSemplice\PublicEvents::where_text( (array) Plugin::activities()->get( $aid ) ) ), 'post_excerpt' => wp_trim_words( $intro, 18 ) ) );
		wp_set_object_terms( $pid, array( $term( $cat ) ), \AssociazioneSemplice\PublicEvents::TAXONOMY );
		$thumb = extra_poster( $title, $rgb );
		if ( $thumb ) {
			set_post_thumbnail( $pid, $thumb );
		}
	};
	$rec = $act->create( array( 'name' => 'Serata giochi settimanale', 'social_year' => $sy->label(), 'kind' => 'recurring', 'fee_cents' => 0, 'guest_fee_cents' => 300 ) );
	$act->generate_weekly( (int) $rec, $from, $to, '21:00', 'Sede dell\'associazione', 30 );
	$publish( (int) $rec, 'Serata giochi settimanale', 'Serate', 'Ogni settimana ci si ritrova in sede per giochi da tavolo e carte: nessuna iscrizione fissa, prenoti la serata che ti interessa.', array( 90, 80, 160 ) );
	$can = $act->create( array( 'name' => 'Gita al castello', 'social_year' => $sy->label(), 'kind' => 'event', 'fee_cents' => 1200, 'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + 20 * 86400 ), 'start_time' => '09:00', 'location' => 'Ritrovo davanti alla sede', 'capacity' => 30 ) ) );
	$publish( (int) $can, 'Gita al castello', 'Gite', 'Una visita guidata al castello con pranzo al sacco nel parco. Purtroppo la guida non è disponibile e la gita è stata annullata.', array( 150, 90, 90 ) );
	foreach ( $act->sessions( (int) $can ) as $s ) {
		$act->cancel_session( (int) $s['id'] );
	}
} );

update_option( 'asem_demo_extra_done', gmdate( 'c' ), false );
WP_CLI::success( 'Dati in più della demo inseriti.' );

// Menu e pagina iniziale: chi prova deve arrivare alle pagine pubbliche degli eventi (locandina, descrizione, altre date, modulo per i non soci).
extra_step( 'menu con gli eventi', function () {
	$nav = get_page_by_path( 'menu-demo', OBJECT, 'wp_navigation' );
	$ev  = get_page_by_path( 'eventi-in-programma' );
	$cal = get_page_by_path( 'calendario-eventi' );
	if ( ! $nav || ! $ev ) {
		return;
	}
	$item = function ( string $label, string $url ) {
		return '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => $label, 'url' => $url, 'kind' => 'custom' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' /-->';
	};
	$content = (string) $nav->post_content;
	$new     = $item( 'Eventi', get_permalink( $ev ) ) . ( $cal ? $item( 'Calendario', get_permalink( $cal ) ) : '' );
	$content = str_replace( 'Attività ed eventi', 'Corsi e attività', $content );
	wp_update_post( array( 'ID' => $nav->ID, 'post_content' => wp_slash( $new . $content ) ) );
	$home = get_page_by_path( 'demo' ) ?: get_post( (int) get_option( 'page_on_front' ) );
	if ( $home ) { // il pulsante della pagina iniziale porta all'elenco a schede
		$html = (string) $home->post_content;
		$old  = get_permalink( get_page_by_path( 'attivita-ed-eventi' ) );
		if ( $old ) {
			$html = str_replace( array( $old . '"', '>Attività ed eventi<' ), array( get_permalink( $ev ) . '"', '>Eventi in programma<' ), $html );
			wp_update_post( array( 'ID' => $home->ID, 'post_content' => wp_slash( $html ) ) );
		}
	}
} );

// WhatsApp della segreteria: il pulsante fisso e quello nelle pagine degli eventi compaiono solo con un numero (qui un numero di prova, non attivo).
extra_step( 'whatsapp della segreteria', function () {
	$s = (array) get_option( 'asem_settings', array() );
	if ( empty( $s['secretary_phone'] ) ) {
		$s['secretary_phone'] = '+39 320 000 0000';
		update_option( 'asem_settings', $s );
	}
} );

// Il corso ha la sua pagina pubblica (orario settimanale, sede, come iscriversi) e compare nel calendario con le sue lezioni.
extra_step( 'pagina del corso di fotografia', function () {
	if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
		return;
	}
	$act = Plugin::activities();
	$aid = (int) \AssociazioneSemplice\Db::db()->get_var( 'SELECT id FROM ' . \AssociazioneSemplice\Db::t( 'activities' ) . " WHERE name = 'Corso di fotografia' AND kind = 'course' ORDER BY id LIMIT 1" );
	if ( ! $aid ) {
		return;
	}
	$act->update( $aid, array( 'lesson_weekday' => 2, 'lesson_start' => '18:30', 'lesson_end' => '20:00', 'location' => 'Sede dell\'associazione' ) );
	$pid = \AssociazioneSemplice\PublicEvents::ensure( $aid );
	if ( ! $pid ) {
		return;
	}
	$intro = 'Un corso per imparare a fotografare con qualsiasi macchina, anche il telefono: composizione, luce, ritratto e un po\' di fotoritocco. Si fa una lezione a settimana, il martedì sera; le uscite fotografiche si concordano insieme.';
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish', 'post_content' => extra_event_html( $intro, 'Corsi', 'Sede dell\'associazione' ), 'post_excerpt' => wp_trim_words( $intro, 18 ) ) );
	$t = term_exists( 'Corsi', \AssociazioneSemplice\PublicEvents::TAXONOMY );
	if ( ! $t ) {
		$t = wp_insert_term( 'Corsi', \AssociazioneSemplice\PublicEvents::TAXONOMY );
	}
	if ( ! is_wp_error( $t ) ) {
		wp_set_object_terms( $pid, array( (int) $t['term_id'] ), \AssociazioneSemplice\PublicEvents::TAXONOMY );
	}
	$thumb = extra_poster( 'Corso di fotografia', array( 60, 90, 130 ) );
	if ( $thumb ) {
		set_post_thumbnail( $pid, $thumb );
	}
} );

// Un esempio dei testi a piacere: la pagina «Eventi in programma» diventa «I nostri prossimi eventi», con il messaggio per quando non c'è nulla.
extra_step( 'testi a piacere della pagina eventi', function () {
	$page = get_page_by_path( 'eventi-in-programma' );
	if ( ! $page ) {
		return;
	}
	wp_update_post( array( 'ID' => $page->ID, 'post_title' => 'I nostri prossimi eventi', 'post_content' => '[associazionesemplice_eventi vuoto="Presto nuovi appuntamenti: torna a trovarci."]' ) );
} );

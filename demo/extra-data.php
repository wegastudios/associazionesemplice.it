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

update_option( 'asem_demo_extra_done', gmdate( 'c' ), false );
WP_CLI::success( 'Dati in più della demo inseriti.' );

<?php
/**
 * Tanti dati per la demo (associazionesemplice.it/demo): serve da esempio per chi valuta il prodotto e da prova di carico.
 * Si AGGIUNGE ai dati di provision.php ed extra-data.php, non cancella niente e non azzera la demo.
 * Ogni passo è ripetibile: se lo script si interrompe, rilanciandolo si ripetono solo i passi rimasti (stesso segnaposto di extra-data.php).
 * Si lancia con: wp eval-file molti-dati.php --user=amministratore    (dopo extra-data.php)
 *
 * TUTTI I DATI SONO INVENTATI: nomi da elenchi comuni combinati a caso, email @demo.invalid, numeri 06 556xxxx di fantasia, nessun codice fiscale.
 * Le date sono relative a oggi (niente anni scritti a mano). Non crea anni chiusi, non compila i dati dell'ente.
 * Le ricevute definitive e gli allegati cifrati si aggiungono solo dopo il rilascio che li porta (vedi docs/DEMO_DATI.md).
 */
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

/** Quantità: cambiano solo la mole dei dati (si possono ridurre per una prova veloce con: MOLTI_SCALA=0.2 wp eval-file …). */
$scala = (float) ( getenv( 'MOLTI_SCALA' ) ?: 1 );
$q = function ( int $n ) use ( $scala ): int { return max( 1, (int) round( $n * $scala ) ); };

if ( ! function_exists( 'molti_step' ) ) {
	/** Un passo riuscito si ricorda; rilanciando si ripetono solo quelli rimasti. Scrive anche quanto ci ha messo. */
	function molti_step( string $what, callable $fn ) {
		$done = (array) get_option( 'asem_demo_extra_steps', array() );
		if ( in_array( $what, $done, true ) ) {
			WP_CLI::log( '  già fatto: ' . $what );
			return null;
		}
		$t0 = microtime( true );
		try {
			$r = $fn();
			$done = (array) get_option( 'asem_demo_extra_steps', array() );
			$done[] = $what;
			update_option( 'asem_demo_extra_steps', $done, false );
			WP_CLI::log( sprintf( '  ok %s (%.1f s)', $what, microtime( true ) - $t0 ) );
			return $r;
		} catch ( \Throwable $e ) {
			WP_CLI::warning( $what . ': ' . $e->getMessage() );
			return null;
		}
	}
}

$people = Plugin::people();
$ledger = Plugin::ledger();
$act    = Plugin::activities();
$cur    = Settings::membership_year( gmdate( 'Y-m-d' ) );
$prev   = $cur->previous()->label();
$prev2  = $cur->previous()->previous()->label();
$T0     = microtime( true );

// Sequenza pseudo-casuale fissa: ogni caricamento dà gli stessi dati (utile per confrontare i tempi e per le prove).
mt_srand( 20261115 );
$pick = function ( array $a ) { return $a[ mt_rand( 0, count( $a ) - 1 ) ]; };

$first = array( 'Aurora', 'Leonardo', 'Sofia', 'Francesco', 'Ginevra', 'Edoardo', 'Vittoria', 'Mattia', 'Chiara', 'Pietro', 'Emma', 'Filippo', 'Arianna', 'Samuele', 'Greta', 'Michele', 'Bianca', 'Claudio', 'Agnese', 'Davide', 'Ludovica', 'Fabio', 'Rebecca', 'Giacomo', 'Marta', 'Cristian', 'Serena', 'Manuel', 'Irene', 'Jacopo', 'Ornella', 'Massimo', 'Nadia', 'Renato', 'Lucia', 'Ettore', 'Teresa', 'Vincenzo', 'Carla', 'Domenico', 'Patrizia', 'Sergio', 'Anna', 'Luigi', 'Rita', 'Paolo', 'Elena', 'Franco', 'Laura', 'Mario' );
$last  = array( 'Conti', 'De Angelis', 'Costa', 'Fontana', 'Caruso', 'Ferrari', 'Bianchi', 'Moretti', 'Rinaldi', 'Silvestri', 'Villa', 'Guidi', 'Palumbo', 'Monti', 'Orlando', 'Bellini', 'Santoro', 'Parisi', 'Marchetti', 'Cattaneo', 'Piras', 'Sanna', 'Serra', 'Melis', 'Pinna', 'Cocco', 'Ferri', 'Neri', 'Gentile', 'Basile', 'Coppola', 'D\'Amico', 'Mariani', 'Rossetti', 'Benedetti', 'Carbone', 'Fiore', 'Farina', 'Messina', 'Sartori', 'Valente', 'Battaglia', 'Pagano', 'Riva', 'Donati', 'Landi', 'Zanetti', 'Pace', 'Grasso', 'Amato' );
$city  = array( 'Roma', 'Roma', 'Roma', 'Frascati', 'Tivoli', 'Ciampino', 'Marino', 'Grottaferrata', 'Albano Laziale', 'Guidonia' );

// Chi c'è già (per non fare doppioni se lo script si interrompe a metà o se un nome esiste già)
$existing = array();
foreach ( $people->search() as $p ) {
	$existing[ $p['first_name'] . ' ' . $p['last_name'] ] = (int) $p['id'];
}

// 1. Soci: tessere in regola (con quota in prima nota), scadute, sospese, senza tessera e ospiti; iscritti da più anni
molti_step( 'molti soci', function () use ( $people, $ledger, $cur, $prev, $prev2, $q, $pick, $first, $last, $city, &$existing ) {
	$cash = $ledger->default_account_for( 'cash' );
	$bank = $ledger->default_account_for( 'bank' ) ?: $cash;
	$cat  = $ledger->category_id_of_kind( 'membership' );
	$n    = $q( 200 );
	$made = 0;
	$host = 0;
	for ( $i = 0; $made < $n && $i < $n * 4; $i++ ) {
		$f = $pick( $first );
		$l = $pick( $last );
		if ( isset( $existing[ $f . ' ' . $l ] ) ) {
			continue;
		}
		$state = $made % 20; // 0-11 in regola (60%), 12-15 scaduti, 16 sospeso, 17-18 senza tessera, 19 ospite
		$type  = 19 === $state ? 'guest' : ( 0 === $made % 9 ? 'volunteer' : 'ordinary' );
		$args  = array(
			'type' => $type, 'first_name' => $f, 'last_name' => $l,
			'email' => 0 === $made % 3 ? strtolower( preg_replace( '/[^a-z]/i', '', $f . '.' . $l ) ) . $made . '@demo.invalid' : null,
			'phone' => '+39 06 556' . sprintf( '%04d', 4000 + $made * 17 ), 'city' => $pick( $city ), 'province' => 'RM',
			'joined_on' => gmdate( 'Y-m-d', time() - ( 40 + ( $made * 13 ) % 900 ) * 86400 ),
		);
		if ( 'guest' !== $type ) {
			$args['card_number'] = $people->next_free_card();
		} else {
			$args['host_person_id'] = $host; // un ospite è sempre collegato al socio che lo ospita
		}
		$id = $people->create( $args );
		$existing[ $f . ' ' . $l ] = (int) $id;
		if ( 'guest' !== $type ) {
			$host = (int) $id;
		}
		$made++;
		if ( $state <= 11 && $cat && $cash ) {
			$bk = 0 === $made % 2;
			$ledger->record_receipt( array(
				'date' => gmdate( 'Y-m-d', time() - ( 1 + ( $made * 7 ) % 78 ) * 86400 ), 'account_id' => (int) ( $bk ? $bank['id'] : $cash['id'] ), 'method' => $bk ? 'bank_transfer' : 'cash', 'person_id' => $id,
				'lines' => array( array( 'category_id' => $cat, 'amount_cents' => 2000, 'description' => 'Quota associativa' ) ),
			) );
			if ( 0 === $made % 3 ) {
				$people->set_membership( $id, $prev, true ); // socio anche l'anno scorso
				if ( 0 === $made % 6 ) {
					$people->set_membership( $id, $prev2, true ); // e due anni fa
				}
			}
		} elseif ( $state <= 15 ) {
			$people->set_membership( $id, $prev, true );
		}
		if ( 16 === $state ) {
			$people->suspend( $id );
		}
	}
} );

// 2. Prima nota: ogni mese dell'anno fiscale scorso e di quello in corso, entrate (corsi, offerte, eventi, quote) e spese (affitto, bollette, forniture).
// Le spese di ogni mese seguono le entrate dello stesso mese (poco meno), nella seconda metà del mese, e i contanti incassati (20%) superano quelli spesi (15%):
// così ogni conto resta sopra zero in ogni momento. L'ultimo mese dell'anno fiscale scorso porta il risultato al disavanzo voluto (-160 €).
molti_step( 'prima nota abbondante', function () use ( $ledger, $q, $pick ) {
	$cash = $ledger->default_account_for( 'cash' );
	$bank = $ledger->default_account_for( 'bank' ) ?: $cash;
	if ( ! $cash ) {
		return;
	}
	$cur_fy = \AssociazioneSemplice\FiscalYears::current();
	$prev_fy = $cur_fy - 1;
	$r      = \AssociazioneSemplice\FiscalYears::range( $prev_fy );
	$start  = new \DateTimeImmutable( $r[0] );
	$last_m = substr( $r[1], 0, 7 ); // ultimo mese dell'anno fiscale scorso
	$today  = gmdate( 'Y-m-d' );
	$in     = array(
		array( 'activity_fee', 'Contributi del corso', 2500, 9000 ), array( 'donation', 'Offerta di un socio', 1000, 12000 ), array( 'activity_fee', 'Iscrizione all\'evento', 800, 3000 ),
		array( 'other_income', 'Incasso del banchetto', 2000, 15000 ), array( 'donation', 'Offerta alla serata', 1500, 6000 ),
	);
	$out = array(
		array( 'general_cost', 'Bolletta della luce' ), array( 'general_cost', 'Affitto della sala' ), array( 'activity_cost', 'Materiale per il corso' ),
		array( 'general_cost', 'Pulizie della sede' ), array( 'activity_cost', 'Compenso per l\'istruttore' ), array( 'general_cost', 'Cancelleria e stampe' ),
	);
	$per = max( 2, $q( 40 ) ); // entrate (e spese) al mese
	$d   = $start;
	while ( $d->format( 'Y-m-d' ) <= $today ) {
		$ym  = $d->format( 'Y-m' );
		$dim = (int) $d->format( 't' );
		$sum = 0;
		for ( $k = 0; $k < $per; $k++ ) {
			$t   = $pick( $in );
			$cat = $ledger->category_id_of_kind( $t[0] );
			$day = sprintf( '%s-%02d', $ym, 1 + ( $k * 3 ) % min( 14, $dim ) ); // entrate nella prima metà del mese
			if ( $cat && $day <= $today ) {
				$bk  = ( $k % 10 ) >= 2; // 80% in banca, 20% in contanti
				$amt = mt_rand( $t[2], $t[3] );
				$ledger->record_receipt( array( 'date' => $day, 'account_id' => (int) ( $bk ? $bank['id'] : $cash['id'] ), 'method' => $bk ? 'bank_transfer' : 'cash', 'lines' => array( array( 'category_id' => $cat, 'amount_cents' => $amt, 'description' => $t[1] ) ) ) );
				$sum += $amt;
			}
		}
		// Quanto spendere nel mese: 99% delle entrate; nell'ultimo mese dell'anno fiscale scorso quanto basta per chiudere a -160 €.
		$budget = (int) round( $sum * 0.99 );
		if ( $ym === $last_m ) {
			$budget = max( 0, (int) \AssociazioneSemplice\Statement::live( $prev_fy )['result']['cur'] + 16000 );
		}
		if ( $budget > 0 ) {
			$w = array();
			for ( $k = 0; $k < $per; $k++ ) {
				$w[] = mt_rand( 20, 100 );
			}
			$wt = array_sum( $w );
			foreach ( $w as $k => $wk ) {
				$s   = $pick( $out );
				$cat = $ledger->category_id_of_kind( $s[0] );
				$day = sprintf( '%s-%02d', $ym, 15 + ( $k * 2 ) % max( 1, min( 13, $dim - 15 ) ) ); // spese nella seconda metà del mese
				$amt = (int) round( $budget * $wk / $wt );
				if ( $cat && $amt > 0 && $day <= $today ) {
					$bk = ( $k % 20 ) >= 3 || $ym === $start->format( 'Y-m' ); // 15% delle spese in contanti (le entrate in contanti sono il 20%): i contanti non scendono mai sotto zero
					$ledger->record_expense( array( 'date' => $day, 'account_id' => (int) ( $bk ? $bank['id'] : $cash['id'] ), 'method' => $bk ? 'bank_transfer' : 'cash', 'category_id' => $cat, 'amount_cents' => $amt, 'description' => $s[1] ) );
				}
			}
		}
		$d = $d->modify( 'first day of next month' );
	}
} );

// 3. Corsi con iscritti e eventi con prenotazioni
molti_step( 'corsi ed eventi con iscritti', function () use ( $act, $people, $q, $pick ) {
	$year  = Settings::social_year()->label();
	$ids   = array();
	foreach ( $people->search() as $p ) {
		if ( 'guest' !== ( $p['type'] ?? '' ) ) {
			$ids[] = (int) $p['id'];
		}
	}
	if ( count( $ids ) < 5 ) {
		return;
	}
	$month   = gmdate( 'Y-m' );
	$courses = array( 'Yoga dolce', 'Acquerello', 'Inglese conversazione', 'Teatro amatoriale', 'Chitarra per principianti', 'Ginnastica per tutti', 'Cucito creativo', 'Fotoritocco', 'Cucina regionale', 'Ballo liscio' );
	foreach ( array_slice( $courses, 0, $q( 10 ) ) as $i => $name ) {
		$cid = $act->create( array( 'name' => $name, 'social_year' => $year, 'kind' => 'course', 'fee_cents' => 1500 + ( $i % 4 ) * 500, 'lesson_weekday' => 1 + $i % 6, 'lesson_start' => '18:' . ( 0 === $i % 2 ? '00' : '30' ), 'lesson_end' => '19:' . ( 0 === $i % 2 ? '30' : '00' ), 'location' => 'Sede dell\'associazione' ) );
		for ( $k = 0, $n = 12 + ( $i * 3 ) % 14; $k < $n; $k++ ) {
			try {
				$act->enroll( (int) $cid, $pick( $ids ), $month );
			} catch ( \Throwable $e ) { // già iscritto: va bene
			}
		}
	}
	$last_day = Settings::social_year()->end()->format( 'Y-m-d' );
	$events   = array( 'Serata di quiz', 'Torneo di calcetto', 'Aperitivo sociale', 'Visita al museo', 'Cena della befana', 'Lezione aperta di yoga', 'Mostra di fotografia', 'Pomeriggio di giochi', 'Cineforum', 'Pranzo di quartiere', 'Corsa solidale', 'Festa dei nonni' );
	foreach ( array_slice( $events, 0, $q( 12 ) ) as $i => $name ) {
		$day = min( gmdate( 'Y-m-d', time() + ( 5 + $i * 11 ) * 86400 ), $last_day );
		$aid = $act->create( array(
			'name' => $name, 'social_year' => $year, 'kind' => 'event', 'fee_cents' => 0 === $i % 3 ? 0 : 500 + ( $i % 5 ) * 300,
			'session' => array( 'session_date' => $day, 'start_time' => '20:00', 'location' => 'Sede dell\'associazione', 'capacity' => 30 + ( $i % 4 ) * 20 ),
		) );
		foreach ( $act->sessions( (int) $aid ) as $s ) {
			for ( $k = 0, $n = 10 + ( $i * 4 ) % 25; $k < $n; $k++ ) {
				try {
					$act->book( (int) $s['id'], $pick( $ids ) );
				} catch ( \Throwable $e ) { // già prenotato o al completo: va bene
				}
			}
		}
	}
} );

// 4. Risorse prenotabili (Pro): più sale e campi, con prenotazioni nei prossimi giorni
molti_step( 'risorse e prenotazioni', function () use ( $people, $q, $pick ) {
	if ( ! \AssociazioneSemplice\Edition::has( 'resources' ) ) {
		return;
	}
	$ids = array();
	foreach ( $people->search() as $p ) {
		if ( 'guest' !== ( $p['type'] ?? '' ) ) {
			$ids[] = (int) $p['id'];
		}
	}
	$res = array();
	foreach ( array( array( 'Campo di calcetto', 'court' ), array( 'Sala prove', 'room' ), array( 'Salone grande', 'room' ), array( 'Campo da padel', 'court' ), array( 'Aula di pittura', 'room' ), array( 'Palestrina', 'room' ) ) as $r ) {
		$res[] = (int) \AssociazioneSemplice\Resources::save( 0, array( 'name' => $r[0], 'category' => $r[1], 'mode' => 'slot', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 3, 'fee_cents' => 'court' === $r[1] ? 1000 : 500, 'advance_days' => 45 ) );
	}
	$booked = 0;
	for ( $i = 0; $i < $q( 150 ) * 3 && $booked < $q( 150 ); $i++ ) {
		try {
			\AssociazioneSemplice\Resources::book_slot( $pick( $res ), $pick( $ids ), gmdate( 'Y-m-d', time() + mt_rand( 1, 40 ) * 86400 ), sprintf( '%02d:00', mt_rand( 8, 21 ) ), 1 );
			$booked++;
		} catch ( \Throwable $e ) { // fascia già occupata: ne provo un'altra
		}
	}
} );

// 5. Rimborsi, certificati medici, inventario e prestiti
molti_step( 'rimborsi, certificati e inventario', function () use ( $people, $q, $pick ) {
	$ids = array();
	foreach ( $people->search() as $p ) {
		if ( 'guest' !== ( $p['type'] ?? '' ) ) {
			$ids[] = (int) $p['id'];
		}
	}
	$what = array( 'Pedaggio e parcheggio', 'Materiale di consumo', 'Biglietti del treno per la gita', 'Cancelleria', 'Carburante per il trasporto', 'Acqua e bibite' );
	for ( $i = 0; $i < $q( 15 ); $i++ ) {
		\AssociazioneSemplice\Refunds::request( $pick( $ids ), array( 'kind' => 'selfcert', 'expense_date' => gmdate( 'Y-m-d', time() - mt_rand( 1, 50 ) * 86400 ), 'description' => $pick( $what ), 'amount' => sprintf( '%d,%02d', mt_rand( 3, 9 ), mt_rand( 0, 99 ) ) ) ); // sotto 10 €: autocertificazione
	}
	if ( class_exists( '\AssociazioneSemplice\Certificates' ) ) {
		for ( $i = 0; $i < $q( 40 ); $i++ ) {
			$exp = mt_rand( -60, 340 );
			try {
				\AssociazioneSemplice\Certificates::add( $pick( $ids ), array( 'kind' => 0 === $i % 3 ? 'agonistic' : 'non_agonistic', 'issued_on' => gmdate( 'Y-m-d', time() + ( $exp - 365 ) * 86400 ), 'expires_on' => gmdate( 'Y-m-d', time() + $exp * 86400 ), 'doctor' => 'Dott. Esempio, Ambulatorio di fantasia' ) );
			} catch ( \Throwable $e ) { // già presente: va bene
			}
		}
	}
	$items = array();
	foreach ( array( 'Gazebo', 'Microfono', 'Tavolo pieghevole', 'Palloni', 'Tappetini', 'Lavagna', 'Prolunga', 'Cavalletto', 'Fornello', 'Tenda', 'Bandiere', 'Panche', 'Stufa', 'Scala', 'Faro' ) as $i => $n ) {
		$items[] = (int) \AssociazioneSemplice\Inventory::save_item( 0, array( 'name' => $n, 'quantity' => 1 + ( $i * 3 ) % 12, 'state' => 'good', 'location' => 'Magazzino della sede' ) );
	}
	for ( $i = 0; $i < $q( 8 ); $i++ ) {
		try {
			\AssociazioneSemplice\Inventory::lend( $items[ $i ], $pick( $ids ), 1, gmdate( 'Y-m-d', time() - mt_rand( 1, 20 ) * 86400 ), gmdate( 'Y-m-d', time() + mt_rand( -5, 20 ) * 86400 ), 'Per un\'attività' );
		} catch ( \Throwable $e ) {
		}
	}
} );

// 6. Quadratura finale (si esegue sempre): l'anno fiscale scorso resta in disavanzo e la cassa non va sotto zero
$prev_fy = \AssociazioneSemplice\FiscalYears::current() - 1;
$st      = \AssociazioneSemplice\Statement::live( $prev_fy );
$res     = (int) $st['result']['cur'];
$target  = -16000; // disavanzo voluto di 160 €: mostra il controllo (decisione del titolare, 15/11/2026)
$range   = \AssociazioneSemplice\FiscalYears::range( $prev_fy );
$when    = ( new \DateTimeImmutable( $range['to'] ?? $range[1] ?? gmdate( 'Y-m-d' ) ) )->modify( '-9 days' )->format( 'Y-m-d' );
$bank    = $ledger->default_account_for( 'bank' ) ?: $ledger->default_account_for( 'cash' );
if ( $res !== $target && $bank ) {
	if ( $res > $target ) { // troppo avanzo: una spesa di manutenzione porta il risultato al disavanzo voluto
		$cat = $ledger->category_id_of_kind( 'general_cost' );
		$ledger->record_expense( array( 'date' => $when, 'account_id' => (int) $bank['id'], 'method' => 'bank_transfer', 'category_id' => $cat, 'amount_cents' => $res - $target, 'description' => 'Manutenzione straordinaria della sede' ) );
	} else { // troppo disavanzo: un contributo
		$cat = $ledger->category_id_of_kind( 'donation' );
		$ledger->record_receipt( array( 'date' => $when, 'account_id' => (int) $bank['id'], 'method' => 'bank_transfer', 'lines' => array( array( 'category_id' => $cat, 'amount_cents' => $target - $res, 'description' => 'Contributo straordinario di un sostenitore' ) ) ) );
	}
	$st = \AssociazioneSemplice\Statement::live( $prev_fy );
}
if ( (int) $st['result']['cur'] >= 0 ) {
	WP_CLI::warning( 'L\'anno fiscale scorso non è in disavanzo.' );
}
$neg = 0;
foreach ( $ledger->balances() as $b ) {
	if ( (int) $b['balance'] < 0 ) {
		$neg++;
		WP_CLI::warning( 'Il conto «' . $b['name'] . '» è sotto zero.' );
	}
}
// Nessun conto sotto zero in nessun giorno (non solo alla fine): saldo corrente con il saldo iniziale del conto
global $wpdb;
$t_tx  = \AssociazioneSemplice\Db::t( 'transactions' );
$t_acc = \AssociazioneSemplice\Db::t( 'accounts' );
foreach ( (array) $wpdb->get_results( "SELECT a.name, a.opening_cents + MIN(x.run) AS lowest FROM $t_acc a JOIN (SELECT account_id, SUM(CASE WHEN type = 'income' THEN amount_cents ELSE -amount_cents END) OVER (PARTITION BY account_id ORDER BY tx_date, id) AS run FROM $t_tx WHERE voided_at IS NULL) x ON x.account_id = a.id GROUP BY a.id, a.name, a.opening_cents", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
	if ( (int) $r['lowest'] < 0 ) {
		$neg++;
		WP_CLI::warning( 'Il conto «' . $r['name'] . '» scende sotto zero in qualche giorno (minimo ' . ( (int) $r['lowest'] / 100 ) . ' €).' );
	}
}
if ( (int) $st['closing_total'] < 0 ) {
	WP_CLI::warning( 'Cassa negativa alla fine dell\'anno fiscale scorso.' );
}
WP_CLI::success( sprintf( 'Molti dati inseriti in %.1f s (persone %d, movimenti %d, conti sotto zero %d).', microtime( true ) - $T0, count( $people->search() ), (int) \AssociazioneSemplice\Db::db()->get_var( 'SELECT COUNT(*) FROM ' . \AssociazioneSemplice\Db::t( 'transactions' ) . ' WHERE voided_at IS NULL' ), $neg ) );

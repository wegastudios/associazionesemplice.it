<?php
/**
 * Misure di velocità (workflow «Misure di velocità», solo in CI, dentro wp-env: mai su un sito vero).
 *   wp eval-file wp-content/sandbox-scripts/misure-velocita.php dati [scala]    crea i dati di prova e misura pagine, esportazioni, ricerche, eventi, testi
 *   wp eval-file wp-content/sandbox-scripts/misure-velocita.php excel [scala]   misura la lettura di fogli Excel grandi (processo a parte: se la memoria finisce, il resto è già misurato)
 * I dati sono inventati e ripetibili (sequenza pseudo-casuale fissa; le date sono relative a oggi). Scrive una tabella in Markdown (le righe che iniziano con «|»)
 * e le righe «@@URL|etichetta|indirizzo» con le pagine pubbliche che il workflow misura poi con curl.
 * Fallisce solo se il generatore o una pagina va in errore; i tempi non fanno mai fallire il job.
 */
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

$mode  = isset( $args[0] ) ? (string) $args[0] : 'dati';
$scala = isset( $args[1] ) ? max( 0.05, (float) $args[1] ) : 1.0;

wp_set_current_user( 1 );
Settings::update( array( 'license_key' => 'SANDBOX-0001' ) ); // funzioni Pro accese (licenza di prova in «standby»), come nelle altre prove in sandbox
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;
$GLOBALS['m_rows'] = array();
$GLOBALS['m_err']  = array();
$GLOBALS['m_warn'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( ! ( error_reporting() & $no ) ) {
			return false;
		}
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/associazionesemplice' ) ) {
			$GLOBALS['m_warn'][ $str . ' (' . basename( $file ) . ':' . $line . ')' ] = true;
		}
		return false;
	}
);

/** Un passo del generatore: scrive quanto ci ha messo. */
function m_log( string $s ): void {
	WP_CLI::log( $s );
}

/**
 * Misura una voce: la prima esecuzione (a freddo) e le successive (si tiene la migliore); query e memoria sono quelle della prima.
 * Il contenuto scritto in uscita si raccoglie (una pagina vuota è un errore).
 *
 * @return string ultimo contenuto scritto in uscita
 */
function m_run( string $label, callable $fn, int $reps = 3, bool $page = true ): string {
	global $wpdb;
	$first = null;
	$best  = null;
	$q     = 0;
	$mem   = 0;
	$out   = '';
	$lvl   = ob_get_level();
	for ( $i = 0; $i < $reps; $i++ ) {
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$q0 = $wpdb->num_queries;
		$t0 = microtime( true );
		try {
			ob_start();
			$ret = $fn();
			$out = (string) ob_get_clean();
			if ( ! $page && is_string( $ret ) ) {
				$out = $ret;
			}
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $lvl ) {
				ob_end_clean();
			}
			$GLOBALS['m_err'][] = $label . ': ' . get_class( $e ) . ' ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			return '';
		}
		$ms = ( microtime( true ) - $t0 ) * 1000;
		if ( 0 === $i ) {
			$first = $ms;
			$q     = $wpdb->num_queries - $q0;
			$mem   = memory_get_peak_usage();
		}
		$best = null === $best ? $ms : min( $best, $ms );
	}
	if ( $page && strlen( $out ) < 100 ) {
		$GLOBALS['m_err'][] = $label . ': la pagina è vuota o quasi (' . strlen( $out ) . ' byte)';
	}
	if ( $page && preg_match( '/(Fatal error|Uncaught|critical error)/i', $out ) ) {
		$GLOBALS['m_err'][] = $label . ': la pagina contiene un errore';
	}
	$GLOBALS['m_rows'][] = array( $label, $first, $best, $q, $mem, strlen( $out ) );
	return $out;
}

/** Una pagina di amministrazione con i suoi parametri nell'indirizzo. */
function m_page( string $label, callable $page, array $get = array(), int $reps = 3 ): string {
	return m_run(
		$label,
		function () use ( $page, $get ) {
			$old  = $_GET;
			$_GET = $get;
			try {
				$page();
			} finally {
				$_GET = $old;
			}
		},
		$reps
	);
}

function m_table(): void {
	m_log( '' );
	m_log( '| Voce | 1ª volta (ms) | Migliore (ms) | Query | Memoria di picco (MB) | Byte in uscita | Nota |' );
	m_log( '|---|---:|---:|---:|---:|---:|---|' );
	foreach ( $GLOBALS['m_rows'] as $r ) {
		$slow = ( $r[2] > 300 || $r[3] > 100 ) ? 'lenta' : '';
		m_log( sprintf( '| %s | %d | %d | %d | %.1f | %s | %s |', $r[0], round( $r[1] ), round( $r[2] ), $r[3], $r[4] / 1048576, $r[5] ? number_format( $r[5], 0, ',', '.' ) : '', $slow ) );
		if ( $r[2] > 1500 || $r[3] > 400 ) { // soglia larga: solo un avviso, mai un errore (i runner sono variabili)
			m_log( sprintf( '::warning title=Voce molto lenta::%s: %d ms (migliore), %d query', $r[0], round( $r[2] ), $r[3] ) );
		}
	}
	m_log( '' );
	if ( $GLOBALS['m_warn'] ) {
		m_log( 'Avvisi PHP dal plugin durante le misure (non bloccanti): ' . count( $GLOBALS['m_warn'] ) );
		foreach ( array_slice( array_keys( $GLOBALS['m_warn'] ), 0, 10 ) as $w ) {
			m_log( '  - ' . $w );
		}
	}
	if ( $GLOBALS['m_err'] ) {
		m_log( 'ERRORI:' );
		foreach ( $GLOBALS['m_err'] as $e ) {
			m_log( '  - ' . $e );
		}
		WP_CLI::error( count( $GLOBALS['m_err'] ) . ' errori: generatore o pagine non andati a buon fine.' );
	}
}

// ---------------------------------------------------------------------------------------------------------------------------------------------
if ( 'excel' === $mode ) {
	m_excel( $scala );
	m_table();
	return;
}

// ---------- Generatore ----------
$T0     = microtime( true );
$people = Plugin::people();
$ledger = Plugin::ledger();
$act    = Plugin::activities();
mt_srand( 20261117 ); // sequenza fissa: stessi dati a ogni esecuzione
$q = function ( int $n ) use ( $scala ): int {
	return max( 1, (int) round( $n * $scala ) );
};
$pick = function ( array $a ) {
	return $a[ mt_rand( 0, count( $a ) - 1 ) ];
};
m_log( sprintf( 'Edizione: reports=%s resources=%s funds=%s; scala %.2f', Edition::has( 'reports' ) ? 'si' : 'NO', Edition::has( 'resources' ) ? 'si' : 'NO', Edition::has( 'funds' ) ? 'si' : 'NO', $scala ) );

$first = array( 'Aurora', 'Leonardo', 'Sofia', 'Francesco', 'Ginevra', 'Edoardo', 'Vittoria', 'Mattia', 'Chiara', 'Pietro', 'Emma', 'Filippo', 'Arianna', 'Samuele', 'Greta', 'Michele', 'Bianca', 'Claudio', 'Agnese', 'Davide', 'Ludovica', 'Fabio', 'Rebecca', 'Giacomo', 'Marta', 'Cristian', 'Serena', 'Manuel', 'Irene', 'Jacopo', 'Ornella', 'Massimo', 'Nadia', 'Renato', 'Lucia', 'Ettore', 'Teresa', 'Vincenzo', 'Carla', 'Domenico', 'Patrizia', 'Sergio', 'Anna', 'Luigi', 'Rita', 'Paolo', 'Elena', 'Franco', 'Laura', 'Mario' );
$last  = array( 'Conti', 'De Angelis', 'Costa', 'Fontana', 'Caruso', 'Ferrari', 'Bianchi', 'Moretti', 'Rinaldi', 'Silvestri', 'Villa', 'Guidi', 'Palumbo', 'Monti', 'Orlando', 'Bellini', 'Santoro', 'Parisi', 'Marchetti', 'Cattaneo', 'Piras', 'Sanna', 'Serra', 'Melis', 'Pinna', 'Cocco', 'Ferri', 'Neri', 'Gentile', 'Basile', 'Coppola', 'D\'Amico', 'Mariani', 'Rossetti', 'Benedetti', 'Carbone', 'Fiore', 'Farina', 'Messina', 'Sartori', 'Valente', 'Battaglia', 'Pagano', 'Riva', 'Donati', 'Landi', 'Zanetti', 'Pace', 'Grasso', 'Amato' );
$city  = array( 'Roma', 'Roma', 'Roma', 'Frascati', 'Tivoli', 'Ciampino', 'Marino', 'Grottaferrata', 'Albano Laziale', 'Guidonia' );

$today   = gmdate( 'Y-m-d' );
$fy      = FiscalYears::current();
$fy_r    = FiscalYears::range( $fy );
$pfy_r   = FiscalYears::range( $fy - 1 );
$cur     = Settings::membership_year( $today );
$prev    = $cur->previous()->label();
$cash    = $ledger->default_account_for( 'cash' );
$bank    = $ledger->default_account_for( 'bank' ) ?: $cash;
$cat_mem = $ledger->category_id_of_kind( 'membership' );
if ( ! $cash || ! $cat_mem ) {
	WP_CLI::error( 'Conti o categorie iniziali mancanti: il plugin non è installato bene.' );
}
$cur_from = $fy_r[0] <= $today ? $fy_r[0] : $today; // inizio dell'anno fiscale in corso (mai nel futuro)
$day_in   = function ( string $from, string $to, int $k ) use ( $today ): string { // un giorno tra $from e $to (mai oltre oggi), deterministico
	$to   = min( $to, $today );
	$span = max( 0, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / 86400 ) );
	return gmdate( 'Y-m-d', strtotime( $from ) + ( $span ? ( $k * 7919 ) % ( $span + 1 ) : 0 ) * 86400 );
};

// 1. Soci (2.000): in regola, scaduti, sospesi, senza tessera, ospiti, volontari
$t       = microtime( true );
$n_ppl   = $q( 2000 );
$ids     = array(); // soci veri (non ospiti)
$cards   = array();
$phones  = array();
$host    = 0;
$pay_cur = array(); // chi paga la quota nell'anno in corso
$pay_prv = array();
$unpaid  = array();
for ( $i = 0; $i < $n_ppl; $i++ ) {
	$state = $i % 20; // 0-11 in regola, 12-15 scaduti, 16 sospeso, 17-18 senza tessera, 19 ospite
	$type  = 19 === $state ? 'guest' : ( 0 === $i % 9 ? 'volunteer' : 'ordinary' );
	$f     = $first[ $i % 50 ];
	$l     = $last[ intdiv( $i, 50 ) % 50 ];
	$phone = sprintf( '333%07d', 1000000 + $i * 7 );
	$args2 = array(
		'type' => $type, 'first_name' => $f, 'last_name' => $l,
		'email' => 0 === $i % 3 ? strtolower( preg_replace( '/[^a-z]/i', '', $f . '.' . $l ) ) . $i . '@demo.invalid' : null,
		'phone' => $phone, 'city' => $pick( $city ), 'province' => 'RM',
		'joined_on' => gmdate( 'Y-m-d', time() - ( 40 + ( $i * 13 ) % 900 ) * 86400 ),
	);
	if ( 'guest' !== $type ) {
		$args2['card_number'] = (string) ( 1000 + $i ); // numero scelto qui: next_free_card() rilegge tutte le tessere a ogni persona
	} else {
		$args2['host_person_id'] = $host;
	}
	try {
		$id = (int) $people->create( $args2 );
	} catch ( \Throwable $e ) {
		$GLOBALS['m_err'][] = 'generatore persone (' . $i . '): ' . $e->getMessage();
		break;
	}
	if ( 'guest' !== $type ) {
		$host   = $id;
		$ids[]  = $id;
		$cards[ $id ] = (string) ( 1000 + $i );
		if ( $state <= 11 ) {
			$pay_cur[] = $id;
		}
		if ( $state <= 11 && 0 === $i % 3 ) {
			$pay_prv[] = $id;
		} elseif ( $state > 11 && $state <= 15 ) {
			$people->set_membership( $id, $prev, true );
		}
		if ( 17 === $state ) {
			$unpaid[] = $id; // senza tessera: serve alla misura dell'incasso
		}
		if ( 16 === $state ) {
			$people->suspend( $id );
		}
	}
	$phones[ $id ] = $phone;
}
m_log( sprintf( 'Soci creati: %d (%.1f s)', count( $phones ), microtime( true ) - $t ) );

// 2. Quote associative registrate in prima nota (anno in corso e anno scorso)
$t      = microtime( true );
$q_made = 0;
foreach ( array( array( $pay_cur, $cur_from, $today ), array( $pay_prv, $pfy_r[0], $pfy_r[1] ) ) as $set ) {
	foreach ( $set[0] as $k => $pid ) {
		$bk = 0 === $k % 2;
		try {
			$ledger->record_receipt( array(
				'date' => $day_in( $set[1], $set[2], $k ), 'account_id' => (int) ( $bk ? $bank['id'] : $cash['id'] ), 'method' => $bk ? 'bank_transfer' : 'cash', 'person_id' => $pid,
				'lines' => array( array( 'category_id' => $cat_mem, 'amount_cents' => 2000, 'description' => 'Quota associativa' ) ),
			) );
			$q_made++;
		} catch ( \Throwable $e ) {
			if ( $q_made < 5 ) {
				m_log( 'quota non registrata: ' . $e->getMessage() );
			}
		}
	}
}
if ( $q_made < count( $pay_cur ) / 2 ) {
	$GLOBALS['m_err'][] = 'generatore quote: registrate solo ' . $q_made . ' su ' . ( count( $pay_cur ) + count( $pay_prv ) );
}
m_log( sprintf( 'Quote registrate: %d (%.1f s)', $q_made, microtime( true ) - $t ) );

// 3. Prima nota: altri movimenti (entrate e uscite di più voci), scritti a blocchi come farebbe un'importazione
$t     = microtime( true );
$kinds_in  = array( 'activity_fee', 'donation', 'other_income' );
$kinds_out = array( 'general_cost', 'activity_cost' );
$cat_in  = array();
$cat_out = array();
foreach ( $kinds_in as $k ) {
	$cat_in[] = $ledger->category_id_of_kind( $k );
}
foreach ( $kinds_out as $k ) {
	$cat_out[] = $ledger->category_id_of_kind( $k );
}
$cat_in  = array_values( array_filter( $cat_in ) );
$cat_out = array_values( array_filter( $cat_out ) );
$acc     = array( (int) $cash['id'], (int) $bank['id'] );
$tx_t    = Db::t( 'transactions' );
$now     = current_time( 'mysql', true );
$bulk    = function ( string $from, string $to, int $n_in, int $n_out ) use ( $wpdb, $tx_t, $now, $cat_in, $cat_out, $acc, $ids, $day_in, $cash, $bank ) {
	$vals = array();
	$flush = function () use ( &$vals, $wpdb, $tx_t ) {
		if ( $vals ) {
			$wpdb->query( 'INSERT INTO ' . $tx_t . ' (tx_date, type, amount_cents, account_id, method, category_id, person_id, description, created_by, created_at) VALUES ' . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB
			$vals = array();
		}
	};
	for ( $k = 0; $k < $n_in + $n_out; $k++ ) {
		$in   = $k < $n_in;
		$bk   = 0 !== $k % 5;
		$amt  = $in ? 1000 + ( ( $k * 37 ) % 9000 ) : 300 + ( ( $k * 53 ) % 2500 ); // le uscite restano più piccole delle entrate: nessun conto sotto zero
		$pid  = $in && 0 === $k % 3 ? $ids[ ( $k * 31 ) % count( $ids ) ] : null;
		$cat  = $in ? $cat_in[ $k % count( $cat_in ) ] : $cat_out[ $k % count( $cat_out ) ];
		$vals[] = sprintf( "('%s','%s',%d,%d,'%s',%d,%s,'%s',1,'%s')", $day_in( $from, $to, $k ), $in ? 'income' : 'expense', $amt, $bk ? $bank['id'] : $cash['id'], $bk ? 'bank_transfer' : 'cash', $cat, $pid ? (int) $pid : 'NULL', $in ? 'Incasso di prova' : 'Spesa di prova', $now ); // valori tutti interni (numeri e date calcolati qui)
		if ( count( $vals ) >= 400 ) {
			$flush();
		}
	}
	$flush();
};
$bulk( $pfy_r[0], $pfy_r[1], $q( 1800 ), $q( 800 ) );
$bulk( $cur_from, $today, $q( 900 ), $q( 400 ) );
m_log( sprintf( 'Movimenti di prima nota in blocco (%.1f s)', microtime( true ) - $t ) );

// 4. Ricevute: qualche centinaio di numeri già emessi (i PDF si fissano solo aprendo la ricevuta)
$t   = microtime( true );
$rx  = (array) $wpdb->get_results( 'SELECT id, tx_date FROM ' . $tx_t . " WHERE type = 'income' AND person_id IS NOT NULL AND receipt_id IS NULL ORDER BY id LIMIT " . $q( 300 ), ARRAY_A ); // phpcs:ignore WordPress.DB
$rcp = 0;
foreach ( $rx as $r ) {
	try {
		\AssociazioneSemplice\Receipts::number_for( 'tx' . (int) $r['id'], (string) $r['tx_date'] );
		$rcp++;
	} catch ( \Throwable $e ) {
		if ( $rcp < 3 ) {
			m_log( 'ricevuta non numerata: ' . $e->getMessage() );
		}
	}
}
m_log( sprintf( 'Ricevute numerate: %d (%.1f s)', $rcp, microtime( true ) - $t ) );

// 5. Corsi con iscritti ed eventi con prenotazioni
$t      = microtime( true );
$year   = Settings::social_year()->label();
$last_d = Settings::social_year()->end()->format( 'Y-m-d' );
$month  = gmdate( 'Y-m' );
$courses = array();
$names   = array( 'Yoga dolce', 'Acquerello', 'Inglese conversazione', 'Teatro amatoriale', 'Chitarra per principianti', 'Ginnastica per tutti', 'Cucito creativo', 'Fotoritocco', 'Cucina regionale', 'Ballo liscio' );
$enr     = 0;
for ( $i = 0; $i < $q( 30 ); $i++ ) {
	try {
		$cid = (int) $act->create( array( 'name' => $names[ $i % 10 ] . ' ' . chr( 65 + intdiv( $i, 10 ) ), 'social_year' => $year, 'kind' => 'course', 'fee_cents' => 1500 + ( $i % 4 ) * 500, 'lesson_weekday' => 1 + $i % 6, 'lesson_start' => '18:' . ( 0 === $i % 2 ? '00' : '30' ), 'lesson_end' => '19:' . ( 0 === $i % 2 ? '30' : '00' ), 'location' => 'Sede dell\'associazione' ) );
	} catch ( \Throwable $e ) {
		$GLOBALS['m_err'][] = 'generatore corsi: ' . $e->getMessage();
		break;
	}
	$courses[] = $cid;
	for ( $k = 0, $n = $q( 60 ); $k < $n; $k++ ) {
		try {
			$act->enroll( $cid, $ids[ ( $i * 97 + $k * 13 ) % count( $ids ) ], $month );
			$enr++;
		} catch ( \Throwable $e ) { // già iscritto: va bene
		}
	}
}
$events = array();
$book   = 0;
$ev_nm  = array( 'Serata di quiz', 'Torneo di calcetto', 'Aperitivo sociale', 'Visita al museo', 'Cena della befana', 'Lezione aperta di yoga', 'Mostra di fotografia', 'Pomeriggio di giochi', 'Cineforum', 'Pranzo di quartiere', 'Corsa solidale', 'Festa dei nonni' );
for ( $i = 0; $i < $q( 40 ); $i++ ) {
	try {
		$aid = (int) $act->create( array(
			'name' => $ev_nm[ $i % 12 ] . ' ' . ( 1 + intdiv( $i, 12 ) ), 'social_year' => $year, 'kind' => 'event', 'fee_cents' => 0 === $i % 3 ? 0 : 500 + ( $i % 5 ) * 300,
			'session' => array( 'session_date' => min( gmdate( 'Y-m-d', time() + ( 3 + $i * 5 ) * 86400 ), $last_d ), 'start_time' => '20:00', 'location' => 'Sede dell\'associazione', 'capacity' => 60 ),
		) );
	} catch ( \Throwable $e ) {
		$GLOBALS['m_err'][] = 'generatore eventi: ' . $e->getMessage();
		break;
	}
	$events[] = $aid;
	foreach ( $act->sessions( $aid ) as $s ) {
		for ( $k = 0, $n = $q( 20 ); $k < $n; $k++ ) {
			try {
				$act->book( (int) $s['id'], $ids[ ( $i * 61 + $k * 17 ) % count( $ids ) ] );
				$book++;
			} catch ( \Throwable $e ) { // già prenotato o al completo: va bene
			}
		}
	}
}
// pagine pubbliche degli eventi e di qualche corso: pubblicate
$pub = 0;
foreach ( array_merge( $events, array_slice( $courses, 0, 10 ) ) as $aid ) {
	$pid = \AssociazioneSemplice\PublicEvents::ensure( $aid );
	if ( $pid && 'publish' !== get_post_status( $pid ) ) {
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
		$pub++;
	}
}
m_log( sprintf( 'Corsi %d (%d iscrizioni), eventi %d (%d prenotazioni), pagine pubbliche %d (%.1f s)', count( $courses ), $enr, count( $events ), $book, $pub, microtime( true ) - $t ) );

// 6. Pagine del sito con i codici brevi (le misura il workflow con curl)
$mk = function ( string $title, string $content ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content ) );
	return $id && ! is_wp_error( $id ) ? (string) get_permalink( $id ) : '';
};
$urls = array(
	'Sito: pagina senza il plugin'                     => $mk( 'Pagina semplice', '<p>Una pagina di prova senza codici brevi.</p>' ),
	'Sito: elenco eventi pubblici'                     => $mk( 'Eventi', '[associazionesemplice_eventi]' ),
	'Sito: calendario eventi pubblici'                 => $mk( 'Calendario eventi', '[associazionesemplice_calendario_eventi]' ),
	'Sito: prossimi eventi (pubblico)'                 => $mk( 'Prossimi eventi', '[associazionesemplice_prossimi_eventi]' ),
	'Sito: elenco attività (pubblico)'                 => $mk( 'Attività', '[associazionesemplice_attivita]' ),
	'Sito: domanda di adesione online (pubblico)'      => $mk( 'Adesione', '[associazionesemplice_adesione]' ),
);
$evp = get_posts( array( 'post_type' => 'asem_evento', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( $evp ) {
	$urls['Sito: pagina di un evento'] = (string) get_permalink( $evp[0] );
}
$urls['Sito: home'] = home_url( '/' );
foreach ( $urls as $label => $u ) {
	if ( '' === $u ) {
		$GLOBALS['m_err'][] = 'pagina del sito non creata: ' . $label;
		continue;
	}
	m_log( '@@URL|' . $label . '|' . $u );
}
m_log( sprintf( 'Generatore finito in %.1f s: persone %d, movimenti %d, iscrizioni %d, ricevute %d.', microtime( true ) - $T0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tx_t ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'receipts' ) ) ) );
if ( $GLOBALS['m_err'] ) {
	m_table();
}
$mid   = $ids[ (int) ( count( $ids ) / 2 ) ];
$mcard = $cards[ $mid ];
$mphone = $phones[ $mid ];
$course_id = $courses ? $courses[0] : 0;
$ev_id     = $events ? $events[0] : 0;

// ---------- Misure: elenco soci ----------
$P = array( 'AssociazioneSemplice\\Admin\\PeoplePage', 'render_list' );
m_page( 'Rubrica soci: prima pagina', $P, array( 'page' => 'asem-people' ) );
m_page( 'Rubrica soci: pagina interna (n. 20)', $P, array( 'page' => 'asem-people', 'paged' => '20' ) );
m_page( 'Rubrica soci: ultima pagina', $P, array( 'page' => 'asem-people', 'paged' => '999' ) );
m_page( 'Rubrica soci: filtro «tessera valida», prima pagina', $P, array( 'page' => 'asem-people', 'status' => 'active' ) );
m_page( 'Rubrica soci: filtro «tessera valida», pagina interna (n. 15)', $P, array( 'page' => 'asem-people', 'status' => 'active', 'paged' => '15' ) );
m_page( 'Rubrica soci: filtro «scaduta o senza tessera»', $P, array( 'page' => 'asem-people', 'status' => 'expired' ) );
m_page( 'Rubrica soci: filtro «sospesi»', $P, array( 'page' => 'asem-people', 'status' => 'suspended' ) );
m_page( 'Rubrica soci: filtro «non è ancora entrato nell\'area soci»', $P, array( 'page' => 'asem-people', 'status' => 'noaccess' ) );
m_page( 'Rubrica soci: filtro «senza consenso privacy»', $P, array( 'page' => 'asem-people', 'status' => 'noconsent' ) );
m_page( 'Rubrica soci: solo ospiti', $P, array( 'page' => 'asem-people', 'type' => 'guest' ) );
m_page( 'Rubrica soci: ospiti da invitare a iscriversi', $P, array( 'page' => 'asem-people', 'at_limit' => '1' ) );
m_page( 'Rubrica soci: ordinata per nome', $P, array( 'page' => 'asem-people', 'orderby' => 'name', 'order' => 'desc' ) );
$html_rubrica = m_page( 'Rubrica soci: ricerca per cognome', $P, array( 'page' => 'asem-people', 'q' => 'Fontana' ) );
m_page( 'Rubrica soci: ricerca per numero di tessera', $P, array( 'page' => 'asem-people', 'q' => $mcard ) );
m_page( 'Rubrica soci: ricerca per indirizzo email', $P, array( 'page' => 'asem-people', 'q' => 'aurora.conti' ) );
m_page( 'Scheda di una persona', array( 'AssociazioneSemplice\\Admin\\PeoplePage', 'render_edit' ), array( 'page' => 'asem-person', 'id' => (string) $mid ) );
m_page( 'Libro soci', array( 'AssociazioneSemplice\\Admin\\RegistersPage', 'render_book' ), array( 'page' => 'asem-book' ) );

// ---------- Ricerche per tessera e per telefono (come nei moduli pubblici e nel banco) ----------
m_run( 'Ricerca di una persona per numero di tessera', function () use ( $people, $mcard, $mid ) {
	$r = $people->find_by_card( $mcard );
	if ( ! $r || (int) $r['id'] !== $mid ) {
		throw new \RuntimeException( 'tessera non trovata' );
	}
}, 5, false );
m_run( 'Ricerca di una persona per telefono', function () use ( $people, $mphone, $mid ) {
	$r = $people->find_by_phone( $mphone );
	if ( ! $r || (int) $r[0]['id'] !== $mid ) {
		throw new \RuntimeException( 'telefono non trovato' );
	}
}, 5, false );
m_run( 'Ricerca per telefono scritto con prefisso (+39 e spazi)', function () use ( $people, $mphone ) {
	$people->find_by_phone( '+39 ' . substr( $mphone, 0, 3 ) . ' ' . substr( $mphone, 3 ) );
}, 5, false );
m_run( 'Ricerca per telefono: nessun risultato', function () use ( $people ) {
	$people->find_by_phone( '3999999999' );
}, 5, false );

// ---------- Bacheca, cassa, prima nota ----------
m_page( 'Bacheca (schede e contatori)', array( 'AssociazioneSemplice\\Admin\\DashboardPage', 'render' ), array( 'page' => 'asem' ) );
m_page( 'Cassa', array( 'AssociazioneSemplice\\Admin\\MoneyPage', 'render' ), array( 'page' => 'asem-money' ) );
$L = array( 'AssociazioneSemplice\\Admin\\LedgerPage', 'render' );
m_page( 'Prima nota: prima pagina (anno in corso) con totali', $L, array( 'page' => 'asem-ledger', 'year' => (string) $fy ) );
m_page( 'Prima nota: pagina interna (n. 10)', $L, array( 'page' => 'asem-ledger', 'year' => (string) $fy, 'paged' => '10' ) );
m_page( 'Prima nota: anno scorso, prima pagina', $L, array( 'page' => 'asem-ledger', 'year' => (string) ( $fy - 1 ) ) );
m_page( 'Prima nota: anno scorso, pagina interna (n. 20)', $L, array( 'page' => 'asem-ledger', 'year' => (string) ( $fy - 1 ), 'paged' => '20' ) );
m_run( 'Prima nota: totali di un anno (conteggio, entrate, uscite)', function () use ( $ledger, $pfy_r ) {
	$ledger->totals( $pfy_r[0], $pfy_r[1] );
}, 5, false );
m_run( 'Saldi dei conti', function () use ( $ledger ) {
	$ledger->balances();
}, 5, false );

// ---------- Rendiconto e report ----------
$S = array( 'AssociazioneSemplice\\Admin\\ProRegisters', 'render_statement' );
if ( ! class_exists( $S[0] ) ) {
	$GLOBALS['m_err'][] = 'Le funzioni Pro non sono accese: manca ' . $S[0];
} else {
	m_page( 'Rendiconto: anno in corso', $S, array( 'page' => 'asem-statement', 'year' => (string) $fy ) );
	m_page( 'Rendiconto: anno scorso', $S, array( 'page' => 'asem-statement', 'year' => (string) ( $fy - 1 ) ) );
}
m_run( 'Rendiconto: solo il calcolo, anno in corso', function () use ( $fy ) {
	\AssociazioneSemplice\Statement::live( $fy );
}, 3, false );
m_run( 'Rendiconto: solo il calcolo, anno scorso', function () use ( $fy ) {
	\AssociazioneSemplice\Statement::live( $fy - 1 );
}, 3, false );
if ( class_exists( 'AssociazioneSemplice\\Admin\\ReportsPage' ) ) {
	m_page( 'Report', array( 'AssociazioneSemplice\\Admin\\ReportsPage', 'render' ), array( 'page' => 'asem-reports' ) );
}
m_page( 'Contabilità (riepilogo)', array( 'AssociazioneSemplice\\Admin\\AccountingPage', 'render' ), array( 'page' => 'asem-accounting' ) );

// ---------- Ricevute ----------
m_page( 'Verifica ricevute (sola apertura della pagina)', array( 'AssociazioneSemplice\\Admin\\ReceiptCheckPage', 'render' ), array( 'page' => 'asem-receipt-check', 'year' => (string) $fy ) );
m_page( 'Verifica ricevute: anno scorso', array( 'AssociazioneSemplice\\Admin\\ReceiptCheckPage', 'render' ), array( 'page' => 'asem-receipt-check', 'year' => (string) ( $fy - 1 ) ) );

// ---------- Esportazioni ----------
m_run( 'Esportazione CSV dei soci', function () {
	$r = \AssociazioneSemplice\Admin\Exports::people();
	return $r[1];
}, 2, false );
m_run( 'Esportazione CSV della prima nota (anno in corso)', function () use ( $fy_r ) {
	$r = \AssociazioneSemplice\Admin\Exports::ledger( $fy_r[0], $fy_r[1] );
	return $r[1];
}, 2, false );
m_run( 'Esportazione CSV della prima nota (anno scorso)', function () use ( $pfy_r ) {
	$r = \AssociazioneSemplice\Admin\Exports::ledger( $pfy_r[0], $pfy_r[1] );
	return $r[1];
}, 2, false );

// ---------- Attività, calendario, eventi ----------
m_page( 'Corsi ed eventi: elenco', array( 'AssociazioneSemplice\\Admin\\ActivitiesPage', 'render_list' ), array( 'page' => 'asem-activities' ) );
if ( $course_id ) {
	m_page( 'Scheda di un corso con ' . $q( 60 ) . ' iscritti', array( 'AssociazioneSemplice\\Admin\\ActivitiesPage', 'render_detail' ), array( 'page' => 'asem-activity', 'id' => (string) $course_id ) );
}
if ( $ev_id ) {
	m_page( 'Scheda di un evento con ' . $q( 20 ) . ' prenotazioni', array( 'AssociazioneSemplice\\Admin\\ActivitiesPage', 'render_detail' ), array( 'page' => 'asem-activity', 'id' => (string) $ev_id ) );
}
m_page( 'Calendario di corsi ed eventi (mese)', array( 'AssociazioneSemplice\\Admin\\CalendarPage', 'render' ), array( 'page' => 'asem-calendar' ) );
m_run( 'Calendario: occorrenze dei prossimi 120 giorni', function () use ( $today ) {
	\AssociazioneSemplice\Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +120 days' ) ) );
}, 3, false );
m_run( 'Calendario: file .ics di tutte le attività', function () {
	return \AssociazioneSemplice\Calendar::ics();
}, 2, false );
m_run( 'Eventi pubblici: elenco a card', function () {
	return \AssociazioneSemplice\PublicEvents::list_html( array( 'limite' => '30', 'filtri' => 'si' ) );
}, 3, false );
m_run( 'Eventi pubblici: calendario del mese', function () {
	return \AssociazioneSemplice\PublicEvents::list_html( array( 'modo' => 'mese', 'filtri' => 'si' ) );
}, 3, false );
m_run( 'Eventi pubblici: elenco, 100 eventi alla volta', function () {
	return \AssociazioneSemplice\PublicEvents::list_html( array( 'limite' => '100', 'filtri' => 'no' ) );
}, 3, false );
m_run( 'Pagine evento: controllo delle attività senza pagina all\'avvio', function () {
	\AssociazioneSemplice\BootState::set( 'events_backfill', '' ); // come un sito che non l'ha ancora fatto: riguarda fino a 400 attività
	\AssociazioneSemplice\PublicEvents::backfill();
}, 1, false );
m_run( 'Creazione di un evento (con la sua pagina pubblica)', function () use ( $act, $year, $last_d ) {
	$act->create( array( 'name' => 'Evento misurato', 'social_year' => $year, 'kind' => 'event', 'fee_cents' => 500, 'session' => array( 'session_date' => $last_d, 'start_time' => '20:00', 'location' => 'Sede', 'capacity' => 30 ) ) );
}, 1, false );
m_run( 'Iscrizione di un socio a un corso', function () use ( $act, $course_id, $ids, $month ) {
	$act->enroll( $course_id, $ids[ count( $ids ) - 3 ], $month );
}, 1, false );
m_run( 'Registrazione di un incasso (quota di un socio)', function () use ( $ledger, $unpaid, $bank, $cat_mem, $today ) {
	$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $bank['id'], 'method' => 'bank_transfer', 'person_id' => $unpaid[0], 'lines' => array( array( 'category_id' => $cat_mem, 'amount_cents' => 2000, 'description' => 'Quota misurata' ) ) ) );
}, 1, false );
m_page( 'Registro delle azioni', array( 'AssociazioneSemplice\\Admin\\AuditPage', 'render' ), array( 'page' => 'asem-audit' ) );

// ---------- Motore dei testi: tutta la pagina sostituita nodo per nodo ----------
if ( class_exists( 'AssociazioneSemplice\\TextsEngine' ) && '' !== $html_rubrica ) {
	$TE   = 'AssociazioneSemplice\\TextsEngine';
	ob_start(); // una pagina più grande: elenco soci + prima nota
	$old_get = $_GET;
	$_GET    = array( 'page' => 'asem-ledger', 'year' => (string) $fy );
	\AssociazioneSemplice\Admin\LedgerPage::render();
	$_GET = $old_get;
	$page = $html_rubrica . (string) ob_get_clean();
	m_log( sprintf( 'Pagina per la prova dei testi: %d byte', strlen( $page ) ) );
	$reset = function () use ( $TE ) {
		$TE::flush();
		\AssociazioneSemplice\Terms::flush();
	};
	$reset();
	m_run( 'Testi: elenco dei testi personalizzabili (a freddo, senza copia salvata)', function () use ( $TE ) {
		return (string) count( $TE::catalog() );
	}, 1, false );
	m_run( 'Testi: elenco dei testi personalizzabili (con la copia salvata)', function () use ( $TE ) {
		return (string) count( $TE::catalog() );
	}, 2, false );
	$reset();
	m_run( 'Testi: pagina con tutto di serie (nessuna sostituzione)', function () use ( $TE, $page ) {
		return $TE::html( $page );
	}, 3, false );
	// lingua inglese (pacchetto incluso)
	Settings::update( array( 'language' => 'en' ) );
	$reset();
	m_run( 'Testi: pagina in inglese (pacchetto della lingua)', function () use ( $TE, $page ) {
		return $TE::html( $page );
	}, 3, false );
	// inglese + tipo di ente e termini cambiati + 400 testi personalizzati
	Settings::update( array( 'entity_type' => 'comitato', 'member_term' => 'iscritto' ) );
	$ov = array();
	foreach ( array_slice( $TE::catalog(), 0, 400 ) as $c ) {
		$ov[ $c['text'] ] = $c['text'] . ' ·';
	}
	$TE::save_overrides( $ov );
	$reset();
	m_run( 'Testi: pagina con lingua, tipo di ente e 400 testi personalizzati', function () use ( $TE, $page ) {
		return $TE::html( $page );
	}, 3, false );
	m_run( 'Testi: una frase di un messaggio (400 testi personalizzati)', function () use ( $TE ) {
		return $TE::plain( 'Il socio ha pagato la quota dell\'associazione.' );
	}, 5, false );
	// si rimette tutto com'era
	$TE::save_overrides( array() );
	Settings::update( array( 'language' => 'it', 'entity_type' => 'associazione', 'member_term' => 'socio' ) );
	$reset();
}

m_table();
m_log( sprintf( 'Misure finite in %.1f s (generatore compreso).', microtime( true ) - $T0 ) );

// ---------------------------------------------------------------------------------------------------------------------------------------------
/** Lettura di fogli Excel grandi (e dello stesso elenco in CSV, per confronto). */
function m_excel( float $scala ): void {
	$dir = get_temp_dir() . 'asem-misure';
	wp_mkdir_p( $dir );
	$make = function ( string $path, int $rows ) {
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Soci" sheetId="1" r:id="rId1"/></sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
		$cell = function ( string $ref, string $v ) {
			return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars( $v, ENT_XML1 ) . '</t></is></c>';
		};
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$xml .= '<row r="1">' . $cell( 'A1', 'Nome' ) . $cell( 'B1', 'Cognome' ) . $cell( 'C1', 'Email' ) . $cell( 'D1', 'Telefono' ) . $cell( 'E1', 'Città' ) . '</row>';
		for ( $i = 0; $i < $rows; $i++ ) {
			$r    = $i + 2;
			$xml .= '<row r="' . $r . '">' . $cell( 'A' . $r, 'Nome' . $i ) . $cell( 'B' . $r, 'Importato' . $i ) . $cell( 'C' . $r, 'importato' . $i . '@demo.invalid' ) . $cell( 'D' . $r, sprintf( '347%07d', 2000000 + $i ) ) . $cell( 'E' . $r, 'Roma' ) . '</row>';
		}
		$xml .= '</sheetData></worksheet>';
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml );
		$zip->close();
	};
	$f5  = $dir . '/soci-5000.xlsx';
	$f19 = $dir . '/soci-19500.xlsx';
	$fc  = $dir . '/soci-5000.csv';
	$n5  = max( 50, (int) round( 4900 * $scala ) );
	$n19 = max( 100, (int) round( 19400 * $scala ) );
	$make( $f5, $n5 );
	$make( $f19, $n19 );
	$csv = "Nome;Cognome;Email;Telefono;Città\n";
	for ( $i = 0; $i < $n5; $i++ ) {
		$csv .= 'Nome' . $i . ';Importato' . $i . ';importato' . $i . '@demo.invalid;' . sprintf( '347%07d', 2000000 + $i ) . ";Roma\n";
	}
	file_put_contents( $fc, $csv );
	m_log( sprintf( 'Fogli di prova: %d righe (%d KB), %d righe (%d KB), CSV %d righe (%d KB)', $n5, filesize( $f5 ) / 1024, $n19, filesize( $f19 ) / 1024, $n5, filesize( $fc ) / 1024 ) );
	m_run( 'Importazione: lettura di un foglio Excel di ' . $n5 . ' righe', function () use ( $f5 ) {
		$s = \AssociazioneSemplice\Xlsx::read( $f5 );
		if ( count( $s[0]['rows'] ) < 100 ) {
			throw new \RuntimeException( 'foglio letto male' );
		}
	}, 2, false );
	m_run( 'Importazione: anteprima di un foglio Excel di ' . $n5 . ' righe (con 2.000 soci già presenti)', function () use ( $f5 ) {
		$p = \AssociazioneSemplice\ImportService::preview_file( $f5, 'soci.xlsx' );
		if ( empty( $p['people']['plan'] ) ) {
			throw new \RuntimeException( 'anteprima vuota' );
		}
	}, 1, false );
	m_run( 'Importazione: lettura dello stesso elenco in CSV (' . $n5 . ' righe)', function () use ( $fc ) {
		$s = \AssociazioneSemplice\SheetReader::read( $fc, 'soci.csv' );
		if ( count( $s[0]['rows'] ) < 100 ) {
			throw new \RuntimeException( 'CSV letto male' );
		}
	}, 2, false );
	m_run( 'Importazione: lettura di un foglio Excel di ' . $n19 . ' righe (vicino al limite)', function () use ( $f19 ) {
		$s = \AssociazioneSemplice\Xlsx::read( $f19 );
		if ( count( $s[0]['rows'] ) < 100 ) {
			throw new \RuntimeException( 'foglio letto male' );
		}
	}, 1, false );
	foreach ( array( $f5, $f19, $fc ) as $f ) {
		@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}
}

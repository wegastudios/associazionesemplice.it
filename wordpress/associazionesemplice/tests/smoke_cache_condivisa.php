<?php
/**
 * Letture ricordate tra le richieste (SharedMemo), richiamato da tests/smoke.php.
 * Senza cache degli oggetti persistente (il sito di prova non ne ha) tutto resta com'era. Poi si simula una cache persistente: un
 * WP_Object_Cache tutto nostro al posto di quello del sito, che dura per tutto il processo come farebbe Redis, e wp_using_ext_object_cache()
 * acceso; «una nuova richiesta» è la fine della richiesta (SharedMemo::end_request) più lo svuotamento della memoria di richiesta.
 * Si prova: stesso risultato con e senza cache, nessuna query alla seconda richiesta, e dopo ogni scrittura (da qualunque parte, anche
 * dentro una transazione confermata o annullata) di nuovo il dato vero.
 */

use AssociazioneSemplice\Calendar;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\RequestMemo;
use AssociazioneSemplice\SharedMemo;

global $wpdb;
wp_set_current_user( 1 );

$sc_people = Plugin::people();
$sc_ledger = Plugin::ledger();
$sc_year   = (int) substr( Db::today(), 0, 4 );
$sc_from   = gmdate( 'Y-m-d', strtotime( Db::today() . ' -40 days' ) );
$sc_to     = gmdate( 'Y-m-d', strtotime( Db::today() . ' +60 days' ) );
if ( ! FiscalYears::get( $sc_year ) ) {
	FiscalYears::create( $sc_year );
}

// Le letture provate: ognuna restituisce il suo risultato.
$sc_reads = array(
	'calendario'       => static function () use ( $sc_from, $sc_to ) {
		return Calendar::occurrences( $sc_from, $sc_to );
	},
	'persone'          => static function () use ( $sc_people ) {
		return $sc_people->search_light();
	},
	'persone attive'   => static function () use ( $sc_people ) {
		return $sc_people->search_light( array( 'status' => 'active' ), true );
	},
	'saldi'            => static function () use ( $sc_ledger ) {
		return $sc_ledger->balances();
	},
	'saldi a una data' => static function () use ( $sc_ledger ) {
		return $sc_ledger->balances( Db::today() );
	},
	'anno fiscale'     => static function () use ( $sc_year ) {
		return FiscalYears::get( $sc_year );
	},
);
$sc_new_request = static function (): void {
	SharedMemo::end_request();
	RequestMemo::forget();
};
$sc_count = static function ( callable $fn ) use ( $wpdb ): array {
	$n = (int) $wpdb->num_queries;
	$v = $fn();
	return array( $v, (int) $wpdb->num_queries - $n );
};
$sc_all = static function () use ( $sc_reads, $sc_count ): array {
	$out = array();
	foreach ( $sc_reads as $k => $fn ) {
		$out[ $k ] = $sc_count( $fn );
	}
	return $out;
};

// ---------- Senza cache persistente: come prima ----------
asem_ok( ! wp_using_ext_object_cache() && ! SharedMemo::enabled(), 'cache condivisa: sul sito di prova non c\'è una cache degli oggetti persistente, la cache tra richieste resta spenta' );
RequestMemo::forget();
$sc_base = $sc_all();
RequestMemo::forget();
$sc_base2 = $sc_all();
$sc_same  = true;
foreach ( $sc_base as $k => $r ) {
	$sc_same = $sc_same && $r[0] === $sc_base2[ $k ][0] && $sc_base2[ $k ][1] > 0;
}
asem_ok( $sc_same, 'cache condivisa: senza cache persistente ogni richiesta legge dal database (stesso risultato, con query)' );
$sc_base = $sc_base2; // le query di riferimento: quelle di una richiesta in cui il resto (opzioni, permessi) è già in memoria
asem_ok( count( $sc_base['calendario'][0] ) > 0 && count( $sc_base['persone'][0] ) > 0 && count( $sc_base['saldi'][0] ) > 0 && null !== $sc_base['anno fiscale'][0], 'cache condivisa: la prova ha date, persone, conti e l\'anno fiscale' );
list( , $sc_q1 ) = $sc_count( static function () use ( $sc_year ) {
	return FiscalYears::get( $sc_year );
} );
list( , $sc_q2 ) = $sc_count( static function () use ( $sc_year ) {
	return FiscalYears::get( $sc_year );
} );
asem_ok( 1 === $sc_q1 && 1 === $sc_q2, 'cache condivisa: senza cache persistente l\'anno fiscale si legge ogni volta, come prima' );

// ---------- Cache persistente simulata ----------
$sc_orig_cache                  = $GLOBALS['wp_object_cache'];
$GLOBALS['wp_object_cache']     = new WP_Object_Cache(); // il «Redis» della prova: dura per tutto il processo
$sc_orig_ext                    = wp_using_ext_object_cache( true );
SharedMemo::boot(); // come all'avvio del plugin
asem_ok( SharedMemo::enabled() && has_filter( 'query', array( SharedMemo::class, 'on_query' ) ), 'cache condivisa: con una cache persistente si accende all\'avvio e guarda le query' );

try {
	$sc_new_request();
	$sc_first = $sc_all();
	$sc_new_request();
	$sc_second = $sc_all();
	$sc_eq     = true;
	$sc_lines  = array();
	foreach ( $sc_base as $k => $r ) {
		$sc_eq      = $sc_eq && $r[0] === $sc_first[ $k ][0] && $r[0] === $sc_second[ $k ][0];
		$sc_lines[] = $k . ' ' . $r[1] . ' → ' . $sc_second[ $k ][1];
	}
	asem_ok( $sc_eq, 'cache condivisa: stesso risultato con e senza cache, alla prima e alla seconda richiesta' );
	WP_CLI::log( 'Cache condivisa, query alla seconda richiesta (senza → con cache persistente): ' . implode( '; ', $sc_lines ) );
	asem_ok( 0 === $sc_second['calendario'][1] && 0 === $sc_second['persone'][1] && 0 === $sc_second['persone attive'][1] && 0 === $sc_second['anno fiscale'][1], 'cache condivisa: alla richiesta dopo calendario, elenco delle persone e anno fiscale non fanno query' );
	asem_ok( $sc_second['saldi'][1] === $sc_base['saldi'][1] - 1 && $sc_second['saldi a una data'][1] === $sc_base['saldi a una data'][1] - 1, 'cache condivisa: alla richiesta dopo i saldi non rifanno la somma dei movimenti (restano solo i conti)' );

	// Prova che il valore viene davvero dalla cache: una modifica fatta fuori da WordPress (senza passare da $wpdb) non si vede finché nessuno scrive.
	$sc_fy_t   = Db::t( 'fiscal_years' );
	$sc_fy_old = (string) $sc_base['anno fiscale'][0]['created_at'];
	mysqli_query( $wpdb->dbh, "UPDATE $sc_fy_t SET created_at = '2001-02-03 04:05:06' WHERE year = $sc_year" ); // phpcs:ignore -- prova: scrittura fuori da $wpdb
	$sc_new_request();
	asem_ok( $sc_fy_old === (string) FiscalYears::get( $sc_year )['created_at'], 'cache condivisa: la lettura arriva dalla cache (una scrittura fuori da WordPress non la cambia: per quella c\'è la scadenza)' );
	mysqli_query( $wpdb->dbh, $wpdb->prepare( "UPDATE $sc_fy_t SET created_at = %s WHERE year = %d", $sc_fy_old, $sc_year ) ); // phpcs:ignore -- prova: si rimette com'era

	// Ogni scrittura con $wpdb (da qualunque parte) svuota l'area: alla richiesta dopo si legge il dato vero.
	$sc_fresh = static function ( string $k ) use ( $sc_reads ) {
		SharedMemo::stop();
		RequestMemo::forget();
		$v = $sc_reads[ $k ]();
		SharedMemo::boot();
		return $v;
	};
	$sc_check = static function ( string $label, array $keys, callable $write, callable $undo ) use ( $sc_reads, $sc_new_request, $sc_fresh ): void {
		$before = array();
		foreach ( $keys as $k ) {
			$sc_new_request();
			$before[ $k ] = $sc_reads[ $k ](); // la cache è calda
		}
		$sc_new_request();
		$write(); // una richiesta che scrive senza leggere
		$sc_new_request();
		$ok = true;
		foreach ( $keys as $k ) {
			$got = $sc_reads[ $k ]();
			$ok  = $ok && $got === $sc_fresh( $k ) && $got !== $before[ $k ];
		}
		asem_ok( $ok, "cache condivisa: dopo una scrittura su $label la richiesta dopo legge il dato nuovo (" . implode( ', ', $keys ) . ')' );
		$sc_new_request();
		$undo();
		$sc_new_request();
		$ok = true;
		foreach ( $keys as $k ) {
			$ok = $ok && $sc_reads[ $k ]() === $before[ $k ];
		}
		asem_ok( $ok, "cache condivisa: rimessa com'era $label, si rilegge il dato di prima" );
	};

	$sc_act = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL ORDER BY id LIMIT 1' );
	$sc_sid = 0;
	$sc_check(
		'date di corsi ed eventi',
		array( 'calendario' ),
		static function () use ( $wpdb, $sc_act, &$sc_sid ) {
			$wpdb->insert( Db::t( 'sessions' ), array( 'activity_id' => $sc_act, 'session_date' => Db::today(), 'start_time' => '23:58', 'location' => 'Prova cache', 'created_at' => Db::now() ) );
			$sc_sid = (int) $wpdb->insert_id;
		},
		static function () use ( $wpdb, &$sc_sid ) {
			$wpdb->delete( Db::t( 'sessions' ), array( 'id' => $sc_sid ) );
		}
	);

	// Un socio con la tessera valida: gli si cambia il cognome e si accorcia la tessera (persone e tessere), poi si rimette tutto com'era.
	$sc_m = $wpdb->get_row( $wpdb->prepare( 'SELECT m.id, m.person_id, m.valid_to, p.last_name FROM ' . Db::t( 'memberships' ) . ' m JOIN ' . Db::t( 'people' ) . ' p ON p.id = m.person_id WHERE m.deleted_at IS NULL AND m.valid_from <= %s AND m.valid_to >= %s AND p.deleted_at IS NULL AND p.anonymized_at IS NULL AND p.application_status IS NULL ORDER BY m.id LIMIT 1', Db::today(), Db::today() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella interni
	asem_ok( ! empty( $sc_m ), 'cache condivisa: la prova ha un socio con la tessera valida' );
	$sc_check(
		'persone e tessere',
		array( 'persone', 'persone attive' ),
		static function () use ( $wpdb, $sc_m ) {
			$wpdb->update( Db::t( 'people' ), array( 'last_name' => 'Zzz Prova cache' ), array( 'id' => (int) $sc_m['person_id'] ) );
			$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => '2001-01-01' ), array( 'id' => (int) $sc_m['id'] ) );
		},
		static function () use ( $wpdb, $sc_m ) {
			$wpdb->update( Db::t( 'people' ), array( 'last_name' => (string) $sc_m['last_name'] ), array( 'id' => (int) $sc_m['person_id'] ) );
			$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => (string) $sc_m['valid_to'] ), array( 'id' => (int) $sc_m['id'] ) );
		}
	);

	$sc_tx   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL AND tx_date <= %s ORDER BY id LIMIT 1', Db::today() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
	$sc_tx_t = Db::t( 'transactions' );
	$sc_check(
		'movimenti',
		array( 'saldi', 'saldi a una data' ),
		static function () use ( $wpdb, $sc_tx_t, $sc_tx ) {
			$wpdb->query( "UPDATE $sc_tx_t SET amount_cents = amount_cents + 7 WHERE id = $sc_tx" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova, id intero
		},
		static function () use ( $wpdb, $sc_tx_t, $sc_tx ) {
			$wpdb->query( "UPDATE $sc_tx_t SET amount_cents = amount_cents - 7 WHERE id = $sc_tx" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova, id intero
		}
	);
	$sc_check(
		'anni fiscali',
		array( 'anno fiscale' ),
		static function () use ( $wpdb, $sc_year ) {
			$wpdb->update( Db::t( 'fiscal_years' ), array( 'created_at' => '2002-03-04 05:06:07' ), array( 'year' => $sc_year ) );
		},
		static function () use ( $wpdb, $sc_year, $sc_fy_old ) {
			$wpdb->update( Db::t( 'fiscal_years' ), array( 'created_at' => $sc_fy_old ), array( 'year' => $sc_year ) );
		}
	);

	// Transazioni: dentro si legge dal database (anche le proprie scritture non confermate) e non si salva nulla; dopo ROLLBACK e dopo COMMIT si legge il dato vero.
	$sc_new_request();
	$sc_bal0 = $sc_ledger->balances(); // cache calda
	$sc_new_request();
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "UPDATE $sc_tx_t SET amount_cents = amount_cents + 11 WHERE id = $sc_tx" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova, id intero
	RequestMemo::forget();
	$sc_in = $sc_ledger->balances();
	asem_ok( $sc_in !== $sc_bal0 && array_sum( array_column( $sc_in, 'balance' ) ) !== array_sum( array_column( $sc_bal0, 'balance' ) ), 'cache condivisa: dentro una transazione si leggono le proprie scritture, non la cache' );
	$wpdb->query( 'ROLLBACK' );
	RequestMemo::forget();
	asem_ok( $sc_bal0 === $sc_ledger->balances(), 'cache condivisa: dopo l\'annullamento si rilegge il saldo di prima, nella stessa richiesta' );
	$sc_new_request();
	asem_ok( $sc_bal0 === $sc_ledger->balances() && $sc_bal0 === $sc_fresh( 'saldi' ), 'cache condivisa: e anche alla richiesta dopo (nessun saldo non confermato rimasto nella cache)' );
	$sc_new_request();
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "UPDATE $sc_tx_t SET amount_cents = amount_cents + 13 WHERE id = $sc_tx" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova, id intero
	$wpdb->query( 'COMMIT' );
	RequestMemo::forget();
	$sc_after = $sc_ledger->balances();
	asem_ok( $sc_after !== $sc_bal0 && $sc_after === $sc_fresh( 'saldi' ), 'cache condivisa: subito dopo la conferma della transazione si legge il saldo nuovo' );
	$sc_new_request();
	asem_ok( $sc_after === $sc_ledger->balances(), 'cache condivisa: e alla richiesta dopo' );
	$wpdb->query( "UPDATE $sc_tx_t SET amount_cents = amount_cents - 13 WHERE id = $sc_tx" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova, id intero
	$sc_new_request();
	asem_ok( $sc_bal0 === $sc_ledger->balances(), 'cache condivisa: rimesso il movimento, il saldo torna quello di prima' );

	// Una scrittura dal servizio vero (movimento registrato dal plugin, con la sua transazione): il saldo della richiesta dopo è quello vero.
	$sc_new_request();
	$sc_ledger->balances();
	$sc_cat = 0;
	foreach ( $sc_ledger->categories() as $sc_c ) {
		if ( 'general_cost' === $sc_c['kind'] && ! $sc_cat ) {
			$sc_cat = (int) $sc_c['id'];
		}
	}
	$sc_bank = $sc_ledger->default_account_for( 'bank' );
	$sc_ledger->record_expense( array( 'date' => Db::today(), 'account_id' => (int) $sc_bank['id'], 'method' => 'bank_transfer', 'category_id' => $sc_cat, 'amount_cents' => 3, 'description' => 'Prova cache condivisa' ) );
	$sc_new_request();
	$sc_svc = $sc_ledger->balances();
	asem_ok( $sc_svc === $sc_fresh( 'saldi' ) && $sc_svc !== $sc_bal0, 'cache condivisa: dopo un movimento registrato dal plugin il saldo della richiesta dopo è quello vero' );
	$sc_new_tx = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $sc_tx_t WHERE description = %s ORDER BY id DESC LIMIT 1", 'Prova cache condivisa' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella interno
	$wpdb->delete( $sc_tx_t, array( 'id' => $sc_new_tx ) ); // la prova non lascia movimenti
	$sc_new_request();
	asem_ok( $sc_bal0 === $sc_ledger->balances(), 'cache condivisa: tolto il movimento di prova, il saldo torna quello di prima' );

	// Il calendario si filtra per chi guarda dopo la cache: un utente senza permessi particolari vede le stesse date dell'amministratore.
	$sc_new_request();
	$sc_admin_cal = Calendar::occurrences( $sc_from, $sc_to );
	wp_set_current_user( 0 );
	$sc_anon_cal = Calendar::occurrences( $sc_from, $sc_to );
	wp_set_current_user( 1 );
	asem_ok( $sc_admin_cal === $sc_anon_cal && $sc_admin_cal === $sc_fresh( 'calendario' ), 'cache condivisa: il calendario ricordato è lo stesso per chiunque, il filtro per utente si applica dopo' );
} finally {
	SharedMemo::stop();
	wp_using_ext_object_cache( $sc_orig_ext );
	$GLOBALS['wp_object_cache'] = $sc_orig_cache;
	RequestMemo::forget();
}
asem_ok( ! SharedMemo::enabled() && ! has_filter( 'query', array( SharedMemo::class, 'on_query' ) ), 'cache condivisa: spenta di nuovo alla fine della prova' );

<?php
/**
 * Prove dei saldi salvati alla chiusura dell'anno fiscale: i saldi dei conti si sommano dall'ultima chiusura in poi e devono essere identici a quelli
 * calcolati su tutta la storia (chiusure, riaperture, movimenti retrodatati bloccati, giroconti, conti nuovi, saldo iniziale cambiato, anni creati
 * prima di un anno chiuso, schema non ancora pronto). Si carica da smoke.php; alla fine rimette anni fiscali e prima nota come li ha trovati.
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Install;
use AssociazioneSemplice\LedgerService;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\RequestMemo;

global $wpdb;

wp_set_current_user( 1 );
$sb_ledger = Plugin::ledger();
$sb_fy_t   = Db::t( 'fiscal_years' );
$sb_tx_t   = Db::t( 'transactions' );

// Gli anni fiscali di adesso si mettono da parte: la prova lavora su anni suoi, chiusi e riaperti in ordine, poi li rimette com'erano.
$sb_saved = $wpdb->get_results( "SELECT * FROM $sb_fy_t", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$wpdb->query( "DELETE FROM $sb_fy_t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
FiscalYears::ensure_current_open();
$sb_tx0 = (int) $wpdb->get_var( "SELECT MAX(id) FROM $sb_tx_t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno

// Quattro anni passati di fila, senza movimenti di altre prove (si chiudono senza fondi o altro in sospeso).
$sb_y1 = 2003;
while ( $sb_y1 < FiscalYears::current() - 6 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sb_tx_t WHERE tx_date BETWEEN %s AND %s", FiscalYears::range( $sb_y1 - 1 )[0], FiscalYears::range( $sb_y1 + 3 )[1] ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
	++$sb_y1;
}
$sb_y0 = $sb_y1 - 1; // creato solo più avanti, quando gli anni dopo sono già chiusi
$sb_y2 = $sb_y1 + 1;
$sb_y3 = $sb_y1 + 2;
foreach ( array( $sb_y1, $sb_y2, $sb_y3 ) as $sb_y ) {
	FiscalYears::ensure( $sb_y );
}
$sb_day = static function ( int $year, int $days ): string { // un giorno dentro l'anno fiscale
	return gmdate( 'Y-m-d', strtotime( FiscalYears::range( $year )[0] ) + $days * 86400 );
};
$sb_end   = static function ( int $year ): string {
	return FiscalYears::range( $year )[1];
};
$sb_cat   = array();
foreach ( $sb_ledger->categories() as $sb_c ) {
	$sb_cat[ $sb_c['kind'] ] = (int) $sb_c['id'];
}
$sb_a = $sb_ledger->add_account( 'Saldi chiusura: cassa', 'cash', 1000 );
$sb_b = $sb_ledger->add_account( 'Saldi chiusura: banca', 'bank', 5000 );
$sb_in = static function ( int $acc, string $date, int $cents ) use ( $sb_ledger, $sb_cat ): int {
	return $sb_ledger->record_receipt( array( 'date' => $date, 'account_id' => $acc, 'method' => '', 'lines' => array( array( 'category_id' => $sb_cat['other_income'], 'amount_cents' => $cents, 'description' => 'Saldi chiusura' ) ) ) );
};
$sb_out = static function ( int $acc, string $date, int $cents ) use ( $sb_ledger, $sb_cat ): int {
	return $sb_ledger->record_expense( array( 'date' => $date, 'account_id' => $acc, 'method' => '', 'category_id' => $sb_cat['general_cost'], 'amount_cents' => $cents, 'description' => 'Saldi chiusura' ) );
};
$sb_last_tx = static function () use ( $wpdb, $sb_tx_t ): int {
	return (int) $wpdb->get_var( "SELECT MAX(id) FROM $sb_tx_t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
};

// Il calcolo di prima: saldo iniziale + tutti i movimenti non annullati fino alla data, su tutta la storia.
$sb_ref = static function ( ?string $up_to ) use ( $wpdb, $sb_tx_t ): array {
	$sql = "SELECT a.id, a.opening_cents + COALESCE((SELECT SUM(CASE WHEN t.type IN ('income','transfer_in') THEN t.amount_cents ELSE -t.amount_cents END) FROM $sb_tx_t t WHERE t.account_id = a.id AND t.voided_at IS NULL" . ( null !== $up_to ? $wpdb->prepare( ' AND t.tx_date <= %s', $up_to ) : '' ) . '), 0) AS bal FROM ' . Db::t( 'accounts' ) . ' a WHERE a.deleted_at IS NULL';
	$out = array();
	foreach ( $wpdb->get_results( $sql, ARRAY_A ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella interni, la data passa da prepare()
		$out[ (int) $r['id'] ] = (int) $r['bal'];
	}
	ksort( $out );
	return $out;
};
$sb_got = static function ( ?string $up_to ) use ( $sb_ledger ): array {
	RequestMemo::forget(); // come una richiesta nuova
	$out = array();
	foreach ( $sb_ledger->balances( $up_to, true ) as $a ) {
		$out[ (int) $a['id'] ] = (int) $a['balance'];
	}
	ksort( $out );
	return $out;
};
// Le date provate: a oggi, la fine di ogni anno e un giorno a metà, prima del primo anno.
$sb_dates = static function () use ( $sb_y0, $sb_y1, $sb_y2, $sb_y3, $sb_day, $sb_end ): array {
	$d = array( null, Db::today(), $sb_end( $sb_y0 - 1 ) );
	foreach ( array( $sb_y0, $sb_y1, $sb_y2, $sb_y3 ) as $y ) {
		$d[] = $sb_day( $y, 150 );
		$d[] = $sb_end( $y );
	}
	return $d;
};
$sb_same = static function ( string $label ) use ( $sb_ref, $sb_got, $sb_dates ): void {
	$bad = array();
	foreach ( $sb_dates() as $d ) {
		if ( $sb_ref( $d ) !== $sb_got( $d ) ) {
			$bad[] = null === $d ? 'oggi (senza data)' : $d;
		}
	}
	asem_ok( ! $bad, 'saldi dalla chiusura: ' . $label . ' — identici al calcolo su tutta la storia' . ( $bad ? ' (diversi a: ' . implode( ', ', $bad ) . ')' : '' ) );
};
$sb_stored = static function ( int $year ) use ( $wpdb, $sb_fy_t ): ?array {
	return LedgerService::closing_decode( $wpdb->get_var( $wpdb->prepare( "SELECT closing_sums FROM $sb_fy_t WHERE year = %d", $year ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
};
$sb_sql = array();
$sb_spy = static function ( $q ) use ( &$sb_sql ) {
	if ( is_string( $q ) && false !== strpos( $q, 'GROUP BY account_id' ) ) {
		$sb_sql[] = $q;
	}
	return $q;
};
add_filter( 'query', $sb_spy );

// Movimenti su tre anni: entrate, uscite, giroconti, un annullato.
foreach ( array( $sb_y1, $sb_y2, $sb_y3 ) as $sb_k => $sb_y ) {
	$sb_in( $sb_a, $sb_day( $sb_y, 10 ), 3000 + $sb_k );
	$sb_in( $sb_b, $sb_day( $sb_y, 20 ), 9000 + $sb_k );
	$sb_out( $sb_a, $sb_day( $sb_y, 30 ), 700 + $sb_k );
	$sb_out( $sb_b, $sb_day( $sb_y, 40 ), 1100 + $sb_k );
	$sb_ledger->record_transfer( $sb_day( $sb_y, 50 ), $sb_a, $sb_b, 1500, 'bank_transfer', 'Saldi chiusura: versamento' );
	$sb_ledger->record_transfer( $sb_end( $sb_y ), $sb_b, $sb_a, 200 + $sb_k, 'bank_transfer', 'Saldi chiusura: prelievo l\'ultimo giorno' );
	$sb_out( $sb_a, $sb_day( $sb_y, 60 ), 999 );
	$sb_ledger->void( $sb_last_tx(), 'Saldi chiusura: annullato' );
}
$sb_in( $sb_a, Db::today(), 4321 );
$sb_out( $sb_b, Db::today(), 1234 );
$sb_same( 'nessun anno chiuso' );

// Chiusura del primo anno: il saldo dei movimenti fino all'ultimo giorno si salva con l'anno.
FiscalYears::close( $sb_y1, true );
$sb_s1 = $sb_stored( $sb_y1 );
asem_ok( null !== $sb_s1 && $sb_end( $sb_y1 ) === $sb_s1['to'] && isset( $sb_s1['d'][ $sb_a ], $sb_s1['d'][ $sb_b ] ), 'saldi dalla chiusura: alla chiusura si salva la somma dei movimenti di ogni conto fino all\'ultimo giorno dell\'anno' );
asem_ok( $sb_ref( $sb_end( $sb_y1 ) )[ $sb_a ] - 1000 === $sb_s1['d'][ $sb_a ] && $sb_ref( $sb_end( $sb_y1 ) )[ $sb_b ] - 5000 === $sb_s1['d'][ $sb_b ], 'saldi dalla chiusura: si salvano i movimenti, non il saldo iniziale' );
$sb_same( 'primo anno chiuso' );

// Movimenti retrodatati nell'anno chiuso: bloccati, i saldi non cambiano.
$sb_before = $sb_ref( null );
asem_ok( null !== asem_throws( function () use ( $sb_in, $sb_a, $sb_day, $sb_y1 ) { $sb_in( $sb_a, $sb_day( $sb_y1, 100 ), 500 ); } ) && null !== asem_throws( function () use ( $sb_ledger, $sb_a, $sb_b, $sb_end, $sb_y1 ) { $sb_ledger->record_transfer( $sb_end( $sb_y1 ), $sb_a, $sb_b, 100, 'bank_transfer' ); } ), 'saldi dalla chiusura: nell\'anno chiuso non si registrano movimenti retrodatati' );
$sb_y1_tx = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $sb_tx_t WHERE tx_date = %s AND voided_at IS NULL AND account_id = %d ORDER BY id LIMIT 1", $sb_day( $sb_y1, 10 ), $sb_a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
asem_ok( $sb_y1_tx > 0 && null !== asem_throws( function () use ( $sb_ledger, $sb_y1_tx ) { $sb_ledger->void( $sb_y1_tx, 'no' ); } ) && $sb_before === $sb_ref( null ) && null !== $sb_stored( $sb_y1 ), 'saldi dalla chiusura: nell\'anno chiuso non si annullano movimenti; il saldo salvato resta' );
$sb_same( 'dopo i tentativi nell\'anno chiuso' );

// Altri due anni chiusi: i saldi di oggi sommano solo i movimenti dopo l'ultima chiusura.
FiscalYears::close( $sb_y2, true );
$sb_same( 'due anni chiusi' );
FiscalYears::close( $sb_y3, true );
$sb_same( 'tre anni chiusi' );
$sb_sql = array();
$sb_got( null );
asem_ok( 1 === count( $sb_sql ) && false !== strpos( $sb_sql[0], "tx_date > '" . $sb_end( $sb_y3 ) . "'" ), 'saldi dalla chiusura: a oggi si leggono solo i movimenti dopo la fine dell\'ultimo anno chiuso [' . implode( ' | ', $sb_sql ) . ']' );
$sb_sql = array();
$sb_got( $sb_day( $sb_y3, 150 ) );
asem_ok( 1 === count( $sb_sql ) && false !== strpos( $sb_sql[0], "tx_date > '" . $sb_end( $sb_y2 ) . "'" ), 'saldi dalla chiusura: a una data dell\'anno chiuso dopo si parte dalla chiusura precedente' );
$sb_sql = array();
$sb_got( $sb_end( $sb_y1 - 1 ) );
asem_ok( 1 === count( $sb_sql ) && false === strpos( $sb_sql[0], 'tx_date >' ), 'saldi dalla chiusura: prima del primo anno chiuso si somma tutta la storia, come prima' );

// Un conto nuovo dopo le chiusure, con un giroconto da un conto vecchio.
$sb_c = $sb_ledger->add_account( 'Saldi chiusura: conto nuovo', 'other', 250 );
$sb_ledger->record_transfer( Db::today(), $sb_a, $sb_c, 800, 'bank_transfer', 'Saldi chiusura: al conto nuovo' );
$sb_same( 'conto nuovo dopo le chiusure, con un giroconto' );

// Riapertura dell'ultimo anno: il suo saldo salvato si cancella; i movimenti di quell'anno tornano possibili e contano.
FiscalYears::reopen( $sb_y3, 'Saldi chiusura: prova di riapertura' );
asem_ok( null === $sb_stored( $sb_y3 ) && null !== $sb_stored( $sb_y2 ), 'saldi dalla chiusura: alla riapertura il saldo salvato di quell\'anno si cancella, quelli degli anni prima restano' );
$sb_same( 'ultimo anno riaperto' );
$sb_in( $sb_b, $sb_day( $sb_y3, 200 ), 777 );
$sb_ledger->void( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $sb_tx_t WHERE tx_date = %s AND voided_at IS NULL AND account_id = %d AND type = 'expense' ORDER BY id LIMIT 1", $sb_day( $sb_y3, 30 ), $sb_a ) ), 'Saldi chiusura: correzione' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$sb_same( 'anno riaperto, con un movimento nuovo e uno annullato' );
FiscalYears::close( $sb_y3, true );
$sb_s3 = $sb_stored( $sb_y3 );
asem_ok( null !== $sb_s3 && $sb_ref( $sb_end( $sb_y3 ) )[ $sb_b ] - 5000 === $sb_s3['d'][ $sb_b ], 'saldi dalla chiusura: richiudendo, il saldo salvato comprende le correzioni' );
$sb_same( 'anno richiuso' );

// Un anno più vecchio creato dopo le chiusure (aperto): un suo movimento cambia tutti i saldi dopo, quelli salvati si cancellano e si rifanno.
FiscalYears::create( $sb_y0 );
$sb_in( $sb_a, $sb_day( $sb_y0, 90 ), 1111 );
asem_ok( null === $sb_stored( $sb_y1 ) && null === $sb_stored( $sb_y2 ) && null === $sb_stored( $sb_y3 ), 'saldi dalla chiusura: un movimento datato prima di un anno chiuso cancella i saldi salvati da lì in poi' );
$sb_same( 'movimento in un anno aperto più vecchio di quelli chiusi' );
asem_ok( null !== $sb_stored( $sb_y3 ), 'saldi dalla chiusura: alla prima lettura il saldo dell\'ultimo anno chiuso si risalva da solo' );
$sb_s3b = $sb_stored( $sb_y3 );
$sb_ledger->void( $sb_last_tx(), 'Saldi chiusura: tolto' );
asem_ok( null === $sb_stored( $sb_y3 ), 'saldi dalla chiusura: anche annullare un movimento datato prima di un anno chiuso cancella i saldi salvati' );
$sb_same( 'movimento annullato in un anno aperto più vecchio' );
$sb_same( 'di nuovo, con il saldo risalvato' );
asem_ok( $sb_stored( $sb_y3 ) !== $sb_s3b && null !== $sb_stored( $sb_y3 ), 'saldi dalla chiusura: il saldo risalvato non è quello di prima dell\'annullo' );

// Saldo iniziale di un conto cambiato: non entra nel saldo salvato, quindi i saldi restano giusti (a mano è bloccato con anni chiusi; qui lo si cambia nel database).
$wpdb->update( Db::t( 'accounts' ), array( 'opening_cents' => 2500 ), array( 'id' => $sb_a ) );
$sb_same( 'saldo iniziale di un conto cambiato' );
asem_ok( null !== asem_throws( function () use ( $sb_ledger, $sb_a ) { $sb_ledger->update_account( $sb_a, 'Saldi chiusura: cassa', 'cash', 3000 ); } ), 'saldi dalla chiusura: con anni chiusi il saldo iniziale resta bloccato' );

// Anni chiusi prima di questa versione (nessun saldo salvato): il primo calcolo lo salva, con lo stesso risultato.
$wpdb->query( "UPDATE $sb_fy_t SET closing_sums = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$sb_sql = array();
$sb_same( 'anni chiusi senza saldo salvato' );
asem_ok( null !== $sb_stored( $sb_y3 ) && null === $sb_stored( $sb_y2 ) && $sb_end( $sb_y3 ) === $sb_stored( $sb_y3 )['to'], 'saldi dalla chiusura: il saldo mancante dell\'ultimo anno chiuso si salva alla prima lettura (gli altri restano vuoti)' );
$wpdb->query( $wpdb->prepare( "UPDATE $sb_fy_t SET closing_sums = %s WHERE year = %d", 'non valido', $sb_y3 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$sb_same( 'saldo salvato illeggibile (si ricalcola)' );

// Schema non ancora pronto (aggiornamento a metà): si somma tutta la storia, senza leggere la colonna nuova.
update_option( Install::DB_VERSION_OPTION, '74' );
$sb_sql = array();
$sb_same( 'schema non ancora pronto' );
$sb_full = true;
foreach ( $sb_sql as $sb_q ) {
	$sb_full = $sb_full && false === strpos( $sb_q, 'tx_date >' );
}
asem_ok( $sb_full && $sb_sql, 'saldi dalla chiusura: con lo schema a metà nessun saldo salvato viene usato' );
update_option( Install::DB_VERSION_OPTION, Install::DB_VERSION );

// Riaperture in fila fino al primo anno: nessun saldo salvato resta su un anno aperto.
FiscalYears::reopen( $sb_y3, 'Saldi chiusura: fine della prova' );
FiscalYears::reopen( $sb_y2, 'Saldi chiusura: fine della prova' );
FiscalYears::reopen( $sb_y1, 'Saldi chiusura: fine della prova' );
asem_ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sb_fy_t WHERE closing_sums IS NOT NULL" ), 'saldi dalla chiusura: riaperti tutti gli anni, nessun saldo salvato resta' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$sb_same( 'tutti gli anni riaperti' );

// Copia di sicurezza ripristinata: i saldi salvati si cancellano e si rifanno alla prima lettura.
FiscalYears::close( $sb_y0, true );
FiscalYears::close( $sb_y1, true );
LedgerService::forget_closing_sums();
asem_ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sb_fy_t WHERE closing_sums IS NOT NULL" ), 'saldi dalla chiusura: si possono cancellare tutti (dopo un ripristino)' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
$sb_same( 'saldi salvati cancellati' );
FiscalYears::reopen( $sb_y1, 'Saldi chiusura: fine della prova' );
FiscalYears::reopen( $sb_y0, 'Saldi chiusura: fine della prova' );

remove_filter( 'query', $sb_spy );

// Si rimette tutto com'era: movimenti e conti della prova via, anni fiscali di prima.
$wpdb->query( $wpdb->prepare( "DELETE FROM $sb_tx_t WHERE id > %d", $sb_tx0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
foreach ( array( $sb_a, $sb_b, $sb_c ) as $sb_acc ) {
	$wpdb->update( Db::t( 'accounts' ), array( 'deleted_at' => Db::now() ), array( 'id' => $sb_acc ) );
}
$wpdb->query( "DELETE FROM $sb_fy_t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno
foreach ( $sb_saved as $sb_row ) {
	$sb_row['closing_sums'] = null; // i saldi salvati si rifanno alla prima lettura
	$wpdb->insert( $sb_fy_t, $sb_row );
}
RequestMemo::forget();
asem_ok( count( $sb_saved ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sb_fy_t" ), 'saldi dalla chiusura: anni fiscali rimessi come prima' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella interno

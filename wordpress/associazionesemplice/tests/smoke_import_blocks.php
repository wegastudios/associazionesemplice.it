<?php
/**
 * Prove dell'importazione di soci a giri, con ripresa. Il risultato deve essere identico a quello di prima: la vecchia versione (tutto in una
 * richiesta, un'anagrafica riletta a ogni ospite) resta qui sotto come riferimento e si confronta, riga per riga, con l'importazione a giri.
 * Si carica da smoke.php.
 */

use AssociazioneSemplice\Admin;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Guardians;
use AssociazioneSemplice\ImportJob;
use AssociazioneSemplice\ImportService;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\MembershipRules;
use AssociazioneSemplice\PeopleCsv;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\RuntsRules;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Text;

global $wpdb;

// ---------- Aiuti ----------

/** Parola unica per ogni numero (solo lettere): serve a dare un nome diverso a ogni riga. */
function asem_imp_word( int $i ): string {
	$s = '';
	do {
		$s = chr( 97 + $i % 26 ) . $s;
		$i = intdiv( $i, 26 );
	} while ( $i > 0 );
	return $s;
}

/**
 * Le righe di un file di prova. Ogni dieci righe: 0 socio adulto (ogni 50 già presente: aggiornamento), 1 volontario, 2 e 4 e 7 soci, 3 tessera doppia
 * (errore), 5 email non valida (errore), 6 socio senza email, 8 ospite (ospitante per tessera, email o nome), 9 minore con tutore (ogni 100 un tutore che non esiste).
 *
 * @return array[]
 */
function asem_imp_rows( string $tag, int $base, int $n, string $pp ): array {
	$rows = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$r   = $i % 10;
		$w   = asem_imp_word( $i );
		$row = array(
			'card' => (string) ( $base + $i ), 'type' => '', 'first' => $tag . $w, 'last' => 'Cogn' . $w, 'email' => strtolower( $tag ) . $i . '@example.com',
			'phone' => '333 ' . $pp . sprintf( '%06d', $i ), 'host' => '', 'birth' => '', 'guardian' => '',
		);
		if ( 0 === $r ) {
			$row['birth'] = '04/03/1980';
		} elseif ( 1 === $r ) {
			$row['type'] = 'volontario';
		} elseif ( 3 === $r ) {
			$row['card'] = (string) ( $base + $i - 1 ); // la tessera della riga prima
		} elseif ( 5 === $r ) {
			$row['email'] = 'non-una-email';
		} elseif ( 6 === $r ) {
			$row['email'] = '';
		} elseif ( 8 === $r ) {
			$h   = $i - 8;
			$hw  = asem_imp_word( $h );
			$how = intdiv( $i, 10 ) % 3;
			$row['type']  = 'ospite';
			$row['card']  = '';
			$row['email'] = '';
			$row['phone'] = '338 ' . $pp . sprintf( '%06d', $i );
			$row['host']  = 0 === $how ? (string) ( $base + $h ) : ( 1 === $how ? strtolower( $tag ) . $h . '@example.com' : $tag . $hw . ' Cogn' . $hw );
		} elseif ( 9 === $r ) {
			$row['email']    = '';
			$row['phone']    = '';
			$row['birth']    = '12/05/2015';
			$row['guardian'] = 9 === $i % 100 ? 'nessuno.' . strtolower( $tag ) . '@example.com' : (string) ( $base + $i - 9 );
		}
		$rows[] = $row;
	}
	return $rows;
}

function asem_imp_csv( array $rows ): string {
	$out = "Tessera;Tipo;Nome;Cognome;Email;Cellulare;Ospite di;Data di nascita;Tutore\r\n";
	foreach ( $rows as $r ) {
		$out .= implode( ';', array( $r['card'], $r['type'], $r['first'], $r['last'], $r['email'], $r['phone'], $r['host'], $r['birth'], $r['guardian'] ) ) . "\r\n";
	}
	return $out;
}

/** Soci già presenti (uno ogni 50 righe, con un altro cellulare): quelle righe del file sono aggiornamenti. @return int quanti */
function asem_imp_seed( string $tag, int $base, int $n, string $pp ): int {
	$people = Plugin::people();
	$c      = 0;
	for ( $i = 0; $i < $n; $i += 50 ) {
		$w = asem_imp_word( $i );
		$people->create( array( 'type' => 'ordinary', 'first_name' => $tag . $w, 'last_name' => 'Cogn' . $w, 'email' => strtolower( $tag ) . $i . '@example.com', 'card_number' => (string) ( $base + $i ), 'phone' => '320 ' . $pp . sprintf( '%06d', $i ) ) );
		$c++;
	}
	return $c;
}

/** Anteprima del file di prova (con i soci già presenti creati prima). */
function asem_imp_preview( string $tag, int $base, int $n, string $pp ): array {
	asem_imp_seed( $tag, $base, $n, $pp );
	$path = sys_get_temp_dir() . '/asem-imp-' . $tag . '-' . wp_generate_password( 6, false ) . '.csv';
	file_put_contents( $path, asem_imp_csv( asem_imp_rows( $tag, $base, $n, $pp ) ) );
	$pv = ImportService::preview_file( $path, strtolower( $tag ) . '.csv', array() );
	unlink( $path );
	return $pv;
}

/** L'ospitante come lo cercava la vecchia versione: tutta l'anagrafica riletta a ogni ospite. */
function asem_imp_ref_find_host( string $text ): int {
	$text = trim( $text );
	if ( '' === $text ) {
		return 0;
	}
	$n = Text::normalize( $text );
	$c = Text::lower( PeopleCsv::clean_card( $text ) );
	$m = array();
	foreach ( Plugin::people()->search() as $p ) {
		if ( MemberType::GUEST === $p['type'] ) {
			continue;
		}
		if ( ( ! empty( $p['card_number'] ) && Text::lower( (string) $p['card_number'] ) === $c )
			|| ( ! empty( $p['email'] ) && Text::lower( (string) $p['email'] ) === Text::lower( $text ) )
			|| Text::normalize( $p['first_name'] . $p['last_name'] ) === $n || Text::normalize( $p['last_name'] . $p['first_name'] ) === $n ) {
			$m[ (int) $p['id'] ] = true;
		}
	}
	return 1 === count( $m ) ? (int) array_keys( $m )[0] : 0;
}

/** L'importazione dei soci come si faceva prima: una sola richiesta, righe una dopo l'altra, tutori alla fine. */
function asem_imp_ref_apply( array $preview, bool $mark ): array {
	$db     = Db::db();
	$year   = Settings::membership_year()->label();
	$people = Plugin::people();
	$track  = ImportService::empty_track();
	$batch  = ImportService::open_batch( 'riferimento' );
	$plan   = $preview['people']['plan'];
	$created = 0;
	$updated = 0;
	$failed  = array();
	$links   = array();
	$order   = array();
	foreach ( $plan as $row ) {
		if ( 'error' === $row['action'] ) {
			continue;
		}
		$order[ MemberType::GUEST === $row['type'] ? 1 : 0 ][] = $row;
	}
	foreach ( array( 0, 1 ) as $pass ) {
		foreach ( $order[ $pass ] ?? array() as $row ) {
			$r    = $row['row'];
			$data = array( 'first_name' => $r['first'], 'last_name' => $r['last'] );
			if ( null !== $r['email'] ) {
				$data['email'] = $r['email'];
			}
			foreach ( array( 'card' => 'card_number', 'phone' => 'phone', 'tax' => 'tax_code', 'addr' => 'address', 'zip' => 'zip', 'city' => 'city', 'prov' => 'province' ) as $from => $to ) {
				if ( isset( $r[ $from ] ) && null !== $r[ $from ] && ( 0 === $pass || 'card' !== $from ) ) {
					$data[ $to ] = $r[ $from ];
				}
			}
			if ( ! empty( $r['notes'] ) ) {
				$data['notes'] = $r['notes'];
			}
			if ( ! empty( $r['since'] ) ) {
				$data['joined_on'] = $r['since'];
			}
			if ( ! empty( $r['birth'] ) && RuntsRules::ENTITY !== ( $r['kind'] ?? '' ) ) {
				$data['birth_date'] = $r['birth'];
			}
			if ( RuntsRules::ENTITY === ( $r['kind'] ?? '' ) ) {
				$data['subject_kind'] = RuntsRules::ENTITY;
				if ( is_bool( $r['same'] ?? null ) ) {
					$data['runts_same_section'] = $r['same'] ? '1' : '0';
				}
			}
			if ( 1 === $pass && isset( $r['prior'] ) && null !== $r['prior'] ) {
				$data['prior_visits'] = (int) $r['prior'];
			}
			try {
				if ( 1 === $pass ) {
					$host = ! empty( $row['host_id'] ) ? (int) $row['host_id'] : asem_imp_ref_find_host( (string) $row['host_text'] );
					if ( ! $host && ( '' !== trim( (string) $row['host_text'] ) || \AssociazioneSemplice\Limits::flag( 'guest_host_required' ) ) ) {
						throw new \InvalidArgumentException( 'socio ospitante "' . $row['host_text'] . '" non trovato' );
					}
					$data['host_person_id'] = $host ?: '';
				}
				if ( 'create' === $row['action'] ) {
					$data['type'] = $row['type'];
					$user_existed = ! empty( $data['email'] ) && (bool) get_user_by( 'email', $data['email'] );
					$id           = $people->create( $data );
					$created++;
					$track['people_created'][] = $id;
					$person                    = $people->get( $id );
					if ( ! $user_existed && $person && ! empty( $person['wp_user_id'] ) ) {
						$track['wp_users_created'][ $id ] = (int) $person['wp_user_id'];
					}
				} else {
					$id     = (int) $row['matched_id'];
					$before = $people->get( $id );
					if ( ! empty( $row['type_given'] ) && 0 === $pass ) {
						$data['type'] = $row['type'];
					}
					$people->update( $id, $data );
					$updated++;
					if ( $before ) {
						$track['people_updated'][ $id ] = array_intersect_key( $before, array_flip( ImportService::BEFORE_FIELDS ) );
					}
				}
				if ( ! empty( $r['guardian'] ) ) {
					$links[] = array( $id, (string) $r['guardian'], ( $r['sheet'] ? $r['sheet'] . ' ' : '' ) . 'riga ' . $r['line'] );
				}
				$person = $people->get( $id );
				if ( $mark && $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
					$had = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND social_year = %s AND deleted_at IS NULL', $id, $year ) );
					$people->set_membership( $id, $year, true, 'import' );
					if ( ! $had ) {
						$track['memberships'][] = array( $id, $year );
					}
				}
				if ( $person && ! empty( $r['years'] ) && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
					foreach ( $r['years'] as $y ) {
						$y     = '@current' === $y ? $year : ( '@previous' === $y ? Settings::membership_year()->previous()->label() : MembershipRules::import_label( (string) $y, Settings::start_month() ) );
						$y_had = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND social_year = %s AND deleted_at IS NULL', $id, (string) $y ) );
						$people->set_membership( $id, (string) $y, true, 'import' );
						if ( ! $y_had ) {
							$track['memberships'][] = array( $id, (string) $y );
						}
					}
				}
			} catch ( \InvalidArgumentException $e ) {
				$failed[] = ( $r['sheet'] ? $r['sheet'] . ' ' : '' ) . 'riga ' . $r['line'] . ': ' . $e->getMessage();
			}
		}
	}
	foreach ( $links as $l ) {
		try {
			$g = Guardians::find_by_ref( $l[1] );
			if ( ! $g ) {
				throw new \InvalidArgumentException( 'tutore "' . $l[1] . '" non trovato o ambiguo (indica la tessera o l\'email)' );
			}
			if ( ! Guardians::is_guardian_of( (int) $g['id'], (int) $l[0] ) ) {
				Guardians::link( (int) $l[0], (int) $g['id'] );
				$track['guardians'][] = array( (int) $l[0], (int) $g['id'] );
			}
		} catch ( \InvalidArgumentException $e ) {
			$failed[] = $l[2] . ': ' . $e->getMessage();
		}
	}
	$res = array( 'people' => array( 'created' => $created, 'updated' => $updated, 'failed' => $failed ), 'ledger' => null, 'batch_id' => $batch );
	ImportService::close_batch( $batch, $res, $track );
	return $res;
}

/** Le persone di una prova con le loro caratteristiche, senza ciò che cambia da un file all'altro (prefisso dei nomi, numeri di tessera, id). */
function asem_imp_snapshot( string $tag, int $base ): array {
	$db   = Db::db();
	$rows = $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE first_name LIKE %s AND deleted_at IS NULL ORDER BY id', $db->esc_like( $tag ) . '%' ), ARRAY_A ) ?: array();
	$word = array();
	foreach ( $rows as $p ) {
		$word[ (int) $p['id'] ] = substr( (string) $p['first_name'], strlen( $tag ) );
	}
	$by    = array();
	$order = array();
	foreach ( $rows as $p ) {
		$id = (int) $p['id'];
		$g  = array();
		foreach ( Guardians::of( $id ) as $x ) {
			$g[] = $word[ (int) $x['id'] ] ?? '?';
		}
		sort( $g );
		$by[ $word[ $id ] ] = array(
			'type'      => (string) $p['type'],
			'last'      => substr( (string) $p['last_name'], 4 ),
			'card'      => '' === (string) $p['card_number'] ? '' : (string) ( (int) $p['card_number'] - $base ),
			'email'     => str_replace( strtolower( $tag ), '', (string) $p['email'] ),
			'phone'     => substr( preg_replace( '/\D/', '', (string) $p['phone'] ), -6 ),
			'host'      => ! empty( $p['host_person_id'] ) ? ( $word[ (int) $p['host_person_id'] ] ?? '?' ) : '',
			'birth'     => (string) $p['birth_date'],
			'joined'    => (string) $p['joined_on'],
			'guardians' => $g,
			'years'     => $db->get_col( $db->prepare( 'SELECT social_year FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND deleted_at IS NULL ORDER BY social_year', $id ) ),
		);
		$order[] = $word[ $id ];
	}
	return array( 'by' => $by, 'order' => $order );
}

/** Un messaggio senza il prefisso dei nomi e con i numeri di tessera ridotti al numero di riga. */
function asem_imp_norm( string $m, string $tag, int $base ): string {
	$m = str_ireplace( $tag, '', $m );
	return (string) preg_replace_callback(
		'/\b(\d{6})\b/',
		function ( $x ) use ( $base ) {
			return (string) ( (int) $x[1] - $base );
		},
		$m
	);
}

function asem_imp_plan_sig( array $plan, string $tag, int $base ): array {
	$out = array();
	foreach ( $plan as $p ) {
		$out[] = $p['action'] . '|' . asem_imp_norm( (string) $p['message'], $tag, $base );
	}
	return $out;
}

function asem_imp_count( string $tag ): int {
	$db = Db::db();
	return (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . ' WHERE first_name LIKE %s AND deleted_at IS NULL', $db->esc_like( $tag ) . '%' ) );
}

/** Quante righe da creare ci sono nei primi $upto passi dell'ordine di scrittura. */
function asem_imp_creates_upto( array $plan, int $upto ): int {
	$c = 0;
	foreach ( array_slice( ImportService::people_sequence( $plan ), 0, $upto ) as $s ) {
		if ( 'create' === $plan[ $s[0] ]['action'] ) {
			$c++;
		}
	}
	return $c;
}

/** Un giro dopo l'altro, fino alla fine. @return array{0:array,1:int} risultato, numero di giri */
function asem_imp_run_all(): array {
	$giri = 0;
	do {
		$r = ImportJob::step();
		$giri++;
	} while ( ! $r['finished'] && $giri < 20000 );
	return array( $r['result'], $giri );
}

$imp_guests_before = Settings::guests_enabled();
Settings::update( array( 'guests_enabled' => 1, 'limits' => array() ) );
delete_option( ImportJob::OPT_LOCK );
asem_ok( null === ImportJob::current(), 'import a giri: all\'inizio non c\'è nessuna importazione in corso' );

// ---------- Confronto con la vecchia versione ----------
$N = 300; // righe dei file di confronto
$rf_seed = 0;
$rf_pv   = asem_imp_preview( 'Rif', 100000, $N, '1' );
$rf_plan = asem_imp_plan_sig( $rf_pv['people']['plan'], 'Rif', 100000 );
$rf_res  = asem_imp_ref_apply( $rf_pv, true );
$rf_snap = asem_imp_snapshot( 'Rif', 100000 );
$rf_bat  = ImportService::batch( (int) $rf_res['batch_id'] );
$rf_exp_bad = 0; // tutori che non esistono nel file di prova
for ( $i = 0; $i < $N; $i++ ) {
	if ( 9 === $i % 100 ) {
		$rf_exp_bad++;
	}
}
$rf_actions = array_count_values( array_column( $rf_pv['people']['plan'], 'action' ) );
asem_ok( 2 * ( $N / 10 ) === (int) ( $rf_actions['error'] ?? 0 ) && ( $N / 50 ) === (int) ( $rf_actions['update'] ?? 0 ) && count( $rf_res['people']['failed'] ) === $rf_exp_bad, 'import a giri, riferimento: tessere doppie ed email sbagliate sono errori, un socio ogni 50 è già presente e un solo tipo di riga fallisce all\'applicazione (il tutore che non esiste)' );
asem_ok( $rf_res['people']['created'] > 150 && count( $rf_snap['by'] ) === $rf_res['people']['created'] + ( $N / 50 ), 'import a giri, riferimento: le righe valide sono entrate' );
asem_ok( count( array_filter( $rf_snap['by'], function ( $p ) { return 'guest' === $p['type'] && '' !== $p['host']; } ) ) >= 25 && count( array_filter( $rf_snap['by'], function ( $p ) { return $p['guardians']; } ) ) >= 25, 'import a giri, riferimento: ospiti con ospitante e minori con tutore collegati' );

// ---------- Un giro per riga: stesso risultato ----------
$imp_budget = function () {
	return 0.0;
};
add_filter( 'asem_import_budget_seconds', $imp_budget );
$nu_pv = asem_imp_preview( 'Nuo', 200000, $N, '2' );
asem_ok( asem_imp_plan_sig( $nu_pv['people']['plan'], 'Nuo', 200000 ) === $rf_plan, 'import a giri: l\'anteprima dà le stesse righe, esiti e messaggi di quella di riferimento' );
$nu_batch = ImportJob::start( $nu_pv, array( 'mark_members' => true, 'source' => 'nuo.csv' ) );
asem_ok( null !== ImportJob::current() && ImportJob::is_running( $nu_batch ) && 0 === asem_imp_count( 'Nuo' ) - ( $N / 50 ), 'import a giri: avviato, ancora nessuna riga scritta' );
$msg_start = asem_throws( function () use ( $nu_pv ) { ImportJob::start( $nu_pv, array() ); } );
asem_ok( null !== $msg_start && false !== strpos( $msg_start, 'già in corso' ), 'import a giri: con un\'importazione a metà non se ne apre un\'altra' );
asem_ok( null !== asem_throws( function () use ( $nu_batch ) { ImportService::undo( $nu_batch ); } ), 'import a giri: finché è a metà non si annulla' );
list( $nu_res, $nu_giri ) = asem_imp_run_all();
remove_filter( 'asem_import_budget_seconds', $imp_budget );
asem_ok( $nu_giri > 200 && null === ImportJob::current() && false === get_option( ImportJob::OPT_PLAN, false ) && false === get_option( ImportJob::OPT_FLIGHT, false ), 'import a giri: una riga per giro, alla fine non resta nessun segno del lavoro (' . $nu_giri . ' giri)' );
$nu_snap = asem_imp_snapshot( 'Nuo', 200000 );
asem_ok( $nu_res['people']['created'] === $rf_res['people']['created'] && $nu_res['people']['updated'] === $rf_res['people']['updated'], 'import a giri: stessi soci creati e aggiornati della vecchia versione' );
asem_ok( array_map( function ( $m ) { return asem_imp_norm( $m, 'Nuo', 200000 ); }, $nu_res['people']['failed'] ) === array_map( function ( $m ) { return asem_imp_norm( $m, 'Rif', 100000 ); }, $rf_res['people']['failed'] ), 'import a giri: stesse righe non riuscite, con gli stessi messaggi e nello stesso ordine' );
asem_ok( $nu_snap['by'] === $rf_snap['by'], 'import a giri: stessa anagrafica della vecchia versione (tipi, tessere, email, cellulari, ospitanti, tutori, iscrizioni)' );
asem_ok( $nu_snap['order'] === $rf_snap['order'], 'import a giri: le schede nascono nello stesso ordine di prima (prima i soci, poi gli ospiti)' );
$nu_bat = ImportService::batch( (int) $nu_res['batch_id'] );
asem_ok( empty( $nu_bat['data']['job'] ) && count( $nu_bat['data']['people_created'] ) === count( $rf_bat['data']['people_created'] ) && count( $nu_bat['data']['people_updated'] ) === count( $rf_bat['data']['people_updated'] ) && count( $nu_bat['data']['memberships'] ) === count( $rf_bat['data']['memberships'] ) && count( $nu_bat['data']['guardians'] ) === count( $rf_bat['data']['guardians'] ) && $nu_bat['summary'] === $rf_bat['summary'], 'import a giri: la traccia per l\'annullo e il riepilogo sono quelli di prima' );

// ---------- Un giro che muore a metà ----------
$GLOBALS['asem_imp_crash_at']     = -1;
$GLOBALS['asem_imp_crash_flight'] = null;
add_action(
	'asem_import_after_row',
	function ( $i ) {
		if ( (int) $i === (int) $GLOBALS['asem_imp_crash_at'] ) {
			$GLOBALS['asem_imp_crash_at']     = -1;
			$GLOBALS['asem_imp_crash_flight'] = get_option( ImportJob::OPT_FLIGHT, null ); // com'era quando è partito il blocco
			throw new \RuntimeException( 'giro morto' );
		}
	}
);
$die = function () {
	try {
		ImportJob::step( 1000.0 );
	} catch ( \RuntimeException $e ) {
		return true;
	}
	return false;
};

// a) errore a metà blocco: i giri finiti restano, la riga a metà si riprende senza farne due
$cr_pv   = asem_imp_preview( 'Cra', 300000, $N, '3' );
$cr_plan = $cr_pv['people']['plan'];
$cr_seed = (int) ( $N / 50 );
ImportJob::start( $cr_pv, array( 'mark_members' => true, 'source' => 'cra.csv' ) );
$GLOBALS['asem_imp_crash_at'] = 130;
asem_ok( $die(), 'import interrotto: il giro muore dopo aver scritto la riga 131' );
asem_ok( null !== ImportJob::current() && false === get_option( ImportJob::OPT_LOCK, false ) && asem_imp_count( 'Cra' ) === $cr_seed + asem_imp_creates_upto( $cr_plan, 131 ), 'import interrotto: le righe già scritte restano e il blocco è libero' );
list( $cr_res ) = asem_imp_run_all();
$cr_snap = asem_imp_snapshot( 'Cra', 300000 );
asem_ok( asem_imp_count( 'Cra' ) === $cr_seed + $rf_res['people']['created'] && $cr_snap['by'] === $rf_snap['by'] && $cr_snap['order'] === $rf_snap['order'], 'import interrotto: alla ripresa nessuna riga persa né doppia, stessa anagrafica e stesso ordine della vecchia versione' );
asem_ok( $cr_res['people']['created'] === $rf_res['people']['created'] && $cr_res['people']['updated'] === $rf_res['people']['updated'] && count( $cr_res['people']['failed'] ) === count( $rf_res['people']['failed'] ), 'import interrotto: i conti sono quelli di prima (la riga interrotta si conta una volta sola)' );
$cr_bat = ImportService::batch( (int) $cr_res['batch_id'] );
asem_ok( count( $cr_bat['data']['people_created'] ) === count( array_unique( $cr_bat['data']['people_created'] ) ) && count( $cr_bat['data']['people_created'] ) === count( $rf_bat['data']['people_created'] ) && count( $cr_bat['data']['memberships'] ) === count( $rf_bat['data']['memberships'] ), 'import interrotto: la traccia per l\'annullo copre ogni scheda, una volta sola' );

// b) il giro muore senza poter salvare nulla (tempo, memoria): resta solo il segno del blocco
$ft_pv   = asem_imp_preview( 'Fat', 400000, $N, '4' );
$ft_plan = $ft_pv['people']['plan'];
$ft_batch = ImportJob::start( $ft_pv, array( 'mark_members' => true, 'source' => 'fat.csv' ) );
$ft_data  = $wpdb->get_var( 'SELECT data FROM ' . Db::t( 'import_batches' ) . ' WHERE id = ' . (int) $ft_batch );
$ft_ptr   = get_option( ImportJob::OPT_JOB );
$GLOBALS['asem_imp_crash_at'] = 130;
asem_ok( $die(), 'import interrotto di netto: il giro muore a metà del primo blocco' );
$wpdb->update( Db::t( 'import_batches' ), array( 'data' => $ft_data ), array( 'id' => $ft_batch ) ); // come se il giro non avesse potuto salvare nulla dopo l'inizio del blocco
update_option( ImportJob::OPT_JOB, $ft_ptr, false );
update_option( ImportJob::OPT_FLIGHT, $GLOBALS['asem_imp_crash_flight'], false );
$wpdb->query( 'DELETE FROM ' . $wpdb->options . " WHERE option_name = '" . ImportJob::OPT_LOCK . "'" );
$wpdb->query( 'INSERT INTO ' . $wpdb->options . " (option_name, option_value, autoload) VALUES ('" . ImportJob::OPT_LOCK . "', '" . ( time() - 3600 ) . "', 'no')" ); // il blocco rimasto del giro morto è scaduto
asem_ok( is_array( $GLOBALS['asem_imp_crash_flight'] ) && 0 === (int) $GLOBALS['asem_imp_crash_flight']['from'] && asem_imp_count( 'Fat' ) === $cr_seed + asem_imp_creates_upto( $ft_plan, 131 ),'import interrotto di netto: lo stato è fermo all\'inizio del blocco ma le schede già scritte ci sono' );
list( $ft_res ) = asem_imp_run_all();
$ft_snap = asem_imp_snapshot( 'Fat', 400000 );
asem_ok( asem_imp_count( 'Fat' ) === $cr_seed + $rf_res['people']['created'] && $ft_snap['by'] === $rf_snap['by'] && $ft_snap['order'] === $rf_snap['order'], 'import interrotto di netto: il blocco si rifà riconoscendo le schede già scritte, nessuna riga persa né doppia' );
asem_ok( $ft_res['people']['created'] === $rf_res['people']['created'] && $ft_res['people']['updated'] === $rf_res['people']['updated'] && count( $ft_res['people']['failed'] ) === count( $rf_res['people']['failed'] ), 'import interrotto di netto: i conti sono quelli di prima' );
$ft_bat = ImportService::batch( (int) $ft_res['batch_id'] );
$ft_back = true;
foreach ( $ft_bat['data']['people_updated'] as $before ) {
	$ft_back = $ft_back && 0 === strpos( preg_replace( '/\D/', '', (string) $before['phone'] ), '320' ); // le schede com'erano prima dell'import, non già aggiornate
}
asem_ok( $ft_back && count( $ft_bat['data']['people_updated'] ) === count( $rf_bat['data']['people_updated'] ) && count( array_unique( $ft_bat['data']['people_created'] ) ) === count( $rf_bat['data']['people_created'] ) && count( $ft_bat['data']['guardians'] ) === count( $rf_bat['data']['guardians'] ), 'import interrotto di netto: la traccia ricorda le schede com\'erano prima e copre tutto' );
ImportService::undo( (int) $ft_res['batch_id'] );
$ft_phones = true;
foreach ( $wpdb->get_col( 'SELECT phone FROM ' . Db::t( 'people' ) . " WHERE first_name LIKE 'Fat%' AND deleted_at IS NULL" ) as $ph ) {
	$ft_phones = $ft_phones && 0 === strpos( preg_replace( '/\D/', '', (string) $ph ), '320' );
}
asem_ok( asem_imp_count( 'Fat' ) === $cr_seed && $ft_phones, 'import interrotto di netto: l\'annullo toglie tutto quello che è entrato e rimette le schede aggiornate' );
remove_all_actions( 'asem_import_after_row' );

// ---------- Un giro per volta, fermare, pagina e azioni ----------
$bk_pv    = asem_imp_preview( 'Blk', 500000, 60, '5' );
$bk_seed  = 2;
$bk_batch = ImportJob::start( $bk_pv, array( 'source' => 'blk.csv' ) );
$wpdb->query( 'INSERT INTO ' . $wpdb->options . " (option_name, option_value, autoload) VALUES ('" . ImportJob::OPT_LOCK . "', '" . time() . "', 'no')" ); // un altro giro in corso
$msg_lock = asem_throws( function () { ImportJob::step(); } );
asem_ok( null !== $msg_lock && false !== strpos( $msg_lock, 'altra operazione' ) && $bk_seed === asem_imp_count( 'Blk' ), 'import a giri: con un altro giro in corso non parte nulla e non si scrive niente' );
$wpdb->query( 'UPDATE ' . $wpdb->options . " SET option_value = '" . ( time() - 600 ) . "' WHERE option_name = '" . ImportJob::OPT_LOCK . "'" ); // scaduto: lo prende chi arriva
add_filter( 'asem_import_budget_seconds', $imp_budget );
$bk_r = ImportJob::step();
remove_filter( 'asem_import_budget_seconds', $imp_budget );
asem_ok( false === $bk_r['finished'] && 1 === $bk_r['done'] && false === get_option( ImportJob::OPT_LOCK, false ), 'import a giri: un blocco scaduto non ferma per sempre, e a fine giro il blocco è libero' );
$html = asem_render( array( Admin\ImportPage::class, 'render' ), 'asem_import_continue' );
asem_ok( false !== strpos( $html, 'elaborate 1 righe su' ) && false !== strpos( $html, 'asem_import_stop' ) && false === strpos( $html, 'name="file"' ), 'pagina: l\'importazione a metà mostra l\'avanzamento con «Riprendi» e «Ferma», e non il caricamento di un altro file' );
asem_ok( 0 === strpos( (string) ( new ReflectionMethod( Admin\Actions::class, 'import_continue' ) )->getName(), 'import_continue' ), 'azione «Riprendi l\'importazione» registrata' );
$cont = new ReflectionMethod( Admin\Actions::class, 'import_continue' );
$cont->setAccessible( true );
add_filter( 'asem_import_budget_seconds', $imp_budget );
$cr = $cont->invoke( null, array() );
remove_filter( 'asem_import_budget_seconds', $imp_budget );
asem_ok( false !== strpos( $cr[0], 'asem-import' ) && false !== strpos( $cr[1], 'Importazione in corso: elaborate 2 righe su' ), 'azione «Riprendi»: un altro giro, con il messaggio dell\'avanzamento' );
$stop = new ReflectionMethod( Admin\Actions::class, 'import_stop' );
$stop->setAccessible( true );
$sr = $stop->invoke( null, array() );
asem_ok( false !== strpos( $sr[1], 'Importazione fermata' ) && null === ImportJob::current() && false === get_option( ImportJob::OPT_PLAN, false ), 'azione «Ferma»: l\'importazione si chiude con quello che c\'è' );
asem_ok( asem_imp_count( 'Blk' ) > $bk_seed && asem_imp_count( 'Blk' ) < 60, 'fermata a metà: le prime righe sono entrate, le altre no' );
ImportService::undo( $bk_batch );
asem_ok( asem_imp_count( 'Blk' ) === $bk_seed, 'fermata a metà: l\'annullo copre tutto quello che è entrato fino a lì' );
asem_ok( null !== asem_throws( function () { ImportJob::step(); } ) && null !== asem_throws( function () { ImportJob::stop(); } ), 'senza importazione in corso riprendere o fermare dice che non c\'è nulla' );

// l'azione «Importa» dell'anteprima: parte, e se il tempo finisce si riprende
$ap_pv  = asem_imp_preview( 'Apa', 550000, 40, '6' );
$ap_tok = 'imp' . strtolower( wp_generate_password( 6, false ) );
set_transient( 'asem_import_' . get_current_user_id() . '_' . $ap_tok, $ap_pv, HOUR_IN_SECONDS );
$apply = new ReflectionMethod( Admin\Actions::class, 'import_apply' );
$apply->setAccessible( true );
add_filter( 'asem_import_budget_seconds', $imp_budget );
$ar = $apply->invoke( null, array( 'token' => $ap_tok ) );
remove_filter( 'asem_import_budget_seconds', $imp_budget );
asem_ok( false !== strpos( $ar[1], 'Importazione in corso: elaborate 1 righe su' ) && false === get_transient( 'asem_import_' . get_current_user_id() . '_' . $ap_tok ) && null !== ImportJob::current(), 'azione «Importa»: scrive il primo giro, poi l\'anteprima non serve più e si riprende dall\'avanzamento [' . $ar[1] . ']' );
$ar2 = $stop->invoke( null, array() );
asem_ok( false !== strpos( $ar2[1], 'Importazione fermata' ), 'azione «Importa»: si può fermare subito' );
ImportService::undo( (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Db::t( 'import_batches' ) ) );

// un import lasciato a metà per troppo tempo si chiude da solo
$st_pv    = asem_imp_preview( 'Old', 560000, 20, '7' );
$st_batch = ImportJob::start( $st_pv, array( 'source' => 'old.csv' ) );
$st_ptr2  = get_option( ImportJob::OPT_JOB );
$st_ptr2['ts'] = time() - 30 * DAY_IN_SECONDS;
update_option( ImportJob::OPT_JOB, $st_ptr2, false );
asem_ok( null === ImportJob::current() && false === get_option( ImportJob::OPT_PLAN, false ), 'import lasciato a metà da troppo tempo: si chiude da solo e libera i dati dell\'anteprima' );

// ---------- Un file grande ----------
$BIG    = 5000;
$bg_pv  = asem_imp_preview( 'Big', 600000, $BIG, '8' );
$bg_pl  = $bg_pv['people']['plan'];
$bg_bad = 0;
$bg_cre = 0;
$bg_upd = 0;
$bg_err = 0;
foreach ( $bg_pl as $i => $p ) {
	$should_err = in_array( $i % 10, array( 3, 5 ), true );
	if ( ( 'error' === $p['action'] ) !== $should_err ) {
		$bg_bad++;
	}
	if ( 'update' === $p['action'] ) {
		$bg_upd++;
		if ( 0 !== $i % 50 ) {
			$bg_bad++;
		}
	}
	$bg_cre += 'create' === $p['action'] ? 1 : 0;
	$bg_err += 'error' === $p['action'] ? 1 : 0;
}
asem_ok( $BIG === count( $bg_pl ) && 0 === $bg_bad && 1000 === $bg_err && 100 === $bg_upd && 3900 === $bg_cre, 'file grande: 5.000 righe, 1.000 errori (tessere doppie ed email sbagliate), 100 aggiornamenti e 3.900 nuovi' );
$imp_budget_big = function () {
	return 1.5;
};
add_filter( 'asem_import_budget_seconds', $imp_budget_big );
$bg_batch = ImportJob::start( $bg_pv, array( 'source' => 'big.csv' ) );
list( $bg_res, $bg_giri ) = asem_imp_run_all();
remove_filter( 'asem_import_budget_seconds', $imp_budget_big );
asem_ok( $bg_giri >= 3 && null === ImportJob::current(), 'file grande: non basta un giro, si riprende dal punto salvato fino alla fine (' . $bg_giri . ' giri)' );
asem_ok( $bg_res['people']['created'] === $bg_cre && $bg_res['people']['updated'] === $bg_upd && 50 === count( $bg_res['people']['failed'] ), 'file grande: 3.900 creati, 100 aggiornati, e solo i 50 tutori che non esistono non riusciti' );
asem_ok( asem_imp_count( 'Big' ) === 100 + $bg_cre, 'file grande: nessuna scheda doppia né mancante' );
$bg_bat = ImportService::batch( $bg_batch );
$bg_words = array();
$bg_want  = array();
$bg_by_id = array();
foreach ( $wpdb->get_results( "SELECT id, first_name FROM " . Db::t( 'people' ) . " WHERE first_name LIKE 'Big%' AND deleted_at IS NULL", ARRAY_A ) as $row ) {
	$bg_by_id[ (int) $row['id'] ] = substr( (string) $row['first_name'], 3 );
}
foreach ( $bg_bat['data']['people_created'] as $id ) {
	$bg_words[] = $bg_by_id[ (int) $id ] ?? '?';
}
foreach ( ImportService::people_sequence( $bg_pl ) as $s ) {
	if ( 'create' === $bg_pl[ $s[0] ]['action'] ) {
		$bg_want[] = asem_imp_word( $s[0] );
	}
}
asem_ok( $bg_words === $bg_want, 'file grande: le schede sono nate nell\'ordine di prima, prima i soci e poi gli ospiti' );
$bg_hosts = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . " WHERE first_name LIKE 'Big%' AND type = 'guest' AND host_person_id > 0 AND deleted_at IS NULL" );
$bg_kids  = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT person_id) FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . " p ON p.id = g.person_id WHERE p.first_name LIKE 'Big%'" );
asem_ok( 500 === $bg_hosts && 450 === $bg_kids, 'file grande: tutti i 500 ospiti hanno l\'ospitante (anche creato nello stesso file) e 450 minori il tutore' );
$bg_undo = ImportService::undo( $bg_batch );
asem_ok( asem_imp_count( 'Big' ) === 100 && $bg_undo['people_removed'] === $bg_cre && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . " p ON p.id = g.person_id WHERE p.first_name LIKE 'Big%' AND p.deleted_at IS NULL" ), 'file grande: l\'annullo in blocco toglie tutte le schede nate in tutti i giri e i loro tutori' );

// ---------- Pulizia ----------
Settings::update( array( 'guests_enabled' => $imp_guests_before ? 1 : 0 ) );
remove_all_filters( 'asem_import_budget_seconds' );
remove_all_actions( 'asem_import_after_row' );
delete_option( ImportJob::OPT_LOCK );
asem_ok( null === ImportJob::current(), 'import a giri: finite le prove nessuna importazione resta in corso' );

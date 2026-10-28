<?php
/**
 * Dati della demo pubblica (associazionesemplice.it/demo). Si lancia con: wp eval-file provision.php
 * Dopo l'installazione pulita di WordPress e l'attivazione dei due plugin: crea la segreteria di prova, il socio di prova,
 * dei soci inventati, un corso, due eventi e qualche movimento di prima nota. Nessun utente amministratore.
 * Le credenziali sono pubbliche (stanno nella pagina «Il plugin» del portale): niente dati veri qui dentro.
 */
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Wizard;

const DEMO_PASS_SEGRETERIA = 'Segreteria-Demo-2026';
const DEMO_PASS_SOCIO      = 'Socio-Demo-2026';

function demo_step( string $what, callable $fn ) {
	try {
		$r = $fn();
		WP_CLI::log( '  ok ' . $what );
		return $r;
	} catch ( \Throwable $e ) {
		WP_CLI::warning( $what . ': ' . $e->getMessage() );
		return null;
	}
}

// 1. Impostazioni dell'ente e delle funzioni
demo_step( 'ente e funzioni', function () {
	Wizard::apply( array(
		'association_name' => 'Associazione Demo APS',
		'legal_address'    => 'Via della Prova 1',
		'legal_zip'        => '00100',
		'legal_city'       => 'Roma',
		'legal_province'   => 'RM',
		'guests_enabled'   => 1,
		'join_mode'        => 'request',
		'membership_fee'   => '20,00',
	) );
	Wizard::mark( Wizard::DONE );
} );

// 1b. Pagine del sito e pagina iniziale
demo_step( 'pagine', function () {
	\AssociazioneSemplice\Pages::create( array( 'area', 'attivita', 'adesione', 'privacy', 'segreteria', 'tesoriere' ) );
	$have = \AssociazioneSemplice\Pages::existing();
	$link = function ( string $key, string $text ) use ( $have ): string {
		return isset( $have[ $key ] ) ? '<li><a href="' . esc_url( (string) get_permalink( $have[ $key ] ) ) . '">' . esc_html( $text ) . '</a></li>' : '';
	};
	$home = wp_insert_post( array(
		'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Demo di Associazione Semplice',
		'post_content' => '<p>Questa è una demo: i dati sono inventati e si azzerano ogni notte. Per provare le funzioni entra con le credenziali scritte nella pagina <a href="https://associazionesemplice.it/plugin">Il plugin</a> del sito associazionesemplice.it.</p>'
			. '<ul>' . $link( 'area', 'Area soci (entra come socio)' ) . $link( 'attivita', 'Attività ed eventi' ) . $link( 'adesione', 'Diventa socio (domanda online)' ) . $link( 'segreteria', 'Area segreteria (entra come segreteria)' ) . '</ul>'
			. '<p>La segreteria lavora anche nel pannello: <a href="' . esc_url( admin_url( 'admin.php?page=asem-dashboard' ) ) . '">Bacheca di Associazione Semplice</a>.</p>',
	) );
	if ( $home && ! is_wp_error( $home ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $home );
	}
} );

// 2. Licenza Pro (se la chiave è stata messa da parte dal portale)
$keyfile = getenv( 'HOME' ) . '/.demo_key';
if ( is_readable( $keyfile ) ) {
	demo_step( 'licenza Pro', function () use ( $keyfile ) {
		Settings::update( array( 'license_key' => trim( (string) file_get_contents( $keyfile ) ) ) );
		$r = \AssociazioneSemplice\License::sync();
		WP_CLI::log( '     ' . $r['message'] );
	} );
} else {
	WP_CLI::warning( 'Nessuna chiave in ~/.demo_key: la demo parte con le funzioni gratuite.' );
}

// 3. Utenti di prova (WordPress): segreteria e socio, mai amministratori
$mk_user = function ( string $login, string $email, string $name, string $role, string $pass ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$id = wp_insert_user( array(
		'user_login'   => $login,
		'user_email'   => $email,
		'user_pass'    => $pass,
		'display_name' => $name,
		'role'         => $role,
	) );
	if ( is_wp_error( $id ) ) {
		throw new \RuntimeException( $id->get_error_message() );
	}
	update_user_meta( (int) $id, 'asem_demo_user', '1' );
	return (int) $id;
};
demo_step( 'utente segreteria', function () use ( $mk_user ) {
	$mk_user( 'segreteria', 'segreteria@demo.invalid', 'Laura Demo (segreteria)', Plugin::ROLE_SECRETARY, DEMO_PASS_SEGRETERIA );
} );
demo_step( 'utente socio', function () use ( $mk_user ) {
	$mk_user( 'socio', 'socio@demo.invalid', 'Mario Demo (socio)', Plugin::ROLE_MEMBER, DEMO_PASS_SOCIO );
} );

// 4. Persone: le due di prova (collegate agli utenti per email) e dei soci inventati
$people = Plugin::people();
$ids    = array();
$add    = function ( string $first, string $last, string $type, ?string $email, string $city, string $joined, ?string $phone = null ) use ( $people, &$ids ) {
	$id = $people->create( array(
		'type' => $type, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
		'city' => $city, 'province' => 'RM', 'joined_on' => $joined,
	) );
	$ids[ $first . ' ' . $last ] = $id;
	return $id;
};
$y = (int) gmdate( 'Y' );
demo_step( 'soci', function () use ( $add, $y ) {
	$add( 'Laura', 'Demo', 'ordinary', 'segreteria@demo.invalid', 'Roma', ( $y - 3 ) . '-09-10' );
	$add( 'Mario', 'Demo', 'ordinary', 'socio@demo.invalid', 'Roma', ( $y - 1 ) . '-10-02' );
	$list = array(
		array( 'Anna', 'Verdi', 'founder', 'Roma', ( $y - 5 ) . '-09-01' ), array( 'Marco', 'Bianchi', 'founder', 'Roma', ( $y - 5 ) . '-09-01' ),
		array( 'Luca', 'Neri', 'ordinary', 'Frascati', ( $y - 2 ) . '-10-15' ), array( 'Sara', 'Gallo', 'ordinary', 'Roma', ( $y - 2 ) . '-11-03' ),
		array( 'Paolo', 'Ferri', 'volunteer', 'Tivoli', ( $y - 1 ) . '-01-20' ), array( 'Elena', 'Conti', 'ordinary', 'Roma', ( $y - 1 ) . '-02-11' ),
		array( 'Davide', 'Greco', 'ordinary', 'Ciampino', ( $y - 1 ) . '-03-05' ), array( 'Chiara', 'Moretti', 'volunteer', 'Roma', ( $y - 1 ) . '-09-18' ),
		array( 'Franco', 'Riva', 'ordinary', 'Roma', ( $y - 1 ) . '-09-18' ), array( 'Marta', 'Serra', 'ordinary', 'Grottaferrata', gmdate( 'Y' ) . '-01-09' ),
		array( 'Giorgio', 'Fontana', 'ordinary', 'Roma', gmdate( 'Y' ) . '-02-14' ), array( 'Irene', 'Costa', 'ordinary', 'Roma', gmdate( 'Y' ) . '-03-22' ),
	);
	foreach ( $list as $r ) {
		$add( $r[0], $r[1], $r[2], null, $r[3], $r[4] );
	}
} );

// 5. Cariche: la segreteria di prova è anche vicepresidente? No: resta solo segreteria. Presidente e tesoriere inventati.
demo_step( 'cariche', function () use ( $people, &$ids ) {
	if ( isset( $ids['Anna Verdi'] ) ) {
		$people->set_board_role( $ids['Anna Verdi'], \AssociazioneSemplice\BoardRole::PRESIDENT );
	}
} );

// 6. Prima nota: quote associative incassate e qualche spesa
$ledger = Plugin::ledger();
demo_step( 'quote incassate', function () use ( $ledger, &$ids ) {
	$cat  = $ledger->category_id_of_kind( 'membership' );
	$acc  = $ledger->default_account_for( 'cash' );
	$days = 40;
	foreach ( array( 'Laura Demo', 'Mario Demo', 'Luca Neri', 'Sara Gallo', 'Paolo Ferri', 'Elena Conti', 'Marta Serra', 'Chiara Moretti' ) as $n ) { // i fondatori hanno la tessera sempre rinnovata
		if ( empty( $ids[ $n ] ) || ! $acc || ! $cat ) {
			continue;
		}
		try {
			$ledger->record_receipt( array(
				'date' => gmdate( 'Y-m-d', time() - ( --$days ) * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'person_id' => $ids[ $n ],
				'lines' => array( array( 'category_id' => $cat, 'amount_cents' => 2000, 'description' => 'Quota associativa' ) ),
			) );
		} catch ( \Throwable $e ) {
			WP_CLI::warning( $n . ': ' . $e->getMessage() );
		}
	}
} );
demo_step( 'spese', function () use ( $ledger ) {
	$acc = $ledger->default_account_for( 'cash' );
	$cat = $ledger->category_id_of_kind( 'general_cost' );
	if ( ! $acc || ! $cat ) {
		return;
	}
	foreach ( array( array( 35, 12500, 'Affitto sala per la riunione' ), array( 20, 4800, 'Materiale di cancelleria' ), array( 8, 9000, 'Assicurazione dei volontari' ) ) as $s ) {
		$ledger->record_expense( array( 'date' => gmdate( 'Y-m-d', time() - $s[0] * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'category_id' => $cat, 'amount_cents' => $s[1], 'description' => $s[2] ) );
	}
} );

// 7. Un corso e due eventi
demo_step( 'attività', function () use ( &$ids ) {
	$act  = Plugin::activities();
	$year = Settings::membership_year( gmdate( 'Y-m-d' ) )->label();
	$act->create( array( 'name' => 'Corso di fotografia', 'social_year' => $year, 'kind' => 'course', 'instructor_person_id' => $ids['Paolo Ferri'] ?? null, 'fee_cents' => 2500 ) );
	foreach ( array( array( 'Cena sociale', 12, 1500, 60 ), array( 'Gita al lago', 26, 1000, 30 ) ) as $e ) {
		$act->create( array(
			'name' => $e[0], 'social_year' => $year, 'kind' => 'event', 'instructor_person_id' => $ids['Chiara Moretti'] ?? null, 'fee_cents' => $e[2],
			'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + $e[1] * 86400 ), 'start_time' => '20:00', 'location' => 'Sede dell\'associazione', 'capacity' => $e[3] ),
		) );
	}
} );

WP_CLI::success( 'Dati della demo creati.' );

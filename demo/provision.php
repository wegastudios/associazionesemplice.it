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
const DEMO_PASS_AMMIN      = 'Amministratore-Demo-2026';

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
		$url = function ( string $key ) use ( $have ): string {
			return isset( $have[ $key ] ) ? (string) get_permalink( $have[ $key ] ) : home_url( '/' );
		};
		$btn = function ( string $text, string $href, bool $outline = false ): string {
			return '<!-- wp:button' . ( $outline ? ' {"className":"is-style-outline"}' : '' ) . ' --><div class="wp-block-button' . ( $outline ? ' is-style-outline' : '' ) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . esc_html( $text ) . '</a></div><!-- /wp:button -->';
		};
		$card = function ( string $title, string $text, string $extra, string $buttons ): string {
			return '<!-- wp:column --><div class="wp-block-column"><!-- wp:group {"className":"asem-card","layout":{"type":"constrained"}} --><div class="wp-block-group asem-card">'
				. '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $title ) . '</h3><!-- /wp:heading -->'
				. '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' . $extra
				. '<!-- wp:buttons --><div class="wp-block-buttons">' . $buttons . '</div><!-- /wp:buttons --></div><!-- /wp:group --></div><!-- /wp:column -->';
		};
		$cred = function ( string $user, string $pass, string $label = 'Utente' ): string {
			return '<!-- wp:html --><p class="asem-cred">' . esc_html( $label ) . ' <code>' . esc_html( $user ) . '</code><br>Password <code>' . esc_html( $pass ) . '</code></p><!-- /wp:html -->';
		};
		$content = '<!-- wp:group {"className":"asem-hero","align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide asem-hero">'
			. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Prova Associazione Semplice</h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Una demo per provare le funzioni del Pro: un sito WordPress vero con il plugin gratuito e il Pro già installati, pieno di dati inventati. Clicca, inserisci, cancella: non rompi niente. Tutto si azzera ogni notte alle 4 e non parte nessuna email.</p><!-- /wp:paragraph -->'
			. '<!-- wp:buttons --><div class="wp-block-buttons">' . $btn( 'Entra come segreteria', wp_login_url( admin_url( 'admin.php?page=asem' ) ) ) . $btn( 'Entra come socio', \AssociazioneSemplice\Frontend\MemberLoginPage::url(), true ) . '</div><!-- /wp:buttons --></div><!-- /wp:group -->'
			. '<!-- wp:spacer {"height":"36px"} --><div style="height:36px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
			. '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
			. $card( 'Senza entrare', 'Guarda cosa vede chi visita il sito dell\'associazione: l\'elenco di corsi ed eventi e la domanda di adesione online.', '', $btn( 'Attività ed eventi', $url( 'attivita' ) ) . $btn( 'Diventa socio', $url( 'adesione' ), true ) )
			. $card( 'Come socio', 'La tessera con QR, le prenotazioni, i pagamenti e i propri dati: l\'area riservata che ogni socio ha sul sito.', $cred( 'socio@demo.invalid', 'Socio-Demo-2026', 'Email' ), $btn( 'Area soci', $url( 'area' ) ) )
			. $card( 'Come segreteria', 'Soci, incassi, spese, attività e registri: il lavoro di ogni giorno, dal pannello o dal sito.', $cred( 'segreteria@demo.invalid', 'Segreteria-Demo-2026', 'Email' ), $btn( 'Bacheca', admin_url( 'admin.php?page=asem' ) ) . $btn( 'Area segreteria', $url( 'segreteria' ), true ) )
			. $card( 'Come amministratore del plugin', 'Tutte le impostazioni di Associazione Semplice. Qui puoi scaricare una copia dei tuoi dati e ricaricarla il giorno dopo, per provare a lungo.', $cred( 'amministratore@demo.invalid', 'Amministratore-Demo-2026', 'Email' ), $btn( 'Copia di sicurezza', admin_url( 'admin.php?page=asem-backup' ) ) . $btn( 'Impostazioni', admin_url( 'admin.php?page=asem-settings' ), true ) )
			. '</div><!-- /wp:columns -->'
			. '<!-- wp:shortcode -->[asem_demo_confronto]<!-- /wp:shortcode -->'
			. '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Per i ruoli del plugin, i collegamenti e il confronto con le versioni: <a href="https://associazionesemplice.it/prova">associazionesemplice.it/prova</a></p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center"><strong>Ti è piaciuta?</strong> <a href="https://associazionesemplice.it/free">Ottieni la licenza gratuita</a>: crei il tuo account, accedi al supporto e alle demo dei piani Pro.</p><!-- /wp:paragraph -->';
		$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Demo di Associazione Semplice', 'post_name' => 'demo', 'post_content' => $content ) );
		// Menu del sito: solo ciò che serve a chi prova.
		$nav = '';
		foreach ( array( array( 'Attività ed eventi', 'attivita' ), array( 'Diventa socio', 'adesione' ), array( 'Area soci', 'area' ), array( 'Area segreteria', 'segreteria' ) ) as $n ) {
			$nav .= '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => $n[0], 'url' => $url( $n[1] ), 'kind' => 'custom' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' /-->';
		}
		wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Menu della demo', 'post_name' => 'menu-demo', 'post_content' => wp_slash( $nav ) ) );
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
// Il socio di prova NON ha un utente WordPress: se l'avesse, collegato per email, la persona non potrebbe entrare con l'accesso proprio (vedi 4.).
// Amministratore del plugin: tutti i permessi di Associazione Semplice (impostazioni, copia di sicurezza, ruoli, registro) ma non è un amministratore di WordPress:
// niente plugin, temi, utenti o impostazioni del sito. Mai il ruolo «administrator».
demo_step( 'ruolo e utente amministratore del plugin', function () use ( $mk_user ) {
	remove_role( 'asem_demo_admin' );
	add_role( 'asem_demo_admin', 'Amministratore del plugin', array( 'read' => true, Plugin::CAP => true, Plugin::CAP_OPS => true ) );
	$mk_user( 'amministratore', 'amministratore@demo.invalid', 'Amministratore del plugin (demo)', 'asem_demo_admin', DEMO_PASS_AMMIN );
} );

// 4. Persone: le due di prova (collegate agli utenti per email) e dei soci inventati
/** Dati anagrafici inventati per i soci della demo: codice fiscale di forma valida (lettera di controllo calcolata), nascita e residenza. */
function demo_profile( string $first, string $last, int $n ): array {
	$cons = function ( string $s ): string { return strtoupper( substr( preg_replace( '/[^BCDFGHJKLMNPQRSTVWXYZ]/i', '', $s ) . 'XXX', 0, 3 ) ); };
	$year = 1960 + ( $n * 7 ) % 40;
	$day  = 1 + ( $n * 5 ) % 27;
	$base = $cons( $last ) . $cons( $first ) . sprintf( '%02d', $year % 100 ) . 'M' . sprintf( '%02d', $day ) . 'H501';
	$code = $base;
	foreach ( range( 'A', 'Z' ) as $c ) {
		if ( \AssociazioneSemplice\TaxCode::is_valid( $base . $c ) ) {
			$code = $base . $c;
			break;
		}
	}
	return array(
		'tax_code' => $code, 'birth_date' => sprintf( '%d-08-%02d', $year, $day ), 'birth_place' => 'Roma', 'province' => 'RM',
		'address' => 'Via dei Fori ' . ( 1 + $n ), 'zip' => '00100',
		'phone' => '+39 06 555' . sprintf( '%04d', 1000 + $n * 37 ),
	);
}

$people = Plugin::people();
$ids    = array();
$add    = function ( string $first, string $last, string $type, ?string $email, string $city, string $joined, ?string $phone = null, bool $full = false ) use ( $people, &$ids ) {
	$id = $people->create( ( $full ? demo_profile( $first, $last, count( $ids ) + 1 ) : array() ) + array(
		'type' => $type, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
		'city' => $city, 'province' => 'RM', 'joined_on' => $joined, 'card_number' => $people->next_free_card(),
	) );
	$ids[ $first . ' ' . $last ] = $id;
	return $id;
};
$y = (int) gmdate( 'Y' );
demo_step( 'soci', function () use ( $add, $y ) {
	$add( 'Laura', 'Demo', 'ordinary', 'segreteria@demo.invalid', 'Roma', ( $y - 3 ) . '-09-10', null, true );
	$mario = $add( 'Mario', 'Demo', 'ordinary', 'socio@demo.invalid', 'Roma', ( $y - 1 ) . '-10-02', null, true );
	// Dal plugin 1.3 il socio entra con email e password della sua scheda (non è più un utente WordPress): senza questa riga l'accesso si perde a ogni azzeramento.
	\AssociazioneSemplice\MemberLogin::set_password( (int) $mario, DEMO_PASS_SOCIO );
	$list = array(
		array( 'Anna', 'Verdi', 'founder', 'Roma', ( $y - 5 ) . '-09-01' ), array( 'Marco', 'Bianchi', 'founder', 'Roma', ( $y - 5 ) . '-09-01' ),
		array( 'Luca', 'Neri', 'ordinary', 'Frascati', ( $y - 2 ) . '-10-15' ), array( 'Sara', 'Gallo', 'ordinary', 'Roma', ( $y - 2 ) . '-11-03' ),
		array( 'Paolo', 'Ferri', 'volunteer', 'Tivoli', ( $y - 1 ) . '-01-20' ), array( 'Elena', 'Conti', 'ordinary', 'Roma', ( $y - 1 ) . '-02-11' ),
		array( 'Davide', 'Greco', 'ordinary', 'Ciampino', ( $y - 1 ) . '-03-05' ), array( 'Chiara', 'Moretti', 'volunteer', 'Roma', ( $y - 1 ) . '-09-18' ),
		array( 'Franco', 'Riva', 'ordinary', 'Roma', ( $y - 1 ) . '-09-18' ), array( 'Marta', 'Serra', 'ordinary', 'Grottaferrata', gmdate( 'Y' ) . '-01-09' ),
		array( 'Giorgio', 'Fontana', 'ordinary', 'Roma', gmdate( 'Y' ) . '-02-14' ), array( 'Irene', 'Costa', 'ordinary', 'Roma', gmdate( 'Y' ) . '-03-22' ),
	);
	foreach ( $list as $r ) {
		$add( $r[0], $r[1], $r[2], null, $r[3], $r[4], null, ! in_array( $r[1], array( 'Fontana', 'Costa' ), true ) ); // gli ultimi due iscritti restano da completare: la Bacheca mostra l'avviso
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
	$days = 50;
	foreach ( array( 'Laura Demo', 'Mario Demo', 'Luca Neri', 'Sara Gallo', 'Paolo Ferri', 'Elena Conti', 'Marta Serra', 'Chiara Moretti', 'Davide Greco', 'Franco Riva', 'Giorgio Fontana', 'Irene Costa' ) as $n ) { // i fondatori hanno la tessera sempre rinnovata
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
demo_step( 'donazioni', function () use ( $ledger ) {
	$acc = $ledger->default_account_for( 'cash' );
	$cat = $ledger->category_id_of_kind( 'donation' );
	if ( ! $acc || ! $cat ) {
		return;
	}
	foreach ( array( array( 30, 10000, 'Erogazione liberale' ), array( 14, 5000, 'Offerta alla cena di raccolta fondi' ) ) as $d ) {
		$ledger->record_receipt( array(
			'date' => gmdate( 'Y-m-d', time() - $d[0] * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash',
			'lines' => array( array( 'category_id' => $cat, 'amount_cents' => $d[1], 'description' => $d[2] ) ),
		) );
	}
} );
demo_step( 'spese', function () use ( $ledger ) {
	$acc = $ledger->default_account_for( 'cash' );
	$cat = $ledger->category_id_of_kind( 'general_cost' );
	if ( ! $acc || ! $cat ) {
		return;
	}
	foreach ( array( array( 35, 12500, 'Affitto sala per la riunione' ), array( 20, 4800, 'Materiale di cancelleria' ), array( 8, 6000, 'Assicurazione dei volontari' ), array( 22, 9500, 'Noleggio attrezzatura per il corso' ), array( 5, 3800, 'Rinfresco dopo la riunione' ) ) as $s ) {
		$ledger->record_expense( array( 'date' => gmdate( 'Y-m-d', time() - $s[0] * 86400 ), 'account_id' => (int) $acc['id'], 'method' => 'cash', 'category_id' => $cat, 'amount_cents' => $s[1], 'description' => $s[2] ) );
	}
} );

// 7. Un corso e due eventi, con iscritti e prenotazioni: chi prova vede subito elenchi, posti e importi da incassare.
demo_step( 'attività', function () use ( &$ids ) {
	$act  = Plugin::activities();
	$year = Settings::social_year()->label();
	$mario = $ids['Mario Demo'] ?? 0;
	$course = $act->create( array( 'name' => 'Corso di fotografia', 'social_year' => $year, 'kind' => 'course', 'instructor_person_id' => $ids['Paolo Ferri'] ?? null, 'fee_cents' => 2500 ) );
	$month  = gmdate( 'Y-m' );
	foreach ( array( 'Mario Demo', 'Elena Conti', 'Davide Greco', 'Marta Serra', 'Irene Costa' ) as $n ) {
		if ( ! empty( $ids[ $n ] ) ) {
			try {
				$act->enroll( (int) $course, (int) $ids[ $n ], $month );
			} catch ( \Throwable $e ) {
				WP_CLI::warning( 'iscrizione ' . $n . ': ' . $e->getMessage() );
			}
		}
	}
	$guests = array(
		'Cena sociale' => array( 'Mario Demo', 'Anna Verdi', 'Marco Bianchi', 'Luca Neri', 'Sara Gallo', 'Elena Conti', 'Franco Riva', 'Giorgio Fontana' ),
		'Gita al lago' => array( 'Anna Verdi', 'Paolo Ferri', 'Chiara Moretti', 'Irene Costa' ),
	);
	foreach ( array( array( 'Cena sociale', 12, 1500, 60 ), array( 'Gita al lago', 26, 1000, 30 ) ) as $e ) {
		$aid = $act->create( array(
			'name' => $e[0], 'social_year' => $year, 'kind' => 'event', 'instructor_person_id' => $ids['Chiara Moretti'] ?? null, 'fee_cents' => $e[2],
			'session' => array( 'session_date' => gmdate( 'Y-m-d', time() + $e[1] * 86400 ), 'start_time' => '20:00', 'location' => 'Sede dell\'associazione', 'capacity' => $e[3] ),
		) );
		foreach ( $act->sessions( (int) $aid ) as $s ) {
			foreach ( $guests[ $e[0] ] as $n ) {
				if ( ! empty( $ids[ $n ] ) ) {
					try {
						$act->book( (int) $s['id'], (int) $ids[ $n ] );
					} catch ( \Throwable $ex ) {
						WP_CLI::warning( 'prenotazione ' . $n . ': ' . $ex->getMessage() );
					}
				}
			}
		}
	}
} );

// 8. App installabile e QR sulla tessera accesi: sul telefono si vede subito l'invito a installarla e il codice da mostrare.
demo_step( 'app', function () {
	Settings::update( array( 'pwa_enabled' => 1, 'card_qr_enabled' => 1 ) );
} );


// 9. Risorse prenotabili e crediti: un campo, una sala, una prenotazione di Mario e un po' di crediti, così le due funzioni non sono vuote.
demo_step( 'risorse e crediti', function () use ( &$ids ) {
	if ( ! \AssociazioneSemplice\Edition::has( 'resources' ) ) {
		return;
	}
	$court = \AssociazioneSemplice\Resources::save( 0, array( 'name' => 'Campo da tennis', 'category' => 'court', 'mode' => 'slot', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 800, 'guests_max' => 1, 'guest_fee_cents' => 200, 'max_per_person' => 2, 'advance_days' => 30 ) );
	\AssociazioneSemplice\Resources::save( 0, array( 'name' => 'Sala riunioni', 'category' => 'room', 'mode' => 'slot', 'open_time' => '09:00', 'close_time' => '21:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 4, 'fee_cents' => 0, 'advance_days' => 60 ) );
	$day = gmdate( 'Y-m-d', time() + 3 * 86400 );
	if ( ! empty( $ids['Mario Demo'] ) ) {
		\AssociazioneSemplice\Resources::book_slot( (int) $court, (int) $ids['Mario Demo'], $day, '18:00', 1 );
	}
	if ( ! empty( $ids['Elena Conti'] ) ) {
		\AssociazioneSemplice\Resources::book_slot( (int) $court, (int) $ids['Elena Conti'], $day, '19:00', 1 );
	}
	if ( \AssociazioneSemplice\Edition::has( 'credits' ) ) {
		foreach ( array( 'Mario Demo' => 10, 'Elena Conti' => 5, 'Sara Gallo' => 5 ) as $n => $c ) {
			if ( ! empty( $ids[ $n ] ) ) {
				\AssociazioneSemplice\Credits::adjust( (int) $ids[ $n ], $c, 'Crediti di benvenuto' );
			}
		}
	}
} );

WP_CLI::success( 'Dati della demo creati.' );

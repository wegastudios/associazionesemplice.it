<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Import da Excel/CSV (e dall'area di appoggio di WP All Import): soci, ospiti e prima nota.
 * Prima si costruisce un'anteprima (nulla viene scritto), poi si applica.
 */
final class ImportService {

	const MAX_ROWS = 5000;

	// ---------- Anteprima ----------

	/**
	 * @param array $opts default_type, default_account_id
	 * @return array anteprima: people {plan}, ledger {plan, summary}, ignored (nomi dei fogli non riconosciuti)
	 * @throws \InvalidArgumentException
	 */
	public static function preview_file( string $path, string $name, array $opts = array() ): array {
		$out         = self::preview_sheets( SheetReader::read( $path, $name ), $opts );
		$out['file'] = $name;
		return $out;
	}

	/** @param array[] $sheets [ ['name','rows','lines'], ... ] */
	public static function preview_sheets( array $sheets, array $opts = array() ): array {
		$people_rows = array();
		$ledger_rows = array();
		$ignored     = array();
		foreach ( $sheets as $sh ) {
			$role = SheetReader::role( $sh );
			if ( SheetReader::ROLE_PEOPLE === $role ) {
				$p = PeopleCsv::parse_table( $sh['rows'], $sh['lines'] ?? null, (string) $sh['name'] );
				if ( isset( $p['error'] ) ) {
					throw new \InvalidArgumentException( $p['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$people_rows = array_merge( $people_rows, $p['rows'] );
			} elseif ( SheetReader::ROLE_LEDGER === $role ) {
				$l = LedgerImport::parse_table( $sh['rows'], $sh['lines'] ?? null, (string) $sh['name'] );
				if ( isset( $l['error'] ) ) {
					throw new \InvalidArgumentException( $l['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$ledger_rows = array_merge( $ledger_rows, $l['rows'] );
			} else {
				$ignored[] = (string) $sh['name'];
			}
		}
		if ( ! $people_rows && ! $ledger_rows ) {
			throw new \InvalidArgumentException(
				'Non sono stati trovati né soci né movimenti. Per i soci servono le colonne «Nome» e «Cognome»; per la prima nota «Data» e «Importo» (oppure «Entrata» e «Uscita»). Fogli letti: ' . ( $ignored ? implode( ', ', $ignored ) : 'nessuno' ) . '.' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			);
		}
		if ( count( $people_rows ) > Limits::get( 'import_max_rows' ) || count( $ledger_rows ) > Limits::get( 'import_max_rows' ) ) {
			throw new \InvalidArgumentException( 'Il file ha più di ' . Limits::get( 'import_max_rows' ) . ' righe di soci o di movimenti: dividilo in più file.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$out = array( 'people' => null, 'ledger' => null, 'ignored' => $ignored );
		if ( $people_rows ) {
			$default = MemberType::is_member( (string) ( $opts['default_type'] ?? '' ) ) ? $opts['default_type'] : MemberType::ORDINARY;
			$out['people'] = array( 'plan' => self::lock_check( PeopleCsv::plan( $people_rows, self::existing_people(), $default, array( 'guest_phone_required' => Limits::flag( 'guest_phone_required' ), 'guest_host_required' => Limits::flag( 'guest_host_required' ) ) ) ) );
		}
		if ( $ledger_rows ) {
			$plan             = LedgerImport::plan( $ledger_rows, self::ledger_context( $ledger_rows, (int) ( $opts['default_account_id'] ?? 0 ), $people_rows ) );
			$names            = array_column( Plugin::ledger()->accounts(), 'name', 'id' );
			$out['ledger']    = array( 'plan' => $plan, 'summary' => LedgerImport::summary( $plan, array_map( 'strval', $names ) ) );
		}
		return $out;
	}

	/**
	 * Anni chiusi: un socio ente con una data di ingresso in un esercizio chiuso non entra (il libro soci di quell'esercizio è fotografato e non si
	 * modifica); la riga diventa un errore già nell'anteprima, con il motivo.
	 *
	 * @param array[] $plan
	 * @return array[]
	 */
	private static function lock_check( array $plan ): array {
		foreach ( $plan as $i => $p ) {
			if ( ! $p || 'error' === $p['action'] || RuntsRules::ENTITY !== ( $p['row']['kind'] ?? '' ) || empty( $p['row']['since'] ) ) {
				continue;
			}
			$closed = YearLock::closed_year_of( (string) $p['row']['since'] );
			if ( null !== $closed ) {
				$plan[ $i ] = array( 'row' => $p['row'], 'type' => null, 'action' => 'error', 'message' => FiscalYears::closed_message( (int) $closed ) . ' Oppure togli la data di ingresso.', 'matched_id' => null );
			}
		}
		return $plan;
	}

	private static function existing_people(): array {
		$out = array();
		foreach ( Plugin::people()->search() as $e ) {
			$out[] = array(
				'id' => (int) $e['id'], 'card' => $e['card_number'], 'first' => $e['first_name'], 'last' => $e['last_name'], 'email' => $e['email'],
				'tax' => $e['tax_code'], 'phone' => $e['phone'], 'type' => $e['type'], 'host_id' => (int) $e['host_person_id'], 'kind' => RuntsRules::kind( $e['subject_kind'] ?? '' ),
			);
		}
		return $out;
	}

	private static function ledger_context( array $rows, int $default_account_id, array $people_rows = array() ): array {
		$ledger = Plugin::ledger();
		$from   = null;
		$to     = null;
		foreach ( $rows as $r ) {
			if ( $r['date'] ) {
				$from = null === $from || $r['date'] < $from ? $r['date'] : $from;
				$to   = null === $to || $r['date'] > $to ? $r['date'] : $to;
			}
		}
		$people = array();
		foreach ( Plugin::people()->search() as $p ) {
			$people[] = array( 'id' => (int) $p['id'], 'card' => $p['card_number'], 'first' => $p['first_name'], 'last' => $p['last_name'] );
		}
		return array(
			'accounts'           => array_map( function ( $a ) {
				return array( 'id' => (int) $a['id'], 'name' => $a['name'], 'type' => $a['type'] );
			}, $ledger->accounts() ),
			'categories'         => array_map( function ( $c ) {
				return array( 'id' => (int) $c['id'], 'name' => $c['name'], 'kind' => $c['kind'] );
			}, $ledger->categories() ),
			'people'             => $people,
			'activities'         => Plugin::activities()->all_for_select(),
			'existing'           => $from ? $ledger->import_keys( $from, $to ) : array(),
			'default_account_id' => $default_account_id,
			'pending_people'     => array_map( function ( $r ) {
				return array( 'card' => $r['card'], 'first' => $r['first'], 'last' => $r['last'] );
			}, $people_rows ),
		);
	}

	// ---------- Applicazione ----------

	/**
	 * Applica un'anteprima. Tutto quello che si scrive è legato a un "import" (numero), così si può annullare in blocco con {@see undo()}.
	 *
	 * @param array $preview anteprima di preview_sheets()
	 * @param array $opts    mark_members (bool), keep_balances (bool), source (testo: nome del file, "WP All Import"...)
	 * @return array people {created, updated, failed[]}, ledger {created, transfers, duplicates, skipped, failed[], memberships, shifted}, batch_id
	 */
	public static function apply( array $preview, array $opts = array() ): array {
		// Stesso lavoro dell'importazione a giri (vedi ImportJob), fatto qui tutto in una volta: nessun limite di tempo.
		ImportJob::start( $preview, $opts );
		try {
			do {
				$r = ImportJob::step( INF );
			} while ( ! $r['finished'] );
		} catch ( \Throwable $e ) {
			try {
				ImportJob::stop(); // anche se qualcosa va storto a metà: quello che è stato scritto si può annullare
			} catch ( \Throwable $unused ) { // l'import è già chiuso (errore nella prima nota) o il database non risponde: conta l'errore di partenza
				unset( $unused );
			}
			throw $e;
		}
		return $r['result'];
	}

	/** Prima di scrivere qualunque cosa: con anni fiscali chiusi non si può aggiustare il saldo iniziale dei conti. @throws \InvalidArgumentException */
	public static function assert_can_apply( array $preview, array $opts ): void {
		if ( ! empty( $preview['ledger'] ) && ! empty( $opts['keep_balances'] ) && null !== FiscalYears::last_closed() ) {
			// Aggiustare il saldo iniziale cambierebbe i saldi degli esercizi chiusi: si ferma prima di scrivere qualunque cosa.
			throw new \InvalidArgumentException( FiscalYears::closed_message( (int) FiscalYears::last_closed() ) . ' «Non cambiare i saldi attuali dei conti» aggiusta il saldo iniziale e non si può usare con anni fiscali chiusi: togli la spunta (i saldi derivano dai movimenti) oppure riapri l\'anno fiscale.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** La traccia per l'annullo di un import appena aperto, ancora vuota. */
	public static function empty_track(): array {
		return array( 'people_created' => array(), 'people_updated' => array(), 'wp_users_created' => array(), 'memberships' => array(), 'shifts' => array(), 'accounts_created' => array() );
	}

	/** Apre un import (numero): quello che si scrive da qui in poi si può annullare in blocco. Lo usano anche gli import che non sono prima nota (The Events Calendar). */
	public static function open_batch( string $source ): int {
		Db::db()->insert( Db::t( 'import_batches' ), array( 'created_at' => Db::now(), 'user_id' => get_current_user_id() ?: null, 'source' => mb_substr( $source, 0, 190 ) ) );
		return (int) Db::db()->insert_id;
	}

	/** Chiude un import con il riepilogo e i dati per annullarlo; se non è cambiato nulla lo toglie dall'elenco (null). */
	public static function finish_batch( int $batch, array $summary, array $track, bool $changed ): ?int {
		if ( ! $changed ) {
			Db::db()->delete( Db::t( 'import_batches' ), array( 'id' => $batch ) );
			return null;
		}
		Db::db()->update( Db::t( 'import_batches' ), array( 'summary' => wp_json_encode( $summary ), 'data' => wp_json_encode( $track ) ), array( 'id' => $batch ) );
		return $batch;
	}

	/** Segna un import come annullato (una volta sola) e lo scrive nel registro azioni. */
	public static function mark_undone( int $batch_id, array $res, array $audit ): void {
		Db::db()->update( Db::t( 'import_batches' ), array( 'undone_at' => Db::now(), 'undone_by' => get_current_user_id() ?: null, 'undo_result' => wp_json_encode( $res ) ), array( 'id' => $batch_id ) );
		Audit::log( 'import.undone', 'import', $batch_id, $audit );
	}

	/** Chiude un import con il riepilogo e i dati per annullarlo. */
	public static function close_batch( int $batch, array $res, array $track ): void {
		$summary = array(
			'people_created' => count( $track['people_created'] ), 'people_updated' => count( $track['people_updated'] ),
			'transactions'   => (int) ( $res['ledger']['created'] ?? 0 ), 'transfers' => (int) ( $res['ledger']['transfers'] ?? 0 ),
		);
		Db::db()->update( Db::t( 'import_batches' ), array( 'summary' => wp_json_encode( $summary ), 'data' => wp_json_encode( $track ) ), array( 'id' => $batch ) );
	}

	/** I campi di una scheda che si ricordano prima di aggiornarla: servono a rimetterla com'era se l'import viene annullato. */
	const BEFORE_FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'tax_code', 'address', 'zip', 'city', 'province', 'card_number', 'type', 'host_person_id', 'notes', 'joined_on', 'prior_visits', 'birth_date', 'subject_kind', 'runts_same_section' );

	/** @var array|null ospitanti possibili (non ospiti) per tessera, email e nome: si carica una volta per giro, quando serve il primo ospite */
	private static $host_idx = null;

	/** Da chiamare a ogni giro: l'elenco degli ospitanti si rilegge dal database (i soci creati nel primo passaggio servono agli ospiti del secondo). */
	public static function reset_host_index(): void {
		self::$host_idx = null;
	}

	/**
	 * L'ordine in cui si scrivono le righe di soci e ospiti: prima i soci, poi gli ospiti (l'ospitante può essere nuovo); le righe con errore si saltano.
	 *
	 * @return array<int,array{0:int,1:int}> per ogni passo: posizione nel piano e passaggio (0 soci, 1 ospiti)
	 */
	public static function people_sequence( array $plan ): array {
		$order = array( array(), array() );
		foreach ( $plan as $i => $row ) {
			if ( ! $row || 'error' === $row['action'] ) {
				continue;
			}
			$pass               =MemberType::GUEST === $row['type'] ? 1 : 0;
			$order[ $pass ][]   = array( (int) $i, $pass );
		}
		return array_merge( $order[0], $order[1] );
	}

	/**
	 * Scrive una riga di soci o ospiti: crea o aggiorna la scheda, segna le iscrizioni e ricorda i tutori da collegare dopo.
	 * Quello che fa è ricordato in $track (per l'annullo) e in $job (contatori, righe non riuscite, tutori).
	 *
	 * @param array $job   created, updated, failed[], links[]
	 * @param array $track la traccia dell'import
	 * @param array $ctx   mark (bool), year (anno sociale), pre (schede prima di un aggiornamento, per id), touched (id già aggiornati in questo giro),
	 *                     adopt (id di una scheda già creata da questa riga in un giro interrotto: non si crea di nuovo), since (marca di tempo di quel giro)
	 */
	public static function apply_person_row( array $row, int $pass, array &$job, array &$track, array &$ctx ): void {
		$db     = Db::db();
		$people = Plugin::people();
		$year   = (string) $ctx['year'];
		$mark   = ! empty( $ctx['mark'] );
		$r      = $row['row'];
		$data   = array( 'first_name' => $r['first'], 'last_name' => $r['last'] );
		if ( null !== $r['email'] ) {
			$data['email'] = $r['email'];
		}
		foreach ( array( 'card' => 'card_number', 'phone' => 'phone', 'tax' => 'tax_code', 'addr' => 'address', 'zip' => 'zip', 'city' => 'city', 'prov' => 'province' ) as $from => $to ) {
			if ( isset( $r[ $from ] ) && null !== $r[ $from ] && ( 0 === $pass || 'card' !== $from ) ) {
				$data[ $to ] = $r[ $from ];
			}
		}
		if ( ! empty( $r['notes'] ) ) {
			$data['notes'] = $r['notes']; // ad es. gli eventi passati a cui l'ospite ha già partecipato
		}
		if ( ! empty( $r['since'] ) ) {
			$data['joined_on'] = $r['since']; // anno (o data) di iscrizione
		}
		if ( ! empty( $r['birth'] ) && RuntsRules::ENTITY !== ( $r['kind'] ?? '' ) ) {
			$data['birth_date'] = $r['birth'];
		}
		if ( RuntsRules::ENTITY === ( $r['kind'] ?? '' ) ) { // socio ente: denominazione nel «cognome», nome vuoto, sezione del RUNTS se indicata
			$data['subject_kind'] = RuntsRules::ENTITY;
			if ( is_bool( $r['same'] ?? null ) ) {
				$data['runts_same_section'] = $r['same'] ? '1' : '0';
			}
		}
		if ( 1 === $pass && isset( $r['prior'] ) && null !== $r['prior'] ) {
			$data['prior_visits'] = (int) $r['prior']; // volte già venuto prima di usare il programma
		}
		$adopted = false;
		try {
			if ( 1 === $pass ) {
				$host = ! empty( $row['host_id'] ) ? (int) $row['host_id'] : self::find_host( (string) $row['host_text'] );
				if ( ! $host && ( '' !== trim( (string) $row['host_text'] ) || Limits::flag( 'guest_host_required' ) ) ) {
					throw new \InvalidArgumentException( 'socio ospitante «' . $row['host_text'] . '» non trovato' );
				}
				$data['host_person_id'] = $host ?: '';
			}
			if ( 'create' === $row['action'] ) {
				$data['type'] = $row['type'];
				if ( ! empty( $ctx['adopt'] ) ) { // la scheda l'ha già scritta un giro interrotto: si riprende da quella, senza crearne un'altra
					$adopted = true;
					$id      = (int) $ctx['adopt'];
					$job['created']++;
					if ( ! in_array( $id, $track['people_created'], true ) ) {
						$track['people_created'][] = $id;
					}
					$person = $people->get( $id );
					if ( $person && ! empty( $person['wp_user_id'] ) && ! isset( $track['wp_users_created'][ $id ] ) && self::user_is_new( (int) $person['wp_user_id'], (int) ( $ctx['since'] ?? 0 ) ) ) {
						$track['wp_users_created'][ $id ] = (int) $person['wp_user_id'];
					}
				} else {
					$user_existed = ! empty( $data['email'] ) && (bool) get_user_by( 'email', $data['email'] );
					$id           = $people->create( $data );
					$job['created']++;
					$track['people_created'][] = $id;
					$person                    = $people->get( $id );
					if ( ! $user_existed && $person && ! empty( $person['wp_user_id'] ) ) {
						$track['wp_users_created'][ $id ] = (int) $person['wp_user_id']; // utente nato con questo import: si potrà togliere
					}
				}
			} else {
				$id = (int) $row['matched_id'];
				if ( ! isset( $ctx['touched'][ $id ] ) && isset( $ctx['pre'][ $id ] ) ) {
					$before = $ctx['pre'][ $id ]; // letta prima del giro (uguale a quella di adesso): sopravvive a un'interruzione
				} else {
					$b      = $people->get( $id );
					$before = $b ? array_intersect_key( $b, array_flip( self::BEFORE_FIELDS ) ) : null;
				}
				if ( ! empty( $row['type_given'] ) && 0 === $pass ) {
					$data['type'] = $row['type'];
				}
				$people->update( $id, $data );
				$job['updated']++;
				$ctx['touched'][ $id ] = true;
				if ( $before ) {
					$track['people_updated'][ $id ] = $before;
				}
			}
			if ( ! empty( $r['guardian'] ) ) {
				$job['links'][] = array( $id, (string) $r['guardian'], ( $r['sheet'] ? $r['sheet'] . ' ' : '' ) . 'riga ' . $r['line'] );
			}
			if ( 'create' !== $row['action'] ) { // una scheda appena creata (o ripresa) è già stata letta qui sopra e non è cambiata
				$person = $people->get( $id );
			}
			if ( $mark && $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
				$had = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND social_year = %s AND deleted_at IS NULL', $id, $year ) );
				$people->set_membership( $id, $year, true, 'import' );
				if ( ! $had || $adopted ) {
					$track['memberships'][] = array( $id, $year );
				}
			}
			if ( $person && ! empty( $r['years'] ) && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
				foreach ( $r['years'] as $y ) { // anni di iscrizione indicati nelle colonne del file (2024, 2025… oppure «ANNO CORRENTE» e «ANNO SCORSO»)
					$y = '@current' === $y ? $year : ( '@previous' === $y ? Settings::membership_year()->previous()->label() : MembershipRules::import_label( (string) $y, Settings::membership_start_month() ) );
					$y_had = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND social_year = %s AND deleted_at IS NULL', $id, (string) $y ) );
					$people->set_membership( $id, (string) $y, true, 'import' );
					if ( ! $y_had || $adopted ) {
						$track['memberships'][] = array( $id, (string) $y );
					}
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			$job['failed'][] = ( $r['sheet'] ? $r['sheet'] . ' ' : '' ) . 'riga ' . $r['line'] . ': ' . $e->getMessage();
		}
	}

	/** L'utente WordPress è nato dopo il momento indicato (marca di tempo)? */
	private static function user_is_new( int $user_id, int $since ): bool {
		$u = get_userdata( $user_id );
		return $u && $since > 0 && strtotime( (string) $u->user_registered . ' UTC' ) >= $since - 2;
	}

	/**
	 * Collega un minore al suo tutore (uno dei collegamenti ricordati da {@see apply_person_row()}); i tutori si cercano alla fine: possono essere nello stesso file.
	 *
	 * @param array  $link  id del minore, riferimento al tutore, riga del file
	 * @param string $since se non è vuoto (data e ora del sito), un collegamento già presente e nato dopo quel momento è di un giro interrotto: si ricorda per l'annullo
	 */
	public static function apply_guardian_link( array $link, array &$job, array &$track, string $since = '' ): void {
		try {
			$g = Guardians::find_by_ref( $link[1] );
			if ( ! $g ) {
				throw new \InvalidArgumentException( 'tutore «' . $link[1] . '» non trovato o ambiguo (indica la tessera o l\'email)' );
			}
			$pair = array( (int) $link[0], (int) $g['id'] );
			if ( ! Guardians::is_guardian_of( (int) $g['id'], (int) $link[0] ) ) {
				Guardians::link( (int) $link[0], (int) $g['id'] );
				$track['guardians'][] = $pair;
			} elseif ( '' !== $since ) {
				$db  = Db::db();
				$own = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'guardians' ) . ' WHERE person_id = %d AND guardian_person_id = %d AND created_at >= %s', $pair[0], $pair[1], $since ) );
				if ( $own > 0 && ! in_array( $pair, (array) ( $track['guardians'] ?? array() ), true ) ) {
					$track['guardians'][] = $pair;
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			$job['failed'][] = $link[2] . ': ' . $e->getMessage();
		}
	}

	/** L'ospitante di un ospite: tessera, email oppure nome e cognome (unico). Gli ospitanti si leggono una volta per giro. */
	private static function find_host( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		if ( null === self::$host_idx ) {
			$idx = array( 'card' => array(), 'mail' => array(), 'name' => array() );
			foreach ( Plugin::people()->search() as $p ) {
				if ( MemberType::GUEST === $p['type'] ) {
					continue;
				}
				$id = (int) $p['id'];
				if ( ! empty( $p['card_number'] ) ) {
					$idx['card'][ Text::lower( (string) $p['card_number'] ) ][ $id ] = true;
				}
				if ( ! empty( $p['email'] ) ) {
					$idx['mail'][ Text::lower( (string) $p['email'] ) ][ $id ] = true;
				}
				$idx['name'][ Text::normalize( $p['first_name'] . $p['last_name'] ) ][ $id ] = true;
				$idx['name'][ Text::normalize( $p['last_name'] . $p['first_name'] ) ][ $id ] = true;
			}
			self::$host_idx = $idx;
		}
		$n = Text::normalize( $text );
		$c = Text::lower( PeopleCsv::clean_card( $text ) );
		$m = array();
		foreach ( array( self::$host_idx['card'][ $c ] ?? array(), self::$host_idx['mail'][ Text::lower( $text ) ] ?? array(), self::$host_idx['name'][ $n ] ?? array() ) as $set ) {
			foreach ( $set as $id => $unused ) {
				$m[ $id ] = true;
			}
		}
		return 1 === count( $m ) ? (int) array_keys( $m )[0] : 0;
	}

	/** @internal usato da ImportJob: la prima nota si scrive tutta in una volta, in un'unica transazione. */
	public static function apply_ledger( array $plan, bool $keep_balances, int $batch, array &$track ): array {
		$ledger = Plugin::ledger();
		$people = Plugin::people();
		$res    = array( 'created' => 0, 'transfers' => 0, 'duplicates' => 0, 'skipped' => 0, 'failed' => array(), 'memberships' => 0, 'shifted' => 0 );
		$from   = null;
		$to     = null;
		$delta  = array(); // variazione per conto già esistente
		$new    = array(); // riferimento "new:…" => id del conto creato
		$member_rows = array();

		// Soci che stavano nello stesso file: ora esistono, si collegano ai loro movimenti.
		$late = array();
		foreach ( $plan as $i => $p ) {
			if ( 'create' === $p['action'] && ! empty( $p['data']['person_late'] ) ) {
				$late[ $i ] = $p['data'];
			}
		}
		if ( $late ) {
			$by_card = array();
			$by_name = array();
			foreach ( $people->search() as $pe ) {
				if ( ! empty( $pe['card_number'] ) ) {
					$by_card[ Text::lower( (string) $pe['card_number'] ) ] = (int) $pe['id'];
				}
				$by_name[ Text::normalize( $pe['first_name'] . $pe['last_name'] ) ][ (int) $pe['id'] ]  = true;
				$by_name[ Text::normalize( $pe['last_name'] . $pe['first_name'] ) ][ (int) $pe['id'] ] = true;
			}
			foreach ( $late as $i => $d ) {
				$id = 0;
				if ( '' !== (string) $d['card'] ) {
					$id = $by_card[ Text::lower( PeopleCsv::clean_card( (string) $d['card'] ) ) ] ?? 0;
				}
				if ( ! $id && '' !== (string) $d['person_text'] ) {
					$m  = array_keys( $by_name[ Text::normalize( (string) $d['person_text'] ) ] ?? array() );
					$id = 1 === count( $m ) ? (int) $m[0] : 0;
				}
				$plan[ $i ]['data']['person_id'] = $id;
			}
		}

		$ledger->in_batch(
			function () use ( $plan, $ledger, $batch, &$res, &$from, &$to, &$delta, &$new, &$member_rows ) {
				$account = function ( array $acc ) use ( $ledger, &$new ) {
					if ( ! empty( $acc['id'] ) ) {
						return (int) $acc['id'];
					}
					if ( ! isset( $new[ $acc['ref'] ] ) ) {
						$new[ $acc['ref'] ] = $ledger->add_account( (string) $acc['new'], (string) ( $acc['type'] ?: 'bank' ), 0 );
					}
					return $new[ $acc['ref'] ];
				};
				foreach ( $plan as $p ) { // importando gli anni precedenti, gli anni fiscali che mancano si creano (aperti); uno chiuso resta chiuso e blocca l'import
					$when = (string) ( $p['data']['date'] ?? '' );
					if ( preg_match( '/^(\d{4})-\d{2}-\d{2}$/', $when, $ym ) && (int) $ym[1] >= 2000 && (int) $ym[1] <= 2100 ) {
						FiscalYears::ensure( FiscalYears::year_of( $when ) );
					}
				}
				foreach ( $plan as $p ) {
					$d = $p['data'];
					switch ( $p['action'] ) {
						case 'duplicate':
							$res['duplicates']++;
							break;
						case 'skip':
							$res['skipped']++;
							break;
						case 'create':
							$acc_id = $account( array( 'id' => $d['account_id'], 'new' => $d['new_account'], 'type' => $d['new_account_type'], 'ref' => $d['account_ref'] ) );
							$tx     = $ledger->import_row( array_merge( $d, array( 'account_id' => $acc_id, 'batch' => $batch ) ) );
							$res['created']++;
							if ( $d['account_id'] ) {
								$delta[ $acc_id ] = ( $delta[ $acc_id ] ?? 0 ) + ( 'income' === $d['type'] ? $d['cents'] : -$d['cents'] );
							}
							if ( 'membership' === ( $d['category_kind'] ?? '' ) && $d['person_id'] ) {
								$member_rows[] = array( (int) $d['person_id'], Settings::membership_year( $d['date'] )->label(), $tx );
							}
							$from = null === $from || $d['date'] < $from ? $d['date'] : $from;
							$to   = null === $to || $d['date'] > $to ? $d['date'] : $to;
							break;
						case 'transfer':
							$a = $account( array( 'id' => $d['from']['id'], 'new' => $d['from']['new'], 'type' => $d['from']['type'], 'ref' => $d['from']['ref'] ) );
							$b = $account( array( 'id' => $d['to']['id'], 'new' => $d['to']['new'], 'type' => $d['to']['type'], 'ref' => $d['to']['ref'] ) );
							$ledger->tag_transfer( $ledger->record_transfer( $d['date'], $a, $b, $d['cents'], $d['method'], $d['description'] ), $batch );
							$res['transfers']++;
							if ( $d['from']['id'] ) {
								$delta[ $a ] = ( $delta[ $a ] ?? 0 ) - $d['cents'];
							}
							if ( $d['to']['id'] ) {
								$delta[ $b ] = ( $delta[ $b ] ?? 0 ) + $d['cents'];
							}
							$from = null === $from || $d['date'] < $from ? $d['date'] : $from;
							$to   = null === $to || $d['date'] > $to ? $d['date'] : $to;
							break;
					}
				}
			}
		);
		$track['accounts_created'] = array_values( $new );
		// Iscrizioni dall'anno sociale delle quote importate (anche passate): la tessera "ricorda" gli anni pagati.
		foreach ( $member_rows as $m ) {
			try {
				$person = $people->get( $m[0] );
				if ( $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
					$people->set_membership( $m[0], $m[1], true, 'import', $m[2] );
					$res['memberships']++;
				}
			} catch ( \InvalidArgumentException $e ) {
				$res['failed'][] = 'iscrizione ' . $m[1] . ': ' . $e->getMessage();
			}
		}
		// Movimenti storici: il saldo attuale dei conti già esistenti non deve cambiare.
		if ( $keep_balances ) {
			foreach ( $delta as $acc_id => $d ) {
				$ledger->shift_opening( (int) $acc_id, -$d );
				$track['shifts'][ (int) $acc_id ] = -$d;
				$res['shifted']++;
			}
		}
		Audit::log( 'import.ledger', 'ledger', null, array( 'created' => $res['created'], 'transfers' => $res['transfers'], 'duplicates' => $res['duplicates'], 'from' => $from, 'to' => $to, 'keep_balances' => $keep_balances ) );
		return $res;
	}

	// ---------- Cronologia e annullamento ----------

	/** @return array[] gli ultimi import, dal più recente (summary e data già decodificati) */
	public static function batches( int $limit = 20 ): array {
		$db   = Db::db();
		$rows = $db->get_results( $db->prepare( 'SELECT b.*, u.display_name FROM ' . Db::t( 'import_batches' ) . ' b LEFT JOIN ' . $db->users . ' u ON u.ID = b.user_id ORDER BY b.id DESC LIMIT %d', max( 1, $limit ) ), ARRAY_A ) ?: array();
		foreach ( $rows as &$r ) {
			$r['summary'] = (array) json_decode( (string) $r['summary'], true );
			$r['data']    = (array) json_decode( (string) $r['data'], true );
		}
		return $rows;
	}

	/** Un import, letto per numero (una sola riga: non l'elenco di tutti). */
	public static function batch( int $id ): ?array {
		$db  = Db::db();
		$row = $db->get_row( $db->prepare( 'SELECT b.*, u.display_name FROM ' . Db::t( 'import_batches' ) . ' b LEFT JOIN ' . $db->users . ' u ON u.ID = b.user_id WHERE b.id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['summary'] = (array) json_decode( (string) $row['summary'], true );
		$row['data']    = (array) json_decode( (string) $row['data'], true );
		return $row;
	}

	/**
	 * Quali di queste persone sono usate altrove (iscrizione a un'attività, prenotazione, iscrizione residua, pagamento o movimento): cinque
	 * letture per tutte, non cinque per persona.
	 *
	 * @param int[] $ids
	 * @return array<int,true> gli id usati
	 */
	private static function people_in_use( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		$db = Db::db();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			foreach ( array(
				array( 'enrollments', 'person_id', '' ),
				array( 'trials', 'person_id', '' ),
				array( 'course_month_dues', 'person_id', '' ),
				array( 'course_credits', 'person_id', '' ),
				array( 'course_credit_payments', 'person_id', '' ),
				array( 'bookings', 'person_id', '' ),
				array( 'bookings', 'booked_by', '' ),
				array( 'memberships', 'person_id', ' AND deleted_at IS NULL' ),
				array( 'payments', 'payer_person_id', '' ),
				array( 'transactions', 'person_id', ' AND voided_at IS NULL' ),
			) as $q ) {
				foreach ( $db->get_col( 'SELECT DISTINCT ' . $q[1] . ' FROM ' . Db::t( $q[0] ) . ' WHERE ' . $q[1] . ' IN (' . $in . ')' . $q[2] ) ?: array() as $used ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi interni e id interi
					$out[ (int) $used ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Annulla un intero import: movimenti (e le iscrizioni nate da loro), saldi iniziali aggiustati, conti creati e vuoti,
	 * iscrizioni segnate, dati dei soci aggiornati (ripristinati), soci e ospiti creati (solo se non ancora usati; l'utente WordPress
	 * nato con l'import viene tolto se non ha altri ruoli). Quello che è già stato usato resta e viene segnalato.
	 *
	 * @return array voided, shifts_undone, accounts_removed, memberships_removed, people_restored, people_removed, people_kept[], users_removed
	 * @throws \InvalidArgumentException
	 */
	public static function undo( int $batch_id ): array {
		return FiscalYears::memo(
			function () use ( $batch_id ) { // gli anni fiscali si leggono una volta per anno, non una volta per tessera o movimento
				return self::undo_now( $batch_id );
			}
		);
	}

	private static function undo_now( int $batch_id ): array {
		$b = self::batch( $batch_id );
		if ( ! $b || ImportJob::is_running( $batch_id ) ) { // un import ancora a giri non si annulla: prima si finisce o si ferma
			throw new \InvalidArgumentException( 'Importazione non trovata.' );
		}
		if ( ! empty( $b['undone_at'] ) ) {
			throw new \InvalidArgumentException( 'Questa importazione è già stata annullata.' );
		}
		if ( 'tribe' === (string) ( $b['data']['kind'] ?? '' ) ) {
			return TribeImport::undo( $b ); // eventi e corsi di The Events Calendar: stesso elenco e stesso pulsante, annullamento sul loro contenuto
		}
		$db     = Db::db();
		$ledger = Plugin::ledger();
		$people = Plugin::people();
		$data   = $b['data'];
		$res    = array( 'voided' => 0, 'shifts_undone' => 0, 'accounts_removed' => 0, 'memberships_removed' => 0, 'people_restored' => 0, 'people_removed' => 0, 'people_kept' => array(), 'users_removed' => 0 );
		$reason = 'Annullamento importazione n. ' . $batch_id;

		// 1. Prima nota: tutto o niente
		$ledger->bulk(
			function () use ( $db, $ledger, $batch_id, $data, $reason, &$res ) {
				$ledger->in_batch(
					function () use ( $db, $ledger, $batch_id, $data, $reason, &$res ) {
						$rows  = $db->get_results( $db->prepare( 'SELECT id, transfer_id FROM ' . Db::t( 'transactions' ) . ' WHERE import_batch = %d AND voided_at IS NULL ORDER BY id', $batch_id ), ARRAY_A ) ?: array();
						$legs  = array(); // le righe di ogni giroconto dell'import
						foreach ( $rows as $r ) {
							if ( ! empty( $r['transfer_id'] ) ) {
								$legs[ (string) $r['transfer_id'] ][] = (int) $r['id'];
							}
						}
						$gone = array();
						foreach ( $rows as $r ) {
							$id = (int) $r['id'];
							if ( isset( $gone[ $id ] ) ) { // un giroconto si annulla con entrambe le righe: la seconda è già andata
								continue;
							}
							$ledger->void( $id, $reason );
							$res['voided']++;
							$gone[ $id ] = true;
							foreach ( $legs[ (string) ( $r['transfer_id'] ?? '' ) ] ?? array() as $leg ) {
								$gone[ $leg ] = true;
							}
						}
						foreach ( (array) ( $data['shifts'] ?? array() ) as $acc => $shift ) {
							$ledger->shift_opening( (int) $acc, -(int) $shift );
							$res['shifts_undone']++;
						}
						foreach ( (array) ( $data['accounts_created'] ?? array() ) as $acc ) {
							$used = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE account_id = %d AND voided_at IS NULL', (int) $acc ) );
							if ( ! $used ) {
								$db->update( Db::t( 'accounts' ), array( 'deleted_at' => Db::now() ), array( 'id' => (int) $acc ) );
								$res['accounts_removed']++;
							}
						}
					}
				);
			}
		);

		// 2. Iscrizioni segnate dall'import
		foreach ( (array) ( $data['memberships'] ?? array() ) as $m ) {
			try {
				$people->set_membership( (int) $m[0], (string) $m[1], false, 'import-undo' );
				$res['memberships_removed']++;
			} catch ( \InvalidArgumentException $e ) { // persona eliminata nel frattempo
				continue;
			}
		}

		// 2b. Tutori collegati dall'import
		foreach ( (array) ( $data['guardians'] ?? array() ) as $g ) {
			Guardians::unlink( (int) $g[0], (int) $g[1] );
		}

		// 3. Soci aggiornati: si rimettono i dati di prima
		$created_set = array_flip( array_map( 'intval', (array) ( $data['people_created'] ?? array() ) ) );
		foreach ( (array) ( $data['people_updated'] ?? array() ) as $id => $before ) {
			if ( isset( $created_set[ (int) $id ] ) ) {
				continue;
			}
			try {
				$people->update( (int) $id, (array) $before );
				$res['people_restored']++;
			} catch ( \InvalidArgumentException $e ) {
				$res['people_kept'][] = 'scheda ' . (int) $id . ' non ripristinata: ' . $e->getMessage();
			}
		}

		// 4. Soci e ospiti creati: prima gli ospiti (altrimenti l'ospitante non si può togliere), solo se non usati
		$created = array_map( 'intval', (array) ( $data['people_created'] ?? array() ) );
		$cards   = array(); // le schede lette una volta sola (prima si rileggevano a ogni confronto dell'ordinamento)
		$in_use  = array();
		foreach ( array_chunk( $created, 500 ) as $chunk ) {
			$cards  += $people->get_many( $chunk );
			$in_use += self::people_in_use( $chunk );
		}
		usort(
			$created,
			function ( $a, $c ) use ( $cards ) {
				return ( isset( $cards[ $a ] ) && MemberType::GUEST === $cards[ $a ]['type'] ? 0 : 1 ) <=> ( isset( $cards[ $c ] ) && MemberType::GUEST === $cards[ $c ]['type'] ? 0 : 1 );
			}
		);
		foreach ( $created as $id ) {
			$p = $cards[ $id ] ?? null;
			if ( ! $p ) {
				continue;
			}
			$name = trim( $p['first_name'] . ' ' . $p['last_name'] );
			if ( isset( $in_use[ $id ] ) ) {
				$res['people_kept'][] = $name . ' (già usato)';
				continue;
			}
			try {
				$people->delete( $id );
				$res['people_removed']++;
				$uid = (int) ( $data['wp_users_created'][ $id ] ?? 0 );
				if ( $uid && self::remove_import_user( $uid ) ) {
					$res['users_removed']++;
				}
			} catch ( \InvalidArgumentException $e ) {
				$res['people_kept'][] = $name . ' (' . $e->getMessage() . ')';
			}
		}

		$db->update(
			Db::t( 'import_batches' ),
			array( 'undone_at' => Db::now(), 'undone_by' => get_current_user_id() ?: null, 'undo_result' => wp_json_encode( $res ) ),
			array( 'id' => $batch_id )
		);
		Audit::log( 'import.undone', 'import', $batch_id, array( 'voided' => $res['voided'], 'people_removed' => $res['people_removed'], 'kept' => count( $res['people_kept'] ) ) );
		return $res;
	}

	/** Toglie l'utente WordPress creato da un import, ma solo se è un semplice socio (mai amministratori né utenti con altri ruoli o contenuti). */
	private static function remove_import_user( int $user_id ): bool {
		$u = get_userdata( $user_id );
		if ( ! $u || user_can( $u, 'manage_options' ) || user_can( $u, Plugin::CAP_OPS ) || user_can( $u, 'edit_posts' ) ) {
			return false;
		}
		$extra = array_diff( (array) $u->roles, array( Plugin::ROLE_MEMBER, 'subscriber' ) );
		if ( $extra || (int) count_user_posts( $user_id, 'any' ) > 0 ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return (bool) wp_delete_user( $user_id );
	}
}

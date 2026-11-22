<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class Install {

	const DB_VERSION_OPTION = 'asem_db_version';
	const DB_VERSION        = '74'; // dalla 74 hanno un indice anche i pagamenti online per riferimento del fornitore, i movimenti per chi li ha registrati, le richieste di prenotazione per persona e i destinatari delle comunicazioni per persona (si creano una volta, senza toccare i dati); dalla 73 ogni socio ha la versione del suo codice QR (qr_version: 0 = come prima, i QR già stampati valgono finché il socio non rigenera il codice); dalla 72 la voce «Quota attività / corso» si chiama «Contributo attività / corso» (si rinomina una volta, solo dove ha ancora il nome originale); dalla 71 la tessera segue l'anno fiscale (memberships.replaced_by: la tessera sostituita da una più recente, senza cancellarla); dalla 70 un evento può tenere occupata la risorsa dal primo all'ultimo giorno (resource_block, identificativo del blocco condiviso, e resource_block_label, il suo nome: vuoti = come prima); dalla 69 ogni data di un evento può avere una data di fine (end_date, vuota = finisce lo stesso giorno: nessun dato cambia); dalla 68 le ricerche più frequenti hanno gli indici per cognome e nome, codice fiscale, conto e data, pagante e riferimento del documento, richiesta per turno e persona (si creano una volta, senza toccare i dati); dalla 67 i ruoli di WordPress diversi da amministratore non hanno più il permesso di lavorare nel plugin (si ripuliscono una volta); dalla 66 la lista d'attesa ricorda il listino scelto (price_list); dalla 65 i file riservati hanno l'impronta del contenuto e la versione della cifratura (enc_ver); dalla 64 il pulsante WhatsApp è una scelta a parte; dalla 62 il verbale ha il numero salvato (number_year, number_seq); dalla 63 l'anno sociale parte a gennaio

	/**
	 * Tutti i lavori periodici e programmati del plugin e delle sue estensioni (nomi dei punti di aggancio di WP-Cron).
	 * Alla disattivazione e alla disinstallazione si tolgono tutti, qualunque siano gli argomenti con cui erano stati programmati.
	 */
	const JOB_HOOKS = array(
		'asem_send_reminders',
		'asem_release_holds',
		'asem_event_requests',
		'asem_broadcast_batch',
		'asem_check_pending_payments',
		'asem_purge_backups',
		'asem_private_folder_check',
		'asem_file_crypt_migrate',
		'asem_file_crypt_migrate_certs',
		'asem_file_crypt_certs_check',
		'asem_receipt_pdfs_batch',
		'asem_purge_audit',
		'asem_purge_member_sessions',
		'asem_purge_applications',
		'asem_purge_broadcast_rcpt',
		'asem_long_job_tick',
		'asem_sms_hourly',
		'asem_sms_queue',
		'asem_newsletter_sync',
		'asem_license_check',
		'asem_license_resync',
		'asem_update_check',
		'asem_updater_refresh',
		'asem_watch_month_end',
	);

	/** Toglie dal sito corrente tutti i lavori programmati del plugin (anche quelli con argomenti). */
	public static function clear_jobs(): void {
		foreach ( self::JOB_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public static function activate(): void {
		self::create_tables();
		self::ensure_indexes(); // indici delle ricerche frequenti: si creano solo se mancano (idempotente)
		self::add_roles_and_caps();
		self::seed();
		self::migrate_year_closures(); // gli anni fiscali già chiusi entrano nella traccia permanente (idempotente)
		self::migrate_minute_numbers(); // ogni verbale ha il suo numero salvato, con lo stesso ordine di prima (idempotente)
		MemberBook::assign_missing(); // ogni socio ha la sua matricola, assegnata una volta sola (idempotente)
		if ( '' === (string) get_option( self::DB_VERSION_OPTION, '' ) ) { // prima installazione: si propone la configurazione guidata
			Wizard::schedule_first_run();
		} elseif ( Wizard::pending() ) { // sito già in uso prima di questa funzione: nessun invito, la pagina resta in Impostazioni
			Wizard::mark( Wizard::SKIPPED );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Nome (logico) del blocco che tiene un solo aggiornamento dello schema alla volta; {@see Db::lock_name()} lo rende unico per sito. */
	const UPGRADE_LOCK = 'schema_upgrade';

	/**
	 * Esegue gli aggiornamenti dello schema se il plugin è stato aggiornato senza riattivarlo.
	 * Due richieste insieme non lo eseguono due volte: la prima prende un blocco, l'altra aspetta (al più 10 secondi), poi rilegge la versione e,
	 * se l'aggiornamento è già stato fatto, non fa nulla. Se il blocco non si ottiene, la richiesta prosegue com'era e riprova alla successiva;
	 * il blocco cade da solo a fine richiesta, quindi un aggiornamento interrotto non blocca il sito.
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		$started = false;
		try {
			Db::with_lock(
				self::UPGRADE_LOCK,
				function () use ( &$started ) {
					$db    = Db::db();
					$fresh = (string) $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name = %s", self::DB_VERSION_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- serve il valore appena scritto da un'altra richiesta, non quello in cache
					if ( self::upgrade_needed( $fresh ) ) {
						$started = true;
						self::run_upgrade( $fresh );
					}
				},
				10
			);
		} catch ( \InvalidArgumentException $e ) {
			if ( $started ) {
				throw $e; // l'errore viene dall'aggiornamento stesso, non dal blocco
			}
			// blocco occupato oltre l'attesa: lo sta facendo un'altra richiesta, qui si prosegue e si riprova alla prossima
		}
	}

	/** Lo schema va aggiornato? (la versione salvata è diversa da quella del codice) */
	public static function upgrade_needed( string $stored ): bool {
		return $stored !== self::DB_VERSION;
	}

	private static function run_upgrade( string $old ): void {
		if ( self::upgrade_needed( $old ) ) {
			self::activate();
			if ( '' !== $old && version_compare( $old, '21', '<' ) ) {
				self::migrate_reimbursements();
			}
			if ( '' !== $old && version_compare( $old, '17', '<' ) ) {
				self::migrate_membership_years();
			}
			if ( '' !== $old && version_compare( $old, '45', '<' ) ) {
				self::migrate_operative_guardians();
			}
			if ( '' !== $old && version_compare( $old, '61', '<' ) ) {
				self::migrate_voting_types();
			}
			if ( '' !== $old && version_compare( $old, '63', '<' ) ) {
				self::migrate_social_year_month();
			}
			if ( '' !== $old && version_compare( $old, '64', '<' ) ) {
				self::migrate_whatsapp_button();
			}
			if ( '' !== $old && version_compare( $old, '67', '<' ) ) {
				self::migrate_wp_role_access();
			}
			if ( '' !== $old && version_compare( $old, '71', '<' ) ) {
				update_option( self::REALIGN_OPTION, '0', false ); // la tessera segue l'anno fiscale: riallineamento delle tessere esistenti (dopo una copia di sicurezza)
				self::maybe_realign_memberships();
			}
			if ( '' !== $old && version_compare( $old, '72', '<' ) ) {
				self::migrate_activity_fee_name();
			}
			if ( '' !== $old && version_compare( $old, '3', '<' ) ) {
				// Dalla v3 il contributo di un'attività è `fee_cents` (per i corsi resta "al mese").
				Db::db()->query( 'UPDATE ' . Db::t( 'activities' ) . ' SET fee_cents = monthly_fee_cents WHERE fee_cents = 0 AND monthly_fee_cents > 0' );
			}
		}
	}

	/**
	 * Indici delle ricerche più frequenti: tabella => nome dell'indice => colonne. Solo tabelle del gratuito; le altre hanno già quelli che servono.
	 * Elenco anagrafico per cognome e nome (ordine e pagine), ricerca per codice fiscale, movimenti di un conto in un periodo, movimenti per pagante e per
	 * riferimento del documento (pagamenti online), richieste di prenotazione per turno e persona.
	 */
	const INDEXES = array(
		'people'         => array(
			'last_first' => 'last_name, first_name',
			'tax_code'   => 'tax_code',
		),
		'transactions'   => array(
			'account_date'    => 'account_id, tx_date',
			'payer_person_id' => 'payer_person_id',
			'document_ref'    => 'document_ref',
			'created_by'      => 'created_by',
		),
		'event_requests' => array(
			'session_person' => 'session_id, person_id',
			'person_id'      => 'person_id',
		),
		'payments'       => array( // tabelle dell'estensione: si creano solo dove esistono
			'provider_payment_id' => 'provider_payment_id',
		),
		'broadcast_rcpt' => array(
			'person_id' => 'person_id',
		),
	);

	/** La tabella esiste? @param string $table nome completo con prefisso */
	public static function table_exists( string $table ): bool {
		$db = Db::db();
		return $table === (string) $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- struttura del database
	}

	/** L'indice esiste già sulla tabella? @param string $table nome completo con prefisso */
	public static function index_exists( string $table, string $index ): bool {
		$db = Db::db();
		return (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s', $table, $index ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- struttura della tabella, non un dato
	}

	/**
	 * Crea gli indici di {@see INDEXES} che mancano. Non modifica né riscrive i dati (indici secondari, creati sul posto senza bloccare le scritture),
	 * non fa nulla se l'indice c'è già e non dà errore se un'altra richiesta lo crea nello stesso momento. Si può rilanciare quando si vuole.
	 *
	 * @return string[] indici creati ora, come «tabella.indice»
	 */
	public static function ensure_indexes(): array {
		$db      = Db::db();
		$created = array();
		foreach ( self::INDEXES as $name => $indexes ) {
			$table = Db::t( $name );
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			foreach ( $indexes as $index => $columns ) {
				if ( self::index_exists( $table, $index ) ) {
					continue;
				}
				$quiet = $db->suppress_errors( true ); // «indice già presente» per una richiesta in parallelo non è un errore
				$db->query( "CREATE INDEX `{$index}` ON `{$table}` ({$columns})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nomi fissi del codice
				$db->suppress_errors( $quiet );
				if ( self::index_exists( $table, $index ) ) {
					$created[] = $name . '.' . $index;
				}
			}
		}
		return $created;
	}

	/**
	 * Dalla v62 il numero del verbale è salvato. Ai verbali esistenti (non cancellati) si assegna il numero che già mostravano: progressivo per tipo e
	 * anno della data, in ordine di data e poi di inserimento, quindi nessun numero visibile cambia. Ripartendo da dove sono arrivati i numeri già
	 * salvati, si può rilanciare senza effetti. I verbali già cancellati restano senza numero. @return int verbali numerati
	 */
	public static function migrate_minute_numbers(): int {
		$db  = Db::db();
		$tbl = Db::t( 'minutes' );
		$n   = 0;
		try {
			Db::with_lock(
				'asem_minutes_number',
				function () use ( $db, $tbl, &$n ) {
					$rows = $db->get_results( "SELECT id, kind, meeting_date FROM $tbl WHERE deleted_at IS NULL AND number_seq IS NULL ORDER BY kind, YEAR(meeting_date), meeting_date, id", ARRAY_A ) ?: array();
					$next = array();
					foreach ( $rows as $r ) {
						$y = (int) substr( (string) $r['meeting_date'], 0, 4 );
						$k = $r['kind'] . '|' . $y;
						if ( ! isset( $next[ $k ] ) ) {
							$next[ $k ] = 1 + (int) $db->get_var( $db->prepare( "SELECT MAX(number_seq) FROM $tbl WHERE kind = %s AND number_year = %d", $r['kind'], $y ) );
						}
						$db->update( $tbl, array( 'number_year' => $y, 'number_seq' => $next[ $k ] ), array( 'id' => (int) $r['id'] ) );
						++$next[ $k ];
						++$n;
					}
				}
			);
		} catch ( \InvalidArgumentException $e ) {
			return 0; // blocco occupato: ci pensa la prossima attivazione, e intanto ogni verbale si numera da solo alla prima lettura
		}
		return $n;
	}

	/**
	 * Dalla v58 ogni chiusura di un esercizio lascia una riga permanente in `fiscal_year_closures`. Per gli esercizi già chiusi prima di questa
	 * funzione si crea la riga della prima versione con lo stato «non fotografato»: nessun dato viene inventato (chi ha chiuso e il motivo non
	 * si conoscono). Si può rilanciare senza effetti. @return int righe create
	 */
	public static function migrate_year_closures(): int {
		$n = 0;
		foreach ( Db::db()->get_results( 'SELECT year, closed_at FROM ' . Db::t( 'fiscal_years' ) . " WHERE status = 'closed'", ARRAY_A ) ?: array() as $y ) {
			if ( null === FiscalYears::current_closure( (int) $y['year'] ) ) {
				FiscalYears::record_closure( (int) $y['year'], $y['closed_at'] ? (string) $y['closed_at'] : Db::now(), 0 );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Dalla v63 il mese di partenza dell'anno sociale è gennaio (prima era settembre). Chi usa già il programma e non ha mai salvato questa scelta
	 * deve restare com'era, cioè con la tessera sull'anno solare: se ha già soci o tessere, gennaio viene scritto come scelta salvata. Chi ha una
	 * scelta salvata (qualunque mese) non si tocca; chi non ha ancora dati prende il nuovo valore di partenza. Si può rilanciare senza effetti.
	 *
	 * @return bool true se il mese è stato fissato a gennaio
	 */
	public static function migrate_social_year_month(): bool {
		$saved = get_option( Settings::OPTION, array() );
		if ( is_array( $saved ) && array_key_exists( 'social_year_start_month', $saved ) ) {
			return false; // una scelta salvata c'è già: resta
		}
		$db       = Db::db();
		$has_data = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL' ) > 0 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- nomi tabella interni, nessun valore dall'esterno
			|| (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE deleted_at IS NULL' ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- nomi tabella interni, nessun valore dall'esterno
		if ( ! $has_data ) {
			return false; // nessun dato: vale il nuovo valore di partenza, gennaio
		}
		$saved                            = is_array( $saved ) ? $saved : array();
		$saved['social_year_start_month'] = 1;
		update_option( Settings::OPTION, $saved );
		Settings::forget(); // la copia in memoria di questa richiesta si rilegge
		return true;
	}

	const OPT_LEGACY_ROLE_USERS = 'asem_legacy_role_users'; // elenco (id, nome, ruolo) di chi entrava nel pannello solo con un ruolo di WordPress, per l'avviso in Bacheca

	/**
	 * Dalla v67 il plugin non dà più permessi ai ruoli di WordPress altrui (Editore, Autore, ruoli di altri plugin): si toglie ai ruoli che li avevano.
	 * Nessun utente cambia ruolo. Chi entrava solo per quel ruolo (non è amministratore, non ha un ruolo interno, non è presidente o vicepresidente)
	 * finisce in un elenco per l'avviso dell'amministratore; l'operazione resta scritta nel registro azioni (numero di ruoli e di utenti, nomi dei ruoli).
	 * Si può rilanciare senza effetti: al secondo passaggio non c'è più niente da togliere. @return array{roles:string[],users:int} ruoli ripuliti e utenti nell'avviso
	 */
	public static function migrate_wp_role_access(): array {
		$wp_roles = wp_roles();
		$caps     = array();
		foreach ( $wp_roles->roles as $key => $r ) {
			$caps[ (string) $key ] = (array) ( $r['capabilities'] ?? array() );
		}
		$clean = WpRoleCleanup::roles_to_clean( $caps );
		if ( ! $clean ) {
			return array( 'roles' => array(), 'users' => 0 );
		}
		$list = (array) get_option( self::OPT_LEGACY_ROLE_USERS, array() );
		$list = array_filter( $list, 'is_array' );
		foreach ( get_users( array( 'role__in' => $clean ) ) as $u ) { // prima di togliere i permessi: chi lavora come presidente o vicepresidente non dipende dal ruolo
			$roles = array_values( (array) $u->roles );
			if ( ! WpRoleCleanup::entered_only_by_cleaned_role( $roles, $clean, Access::is_board_operator( (int) $u->ID ) ) ) {
				continue;
			}
			$names = array();
			foreach ( array_intersect( $roles, $clean ) as $rk ) {
				$names[] = translate_user_role( (string) ( $wp_roles->roles[ $rk ]['name'] ?? $rk ) );
			}
			$list[ (int) $u->ID ] = array( 'id' => (int) $u->ID, 'name' => (string) $u->display_name, 'role' => implode( ', ', $names ) );
		}
		foreach ( $clean as $key ) {
			$role = get_role( $key );
			foreach ( WpRoleCleanup::caps() as $cap ) {
				if ( $role && array_key_exists( $cap, (array) $role->capabilities ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		if ( $list ) {
			update_option( self::OPT_LEGACY_ROLE_USERS, $list, false );
			delete_option( Employees::OPT_LEGACY_ROLES_SEEN );
		}
		Audit::log( 'roles.wp_access_removed', 'role', null, array( 'roles' => count( $clean ), 'users' => count( $list ), 'names' => implode( ',', $clean ) ) );
		return array( 'roles' => $clean, 'users' => count( $list ) );
	}

	/**
	 * Dalla v64 il pulsante WhatsApp è una scelta a parte (`whatsapp_button`): il telefono della segreteria è obbligatorio e può essere un fisso.
	 * Chi aveva già un numero compilato (che prima accendeva il pulsante) lo tiene acceso. Si può rilanciare senza effetti. @return bool true se è stato acceso
	 */
	/**
	 * La voce degli incassi di attività e corsi si chiama «Contributo attività / corso» (prima «Quota attività / corso»). Si rinomina solo la voce di tipo
	 * «activity_fee» che ha ancora il nome originale: chi l'ha chiamata in altro modo la ritrova com'era. Gli anni fiscali chiusi hanno il rendiconto
	 * fotografato (testo salvato alla chiusura): non si toccano. @return int voci rinominate
	 */
	public static function migrate_activity_fee_name(): int {
		$db = Db::db();
		return (int) $db->query( $db->prepare( 'UPDATE ' . Db::t( 'categories' ) . ' SET name = %s WHERE kind = %s AND name = %s', 'Contributo attività / corso', 'activity_fee', 'Quota attività / corso' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tabella del plugin, valori preparati
	}

	public static function migrate_whatsapp_button(): bool {
		$saved = get_option( Settings::OPTION, array() );
		if ( ! is_array( $saved ) || array_key_exists( 'whatsapp_button', $saved ) || '' === trim( (string) ( $saved['secretary_phone'] ?? '' ) ) ) {
			return false;
		}
		$saved['whatsapp_button'] = 1;
		update_option( Settings::OPTION, $saved );
		Settings::forget(); // la copia in memoria di questa richiesta si rilegge
		return true;
	}

	/**
	 * Dalla v61 il diritto di voto non è più una scelta per tipo di socio nelle impostazioni: nel gratuito votano tutti, nel Pro si decide per livello
	 * (`member_levels.votes`). Chi aveva tolto la spunta a un tipo (opzione `voting_types` della v60) mantiene lo stesso effetto: i livelli di quella
	 * base passano a «non vota». Chi non aveva toccato nulla non cambia: votano tutti. Si può rilanciare senza effetti.
	 */
	public static function migrate_voting_types(): void {
		$saved = get_option( Settings::OPTION, array() );
		if ( ! is_array( $saved ) || ! isset( $saved['voting_types'] ) ) {
			return;
		}
		$keep = is_array( $saved['voting_types'] ) ? array_map( 'strval', $saved['voting_types'] ) : array();
		if ( array_intersect( MemberType::member_types(), $keep ) ) { // se non ne restava nessuno votavano tutti
			foreach ( array_diff( MemberType::member_types(), $keep ) as $base ) {
				Db::db()->update( Db::t( 'member_levels' ), array( 'votes' => 0 ), array( 'base_type' => $base ) );
			}
		}
		unset( $saved['voting_types'] );
		update_option( Settings::OPTION, $saved );
		Settings::forget(); // la copia in memoria di questa richiesta si rilegge
	}

	/** Dalla v45 di due tutori ne opera uno solo sul sito: dove prima ce n'erano due resta operativo il primo collegato. */
	public static function migrate_operative_guardians(): void {
		$db   = Db::db();
		$tbl  = Db::t( 'guardians' );
		$rows = $db->get_results( "SELECT id, person_id FROM $tbl ORDER BY id", ARRAY_A ) ?: array();
		$seen = array();
		foreach ( $rows as $r ) {
			$k = (int) $r['person_id'];
			if ( isset( $seen[ $k ] ) ) {
				$db->update( $tbl, array( 'is_operative' => 0 ), array( 'id' => (int) $r['id'] ) );
			}
			$seen[ $k ] = true;
		}
	}

	/**
	 * La tessera dura l'anno solare (scade il 31 dicembre): le iscrizioni registrate con l'anno sociale (es. "2025/2026", fino al 31 agosto)
	 * diventano l'anno solare in cui finivano ("2026", fino al 31 dicembre). Non si accorcia nessuna validità. @return int iscrizioni convertite
	 */
	public static function migrate_membership_years(): int {
		$db   = Db::db();
		$tbl  = Db::t( 'memberships' );
		$rows = $db->get_results( "SELECT * FROM $tbl WHERE deleted_at IS NULL AND social_year LIKE '%/%'", ARRAY_A ) ?: array();
		$n    = 0;
		foreach ( $rows as $r ) {
			$year = (int) substr( $r['social_year'], (int) strpos( $r['social_year'], '/' ) + 1, 4 );
			if ( $year < 2000 ) {
				continue;
			}
			$label    = (string) $year;
			$to       = $year . '-12-31';
			$existing = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND social_year = %s", (int) $r['person_id'], $label ), ARRAY_A );
			if ( $existing && null === $existing['deleted_at'] ) {
				$db->update( $tbl, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $r['id'] ) ); // c'è già l'anno fiscale: basta quello
			} elseif ( $existing ) {
				$db->update( $tbl, array( 'valid_from' => $r['valid_from'], 'valid_to' => $to, 'source' => $r['source'], 'transaction_id' => $r['transaction_id'], 'deleted_at' => null ), array( 'id' => (int) $existing['id'] ) );
				$db->update( $tbl, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $r['id'] ) );
			} else {
				$db->update( $tbl, array( 'social_year' => $label, 'valid_to' => $to ), array( 'id' => (int) $r['id'] ) );
			}
			$n++;
		}
		return $n;
	}

	const REALIGN_OPTION = 'asem_memberships_realigned'; // vuoto = da fare, 1 = fatto

	/**
	 * La tessera segue l'anno fiscale: le tessere già emesse con un altro anno (per esempio «2025/2026», settembre-agosto) si riallineano all'anno
	 * fiscale in cui scadono. Mai accorciare: la scadenza si allunga fino alla fine di quell'anno fiscale, mai si anticipa. Gli anni fiscali già chiusi
	 * non si toccano. Se una persona ha due tessere che finirebbero con la stessa etichetta resta valida la più recente e l'altra è segnata
	 * «sostituita» (resta registrata, pagamenti invariati). Prima si fa una copia di sicurezza; ogni passo è nel registro azioni.
	 *
	 * @return int tessere riallineate (-1 se la copia di sicurezza non è riuscita: si riprova)
	 */
	/** Cosa cambierebbe con il mese di inizio $m: elenco dei passi (tessere che cambiano etichetta o scadenza, tessere «sostituite»). */
	public static function realign_plan( int $m ): array {
		$db   = Db::db();
		$tbl  = Db::t( 'memberships' );
		$tx   = Db::t( 'transactions' );
		$rows = $db->get_results( "SELECT m.*, (t.id IS NOT NULL) AS paid FROM $tbl m LEFT JOIN $tx t ON t.id = m.transaction_id AND t.voided_at IS NULL WHERE m.deleted_at IS NULL AND m.replaced_by IS NULL AND m.social_year <> 'FOUNDER' ORDER BY m.person_id, m.valid_to DESC, m.id DESC", ARRAY_A ) ?: array(); // si riallineano solo le tessere con un pagamento registrato: senza pagamento non si allunga niente
		$plan = array();
		$used = array(); // persona => etichette già prese dopo il riallineamento
		foreach ( $rows as $r ) {
			$fy              = FiscalYears::year_for( (string) $r['valid_to'], $m );
			$label           = 1 === $m ? (string) $fy : $fy . '/' . ( $fy + 1 );
			list( $from, $to ) = FiscalYears::range_for( $fy, $m );
			$fiscal          = FiscalYears::get( $fy );
			$closed          = $fiscal && 'open' !== $fiscal['status']; // il chiuso è chiuso
			$pid             = (int) $r['person_id'];
			$aligned         = ( $label === (string) $r['social_year'] && $to === (string) $r['valid_to'] );
			if ( $closed || $aligned || empty( $r['paid'] ) ) {
				$used[ $pid ][ (string) $r['social_year'] ] = (int) $r['id'];
				continue;
			}
			if ( isset( $used[ $pid ][ $label ] ) ) { // c'è già una tessera più recente con questa etichetta: questa è sostituita
				$plan[] = array( 'id' => (int) $r['id'], 'replace_by' => $used[ $pid ][ $label ] );
				continue;
			}
			if ( (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $tbl WHERE person_id = %d AND social_year = %s AND id <> %d", $pid, $label, (int) $r['id'] ) ) > 0 ) { // l'etichetta è già occupata (anche da una tessera cancellata): non si tocca
				$used[ $pid ][ (string) $r['social_year'] ] = (int) $r['id'];
				continue;
			}
			$used[ $pid ][ $label ] = (int) $r['id'];
			$plan[]               = array( 'id' => (int) $r['id'], 'label' => $label, 'valid_to' => max( (string) $r['valid_to'], $to ), 'old_label' => (string) $r['social_year'], 'old_to' => (string) $r['valid_to'] );
		}
		return $plan;
	}

	/**
	 * Anteprima per chi sta per cambiare il mese dell'anno fiscale (o per la prima volta dopo l'aggiornamento): quante tessere cambiano,
	 * come diventano (per esempio «2025/2026 → 2026») e quante finiscono «sostituite». Non cambia nulla.
	 *
	 * @return array{changed:int,replaced:int,examples:string[]}
	 */
	public static function realign_preview( int $m ): array {
		$out = array( 'changed' => 0, 'replaced' => 0, 'examples' => array() );
		foreach ( self::realign_plan( $m ) as $p ) {
			if ( isset( $p['replace_by'] ) ) {
				$out['replaced']++;
				continue;
			}
			$out['changed']++;
			$ex = $p['old_label'] . ' → ' . $p['label'];
			if ( count( $out['examples'] ) < 3 && ! in_array( $ex, $out['examples'], true ) ) {
				$out['examples'][] = $ex;
			}
		}
		return $out;
	}

	const NOTICE_OPTION = 'asem_realign_notice'; // esito dell'ultimo riallineamento, per l'avviso in Bacheca: changed, replaced, at

	/** @return int passi fatti (tessere riallineate o sostituite); -1 se la copia di sicurezza non è riuscita: si riprova */
	public static function realign_memberships_to_fiscal(): int {
		$db   = Db::db();
		$tbl  = Db::t( 'memberships' );
		$plan = self::realign_plan( Settings::membership_start_month() );
		if ( ! $plan ) {
			return 0;
		}
		try { // prima la copia di sicurezza: se non riesce non si cambia nulla
			Attachments::prepare_dir();
			if ( ! is_dir( Backup::dir() ) ) {
				wp_mkdir_p( Backup::dir() ); // la cartella delle copie può non esserci ancora
			}
			Backup::export( false, Backup::dir() . '/prima-del-riallineamento-delle-tessere-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.zip' ); // nome non prevedibile, come le altre copie
		} catch ( \Throwable $e ) {
			Audit::log( 'memberships.realign_skipped', 'membership', null, array( 'reason' => 'backup', 'error' => $e->getMessage() ) );
			return -1;
		}
		$n = 0;
		$changed = 0;
		$replaced = 0;
		foreach ( $plan as $p ) {
			if ( isset( $p['replace_by'] ) ) {
				$db->update( $tbl, array( 'replaced_by' => (int) $p['replace_by'] ), array( 'id' => (int) $p['id'] ) );
				Audit::log( 'membership.replaced', 'membership', (int) $p['id'], array( 'by' => (int) $p['replace_by'] ) );
				$n++;
				$replaced++;
				continue;
			}
			$db->update( $tbl, array( 'social_year' => $p['label'], 'valid_to' => $p['valid_to'] ), array( 'id' => (int) $p['id'] ) );
			Audit::log( 'membership.realigned', 'membership', (int) $p['id'], array( 'from' => $p['old_label'], 'to' => $p['label'], 'valid_to_old' => $p['old_to'], 'valid_to_new' => $p['valid_to'] ) );
			$n++;
			$changed++;
		}
		update_option( self::NOTICE_OPTION, array( 'changed' => $changed, 'replaced' => $replaced, 'at' => time() ), false ); // l'avviso in Bacheca
		return $n;
	}

	/** Fa il riallineamento delle tessere una volta sola; se la copia non è riuscita si riprova al prossimo accesso al pannello. */
	public static function maybe_realign_memberships(): void {
		if ( '0' !== (string) get_option( self::REALIGN_OPTION, '' ) ) { // solo dopo un aggiornamento che l'ha chiesto («0»), e finché non è riuscito
			return;
		}
		if ( self::realign_memberships_to_fiscal() >= 0 ) {
			update_option( self::REALIGN_OPTION, '1', false );
		}
	}

	/**
	 * Per soci e volontari esistono solo rimborsi (mai compensi) e gli istruttori non sono un ruolo: la voce
	 * "Compenso / rimborso istruttore" si unisce a "Rimborso spese socio/volontario" (i movimenti passano alla voce unica).
	 *
	 * @return int voci unite
	 */
	public static function migrate_reimbursements(): int {
		$db     = Db::db();
		$cat    = Db::t( 'categories' );
		$tx     = Db::t( 'transactions' );
		$old    = $db->get_results( "SELECT * FROM $cat WHERE kind = 'instructor_reimbursement' AND deleted_at IS NULL ORDER BY id", ARRAY_A ) ?: array();
		$target = $db->get_row( "SELECT * FROM $cat WHERE kind = 'member_reimbursement' AND deleted_at IS NULL ORDER BY id LIMIT 1", ARRAY_A );
		$n      = 0;
		if ( ! $target && $old ) {
			$first = array_shift( $old ); // non c'è la voce unica: la prima vecchia diventa quella
			$db->update( $cat, array( 'kind' => 'member_reimbursement' ), array( 'id' => (int) $first['id'] ) );
			$target = $first;
			$n++;
		}
		foreach ( $old as $o ) {
			$db->query( $db->prepare( "UPDATE $tx SET category_id = %d WHERE category_id = %d", (int) $target['id'], (int) $o['id'] ) );
			$db->update( $cat, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $o['id'] ) );
			$n++;
		}
		if ( $target ) {
			$db->update( $cat, array( 'name' => 'Rimborso spese socio/volontario' ), array( 'id' => (int) $target['id'] ) );
		}
		return $n;
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'asem_';

		// dbDelta è esigente: un campo per riga, due spazi dopo PRIMARY KEY, nomi per ogni KEY.
		$tables   = array();
		$tables[] = "CREATE TABLE {$p}people (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  type varchar(20) NOT NULL,
  card_number varchar(40) DEFAULT NULL,
  matricola int(10) unsigned DEFAULT NULL,
  first_name varchar(120) NOT NULL,
  last_name varchar(120) NOT NULL,
  email varchar(190) DEFAULT NULL,
  phone varchar(60) DEFAULT NULL,
  tax_code varchar(32) DEFAULT NULL,
  birth_date date DEFAULT NULL,
  birth_place varchar(120) DEFAULT NULL,
  address varchar(190) DEFAULT NULL,
  zip varchar(12) DEFAULT NULL,
  city varchar(100) DEFAULT NULL,
  province varchar(5) DEFAULT NULL,
  profile_due tinyint(1) NOT NULL DEFAULT 0,
  host_person_id bigint(20) unsigned DEFAULT NULL,
  joined_on date DEFAULT NULL,
  approved_on date DEFAULT NULL,
  suspended_at datetime DEFAULT NULL,
  board_role varchar(20) DEFAULT NULL,
  level_id bigint(20) unsigned DEFAULT NULL,
  family_head_id bigint(20) unsigned DEFAULT NULL,
  left_on date DEFAULT NULL,
  left_reason varchar(190) DEFAULT NULL,
  privacy_consent_at datetime DEFAULT NULL,
  privacy_consent_source varchar(20) DEFAULT NULL,
  anonymized_at datetime DEFAULT NULL,
  rules_accepted_at datetime DEFAULT NULL,
  rules_accepted_version varchar(20) DEFAULT NULL,
  rules_accepted_source varchar(20) DEFAULT NULL,
  rules_accepted_by bigint(20) unsigned DEFAULT NULL,
  privacy_consent_by bigint(20) unsigned DEFAULT NULL,
  application_status varchar(12) DEFAULT NULL,
  application_at datetime DEFAULT NULL,
  application_data text,
  image_consent tinyint(1) DEFAULT NULL,
  image_consent_at datetime DEFAULT NULL,
  image_consent_by bigint(20) unsigned DEFAULT NULL,
  affiliation_role varchar(20) DEFAULT NULL,
  subject_kind varchar(10) NOT NULL DEFAULT 'person',
  runts_same_section tinyint(1) DEFAULT NULL,
  prior_visits int(10) unsigned NOT NULL DEFAULT 0,
  qr_version smallint(5) unsigned NOT NULL DEFAULT 0,
  notes text,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY card_number (card_number),
  UNIQUE KEY matricola (matricola),
  UNIQUE KEY wp_user_id (wp_user_id),
  KEY email (email),
  KEY type (type),
  KEY host_person_id (host_person_id),
  KEY family_head_id (family_head_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}guardians (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  guardian_person_id bigint(20) unsigned NOT NULL,
  is_operative tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_guardian (person_id,guardian_person_id),
  KEY guardian_person_id (guardian_person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}minutes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  meeting_date date NOT NULL,
  place varchar(190) DEFAULT NULL,
  title varchar(190) NOT NULL,
  attendees text,
  agenda text,
  body longtext,
  approved_on date DEFAULT NULL,
  number_year smallint(5) unsigned DEFAULT NULL,
  number_seq int(10) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY kind_date (kind,meeting_date),
  UNIQUE KEY kind_number (kind,number_year,number_seq)
) $c;";

		$tables[] = "CREATE TABLE {$p}member_levels (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(80) NOT NULL,
  base_type varchar(20) NOT NULL,
  fee_cents bigint(20) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  votes tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}memberships (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  social_year varchar(12) NOT NULL,
  valid_from date NOT NULL,
  valid_to date NOT NULL,
  source varchar(20) NOT NULL,
  replaced_by bigint(20) unsigned DEFAULT NULL,
  transaction_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_year (person_id,social_year),
  KEY transaction_id (transaction_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}accounts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  type varchar(20) NOT NULL,
  opening_cents bigint(20) NOT NULL DEFAULT 0,
  fee_fixed_cents int(11) NOT NULL DEFAULT 0,
  fee_bp int(11) NOT NULL DEFAULT 0,
  closed_at datetime DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}fiscal_years (
  year smallint(5) unsigned NOT NULL,
  status varchar(8) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (year)
) $c;";

		// Traccia permanente delle chiusure e delle riaperture degli esercizi: non la tocca né la pulizia né «Libera spazio» del registro azioni.
		$tables[] = "CREATE TABLE {$p}fiscal_year_closures (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  year smallint(5) unsigned NOT NULL,
  version int(11) NOT NULL DEFAULT 1,
  closed_at datetime NOT NULL,
  closed_by bigint(20) unsigned DEFAULT NULL,
  start_month_used tinyint(3) unsigned NOT NULL DEFAULT 1,
  range_from date DEFAULT NULL,
  range_to date DEFAULT NULL,
  plugin_version varchar(20) DEFAULT NULL,
  reopened_at datetime DEFAULT NULL,
  reopened_by bigint(20) unsigned DEFAULT NULL,
  reopen_reason text DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'current',
  snapshot_state varchar(10) NOT NULL DEFAULT 'none',
  retroactive tinyint(1) NOT NULL DEFAULT 0,
  snapshotted_at datetime DEFAULT NULL,
  snapshotted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY year_version (year,version)
) $c;";

		// Libro soci fotografato alla chiusura di un esercizio: una riga per socio per chiusura, con i soli dati minimi (l'elenco è in MemberBook::snapshot_fields()).
		// Non si cancella mai: né alla riapertura (la chiusura diventa una versione precedente), né con l'anonimizzazione o la cancellazione di un socio.
		$tables[] = "CREATE TABLE {$p}member_book_snapshot (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  closure_id bigint(20) unsigned NOT NULL,
  n int(11) NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  matricola int(10) unsigned DEFAULT NULL,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY closure_n (closure_id,n),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}funds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  activity_id bigint(20) unsigned DEFAULT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}fund_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fund_id bigint(20) unsigned NOT NULL,
  kind varchar(8) NOT NULL,
  cents bigint(20) NOT NULL,
  tx_id bigint(20) unsigned DEFAULT NULL,
  entry_date date NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY fund_id (fund_id),
  KEY tx_id (tx_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  kind varchar(40) NOT NULL,
  fiscal_group varchar(160) DEFAULT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}activities (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  social_year varchar(12) NOT NULL,
  instructor_person_id bigint(20) unsigned DEFAULT NULL,
  monthly_fee_cents bigint(20) NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT 'course',
  fee_cents bigint(20) NOT NULL DEFAULT 0,
  guest_fee_cents bigint(20) DEFAULT NULL,
  price_lists text,
  hold_hours smallint(5) unsigned NOT NULL DEFAULT 0,
  vat_rate tinyint(3) DEFAULT NULL,
  cancellable tinyint(1) NOT NULL DEFAULT 0,
  cancel_policy varchar(4) DEFAULT NULL,
  booking_qr tinyint(1) NOT NULL DEFAULT 0,
  lesson_weekday tinyint(1) NOT NULL DEFAULT 0,
  lesson_slots text,
  billing varchar(12) NOT NULL DEFAULT 'monthly',
  sport varchar(60) DEFAULT NULL,
  discipline varchar(120) DEFAULT NULL,
  lesson_start char(5) DEFAULT NULL,
  lesson_end char(5) DEFAULT NULL,
  location varchar(190) DEFAULT NULL,
  starts_on date DEFAULT NULL,
  ends_on date DEFAULT NULL,
  fund_mode varchar(8) NOT NULL DEFAULT '',
  fund_value bigint(20) NOT NULL DEFAULT 0,
  resource_id bigint(20) unsigned DEFAULT NULL,
  credit_price int(11) DEFAULT NULL,
  setup_before_min int(11) DEFAULT NULL,
  setup_after_min int(11) DEFAULT NULL,
  occupy_on_booking tinyint(1) NOT NULL DEFAULT 0,
  resource_block varchar(40) DEFAULT NULL,
  resource_block_label varchar(120) DEFAULT NULL,
  notes text,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY social_year (social_year)
) $c;";

		$tables[] = "CREATE TABLE {$p}enrollments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  start_month char(7) NOT NULL,
  end_month char(7) DEFAULT NULL,
  price_list varchar(30) DEFAULT NULL,
  fee_note varchar(190) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_person (activity_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tx_date date NOT NULL,
  type varchar(20) NOT NULL,
  amount_cents bigint(20) NOT NULL,
  account_id bigint(20) unsigned NOT NULL,
  method varchar(20) NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  activity_id bigint(20) unsigned DEFAULT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  session_id bigint(20) unsigned DEFAULT NULL,
  reservation_id bigint(20) unsigned DEFAULT NULL,
  competence_month char(7) DEFAULT NULL,
  social_year varchar(12) DEFAULT NULL,
  document_ref varchar(80) DEFAULT NULL,
  discount_cents bigint(20) NOT NULL DEFAULT 0,
  vat_rate tinyint(3) DEFAULT NULL,
  vat_cents bigint(20) NOT NULL DEFAULT 0,
  payer_person_id bigint(20) unsigned DEFAULT NULL,
  receipt_id varchar(40) DEFAULT NULL,
  import_batch bigint(20) unsigned DEFAULT NULL,
  transfer_id varchar(40) DEFAULT NULL,
  void_reason varchar(255) DEFAULT NULL,
  voided_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tx_date (tx_date),
  KEY account_id (account_id),
  KEY activity_id (activity_id),
  KEY person_id (person_id),
  KEY receipt_id (receipt_id),
  KEY import_batch (import_batch),
  KEY transfer_id (transfer_id),
  KEY session_id (session_id),
  KEY reservation_id (reservation_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}cash_counts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  account_id bigint(20) unsigned NOT NULL,
  count_date date NOT NULL,
  counted_cents bigint(20) NOT NULL,
  expected_cents bigint(20) NOT NULL,
  difference_cents bigint(20) NOT NULL,
  adjustment_tx_id bigint(20) unsigned DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY account_id (account_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  session_date date NOT NULL,
  end_date date DEFAULT NULL,
  start_time char(5) DEFAULT NULL,
  end_time char(5) DEFAULT NULL,
  location varchar(190) DEFAULT NULL,
  capacity int(11) DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id),
  KEY session_date (session_date)
) $c;";

		$tables[] = "CREATE TABLE {$p}lesson_exceptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  lesson_date date NOT NULL,
  action varchar(8) NOT NULL,
  new_date date DEFAULT NULL,
  new_start char(5) DEFAULT NULL,
  new_end char(5) DEFAULT NULL,
  note varchar(190) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_lesson (activity_id,lesson_date),
  KEY new_date (new_date)
) $c;";

		$tables[] = "CREATE TABLE {$p}bookings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'booked',
  fee_due_cents bigint(20) NOT NULL DEFAULT 0,
  price_list varchar(40) DEFAULT NULL,
  guest_of varchar(12) DEFAULT NULL,
  waived_cents bigint(20) NOT NULL DEFAULT 0,
  package_entries smallint(5) unsigned NOT NULL DEFAULT 0,
  package_of bigint(20) unsigned DEFAULT NULL,
  transferred_to bigint(20) unsigned DEFAULT NULL,
  transferred_from bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  cancelled_at datetime DEFAULT NULL,
  checked_in_at datetime DEFAULT NULL,
  checked_in_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_person (session_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}event_requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  session_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  seats smallint(5) unsigned NOT NULL DEFAULT 1,
  status varchar(12) NOT NULL DEFAULT 'new',
  token_hash char(64) NOT NULL,
  email varchar(190) DEFAULT NULL,
  data longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deadline datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY activity_id (activity_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}activity_staff (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  can_cash tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_person (activity_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}waitlist (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  requested_by bigint(20) unsigned DEFAULT NULL,
  price_list varchar(30) DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'waiting',
  created_at datetime NOT NULL,
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_person (session_id,person_id),
  KEY status (status)
) $c;";

		$tables[] = "CREATE TABLE {$p}reminders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  ref varchar(60) NOT NULL,
  sent_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_kind_ref (person_id,kind,ref),
  KEY sent_at (sent_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}notices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  session_id bigint(20) unsigned DEFAULT NULL,
  author_user_id bigint(20) unsigned DEFAULT NULL,
  author_name varchar(120) NOT NULL DEFAULT '',
  subject varchar(160) NOT NULL,
  body text NOT NULL,
  recipients int(11) NOT NULL DEFAULT 0,
  emailed int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id),
  KEY created_at (created_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}attachments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL,
  original_name varchar(190) NOT NULL,
  stored_name varchar(40) NOT NULL,
  mime varchar(60) NOT NULL,
  size_bytes bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  enc_ver tinyint(3) unsigned DEFAULT NULL,
  uploaded_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  removed_at datetime DEFAULT NULL,
  removed_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY transaction_id (transaction_id),
  KEY sha256 (sha256)
) $c;";

		$tables[] = "CREATE TABLE {$p}import_batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  source varchar(190) NOT NULL DEFAULT '',
  summary text,
  data longtext,
  undone_at datetime DEFAULT NULL,
  undone_by bigint(20) unsigned DEFAULT NULL,
  undo_result text,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  action varchar(60) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  details text,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action (action)
) $c;";

		$tables[] = "CREATE TABLE {$p}polls (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL DEFAULT 'vote',
  title varchar(190) NOT NULL,
  question text,
  multiple tinyint(1) NOT NULL DEFAULT 0,
  max_choices smallint(5) unsigned NOT NULL DEFAULT 0,
  secret tinyint(1) NOT NULL DEFAULT 0,
  audience varchar(10) NOT NULL DEFAULT 'members',
  activity_id bigint(20) unsigned DEFAULT NULL,
  opens_at datetime NOT NULL,
  closes_at datetime NOT NULL,
  show_results tinyint(1) NOT NULL DEFAULT 0,
  notify tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY opens_closes (opens_at,closes_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}poll_options (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  label varchar(190) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  votes int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY poll_id (poll_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}poll_voters (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  voted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY poll_person (poll_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}poll_votes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  option_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY poll_option (poll_id,option_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  category varchar(80) NOT NULL DEFAULT '',
  code varchar(60) DEFAULT NULL,
  quantity int(11) NOT NULL DEFAULT 1,
  state varchar(10) NOT NULL DEFAULT 'good',
  location varchar(190) NOT NULL DEFAULT '',
  value_cents bigint(20) DEFAULT NULL,
  purchased_on date DEFAULT NULL,
  notes text,
  file_name varchar(190) DEFAULT NULL,
  file_stored varchar(64) DEFAULT NULL,
  file_sha256 char(64) DEFAULT NULL,
  enc_ver tinyint(3) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY code (code)
) $c;";

		$tables[] = "CREATE TABLE {$p}loans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  quantity int(11) NOT NULL DEFAULT 1,
  lent_on date NOT NULL,
  due_on date NOT NULL,
  returned_on date DEFAULT NULL,
  notes_out varchar(255) NOT NULL DEFAULT '',
  notes_in varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_id (item_id),
  KEY person_id (person_id),
  KEY due_on (due_on)
) $c;";

		$tables[] = "CREATE TABLE {$p}documents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(190) NOT NULL,
  category varchar(80) NOT NULL DEFAULT '',
  doc_date date DEFAULT NULL,
  visibility varchar(10) NOT NULL DEFAULT 'staff',
  description varchar(255) NOT NULL DEFAULT '',
  file_name varchar(190) NOT NULL,
  file_stored varchar(64) NOT NULL,
  file_sha256 char(64) DEFAULT NULL,
  enc_ver tinyint(3) unsigned DEFAULT NULL,
  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY visibility (visibility)
) $c;";

		$tables[] = "CREATE TABLE {$p}conventions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  category varchar(80) NOT NULL DEFAULT '',
  benefit varchar(500) NOT NULL DEFAULT '',
  terms text,
  valid_from date DEFAULT NULL,
  valid_to date DEFAULT NULL,
  address varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  website varchar(255) NOT NULL DEFAULT '',
  logo_id bigint(20) unsigned DEFAULT NULL,
  audience varchar(10) NOT NULL DEFAULT 'all',
  types varchar(120) NOT NULL DEFAULT '',
  is_public tinyint(1) NOT NULL DEFAULT 0,
  code varchar(60) NOT NULL DEFAULT '',
  announced_at datetime DEFAULT NULL,
  archived_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY category (category),
  KEY valid_to (valid_to)
) $c;";

		// Accesso proprio dei soci (senza utente WordPress). Orari in UTC; nel database restano solo impronte, mai token o codici in chiaro.
		$tables[] = "CREATE TABLE {$p}member_auth (
  person_id bigint(20) unsigned NOT NULL,
  login_email varchar(190) NOT NULL,
  pass_hash varchar(255) DEFAULT NULL,
  must_reset tinyint(1) unsigned NOT NULL DEFAULT 0,
  failed_count smallint(5) unsigned NOT NULL DEFAULT 0,
  locked_until datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  last_login_at datetime DEFAULT NULL,
  PRIMARY KEY  (person_id),
  UNIQUE KEY login_email (login_email)
) $c;";

		$tables[] = "CREATE TABLE {$p}member_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  last_seen datetime NOT NULL,
  expires_at datetime NOT NULL,
  remember tinyint(1) NOT NULL DEFAULT 1,
  ip_hash char(32) NOT NULL DEFAULT '',
  ua_hash char(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY person_id (person_id),
  KEY expires_at (expires_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}member_challenges (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  kind varchar(8) NOT NULL DEFAULT 'login',
  token_hash char(64) NOT NULL,
  code_hash char(64) NOT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY person_id (person_id),
  KEY expires_at (expires_at)
) $c;";

		$tables = (array) apply_filters( 'asem_schema_tables', $tables, $p, $c ); // le estensioni aggiungono le loro tabelle
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	public static function add_roles_and_caps(): void {
		// Ruolo per i soci creati come utenti: può solo "read", quindi nessun accesso alla gestione.
		if ( ! get_role( Plugin::ROLE_MEMBER ) ) {
			add_role( Plugin::ROLE_MEMBER, 'Socio', array( 'read' => true ) );
		}
		// Segreteria: lavora su soci, attività e contabilità, ma non tocca impostazioni, pagamenti online, privacy e testi.
		if ( ! get_role( Plugin::ROLE_SECRETARY ) ) {
			add_role( Plugin::ROLE_SECRETARY, 'Segreteria', array( 'read' => true, Plugin::CAP_OPS => true ) );
		}
		Employees::ensure_role();
		$wp_roles = wp_roles(); // i ruoli creati con il vecchio nome («Socio APS», «Segreteria APS») prendono il nome nuovo
		$names    = array( Plugin::ROLE_MEMBER => 'Socio', Plugin::ROLE_SECRETARY => 'Segreteria' );
		$renamed  = false;
		foreach ( $names as $key => $name ) {
			if ( isset( $wp_roles->roles[ $key ] ) && $name !== $wp_roles->roles[ $key ]['name'] ) {
				$wp_roles->roles[ $key ]['name'] = $name;
				$wp_roles->role_names[ $key ]    = $name;
				$renamed                         = true;
			}
		}
		if ( $renamed ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
		$admin = get_role( 'administrator' );
		foreach ( array( Plugin::CAP, Plugin::CAP_OPS ) as $cap ) {
			if ( $admin && ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function seed(): void {
		global $wpdb;
		$cat = Db::t( 'categories' );
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cat" ) ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabelle del plugin, nessuna API di WordPress equivalente
			$entrate  = 'Entrate da attività di interesse generale';
			$uscite   = 'Uscite da attività di interesse generale';
			$seed     = array(
				array( 'Quota associativa', 'membership', 'Entrate da quote associative' ),
				array( 'Contributo attività / corso', 'activity_fee', $entrate ),
				array( 'Erogazione liberale', 'donation', 'Erogazioni liberali' ),
				array( 'Altre entrate', 'other_income', 'Altre entrate' ),
				array( 'Rimborso spese socio/volontario', 'member_reimbursement', $uscite ),
				array( 'Costi attività (materiali, noleggi)', 'activity_cost', $uscite ),
				array( 'Costi generali (affitto, utenze, assicurazione)', 'general_cost', 'Uscite di supporto generale' ),
				array( 'Rettifica di cassa', 'adjustment', 'Rettifiche' ),
			);
			foreach ( $seed as $s ) {
				$wpdb->insert( $cat, array( 'name' => $s[0], 'kind' => $s[1], 'fiscal_group' => $s[2] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- tabelle del plugin, nessuna API di WordPress equivalente
			}
		}
		Levels::seed();
		FiscalYears::seed(); // l'anno fiscale in corso (e quelli con movimenti) esiste sempre
		$acc = Db::t( 'accounts' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $acc" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabelle del plugin, nessuna API di WordPress equivalente
		if ( 0 === $count ) {
			$wpdb->insert( $acc, array( 'name' => 'Cassa contanti', 'type' => 'cash', 'sort_order' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- tabelle del plugin, nessuna API di WordPress equivalente
			$count = 1;
		}
		if ( 1 === $count && Edition::has( 'funds' ) ) { // l'edizione gratuita ha una cassa unica; con le funzioni avanzate c'è anche il conto corrente
			$wpdb->insert( $acc, array( 'name' => 'Conto corrente', 'type' => 'bank', 'sort_order' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- tabelle del plugin, nessuna API di WordPress equivalente
		}
	}
}

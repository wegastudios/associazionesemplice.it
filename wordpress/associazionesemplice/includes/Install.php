<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class Install {

	const DB_VERSION_OPTION = 'asem_db_version';
	const DB_VERSION        = '63'; // dalla 62 il verbale ha il numero salvato (number_year, number_seq); dalla 63 l'anno sociale parte a gennaio

	public static function activate(): void {
		self::create_tables();
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

	/** Esegue gli aggiornamenti dello schema se il plugin è stato aggiornato senza riattivarlo. */
	public static function maybe_upgrade(): void {
		$old = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( $old !== self::DB_VERSION ) {
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
			if ( '' !== $old && version_compare( $old, '3', '<' ) ) {
				// Dalla v3 il contributo di un'attività è `fee_cents` (per i corsi resta "al mese").
				Db::db()->query( 'UPDATE ' . Db::t( 'activities' ) . ' SET fee_cents = monthly_fee_cents WHERE fee_cents = 0 AND monthly_fee_cents > 0' );
			}
		}
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
				array( 'Quota attività / corso', 'activity_fee', $entrate ),
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

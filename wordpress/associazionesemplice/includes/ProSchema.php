<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tabelle delle funzioni aggiuntive. Le crea il Pro agganciandosi allo schema di Associazione Semplice (filtro `asem_schema_tables`);
 * le tabelle già esistenti non si toccano (dbDelta aggiunge solo ciò che manca).
 */
final class ProSchema {

	const VERSION_OPTION = 'asem_pro_db_version';
	const VERSION        = '8';

	public static function register(): void {
		add_filter( 'asem_schema_tables', array( __CLASS__, 'tables' ), 10, 3 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 12 ); // dopo Install::maybe_upgrade() del gratuito (priorità 10)
	}

	/** Se lo schema del Pro è cambiato (o è la prima volta) ricrea le tabelle mancanti. */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			self::create();
		}
	}

	public static function create(): void {
		Install::create_tables();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * @param string[] $tables istruzioni CREATE TABLE già pronte
	 * @return string[]
	 */
	public static function tables( array $tables, string $p, string $c ): array {
		$tables[] = "CREATE TABLE {$p}insurance (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  company varchar(120) NOT NULL,
  policy_no varchar(80) DEFAULT NULL,
  valid_from date NOT NULL,
  valid_to date NOT NULL,
  notes varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}assoc_policies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  company varchar(120) NOT NULL,
  policy_no varchar(80) DEFAULT NULL,
  valid_from date NOT NULL,
  valid_to date NOT NULL,
  premium_cents bigint(20) DEFAULT NULL,
  coverage varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind (kind)
) $c;";

		$tables[] = "CREATE TABLE {$p}fivepm (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  year smallint(5) unsigned NOT NULL,
  amount_cents bigint(20) NOT NULL,
  choices int(11) NOT NULL DEFAULT 0,
  received_on date NOT NULL,
  reported_on date DEFAULT NULL,
  report_notes text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY year (year)
) $c;";

		$tables[] = "CREATE TABLE {$p}woo_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(12) NOT NULL,
  ref_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_ref (kind,ref_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}push_subs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint text NOT NULL,
  endpoint_hash char(40) NOT NULL,
  p256dh varchar(140) NOT NULL,
  auth varchar(40) NOT NULL,
  ua varchar(190) DEFAULT NULL,
  failures int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_ok_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}attendance (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  lesson_date date NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  present tinyint(1) NOT NULL DEFAULT 1,
  marked_by bigint(20) unsigned DEFAULT NULL,
  marked_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY lesson_person (activity_id,lesson_date,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}resources (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  category varchar(12) NOT NULL DEFAULT 'other',
  mode varchar(8) NOT NULL DEFAULT 'slot',
  parent_id bigint(20) unsigned DEFAULT NULL,
  description text,
  rules text,
  rates text,
  pay_mode varchar(8) NOT NULL DEFAULT 'desk',
  credit_cost int(11) NOT NULL DEFAULT 0,
  fund_id bigint(20) unsigned DEFAULT NULL,
  fund_mode varchar(8) NOT NULL DEFAULT '',
  fund_value bigint(20) NOT NULL DEFAULT 0,
  setup_before_min int(11) NOT NULL DEFAULT 0,
  setup_after_min int(11) NOT NULL DEFAULT 0,
  category_id bigint(20) unsigned DEFAULT NULL,
  remind_hours smallint(5) unsigned NOT NULL DEFAULT 0,
  entry_qr tinyint(1) NOT NULL DEFAULT 0,
  open_time char(5) DEFAULT NULL,
  close_time char(5) DEFAULT NULL,
  slot_minutes smallint(5) unsigned NOT NULL DEFAULT 60,
  min_slots smallint(5) unsigned NOT NULL DEFAULT 1,
  max_slots smallint(5) unsigned NOT NULL DEFAULT 1,
  advance_days smallint(5) unsigned NOT NULL DEFAULT 0,
  max_per_person smallint(5) unsigned NOT NULL DEFAULT 0,
  fee_cents bigint(20) NOT NULL DEFAULT 0,
  guests_max smallint(5) unsigned NOT NULL DEFAULT 0,
  guest_fee_cents bigint(20) NOT NULL DEFAULT 0,
  cancellable tinyint(1) NOT NULL DEFAULT 0,
  cancel_policy varchar(4) DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}reservations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(8) NOT NULL DEFAULT 'slot',
  starts_at datetime NOT NULL,
  ends_at datetime DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'booked',
  fee_due_cents bigint(20) NOT NULL DEFAULT 0,
  note varchar(255) DEFAULT NULL,
  series_id bigint(20) unsigned DEFAULT NULL,
  hold_until datetime DEFAULT NULL,
  credits_paid int(11) NOT NULL DEFAULT 0,
  checked_in_at datetime DEFAULT NULL,
  checked_in_by bigint(20) unsigned DEFAULT NULL,
  session_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  cancelled_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY resource_time (resource_id,starts_at),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}credit_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  delta int(11) NOT NULL,
  kind varchar(8) NOT NULL,
  label varchar(190) NOT NULL DEFAULT '',
  ref_type varchar(16) DEFAULT NULL,
  ref_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}credit_charges (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code char(24) NOT NULL,
  credits int(11) NOT NULL,
  label varchar(190) NOT NULL DEFAULT '',
  activity_id bigint(20) unsigned DEFAULT NULL,
  staff_person_id bigint(20) unsigned NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  payer_person_id bigint(20) unsigned DEFAULT NULL,
  entry_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY staff_person_id (staff_person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}resource_waitlist (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'waiting',
  created_at datetime NOT NULL,
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY resource_status (resource_id,status),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}reservation_guests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reservation_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  PRIMARY KEY  (id),
  KEY reservation_id (reservation_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}broadcasts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject varchar(190) NOT NULL,
  body text NOT NULL,
  audience varchar(40) NOT NULL,
  audience_ref bigint(20) unsigned DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'sending',
  total int(10) unsigned NOT NULL DEFAULT 0,
  sent int(10) unsigned NOT NULL DEFAULT 0,
  failed int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$tables[] = "CREATE TABLE {$p}broadcast_rcpt (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  broadcast_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  email varchar(190) NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'queued',
  sent_at datetime DEFAULT NULL,
  error varchar(190) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY broadcast_status (broadcast_id,status)
) $c;";

		$tables[] = "CREATE TABLE {$p}receipts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  receipt_key varchar(40) NOT NULL,
  year smallint(5) unsigned NOT NULL,
  number int(10) unsigned NOT NULL,
  issued_at datetime NOT NULL,
  issued_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY receipt_key (receipt_key),
  UNIQUE KEY year_number (year,number)
) $c;";

		$tables[] = "CREATE TABLE {$p}payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id varchar(40) NOT NULL,
  provider varchar(12) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'created',
  amount_cents bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'EUR',
  payer_person_id bigint(20) unsigned NOT NULL,
  payer_user_id bigint(20) unsigned NOT NULL,
  items longtext NOT NULL,
  provider_ref varchar(120) DEFAULT NULL,
  provider_payment_id varchar(120) DEFAULT NULL,
  allocated_cents bigint(20) NOT NULL DEFAULT 0,
  review tinyint(1) NOT NULL DEFAULT 0,
  refund_due tinyint(1) NOT NULL DEFAULT 0,
  error varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY provider_ref (provider_ref),
  KEY status (status),
  KEY payer_person_id (payer_person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}medical_certs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  issued_on date NOT NULL,
  expires_on date NOT NULL,
  doctor varchar(190) DEFAULT NULL,
  file_name varchar(190) DEFAULT NULL,
  file_stored varchar(80) DEFAULT NULL,
  file_size bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY person_id (person_id),
  KEY expires_on (expires_on)
) $c;";

		$tables[] = "CREATE TABLE {$p}cert_activities (
  activity_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (activity_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}affiliations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  body varchar(12) NOT NULL,
  body_label varchar(80) DEFAULT NULL,
  code varchar(60) NOT NULL,
  season varchar(12) NOT NULL,
  expires_on date DEFAULT NULL,
  cost_cents bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY body_season (body,season)
) $c;";

		$tables[] = "CREATE TABLE {$p}body_cards (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  body varchar(12) NOT NULL,
  body_label varchar(80) DEFAULT NULL,
  card_number varchar(60) DEFAULT NULL,
  card_type varchar(60) DEFAULT NULL,
  season varchar(12) NOT NULL,
  issued_on date DEFAULT NULL,
  expires_on date DEFAULT NULL,
  cost_cents bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY person_id (person_id),
  KEY body_season (body,season)
) $c;";

		$tables[] = "CREATE TABLE {$p}budgets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  year smallint(5) unsigned NOT NULL,
  title varchar(190) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'draft',
  approved_on date DEFAULT NULL,
  assembly_id bigint(20) unsigned DEFAULT NULL,
  notes text,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY year (year)
) $c;";

		$tables[] = "CREATE TABLE {$p}budget_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  budget_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  type varchar(8) NOT NULL,
  planned_cents bigint(20) NOT NULL DEFAULT 0,
  notes varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY budget_category (budget_id,category_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}refunds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  kind varchar(12) NOT NULL DEFAULT 'documented',
  expense_date date NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  activity_id bigint(20) unsigned DEFAULT NULL,
  km int(11) DEFAULT NULL,
  amount_cents bigint(20) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'requested',
  file_name varchar(190) DEFAULT NULL,
  file_stored varchar(64) DEFAULT NULL,
  decided_by bigint(20) unsigned DEFAULT NULL,
  decided_on date DEFAULT NULL,
  reject_reason varchar(255) DEFAULT NULL,
  paid_on date DEFAULT NULL,
  paid_by bigint(20) unsigned DEFAULT NULL,
  account_id bigint(20) unsigned DEFAULT NULL,
  tx_id bigint(20) unsigned DEFAULT NULL,
  requested_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY person_id (person_id),
  KEY status (status),
  KEY expense_date (expense_date)
) $c;";

		$tables[] = "CREATE TABLE {$p}assemblies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(15) NOT NULL DEFAULT 'ordinary',
  title varchar(190) NOT NULL,
  first_call_at datetime NOT NULL,
  second_call_at datetime DEFAULT NULL,
  place varchar(190) NOT NULL DEFAULT '',
  agenda text,
  quorum_first tinyint(3) unsigned NOT NULL DEFAULT 50,
  quorum_second tinyint(3) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'draft',
  convened_on date DEFAULT NULL,
  call_held tinyint(1) unsigned NOT NULL DEFAULT 0,
  eligible_at_close int(11) DEFAULT NULL,
  minutes_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY first_call_at (first_call_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}assembly_attendees (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  assembly_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  marked_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY assembly_person (assembly_id,person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}assembly_proxies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  assembly_id bigint(20) unsigned NOT NULL,
  delegator_id bigint(20) unsigned NOT NULL,
  delegate_id bigint(20) unsigned NOT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY assembly_delegator (assembly_id,delegator_id),
  KEY delegate_id (delegate_id)
) $c;";

		return $tables;
	}
}

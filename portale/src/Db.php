<?php
namespace Portale;

/**
 * Accesso al database (PostgreSQL in produzione; SQLite per i test veloci e per il passaggio) con uno schema scritto in SQL comune.
 * Su PostgreSQL le due uniche particolarità di SQLite usate nel codice si traducono qui (GROUP_CONCAT, LIKE senza maiuscole).
 */
final class Db {

	/** @var \PDO */
	private $pdo;

	/** @var bool */
	private $pg = false;

	public function __construct( \PDO $pdo ) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
		$this->pg = 'pgsql' === $pdo->getAttribute( \PDO::ATTR_DRIVER_NAME );
	}

	/**
	 * Configurazione `db`: `dsn`, `user`, `pass` oppure `pass_file` (percorso di un file fuori dal repository con la sola password),
	 * e per PostgreSQL `schema` (default del ruolo se omesso).
	 */
	public static function connect( array $cfg ): self {
		$dsn = (string) $cfg['dsn'];
		if ( 0 === strpos( $dsn, 'sqlite:' ) && ':memory:' !== substr( $dsn, 7 ) ) {
			$dir = dirname( substr( $dsn, 7 ) );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0750, true );
			}
		}
		$pass = $cfg['pass'] ?? null;
		if ( ! empty( $cfg['pass_file'] ) ) {
			$read = is_readable( (string) $cfg['pass_file'] ) ? file_get_contents( (string) $cfg['pass_file'] ) : false;
			if ( false === $read ) {
				throw new \RuntimeException( 'Password del database non leggibile.' );
			}
			$pass = trim( $read );
		}
		$db = new self( new \PDO( $dsn, $cfg['user'] ?? null, $pass ) );
		if ( $db->pg && ! empty( $cfg['schema'] ) ) {
			if ( 1 !== preg_match( '/^[a-z_][a-z0-9_]{0,62}$/', (string) $cfg['schema'] ) ) {
				throw new \RuntimeException( 'Nome dello schema non valido.' );
			}
			$db->pdo->exec( 'SET search_path TO ' . $cfg['schema'] );
		}
		return $db;
	}

	public function isPostgres(): bool {
		return $this->pg;
	}

	private function sql( string $sql ): string {
		if ( ! $this->pg ) {
			return $sql;
		}
		$sql = (string) preg_replace( '/\bGROUP_CONCAT\(\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\)/i', "string_agg($1::text, ',')", $sql );
		return (string) preg_replace( '/\bLIKE\b/i', 'ILIKE', $sql );
	}

	public function run( string $sql, array $args = array() ): \PDOStatement {
		$st = $this->pdo->prepare( $this->sql( $sql ) );
		$st->execute( $args );
		return $st;
	}

	public function one( string $sql, array $args = array() ): ?array {
		$r = $this->run( $sql, $args )->fetch();
		return $r ?: null;
	}

	public function all( string $sql, array $args = array() ): array {
		return $this->run( $sql, $args )->fetchAll();
	}

	public static function id(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public function migrate(): void {
		$stmts = array(
			'CREATE TABLE IF NOT EXISTS customers (
				id VARCHAR(32) PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS login_tokens (
				token_hash VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, subscription_id VARCHAR(32) NULL,
				expires_at VARCHAR(19) NOT NULL, used_at VARCHAR(19) NULL)',
			'CREATE TABLE IF NOT EXISTS subscriptions (
				id VARCHAR(32) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, plan_code VARCHAR(40) NOT NULL,
				period VARCHAR(8) NOT NULL, provider VARCHAR(12) NOT NULL, provider_ref VARCHAR(120) NULL,
				provider_customer VARCHAR(120) NULL, status VARCHAR(12) NOT NULL, domain VARCHAR(190) NOT NULL,
				license_id VARCHAR(64) NULL, license_key_enc TEXT NULL, expires_at VARCHAR(19) NULL,
				cancel_at_period_end INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)',
			'CREATE INDEX idx_sub_ref ON subscriptions (provider, provider_ref)',
			'CREATE INDEX idx_sub_domain ON subscriptions (domain)',
			'CREATE TABLE IF NOT EXISTS events (
				provider VARCHAR(12) NOT NULL, event_id VARCHAR(120) NOT NULL, processed_at VARCHAR(19) NOT NULL,
				PRIMARY KEY (provider, event_id))',
			'CREATE TABLE IF NOT EXISTS settings (name VARCHAR(60) PRIMARY KEY, val TEXT NOT NULL)',
			'CREATE TABLE IF NOT EXISTS email_templates (
				event VARCHAR(40) PRIMARY KEY, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL,
				enabled INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS audit (
				id VARCHAR(32) PRIMARY KEY, at VARCHAR(19) NOT NULL, actor VARCHAR(190) NOT NULL, action VARCHAR(60) NOT NULL, detail TEXT NULL)',
			'CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(80) NOT NULL, hour VARCHAR(10) NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (bucket, hour))',
			'CREATE TABLE IF NOT EXISTS proxy_cache (k VARCHAR(64) PRIMARY KEY, body TEXT NOT NULL, at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS releases (product VARCHAR(8) NOT NULL, version VARCHAR(20) NOT NULL, size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, note VARCHAR(500) NULL, created_at VARCHAR(19) NOT NULL, PRIMARY KEY (product, version))',
			'CREATE TABLE IF NOT EXISTS downloads (id VARCHAR(32) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, at VARCHAR(19) NOT NULL, product VARCHAR(8) NOT NULL, version VARCHAR(20) NOT NULL)',
			'CREATE INDEX idx_dl_customer ON downloads (customer_id, at)',
			'CREATE TABLE IF NOT EXISTS staff (email VARCHAR(190) PRIMARY KEY, role VARCHAR(10) NOT NULL, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS catalog (product VARCHAR(12) PRIMARY KEY, rev INTEGER NOT NULL, etag VARCHAR(80) NULL, body TEXT NOT NULL, fetched_at VARCHAR(19) NOT NULL, checked_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS orders (id VARCHAR(32) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, product VARCHAR(12) NOT NULL, plan VARCHAR(40) NOT NULL, plan_version INTEGER NOT NULL, period VARCHAR(8) NOT NULL, members INTEGER NOT NULL, catalog_rev INTEGER NOT NULL, quote TEXT NOT NULL, status VARCHAR(12) NOT NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS saas_outbox (id VARCHAR(32) PRIMARY KEY, org_id VARCHAR(64) NOT NULL, rev INTEGER NOT NULL, body TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_at VARCHAR(19) NOT NULL, done_at VARCHAR(19) NULL, last_error VARCHAR(120) NULL, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS seen_jti (jti VARCHAR(64) PRIMARY KEY, exp INTEGER NOT NULL)',
			// Associazioni del SaaS create dal portale: `ref` è l'identificativo stabile mandato al SaaS, `rev` cresce a ogni messaggio `subscription`.
			'CREATE TABLE IF NOT EXISTS saas_orgs (ref VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, org_id VARCHAR(64) NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(40) NOT NULL, rev INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(19) NOT NULL)',
			'ALTER TABLE orders ADD COLUMN org_ref VARCHAR(64) NULL',
			'ALTER TABLE orders ADD COLUMN provider VARCHAR(12) NULL',
			'ALTER TABLE orders ADD COLUMN provider_ref VARCHAR(120) NULL',
			'ALTER TABLE orders ADD COLUMN provider_customer VARCHAR(120) NULL',
			'ALTER TABLE orders ADD COLUMN paid_at VARCHAR(19) NULL',
			'ALTER TABLE orders ADD COLUMN cancel_at_period_end INTEGER NOT NULL DEFAULT 0',
			'ALTER TABLE orders ADD COLUMN reminded_for VARCHAR(10) NULL',
			'ALTER TABLE orders ADD COLUMN suspended_for VARCHAR(10) NULL',
			'ALTER TABLE orders ADD COLUMN retry_from VARCHAR(19) NULL',
			'ALTER TABLE orders ADD COLUMN retry_n INTEGER NOT NULL DEFAULT 0',
			'ALTER TABLE orders ADD COLUMN retry_at VARCHAR(19) NULL',
			// Colonne aggiunte dopo la prima versione: se esistono già l'errore si ignora.
			"ALTER TABLE staff ADD COLUMN saas VARCHAR(10) NOT NULL DEFAULT ''",
			'ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NULL',
			'ALTER TABLE subscriptions ADD COLUMN last_seen_at VARCHAR(19) NULL',
			'ALTER TABLE customers ADD COLUMN name VARCHAR(150) NULL',
			'ALTER TABLE releases ADD COLUMN signature VARCHAR(120) NULL',
			'ALTER TABLE releases ADD COLUMN kid VARCHAR(40) NULL',
			'ALTER TABLE subscriptions ADD COLUMN reminded_for VARCHAR(19) NULL',
			'ALTER TABLE subscriptions ADD COLUMN note VARCHAR(255) NULL',
			'ALTER TABLE subscriptions ADD COLUMN mode VARCHAR(10) NULL',
			'ALTER TABLE subscriptions ADD COLUMN renews VARCHAR(32) NULL',
			'ALTER TABLE subscriptions ADD COLUMN trial_used_at VARCHAR(19) NULL',
			'ALTER TABLE subscriptions ADD COLUMN next_plan VARCHAR(40) NULL',
			'ALTER TABLE subscriptions ADD COLUMN change_to VARCHAR(40) NULL',
			'ALTER TABLE subscriptions ADD COLUMN domain_changed_at VARCHAR(19) NULL',
			// Notifica di abbonamento dal negozio (Plugin\SubscriptionNotice): versione del piano, siti ammessi, ultima revisione applicata.
			'ALTER TABLE subscriptions ADD COLUMN plan_version INTEGER NULL',
			'ALTER TABLE subscriptions ADD COLUMN sites_allowed INTEGER NULL',
			'ALTER TABLE subscriptions ADD COLUMN notice_rev INTEGER NULL',
			// Codici promo del plugin (sempre di un solo prodotto) e usi confermati (uno per ordine).
			'CREATE TABLE IF NOT EXISTS plugin_promos (code VARCHAR(30) PRIMARY KEY, kind VARCHAR(8) NOT NULL, value INTEGER NOT NULL, plans TEXT NOT NULL, starts_on VARCHAR(10) NOT NULL DEFAULT \'\', ends_on VARCHAR(10) NOT NULL, max_uses INTEGER NULL, used INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS plugin_promo_uses (code VARCHAR(30) NOT NULL, order_ref VARCHAR(80) NOT NULL, at VARCHAR(19) NOT NULL, PRIMARY KEY (code, order_ref))',
			// Catalogo del plugin: bozza unica e revisioni pubblicate immutabili.
			'CREATE TABLE IF NOT EXISTS plugin_catalog_draft (id INTEGER PRIMARY KEY, doc TEXT NOT NULL, updated_at VARCHAR(19) NOT NULL, updated_by VARCHAR(190) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS plugin_catalog_revs (rev INTEGER PRIMARY KEY, doc TEXT NOT NULL, at VARCHAR(19) NOT NULL, actor VARCHAR(190) NOT NULL)',
			// Staff dell'amministrazione del plugin (/wordpress/admin): tabella propria. Primo tempo: si copia da `staff`, che resta
			// come ripiego in lettura; il secondo tempo (togliere la colonna `role` vecchia) solo dopo la verifica in produzione.
			'CREATE TABLE IF NOT EXISTS plugin_staff (
				email VARCHAR(190) PRIMARY KEY, role VARCHAR(10) NOT NULL, created_at VARCHAR(19) NOT NULL,
				totp_secret_enc TEXT NULL, mfa_at VARCHAR(19) NULL)',
			"INSERT INTO plugin_staff (email, role, created_at) SELECT email, role, created_at FROM staff
				WHERE role IN ('admin','support') AND email NOT IN (SELECT email FROM plugin_staff)",
			// Elenco centrale dello staff (docs/UTENTI_E_ACCESSI.md §6). Primo tempo: si aggiunge e si copia, le tabelle vecchie restano.
			'CREATE TABLE IF NOT EXISTS staff_people (
				id VARCHAR(32) PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL DEFAULT \'\', active INTEGER NOT NULL DEFAULT 1,
				totp_secret_enc TEXT NULL, mfa_at VARCHAR(19) NULL, recovery_json TEXT NULL, last_login_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS staff_roles (
				person_id VARCHAR(32) NOT NULL, area VARCHAR(12) NOT NULL, level VARCHAR(12) NOT NULL, granted_by VARCHAR(190) NOT NULL DEFAULT \'\',
				granted_at VARCHAR(19) NOT NULL, PRIMARY KEY (person_id, area))',
			'CREATE TABLE IF NOT EXISTS staff_invites (
				id VARCHAR(32) PRIMARY KEY, token_hash VARCHAR(64) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL, area VARCHAR(12) NOT NULL, level VARCHAR(12) NOT NULL,
				expires_at VARCHAR(19) NOT NULL, used_at VARCHAR(19) NULL, created_by VARCHAR(190) NOT NULL, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS staff_sessions (
				sid VARCHAR(32) PRIMARY KEY, person_id VARCHAR(32) NOT NULL, created_at VARCHAR(19) NOT NULL, last_used_at VARCHAR(19) NOT NULL,
				ip VARCHAR(45) NOT NULL DEFAULT \'\', mfa_at VARCHAR(19) NULL, revoked_at VARCHAR(19) NULL)',
			'CREATE TABLE IF NOT EXISTS staff_audit (
				id VARCHAR(32) PRIMARY KEY, at VARCHAR(19) NOT NULL, actor VARCHAR(190) NOT NULL, target VARCHAR(190) NOT NULL DEFAULT \'\',
				action VARCHAR(40) NOT NULL, detail VARCHAR(500) NOT NULL DEFAULT \'\', ip VARCHAR(45) NOT NULL DEFAULT \'\')',
		);
		foreach ( $stmts as $sql ) {
			try {
				$this->pdo->exec( $sql );
			} catch ( \PDOException $e ) {
				// CREATE INDEX e ADD COLUMN non hanno "IF NOT EXISTS" ovunque: se esistono già va bene.
				if ( 0 !== strpos( $sql, 'CREATE INDEX' ) && 0 !== strpos( $sql, 'ALTER TABLE' ) ) {
					throw $e;
				}
			}
		}
		// Staff: copia (ripetibile) degli accessi di oggi nell'elenco centrale; le tabelle vecchie non si toccano.
		try {
			Plugin\Directory::importLegacy( $this );
		} catch ( \Throwable $e ) {
			error_log( '[portale] elenco staff: copia non riuscita (' . $e->getMessage() . ')' );
		}
	}

	/** Segna un evento dei provider come elaborato; false se lo era già (i provider ripetono le consegne). */
	public function claimEvent( string $provider, string $eventId ): bool {
		try {
			$this->run( 'INSERT INTO events (provider, event_id, processed_at) VALUES (?,?,?)', array( $provider, $eventId, self::now() ) );
			return true;
		} catch ( \PDOException $e ) {
			return false;
		}
	}

	/** Se l'elaborazione fallisce l'evento va rilasciato, così la riconsegna del provider lo riprova. */
	public function releaseEvent( string $provider, string $eventId ): void {
		$this->run( 'DELETE FROM events WHERE provider = ? AND event_id = ?', array( $provider, $eventId ) );
	}
}

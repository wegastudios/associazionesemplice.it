<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Stato del server: legge le caratteristiche dell'hosting (PHP, memoria, OPcache, cache, cron, database…) e le confronta con ciò che il plugin
 * consiglia ({@see ServerStatusRules}). Serve alla scheda «Stato del server» (Strumenti) e a qualche prova della «Salute del sito» di WordPress.
 * Costa solo quando una di queste due pagine si apre: nessuna lettura nelle richieste normali, e la classe si carica solo allora.
 */
final class ServerStatus {

	/** Le prove aggiunte alla «Salute del sito»: nome => righe della scheda che la compongono. */
	const HEALTH = array(
		'cron'       => array( 'cron' ),
		'opcache'    => array( 'opcache' ),
		'memory'     => array( 'memory', 'time' ),
		'db_engine'  => array( 'db_engine' ),
		'extensions' => array( 'sodium', 'zip', 'mbstring', 'other_ext' ),
	);

	/** @var array<string,array> righe già calcolate in questa richiesta ('full' o 'light') */
	private static $memo = array();

	/**
	 * Legge i valori dal server.
	 *
	 * @param bool $full vero: anche le letture dal database (versione, dimensione delle richieste, motore delle tabelle appena controllato);
	 *                   falso: solo ciò che si legge senza interrogare il database (le prove della Salute del sito)
	 * @return array<string,mixed>
	 */
	public static function collect( bool $full ): array {
		$e = array(
			'php'           => PHP_VERSION,
			'wp'            => (string) get_bloginfo( 'version' ),
			'opcache'       => self::opcache_on(),
			'memory'        => (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ),
			'time'          => (int) ini_get( 'max_execution_time' ),
			'upload'        => (int) wp_max_upload_size(),
			'object_cache'  => (bool) wp_using_ext_object_cache(),
			'page_cache'    => self::page_cache(),
			'cron_last'     => Jobs::last_run(),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_url'      => site_url( 'wp-cron.php?doing_wp_cron' ),
			'now'           => time(),
			'https'         => is_ssl(),
			'sodium'        => FileCrypt::available(),
			'zip'           => class_exists( '\ZipArchive' ),
			'mbstring'      => function_exists( 'mb_strlen' ),
			'missing_ext'   => self::missing_extensions(),
		);
		if ( $full ) {
			$db = Db::db();
			$e['db_version'] = (string) $db->db_version();
			$e['db_maria']   = method_exists( $db, 'db_server_info' ) && false !== stripos( (string) $db->db_server_info(), 'mariadb' );
			$e['packet']     = self::packet( $db );
			$st              = DbEngine::check(); // aprire la scheda è raro: si legge lo stato vero e si aggiorna quello ricordato
			$e['db_engine']  = array( 'total' => $st['total'], 'bad' => count( $st['bad'] ), 'innodb' => $st['innodb'] );
		} else {
			$st = DbEngine::last(); // la Salute del sito legge solo l'esito ricordato dal controllo settimanale
			if ( $st ) {
				$e['db_engine'] = array( 'total' => $st['total'], 'bad' => count( $st['bad'] ), 'innodb' => $st['innodb'] );
			}
		}
		return $e;
	}

	/** Le righe della scheda, con stato e consiglio. */
	public static function rows( bool $full ): array {
		$k = $full ? 'full' : 'light';
		if ( ! isset( self::$memo[ $k ] ) ) {
			self::$memo[ $k ] = ServerStatusRules::rows( self::collect( $full ) );
		}
		return self::$memo[ $k ];
	}

	/** OPcache è attivo per le richieste web? */
	private static function opcache_on(): bool {
		return extension_loaded( 'Zend OPcache' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
	}

	/** Estensioni facoltative che mancano. @return string[] */
	private static function missing_extensions(): array {
		$out = array();
		foreach ( array( 'zlib', 'iconv', 'SimpleXML', 'openssl', 'intl' ) as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$out[] = $ext;
			}
		}
		return $out;
	}

	/** Valore di max_allowed_packet in byte (0 se non si legge). */
	private static function packet( $db ): int {
		$r = $db->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- impostazione del server, letta solo aprendo la scheda
		return is_array( $r ) && isset( $r[1] ) ? (int) $r[1] : 0;
	}

	/** Nome della cache di pagina rilevata, o stringa vuota. */
	public static function page_cache(): string {
		if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core', false ) ) {
			return 'LiteSpeed Cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			return 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			return 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			return 'WP Super Cache';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			return 'WP Fastest Cache';
		}
		if ( class_exists( '\SiteGround_Optimizer\Loader\Loader', false ) ) {
			return 'SiteGround Optimizer';
		}
		if ( class_exists( '\Cache_Enabler', false ) ) {
			return 'Cache Enabler';
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE && defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			return Texts::t( 'cache di pagina del sito' );
		}
		return '';
	}

	// ---------- Salute del sito di WordPress ----------

	/** Filtro `site_status_tests`: aggiunge le prove del plugin (si eseguono solo aprendo la pagina «Salute del sito»). */
	public static function health_tests( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();
		$names = array(
			'cron'       => Texts::t( 'Operazioni programmate di Associazione Semplice' ),
			'opcache'    => Texts::t( 'OPcache per Associazione Semplice' ),
			'memory'     => Texts::t( 'Memoria e tempo di PHP per Associazione Semplice' ),
			'db_engine'  => Texts::t( 'Tabelle di Associazione Semplice' ),
			'extensions' => Texts::t( 'Estensioni di PHP usate da Associazione Semplice' ),
		);
		foreach ( $names as $id => $label ) {
			$tests['direct']['asem_' . $id] = array(
				'label' => $label,
				'test'  => array( __CLASS__, 'health_' . $id ),
			);
		}
		return $tests;
	}

	public static function health_cron(): array {
		return self::health_result( 'cron', Texts::t( 'Le operazioni programmate girano' ), Texts::t( 'Le operazioni programmate non girano' ) );
	}

	public static function health_opcache(): array {
		return self::health_result( 'opcache', Texts::t( 'OPcache è attivo' ), Texts::t( 'OPcache non è attivo' ) );
	}

	public static function health_memory(): array {
		return self::health_result( 'memory', Texts::t( 'Memoria e tempo di PHP sono adeguati' ), Texts::t( 'Memoria o tempo di PHP sono al minimo' ) );
	}

	public static function health_db_engine(): array {
		return self::health_result( 'db_engine', Texts::t( 'Le tabelle usano InnoDB' ), Texts::t( 'Alcune tabelle non usano InnoDB' ) );
	}

	public static function health_extensions(): array {
		return self::health_result( 'extensions', Texts::t( 'Le estensioni di PHP usate dal plugin ci sono' ), Texts::t( 'Mancano estensioni di PHP usate dal plugin' ) );
	}

	/** Il risultato di una prova: stato peggiore tra le righe che la compongono, con i loro valori e consigli. */
	private static function health_result( string $id, string $good, string $worse ): array {
		$rows     = array();
		$statuses = array();
		foreach ( self::HEALTH[ $id ] as $key ) {
			$r = ServerStatusRules::find( self::rows( false ), $key );
			if ( $r ) {
				$rows[]     = $r;
				$statuses[] = $r['status'];
			}
		}
		if ( ! $rows ) { // nessun dato ricordato (il controllo settimanale delle tabelle non è ancora partito)
			return array(
				'label'       => Texts::t( 'Controllo non ancora eseguito' ),
				'status'      => 'good',
				'badge'       => array( 'label' => 'Associazione Semplice', 'color' => 'blue' ),
				'description' => '<p>' . esc_html( Texts::t( 'Il controllo parte da solo una volta alla settimana. Per vederlo subito apri lo stato del server.' ) ) . '</p>',
				'actions'     => current_user_can( Plugin::CAP ) ? '<p><a href="' . esc_url( Admin\Ui::url( 'asem-server' ) ) . '">' . esc_html( Texts::t( 'Apri lo stato del server' ) ) . '</a></p>' : '',
				'test'        => 'asem_' . $id,
			);
		}
		$worst = ServerStatusRules::worst( $statuses );
		$html  = '';
		foreach ( $rows as $r ) {
			$html .= '<p><strong>' . esc_html( $r['label'] ) . '</strong>: ' . esc_html( $r['value'] ) . '</p>';
			if ( '' !== $r['advice'] ) {
				$html .= '<p>' . esc_html( $r['advice'] ) . '</p>';
			}
			if ( ! empty( $r['code'] ) ) {
				$html .= '<p><code>' . esc_html( $r['code'] ) . '</code></p>';
			}
		}
		$link = '<p><a href="' . esc_url( Admin\Ui::url( 'asem-server' ) ) . '">' . esc_html( Texts::t( 'Apri lo stato del server' ) ) . '</a></p>';
		return array(
			'label'       => ServerStatusRules::OK === $worst ? $good : $worse,
			'status'      => ServerStatusRules::OK === $worst ? 'good' : ( ServerStatusRules::BAD === $worst ? 'critical' : 'recommended' ),
			'badge'       => array( 'label' => 'Associazione Semplice', 'color' => 'blue' ),
			'description' => $html,
			'actions'     => current_user_can( Plugin::CAP ) ? $link : '',
			'test'        => 'asem_' . $id,
		);
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Capability di amministrazione completa (impostazioni, pagamenti online, privacy, testi): solo gli amministratori. */
	const CAP = 'asem_manage';

	/** Capability per lavorare con soci, attività e contabilità: amministratori e ruolo Segreteria. */
	const CAP_OPS = 'asem_operate';

	/** Ruolo WordPress della segreteria: opera sul plugin senza essere amministratore del sito. */
	const ROLE_SECRETARY = 'asem_secretary';

	/** Ruolo WordPress del dipendente dell'ente: parte con i permessi della segreteria; si possono togliere solo quelli dell'elenco di {@see EmployeeRules}. */
	const ROLE_EMPLOYEE = 'asem_employee';

	/** Ruolo WordPress dato ai nuovi utenti creati per i soci: nessun accesso all'area di amministrazione. */
	const ROLE_MEMBER = 'asem_member';

	/**
	 * Versione dello schema del database (la storia dei cambi è accanto a {@see Install::DB_VERSION}, che vale questa).
	 * Sta qui perché il confronto con quella salvata si fa a ogni richiesta: così la classe Install, grande, si carica solo quando c'è da aggiornare.
	 */
	const DB_VERSION = '74';

	/** Opzione con la versione dello schema salvata (la stessa di {@see Install::DB_VERSION_OPTION}). */
	const DB_VERSION_OPTION = 'asem_db_version';

	private static $services = array();

	public static function init(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			Install::maybe_upgrade();
		}
		FiscalYears::maybe_open_current(); // il primo giorno dell'anno si apre il nuovo anno fiscale
		Access::register();      // capability meta: asem_notify_activity, asem_view_person...
		Employees::register();   // utenti «Dipendente»: permessi che si possono togliere, conteggio per il RUNTS
		Gatekeeper::register(); // i soci restano fuori da wp-admin
		add_action( 'rest_api_init', array( Rest\Api::class, 'routes' ) ); // associazionesemplice/v1: la classe si carica solo per le richieste REST
		Frontend\Front::init();  // shortcode, contenuti riservati, blocchi, widget
		if ( '' !== Edition::mismatch() ) { // Associazione Semplice Pro troppo diverso da questo Associazione Semplice: non si carica, e si dice cosa fare
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>Associazione Semplice Pro</strong> non è compatibile con questa versione di Associazione Semplice: le funzioni avanzate sono sospese. Aggiorna entrambi all\'ultima versione; il resto funziona e i dati sono al sicuro.</p></div>';
					}
				}
			);
		}
		MailLog::register();     // registro dell'esito delle email spedite
		PublicEvents::register(); // pagina pubblica di eventi e corsi (un contenuto collegato a ogni attività)
		Frontend\WhatsappFloat::register(); // pulsante WhatsApp fisso (se c'è il numero della segreteria)
		self::updater(); // aggiornamenti dal portale: nel pacchetto per WordPress.org non c'è (lì aggiorna WordPress.org)
		self::schedule_jobs();   // lavori periodici: la classe si carica solo quando il lavoro parte
		self::lazy_admin_post(); // download e azioni di admin-post: la classe si carica solo per quell'azione
		// Parti che servono solo se la funzione è presente e in uso: altrimenti nemmeno si caricano.
		if ( defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin', false ) ) {
			WpAllImport::register(); // compatibilità con WP All Import (area di appoggio)
		}
		if ( is_admin() ) {
			Admin\Admin::init();
		}
	}

	/** Richiesta della parte di gestione: bacheca (comprese admin-post.php e admin-ajax.php), WP-Cron o WP-CLI. Sul sito pubblico è falso. */
	public static function backend(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/** Hook di WordPress con cui lavora l'aggiornatore anche fuori dalla bacheca (aggiornamenti chiesti da remoto, ad esempio da un servizio di gestione dei siti). */
	const UPDATER_HOOKS = array( 'pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_pre_download', 'upgrader_process_complete' );

	/** C'è l'aggiornatore dal portale (in questo pacchetto o nella copia del plugin aggiuntivo)? Lo dice senza caricarne la classe. */
	public static function has_updater(): bool {
		return class_exists( __NAMESPACE__ . '\Updater', false ) || is_readable( ASEM_DIR . 'includes/Updater.php' ) || ( defined( 'ASEM_PRO_DIR' ) && is_readable( ASEM_PRO_DIR . 'updater/Updater.php' ) );
	}

	/**
	 * Aggiornatore dal portale: in bacheca, nel cron e in WP-CLI si registra subito; sul sito pubblico la sua classe nemmeno si legge,
	 * e si carica solo se WordPress lavora davvero sugli aggiornamenti dei plugin (uno degli hook di UPDATER_HOOKS).
	 */
	private static function updater(): void {
		if ( ! is_readable( ASEM_DIR . 'includes/Updater.php' ) ) {
			return; // pacchetto per WordPress.org
		}
		if ( self::backend() ) {
			Updater::register();
			return;
		}
		$load = static function ( $value = null ) {
			Updater::register(); // una volta sola: le chiamate successive non fanno nulla; i suoi hook si aggiungono mentre WordPress esegue quello in corso
			return $value;
		};
		foreach ( self::UPDATER_HOOKS as $hook ) {
			add_filter( $hook, $load, 1 );
		}
	}

	/**
	 * Lavori periodici: hook => [frequenza, secondi al primo giro, classe, metodo, funzione che li richiede].
	 * Si registra il nome dell'hook e il richiamo con il nome della classe: la classe viene caricata solo quando WP-Cron esegue il lavoro.
	 */
	private static function schedule_jobs(): void {
		$jobs = array(
			'asem_release_holds'          => array( 'hourly', 900, 'Holds', 'release_expired', '' ),
			'asem_event_requests'         => array( 'hourly', 1500, 'EventRequests', 'run', '' ),
			'asem_send_reminders'         => array( 'daily', 600, 'Reminders', 'run', '' ),
			'asem_purge_backups'          => array( 'hourly', 1200, 'Backup', 'purge_old', '' ),
			'asem_private_folder_check'   => array( 'daily', 3000, 'PrivateFolder', 'cron', '' ),
			'asem_file_crypt_migrate'     => array( '', 0, 'FileMigration', 'cron', '' ), // lotti programmati per cifrare i file già caricati (si programmano da soli, uno alla volta)
			'asem_file_crypt_migrate_certs' => array( '', 0, 'FileMigration', 'certs_cron', '' ), // lotti dei soli certificati medici già caricati (partono da soli dopo l'aggiornamento)
			'asem_file_crypt_certs_check' => array( 'hourly', 1000, 'FileMigration', 'auto_certs', '' ), // se la cifratura dei certificati si è fermata o è andata persa, riparte
			'asem_purge_audit'            => array( 'daily', 1800, 'Audit', 'purge_old', '' ),
			'asem_purge_member_sessions'  => array( 'daily', 2400, 'MemberSession', 'purge_expired', '' ),
			'asem_purge_applications'     => array( 'daily', 3300, 'Applications', 'purge', '' ), // domande mai confermate (30 giorni) e respinte con quota pagata (90 giorni dalla risposta)
			'asem_long_job_tick'          => array( '', 0, 'LongJob', 'tick', '' ), // rete di sicurezza dei lavori lunghi: una tappa quando la pagina è chiusa (si programma da sola, un evento per volta)
		);
		$jobs = (array) apply_filters( 'asem_scheduled_jobs', $jobs ); // un'estensione aggiunge i suoi lavori periodici
		if ( is_admin() ) {
			add_action( 'admin_init', array( __NAMESPACE__ . '\\FileMigration', 'auto_certs' ) ); // dopo l'aggiornamento i certificati medici già caricati partono da soli
			add_action( 'admin_init', array( __NAMESPACE__ . '\\Install', 'maybe_realign_memberships' ) ); // la tessera segue l'anno fiscale: se la copia di sicurezza non era riuscita si riprova
		}
		foreach ( $jobs as $hook => $j ) {
			if ( '' !== $j[4] && ! Edition::installed( $j[4] ) ) {
				continue;
			}
			add_action( $hook, array( __NAMESPACE__ . '\\' . $j[2], $j[3] ) );
			if ( '' !== $j[0] && ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $j[1], $j[0], $hook );
			}
		}
	}

	/** Azione di admin-post => classe che la gestisce. */
	const ADMIN_POST = array(
		'asem_backup'          => 'Backup',
		'asem_backup_saved'    => 'Backup',
		'asem_privacy_export'  => 'Privacy',
		'asem_doc'             => 'Docs',
		'asem_document'        => 'Documents',
		'asem_item_file'       => 'Inventory',
	);

	/** Azioni di admin-post con la classe che le gestisce, comprese quelle di un'estensione (filtro `asem_admin_post_classes`). */
	private static function admin_post_classes(): array {
		return (array) apply_filters( 'asem_admin_post_classes', self::ADMIN_POST );
	}

	private static function lazy_admin_post(): void {
		add_action(
			'admin_init',
			function () {
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( '' !== $action ) {
					self::load_for_action( $action );
				}
			},
			1
		);
	}

	/** Carica e registra la classe che gestisce un'azione di admin-post. @return bool true se l'azione è una di quelle gestite così */
	public static function load_for_action( string $action ): bool {
		$classes = self::admin_post_classes();
		if ( ! isset( $classes[ $action ] ) ) {
			return false;
		}
		$class = __NAMESPACE__ . '\\' . $classes[ $action ];
		if ( ! class_exists( $class ) ) { // la funzione non è in questa edizione
			return false;
		}
		$class::register();
		return true;
	}

	/** Campi delle password: non si ripuliscono (come fa WordPress), si usano solo per wp_set_password() o per cifrarli. */
	const VERBATIM_FIELDS = array( 'password', 'password2', 'pass1', 'pass2', 'apple_password', 'phrase', 'phrase2', 'old_enc_const', 'old_auth_key', 'old_auth_salt', 'rphrase', 'rphrase2', 'recovery_phrase', 'file_phrase', 'file_phrase2' );

	/**
	 * I dati di un modulo ($_POST) senza le barre aggiunte da WordPress e già ripuliti: gli indirizzi (_back, *_url) con esc_url_raw(),
	 * ogni altro testo con sanitize_textarea_field() (niente tag, UTF-8 valido, a capo conservati per i testi su più righe). Ogni gestore
	 * poi valida i suoi campi con la funzione adatta (email, numero, data, valori ammessi…). Fanno eccezione solo le password, che non vanno alterate.
	 */
	public static function post_data(): array {
		$out = array();
		foreach ( (array) wp_unslash( $_POST ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- il nonce lo controlla il chiamante; chiavi e valori sono ripuliti qui sotto
			$name = sanitize_key( (string) $key );
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( $name, self::VERBATIM_FIELDS, true ) ) {
				$out[ $name ] = is_string( $value ) ? wp_kses_no_null( (string) wp_check_invalid_utf8( $value ) ) : '';
				continue;
			}
			$is_url       = '_back' === $name || '_wp_http_referer' === $name || '_url' === substr( $name, -4 );
			$out[ $name ] = self::clean_value( $value, $is_url );
		}
		return $out;
	}

	/**
	 * Un valore del modulo ripulito; negli elenchi (campi come `righe[3][importo]`) anche le chiavi, a ogni livello:
	 * numeri interi o testo con sanitize_key().
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function clean_value( $value, bool $is_url ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$k = is_int( $k ) ? $k : sanitize_key( (string) $k );
				if ( '' !== $k ) {
					$out[ $k ] = self::clean_value( $v, $is_url );
				}
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		return $is_url ? esc_url_raw( $value ) : sanitize_textarea_field( $value );
	}

	/** Versione di un file in assets/: cambia a ogni aggiornamento, così il browser non tiene in cache script e stili vecchi. */
	public static function asset_version( string $file ): string {
		$path = ASEM_DIR . 'assets/' . $file;
		return ASEM_VERSION . '.' . ( is_readable( $path ) ? (string) filemtime( $path ) : '0' );
	}

	public static function people(): PeopleService {
		return self::$services['people'] ?? ( self::$services['people'] = new PeopleService() );
	}

	public static function activities(): ActivityService {
		return self::$services['activities'] ?? ( self::$services['activities'] = new ActivityService() );
	}

	public static function ledger(): LedgerService {
		return self::$services['ledger'] ?? ( self::$services['ledger'] = new LedgerService() );
	}

	public static function funds(): FundService {
		return self::$services['funds'] ?? ( self::$services['funds'] = new FundService() );
	}

	/** Servizio dei pagamenti: quello online di un'estensione (filtro `asem_payments_service`) se c'è, altrimenti solo l'elenco di ciò che c'è da pagare. */
	public static function payments(): OfflinePayments {
		$offline = self::$services['offline_payments'] ?? ( self::$services['offline_payments'] = new OfflinePayments() );
		if ( ! Edition::has( 'payments' ) ) {
			return $offline;
		}
		$svc = apply_filters( 'asem_payments_service', $offline );
		return $svc instanceof OfflinePayments ? $svc : $offline;
	}

	public static function reports(): ReportService {
		return self::$services['reports'] ?? ( self::$services['reports'] = new ReportService() );
	}
}

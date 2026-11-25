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
	const DB_VERSION = '80';

	/** Opzione con la versione dello schema salvata (la stessa di {@see Install::DB_VERSION_OPTION}). */
	const DB_VERSION_OPTION = 'asem_db_version';

	private static $services = array();

	public static function init(): void {
		if ( wp_using_ext_object_cache() ) {
			SharedMemo::boot(); // con Redis, Memcached o simili alcune letture si ricordano tra le richieste; senza, la classe nemmeno si carica
		}
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			Install::maybe_upgrade();
		}
		if ( ! self::schema_ready() ) { // aggiornamento dello schema rimasto a metà: le scritture aspettano, le letture proseguono
			self::guard_writes();
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
		add_action(
			'admin_notices',
			function () {
				if ( current_user_can( 'activate_plugins' ) && ! ActivityService::others_allowed() ) { // gratuito aggiornato da solo: serve anche il Pro
					echo '<div class="notice notice-warning"><p>Aggiorna <strong>Associazione Semplice Pro</strong> per far prenotare ai soci anche altri soci. Fino ad allora la prenotazione per altri soci non si offre e i contributi già prenotati per altri si incassano in segreteria; il resto funziona.</p></div>';
				}
			}
		);
		MailLog::register();     // registro dell'esito delle email spedite
		PublicEvents::register(); // pagina pubblica di eventi e corsi (un contenuto collegato a ogni attività)
		Frontend\WhatsappFloat::register(); // pulsante WhatsApp fisso (se c'è il numero della segreteria)
		self::updater(); // aggiornamenti dal portale: nel pacchetto per WordPress.org non c'è (lì aggiorna WordPress.org)
		self::schedule_jobs();   // lavori periodici: la classe si carica solo quando il lavoro parte
		add_filter( 'asem_long_jobs', array( __NAMESPACE__ . '\\Backup', 'long_jobs' ) ); // copia di sicurezza a tappe: la classe si carica solo quando un lavoro lungo parte o avanza
		self::lazy_admin_post(); // download e azioni di admin-post: la classe si carica solo per quell'azione
		// Parti che servono solo se la funzione è presente e in uso: altrimenti nemmeno si caricano.
		if ( defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin', false ) ) {
			WpAllImport::register(); // compatibilità con WP All Import (area di appoggio)
		}
		if ( is_admin() ) {
			Admin\Admin::init();
		}
	}

	/**
	 * Lo schema del database è pronto? (versione salvata = versione del codice; come {@see Install::schema_ready()}, ma senza caricare quella classe.)
	 * Con un'estensione vale anche il suo schema: lo dice il filtro `asem_extension_schema_ready` (senza estensioni nessuno risponde e resta vero).
	 */
	public static function schema_ready(): bool {
		return self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' ) && (bool) apply_filters( 'asem_extension_schema_ready', true );
	}

	/** Con lo schema a metà le scritture non partono: i moduli (admin-post, admin-ajax) e le chiamate REST del plugin rispondono «riprova tra un minuto». Le regole sono in {@see SchemaGuard}. */
	private static function guard_writes(): void {
		add_action(
			'admin_init',
			function () {
				$msg = self::write_refusal( self::requested_action() );
				if ( '' === $msg ) {
					return;
				}
				if ( wp_doing_ajax() ) {
					wp_send_json_error( array( 'message' => $msg ), 503 );
				}
				wp_die( esc_html( $msg ), '', array( 'response' => 503, 'back_link' => true ) );
			},
			0
		);
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_guard' ), 10, 3 );
	}

	/** La risposta a una scrittura rifiutata per lo schema a metà; vuota se l'azione può andare avanti. */
	public static function write_refusal( string $action ): string {
		return SchemaGuard::action_blocked( $action, self::schema_ready() ) ? Texts::t( SchemaGuard::MESSAGE ) : '';
	}

	/**
	 * Filtro `rest_pre_dispatch`: con lo schema a metà le chiamate REST del plugin che scrivono (compresi i segnali dei gestori di pagamento, che riprovano) ricevono un 503.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function rest_guard( $result, $server, $request ) {
		if ( null === $result && $request instanceof \WP_REST_Request && SchemaGuard::rest_blocked( (string) $request->get_route(), (string) $request->get_method(), self::schema_ready() ) ) {
			return new \WP_Error( 'asem_schema_upgrading', Texts::t( SchemaGuard::MESSAGE ), array( 'status' => 503 ) );
		}
		return $result;
	}

	/** Richiesta della parte di gestione: bacheca (comprese admin-post.php e admin-ajax.php), WP-Cron o WP-CLI. Sul sito pubblico è falso. */
	public static function backend(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/** Aggiornatore dal portale (UpdaterBoot e Updater ci sono solo nel pacchetto del portale): in bacheca, cron e WP-CLI subito, sul sito pubblico solo se serve. */
	private static function updater(): void {
		if ( is_readable( ASEM_DIR . 'includes/UpdaterBoot.php' ) ) {
			UpdaterBoot::register( self::backend() );
		}
	}

	/**
	 * Lavori periodici: hook => [frequenza, secondi al primo giro, classe, metodo, funzione che li richiede].
	 * Si registra il nome dell'hook e il richiamo con il nome della classe: la classe viene caricata solo quando WP-Cron esegue il lavoro.
	 */
	private static function schedule_jobs(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __NAMESPACE__ . '\\FileMigration', 'auto_certs' ) ); // dopo l'aggiornamento i certificati medici già caricati partono da soli
			add_action( 'admin_init', array( __NAMESPACE__ . '\\Install', 'maybe_realign_memberships' ) ); // la tessera segue l'anno fiscale: se la copia di sicurezza non era riuscita si riprova
		}
		foreach ( self::jobs() as $hook => $j ) {
			add_action( $hook, array( __NAMESPACE__ . '\\' . $j[2], $j[3] ) ); // il lavoro resta un'azione con il suo nome: la esegue il giro dei lavori (o un evento singolo)
		}
		// I lavori periodici non hanno un evento ciascuno: girano in fila dentro due soli eventi, ogni ora e ogni giorno (Jobs).
		add_action( self::JOBS_HOURLY, array( __NAMESPACE__ . '\\Jobs', 'hourly' ) );
		add_action( self::JOBS_DAILY, array( __NAMESPACE__ . '\\Jobs', 'daily' ) );
		if ( gmdate( 'Y-m-d' ) !== (string) BootState::get( self::JOBS_CHECKED ) ) { // la programmazione si controlla una volta al giorno (segnale già in memoria: nessuna domanda in più)
			Jobs::ensure_scheduled();
		}
	}

	/** Eventi di WP-Cron dei lavori periodici: uno ogni ora e uno ogni giorno. */
	const JOBS_HOURLY = 'asem_cron_hourly';
	const JOBS_DAILY  = 'asem_cron_daily';

	/** Segnale (BootState) con il giorno dell'ultimo controllo della programmazione dei due eventi. */
	const JOBS_CHECKED = 'jobs_checked';

	/**
	 * Lavori del plugin e delle estensioni presenti (filtro `asem_scheduled_jobs`): hook => [frequenza ('hourly', 'daily' o '' per i lotti
	 * che si programmano da soli), secondi al primo giro, classe, metodo, funzione che li richiede].
	 *
	 * @return array<string,array{0:string,1:int,2:string,3:string,4:string}>
	 */
	public static function jobs(): array {
		$jobs = array(
			'asem_release_holds'          => array( 'hourly', 900, 'Holds', 'release_expired', '' ),
			'asem_event_requests'         => array( 'hourly', 1500, 'EventRequests', 'run', '' ),
			'asem_send_reminders'         => array( 'daily', 600, 'Reminders', 'run', '' ),
			'asem_purge_backups'          => array( 'hourly', 1200, 'Backup', 'purge_old', '' ),
			'asem_purge_counters'         => array( 'hourly', 1100, 'Counters', 'purge', '' ), // contatori dei tentativi scaduti
			'asem_private_folder_check'   => array( 'daily', 3000, 'PrivateFolder', 'cron', '' ),
			'asem_db_engine_check'        => array( 'daily', 3600, 'DbEngine', 'cron', '' ),
			'asem_file_crypt_migrate'     => array( '', 0, 'FileMigration', 'cron', '' ), // lotti programmati per cifrare i file già caricati (si programmano da soli, uno alla volta)
			'asem_file_crypt_migrate_certs' => array( '', 0, 'FileMigration', 'certs_cron', '' ), // lotti dei soli certificati medici già caricati (partono da soli dopo l'aggiornamento)
			'asem_file_crypt_certs_check' => array( 'hourly', 1000, 'FileMigration', 'auto_certs', '' ), // se la cifratura dei certificati si è fermata o è andata persa, riparte
			'asem_purge_audit'            => array( 'daily', 1800, 'Audit', 'purge_old', '' ),
			'asem_purge_member_sessions'  => array( 'daily', 2400, 'MemberSession', 'purge_expired', '' ),
			'asem_purge_applications'     => array( 'daily', 3300, 'Applications', 'purge', '' ), // domande mai confermate (30 giorni) e respinte con quota pagata (90 giorni dalla risposta)
			'asem_long_job_tick'          => array( '', 0, 'LongJob', 'tick', '' ), // rete di sicurezza dei lavori lunghi: una tappa quando la pagina è chiusa (si programma da sola, un evento per volta)
			'asem_mail_retry'             => array( '', 0, 'MailRetry', 'run', '' ), // nuovo tentativo delle email singole rifiutate dal server di posta (si programma da solo, pochi minuti dopo)
			'asem_schema_upgrade'         => array( '', 0, 'Install', 'maybe_upgrade', '' ), // aggiornamento dello schema rimasto a metà o rimandato perché la richiesta era di un visitatore (si programma da solo)
		);
		$out = array();
		foreach ( (array) apply_filters( 'asem_scheduled_jobs', $jobs ) as $hook => $j ) { // un'estensione aggiunge i suoi lavori periodici
			if ( is_array( $j ) && isset( $j[0], $j[1], $j[2], $j[3], $j[4] ) && ( '' === $j[4] || Edition::installed( $j[4] ) ) ) {
				$out[ (string) $hook ] = $j;
			}
		}
		return $out;
	}

	/** Azione di admin-post => classe che la gestisce. */
	const ADMIN_POST = array(
		'asem_backup'          => 'Backup',
		'asem_backup_saved'    => 'Backup',
		'asem_backup_get'      => 'Backup',
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

	/**
	 * Azione chiesta a admin-post.php o admin-ajax.php (ripulita), oppure '' per ogni altra richiesta (pagine della bacheca, sito, cron).
	 * Si legge solo il nome: nonce e permessi li controlla il gestore dell'azione.
	 */
	public static function requested_action(): string {
		$script = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		if ( 'admin-post.php' !== $script && 'admin-ajax.php' !== $script && ! wp_doing_ajax() ) {
			return '';
		}
		return isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo il nome dell'azione, per sapere quale gestore registrare
	}

	/**
	 * Vanno registrati i gestori delle azioni? Solo se la richiesta (admin-post.php o admin-ajax.php) chiede un'azione del plugin (asem_…;
	 * per i moduli del sito asem_front_…). Le pagine della bacheca e del sito, il battito di WordPress e le azioni degli altri plugin non li caricano.
	 * In WP-CLI (prove, strumenti) sempre.
	 */
	public static function action_handlers_needed( string $prefix = 'asem_', ?string $action = null, ?bool $cli = null ): bool {
		if ( $cli ?? ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		$action = $action ?? self::requested_action();
		return '' !== $prefix && 0 === strpos( $action, $prefix );
	}

	/**
	 * Per i gestori che registrano le azioni da un elenco: va registrata questa? Con un'azione chiesta si registra solo quella;
	 * senza (WP-CLI, prove) tutte.
	 */
	public static function wants_action( string $action ): bool {
		$asked = self::requested_action();
		return '' === $asked || $asked === $action;
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

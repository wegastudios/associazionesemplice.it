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

	/** Ruolo WordPress dato ai nuovi utenti creati per i soci: nessun accesso all'area di amministrazione. */
	const ROLE_MEMBER = 'asem_member';

	private static $services = array();

	public static function init(): void {
		Install::maybe_upgrade();
		FiscalYears::maybe_open_current(); // il primo giorno dell'anno si apre il nuovo anno solare
		Access::register();      // capability meta: asem_notify_activity, asem_view_person...
		Gatekeeper::register(); // i soci restano fuori da wp-admin
		Rest\Api::register();    // associazionesemplice/v1
		Frontend\Front::init();  // shortcode, contenuti riservati, blocchi, widget
		if ( '' !== Edition::mismatch() ) { // Associazione Semplice Pro troppo diverso da questo Associazione Semplice: non si carica, e si dice cosa fare
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>Associazione Semplice Pro</strong> non è compatibile con questa versione di Associazione Semplice: le funzioni avanzate sono sospese. Aggiorna Associazione Semplice Pro e Associazione Semplice all\'ultima versione; il resto funziona e i dati sono al sicuro.</p></div>';
					}
				}
			);
		}
		MailLog::register();     // registro dell'esito delle email spedite
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

	/**
	 * Lavori periodici: hook => [frequenza, secondi al primo giro, classe, metodo, funzione che li richiede].
	 * Si registra il nome dell'hook e il richiamo con il nome della classe: la classe viene caricata solo quando WP-Cron esegue il lavoro.
	 */
	private static function schedule_jobs(): void {
		$jobs = array(
			'asem_release_holds'          => array( 'hourly', 900, 'Holds', 'release_expired', '' ),
			'asem_send_reminders'         => array( 'daily', 600, 'Reminders', 'run', '' ),
			'asem_purge_backups'          => array( 'hourly', 1200, 'Backup', 'purge_old', '' ),
		);
		$jobs = (array) apply_filters( 'asem_scheduled_jobs', $jobs ); // un'estensione aggiunge i suoi lavori periodici
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

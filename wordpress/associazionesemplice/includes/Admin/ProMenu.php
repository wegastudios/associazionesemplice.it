<?php
namespace AssociazioneSemplice\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Pagine di amministrazione, schede e permessi delle funzioni aggiuntive: si innestano nel menu di Associazione Semplice con i suoi filtri
 * (`asem_admin_groups`, `asem_admin_sections`, `asem_admin_only`, `asem_admin_parents`, `asem_admin_menu_pages`, `asem_admin_hidden_pages`).
 */
final class ProMenu {

	/** Voce di menu => [titolo, schede]. Le schede sono pagine nascoste dal menu, raggiungibili dalla barra in cima. */
	const GROUPS = array(
		'asem-people'     => array( 'Rubrica soci', array( 'asem-people' => 'Soci e ospiti', 'asem-messages' => 'Comunicazioni' ) ),
		'asem-activities' => array( 'Corsi ed eventi', array( 'asem-activities' => 'Elenco' ) ),
		'asem-resources'  => array( 'Risorse', array( 'asem-resources' => 'Risorse' ) ),
		'asem-credits'    => array( 'Crediti', array( 'asem-credits' => 'Crediti' ) ),
		'asem-tools'      => array( 'Strumenti', array( 'asem-tools' => 'Panoramica', 'asem-import' => 'Importa da Excel/CSV', 'asem-tec' => 'Da The Events Calendar', 'asem-wpai' => 'WP All Import', 'asem-exports' => 'Esporta', 'asem-calendar' => 'Calendari', 'asem-backup' => 'Copia di sicurezza', 'asem-tech' => 'Integrazioni' ) ),
		'asem-money'      => array( 'Cassa', array( 'asem-money' => 'Cassa', 'asem-ledger' => 'Prima nota', 'asem-income' => 'Nuovo incasso', 'asem-bookdesk' => 'Prenota', 'asem-group' => 'Cassa per più persone', 'asem-expense' => 'Nuova spesa', 'asem-refunds' => 'Rimborsi', 'asem-transfer' => 'Giroconto', 'asem-accounts' => 'Conti e fondi' ) ),
		'asem-accounting' => array( 'Contabilità', array( 'asem-accounting' => 'Anno solare', 'asem-years' => 'Anni solari', 'asem-budget' => 'Preventivo', 'asem-reports' => 'Report', 'asem-statement' => 'Rendiconto',  'asem-fivepm' => 'Adempimenti (5x1000)' ) ),
		'asem-book'       => array( 'Registri', array( 'asem-book' => 'Libro soci', 'asem-minutes' => 'Verbali', 'asem-polls' => 'Votazioni', 'asem-assemblies' => 'Assemblee', 'asem-inventory' => 'Inventario', 'asem-documents' => 'Documenti', 'asem-volunteers' => 'Assicurazioni', 'asem-attendance' => 'Presenze', 'asem-medical' => 'Certificati medici', 'asem-affiliations' => 'Affiliazioni' ) ),
		'asem-settings'   => array( 'Impostazioni', array( 'asem-entity' => 'Dati e fiscalità', 'asem-comms' => 'Privacy, regolamento e ricevute', 'asem-texts' => 'Testi e lingua', 'asem-settings' => 'Soci e quote', 'asem-look' => 'Aspetto', 'asem-bank' => 'Bonifico', 'asem-donate' => 'Donazioni', 'asem-card' => 'Tessera, QR e Wallet', 'asem-roles' => 'Ruoli e accessi', 'asem-payments' => 'Pagamenti online', 'asem-acct' => 'Opzioni contabili', 'asem-app' => 'App e notifiche', 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ) ),
	);

	/** Le Impostazioni sono in tre sezioni: ente e funzioni, tecniche, contabilità. Titolo => pagine (la prima è quella a cui porta la scheda). */
	const SECTIONS = array(
		'Ente e fiscalità' => array( 'asem-entity' => 'Dati e fiscalità', 'asem-comms' => 'Privacy, regolamento e ricevute', 'asem-texts' => 'Testi e lingua' ),
		'Soci e identità'  => array( 'asem-settings' => 'Soci e quote', 'asem-look' => 'Aspetto', 'asem-card' => 'Tessera, QR e Wallet', 'asem-roles' => 'Ruoli e accessi' ),
		'Cassa'            => array( 'asem-payments' => 'Pagamenti online', 'asem-bank' => 'Bonifico', 'asem-donate' => 'Donazioni' ),
		'Contabilità'      => array( 'asem-acct' => 'Opzioni contabili' ),
		'Comunicazioni'    => array( 'asem-app' => 'App e notifiche' ),
		'Sistema'          => array( 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ),
	);

	/** Pagine riservate agli amministratori (la segreteria non le vede). */
	const ADMIN_ONLY = array( 'asem-look', 'asem-pro', 'asem-bank', 'asem-donate', 'asem-settings', 'asem-payments', 'asem-card', 'asem-comms', 'asem-texts', 'asem-backup', 'asem-audit', 'asem-wpai', 'asem-years', 'asem-tech', 'asem-roles', 'asem-acct', 'asem-app', 'asem-limits', 'asem-wizard', 'asem-entity', 'asem-activity-delete', 'asem-booking-delete', 'asem-reset' );


	/** Pagine delle funzioni aggiuntive raggiungibili solo dai link: [slug, titolo, [classe, metodo]]. */
	const HIDDEN = array(
		array( 'asem-group', 'Cassa per più persone', array( GroupCashPage::class, 'render' ) ),
		array( 'asem-transfer', 'Giroconto', array( TransferPage::class, 'render' ) ),
		array( 'asem-accounts', 'Conti e fondi', array( AccountsPage::class, 'render' ) ),
		array( 'asem-reports', 'Report', array( ReportsPage::class, 'render' ) ),
		array( 'asem-statement', 'Rendiconto', array( ProRegisters::class, 'render_statement' ) ),
		array( 'asem-volunteers', 'Assicurazioni', array( ProRegisters::class, 'render_volunteers' ) ),
		array( 'asem-attendance', 'Presenze', array( ProRegisters::class, 'render_attendance' ) ),
		array( 'asem-app', 'App e notifiche', array( AppPage::class, 'render' ) ),
		array( 'asem-acct', 'Opzioni contabili', array( ProRegisters::class, 'render_accounting' ) ),
		array( 'asem-fivepm', '5x1000', array( FivePmPage::class, 'render' ) ),
		array( 'asem-payments', 'Pagamenti online', array( PaymentsPage::class, 'render' ) ),
		array( 'asem-messages', 'Comunicazioni', array( MessagesPage::class, 'render' ) ),
		array( 'asem-texts', 'Testi personalizzati', array( TextsPage::class, 'render' ) ),
		array( 'asem-resource', 'Scheda risorsa', array( ResourcesPage::class, 'render_detail' ) ),
		array( 'asem-medical', 'Certificati medici', array( CertificatesPage::class, 'render' ) ),
		array( 'asem-affiliations', 'Affiliazioni', array( AffiliationsPage::class, 'render' ) ),
		array( 'asem-budget', 'Bilancio preventivo', array( BudgetPage::class, 'render' ) ),
		array( 'asem-refunds', 'Rimborsi ai volontari', array( RefundsPage::class, 'render' ) ),
	);

	/** Titoli completi delle pagine che il gratuito chiama con un nome più corto. */
	const TITLES = array(
		'asem-card'    => 'Tessera, QR e Wallet',
		'asem-comms'   => 'Promemoria, privacy, regolamento e ricevute',
		'asem-entity'  => 'Dati dell\'ente e fiscalità',
	);

	public static function register(): void {
		add_filter( 'asem_admin_groups', array( __CLASS__, 'groups' ) );
		add_filter( 'asem_admin_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'asem_admin_only', array( __CLASS__, 'admin_only' ) );
		add_filter( 'asem_admin_parents', array( __CLASS__, 'parents' ) );
		add_filter( 'asem_admin_menu_pages', array( __CLASS__, 'menu_pages' ) );
		add_filter( 'asem_admin_hidden_pages', array( __CLASS__, 'hidden_pages' ) );
		add_action( 'asem_admin_init', array( __CLASS__, 'admin_init' ) );
		ProIntegrations::register();
		ProWallet::register();
		ProDashboard::register();
		ProPanels::register();
		ProWizard::register();
		add_filter( 'asem_card_page_title', function () {
			return 'Impostazioni: tessera, QR e Wallet';
		} );
	}

	/** Azioni delle pagine di risorse e crediti. */
	public static function admin_init(): void {
		if ( \AssociazioneSemplice\Edition::has( 'resources' ) ) {
			ResourcesActions::register();
		}
		if ( \AssociazioneSemplice\Edition::has( 'credits' ) ) {
			CreditsActions::register();
		}
	}

	public static function groups( array $groups ): array {
		return self::GROUPS + $groups;
	}

	public static function sections( array $sections ): array {
		return self::SECTIONS;
	}

	public static function admin_only( array $pages ): array {
		return array_merge( $pages, self::ADMIN_ONLY );
	}

	public static function parents( array $parents ): array {
		return $parents + array( 'asem-resource' => 'asem-resources' );
	}

	/** Voci di menu: Risorse e Crediti dopo Corsi ed eventi, Contabilità dopo Cassa. */
	public static function menu_pages( array $pages ): array {
		$out = array();
		foreach ( $pages as $p ) {
			$out[] = $p;
			if ( 'asem-activities' === $p[0] ) {
				$out[] = array( 'asem-resources', 'Risorse', array( ResourcesPage::class, 'render_list' ) );
				$out[] = array( 'asem-credits', 'Crediti', array( CreditsPage::class, 'render' ) );
			} elseif ( 'asem-money' === $p[0] ) {
				$out[] = array( 'asem-accounting', 'Contabilità', array( AccountingPage::class, 'render' ) );
			}
		}
		return $out;
	}

	public static function hidden_pages( array $pages ): array {
		foreach ( $pages as $k => $p ) {
			if ( isset( self::TITLES[ $p[0] ] ) ) {
				$pages[ $k ][1] = self::TITLES[ $p[0] ];
			}
		}
		return array_merge( $pages, self::HIDDEN );
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** Icona del menu: il simbolo ufficiale in bianco (SVG, WordPress lo adatta ai colori dell'amministrazione); se manca, l'icona di WordPress. */
	private static function menu_icon(): string {
		$file = ASEM_DIR . 'assets/brand/simbolo-bianco.svg';
		if ( ! is_readable( $file ) ) {
			return 'dashicons-groups';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file del plugin, non un indirizzo remoto
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'parent_file', array( __CLASS__, 'menu_parent' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Actions::register();
		YearsPage::register();
		WpDashboard::register();
		Exports::register();
		IncomePage::register_ajax();
		Dismiss::register();
		RegistersActions::register();
		BlockBActions::register();
		BlockFActions::register();
		TechActions::register();
		do_action( 'asem_admin_init' ); // le estensioni registrano qui le loro azioni
		\AssociazioneSemplice\Wizard::register();
	}

	/** Voce di menu => [titolo, schede]. Le schede sono pagine nascoste dal menu, raggiungibili dalla barra in cima. */
	const BASE_GROUPS = array(
		'asem-people'     => array( 'Rubrica soci', array( 'asem-people' => 'Soci e ospiti' ) ),
		'asem-activities' => array( 'Corsi ed eventi', array( 'asem-activities' => 'Elenco' ) ),
		'asem-tools'      => array( 'Strumenti', array( 'asem-tools' => 'Panoramica', 'asem-import' => 'Importa da Excel/CSV', 'asem-tec' => 'Da The Events Calendar', 'asem-wpai' => 'WP All Import', 'asem-exports' => 'Esporta', 'asem-calendar' => 'Calendari', 'asem-backup' => 'Copia di sicurezza', 'asem-tech' => 'Integrazioni' ) ),
		'asem-money'      => array( 'Cassa', array( 'asem-money' => 'Cassa', 'asem-ledger' => 'Prima nota', 'asem-income' => 'Nuovo incasso', 'asem-bookdesk' => 'Prenota', 'asem-expense' => 'Nuova spesa', 'asem-years' => 'Anni solari' ) ),
		'asem-book'       => array( 'Registri', array( 'asem-book' => 'Libro soci', 'asem-minutes' => 'Verbali', 'asem-polls' => 'Votazioni', 'asem-inventory' => 'Inventario', 'asem-documents' => 'Documenti' ) ),
		'asem-conventions' => array( 'Convenzioni', array( 'asem-conventions' => 'Convenzioni' ) ),
		'asem-settings'   => array( 'Impostazioni', array( 'asem-entity' => 'Dati dell\'ente', 'asem-comms' => 'Privacy e regolamento', 'asem-settings' => 'Soci e quote', 'asem-look' => 'Aspetto', 'asem-bank' => 'Bonifico', 'asem-donate' => 'Donazioni', 'asem-card' => 'Tessera e QR', 'asem-roles' => 'Ruoli e accessi', 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ) ),
	);

	/** Le Impostazioni sono in sezioni. Titolo => pagine (la prima è quella a cui porta la scheda). */
	const BASE_SECTIONS = array(
		'Ente'            => array( 'asem-entity' => 'Dati dell\'ente', 'asem-comms' => 'Privacy e regolamento' ),
		'Soci e identità' => array( 'asem-settings' => 'Soci e quote', 'asem-look' => 'Aspetto', 'asem-card' => 'Tessera e QR', 'asem-roles' => 'Ruoli e accessi' ),
		'Cassa'           => array( 'asem-bank' => 'Bonifico', 'asem-donate' => 'Donazioni' ),
		'Sistema'         => array( 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ),
	);

	/** Pagine riservate agli amministratori (la segreteria non le vede). */
	const BASE_ADMIN_ONLY = array( 'asem-years', 'asem-look', 'asem-pro', 'asem-bank', 'asem-donate', 'asem-settings', 'asem-card', 'asem-comms', 'asem-backup', 'asem-audit', 'asem-wpai', 'asem-tech', 'asem-roles', 'asem-limits', 'asem-wizard', 'asem-entity', 'asem-activity-delete', 'asem-booking-delete', 'asem-reset' );

	/** Pagine di dettaglio => voce di menu a cui appartengono. */
	const BASE_PARENTS = array( 'asem-person' => 'asem-people', 'asem-activity' => 'asem-activities', 'asem-activity-delete' => 'asem-activities', 'asem-booking-delete' => 'asem-activities' );

	/** Voci di menu e schede; un'estensione aggiunge le sue con il filtro `asem_admin_groups`. */
	public static function groups(): array {
		static $c = null;
		return $c ?? ( $c = (array) apply_filters( 'asem_admin_groups', self::BASE_GROUPS ) );
	}

	/** Sezioni delle Impostazioni (filtro `asem_admin_sections`). */
	public static function sections(): array {
		static $c = null;
		return $c ?? ( $c = (array) apply_filters( 'asem_admin_sections', self::BASE_SECTIONS ) );
	}

	/** Pagine riservate agli amministratori (filtro `asem_admin_only`). */
	public static function admin_only(): array {
		static $c = null;
		return $c ?? ( $c = array_values( array_unique( (array) apply_filters( 'asem_admin_only', self::BASE_ADMIN_ONLY ) ) ) );
	}

	/** Pagine di dettaglio => voce di menu (filtro `asem_admin_parents`). */
	public static function parents(): array {
		static $c = null;
		return $c ?? ( $c = (array) apply_filters( 'asem_admin_parents', self::BASE_PARENTS ) );
	}

	/** Voce di menu a cui appartiene una pagina ('asem' = Bacheca). */
	public static function menu_item_of( string $page ): string {
		if ( isset( self::parents()[ $page ] ) ) {
			return self::parents()[ $page ];
		}
		foreach ( self::groups() as $main => $g ) {
			if ( $main === $page || isset( $g[1][ $page ] ) ) {
				return $main;
			}
		}
		return 'asem';
	}

	public static function menu(): void {
		$cap = Plugin::CAP_OPS;
		add_menu_page( 'Associazione Semplice', 'Associazione Semplice', $cap, 'asem', self::guard( 'asem', array( DashboardPage::class, 'render' ) ), self::menu_icon(), 30 );
		// Schede e pagine di dettaglio: raggiungibili dai link e dalla barra in cima, non compaiono nel menu
		$hidden = array(
			array( 'asem-activity-delete', 'Elimina l\'evento', array( DeleteActivityPage::class, 'render' ) ),
			array( 'asem-booking-delete', 'Cancella l\'iscrizione', array( DeleteBookingPage::class, 'render' ) ),
			array( 'asem-reset', 'Azzeramento dati', array( ResetPage::class, 'render' ) ),
			array( 'asem-ledger', 'Prima nota', array( LedgerPage::class, 'render' ) ),
			array( 'asem-calendar', 'Calendario', array( CalendarPage::class, 'render' ) ),
			array( 'asem-income', 'Nuovo incasso', array( IncomePage::class, 'render' ) ),
			array( 'asem-bookdesk', 'Prenota', array( BookDeskPage::class, 'render' ) ),
			array( 'asem-years', 'Anni solari', array( YearsPage::class, 'render' ) ),
			array( 'asem-expense', 'Nuova spesa', array( ExpensePage::class, 'render' ) ),
			array( 'asem-minutes', 'Verbali', array( RegistersPage::class, 'render_minutes' ) ),
			array( 'asem-polls', 'Votazioni', array( PollsPage::class, 'render' ) ),
			array( 'asem-inventory', 'Inventario', array( InventoryPage::class, 'render' ) ),
			array( 'asem-documents', 'Documenti', array( DocumentsPage::class, 'render' ) ),
			array( 'asem-guide', 'Guida iniziale', array( GuidePage::class, 'render' ) ),
			array( 'asem-tech', 'Integrazioni', array( TechPage::class, 'render_integrations' ) ),
			array( 'asem-roles', 'Ruoli e accessi', array( TechPage::class, 'render_roles' ) ),
			array( 'asem-limits', 'Limiti e soglie', array( LimitsPage::class, 'render' ) ),
			array( 'asem-wizard', 'Configurazione guidata', array( WizardPage::class, 'render' ) ),
			array( 'asem-entity', 'Dati dell\'ente', array( EntityPage::class, 'render' ) ),
			array( 'asem-exports', 'Esportazioni', array( ToolsPage::class, 'render_exports' ) ),
			array( 'asem-card', 'Tessera e QR', array( CardPage::class, 'render' ) ),
			array( 'asem-look', 'Aspetto', array( AppearancePage::class, 'render' ) ),
			array( 'asem-bank', 'Bonifico bancario', array( BankPage::class, 'render' ) ),
			array( 'asem-donate', 'Donazioni con PayPal', array( DonatePage::class, 'render' ) ),
			array( 'asem-backup', 'Copia di sicurezza', array( BackupPage::class, 'render' ) ),
			array( 'asem-comms', 'Privacy e regolamento', array( CommsPage::class, 'render' ) ),
			array( 'asem-audit', 'Registro azioni', array( AuditPage::class, 'render' ) ),
			array( 'asem-person', 'Scheda persona', array( PeoplePage::class, 'render_edit' ) ),
			array( 'asem-activity', 'Scheda attività', array( ActivitiesPage::class, 'render_detail' ) ),
			array( 'asem-import', 'Importa soci', array( ImportPage::class, 'render' ) ),
			array( 'asem-tec', 'Importa da The Events Calendar', array( TribeImportPage::class, 'render' ) ),
			array( 'asem-wpai', 'Import con WP All Import', array( WpAiPage::class, 'render' ) ),
		);
		$visible = array(
			array( 'asem', 'Bacheca', array( DashboardPage::class, 'render' ) ),
			array( 'asem-people', 'Rubrica soci', array( PeoplePage::class, 'render_list' ) ),
			array( 'asem-activities', 'Corsi ed eventi', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'asem-money', 'Cassa', array( MoneyPage::class, 'render' ) ),
			array( 'asem-book', 'Registri', array( RegistersPage::class, 'render_book' ) ),
			array( 'asem-conventions', 'Convenzioni', array( ConventionsPage::class, 'render' ) ),
			array( 'asem-tools', 'Strumenti', array( ToolsPage::class, 'render' ) ),
			array( 'asem-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		$visible = (array) apply_filters( 'asem_admin_menu_pages', $visible ); // un'estensione aggiunge voci di menu: [slug, titolo, [classe, metodo]]
		$hidden  = (array) apply_filters( 'asem_admin_hidden_pages', $hidden ); // ... e pagine raggiungibili solo dai link
		if ( ProPage::needed() ) { // c'è qualcosa che questa installazione non ha: una pagina informativa, senza funzioni finte
			$visible[] = array( 'asem-pro', 'Scopri il Pro', array( ProPage::class, 'render' ) );
		}
		self::note_absent( $visible );
		self::note_absent( $hidden );
		foreach ( $visible as $s ) {
			if ( self::group_off( $s[0] ) ) {
				$s[1] = null; // la pagina resta raggiungibile (con l'avviso), ma non compare nel menu
			}
			add_submenu_page( null === $s[1] ? null : 'asem', (string) $s[1], (string) $s[1], in_array( $s[0], self::admin_only(), true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
		}
		foreach ( $hidden as $s ) {
			add_submenu_page( null, $s[1], $s[1], in_array( $s[0], self::admin_only(), true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
		}
	}

	/** Tiene evidenziata la voce giusta del menu quando si è in una scheda nascosta. */
	public static function menu_parent( $parent_file ) {
		global $plugin_page, $submenu_file;
		if ( is_string( $plugin_page ) && 0 === strpos( $plugin_page, 'asem' ) ) {
			$submenu_file = self::menu_item_of( $plugin_page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'asem';
		}
		return $parent_file;
	}

	/** Barra delle schede in cima alle pagine di un gruppo (Contabilità, Impostazioni, Rubrica). */
	public static function tabs( string $page ): string {
		$main = self::menu_item_of( $page );
		$tabs = self::groups()[ $main ][1] ?? array();
		if ( ! $tabs || isset( self::parents()[ $page ] ) ) {
			return '';
		}
		$off     = self::disabled_pages();
		$visible = function ( string $slug ) use ( $off ) {
			return ! in_array( $slug, $off, true ) && ( ! in_array( $slug, self::admin_only(), true ) || current_user_can( Plugin::CAP ) );
		};
		if ( 'asem-settings' === $main ) { // Impostazioni: prima la sezione, poi le schede della sezione
			$current = null;
			foreach ( self::sections() as $title => $pages ) {
				if ( isset( $pages[ $page ] ) ) {
					$current = $title;
				}
			}
			$html = '<nav class="nav-tab-wrapper asem-tabs">';
			foreach ( self::sections() as $title => $pages ) {
				$first = null;
				foreach ( array_keys( $pages ) as $slug ) {
					if ( $visible( $slug ) ) {
						$first = $slug;
						break;
					}
				}
				if ( null !== $first ) {
					$html .= '<a class="nav-tab' . ( $title === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $first ) ) . '">' . esc_html( $title ) . '</a>';
				}
			}
			$html .= '</nav>';
			if ( null !== $current ) {
				$links = array();
				foreach ( self::sections()[ $current ] as $slug => $label ) {
					if ( $visible( $slug ) ) {
						$links[] = '<li><a' . ( $slug === $page ? ' class="current"' : '' ) . ' href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a></li>';
					}
				}
				if ( count( $links ) > 1 ) {
					$html .= '<ul class="subsubsub asem-subtabs">' . implode( ' | ', $links ) . '</ul><br class="clear">';
				}
			}
			return $html;
		}
		$html = '<nav class="nav-tab-wrapper asem-tabs">';
		foreach ( $tabs as $slug => $label ) {
			if ( ! $visible( $slug ) ) {
				continue;
			}
			$html .= '<a class="nav-tab' . ( $slug === $page ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** @var string[] pagine registrate il cui contenuto non c'è in questa installazione (la classe che le disegna non esiste) */
	private static $absent = array();

	/** Segna le pagine di un elenco la cui classe non c'è: spariscono dalle schede e mostrano la spiegazione. */
	private static function note_absent( array $entries ): void {
		foreach ( $entries as $s ) {
			if ( is_array( $s[2] ) && ! class_exists( $s[2][0] ) && ! in_array( $s[0], self::$absent, true ) ) {
				self::$absent[] = $s[0];
			}
		}
	}

	/** Pagine che questa installazione non può mostrare: quelle delle funzioni non presenti. @return string[] */
	public static function absent_pages(): array {
		return array_values( array_unique( array_merge( \AssociazioneSemplice\Edition::missing_pages(), self::$absent ) ) );
	}

	/** Schede spente dalle impostazioni (report e rendiconto). @return string[] */
	public static function disabled_pages(): array {
		return array_merge( \AssociazioneSemplice\Modules::off_pages(), self::absent_pages() );
	}

	/** Voce di menu da nascondere: tutte le sue pagine sono spente. */
	private static function group_off( string $slug ): bool {
		$off = self::disabled_pages();
		if ( ! in_array( $slug, $off, true ) ) {
			return false;
		}
		foreach ( array_keys( self::groups()[ $slug ][1] ?? array() ) as $tab ) {
			if ( ! in_array( $tab, $off, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Una pagina di una parte spenta non mostra i suoi contenuti: dice come riaccenderla. */
	public static function guard( string $slug, $render ): callable { // $render può essere una classe che in questa edizione non c'è (la pagina mostra l'avviso)
		static $made = array(); // lo stesso oggetto per la stessa pagina: se è registrata due volte (menu principale e prima voce) WordPress la disegna una volta sola
		if ( isset( $made[ $slug ] ) ) {
			return $made[ $slug ];
		}
		return $made[ $slug ] = function () use ( $slug, $render ) {
			if ( is_array( $render ) && ! class_exists( $render[0] ) && ! in_array( $slug, self::$absent, true ) ) {
				self::$absent[] = $slug;
			}
			if ( in_array( $slug, self::absent_pages(), true ) ) {
				Ui::header( \AssociazioneSemplice\Edition::degraded() ? 'Funzione sospesa' : 'Funzione non inclusa' );
				echo \AssociazioneSemplice\Edition::missing_html(); // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::footer();
				return;
			}
			if ( in_array( $slug, self::disabled_pages(), true ) ) {
				Ui::header( 'Parte non in uso' );
				echo '<p>Questa parte del gestionale è spenta. I dati non sono stati toccati: si riaccende dalla <a href="' . esc_url( Ui::url( 'asem-wizard' ) ) . '">configurazione guidata</a>.</p>';
				Ui::footer();
				return;
			}
			$render();
		};
	}

	/** Se le schede dei report sono spente scrive l'avviso e dice di fermarsi. */
	public static function reports_off( string $title ): bool {
		if ( \AssociazioneSemplice\Settings::get( 'reports_enabled' ) ) {
			return false;
		}
		Ui::header( $title );
		echo '<p>Report e rendiconto sono spenti. Si riaccendono da <a href="' . esc_url( Ui::url( 'asem-acct' ) ) . '">Impostazioni → Contabilità</a>.</p>';
		Ui::footer();
		return true;
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'asem' ) ) {
			return;
		}
		wp_enqueue_style( 'asem-admin', ASEM_URL . 'assets/admin.css', array(), Plugin::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'asem-admin', ASEM_URL . 'assets/admin.js', array(), Plugin::asset_version( 'admin.js' ), true );
		$places = \AssociazioneSemplice\Places::script_url(); // suggerimenti di Google per i campi «Luogo», solo se l'ente li ha accesi
		if ( '' !== $places ) {
			wp_enqueue_script( 'asem-places', $places, array( 'asem-admin' ), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- script esterno di Google, la versione la gestisce Google
		}
	}
}

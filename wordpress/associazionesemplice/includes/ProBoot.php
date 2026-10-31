<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Avvio di Associazione Semplice Pro: dichiara al plugin base le funzioni che porta e si aggancia ai suoi punti di estensione (filtri e azioni `asem_*`).
 * Qui stanno anche la licenza (chiave, livelli, avvisi, aggiornamenti diretti) e il modo in cui il livello della licenza limita le funzioni.
 * Il plugin base non conosce né i nomi né le regole di queste funzioni.
 */
final class ProBoot {

	/** Funzione => file (dentro includes/) che la contiene. */
	const FEATURES = array(
		'vat'        => 'Admin/VatFields.php',     // IVA: campi nei moduli e impostazioni fiscali
		'levels'     => 'Admin/LevelsEditor.php',  // più livelli di socio, ognuno con la sua quota
		'payments'   => 'PaymentService.php',      // pagamenti online
		'funds'      => 'Admin/AccountsPage.php',  // conti multipli, giroconti, fondi e cassa per più persone
		'reports'    => 'Admin/ReportsPage.php',   // report di gestione (saldi, attività)
		'fiscal'     => 'Statement.php',           // contabilità e rendiconto per il commercialista (gli anni solari sono nel gratuito)
		'watch'      => 'BalanceWatch.php',        // Pro Fiscale: controllo di bilancio, avvisi, istantanee mensili, spese attribuite ai fondi, inizio dell'anno fiscale
		'fivepm'     => 'FivePerMille.php',        // 5 per mille
		'insurance'  => 'Insurance.php',           // assicurazioni e presenze
		'broadcasts' => 'Broadcasts.php',          // comunicazioni di massa
		'pwa'        => 'Pwa.php',                 // app installabile e notifiche
		'wallet'     => 'Wallet.php',              // tessera in Apple e Google Wallet
		'receipts'   => 'Receipts.php',            // ricevute PDF
		'door_sales' => 'DoorSales.php',           // incasso sul posto
		'resources'  => 'Resources.php',           // risorse prenotabili: campi, sale, posti auto, armadietti, posti barca
		'credits'    => 'Credits.php',             // crediti prepagati: acquisto, spesa nelle attività e nelle risorse, addebito dal vivo con QR
		'texts'      => 'TextsEngine.php',         // personalizzazione dei testi, lingue, tipo di ente e termini
		'medical'      => 'Certificates.php',      // certificato medico sportivo: scadenze, attività che lo richiedono, promemoria
		'affiliations' => 'Affiliations.php',      // affiliazioni agli enti (CONI/RAS, UISP, CSEN, ACSI, AICS) e tesseramento dei soci
		'budget'       => 'Budget.php',            // bilancio preventivo e confronto con il consuntivo della prima nota
		'refunds'      => 'Refunds.php',           // rimborsi ai volontari: richiesta, approvazione, pagamento e prima nota
		'assemblies'   => 'Assemblies.php',        // assemblee dei soci: convocazione, presenze, deleghe, numero legale e verbale
		'sms'          => 'Sms.php',               // SMS ai soci: comunicazioni, promemoria facoltativi, registro e nuovi tentativi
		'newsletter'   => 'Newsletter.php',        // newsletter: sincronizzazione dei soci con Brevo, Mailchimp e MailPoet, solo con il consenso
	);

	/** Pagine di amministrazione di ogni funzione: se la funzione non c'è, spariscono dal menu e dalle schede. */
	const PAGES = array(
		'payments'   => array( 'asem-payments' ),
		'funds'      => array( 'asem-accounts', 'asem-transfer', 'asem-group' ),
		'reports'    => array( 'asem-reports' ),
		'fiscal'     => array( 'asem-accounting', 'asem-statement', 'asem-acct' ),
		'watch'      => array( 'asem-watch' ),
		'fivepm'     => array( 'asem-fivepm' ),
		'insurance'  => array( 'asem-volunteers', 'asem-attendance' ),
		'broadcasts' => array( 'asem-messages' ),
		'pwa'        => array( 'asem-app' ),
		'texts'      => array( 'asem-texts' ),
		'resources'  => array( 'asem-resources', 'asem-resource', 'asem-res-report' ),
		'credits'    => array( 'asem-credits' ),
		'medical'      => array( 'asem-medical' ),
		'affiliations' => array( 'asem-affiliations' ),
		'budget'       => array( 'asem-budget' ),
		'refunds'      => array( 'asem-refunds' ),
		'assemblies'   => array( 'asem-assemblies' ),
	);

	/** Azioni di amministrazione di ogni funzione: senza la funzione si bloccano. */
	const ACTIONS = array(
		'payments'   => array( 'asem_save_payment_settings', 'asem_test_gateway', 'asem_satispay_connect', 'asem_check_payments', 'asem_payment_reviewed', 'asem_payment_refunded' ),
		'watch'      => array( 'asem_expense_fund_attach', 'asem_expense_fund_undo', 'asem_watch_snapshot', 'asem_watch_csv' ),
		'funds'      => array( 'asem_save_group_cash', 'asem_save_transfer', 'asem_add_account', 'asem_update_account', 'asem_close_account', 'asem_reopen_account', 'asem_fund_create', 'asem_fund_deposit', 'asem_fund_release', 'asem_fund_settle' ),
		'receipts'   => array( 'asem_receipt_email' ),
		'door_sales' => array( 'asem_walk_in' ),
		'broadcasts' => array( 'asem_broadcast_send', 'asem_broadcast_test', 'asem_broadcast_retry' ),
		'wallet'     => array( 'asem_save_wallet_apple', 'asem_save_wallet_google', 'asem_wallet_clear' ),
		'medical'      => array( 'asem_cert_add', 'asem_cert_delete', 'asem_save_cert_settings' ),
		'affiliations' => array( 'asem_aff_save', 'asem_aff_delete', 'asem_card_add', 'asem_card_update', 'asem_card_delete', 'asem_card_bulk', 'asem_save_aff_settings' ),
		'budget'       => array( 'asem_budget_save', 'asem_budget_lines', 'asem_budget_copy', 'asem_budget_approve', 'asem_budget_reopen', 'asem_budget_delete' ),
		'refunds'      => array( 'asem_refund_request', 'asem_refund_approve', 'asem_refund_reject', 'asem_refund_cancel', 'asem_refund_pay', 'asem_save_refund_settings', 'asem_front_refund', 'asem_front_refund_cancel' ),
		'assemblies'   => array( 'asem_assembly_save', 'asem_assembly_delete', 'asem_assembly_convene', 'asem_assembly_hold', 'asem_assembly_cancel', 'asem_assembly_draft', 'asem_assembly_attendance', 'asem_assembly_proxy_add', 'asem_assembly_proxy_revoke', 'asem_assembly_minutes', 'asem_front_proxy', 'asem_front_proxy_revoke' ),
		'sms'          => array( 'asem_save_sms', 'asem_test_sms', 'asem_sms_retry' ),
		'newsletter'   => array( 'asem_save_newsletter', 'asem_newsletter_lists', 'asem_test_newsletter', 'asem_newsletter_sync' ),
		'texts'      => array( 'asem_save_terms', 'asem_save_texts', 'asem_import_texts', 'asem_reset_texts', 'asem_add_text', 'asem_save_language', 'asem_import_language', 'asem_delete_language' ),
	);

	/** Funzioni fiscali: solo con la licenza Pro Full (IVA, 5 per mille, anni solari e rendiconto; più avanti la fatturazione elettronica). */
	const FISCAL = array( 'vat', 'fivepm', 'fiscal', 'watch' );

	/** Funzioni di contabilità: conti e fondi, report, ricevute. Mancano nel Pro Lite; ci sono nel Pro Full e nel vecchio livello contabile. */
	const ACCOUNTING = array( 'funds', 'reports', 'receipts', 'budget' );

	/** @var bool */
	private static $done = false;

	/** Si chiama una volta, dopo `Edition::add_dir()`, prima che Associazione Semplice si avvii. */
	public static function init(): void {
		if ( self::$done ) {
			return;
		}
		self::$done = true;
		Edition::register( self::FEATURES, self::PAGES, self::ACTIONS );

		// Come la licenza limita le funzioni.
		ProSchema::register(); // tabelle delle funzioni aggiuntive
		ProData::register(); // esportazione, anonimizzazione ed eliminazioni
		ProSettings::register();
		ProBlockC::register(); // certificati medici e affiliazioni agli enti
		ProBlockD::register(); // assemblee, bilancio preventivo e rimborsi ai volontari
		ProWatch::register(); // controllo di bilancio, spese attribuite ai fondi e inizio dell'anno fiscale (Pro Fiscale)
		ProBlockE::register(); // SMS, newsletter, Satispay e Klarna
		DocsPro::register();
		add_filter( 'asem_settings_defaults', array( ProSettings::class, 'defaults' ) );
		add_filter( 'asem_settings_clean', array( ProSettings::class, 'clean' ), 10, 2 );
		Admin\ProMenu::register(); // pagine, schede e permessi dell'amministrazione
		Frontend\ProViews::register(); // sezioni e viste del sito
		Frontend\ProActions::register(); // azioni dei moduli del sito
		add_filter( 'asem_features_suspended', array( __CLASS__, 'suspended' ) );
		add_filter( 'asem_feature_available', array( __CLASS__, 'available' ), 10, 2 );
		add_filter( 'asem_feature_unavailable_message', array( __CLASS__, 'message' ), 10, 2 );
		add_filter( 'asem_feature_unavailable_html', array( __CLASS__, 'html' ) );

		// Impostazioni, limiti e sezione «Licenza» nelle impostazioni.
		add_filter( 'asem_settings_defaults', array( __CLASS__, 'settings_defaults' ) );
		add_filter( 'asem_settings_clean', array( __CLASS__, 'settings_clean' ) );
		add_filter( 'asem_limits_defs', array( __CLASS__, 'limits' ) );
		add_action( 'asem_settings_page_end', array( Admin\LicensePanel::class, 'render' ) );

		// Azioni di amministrazione della licenza.
		add_action( 'plugins_loaded', array( __CLASS__, 'handlers' ), 6 );

		// Avvisi, aggiornamenti diretti e lavori periodici.
		add_action( 'plugins_loaded', array( __CLASS__, 'late' ), 11 );
		add_action( 'asem_license_check', array( License::class, 'cron' ) );
		add_action( 'asem_update_check', array( UpdateCheck::class, 'cron' ) );
		add_action( 'asem_license_resync', array( License::class, 'cron' ) );
	}

	public static function handlers(): void {
		Admin\Actions::add_handler( 'asem_save_license', array( __CLASS__, 'save_license' ) );
		Admin\Actions::add_handler( 'asem_license_check', array( __CLASS__, 'license_check' ) );
		Admin\ProActions::register();
		Admin\BlockCActions::register();
		Admin\BlockDProActions::register();
		Admin\BlockEActions::register();
		Admin\WatchActions::register();
	}

	/** Dopo l'avvio di Associazione Semplice: avvisi, aggiornatore, lavori periodici. */
	public static function late(): void {
		Admin\LicenseNotice::register();
		Admin\UpdateNotice::register();
		Updater::register();
		if ( Edition::has( 'pwa' ) && Settings::get( 'pwa_enabled' ) ) {
			Pwa::register(); // app installabile e notifiche
		}
		if ( Edition::installed( 'payments' ) && class_exists( 'WooCommerce', false ) ) {
			add_action( 'plugins_loaded', array( WooBridge::class, 'register' ), 20 ); // compatibilità con WooCommerce
		}
		ProBlockE::schedule();
		License::maybe_resync();
		if ( ! wp_next_scheduled( 'asem_license_check' ) ) {
			wp_schedule_event( time() + 1800, 'daily', 'asem_license_check' );
		}
		if ( ! wp_next_scheduled( 'asem_update_check' ) ) {
			wp_schedule_event( time() + 2400, 'hourly', 'asem_update_check' );
		}
	}

	/** Alla disattivazione si tolgono i lavori periodici. */
	public static function deactivate(): void {
		ProBlockE::unschedule();
		foreach ( array( 'asem_license_check', 'asem_update_check', 'asem_license_resync', 'asem_watch_month_end' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// ---------- Licenza e funzioni ----------

	/** Con la licenza non in regola le funzioni aggiunte sono sospese. */
	public static function suspended( $value ) {
		return $value || License::degraded();
	}

	/** Il livello della licenza esclude questa funzione? (Pro Full: nessuna esclusione; contabile: le fiscali; Pro Lite: fiscali e contabilità; gratuita: tutte.) */
	public static function plan_blocks( string $feature ): bool {
		switch ( License::plan() ) {
			case License::PLAN_FREE:
				return true;
			case License::PLAN_LITE:
				return in_array( $feature, self::FISCAL, true ) || in_array( $feature, self::ACCOUNTING, true );
			case License::PLAN_ACCOUNTING:
				return in_array( $feature, self::FISCAL, true );
		}
		return false;
	}

	public static function available( $ok, $feature ) {
		return $ok && ! License::degraded() && ! self::plan_blocks( (string) $feature );
	}

	/** Perché una funzione non è disponibile, a seconda della licenza. */
	public static function message( $default, $feature ) {
		$feature = (string) $feature;
		if ( '' !== $feature && Edition::installed( $feature ) && ! License::degraded() && self::plan_blocks( $feature ) ) {
			switch ( License::plan() ) {
				case License::PLAN_FREE:
					return LicensePolicy::STATUS_NOKEY === License::state()['status']
						? 'Questa funzione fa parte di Associazione Semplice Pro: inserisci la chiave di licenza in Impostazioni per usarla.'
						: 'Questa funzione fa parte di Associazione Semplice Pro (Lite o Full): la tua è la licenza gratuita.';
				case License::PLAN_LITE:
					return 'Questa funzione fa parte della licenza Associazione Semplice Pro Full: la tua licenza è Pro Lite, senza la parte contabile.';
			}
			return 'Questa funzione non è compresa nella tua licenza di Associazione Semplice Pro.';
		}
		if ( License::degraded() ) {
			return 'La licenza di Associazione Semplice Pro non è in regola: questa funzione è sospesa. Quando la licenza torna in regola, la funzione si riattiva da sola; intanto il resto funziona e i dati restano tutti.';
		}
		return $default;
	}

	/** Con la licenza scaduta, il collegamento per regolarizzarla al posto di quello che presenta il Pro. */
	public static function html( $html ) {
		if ( ! License::degraded() ) {
			return $html;
		}
		$out = '<p>' . esc_html( Edition::missing_message() ) . '</p>';
		$url = License::payment_url();
		return $out . ( $url
			? '<p><a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Regolarizza la licenza</a></p>'
			: '<p>Scrivi ad Associazione Semplice dall\'area riservata di associazionesemplice.it per regolarizzare la licenza, poi inseriscila in Impostazioni → Soci e quote → Licenza.</p>' );
	}

	// ---------- Impostazioni e limiti ----------

	public static function settings_defaults( $d ) {
		$d['license_key'] = ''; // chiave di licenza
		return $d;
	}

	public static function settings_clean( $clean ) {
		$clean['license_key'] = mb_substr( trim( (string) ( $clean['license_key'] ?? '' ) ), 0, 120 );
		return $clean;
	}

	/** Limiti della licenza e degli aggiornamenti (stessa forma di quelli di Associazione Semplice). */
	public static function limits( $d ) {
		$add = function ( string $key, string $group, string $label, string $unit, int $default, int $min, int $max, string $what, string $raise, string $lower ) use ( &$d ) {
			$d[ $key ] = array( 'group' => $group, 'label' => $label, 'unit' => $unit, 'default' => $default, 'min' => $min, 'max' => $max, 'what' => $what, 'raise' => $raise, 'lower' => $lower );
		};
		$add(
			'update_notice', 'comunicazioni', 'Avviso «ci sono aggiornamenti» di Associazione Semplice', 'flag', 1, 0, 1,
			'Con una chiave di licenza inserita (anche la gratuita), il plugin chiede al portale di Associazione Semplice qual è l\'ultima versione e mostra un avviso chiudibile alla segreteria e agli amministratori quando ne esiste una più recente. Non invia la chiave né dati del sito.',
			'Il sito fa una richiesta al portale di tanto in tanto (solo per leggere l\'ultima versione, senza inviare dati) e mostra l\'avviso: rischi trascurabili.',
			'Se lo disattivi il plugin non contatta più il portale per le versioni e non vedrai l\'avviso: dovrai controllare a mano nell\'area clienti, con il rischio di restare su versioni vecchie, senza correzioni di sicurezza.'
		);
		$add(
			'update_check_hours', 'comunicazioni', 'Ogni quante ore cercare nuove versioni', 'ore', 24, 12, 168,
			'Ogni quante ore il plugin chiede al portale l\'ultima versione disponibile (solo se l\'avviso è attivo).',
			'Con intervalli lunghi l\'avviso arriva con qualche giorno di ritardo.',
			'Intervalli brevi appesantiscono inutilmente il portale e il tuo sito senza vantaggi: le versioni escono di rado.'
		);
		$add(
			'portal_updates', 'comunicazioni', 'Aggiornamenti diretti di Associazione Semplice Pro (Bacheca → Aggiornamenti)', 'flag', 1, 0, 1,
			'Con il Pro installato, il plugin chiede al portale di Associazione Semplice se esiste una versione più nuova (al massimo ogni 12 ore) e la offre in Bacheca → Aggiornamenti, dove si installa con un clic. Il Pro manda la chiave di licenza e il dominio; il gratuito non manda niente di suo.',
			'Nessun rischio rilevante: una richiesta al portale ogni tanto.',
			'Se lo disattivi gli aggiornamenti vanno scaricati dall\'area clienti e caricati a mano.'
		);
		$add(
			'license_offline_days', 'accesso', 'Giorni senza controllo della licenza riuscito, prima di sospendere le funzioni Pro', 'giorni', 7, 1, 60,
			'La conferma della licenza vale circa due giorni; se il sito non riesce a rinnovarla (portale irraggiungibile, chiamate bloccate) si aspettano questi giorni in più prima di sospendere le funzioni Pro.',
			'Chi blocca le chiamate verso il portale tiene il piano più a lungo, anche se la licenza nel frattempo è scaduta o revocata. Alzalo se il tuo hosting ha spesso problemi di rete.',
			'Un problema di rete o del portale sospende le funzioni Pro (e l\'accesso dei soci) più in fretta.'
		);
		$add(
			'license_first_check_days', 'accesso', 'Giorni consentiti prima del primo controllo della licenza', 'giorni', 7, 1, 60,
			'Su un sito vero, finché il primo controllo della licenza non riesce, le funzioni sono tutte disponibili: questi sono i giorni di attesa prima di sospendere le funzioni Pro.',
			'Se i controlli automatici di WordPress sono disattivati le funzioni Pro restano disponibili senza licenza più a lungo.',
			'Se il primo controllo tarda (sito appena installato, controlli automatici di WordPress lenti) le funzioni Pro si sospendono prima del necessario.'
		);
		return $d;
	}

	// ---------- Azioni di amministrazione della licenza ----------

	public static function save_license( array $p ): array {
		$key = mb_substr( sanitize_text_field( $p['license_key'] ?? '' ), 0, 120 );
		Settings::update( array( 'license_key' => $key ) );
		$r = License::sync();
		return array( Admin\Ui::url( 'asem-settings' ), ( '' === $key ? 'Chiave rimossa. ' : 'Chiave salvata. ' ) . $r['message'] );
	}

	public static function license_check( array $p ): array {
		$r   = License::sync();
		$msg = $r['message'];
		if ( \AssociazioneSemplice\UpdateCheck::enabled() ) {
			\AssociazioneSemplice\UpdateCheck::fetch(); // «Controlla adesso» salta anche l'attesa tra un controllo di versione e l'altro
			$msg .= ' ' . \AssociazioneSemplice\UpdateCheck::summary();
		}
		return array( Admin\Ui::url( 'asem-settings' ), $msg );
	}

}

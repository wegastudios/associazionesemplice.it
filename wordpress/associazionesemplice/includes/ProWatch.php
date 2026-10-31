<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Controllo di bilancio, spese attribuite ai fondi e inizio dell'anno fiscale (funzioni di Associazione Semplice Pro Fiscale) nei punti di
 * estensione di Associazione Semplice: impostazioni, limiti, procedura guidata, Bacheca, lavori periodici, privacy e azioni di amministrazione.
 * Si avvia da {@see ProBoot::init()}.
 */
final class ProWatch {

	public static function register(): void {
		add_filter( 'asem_settings_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'asem_settings_clean', array( __CLASS__, 'clean' ) );
		add_filter( 'asem_limits_defs', array( __CLASS__, 'limits' ) );
		add_filter( 'asem_fiscal_start_month', array( __CLASS__, 'fiscal_start_month' ) );
		add_action( 'asem_entity_rows', array( __CLASS__, 'entity_row' ), 20 );
		add_filter( 'asem_entity_values', array( __CLASS__, 'entity_values' ), 20, 3 );
		add_action( 'asem_wizard_more_rows', array( __CLASS__, 'wizard_row' ) );
		add_filter( 'asem_wizard_values', array( __CLASS__, 'wizard_values' ), 10, 2 );
		add_action( 'asem_expense_recorded', array( ExpenseFunds::class, 'on_recorded' ), 10, 2 );
		add_action( 'asem_attachments_added', array( ExpenseFunds::class, 'on_attachments' ), 10, 1 );
		add_action( 'asem_expense_form_rows', array( __CLASS__, 'expense_form_row' ) );
		add_filter( 'asem_privacy_export', array( ExpenseFunds::class, 'privacy_export' ), 40, 2 );
		add_filter( 'asem_scheduled_jobs', array( __CLASS__, 'jobs' ) );
		add_filter( 'asem_admin_post_classes', array( __CLASS__, 'admin_post_classes' ) );
		add_action( 'asem_dashboard_alerts', array( Admin\WatchPage::class, 'alerts' ), 20 );
		add_action( 'asem_dashboard_cards', array( Admin\WatchPage::class, 'dashboard_cards' ) );
	}

	// ---------- Impostazioni ----------

	public static function defaults( array $d ): array {
		return $d + array( 'fiscal_start_month' => 1 ); // l'esercizio inizia a gennaio, cioè coincide con l'anno solare (comportamento di sempre)
	}

	public static function clean( array $clean ): array {
		$clean['fiscal_start_month'] = max( 1, min( 12, (int) ( $clean['fiscal_start_month'] ?? 1 ) ) );
		return $clean;
	}

	/** Il mese di inizio dell'anno fiscale vale solo con il livello Pro Fiscale: senza, gli anni restano solari. */
	public static function fiscal_start_month( $month ) {
		return Edition::has( 'watch' ) ? (int) Settings::get( 'fiscal_start_month' ) : (int) $month;
	}

	/** Quando cambia l'inizio dell'anno fiscale gli anni si ridisegnano: serve che esistano quelli che contengono i movimenti. */
	private static function month_changed( int $old ): void {
		if ( $old !== FiscalYears::start_month() ) {
			FiscalYears::seed();
		}
	}

	private static function month_select( array $s ): string {
		return '<select name="fiscal_start_month">' . Admin\Ui::options( Admin\Ui::MONTHS, (int) $s['fiscal_start_month'] ) . '</select>';
	}

	/** Scheda «Dati e fiscalità»: il mese in cui inizia l'anno fiscale, distinto dall'anno sociale. */
	public static function entity_row( array $s ): void {
		if ( ! Edition::has( 'watch' ) ) {
			return;
		}
		echo '<tr><th>L\'anno fiscale inizia a</th><td>' . self::month_select( $s ) . '<p class="description">È l\'esercizio della contabilità (prima nota, anni, report, preventivo, rendiconto): può iniziare in un mese diverso dall\'anno sociale. Di partenza è gennaio, cioè l\'anno solare. Le tessere dei soci restano per anno solare (scadono il 31 dicembre). Cambiarlo ridisegna gli anni della contabilità: fallo a inizio esercizio e controlla poi gli anni in «Anni solari».</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function entity_values( array $vals, array $p, bool $full ): array {
		if ( Edition::has( 'watch' ) && ( $full || array_key_exists( 'fiscal_start_month', $p ) ) ) {
			$old                        = FiscalYears::start_month();
			$vals['fiscal_start_month'] = max( 1, min( 12, (int) ( $p['fiscal_start_month'] ?? 1 ) ) );
			add_action( 'shutdown', function () use ( $old ) { // dopo il salvataggio
				self::month_changed( $old );
			} );
		}
		return $vals;
	}

	/** Procedura guidata: tra le domande secondarie, annidata in «Più dettagli». */
	public static function wizard_row( array $s ): void {
		if ( ! Edition::has( 'watch' ) ) {
			return;
		}
		echo '<tr><th>L\'anno fiscale inizia a</th><td>' . self::month_select( $s ) . ' <span class="description">(di solito gennaio, come l\'anno solare)</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function wizard_values( array $vals, array $p ): array {
		if ( Edition::has( 'watch' ) && isset( $p['fiscal_start_month'] ) ) {
			$old                        = FiscalYears::start_month();
			$vals['fiscal_start_month'] = max( 1, min( 12, (int) $p['fiscal_start_month'] ) );
			add_action( 'shutdown', function () use ( $old ) {
				self::month_changed( $old );
			} );
		}
		return $vals;
	}

	// ---------- Limiti ----------

	public static function limits( array $d ): array {
		$add = function ( string $key, string $label, string $unit, int $default, int $min, int $max, string $what, string $raise, string $lower ) use ( &$d ) {
			$d[ $key ] = array( 'group' => 'bilancio', 'label' => $label, 'unit' => $unit, 'default' => $default, 'min' => $min, 'max' => $max, 'what' => $what, 'raise' => $raise, 'lower' => $lower );
		};
		$add(
			'watch_alerts', 'Avvisi sulla tendenza del bilancio', 'flag', 1, 0, 1,
			'Nella Bacheca e nelle pagine di report e preventivo compare un avviso (chiudibile) quando, di questo passo, l\'anno fiscale si chiude con spese non coperte o con un utile troppo alto. Si ricalcola a ogni apertura, sui movimenti della prima nota.',
			'Acceso: è il comportamento consigliato. Gli avvisi sono stime fatte sui mesi già trascorsi e possono sbagliare se le entrate e le uscite sono concentrate in pochi periodi dell\'anno (quote a settembre, eventi estivi).',
			'Spento: nessun avviso, ma restano visibili il bilancio provvisorio e la tendenza. Una perdita o un utile fuori misura si scoprirebbero solo a fine anno.'
		);
		$add(
			'watch_over_pct', 'Spese non coperte: soglia', '%', 10, 1, 100,
			'Avviso quando la stima di fine anno vede le uscite superare le entrate di più di questa percentuale delle entrate (con i fondi accantonati contati come uscite).',
			'Più alta: l\'avviso scatta tardi e si accetta di chiudere l\'anno in perdita; il rischio è di accorgersene quando resta poco tempo per rimediare.',
			'Più bassa: l\'avviso scatta anche per scarti piccoli e normali nell\'anno, e a lungo andare si smette di leggerlo.'
		);
		$add(
			'watch_surplus_pct', 'Utile eccessivo: soglia', '%', 20, 1, 300,
			'Avviso quando la stima di fine anno vede le entrate superare le uscite di più di questa percentuale delle uscite: un utile molto più alto del necessario va destinato alle finalità dell\'ente.',
			'Più alta: si tollera un avanzo più grande prima di essere avvisati; un utile elevato accumulato negli anni è un rischio per gli enti non commerciali.',
			'Più bassa: l\'avviso scatta anche con avanzi prudenti e normali, utili come riserva.'
		);
		$add(
			'watch_min_months', 'Mesi trascorsi prima di fare la stima', 'mesi', 3, 1, 11,
			'Gli avvisi si calcolano solo quando dall\'inizio dell\'anno fiscale sono passati almeno questi mesi: all\'inizio dell\'anno poche registrazioni darebbero stime molto lontane dalla realtà.',
			'Più alto: gli avvisi arrivano più tardi ma sono più affidabili.',
			'Più basso: gli avvisi arrivano prima, ma nei primi mesi sono spesso falsi allarmi.'
		);
		$add(
			'watch_snapshots', 'Istantanea mensile del bilancio', 'flag', 1, 0, 1,
			'Un lavoro automatico salva, a ogni fine mese, il consuntivo dell\'anno contro il preventivo, e ne conserva lo storico mese per mese (consultabile ed esportabile). Non invia email.',
			'Acceso: ogni mese una riga in più in archivio, trascurabile.',
			'Spento: non si forma lo storico; il bilancio provvisorio resta visibile, ma ricalcolato ogni volta sui dati di oggi.'
		);
		$add(
			'watch_auto_attribute', 'Attribuzione automatica delle spese ai fondi', 'flag', 1, 0, 1,
			'Una spesa documentata (con scontrino o fattura allegati) e con un beneficiario viene attribuita da sola ai fondi di quel volontario, aperti o già liquidati, i più vecchi prima, finché i documenti coprono quanto accantonato. Resta annullabile e si può scegliere il fondo a mano.',
			'Acceso: l\'attribuzione segue i documenti allegati; una spesa registrata con il beneficiario sbagliato si attribuisce al fondo sbagliato finché non la annulli.',
			'Spento: le spese non si attribuiscono da sole e restano tutte nell\'elenco «spese documentate non rimborsate» finché non le colleghi a mano.'
		);
		$add(
			'watch_lookback_months', 'Quanti mesi guardare indietro negli elenchi dei fondi', 'mesi', 24, 3, 120,
			'L\'elenco delle spese documentate non rimborsate considera le spese degli ultimi mesi indicati.',
			'Più alto: l\'elenco comprende anche spese vecchie, più completo ma più lungo da leggere e più pesante da calcolare.',
			'Più basso: spese vecchie scompaiono dall\'elenco anche se non sono mai state attribuite.'
		);
		return $d;
	}

	// ---------- Lavori, download e modulo spese ----------

	public static function jobs( array $jobs ): array {
		return $jobs + array( 'asem_watch_month_end' => array( 'daily', 3000, 'BalanceWatch', 'snapshot_job', 'watch' ) );
	}

	public static function admin_post_classes( array $map ): array {
		return $map + array( 'asem_watch_csv' => 'Admin\\WatchExports' );
	}

	/** Nel modulo «Nuova spesa»: a quale fondo attribuirla. */
	public static function expense_form_row(): void {
		if ( ! Edition::has( 'watch' ) ) {
			return;
		}
		$opts = array( '' => 'Automatico (dai documenti allegati)', 'none' => 'Nessun fondo' );
		foreach ( ExpenseFunds::coverage() as $f ) {
			if ( $f['missing'] > 0 ) {
				$opts[ (string) $f['id'] ] = $f['name'] . ( null === $f['closed_at'] ? '' : ' (liquidato)' ) . ' — da documentare ' . Money::format( (int) $f['missing'] );
			}
		}
		echo '<tr><th>Fondo da coprire</th><td><select name="fund_choice">' . Admin\Ui::options( $opts, '' ) . '</select><p class="description">I fondi accantonano i pagamenti dei corsi per rimborsare il volontario. Se la spesa è sua e ha uno scontrino o una fattura allegati, di norma si attribuisce da sola ai suoi fondi, anche già liquidati. Qui si può scegliere a mano, o escludere la spesa.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

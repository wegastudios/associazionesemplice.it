<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\BalanceWatch;
use AssociazioneSemplice\BalanceWatchRules;
use AssociazioneSemplice\BudgetRules;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpenseFunds;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Contabilità → Bilancio provvisorio: l'anno fiscale in corso aggiornato al mese concluso, il mese in corso, gli avvisi sulla tendenza e lo
 * storico mensile; più i due elenchi dei fondi (spese documentate non rimborsate, fondi accantonati non documentati).
 */
final class WatchPage {

	private static function date( string $d ): string {
		return ( new \DateTimeImmutable( $d ) )->format( 'd/m/Y' );
	}

	private static function m( int $cents ): string {
		return Ui::money( $cents );
	}

	// ---------- Avvisi e schede ----------

	/** Righe di avviso chiudibili in Bacheca. */
	public static function alerts(): void {
		if ( ! Edition::has( 'watch' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		$s = BalanceWatch::status();
		foreach ( $s['alerts'] as $a ) {
			echo Dismiss::html( 'watch-' . $a['kind'] . '-' . $s['year'] . '-' . substr( $s['today'], 0, 7 ), 'warning', '<strong>' . esc_html( BalanceWatchRules::title( $a['kind'] ) ) . '.</strong> ' . esc_html( BalanceWatchRules::message( $a ) ) . ' <a href="' . esc_url( Ui::url( 'asem-watch' ) ) . '">Apri il bilancio provvisorio</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
	}

	/** Schede della Bacheca: bilancio provvisorio e fondi da documentare. */
	public static function dashboard_cards(): void {
		if ( ! Edition::has( 'watch' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		echo '<div class="asem-card">';
		self::box( BalanceWatch::status(), true );
		echo '</div>';
		self::funds_lists( true );
	}

	/** Il bilancio dell'anno in corso con gli avvisi, per le pagine di report, preventivo e contabilità. */
	public static function box_html(): void {
		if ( ! Edition::has( 'watch' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		\AssociazioneSemplice\YearEndCheck::show();
		$s = BalanceWatch::status();
		foreach ( $s['alerts'] as $a ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( BalanceWatchRules::title( $a['kind'] ) ) . '.</strong> ' . esc_html( BalanceWatchRules::message( $a ) ) . '</p></div>';
		}
		echo '<div class="asem-card">';
		self::box( $s, true );
		echo '</div>';
	}

	// ---------- Il bilancio ----------

	/** Bilancio provvisorio + mese in corso + tendenza. */
	private static function box( array $s, bool $link ): void {
		echo '<h2>Bilancio dell\'anno fiscale ' . esc_html( $s['label'] ) . '</h2>';
		echo '<p class="description">Dal ' . esc_html( self::date( $s['from'] ) ) . ' al ' . esc_html( self::date( $s['to'] ) ) . '. Prima nota, senza giroconti né movimenti annullati; i fondi accantonati per i rimborsi contano come uscite.</p>';
		if ( $s['provisional'] ) {
			$p = $s['provisional'];
			echo '<h3>Provvisorio, aggiornato al ' . esc_html( self::date( $s['prov_to'] ) ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th></th><th>Consuntivo</th>' . ( $s['budget'] ? '<th>Preventivo (' . (int) $s['completed_months'] . '/12 dell\'anno)</th>' : '' ) . '</tr></thead><tbody>';
			echo '<tr><td>Entrate</td><td>' . self::m( $p['income'] ) . '</td>' . ( $s['budget'] ? '<td>' . self::m( $s['budget']['share_income'] ) . '</td>' : '' ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td>Uscite</td><td>' . self::m( $p['expense'] ) . '</td>' . ( $s['budget'] ? '<td>' . self::m( $s['budget']['share_expense'] ) . '</td>' : '' ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<tr><td><strong>Risultato</strong></td><td><strong>' . self::m( $p['result'] ) . '</strong></td>' . ( $s['budget'] ? '<td><strong>' . self::m( $s['budget']['share_income'] - $s['budget']['share_expense'] ) . '</strong></td>' : '' ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tbody></table>';
			if ( ! $s['budget'] && Edition::has( 'budget' ) ) {
				echo '<p class="description">Non c\'è un preventivo per quest\'anno: <a href="' . esc_url( Ui::url( 'asem-budget', array( 'new' => 1 ) ) ) . '">crealo</a> per mettere a confronto previsto e speso.</p>';
			}
		} else {
			echo '<p class="description">Il primo mese dell\'anno fiscale non è ancora concluso: il bilancio provvisorio compare a fine mese.</p>';
		}
		$mo = $s['month'];
		echo '<h3>Mese in corso (dal ' . esc_html( self::date( $mo['from'] ) ) . ' a oggi)</h3><p>Entrate ' . self::m( $mo['income'] ) . ' · uscite ' . self::m( $mo['expense'] ) . ' · <strong>risultato ' . self::m( $mo['result'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $s['enough'] && $s['projection'] ) {
			echo '<h3>Stima a fine anno</h3><p>Di questo passo: entrate ' . self::m( $s['projection']['income'] ) . ' · uscite ' . self::m( $s['projection']['expense'] ) . ' · <strong>risultato ' . self::m( $s['projection']['income'] - $s['projection']['expense'] ) . '</strong>. <span class="description">Stima proporzionale ai giorni trascorsi; si ricalcola a ogni apertura.</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			$n = Limits::get( 'watch_min_months' );
			echo '<p class="description">La stima di fine anno compare dopo ' . (int) $n . ( 1 === $n ? ' mese' : ' mesi' ) . ' dall\'inizio dell\'anno fiscale (si cambia da Impostazioni → Limiti e soglie).</p>';
		}
		if ( $link ) {
			echo '<p><a href="' . esc_url( Ui::url( 'asem-watch' ) ) . '">Dettaglio e storico mensile →</a></p>';
		}
	}

	public static function render(): void {
		Ui::header( 'Bilancio provvisorio' );
		\AssociazioneSemplice\YearEndCheck::show();
		$s = BalanceWatch::status();
		foreach ( $s['alerts'] as $a ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( BalanceWatchRules::title( $a['kind'] ) ) . '.</strong> ' . esc_html( BalanceWatchRules::message( $a ) ) . '</p></div>';
		}
		echo '<div class="asem-card">';
		self::box( $s, false );
		echo '</div>';

		if ( ! empty( $s['provisional_rows'] ) ) {
			$labels = BudgetRules::status_labels();
			echo '<h2>Voce per voce, contro il preventivo</h2><table class="widefat striped"><thead><tr><th>Voce</th><th>Tipo</th><th>Previsto (mesi conclusi)</th><th>Consuntivo</th><th>Differenza a favore</th><th>Stato</th></tr></thead><tbody>';
			foreach ( $s['provisional_rows'] as $r ) {
				echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . ( 'income' === $r['type'] ? 'Entrata' : 'Uscita' ) . '</td><td>' . self::m( $r['planned'] ) . '</td><td>' . self::m( $r['actual'] ) . '</td><td>' . self::m( $r['diff'] ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}

		// Quadratura rimborsi e spese per persona
		$ry = Ui::get_int( 'ry', $s['year'] );
		echo '<h2 id="asem-recon">Rimborsi e spese documentate per persona</h2><p class="description">Chi ha avuto rimborsi dai fondi deve trovarsi con le spese sostenute: a fine mese è bene, a fine anno è necessario. Differenza = rimborsato − documentato: se è positiva i rimborsi non hanno documenti a copertura (da regolarizzare), se è negativa ci sono spese documentate non ancora rimborsate.</p>';
		echo '<form method="get"><input type="hidden" name="page" value="asem-watch"><p><label>Anno fiscale <select name="ry" data-asem-autosubmit>';
		for ( $y = $s['year']; $y >= $s['year'] - 5; $y-- ) {
			echo '<option value="' . (int) $y . '"' . selected( $ry, $y, false ) . '>' . esc_html( \AssociazioneSemplice\FiscalYears::label( $y ) ) . '</option>';
		}
		echo '</select></label> <a class="button" href="' . esc_url( WatchExports::url( 'recon', array( 'year' => $ry ) ) ) . '">Esporta CSV</a> <a class="button" href="' . esc_url( WatchExports::url( 'recon_pdf', array( 'year' => $ry ) ) ) . '">Scarica PDF per il rendiconto</a></p></form>';
		$recon = \AssociazioneSemplice\FundReconciliation::rows( $ry );
		echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>Rimborsato dai fondi</th><th>Spese documentate attribuite</th><th>Differenza</th><th>Situazione</th></tr></thead><tbody>';
		if ( ! $recon ) {
			echo '<tr><td colspan="5">Nessun rimborso né spesa documentata in questo anno fiscale.</td></tr>';
		}
		foreach ( $recon as $r ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $r['person_id'] ) ) ) . '">' . esc_html( $r['name'] ) . '</a></td><td>' . self::m( $r['reimbursed'] ) . '</td><td>' . self::m( $r['documented'] ) . '</td><td><strong>' . self::m( $r['diff'] ) . '</strong></td><td>' . esc_html( \AssociazioneSemplice\FundReconciliation::status_label( $r['status'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		// Storico mensile
		$years = BalanceWatch::snapshot_years();
		$year  = Ui::get_int( 'year', $years ? $years[0] : $s['year'] );
		echo '<h2>Storico mensile</h2><p class="description">A ogni fine mese viene salvata una foto del consuntivo contro il preventivo: non cambia più, anche se poi si correggono dei movimenti. Le foto «ricostruite» sono state fatte dopo la prima settimana del mese successivo.</p>';
		if ( $years ) {
			echo '<form method="get"><input type="hidden" name="page" value="asem-watch"><p><label>Anno fiscale <select name="year" data-asem-autosubmit>';
			foreach ( $years as $y ) {
				echo '<option value="' . (int) $y . '"' . selected( $year, $y, false ) . '>' . esc_html( (string) $y ) . '</option>';
			}
			echo '</select></label> <a class="button" href="' . esc_url( WatchExports::url( 'snapshots', array( 'year' => $year ) ) ) . '">Esporta CSV</a> <a class="button" href="' . esc_url( WatchExports::url( 'snapshots' ) ) . '">Esporta tutto</a></p></form>';
		}
		$snaps = $years ? BalanceWatch::snapshots( $year ) : array();
		echo '<table class="widefat striped"><thead><tr><th>Mese</th><th>Entrate del mese</th><th>Uscite del mese</th><th>Entrate dall\'inizio</th><th>Uscite dall\'inizio</th><th>Risultato dall\'inizio</th><th>Preventivo (entrate / uscite)</th><th>Origine</th></tr></thead><tbody>';
		if ( ! $snaps ) {
			echo '<tr><td colspan="8">Nessuna foto salvata: la prima arriva a fine mese.</td></tr>';
		}
		foreach ( $snaps as $x ) {
			$plan = null === $x['planned_income_cents'] ? '—' : self::m( (int) $x['planned_income_cents'] ) . ' / ' . self::m( (int) $x['planned_expense_cents'] );
			echo '<tr><td>' . esc_html( (string) $x['month'] ) . '</td><td>' . self::m( (int) $x['month_income_cents'] ) . '</td><td>' . self::m( (int) $x['month_expense_cents'] ) . '</td><td>' . self::m( (int) $x['ytd_income_cents'] ) . '</td><td>' . self::m( (int) $x['ytd_expense_cents'] ) . '</td><td><strong>' . self::m( (int) $x['ytd_income_cents'] - (int) $x['ytd_expense_cents'] ) . '</strong></td><td>' . $plan . '</td><td>' . ( 'monthly' === $x['source'] ? 'fine mese' : 'ricostruita' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::form_open( 'asem_watch_snapshot', Ui::url( 'asem-watch' ), false, 'asem-inline' );
		echo '<p><button class="button">Salva ora le foto dei mesi conclusi mancanti</button> <span class="description">Succede da sé ogni giorno; il pulsante serve se è appena stato acceso il salvataggio o dopo un blocco del sito.</span></p>';
		Ui::form_close();
		Ui::footer();
	}

	// ---------- Fondi: i due elenchi ----------

	/** Spese documentate non rimborsate e fondi accantonati non documentati (versione corta nella Bacheca). */
	public static function funds_lists( bool $compact = false ): void {
		if ( ! Edition::has( 'watch' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		$spese = ExpenseFunds::uncovered_expenses();
		$fondi = ExpenseFunds::undocumented_funds();
		$back  = Ui::url( $compact ? 'asem' : 'asem-accounts' );
		if ( $compact && ! $spese && ! $fondi ) {
			return;
		}
		$limit = $compact ? 5 : 500;
		echo '<div class="asem-card"><h2>Spese documentate non rimborsate' . ( $spese ? ' (' . count( $spese ) . ')' : '' ) . '</h2>';
		echo '<p class="description">Spese con scontrino o fattura allegati e un beneficiario, che non risultano ancora attribuite a nessun fondo (o solo in parte).' . ( $compact ? '' : ' <a href="' . esc_url( WatchExports::url( 'uncovered' ) ) . '">Esporta CSV</a>' ) . '</p>';
		if ( ! $spese ) {
			echo '<p>Nessuna.</p>';
		} else {
			$cands = array_values(
				array_filter(
					ExpenseFunds::coverage(),
					function ( $f ) {
						return $f['missing'] > 0;
					}
				)
			);
			echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Beneficiario</th><th>Descrizione</th><th>Importo</th><th>Non attribuito</th>' . ( $compact ? '' : '<th></th>' ) . '</tr></thead><tbody>';
			foreach ( array_slice( $spese, 0, $limit ) as $r ) {
				$p = ! empty( $r['person_id'] ) ? Plugin::people()->get( (int) $r['person_id'] ) : null;
				echo '<tr><td>' . esc_html( self::date( $r['tx_date'] ) ) . '</td><td>' . esc_html( $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '' ) . '</td><td>' . esc_html( (string) $r['description'] ) . '</td><td>' . self::m( (int) $r['amount_cents'] ) . '</td><td><strong>' . self::m( (int) $r['remaining'] ) . '</strong></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( ! $compact ) {
					echo '<td>';
					if ( $cands ) {
						$opts = array();
						foreach ( $cands as $f ) {
							$opts[ (string) $f['id'] ] = $f['name'] . ( null === $f['closed_at'] ? '' : ' (liquidato)' ) . ' — da documentare ' . Money::format( (int) $f['missing'] );
						}
						Ui::form_open( 'asem_expense_fund_attach', $back, false, 'asem-inline' );
						echo Ui::hidden( 'tx_id', (int) $r['id'] ) . '<select name="fund_id">' . Ui::options( $opts, null, '— scegli il fondo —' ) . '</select> <button class="button button-small">Attribuisci</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
						Ui::form_close();
					}
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>' . ( $compact && count( $spese ) > $limit ? '<p><a href="' . esc_url( Ui::url( 'asem-accounts' ) ) . '">Vedi tutte →</a></p>' : '' );
		}
		echo '</div>';

		echo '<div class="asem-card"><h2>Fondi accantonati non documentati' . ( $fondi ? ' (' . count( $fondi ) . ')' : '' ) . '</h2>';
		echo '<p class="description">Fondi (aperti o già liquidati) per cui le spese documentate non coprono ancora quanto accantonato.' . ( $compact ? '' : ' <a href="' . esc_url( WatchExports::url( 'undocumented' ) ) . '">Esporta CSV</a>' ) . '</p>';
		if ( ! $fondi ) {
			echo '<p>Nessuno.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Fondo</th><th>Stato</th><th>Accantonato</th><th>Documentato</th><th>Da documentare</th></tr></thead><tbody>';
			foreach ( array_slice( $fondi, 0, $limit ) as $f ) {
				echo '<tr><td>' . esc_html( $f['name'] ) . '</td><td>' . ( null === $f['closed_at'] ? 'aperto' : 'liquidato' ) . '</td><td>' . self::m( (int) $f['accrued'] ) . '</td><td>' . self::m( (int) $f['documented'] ) . '</td><td><strong>' . self::m( (int) $f['missing'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>' . ( $compact && count( $fondi ) > $limit ? '<p><a href="' . esc_url( Ui::url( 'asem-accounts' ) ) . '">Vedi tutti →</a></p>' : '' );
		}
		echo '</div>';

		if ( ! $compact ) {
			$recent = ExpenseFunds::recent( 20 );
			echo '<div class="asem-card"><h2>Attribuzioni recenti</h2><p class="description">Le spese attribuite ai fondi, da sole o a mano. Annullando, la spesa torna nell\'elenco sopra e non viene riattribuita da sola.</p>';
			if ( ! $recent ) {
				echo '<p>Nessuna.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Descrizione</th><th>Importo</th><th>Attribuita a</th><th></th></tr></thead><tbody>';
				foreach ( $recent as $r ) {
					$parts = array();
					foreach ( $r['parts'] as $x ) {
						$parts[] = $x['fund'] . ' (' . Money::format( (int) $x['cents'] ) . ', ' . ( ExpenseFunds::AUTO === $x['source'] ? 'automatica' : 'a mano' ) . ')';
					}
					echo '<tr><td>' . esc_html( self::date( $r['tx_date'] ) ) . '</td><td>' . esc_html( (string) $r['description'] ) . '</td><td>' . self::m( (int) $r['amount_cents'] ) . '</td><td>' . esc_html( implode( ' + ', $parts ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_open( 'asem_expense_fund_undo', $back, false, 'asem-inline' );
					echo Ui::hidden( 'tx_id', (int) $r['tx_id'] ) . '<button class="button button-small" data-confirm="Annullare l\'attribuzione di questa spesa ai fondi?">Annulla</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}
	}
}

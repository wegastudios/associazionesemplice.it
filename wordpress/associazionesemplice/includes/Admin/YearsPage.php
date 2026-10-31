<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Anni solari: si creano, si chiudono e si riaprono. Si incassa e si spende solo negli anni aperti. */
final class YearsPage {

	public static function register(): void {
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alert' ) );
	}

	/** Da settembre ricorda di aprire il nuovo anno solare: chiudibile (ricompare a inizio mese), a dicembre resta sempre visibile. */
	public static function alert(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$today = \AssociazioneSemplice\Db::today();
		$year  = FiscalYears::current();
		$month = ( (int) substr( $today, 5, 2 ) - FiscalYears::start_month() + 12 ) % 12 + 1; // posizione del mese nell'anno: 1 = il primo, 12 = l'ultimo
		$next  = $year + 1;
		if ( $month < 9 || FiscalYears::get( $next ) ) {
			return;
		}
		$msg = '<strong>Apri l\'anno solare ' . (int) $next . '.</strong> Finché non esiste, le quote dei soci si registrano solo per il ' . (int) $year . ' e non puoi incassare in anticipo per il ' . (int) $next . '. '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-years' ) ) . '">Apri il ' . (int) $next . '</a>';
		if ( 12 === $month ) { // a dicembre non si può chiudere
			echo wp_kses( '<div class="notice notice-warning inline"><p>' . $msg . '</p></div>', Html::allowed() );
			return;
		}
		echo wp_kses( Dismiss::html( 'next-year-' . substr( $today, 0, 7 ), 'warning', $msg, 31 ), Html::allowed() );
	}

	public static function render(): void {
		$back   = Ui::url( 'asem-years' );
		$years  = FiscalYears::all();
		$latest = FiscalYears::latest();
		$totals = Plugin::funds()->yearly()['totals'];
		Ui::header( 'Anni solari' );
		echo '<p class="description">Si può incassare, spendere e girare denaro solo negli anni solari <strong>aperti</strong>. Un anno chiuso non accetta più movimenti finché non lo riapri. '
			. 'L\'anno in corso non si chiude (e il primo giorno di ogni anno il nuovo anno si apre da solo). Un anno finito non si chiude se ha fondi accantonati non ancora rimborsati. La quota associativa va all\'anno più recente tra quelli creati: se non è quello in corso, l\'anno in corso è in omaggio per chi si iscrive per la prima volta.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Anno</th><th>Stato</th><th>Movimenti</th><th>Fondi da rimborsare</th><th></th></tr></thead><tbody>';
		foreach ( $years as $y ) {
			$year     = (int) $y['year'];
			$open     = 'open' === $y['status'];
			$unsettled = (int) ( $totals[ $year ]['unsettled'] ?? 0 );
			echo wp_kses( '<tr><td><strong>' . $year . '</strong></td><td>' . ( $open ? '<span class="asem-ok">aperto</span>' : '<strong>chiuso</strong>' . ( $y['closed_at'] ? ' <span class="description">il ' . esc_html( mysql2date( 'd/m/Y', $y['closed_at'] ) ) . '</span>' : '' ) ) . '</td>'
				. '<td>' . (int) FiscalYears::movements( $year ) . '</td><td>' . ( $unsettled > 0 ? '<strong class="asem-warn">' . Ui::money( $unsettled ) . '</strong>' : Ui::money( 0 ) ) . '</td><td>', Html::allowed() );
			if ( $open && $year >= FiscalYears::current() ) {
				echo '<span class="description">' . ( $year === FiscalYears::current() ? 'anno in corso: si chiude dopo la fine dell\'anno' : 'anno non ancora iniziato' ) . '</span>';
			} elseif ( $open ) {
				Ui::form_open( 'asem_close_year', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'year', $year ) . '<button class="button" data-confirm="Chiudere l\'anno ' . $year . '? Non accetterà più incassi né spese (si può riaprire).">Chiudi l\'anno</button>', Html::allowed() );
				Ui::form_close();
			} else {
				Ui::form_open( 'asem_reopen_year', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'year', $year ) . '<button class="button">Riapri l\'anno</button>', Html::allowed() );
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="asem-card"><h2>Crea anno solare</h2>';
		Ui::form_open( 'asem_create_year', $back );
		echo '<p><input type="number" name="year" min="2000" max="2100" value="' . esc_attr( (string) ( $latest ? $latest + 1 : (int) current_time( 'Y' ) ) ) . '" required> <button class="button button-primary">Crea l\'anno</button></p>'
			. '<p class="description">Crea in anticipo l\'anno che inizia (ad esempio a novembre l\'anno dopo): da quel momento i nuovi soci pagano la quota di quell\'anno e hanno in omaggio quello in corso.</p>';
		Ui::form_close();
		echo '</div>';

		do_action( 'asem_years_page_after' ); // un'estensione aggiunge i fondi da rimborsare per anno
		Ui::footer();
	}
}

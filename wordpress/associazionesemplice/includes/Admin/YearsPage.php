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

	/** Dal nono mese dell'anno fiscale ricorda di aprire il nuovo anno: chiudibile (ricompare a inizio mese), all'ultimo mese resta sempre visibile. */
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
		$msg = '<strong>Apri l\'anno fiscale ' . esc_html( FiscalYears::label( $next ) ) . '.</strong> Finché non esiste, non puoi registrare incassi e spese del ' . esc_html( FiscalYears::label( $next ) ) . '. '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-years' ) ) . '">Apri il ' . esc_html( FiscalYears::label( $next ) ) . '</a>';
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
		$last   = FiscalYears::last_closed();
		Ui::header( 'Anni fiscali' );
		echo '<p class="description">Si può incassare, spendere e girare denaro solo negli anni fiscali <strong>aperti</strong>. Un anno fiscale chiuso non si modifica più: nemmeno allegati, saldi iniziali o cessazioni dei soci. Si chiude in ordine, dal più vecchio, e si può riaprire solo l\'ultimo anno fiscale chiuso, indicando il motivo (resta scritto per sempre). '
			. 'L\'anno in corso non si chiude (e il primo giorno di ogni anno il nuovo anno si apre da solo). Un anno finito non si chiude se ha fondi accantonati non ancora rimborsati. L\'anno della tessera non dipende dagli anni fiscali: segue l\'anno sociale (Impostazioni).</p>';
		if ( null !== $last ) {
			echo '<p class="description">I documenti definitivi sono emessi e bloccati alla chiusura dell\'anno fiscale: non cambiano più.</p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>Anno</th><th>Stato</th><th>Movimenti</th><th>Fondi da rimborsare</th><th></th></tr></thead><tbody>';
		foreach ( $years as $y ) {
			$year     = (int) $y['year'];
			$open     = 'open' === $y['status'];
			$unsettled = (int) ( $totals[ $year ]['unsettled'] ?? 0 );
			$cl        = $open ? null : FiscalYears::current_closure( $year );
			$ver       = $cl && (int) $cl['version'] > 1 ? ' <span class="description">(chiusura n. ' . (int) $cl['version'] . ')</span>' : '';
			if ( $cl ) { // libro soci di quell'anno fiscale: fotografato alla chiusura, ricostruito dopo, oppure ancora no
				$ver .= '<br><span class="description">Libro soci ' . esc_html( FiscalYears::snapshot_text( $cl ) ) . ' · <a href="' . esc_url( Ui::url( 'asem-book', array( 'year' => $year ) ) ) . '">Apri</a></span>';
				foreach ( (array) apply_filters( 'asem_year_snapshot_lines', array(), $year, $cl ) as $line ) { // le estensioni aggiungono le loro fotografie (HTML già protetto)
					$ver .= '<br><span class="description">' . $line . '</span>';
				}
			}
			echo wp_kses( '<tr><td><strong>' . esc_html( FiscalYears::label( $year ) ) . '</strong></td><td>' . ( $open ? '<span class="asem-ok">aperto</span>' : '<strong>chiuso</strong>' . ( $y['closed_at'] ? ' <span class="description">il ' . esc_html( mysql2date( 'd/m/Y', $y['closed_at'] ) ) . '</span>' : '' ) . $ver ) . '</td>'
				. '<td>' . (int) FiscalYears::movements( $year ) . '</td><td>' . ( $unsettled > 0 ? '<strong class="asem-warn">' . Ui::money( $unsettled ) . '</strong>' : Ui::money( 0 ) ) . '</td><td>', Html::allowed() );
			if ( $open && $year >= FiscalYears::current() ) {
				echo '<span class="description">' . ( $year === FiscalYears::current() ? 'anno in corso: si chiude dopo la fine dell\'anno' : 'anno non ancora iniziato' ) . '</span>';
			} elseif ( $open ) {
				Ui::form_open( 'asem_close_year', $back, false, 'asem-inline' );
				do_action( 'asem_year_close_form', $year ); // un'estensione aggiunge una conferma
				echo wp_kses( Ui::hidden( 'year', $year ) . '<button class="button" data-confirm="Chiudere l\'anno ' . esc_attr( FiscalYears::label( $year ) ) . '? Non accetterà più nessuna modifica. Si può riaprire solo finché è l\'ultimo anno fiscale chiuso, e serve il motivo.">Chiudi l\'anno</button>', Html::allowed() );
				Ui::form_close();
			} elseif ( $year === $last ) {
				Ui::form_open( 'asem_reopen_year', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'year', $year ) . '<input type="text" name="reason" required maxlength="500" size="28" placeholder="Motivo della riapertura (obbligatorio)" aria-label="Motivo della riapertura"> '
					. '<button class="button" data-confirm="Riaprire l\'anno ' . esc_attr( FiscalYears::label( $year ) ) . '? Tornerà modificabile; il motivo resta registrato per sempre e dovrai chiuderlo di nuovo.">Riapri l\'anno</button>', Html::allowed() );
				Ui::form_close();
			} else {
				echo '<span class="description">Non riapribile: si riapre solo l\'ultimo anno fiscale chiuso (' . esc_html( FiscalYears::label( (int) $last ) ) . ').</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$retro = FiscalYears::unphotographed();
		if ( $retro ) {
			$names = array_map(
				function ( $c ) {
					return FiscalYears::label( (int) $c['year'] );
				},
				$retro
			);
			echo '<div class="asem-card"><h2>Emetti i documenti definitivi degli anni già chiusi</h2>';
			echo '<p>Questi anni fiscali sono stati chiusi prima che esistessero i documenti definitivi: <strong>' . esc_html( implode( ', ', $names ) ) . '</strong>. Si possono emettere ora, ma i dati di allora non ci sono più: il libro viene <strong>ricostruito dai dati di oggi</strong> (chi è già stato cancellato non compare, nome e dati anagrafici sono quelli attuali). '
				. 'Ogni libro porta la dicitura «emesso ora per un anno già chiuso: dati di oggi, ricostruiti il gg/mm/aaaa» e l\'operazione resta scritta nello storico. Non si può annullare.</p>';
			Ui::form_open( 'asem_snapshot_past_years', $back, false, 'asem-inline' );
			echo wp_kses( '<label><input type="checkbox" name="confirm_retro" value="1"> Ho capito: i dati sono ricostruiti, non ripresi alla chiusura.</label> <button class="button" data-confirm="Emettere ora i documenti definitivi degli anni fiscali già chiusi con i dati di oggi? Non si può annullare.">Emetti i documenti definitivi degli anni già chiusi</button>', Html::allowed() );
			Ui::form_close();
			echo '</div>';
		}

		$hist = FiscalYears::closures();
		if ( $hist ) {
			echo '<h2>Storico delle chiusure e delle riaperture</h2><p class="description">Resta per sempre: non si cancella nemmeno con la pulizia del registro azioni.</p>';
			echo '<table class="widefat striped"><thead><tr><th>Anno</th><th>Chiusura</th><th>Chiuso da</th><th>Riapertura</th><th>Motivo</th><th>Documenti definitivi</th></tr></thead><tbody>';
			foreach ( $hist as $h ) {
				$by_closed   = '' !== (string) $h['closed_by_name'] ? (string) $h['closed_by_name'] : 'non registrato (anno chiuso prima di questo storico)';
				$by_reopened = '' !== (string) $h['reopened_by_name'] ? ' da ' . $h['reopened_by_name'] : '';
				$extra = '';
				foreach ( (array) apply_filters( 'asem_year_snapshot_lines', array(), (int) $h['year'], $h ) as $line ) { // rendiconto fotografato di quella chiusura (HTML già protetto)
					$extra .= '<br>' . $line;
				}
				echo '<tr><td>' . esc_html( FiscalYears::label( (int) $h['year'] ) ) . ' <span class="description">(n. ' . (int) $h['version'] . ')</span></td><td>' . esc_html( mysql2date( 'd/m/Y', $h['closed_at'] ) ) . '</td><td>' . esc_html( $by_closed ) . '</td><td>'
					. ( $h['reopened_at'] ? esc_html( mysql2date( 'd/m/Y', $h['reopened_at'] ) . $by_reopened ) : '<span class="description">ancora chiuso</span>' ) . '</td><td>' . esc_html( (string) $h['reopen_reason'] ) . '</td><td>' . esc_html( FiscalYears::snapshot_text( $h ) ) . ( 'complete' === $h['snapshot_state'] ? ' · <a href="' . esc_url( Ui::url( 'asem-book', array( 'year' => (int) $h['year'], 'version' => (int) $h['version'] ) ) ) . '">Apri</a>' : '' ) . wp_kses( $extra, Html::allowed() ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tutti i valori passano da esc_html o da wp_kses
			}
			echo '</tbody></table>';
		}

		echo '<div class="asem-card"><h2>Crea anno fiscale</h2>';
		Ui::form_open( 'asem_create_year', $back );
		echo '<p><input type="number" name="year" min="2000" max="2100" value="' . esc_attr( (string) ( $latest ? $latest + 1 : (int) current_time( 'Y' ) ) ) . '" required> <button class="button button-primary" data-confirm="Creare questo anno? Da adesso si possono registrare incassi e spese di quell\'anno.">Crea l\'anno</button></p>'
			. '<p class="description">Crea in anticipo l\'anno che inizia (ad esempio a novembre l\'anno dopo), se devi registrare incassi o spese in anticipo. Le quote dei soci non dipendono da qui: la tessera segue l\'anno sociale.</p>';
		Ui::form_close();
		echo '</div>';

		do_action( 'asem_years_page_after' ); // un'estensione aggiunge i fondi da rimborsare per anno
		Ui::footer();
	}
}

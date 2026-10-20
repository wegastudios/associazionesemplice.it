<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\AssocPolicies;
use AssociazioneSemplice\Attendance;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\Insurance;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Statement;

defined( 'ABSPATH' ) || exit;

/** Registri delle funzioni aggiuntive (assicurazioni, presenze, rendiconto) e opzioni contabili. */
final class ProRegisters {

	public static function render_volunteers(): void {
		Ui::header( 'Assicurazioni' );
		$assoc = (bool) Settings::get( 'insurance_association' );
		$vols  = (bool) Settings::get( 'insurance_volunteers' );
		if ( ! $assoc && ! $vols ) {
			echo '<p>Le assicurazioni sono spente. Attivale da <a href="' . esc_url( Ui::url( 'asem-settings' ) ) . '">Impostazioni</a> (voce «Assicurazioni»): polizze dell\'associazione e registro delle assicurazioni dei volontari.</p>';
			Ui::footer();
			return;
		}
		if ( $assoc ) {
			self::policies_section();
		}
		if ( $vols ) {
			self::volunteers_section();
		}
		Ui::footer();
	}

	/** Polizze dell'associazione: responsabilità civile, infortuni dei soci, altre coperture. */
	private static function policies_section(): void {
		$statuses = AssocPolicies::statuses();
		$labels   = Insurance::status_labels();
		$kinds    = AssocPolicies::kinds();
		$cls      = array( Insurance::VALID => '', Insurance::EXPIRING => 'asem-warn', Insurance::EXPIRED => 'asem-neg', Insurance::NONE => 'asem-neg' );
		echo '<h2>Polizze dell\'associazione</h2><p class="description">La polizza generale dell\'associazione, a copertura dei soci e della responsabilità civile verso terzi per le attività svolte. '
			. 'Registra compagnia, numero, periodo di copertura, premio e massimale: viene segnalata in Bacheca se la responsabilità civile è scoperta o in scadenza entro ' . (int) Insurance::SOON . ' giorni.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Copertura</th><th>Polizza</th><th>Fino al</th><th>Stato</th></tr></thead><tbody>';
		foreach ( $statuses as $k => $st ) {
			$pol = $st['policy'];
			echo '<tr><td>' . esc_html( $kinds[ $k ] ) . '</td><td>' . ( $pol ? esc_html( $pol['company'] . ( '' !== (string) $pol['policy_no'] ? ' · n. ' . $pol['policy_no'] : '' ) . ( '' !== (string) $pol['coverage'] ? ' · ' . $pol['coverage'] : '' ) ) : '—' )
				. '</td><td>' . ( $pol ? RegistersPage::date( $pol['valid_to'] ) : '—' ) . '</td><td><span class="' . esc_attr( $cls[ $st['status'] ] ) . '">' . esc_html( $labels[ $st['status'] ] ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::form_open( 'asem_policy_add', Ui::url( 'asem-volunteers' ) );
		echo '<h3>Registra una polizza dell\'associazione</h3><p><select name="kind">' . Ui::options( $kinds, AssocPolicies::RC ) . '</select> <input type="text" name="company" placeholder="Compagnia" required> <input type="text" name="policy_no" placeholder="N. polizza" size="14"> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. 'dal <input type="date" name="valid_from" value="' . esc_attr( Db::today() ) . '" required> al <input type="date" name="valid_to" required></p>'
			. '<p><input type="text" name="premium" placeholder="Premio € (facoltativo)" size="18" inputmode="decimal"> <input type="text" name="coverage" placeholder="Massimale / descrizione (facoltativo)" class="regular-text" maxlength="255"> <button class="button button-primary">Registra la polizza</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		$all = AssocPolicies::all();
		if ( $all ) {
			echo '<details><summary>Tutte le polizze registrate (' . count( $all ) . ')</summary><table class="widefat striped"><thead><tr><th>Tipo</th><th>Compagnia</th><th>N.</th><th>Periodo</th><th>Premio</th><th>Massimale</th><th></th></tr></thead><tbody>';
			foreach ( $all as $x ) {
				echo '<tr><td>' . esc_html( $kinds[ $x['kind'] ] ?? $x['kind'] ) . '</td><td>' . esc_html( $x['company'] ) . '</td><td>' . esc_html( (string) $x['policy_no'] ) . '</td><td>' . RegistersPage::date( $x['valid_from'] ) . ' – ' . RegistersPage::date( $x['valid_to'] ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '</td><td>' . ( null === $x['premium_cents'] ? '—' : esc_html( Money::format( (int) $x['premium_cents'] ) ) ) . '</td><td>' . esc_html( (string) $x['coverage'] ) . '</td><td>';
				Ui::form_open( 'asem_policy_delete', Ui::url( 'asem-volunteers' ), false, 'asem-inline' );
				echo Ui::hidden( 'id', $x['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa polizza?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
	}

	private static function volunteers_section(): void {
		echo '<h2>Volontari</h2>';
		$rows   = Insurance::register();
		$labels = Insurance::status_labels();
		$cls    = array( Insurance::VALID => '', Insurance::EXPIRING => 'asem-warn', Insurance::EXPIRED => 'asem-neg', Insurance::NONE => 'asem-neg' );
		echo '<p class="description">I soci volontari con la loro assicurazione: chi svolge attività in modo continuativo deve essere coperto. '
			. 'Registra qui la polizza (compagnia, numero e periodo di copertura): il registro segnala chi è senza copertura o in scadenza entro ' . (int) Insurance::SOON . ' giorni.</p>';
		echo '<p>' . RegistersPage::downloads( 'volunteers', array() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped"><thead><tr><th>Volontario</th><th>Polizza</th><th>Copertura fino al</th><th>Stato</th><th>Registra una polizza</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun volontario.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$p   = $r['person'];
			$pol = $r['policy'];
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $p['id'] ) ) ) . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</a></td>'
				. '<td>' . ( $pol ? esc_html( $pol['company'] . ( '' !== (string) $pol['policy_no'] ? ' · n. ' . $pol['policy_no'] : '' ) ) : '—' ) . '</td>'
				. '<td>' . ( $pol ? RegistersPage::date( $pol['valid_to'] ) : '—' ) . '</td><td><span class="' . esc_attr( $cls[ $r['status'] ] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</span></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_open( 'asem_insurance_add', Ui::url( 'asem-volunteers' ), false, 'asem-inline' );
			echo Ui::hidden( 'person_id', $p['id'] ) . '<input type="text" name="company" placeholder="Compagnia" size="14" required> <input type="text" name="policy_no" placeholder="N. polizza" size="10"> dal <input type="date" name="valid_from" value="' . esc_attr( Db::today() ) . '" required> al <input type="date" name="valid_to" required> <button class="button">Aggiungi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
			$all = Insurance::for_person( (int) $p['id'] );
			if ( count( $all ) > 0 ) {
				echo '<details><summary class="description">Polizze registrate (' . count( $all ) . ')</summary><ul>';
				foreach ( $all as $x ) {
					echo '<li>' . esc_html( $x['company'] . ( '' !== (string) $x['policy_no'] ? ' n. ' . $x['policy_no'] : '' ) ) . ' · ' . RegistersPage::date( $x['valid_from'] ) . ' – ' . RegistersPage::date( $x['valid_to'] ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_open( 'asem_insurance_delete', Ui::url( 'asem-volunteers' ), false, 'asem-inline' );
					echo Ui::hidden( 'id', $x['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa polizza?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					echo '</li>';
				}
				echo '</ul></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- Presenze ----------

	public static function render_attendance(): void {
		$aid   = Ui::get_int( 'activity' );
		$today = Db::today();
		$ym    = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', Ui::get_str( 'ym' ) ) ? Ui::get_str( 'ym' ) : substr( $today, 0, 7 );
		$date  = Ui::get_str( 'date' );
		Ui::header( 'Registro presenze' );
		$acts = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$acts[ $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		echo '<p class="description">Per ogni lezione di un corso (o data di un evento) segna chi era presente. Il registro si compila a lezione fatta e si stampa per periodo.</p>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-attendance"><select name="activity">' . Ui::options( $acts, $aid ?: null, '— scegli il corso o l\'evento —' ) . '</select> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. '<input type="month" name="ym" value="' . esc_attr( $ym ) . '"> <button class="button">Mostra le lezioni</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$a = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a ) {
			Ui::footer();
			return;
		}
		$first = $ym . '-01';
		$last  = ( new \DateTimeImmutable( $first ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
		$dates = Attendance::lesson_dates( $a, $first, $last );
		echo '<h2>' . esc_html( $a['name'] ) . ' — ' . esc_html( Ui::month( $ym ) ) . '</h2>';
		if ( ! $dates ) {
			echo '<p class="description">In questo mese non ci sono lezioni.</p>';
		} else {
			echo '<p>';
			foreach ( $dates as $d ) {
				$done = (bool) Attendance::marks( $aid, $d );
				echo '<a class="button' . ( $d === $date ? ' button-primary' : '' ) . '" href="' . esc_url( Ui::url( 'asem-attendance', array( 'activity' => $aid, 'ym' => $ym, 'date' => $d ) ) ) . '">' . esc_html( ( new \DateTimeImmutable( $d ) )->format( 'd/m' ) ) . ( $done ? ' ✓' : '' ) . '</a> ';
			}
			echo '</p>';
		}
		if ( $date && in_array( $date, $dates, true ) ) {
			$roster = Attendance::roster( $a, $date );
			$marks  = Attendance::marks( $aid, $date );
			echo '<div class="asem-card"><h3>Lezione del ' . RegistersPage::date( $date ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $date > $today ) {
				echo '<p class="description">La lezione non si è ancora tenuta: le presenze si registrano a lezione fatta.</p>';
			} elseif ( ! $roster ) {
				echo '<p class="description">Nessun iscritto o prenotato per questa data.</p>';
			} else {
				Ui::form_open( 'asem_attendance_save', Ui::url( 'asem-attendance', array( 'activity' => $aid, 'ym' => $ym, 'date' => $date ) ) );
				echo Ui::hidden( 'activity_id', $aid ) . Ui::hidden( 'date', $date ) . '<ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $roster as $r ) {
					$on = $marks ? ! empty( $marks[ (int) $r['id'] ] ) : true; // prima registrazione: tutti presenti, si tolgono gli assenti
					echo '<li><label><input type="checkbox" name="present[]" value="' . (int) $r['id'] . '"' . checked( $on, true, false ) . '> ' . esc_html( trim( $r['last_name'] . ' ' . $r['first_name'] ) ) . '</label></li>';
				}
				echo '</ul><p><button class="button button-primary">Registra le presenze</button></p>';
				Ui::form_close();
			}
			echo '</div>';
		}
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', Ui::get_str( 'from' ) ) ? Ui::get_str( 'from' ) : substr( $today, 0, 4 ) . '-01-01';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', Ui::get_str( 'to' ) ) ? Ui::get_str( 'to' ) : $today;
		echo '<h2>Riepilogo del periodo</h2>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-attendance"><input type="hidden" name="activity" value="' . (int) $aid . '"><input type="hidden" name="ym" value="' . esc_attr( $ym ) . '">'
			. 'dal <input type="date" name="from" value="' . esc_attr( $from ) . '"> al <input type="date" name="to" value="' . esc_attr( $to ) . '"> <button class="button">Aggiorna</button> '
			. RegistersPage::downloads( 'attendance', array( 'activity' => $aid, 'from' => $from, 'to' => $to ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$s = Attendance::summary( $aid, $from, $to );
		echo '<p class="description">' . count( $s['dates'] ) . ' lezioni registrate.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>Presenze</th><th>Lezioni</th><th>%</th></tr></thead><tbody>';
		if ( ! $s['people'] ) {
			echo '<tr><td colspan="4">Nessuna presenza registrata nel periodo.</td></tr>';
		}
		foreach ( $s['people'] as $p ) {
			echo '<tr><td>' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</td><td>' . (int) $p['presenze'] . '</td><td>' . (int) $p['totale'] . '</td><td>' . ( $p['totale'] ? (int) round( 100 * $p['presenze'] / $p['totale'] ) . '%' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	// ---------- Rendiconto ----------

	public static function render_statement(): void {
		if ( Admin::reports_off( 'Rendiconto per cassa' ) ) {
			return;
		}
		$years = Statement::years();
		$year  = Ui::get_int( 'year', $years[0] );
		$year  = in_array( $year, $years, true ) ? $year : $years[0];
		Ui::header( 'Rendiconto per cassa' );
		echo '<p class="description">Il rendiconto dell\'anno solare costruito dalla prima nota: entrate e uscite per area, confronto con l\'anno precedente, avanzo o disavanzo, cassa e conti. '
			. 'Le aree seguono la «voce di rendiconto» di ogni categoria. Stampa il PDF, aggiungi la relazione e fai firmare tesoriere e presidente; il commercialista può verificarlo prima dell\'approvazione.</p>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-statement"><select name="year">' . Ui::options( array_combine( $years, $years ), $year ) . '</select> <button class="button">Mostra</button> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. '<a class="button button-primary" target="_blank" href="' . esc_url( Docs::url( 'statement', array( 'year' => $year ) ) ) . '">PDF del rendiconto</a></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$s    = Statement::data( $year );
		$show = function ( string $label, array $groups, array $total ) use ( $year ) {
			echo '<h2>' . esc_html( $label ) . '</h2><table class="widefat striped"><thead><tr><th></th><th style="text-align:right">' . (int) ( $year - 1 ) . '</th><th style="text-align:right">' . (int) $year . '</th></tr></thead><tbody>';
			foreach ( $groups as $g ) {
				echo '<tr><td><strong>' . esc_html( $g['group'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $g['prev'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $g['cur'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $g['lines'] as $l ) {
					echo '<tr><td style="padding-left:24px">' . esc_html( $l['name'] ) . '</td><td style="text-align:right">' . Ui::money( $l['prev'] ) . '</td><td style="text-align:right">' . Ui::money( $l['cur'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			echo '<tr><td><strong>Totale</strong></td><td style="text-align:right"><strong>' . Ui::money( $total['prev'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $total['cur'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$show( 'Entrate', $s['income'], $s['total_income'] );
		$show( 'Uscite', $s['expenses'], $s['total_expense'] );
		echo '<p><strong>' . ( $s['result']['cur'] >= 0 ? 'Avanzo' : 'Disavanzo' ) . ' di gestione ' . (int) $year . ':</strong> ' . Ui::money( $s['result']['cur'] ) . ' <span class="description">(' . (int) ( $year - 1 ) . ': ' . esc_html( Money::format( $s['result']['prev'] ) ) . ')</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h2>Cassa e conti</h2><table class="widefat striped"><thead><tr><th></th><th style="text-align:right">Iniziale</th><th style="text-align:right">Finale</th></tr></thead><tbody>';
		foreach ( $s['accounts'] as $a ) {
			echo '<tr><td>' . esc_html( $a['name'] ) . '</td><td style="text-align:right">' . Ui::money( $a['opening'] ) . '</td><td style="text-align:right">' . Ui::money( $a['closing'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale</strong></td><td style="text-align:right"><strong>' . Ui::money( $s['opening_total'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $s['closing_total'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h2>Relazione sull\'andamento della gestione</h2>';
		Ui::form_open( 'asem_statement_notes', Ui::url( 'asem-statement', array( 'year' => $year ) ) );
		echo Ui::hidden( 'year', $year ) . '<p><textarea name="notes" rows="8" class="large-text" maxlength="' . (int) Statement::MAX_NOTES . '">' . esc_textarea( $s['notes'] ) . '</textarea></p><p class="description">Compare in fondo al PDF, prima delle firme.</p><p><button class="button button-primary">Salva la relazione</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		Ui::footer();
	}

	public static function render_accounting(): void {
		Ui::header( 'Opzioni contabili' );
		Ui::form_open( 'asem_save_acct', Ui::url( 'asem-acct' ) );
		echo '<p><label><input type="checkbox" name="reports_enabled" value="1"' . checked( ! empty( Settings::get( 'reports_enabled' ) ), true, false ) . '> <strong>Report e rendiconto</strong></label><br>'
			. '<span class="description">Mostra le schede «Report» e «Rendiconto» in Contabilità (valutazione dell\'anno sociale, rendiconto per cassa in PDF). Spegnile se la contabilità la tiene qualcun altro: la prima nota resta.</span></p>';
		echo '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<h2>Dove sono le altre impostazioni contabili</h2><ul>'
			. '<li><a href="' . esc_url( Ui::url( 'asem-settings' ) ) . '">Soci e quote</a> — quota associativa, livelli di socio, sconto del nucleo familiare.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'asem-years' ) ) . '">Anni solari</a> — apertura e chiusura degli anni contabili.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'asem-accounts' ) ) . '">Conti e fondi</a> — cassa, banca, POS e fondi per i rimborsi ai volontari.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'asem-comms' ) ) . '">Privacy, regolamento e ricevute</a> — riga in fondo alla ricevuta, promemoria dei pagamenti.</li></ul>';
		Ui::footer();
	}
}

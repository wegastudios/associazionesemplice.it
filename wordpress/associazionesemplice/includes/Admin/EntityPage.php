<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Dati dell'ente: denominazione, sede, codice fiscale e contatti. */
final class EntityPage {

	/** Il campo «L'anno fiscale inizia a»: con esercizi chiusi è bloccato e dice perché. */
	public static function fiscal_month_field( array $s ): string {
		$month = (int) ( $s['fiscal_start_month'] ?? 1 );
		if ( FiscalYears::start_month_locked() ) {
			return '<select name="fiscal_start_month" disabled>' . Ui::options( Ui::MONTHS, $month ) . '</select> <span class="description">' . esc_html( FiscalYears::start_month_locked_message() ) . '</span>';
		}
		return '<select name="fiscal_start_month">' . Ui::options( Ui::MONTHS, $month ) . '</select>';
	}

	public static function render(): void {
		$s = Settings::all();
		Ui::header( (string) apply_filters( 'asem_entity_page_title', 'Dati dell\'ente' ) );
		Ui::form_open( 'asem_save_entity', Ui::url( 'asem-entity' ) );
		echo '<h2>Ente</h2><table class="form-table"><tbody>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		do_action( 'asem_entity_rows', $s ); // le estensioni aggiungono qui i loro campi
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Iscrizione (RUNTS, registro…)</th><td><input type="text" name="runts_number" value="' . esc_attr( (string) $s['runts_number'] ) . '" class="regular-text"></td></tr>';
		echo wp_kses( '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select><p class="description">È l\'anno delle attività: serve per raggruppare corsi ed eventi (per esempio una stagione da settembre ad agosto). Può iniziare in un mese diverso dall\'anno fiscale. Non cambia la tessera, che per ora vale sempre dal 1° gennaio al 31 dicembre.</p></td></tr>', Html::allowed() );
		echo wp_kses( '<tr><th>L\'anno fiscale inizia a</th><td>' . self::fiscal_month_field( $s ) . '<p class="description">È l\'esercizio della contabilità (prima nota, esercizi, report, preventivo): di partenza è gennaio, cioè l\'anno solare, ma può iniziare in qualunque mese. Cambialo prima di chiudere il primo esercizio: dopo la prima chiusura non si può più cambiare.</p></td></tr>', Html::allowed() );
		echo '</tbody></table>';

		echo '<h2>Registro unico del Terzo settore (RUNTS)</h2><table class="form-table"><tbody>';
		echo wp_kses( '<tr><th>Sezione del RUNTS</th><td><select name="runts_section">' . Ui::options( \AssociazioneSemplice\RuntsRules::sections(), (string) $s['runts_section'] ) . '</select>'
			. '<p class="description">Se sei una ODV o una APS il programma controlla che i soci non scendano sotto il minimo (7 persone oppure 3 enti) e ti avvisa. Se non sei iscritto al RUNTS lascia «Non indicata».</p></td></tr>', Html::allowed() );
		$ry = (int) $s['runts_manual_year'];
		echo wp_kses( '<tr><th>Volontari iscritti nel registro</th><td><input type="number" name="runts_volunteers" min="0" max="1000000" value="' . (int) $s['runts_volunteers'] . '" class="small-text"> '
			. 'riferiti al 31 dicembre <input type="number" name="runts_manual_year" min="2000" max="2100" value="' . ( $ry ? $ry : '' ) . '" class="small-text" placeholder="anno">'
			. '<p class="description">Scrivi quanti volontari risultano nel tuo registro dei volontari al 31 dicembre, e di quale anno. Il numero compare in Registri → Dati per il RUNTS.</p></td></tr>', Html::allowed() );
		echo '</tbody></table>';

		echo '<h2>Sede e contatti</h2><table class="form-table"><tbody>';
		echo '<tr><th>Indirizzo</th><td><input type="text" name="legal_address" value="' . esc_attr( (string) $s['legal_address'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>CAP, comune, provincia</th><td><input type="text" name="legal_zip" value="' . esc_attr( (string) $s['legal_zip'] ) . '" size="6" placeholder="CAP"> <input type="text" name="legal_city" value="' . esc_attr( (string) $s['legal_city'] ) . '" placeholder="Comune"> <input type="text" name="legal_province" value="' . esc_attr( (string) $s['legal_province'] ) . '" size="4" placeholder="Prov."></td></tr>';
		echo '<tr><th>PEC</th><td><input type="email" name="pec" value="' . esc_attr( (string) $s['pec'] ) . '" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		do_action( 'asem_entity_sections', $s ); // ... e qui le loro sezioni
		submit_button( 'Salva' );
		Ui::form_close();
		do_action( 'asem_entity_page_end' );
		Ui::footer();
	}
}

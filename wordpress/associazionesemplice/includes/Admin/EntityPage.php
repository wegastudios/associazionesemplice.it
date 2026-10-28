<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Dati dell'ente: denominazione, sede, codice fiscale e contatti. */
final class EntityPage {

	public static function render(): void {
		$s = Settings::all();
		Ui::header( (string) apply_filters( 'asem_entity_page_title', 'Dati dell\'ente' ) );
		Ui::form_open( 'asem_save_entity', Ui::url( 'asem-entity' ) );
		echo '<h2>Ente</h2><table class="form-table"><tbody>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		do_action( 'asem_entity_rows', $s ); // le estensioni aggiungono qui i loro campi
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Iscrizione (RUNTS, registro…)</th><td><input type="text" name="runts_number" value="' . esc_attr( (string) $s['runts_number'] ) . '" class="regular-text"></td></tr>';
		echo wp_kses( '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>', Html::allowed() );
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

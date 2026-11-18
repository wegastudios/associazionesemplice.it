<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\EntityRules;
use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Texts;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Dati dell'ente: denominazione, sede, codice fiscale e contatti. */
final class EntityPage {

	/** Il campo «L'anno fiscale inizia a»: con esercizi chiusi è bloccato e dice perché; se c'è un cambio in attesa di conferma, ne mostra l'anteprima. */
	public static function fiscal_month_field( array $s ): string {
		$month = (int) ( $s['fiscal_start_month'] ?? 1 );
		if ( FiscalYears::start_month_locked() ) {
			return '<select name="fiscal_start_month" disabled>' . Ui::options( Ui::MONTHS, $month ) . '</select> <span class="description">' . esc_html( FiscalYears::start_month_locked_message() ) . '</span>';
		}
		$pending = self::pending_month();
		$select  = '<select name="fiscal_start_month">' . Ui::options( Ui::MONTHS, $pending ?: $month ) . '</select>';
		return $select . ( $pending ? self::realign_box( $pending ) : '' );
	}

	/** Mese scelto e non ancora confermato (salvato dalla persona che sta guardando la pagina), o 0. */
	public static function pending_month(): int {
		$t = get_transient( 'asem_fiscal_pending_' . get_current_user_id() );
		$m = is_array( $t ) ? (int) ( $t['month'] ?? 0 ) : 0;
		return ( $m >= 1 && $m <= 12 && $m !== \AssociazioneSemplice\Settings::membership_start_month() ) ? $m : 0;
	}

	/** «Cosa succede alle tessere»: anteprima del riallineamento con la spunta da confermare. */
	public static function realign_box( int $month ): string {
		$pv = \AssociazioneSemplice\Install::realign_preview( $month );
		if ( 0 === $pv['changed'] + $pv['replaced'] ) {
			return '<p class="description">' . esc_html( Texts::t( 'Nessuna tessera da riallineare.' ) ) . '</p>'; // niente da confermare: nessuna spunta
		}
		$txt =Texts::t( 'Le tessere dei soci seguono l\'anno fiscale. Cambiando il mese, le tessere degli anni fiscali ancora aperti che hanno un pagamento registrato si riallineano al nuovo anno fiscale: non si accorciano mai e quelle degli anni chiusi non si toccano.' )
			. ' ' . sprintf( Texts::t( 'Con questa scelta: %1$d tessere cambiano%2$s; %3$d risultano «sostituite» (restano registrate, i pagamenti non cambiano).' ), $pv['changed'], $pv['examples'] ? ' (' . implode( ', ', $pv['examples'] ) . ')' : '', $pv['replaced'] )
			. ' ' . Texts::t( 'Prima del cambio viene salvata una copia di sicurezza dei dati, che resta sul sito per 7 giorni.' );
		return '<div class="notice notice-warning inline"><p><strong>' . esc_html( Texts::t( 'Cosa succede alle tessere' ) ) . '</strong></p><p>' . esc_html( $txt ) . '</p>'
			. '<p><label><input type="checkbox" name="confirm_card_realign" value="1"> ' . esc_html( Texts::t( 'Ho letto cosa succede alle tessere e voglio cambiare il mese dell\'anno fiscale.' ) ) . '</label></p></div>';
	}

	/** Le due righe obbligatorie dei contatti della segreteria (email e telefono): le stesse nella procedura guidata e nei dati dell'ente. */
	public static function contact_rows( array $s ): string {
		$mail = trim( (string) ( $s['secretary_email'] ?? '' ) );
		$tel  = trim( (string) ( $s['secretary_phone'] ?? '' ) );
		return '<tr id="asem-contacts"><th><label for="asem-secretary-email">' . esc_html( Texts::t( 'Email della segreteria' ) ) . ' *</label></th><td><input type="email" id="asem-secretary-email" name="secretary_email" value="' . esc_attr( $mail ) . '" class="regular-text" required autocomplete="off" placeholder="segreteria@…">'
			. '<p class="description">' . esc_html( Texts::t( 'Li leggono i soci nelle email e nell\'informativa sulla privacy: usa un recapito che qualcuno controlla davvero.' ) ) . '</p></td></tr>'
			. '<tr><th><label for="asem-secretary-phone">' . esc_html( Texts::t( 'Telefono della segreteria' ) ) . ' *</label></th><td><input type="tel" id="asem-secretary-phone" name="secretary_phone" value="' . esc_attr( $tel ) . '" class="regular-text" required maxlength="30" autocomplete="off" placeholder="+39 333 1234567"></td></tr>';
	}

	/** Casella «non ce l'ha» (per i dati obbligatori solo se esistono): se il dato c'è non vale. */
	private static function none_box( string $name, bool $on, string $label ): string {
		return '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}

	public static function name_row( array $s ): string {
		return '<tr><th><label for="asem-association-name">' . esc_html( Texts::t( 'Denominazione' ) ) . ' *</label></th><td><input type="text" id="asem-association-name" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text" required></td></tr>';
	}

	/** Forma dell'ente: separata dalla sezione del RUNTS; con una sezione già indicata si propone la stessa forma, da confermare. */
	public static function form_row( array $s ): string {
		$cur  = (string) $s['entity_form'];
		$sug  = EntityRules::suggested_form( $s );
		$note = '';
		if ( '' === $cur && '' !== $sug ) {
			$cur  = $sug;
			$note = '<p class="description">' . esc_html( Texts::t( 'Proposta in base alla sezione del RUNTS già indicata: controlla e conferma.' ) ) . '</p>';
		}
		return '<tr id="asem-entity-form"><th><label for="asem-entity-form-select">' . esc_html( Texts::t( 'Forma dell\'ente' ) ) . ' *</label></th><td><select id="asem-entity-form-select" name="entity_form" required>'
			. Ui::options( EntityRules::forms(), $cur, Texts::t( '— scegli —' ) ) . '</select>' . $note . '</td></tr>';
	}

	public static function tax_row( array $s ): string {
		return '<tr><th><label for="asem-tax-code">' . esc_html( Texts::t( 'Codice fiscale' ) ) . ' *</label></th><td><input type="text" id="asem-tax-code" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text" required maxlength="20" autocomplete="off"></td></tr>';
	}

	public static function seat_rows( array $s ): string {
		return '<tr><th><label for="asem-legal-address">' . esc_html( Texts::t( 'Sede legale' ) ) . ' *</label></th><td><input type="text" id="asem-legal-address" name="legal_address" value="' . esc_attr( (string) $s['legal_address'] ) . '" class="regular-text" required placeholder="' . esc_attr( Texts::t( 'Indirizzo' ) ) . '"> '
			. '<input type="text" name="legal_zip" value="' . esc_attr( (string) $s['legal_zip'] ) . '" size="6" maxlength="5" inputmode="numeric" required placeholder="CAP" aria-label="CAP"> '
			. '<input type="text" name="legal_city" value="' . esc_attr( (string) $s['legal_city'] ) . '" required placeholder="' . esc_attr( Texts::t( 'Comune' ) ) . '" aria-label="' . esc_attr( Texts::t( 'Comune' ) ) . '"> '
			. '<input type="text" name="legal_province" value="' . esc_attr( (string) $s['legal_province'] ) . '" size="4" maxlength="2" required placeholder="Prov." aria-label="Prov."></td></tr>';
	}

	/** Partita IVA: dato dell'ente (il calcolo dell'IVA è delle funzioni aggiuntive); se non ce l'ha, la casella. */
	public static function vat_row( array $s ): string {
		return '<tr><th><label for="asem-vat-number">' . esc_html( Texts::t( 'Partita IVA' ) ) . '</label></th><td><input type="text" id="asem-vat-number" name="vat_number" value="' . esc_attr( (string) $s['vat_number'] ) . '" class="regular-text" maxlength="13" placeholder="' . esc_attr( Texts::t( '11 cifre' ) ) . '" autocomplete="off">'
			. self::none_box( 'no_vat', ! empty( $s['no_vat'] ), Texts::t( 'L\'associazione non ha la partita IVA' ) ) . '</td></tr>';
	}

	public static function runts_row( array $s ): string {
		return '<tr><th><label for="asem-runts-number">' . esc_html( Texts::t( 'Numero RUNTS (con la sezione)' ) ) . '</label></th><td><input type="text" id="asem-runts-number" name="runts_number" value="' . esc_attr( (string) $s['runts_number'] ) . '" class="regular-text">'
			. self::none_box( 'no_runts', ! empty( $s['no_runts'] ), Texts::t( 'L\'associazione non è iscritta al RUNTS' ) ) . '</td></tr>';
	}

	public static function pec_row( array $s ): string {
		return '<tr><th><label for="asem-pec">' . esc_html( Texts::t( 'PEC' ) ) . '</label></th><td><input type="email" id="asem-pec" name="pec" value="' . esc_attr( (string) $s['pec'] ) . '" class="regular-text">'
			. self::none_box( 'no_pec', ! empty( $s['no_pec'] ), Texts::t( 'L\'associazione non ha una PEC' ) ) . '</td></tr>';
	}

	/** Le righe dei dati obbligatori dell'ente nella configurazione guidata (nei dati dell'ente le stesse righe stanno nelle loro sezioni). */
	public static function identity_rows( array $s ): string {
		return self::name_row( $s ) . self::form_row( $s ) . self::tax_row( $s ) . self::seat_rows( $s ) . self::vat_row( $s ) . self::runts_row( $s ) . self::pec_row( $s );
	}

	public static function render(): void {
		$s = Settings::all();
		Ui::header( (string) apply_filters( 'asem_entity_page_title', 'Dati dell\'ente' ) );
		$warn = EntityRules::mismatch_warning( (string) $s['entity_form'], (string) $s['runts_section'] ); // avviso, non blocca nulla
		if ( '' !== $warn ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warn ) . '</p></div>';
		}
		Ui::form_open( 'asem_save_entity', Ui::url( 'asem-entity' ) );
		echo '<h2>Ente</h2><table class="form-table"><tbody>';
		echo wp_kses( self::name_row( $s ) . self::form_row( $s ), Html::allowed() );
		do_action( 'asem_entity_rows', $s ); // le estensioni aggiungono qui i loro campi
		echo wp_kses( self::tax_row( $s ) . self::vat_row( $s ) . self::runts_row( $s ), Html::allowed() );
		echo wp_kses( '<tr><th>Da che mese iniziano corsi ed eventi (anno delle attività)?</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select><p class="description">È l\'anno di corsi ed eventi (per esempio una stagione da settembre ad agosto): ogni attività sta in un anno e le sue date devono cadere dentro. La tessera dei soci, le quote e gli incassi seguono invece l\'anno fiscale (qui sotto).</p></td></tr>', Html::allowed() );
		echo wp_kses( '<tr><th>Da che mese inizia il tuo anno fiscale (bilancio e rendiconto)?</th><td>' . self::fiscal_month_field( $s ) . '<p class="description">È l\'anno dei conti, della tessera dei soci e delle quote: prima nota, rendiconto, preventivo e rinnovi. Di solito inizia a gennaio, ma puoi scegliere un altro mese, anche diverso dall\'anno delle attività. Sceglilo prima di chiudere il primo anno: dopo la prima chiusura non si può più cambiare. I Dati per il RUNTS restano al 31 dicembre in ogni caso.</p></td></tr>', Html::allowed() );
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
		echo wp_kses( self::seat_rows( $s ), Html::allowed() );
		echo wp_kses( self::pec_row( $s ), Html::allowed() );
		echo wp_kses( self::contact_rows( $s ), Html::allowed() );
		echo '</tbody></table>';
		do_action( 'asem_entity_sections', $s ); // ... e qui le loro sezioni
		submit_button( 'Salva' );
		Ui::form_close();
		do_action( 'asem_entity_page_end' );
		Ui::footer();
	}
}

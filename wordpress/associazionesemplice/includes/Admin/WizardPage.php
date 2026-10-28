<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Modules;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Pages;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Wizard;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Configurazione guidata a domande: prima si decide quali parti del gestionale servono, poi si configurano solo quelle scelte.
 * Un passo alla volta (senza JavaScript compaiono tutti insieme); i passi e i blocchi che non servono si saltano.
 */
final class WizardPage {

	/** Pagina => condizione (nome=valore) perché sia proposta. */
	const PAGE_IF = array(
		'attivita'    => 'mod[activities]=1',
		'calendario'  => 'mod[activities]=1',
		'bonifico'    => 'bank_enabled=1',
		'tesoriere'   => 'mod[ledger]=1',
		'ingressi'    => 'mod[activities]=1',
	);

	public static function step( string $title, string $intro, string $if = '', string $group = '' ): void {
		echo '<section class="asem-wiz-step"' . ( '' !== $if ? ' data-if="' . esc_attr( $if ) . '"' : '' ) . ( '' !== $group ? ' data-group="' . esc_attr( $group ) . '"' : '' ) . '><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $intro ? '<p class="description">' . esc_html( $intro ) . '</p>' : '' );
	}

	/** Sezioni dell'indicatore: Ente (dati dell'ente, partita IVA, quota), Gestione (cosa serve), Pagamenti, Aspetto (pagine e privacy). L'ultima schermata (l'elenco dei soci) è facoltativa e non si conta. */
	const SECTIONS = array(
		'ente'      => 'Ente',
		'gestione'  => 'Gestione',
		'pagamenti' => 'Pagamenti',
		'aspetto'   => 'Aspetto',
	);

	/** Indicatore di sezione: dove ci si trova, senza contare le pagine. */
	private static function stepper(): string {
		$html = '<div class="asem-wiz-stepper"><span class="asem-wiz-where"></span><ol>';
		foreach ( self::SECTIONS as $id => $label ) {
			$html .= '<li data-group="' . esc_attr( $id ) . '"><span class="asem-wiz-dot"></span>' . esc_html( $label ) . '</li>';
		}
		return $html . '</ol></div>';
	}

	public static function end_step(): void {
		echo '</section>';
	}

	/** Domanda sì/no: due pulsanti di scelta con lo stesso nome. */
	public static function yes_no( string $name, string $question, bool $current ): string {
		return '<p class="asem-wiz-q"><strong>' . esc_html( $question ) . '</strong><br>'
			. '<label><input type="radio" name="' . esc_attr( $name ) . '" value="1"' . checked( $current, true, false ) . '> Sì</label> &nbsp; '
			. '<label><input type="radio" name="' . esc_attr( $name ) . '" value="0"' . checked( ! $current, true, false ) . '> No</label></p>';
	}

	private static function features_box( array $s ): string {
		$conditional = (array) apply_filters( 'asem_wizard_feature_conditions', array( 'card_qr_enabled' => 'card_enabled=1' ) );
		$html        = '<input type="hidden" name="features_present" value="1">';
		foreach ( SettingsPage::visible_features() as $k => $label ) {
			if ( in_array( $k, \AssociazioneSemplice\Wizard::own_step(), true ) ) {
				continue;
			}
			$html .= '<div' . ( isset( $conditional[ $k ] ) ? ' data-if="' . esc_attr( $conditional[ $k ] ) . '" style="margin-left:24px"' : '' ) . '><label><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '> ' . esc_html( $label ) . '</label></div>';
		}
		return $html;
	}

	public static function render(): void {
		$s    = Settings::all();
		Ui::header( 'Configurazione guidata' );
		echo '<p style="font-size:15px"><strong>Descrivi il tuo ente e scegli in 4 sezioni veloci i servizi che vuoi gestire.</strong><br><span class="description">Le voci «Più dettagli» sono facoltative: ciò che non scegli resta spento e si accende dopo, dalle impostazioni o riaprendo questa procedura dagli Strumenti.</span></p>';
		Ui::form_open( 'asem_wizard_save', Ui::url( 'asem-wizard' ), false, 'asem-wizard' );
	echo wp_kses( self::stepper(), Html::allowed() );

		// 1. Ente, ospiti e iscrizione
		$jm   = (string) $s['join_mode'];
		self::step( 'Il tuo ente', 'Poche righe per cominciare' . (string) apply_filters( 'asem_wizard_entity_intro', '.' ), '', 'ente' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Come si chiama</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		do_action( 'asem_wizard_entity_rows', $s ); // le estensioni aggiungono qui le loro domande
		echo '</tbody></table>';
		echo wp_kses( self::yes_no( 'guests_enabled', 'Accettate ospiti, cioè persone che partecipano senza essere iscritte?', Settings::guests_enabled() ), Html::allowed() );
		echo '<p class="asem-wiz-q"><strong>Chi può iscriversi</strong><br>'
			. '<label><input type="radio" name="join_mode" value="request"' . checked( 'invite' !== $jm, true, false ) . '> Chiunque può chiedere di iscriversi</label> <span class="description">(la richiesta arriva alla segreteria, che approva; si apre la domanda online nella pagina «Diventa socio»)</span><br>'
			. '<label><input type="radio" name="join_mode" value="invite"' . checked( 'invite' === $jm, true, false ) . '> Solo su presentazione</label> <span class="description">(le iscrizioni le fa la segreteria)</span></p>';
		echo '<details class="asem-wiz-more"><summary>Più dettagli: codice fiscale, anno sociale, sede</summary><table class="form-table"><tbody>';
		echo '<tr><th>Codice fiscale dell\'ente</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo wp_kses( '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>', Html::allowed() );
		echo '<tr><th>Sede</th><td><input type="text" name="legal_address" value="' . esc_attr( (string) $s['legal_address'] ) . '" class="regular-text" placeholder="Indirizzo"> <input type="text" name="legal_zip" value="' . esc_attr( (string) $s['legal_zip'] ) . '" size="6" placeholder="CAP"> <input type="text" name="legal_city" value="' . esc_attr( (string) $s['legal_city'] ) . '" placeholder="Comune"> <input type="text" name="legal_province" value="' . esc_attr( (string) $s['legal_province'] ) . '" size="4" placeholder="Prov."></td></tr>';
		echo '</tbody></table></details>';
		self::end_step();

		do_action( 'asem_wizard_steps_entity', $s ); // le estensioni aggiungono qui i loro passi

		// 4. Parti da usare, con le domande che dipendono dalle risposte
		self::step( 'Cosa ti serve', 'Rispondi sì solo a ciò che usi davvero: il resto sparisce dal menu e dalle schede. Le domande in più compaiono solo se rispondi sì.', '', 'gestione' );
		echo '<input type="hidden" name="mod_present" value="1">';
		foreach ( Modules::defs() as $key => $d ) {
			if ( 'import' === $key ) {
				continue; // le importazioni sono sempre disponibili (dagli Strumenti)
			}
			echo wp_kses( '<div' . ( '' !== $d['needs'] ? ' data-if="mod[' . esc_attr( $d['needs'] ) . ']=1" style="margin-left:24px"' : '' ) . '>' . self::yes_no( 'mod[' . $key . ']', $d['ask'], Modules::on( $key ) ), Html::allowed() );
			do_action( 'asem_wizard_module_extra', $key, $s );
			echo '</div>';
		}
		echo wp_kses( '<details class="asem-wiz-more"><summary>Più dettagli: altre funzioni facoltative (tutte spente)</summary>' . self::features_box( $s ) . '</details>', Html::allowed() );
		self::end_step();


		// 3. Quota associativa
		self::step( 'Quota associativa', 'Quanto costa iscriversi: vale per tutti i soci. Tipi di socio diversi e sconti si aggiungono sotto, solo se servono.', '', 'pagamenti' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Quota associativa</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €</td></tr>';
		echo '</tbody></table>';
		echo '<details class="asem-wiz-more"><summary>Più dettagli: altri tipi di socio e sconti</summary><table class="form-table"><tbody>';
		do_action( 'asem_wizard_levels_rows', $s );
		echo '<tr><th>Sconto nucleo familiare</th><td><input type="number" min="0" max="100" name="family_discount_pct" value="' . (int) $s['family_discount_pct'] . '"> %<p class="description">0 = nessuno sconto.</p></td></tr>';
		echo '</tbody></table></details>';
		self::end_step();

		// 8. Pagamenti
		self::step( 'Pagamenti dei soci', 'Come i soci versano quote e contributi dal sito. Puoi cambiare idea in qualsiasi momento da Pagamenti online.', 'mod[ledger]=1', 'pagamenti' );
		if ( has_action( 'asem_wizard_payment_choices' ) ) {
			do_action( 'asem_wizard_payment_choices', $s );
		} else {
			echo '<input type="hidden" name="payment_choice" value="none">';
		}
		echo '<p><label><input type="checkbox" name="bank_enabled" value="1"' . checked( ! empty( $s['bank_enabled'] ), true, false ) . '> Mostra anche le coordinate bancarie (bonifico)</label><br><span class="description">Gli IBAN si inseriscono subito dopo, in Pagamenti online. Il bonifico può affiancare o sostituire i pagamenti online.</span></p>';
		self::end_step();

		// 9. Prodotti WooCommerce
		do_action( 'asem_wizard_steps_payments', $s );

		// 10. Pagine
		$exist = Pages::existing();
		self::step( 'Pagine del sito', 'Le pagine con gli shortcode già inseriti, proposte in base alle tue risposte. Potrai personalizzarne l\'impaginazione; quelle già create non si duplicano.', '', 'aspetto' );
		echo '<input type="hidden" name="pages_present" value="1">';
		$priv_sel = 0;
		if ( '' !== (string) $s['privacy_url'] ) {
			$priv_sel = (int) url_to_postid( (string) $s['privacy_url'] );
		}
		if ( ! $priv_sel && Wizard::pending() ) { // solo alla prima configurazione si propone la pagina privacy di WordPress; su un sito già in uso non si cambia da sola l'accettazione dell'informativa
			$priv_sel = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		}
		echo wp_kses( '<p><label><strong>Pagina con l\'informativa privacy</strong><br>' . wp_dropdown_pages(
			array( 'name' => 'privacy_page_id', 'selected' => $priv_sel, 'show_option_none' => '— non ho ancora la pagina —', 'option_none_value' => '0', 'echo' => 0 )
		) . '</label><br><span class="description">Chi attiva l\'accesso dal sito dovrà accettarla. Si sceglie tra le pagine che hai già su WordPress.</span></p>', Html::allowed() );
		$titles = \AssociazioneSemplice\Areas::titles();
		$page_if = (array) apply_filters( 'asem_wizard_page_if', self::PAGE_IF ); // condizioni di visibilità delle pagine proposte
		foreach ( Pages::by_area() as $area => $defs ) {
			$conds = array_unique( array_map( function ( $k ) use ( $page_if ) {
				return $page_if[ $k ] ?? '';
			}, array_keys( $defs ) ) );
			$wrap  = 1 === count( $conds ) && '' !== $conds[0] ? ' data-if="' . esc_attr( $conds[0] ) . '"' : ''; // l'intera area compare solo se serve
			echo wp_kses( '<div' . $wrap . '><h3 style="margin-bottom:4px">' . esc_html( $titles[ $area ] ?? $area ) . '</h3>', Html::allowed() );
		foreach ( $defs as $key => $d ) {
			$if = $page_if[ $key ] ?? '';
			echo '<div' . ( '' !== $if ? ' data-if="' . esc_attr( $if ) . '"' : '' ) . '><label><input type="checkbox" name="pages[]" value="' . esc_attr( $key ) . '"' . checked( isset( $exist[ $key ] ) || ! empty( $d['default'] ), true, false ) . disabled( isset( $exist[ $key ] ), true, false ) . '> <strong>' . esc_html( $d['title'] ) . '</strong>'
				. ( isset( $exist[ $key ] ) ? ' <em>(già creata)</em>' : '' ) . '</label><br><span class="description" style="margin-left:24px">' . esc_html( $d['hint'] ) . '</span></div>';
		}
		echo '</div>';
		}
		self::end_step();

		self::step( 'Elenco dei soci', 'Hai già un elenco di soci e ospiti (Excel o CSV, anche in un unico file)? Puoi caricarlo subito dopo: si vede un\'anteprima e si conferma prima di salvare. L\'importazione resta sempre disponibile dagli Strumenti.', '', 'optional' );
		echo wp_kses( self::yes_no( 'go_import', 'Vuoi importare ora l\'elenco dei soci e degli ospiti?', false ), Html::allowed() );
		self::end_step();

		echo '<p class="asem-wiz-nav"><span class="asem-wiz-count"></span> <button type="button" class="button" data-wiz="back">← Indietro</button> <button type="button" class="button button-primary" data-wiz="next">Avanti →</button> '
			. '<button type="submit" class="button button-primary" data-wiz="finish">Applica la configurazione</button></p>';
		Ui::form_close();
		Ui::form_open( 'asem_wizard_skip', Ui::url( 'asem' ), false, 'asem-inline' );
		echo '<p><button class="button-link">Salta per ora</button></p>';
		Ui::form_close();
		self::script();
		Ui::footer();
	}

	/** Un passo alla volta, saltando ciò che non serve; i campi nascosti non vengono inviati. */
	private static function script(): void {
		Ui::inline_js( '(function(){var f=document.querySelector("form.asem-wizard");if(!f)return;'
			. 'var steps=[].slice.call(f.querySelectorAll(".asem-wiz-step")),cur=0;f.classList.add("asem-wiz-js");'
			. 'function ok(el){var c=el.getAttribute("data-if");if(!c)return true;var i=c.lastIndexOf("="),n=c.slice(0,i),v=c.slice(i+1);'
			. 'var els=f.querySelectorAll("[name=\\""+n+"\\"]:checked");for(var k=0;k<els.length;k++){if(els[k].value===v&&!els[k].disabled)return true;}return false;}'
			. 'function avail(s){var p=s;while(p&&p!==f){if(p.hasAttribute&&p.hasAttribute("data-if")&&!ok(p))return false;p=p.parentNode;}return true;}'
			. 'function sync(){var b=[].slice.call(f.querySelectorAll("[data-if]"));b.forEach(function(e){var show=avail(e);if(e.classList.contains("asem-wiz-step")){e.setAttribute("data-skip",show?"0":"1");}else{e.style.display=show?"":"none";}'
			. '[].slice.call(e.querySelectorAll("input,select,textarea")).forEach(function(i){if(!i.hasAttribute("data-was"))i.setAttribute("data-was",i.disabled?"1":"0");i.disabled=!show||i.getAttribute("data-was")==="1";});});}'
			. 'function vis(){return steps.filter(function(s){return s.getAttribute("data-skip")!=="1";});}'
			. 'function show(){sync();var v=vis();if(v.indexOf(steps[cur])<0){cur=steps.indexOf(v[0]);}steps.forEach(function(s,i){s.style.display=i===cur?"":"none";});'
			. 'var n=v.indexOf(steps[cur]),g=steps[cur].getAttribute("data-group")||"",W=f.querySelector(".asem-wiz-where"),lis=[].slice.call(f.querySelectorAll(".asem-wiz-stepper li"));'
			. 'var groups=[];v.forEach(function(s){var x=s.getAttribute("data-group");if(x&&x!=="optional"&&groups.indexOf(x)<0)groups.push(x);});'
			. 'var gi=groups.indexOf(g);lis.forEach(function(li){var x=li.getAttribute("data-group"),k=groups.indexOf(x);li.style.display=k<0?"none":"";li.className=(g==="optional"||(gi>=0&&k<gi))?"done":(k===gi?"current":"");});'
			. 'var lab=lis.filter(function(li){return li.getAttribute("data-group")===g;})[0];W.textContent=g==="optional"?"Facoltativo: elenco dei soci":(gi>=0&&lab?"Sezione "+(gi+1)+" di "+groups.length+": "+lab.textContent:"");'
			. 'f.querySelector(".asem-wiz-count").textContent="";'
			. 'f.querySelector("[data-wiz=back]").style.display=n>0?"":"none";f.querySelector("[data-wiz=next]").style.display=n<v.length-1?"":"none";f.querySelector("[data-wiz=finish]").style.display=n===v.length-1?"":"none";}'
			. 'f.addEventListener("change",show);'
			. 'f.querySelector("[data-wiz=next]").addEventListener("click",function(){var v=vis(),n=v.indexOf(steps[cur]);cur=steps.indexOf(v[Math.min(n+1,v.length-1)]);show();window.scrollTo(0,0);});'
			. 'f.querySelector("[data-wiz=back]").addEventListener("click",function(){var v=vis(),n=v.indexOf(steps[cur]);cur=steps.indexOf(v[Math.max(n-1,0)]);show();window.scrollTo(0,0);});'
			. 'show();})();' );
	}
}

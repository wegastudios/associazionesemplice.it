<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\CancelPolicy;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Secrets;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/** Interruttori delle funzioni: chiave delle impostazioni => etichetta. */
	const FEATURES = array(
		'card_enabled'          => 'Tessera digitale nell\'area soci',
		'card_qr_enabled'       => 'QR sulla tessera (verifica all\'ingresso)',
		'ticket_qr_enabled'     => 'Biglietti QR delle prenotazioni',
		'ical_enabled'          => 'Calendario pubblicato (Google Calendar e simili)',
		'rules_enabled'         => 'Regolamento da accettare',
		'reminders_enabled'     => 'Promemoria automatici per email',
	);

	/** Tutti gli interruttori, comprese le funzioni aggiunte da un'estensione (filtro `asem_feature_switches`). */
	public static function all_features(): array {
		return (array) apply_filters( 'asem_feature_switches', self::FEATURES, false );
	}

	/** Interruttori che si mostrano: quelli di un'estensione solo se la funzione è disponibile. */
	public static function visible_features(): array {
		return (array) apply_filters( 'asem_feature_switches', self::FEATURES, true );
	}

	/** Caselle delle funzioni, lette e salvate dal modulo «Generale». */
	private static function feature_boxes( array $s ): string {
		$html = '<input type="hidden" name="features_present" value="1">';
		foreach ( self::visible_features() as $k => $label ) {
			$html .= '<label><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		return $html;
	}


	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Soci e quote' );
		Ui::form_open( 'asem_save_settings', Ui::url( 'asem-settings' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Quota associativa proposta</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €</td></tr>';
		echo wp_kses( '<tr><th>Funzioni attive</th><td>' . self::feature_boxes( $s ) . '<p class="description">Tutte le funzioni facoltative sono spente di default: accendi solo quelle che ti servono, ciò che è spento resta invisibile a soci e gestori. I dettagli tecnici si trovano nelle pagine «Tessera e QR», «Integrazioni» e «Ruoli e accessi».</p></td></tr>', Html::allowed() );
		echo '<tr><th>Sconto nucleo familiare</th><td><input type="number" min="0" max="100" name="family_discount_pct" value="' . (int) $s['family_discount_pct'] . '"> %<p class="description">I familiari di un capofamiglia (si sceglie nella scheda del socio) pagano la quota ridotta di questa percentuale; il capofamiglia paga la quota piena. 0 = nessuno sconto.</p></td></tr>';
		echo '<tr><th>Ospiti</th><td><input type="hidden" name="guests_present" value="1"><label><input type="checkbox" name="guests_enabled" value="1"' . checked( ! empty( $s['guests_enabled'] ), true, false ) . '> L\'ente accetta ospiti (persone che partecipano senza essere soci)</label><p class="description">Se lo spegni non si possono registrare nuovi ospiti e la sezione «I miei ospiti» sparisce dall\'area soci; gli ospiti già registrati restano.</p></td></tr>';
		echo '<tr><th>Eliminazione del plugin</th><td><input type="hidden" name="uninstall_present" value="1"><label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . '> Cancella tutti i dati se il plugin viene eliminato da WordPress</label><p class="description">Spento (consigliato): eliminando il plugin soci, prima nota, allegati e impostazioni restano e tornano disponibili se lo reinstalli. Acceso: vengono cancellati in modo definitivo, ruoli compresi; fai prima una copia di sicurezza.</p></td></tr>';
		echo '<tr><th>Durata tessera socio fondatore</th><td><input type="number" min="1" name="founder_years" value="' . (int) $s['founder_years'] . '"> anni<p class="description">Il socio fondatore ha la tessera sempre rinnovata: la scadenza viene fissata a questo numero di anni dall\'ingresso.</p></td></tr>';
		echo '<tr><th>Ospiti: soglia di segnalazione</th><td><input type="number" min="0" max="20" name="guest_max_events" value="' . (int) $s['guest_max_events'] . '"> partecipazioni<p class="description">Dopo quante partecipazioni a eventi e corsi un non socio viene segnalato come "da invitare a iscriversi" (di solito 1 o 2: oltre, anche per ragioni assicurative, dovrebbe iscriversi). <strong>Non blocca nulla</strong>: evidenzia chi gestisce gli ospiti negli elenchi, nella scheda e all\'ingresso. Le partecipazioni di chi si registra più volte (stesso cellulare, email o nome) si sommano. 0 = nessuna segnalazione.</p></td></tr>';
		echo '<tr><th>Consiglio direttivo</th><td><input type="number" min="0" max="30" name="board_councillors" value="' . (int) $s['board_councillors'] . '"> consiglieri<p class="description">Le cariche sono 1 presidente, 1 vicepresidente e questo numero di consiglieri. Le assegni dalla scheda del socio fondatore o ordinario in regola.</p></td></tr>';
		echo wp_kses( '<tr><th>Pagina area riservata</th><td>' . wp_dropdown_pages(
			array( 'name' => 'member_area_page_id', 'selected' => (int) $s['member_area_page_id'], 'show_option_none' => '— home del sito —', 'option_none_value' => '0', 'echo' => 0 )
		) . '<p class="description">La pagina del sito dove soci e volontari accedono alla propria area (si crea dalla sezione «Pagine del sito e shortcode» qui sotto). Chi ha solo il ruolo "Socio" viene indirizzato qui al posto di wp-admin.</p></td></tr>', Html::allowed() );
		echo '</tbody></table><h2>Eventi: cancellazioni</h2><table class="form-table"><tbody>';
		echo wp_kses( '<tr><th>Termine predefinito per annullare</th><td><select name="cancel_policy_default">' . Ui::options( CancelPolicy::labels(), $s['cancel_policy_default'] ) . '</select>'
			. '<p class="description">Vale per gli eventi creati come «cancellabili» senza un termine proprio. Gli eventi gratuiti si annullano sempre; quelli a pagamento mai, ma si può cambiare nominativo.</p></td></tr>', Html::allowed() );

		echo '</tbody></table><h2>Domanda di adesione online</h2><table class="form-table"><tbody>';
		$pay_on = Plugin::payments()->enabled() && Plugin::payments()->application_online();
		echo '<tr><th>Chi non è ancora socio</th><td><input type="hidden" name="apply_present" value="1"><label><input type="checkbox" name="apply_enabled" value="1"' . checked( ! empty( $s['apply_enabled'] ), true, false ) . '> Si può chiedere di iscriversi dal sito</label>'
			. '<p class="description">Aggiungi la pagina «Diventa socio» (shortcode <code>[associazionesemplice_adesione]</code>, si crea dalla sezione «Pagine del sito» qui sotto). Ogni domanda arriva nella Bacheca e nel riquadro «Da lavorare»: tu scegli <em>Approva</em> (assegni la tessera e al richiedente arriva il link per scegliere la password) o <em>Rifiuta</em>. Chi risulta minorenne dalla data di nascita deve indicare anche il genitore.</p></td></tr>';
		echo '<tr><th>Tipi di socio richiedibili</th><td><label><input type="checkbox" checked disabled> Socio ordinario</label><br><label><input type="checkbox" name="apply_volunteer" value="1"' . checked( ! empty( $s['apply_volunteer'] ), true, false ) . '> Socio e volontario</label></td></tr>';
		echo '<tr><th>Quota con la domanda</th><td><label><input type="checkbox" name="apply_pay_online" value="1"' . checked( ! empty( $s['apply_pay_online'] ), true, false ) . ( $pay_on ? '' : ' disabled' ) . '> Chi chiede di iscriversi può pagare subito la quota online</label>'
			. '<p class="description">' . ( $pay_on ? 'Se la domanda viene rifiutata il pagamento resta segnato «da rimborsare» nei Pagamenti online.' : 'Non c\'è nessun metodo di pagamento online attivo: la domanda dice di pagare la quota in sede.' ) . '</p></td></tr>';
		echo '<tr><th>Avviso alla segreteria</th><td><input type="email" name="apply_email" value="' . esc_attr( (string) $s['apply_email'] ) . '" class="regular-text" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">A questo indirizzo arriva un\'email a ogni nuova domanda. Vuoto = l\'email di amministrazione del sito.</p></td></tr>';
		echo '</tbody></table><h2>Aspetto e messaggi del sito</h2><table class="form-table"><tbody>';
		echo '<tr><th>Logo e colori</th><td><a href="' . esc_url( Ui::url( 'asem-look' ) ) . '">Aspetto →</a><p class="description">Logo, colore principale e secondario di tessera e pulsanti: di default quelli del sito, personalizzabili.</p></td></tr>';
		echo '<tr><th>Invito al pagamento</th><td><textarea name="payment_hint" rows="2" class="large-text">' . esc_textarea( Settings::payment_hint() ) . '</textarea>'
			. '<p class="description">Mostrato ai soci che hanno importi da pagare (finché i pagamenti online non sono attivi).</p></td></tr>';
		echo '<tr><th>Messaggio sui contenuti riservati</th><td><input type="text" name="gate_message" value="' . esc_attr( (string) $s['gate_message'] ) . '" class="large-text" placeholder="Automatico: «Contenuto riservato ai soci.»">'
			. '<p class="description">Se lo compili sostituisce il messaggio automatico mostrato a chi non può vedere un contenuto riservato.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		do_action( 'asem_settings_page_end' ); // le estensioni aggiungono qui le loro sezioni
		echo '<h2>Pagine del sito e shortcode</h2><p>Soci e volontari usano il sito, non wp-admin. Le viste si inseriscono con Gutenberg (blocchi <em>Associazione Semplice</em> e <em>Contenuto riservato</em>), con Elementor (widget <em>Associazione Semplice</em> e <em>Contenuto riservato</em>) oppure con questi shortcode:</p>';
		Ui::form_open( 'asem_create_pages', Ui::url( 'asem-settings' ) );
		echo '<p><button class="button">Crea le pagine standard</button> <span class="description">Area soci, Area volontari (visibile solo ai volontari) e Attività ed eventi, con gli shortcode già inseriti. Puoi poi personalizzarne l\'impaginazione.</span></p>';
		Ui::form_close();
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Shortcode</th><th>Cosa mostra</th></tr></thead><tbody>';
		foreach ( \AssociazioneSemplice\Areas::groups() as $g ) { // per area: soci, segreteria, tesoriere, eventi, pubblico
			echo '<tr><td colspan="2"><strong>' . esc_html( $g[0] ) . '</strong></td></tr>';
			foreach ( $g[1] as $slug ) {
				echo '<tr><td><code>[associazionesemplice_' . esc_html( $slug ) . ']</code></td><td>' . esc_html( \AssociazioneSemplice\Frontend\Shortcodes::views()[ $slug ] ?? '' ) . '</td></tr>';
			}
		}
		echo '<tr><td><code>[associazionesemplice_spese]</code></td><td>Nome precedente di «Area tesoriere»: continua a funzionare</td></tr>';
		echo '<tr><td><code>[associazionesemplice_attivita tipo="evento" anno="2025/2026" date="5"]</code></td><td>Filtri: tipo = corso / evento / ricorrente, anno sociale, date da mostrare</td></tr>';
		echo '<tr><td><code>[associazionesemplice_riservato accesso="soci"]…[/associazionesemplice_riservato]</code></td><td>Parte di pagina visibile solo ai soci (accesso = soci / volontari / attivita, con attivita="12,13")</td></tr>';
		echo '</tbody></table><p class="description">Per riservare una <strong>pagina o un articolo intero</strong> usa il riquadro «Accesso (Associazione Semplice)» nell\'editor: puoi renderlo visibile ai soli soci, ai volontari o agli iscritti a una o più attività (es. il programma della prima lezione).</p>';
		echo '<h2>Informazioni</h2><p>L\'amministrazione è riservata agli utenti con ruolo Amministratore (capability <code>asem_manage</code>). '
			. 'I soci sono utenti WordPress con ruolo "Socio", senza accesso a wp-admin; soci e volontari usano l\'area riservata, che comunica con l\'API REST <code>' . esc_html( rest_url( 'associazionesemplice/v1' ) ) . '</code>.</p>';
		Ui::footer();
	}

	/** Riga di impostazione non segreta di un gateway. */
	public static function gateway_row( string $key, string $label, array $s, string $type, array $options, string $row_class, string $placeholder = '' ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		if ( 'select' === $type ) {
			return $head . '<select name="' . esc_attr( $key ) . '">' . Ui::options( $options, $s[ $key ] ) . '</select></td></tr>';
		}
		return $head . '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $s[ $key ] ) . '" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></td></tr>';
	}

	/** Riga di una chiave segreta: non si mostra mai il valore, solo una maschera; vuoto = non cambiare. */
	public static function secret_row( string $key, string $label, string $row_class, string $hint ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		$has   = Settings::has_secret( $key );
		$plain = Settings::secret( $key );
		$ph    = $has ? ( '' !== $plain ? Secrets::mask( $plain ) . ' (salvata)' : 'salvata ma non leggibile su questo sito (database copiato da un altro sito?): reinseriscila' ) : '';
		return $head . '<input type="password" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $ph ) . '" autocomplete="new-password" class="regular-text"> '
			. ( $has ? '<label><input type="checkbox" name="clear_' . esc_attr( $key ) . '" value="1"> rimuovi</label>' : '' )
			. '<p class="description">Lascia vuoto per non cambiarla.' . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p></td></tr>';
	}
}

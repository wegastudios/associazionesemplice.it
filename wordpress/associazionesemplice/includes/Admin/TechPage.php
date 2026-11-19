<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Tecniche (integrazioni) e Contabilità (opzioni contabili). Solo amministratori. */
final class TechPage {

	// ---------- Integrazioni ----------

	/** Posta in uscita: esito degli ultimi messaggi e prova di invio, per capire perché un'email non parte. */
	private static function mail_section(): void {
		echo '<h2>Posta in uscita</h2><p class="description">Le email del plugin (primo accesso, ricevute, promemoria, avvisi) partono dalla posta del sito. Qui vedi l\'esito degli ultimi invii e l\'errore segnalato dal server. '
			. 'Se risultano partite ma non arrivano, il problema è la consegna: chiedi a chi gestisce il sito di collegarlo a un servizio di posta (plugin SMTP) con un indirizzo del tuo dominio e di controllare i record SPF e DKIM.</p>';
		$rows = \AssociazioneSemplice\MailLog::recent( 10 );
		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Quando</th><th>A</th><th>Messaggio</th><th>Esito</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $r['t'] ) ) . '</td><td>' . esc_html( (string) $r['to'] ) . '</td><td>' . esc_html( (string) $r['what'] ) . '</td><td>'
					. ( ! empty( $r['ok'] ) ? '<span class="asem-ok">partita</span>' : '<span class="asem-neg">non partita</span> <span class="description">' . esc_html( (string) $r['error'] ) . '</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">Nessuna email spedita finora.</p>';
		}
		Ui::form_open( 'asem_mail_test', Ui::url( 'asem-tech' ) );
		echo '<p><button class="button">Invia un\'email di prova al mio indirizzo</button> <span class="description">(' . esc_html( (string) wp_get_current_user()->user_email ) . ')</span></p>';
		Ui::form_close();
	}

	public static function render_integrations(): void {
		Ui::header( 'Integrazioni' );
		do_action( 'asem_integrations_top' ); // le estensioni aggiungono qui le loro sezioni
		self::mail_section();
		echo '<h2>Ricerca dei luoghi con Google</h2>';
		echo '<p class="description">Nei campi «Luogo» di corsi ed eventi compaiono i suggerimenti di Google mentre si scrive. Parte spenta. Serve una chiave API di Google Maps dell\'ente (Google Cloud, servizio «Places API»): '
			. 'limitala ai siti autorizzati dal pannello di Google, perché è visibile nel codice della pagina. Il collegamento con Google avviene solo nelle pagine di amministrazione, dal browser di chi compila il campo; nessun dato dei soci viene inviato.</p>'
			. '<p><a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank" rel="noopener noreferrer">Come ottenere la chiave API di Google Maps (guida di Google, si apre in una nuova finestra) ↗</a></p>';
		Ui::form_open( 'asem_save_places', Ui::url( 'asem-tech' ) );
		echo '<table class="form-table"><tbody><tr><th>Ricerca dei luoghi</th><td><label><input type="checkbox" name="places_enabled" value="1"' . checked( ! empty( Settings::get( 'places_enabled' ) ), true, false ) . '> Accendi i suggerimenti di Google</label></td></tr>'
			. '<tr><th>Chiave API di Google Maps</th><td><input type="text" name="places_key" value="' . esc_attr( (string) Settings::get( 'places_key' ) ) . '" class="regular-text" autocomplete="off" maxlength="100"></td></tr></tbody></table>';
		echo '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<h2>Altre integrazioni</h2><ul>';
		do_action( 'asem_integrations_list' ); // elenco delle altre integrazioni
		echo '<li><strong>QR e calendario</strong>: <a href="' . esc_url( Ui::url( 'asem-card' ) ) . '">Tessera e QR</a>.</li>';
		echo '<li><strong>WP All Import</strong>: ' . ( class_exists( 'PMXI_Plugin' ) ? 'attivo su questo sito' : 'non attivo' ) . ': <a href="' . esc_url( Ui::url( 'asem-wpai' ) ) . '">Importazione con WP All Import</a>.</li>';
		echo '<li><strong>Elementor e Gutenberg</strong>: widget e blocchi «Associazione Semplice» disponibili dove gli editor sono attivi.</li></ul>';
		Ui::footer();
	}

	// ---------- Contabilità ----------

}

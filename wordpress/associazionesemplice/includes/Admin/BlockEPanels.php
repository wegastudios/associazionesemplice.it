<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ConsentRules;
use AssociazioneSemplice\Consents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Newsletter;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Sms;
use AssociazioneSemplice\SmsRules;

defined( 'ABSPATH' ) || exit;

/** Blocco E: SMS e newsletter in Strumenti → Integrazioni, consensi nella scheda della persona. */
final class BlockEPanels {

	public static function register(): void {
		add_action( 'asem_integrations_top', array( __CLASS__, 'integrations' ), 20 );
		add_action( 'asem_person_receipts', array( __CLASS__, 'person_consents' ), 40 );
	}

	/** Nome di un servizio da mostrare, senza usarlo davvero. */
	private static function provider_label( string $class ): string {
		return ( new $class( '__return_null', array() ) )->label();
	}

	/** Campi di un elenco di servizi nel modulo: ogni gruppo si mostra solo se il servizio è quello scelto. */
	private static function fields_rows( array $providers, array $s, string $prefix ): string {
		$html = '';
		foreach ( $providers as $id => $class ) {
			foreach ( $class::fields() as $key => $label ) {
				$cls   = $prefix . '-' . $id;
				$html .= in_array( $key, $class::secret_fields(), true ) ? SettingsPage::secret_row( $key, $label, $cls, '' ) : SettingsPage::gateway_row( $key, $label, $s, 'text', array(), $cls, '' );
			}
		}
		return $html;
	}

	private static function toggle_js( string $select_id, string $prefix, array $ids ): void {
		Ui::inline_js( '(function(){var s=document.getElementById(' . wp_json_encode( $select_id ) . ');if(!s){return;}var ids=' . wp_json_encode( array_values( $ids ) ) . ';var f=function(){ids.forEach(function(i){document.querySelectorAll(".' . $prefix . '-"+i).forEach(function(r){r.style.display=s.value===i?"":"none";});});};s.addEventListener("change",f);f();})();' );
	}

	public static function integrations(): void {
		if ( Edition::has( 'sms' ) ) {
			self::sms_section();
		}
		if ( Edition::has( 'newsletter' ) ) {
			self::newsletter_section();
		}
	}

	// ---------- SMS ----------

	private static function sms_section(): void {
		$s         = Settings::all();
		$providers = Sms::providers();
		$choices   = array( '' => 'Nessuno (SMS spenti)' );
		foreach ( $providers as $id => $class ) {
			$choices[ $id ] = self::provider_label( $class );
		}
		echo '<h2>SMS ai soci</h2><p class="description">Manda SMS dalle Comunicazioni (in aggiunta all\'email o al suo posto) e, se vuoi, come copia breve dei promemoria. Si scrive solo a chi ha un numero di cellulare valido e ha dato il consenso agli SMS (lo registri nella scheda della persona; il socio può darlo e revocarlo dalla sua area). Il costo degli SMS è quello del fornitore che scegli.</p>';
		Ui::form_open( 'asem_save_sms', Ui::url( 'asem-tech' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Fornitore</th><td><select name="sms_provider" id="asem-sms-provider">' . Ui::options( $choices, (string) $s['sms_provider'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::fields_rows( $providers, $s, 'asem-sms' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Mittente</th><td><input type="text" name="sms_sender" value="' . esc_attr( (string) $s['sms_sender'] ) . '" class="regular-text" maxlength="15" placeholder="' . esc_attr( Sms::sender() ) . '"><p class="description">Fino a 11 lettere o cifre (senza simboli) oppure un numero. Vuoto = il nome dell\'associazione, accorciato. Alcuni paesi e fornitori richiedono di registrare il mittente.</p></td></tr>';
		echo '<tr><th>Promemoria</th><td><label><input type="checkbox" name="sms_reminders" value="1"' . checked( ! empty( $s['sms_reminders'] ), true, false ) . '> Manda anche per SMS gli avvisi automatici di servizio (promemoria di scadenza e rientro, avvisi sulle attività e lista d\'attesa)</label><p class="description">Solo a chi ha dato il consenso. Con i promemoria spenti non cambia nulla.</p></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		self::toggle_js( 'asem-sms-provider', 'asem-sms', array_keys( $providers ) );

		$ready = Sms::configured();
		if ( $ready ) {
			$use = Sms::month_usage();
			$c   = Sms::counts();
			echo '<p>';
			Ui::form_open( 'asem_test_sms', Ui::url( 'asem-tech' ), false, 'asem-inline' );
			echo '<button class="button">Verifica il collegamento e i crediti</button>';
			Ui::form_close();
			$credits = Sms::credits();
			echo ' <span class="description">' . ( null !== $credits ? 'Crediti o saldo residui: ' . esc_html( $credits ) . '. ' : '' ) . 'Questo mese: ' . (int) $use['sms'] . ' SMS inviati' . ( $use['parts'] > $use['sms'] ? ' (' . (int) $use['parts'] . ' messaggi contando quelli lunghi)' : '' ) . '. In coda: ' . (int) ( $c['queued'] + $c['sending'] ) . '.</span></p>';
			if ( $c['failed'] > 0 ) {
				Ui::form_open( 'asem_sms_retry', Ui::url( 'asem-tech' ), false, 'asem-inline' );
				echo '<p class="asem-warn">' . (int) $c['failed'] . ' SMS non sono partiti. <button class="button">Riprova tutti</button></p>';
				Ui::form_close();
			}
		} else {
			echo '<p class="description">Scegli un fornitore e inserisci le sue chiavi per attivare gli SMS.</p>';
		}
		$log = Sms::recent( 30 );
		if ( $log ) {
			$labels = array( 'sent' => 'Inviato', 'queued' => 'In coda', 'sending' => 'In invio', 'failed' => 'Non partito', 'skipped' => 'Saltato' );
			echo '<h3>Ultimi SMS</h3><table class="widefat striped"><thead><tr><th>Data</th><th>Destinatario</th><th>Tipo</th><th>Stato</th><th>Tentativi</th><th>Note</th></tr></thead><tbody>';
			foreach ( $log as $r ) {
				echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', (string) ( $r['sent_at'] ?: $r['created_at'] ) ) ) . '</td><td>' . esc_html( (string) $r['name'] ) . ' <span class="description">' . esc_html( SmsRules::mask( (string) $r['phone'] ) ) . '</span></td>'
					. '<td>' . esc_html( array( 'broadcast' => 'Comunicazione', 'reminder' => 'Promemoria' )[ $r['kind'] ] ?? (string) $r['kind'] ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? (string) $r['status'] ) . '</td><td>' . (int) $r['attempts'] . '</td><td>' . esc_html( (string) $r['error'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	// ---------- Newsletter ----------

	private static function newsletter_section(): void {
		$s         = Settings::all();
		$providers = Newsletter::providers();
		$choices   = array( '' => 'Nessuno (newsletter spenta)' );
		foreach ( $providers as $id => $class ) {
			$choices[ $id ] = self::provider_label( $class );
		}
		echo '<h2>Newsletter</h2><p class="description">Allinea i soci con una lista del tuo servizio di newsletter (nome, email, tipo di socio, stato della tessera). <strong>Si iscrive solo chi ha dato il consenso alla newsletter</strong>; chi lo revoca, cambia indirizzo o viene anonimizzato esce dalla lista. Nessun dato viene inviato senza consenso. Chi si è disiscritto dal servizio non viene mai reiscritto.</p>';
		Ui::form_open( 'asem_save_newsletter', Ui::url( 'asem-tech' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Servizio</th><td><select name="newsletter_provider" id="asem-nl-provider">' . Ui::options( $choices, (string) $s['newsletter_provider'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::fields_rows( $providers, $s, 'asem-nl' ); // phpcs:ignore WordPress.Security.EscapeOutput
		$lists = Newsletter::known_lists();
		if ( '' !== (string) $s['newsletter_list'] && ! isset( $lists[ (string) $s['newsletter_list'] ] ) ) {
			$lists[ (string) $s['newsletter_list'] ] = 'Lista n. ' . $s['newsletter_list'];
		}
		echo '<tr><th>Lista o pubblico</th><td>' . ( $lists ? '<select name="newsletter_list">' . Ui::options( $lists, (string) $s['newsletter_list'], '— scegli —' ) . '</select>' : '<span class="description">Salva le chiavi e premi «Leggi le liste» qui sotto.</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Automatico</th><td><label><input type="checkbox" name="newsletter_auto" value="1"' . checked( ! empty( $s['newsletter_auto'] ), true, false ) . '> Sincronizza da solo ogni giorno</label></td></tr>';
		echo '<tr><th>Tipo e tessera</th><td><label><input type="checkbox" name="newsletter_extra" value="1"' . checked( ! empty( $s['newsletter_extra'] ), true, false ) . '> Invia anche tipo di socio e stato della tessera</label><p class="description">Brevo: servono due campi di testo creati in Brevo, <code>TIPO_SOCIO</code> e <code>STATO_TESSERA</code>. Mailchimp: arrivano come etichette. MailPoet: non supportato.</p></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		self::toggle_js( 'asem-nl-provider', 'asem-nl', array_keys( $providers ) );

		echo '<p style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( array( 'asem_test_newsletter' => 'Verifica il collegamento', 'asem_newsletter_lists' => 'Leggi le liste' ) as $action => $label ) {
			Ui::form_open( $action, Ui::url( 'asem-tech' ), false, 'asem-inline' );
			echo '<button class="button">' . esc_html( $label ) . '</button>';
			Ui::form_close();
		}
		if ( Newsletter::configured() ) {
			Ui::form_open( 'asem_newsletter_sync', Ui::url( 'asem-tech' ), false, 'asem-inline' );
			echo '<button class="button button-primary">Sincronizza adesso</button>';
			Ui::form_close();
		}
		echo '</p>';
		$st = Newsletter::status();
		if ( Newsletter::configured() ) {
			echo '<p class="description">Iscritti sincronizzati: ' . (int) $st['subscribed'] . ( $st['errors'] ? ' · <span class="asem-warn">' . (int) $st['errors'] . ' con errore (si riprova al prossimo passaggio)</span>' : '' )
				. ( $st['last'] ? ' · ultima sincronizzazione: ' . esc_html( wp_date( 'd/m/Y H:i', (int) $st['last']['t'] ) ) : '' ) . '.</p>';
		}
	}

	// ---------- Consensi nella scheda della persona ----------

	public static function person_consents( array $p ): void {
		$channels = array();
		foreach ( ConsentRules::channels() as $ch => $label ) {
			if ( Edition::has( ConsentRules::SMS === $ch ? 'sms' : 'newsletter' ) ) {
				$channels[ $ch ] = $label;
			}
		}
		if ( ! $channels || ! empty( $p['anonymized_at'] ) ) {
			return;
		}
		$id = (int) $p['id'];
		echo '<div class="asem-card"><h2>Consensi SMS e newsletter</h2><p class="description">Senza il consenso non parte nessun SMS e nessun dato va al servizio di newsletter. Registra il consenso solo se la persona l\'ha dato (modulo, a voce); la persona può darlo e revocarlo anche dalla sua area.</p>';
		foreach ( $channels as $ch => $label ) {
			$row = Consents::get( $id, $ch );
			$on  = ConsentRules::is_granted( $row );
			echo '<p><strong>' . esc_html( $label ) . '</strong>: ';
			if ( $on ) {
				echo '<span class="asem-ok">consenso dato</span> il ' . esc_html( mysql2date( 'd/m/Y', (string) $row['granted_at'] ) ) . ' (' . esc_html( ConsentRules::SOURCES[ (string) $row['source'] ] ?? '—' ) . ') ';
			} elseif ( $row && ! empty( $row['revoked_at'] ) ) {
				echo 'consenso revocato il ' . esc_html( mysql2date( 'd/m/Y', (string) $row['revoked_at'] ) ) . ' ';
			} else {
				echo 'nessun consenso ';
			}
			Ui::form_open( 'asem_consent_set', Ui::url( 'asem-person', array( 'id' => $id ) ), false, 'asem-inline' );
			echo Ui::hidden( 'id', $id ) . Ui::hidden( 'channel', $ch ) . Ui::hidden( 'grant', $on ? '0' : '1' ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! $on ) {
				echo '<select name="source">' . Ui::options( array( 'paper' => 'Modulo cartaceo', 'oral' => 'A voce' ), 'paper' ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<button class="button button-small"' . ( $on ? ' data-confirm="Revocare il consenso?"' : '' ) . '>' . ( $on ? 'Revoca' : 'Registra il consenso' ) . '</button>';
			Ui::form_close();
			echo '</p>';
		}
		echo '</div>';
	}
}

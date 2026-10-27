<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\CertificateRules;
use AssociazioneSemplice\Certificates;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpiryRules;
use AssociazioneSemplice\MemberQuery;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Certificati medici sportivi: elenco con filtri, scheda nella persona, riga «richiede il certificato» nelle attività e avviso in Bacheca. */
final class CertificatesPage {

	const CLASSES = array( ExpiryRules::VALID => 'asem-ok', ExpiryRules::EXPIRING => 'asem-warn', ExpiryRules::EXPIRED => 'asem-neg', ExpiryRules::NONE => 'asem-neg' );

	private static function badge( string $status ): string {
		return '<strong class="' . esc_attr( self::CLASSES[ $status ] ) . '">' . esc_html( ExpiryRules::labels()[ $status ] ) . '</strong>';
	}

	private static function date( ?string $ymd ): string {
		return $ymd ? esc_html( ExpiryRules::it_date( $ymd ) ) : '—';
	}

	public static function render(): void {
		if ( ! Certificates::can_manage() ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$filter = Ui::get_str( 'filter' );
		$filter = in_array( $filter, array( ExpiryRules::VALID, ExpiryRules::EXPIRING, ExpiryRules::EXPIRED, ExpiryRules::NONE ), true ) ? $filter : '';
		$act    = Ui::get_int( 'activity' );
		$all    = '1' === Ui::get_str( 'all' );
		Ui::header( 'Certificati medici' );
		echo '<p class="description">Il certificato medico sportivo dei soci: tipo, date di rilascio e di scadenza, medico o struttura e il file. È un dato sanitario: lo vede solo lo staff e il registro delle azioni ricorda soltanto che è stato aperto, mai cosa contiene. '
			. 'Per ogni attività si può indicare che richiede il certificato (nel modulo dell\'attività): qui compaiono i suoi iscritti, con chi è scaduto, in scadenza o senza certificato.</p>';

		$counts = Certificates::counts();
		echo '<p>';
		foreach ( array( ExpiryRules::EXPIRED, ExpiryRules::EXPIRING, ExpiryRules::NONE, ExpiryRules::VALID ) as $s ) {
			echo '<a class="button" href="' . esc_url( Ui::url( 'asem-medical', array( 'filter' => $s ) ) ) . '">' . self::badge( $s ) . ' · ' . (int) $counts[ $s ] . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
		echo '</p>';

		$activities = array();
		foreach ( Certificates::required_ids() as $id ) {
			$a = Plugin::activities()->get( $id );
			if ( $a ) {
				$activities[ $id ] = $a['name'] . ' (' . $a['social_year'] . ')';
			}
		}
		$args = array( 'filter' => $filter, 'activity' => $act, 'all' => $all ? 1 : '' );
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-medical">'
			. '<select name="filter">' . Ui::options( ExpiryRules::labels(), $filter, 'Tutti gli stati' ) . '</select> '
			. '<select name="activity">' . Ui::options( $activities, $act ?: null, 'Tutte le attività che lo richiedono' ) . '</select> '
			. '<label><input type="checkbox" name="all" value="1"' . checked( $all, true, false ) . '> tutti i soci attivi, anche senza attività che lo richiedono</label> '
			. '<button class="button">Filtra</button> <a class="button" href="' . esc_url( BlockCExports::url( 'asem_cert_csv', $args ) ) . '">Esporta (CSV)</a></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto

		$rows = Certificates::rows( $filter, $act, $all );
		echo '<table class="widefat striped"><thead><tr><th>Socio</th><th>Stato</th><th>Tipo</th><th>Scade il</th><th>Giorni</th><th>Attività che lo richiedono</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . ( $activities || $all ? 'Nessun socio con questi criteri.' : 'Nessuna attività richiede il certificato: attivalo nel modulo dell\'attività (riga «Certificato medico»), oppure scegli «tutti i soci attivi».' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$p = $r['person'];
			$c = $r['cert'];
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $p['id'] ) ) ) . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</a></td>'
				. '<td>' . self::badge( $r['status'] ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
				. '<td>' . ( $c ? esc_html( CertificateRules::kinds()[ $c['kind'] ] ?? $c['kind'] ) : '—' ) . '</td>'
				. '<td>' . self::date( $c ? (string) $c['expires_on'] : null ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . ( null === $r['days_left'] ? '—' : (int) $r['days_left'] ) . '</td>'
				. '<td>' . esc_html( implode( ', ', $r['activities'] ) ) . '</td>'
				. '<td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $p['id'] ) ) ) . '#asem-medical">Apri la scheda</a></td></tr>';
		}
		echo '</tbody></table>';

		if ( current_user_can( Plugin::CAP ) ) {
			self::settings_card();
		}
		Ui::footer();
	}

	/** Impostazioni (amministratore): promemoria, giorni di preavviso, scadenza visibile al socio. */
	private static function settings_card(): void {
		echo '<div class="asem-card"><h2>Impostazioni</h2>';
		Ui::form_open( 'asem_save_cert_settings', Ui::url( 'asem-medical' ) );
		echo '<table class="form-table"><tr><th>Preavviso</th><td><input type="number" name="cert_warn_days" min="1" max="365" style="width:80px" value="' . (int) Certificates::warn_days() . '"> giorni prima della scadenza il certificato risulta «In scadenza».</td></tr>'
			. '<tr><th>Promemoria automatici</th><td><label><input type="checkbox" name="cert_reminders" value="1"' . checked( ! empty( Settings::get( 'cert_reminders' ) ), true, false ) . '> manda un\'email al socio (o al suo tutore) quando il certificato sta per scadere o è scaduto da poco, e un\'unica email all\'indirizzo degli avvisi della segreteria con l\'elenco</label>'
			. '<p class="description">Una sola volta per ogni certificato e tipo di avviso. Partono una volta al giorno, solo per gli iscritti alle attività che richiedono il certificato.</p></td></tr>'
			. '<tr><th>Area soci</th><td><label><input type="checkbox" name="cert_show_member" value="1"' . checked( ! empty( Settings::get( 'cert_show_member' ) ), true, false ) . '> il socio vede nella sua area la scadenza del proprio certificato</label>'
			. '<p class="description">Mostra solo tipo e scadenza, mai il file né il medico.</p></td></tr></table>'
			. '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Scheda nella pagina di una persona: stato, certificati, nuovo certificato. */
	public static function person_panel( array $p ): void {
		if ( ! Edition::has( 'medical' ) || ! Certificates::can_manage() || ! MemberType::is_member( (string) $p['type'] ) || ! MemberQuery::find( (int) $p['id'] ) ) { // un socio sospeso o uscito non ha il modulo: il salvataggio lo rifiuterebbe
			return;
		}
		$id    = (int) $p['id'];
		$back  = Ui::url( 'asem-person', array( 'id' => $id ) );
		$st    = Certificates::status_of( $id );
		$certs = Certificates::for_person( $id );
		echo '<div class="asem-card" id="asem-medical"><h2>Certificato medico</h2>';
		echo '<p>Stato: ' . self::badge( $st['status'] ) . ( $st['cert'] ? ' · scade il ' . self::date( (string) $st['cert']['expires_on'] ) : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		if ( $certs ) {
			echo '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Rilasciato il</th><th>Scade il</th><th>Medico o struttura</th><th>File</th><th></th></tr></thead><tbody>';
			foreach ( $certs as $c ) {
				echo '<tr><td>' . esc_html( CertificateRules::kinds()[ $c['kind'] ] ?? $c['kind'] ) . '</td><td>' . self::date( (string) $c['issued_on'] ) . '</td><td>' . self::date( (string) $c['expires_on'] ) . '</td><td>' . esc_html( (string) $c['doctor'] ) . '</td><td>' // phpcs:ignore WordPress.Security.EscapeOutput
					. ( ! empty( $c['file_stored'] ) ? '<a href="' . esc_url( Certificates::file_url( (int) $c['id'] ) ) . '">Scarica</a>' : '—' ) . '</td><td>';
				Ui::form_open( 'asem_cert_delete', $back, false, 'asem-inline' );
				echo Ui::hidden( 'id', $c['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questo certificato (e il file allegato)?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Ui::form_open( 'asem_cert_add', $back, true );
		echo Ui::hidden( 'person_id', $id ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<h3>Registra un certificato</h3><p><select name="kind">' . Ui::options( CertificateRules::kinds(), CertificateRules::NON_AGONISTIC ) . '</select> '
			. 'rilasciato il <input type="date" name="issued_on" value="' . esc_attr( Db::today() ) . '" required> scade il <input type="date" name="expires_on" value="' . esc_attr( ExpiryRules::plus_year( Db::today() ) ) . '" required></p>'
			. '<p><input type="text" name="doctor" class="regular-text" maxlength="190" placeholder="Medico o struttura (facoltativo)"> <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></p>'
			. '<p class="description">Il file (PDF o foto) resta in una cartella privata e lo scarica solo lo staff. La scadenza proposta è un anno dopo il rilascio: correggila con quella scritta sul certificato.</p>'
			. '<p><button class="button button-primary">Registra il certificato</button></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Riga del modulo di un'attività: richiede il certificato medico. */
	public static function activity_row( ?int $activity_id ): string {
		$on = $activity_id && Certificates::is_required( $activity_id );
		return '<tr><th>Certificato medico</th><td><input type="hidden" name="cert_required_present" value="1"><label><input type="checkbox" name="cert_required" value="1"' . checked( (bool) $on, true, false ) . '> richiede il certificato medico sportivo</label>'
			. '<p class="description">Gli iscritti compaiono nell\'elenco <em>Registri → Certificati medici</em>, con chi è scaduto, in scadenza o senza certificato.</p></td></tr>';
	}

	/** Avviso in Bacheca per lo staff. */
	public static function alert(): void {
		if ( ! Edition::has( 'medical' ) || ! Certificates::can_manage() ) {
			return;
		}
		$n   = Certificates::counts();
		$bad = $n[ ExpiryRules::EXPIRED ] + $n[ ExpiryRules::NONE ];
		if ( $bad + $n[ ExpiryRules::EXPIRING ] > 0 ) {
			echo Dismiss::html( 'cert-' . $bad . '-' . $n[ ExpiryRules::EXPIRING ], 'warning', 'Certificati medici: ' . (int) $n[ ExpiryRules::EXPIRED ] . ' scaduti, ' . (int) $n[ ExpiryRules::NONE ] . ' mancanti e ' . (int) $n[ ExpiryRules::EXPIRING ] . ' in scadenza tra gli iscritti alle attività che li richiedono. <a href="' . esc_url( Ui::url( 'asem-medical' ) ) . '">Apri l\'elenco</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
	}
}

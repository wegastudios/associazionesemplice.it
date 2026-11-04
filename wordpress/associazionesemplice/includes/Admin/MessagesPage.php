<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Broadcasts;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Rubrica soci → Comunicazioni: email a gruppi di persone. */
final class MessagesPage {

	/** Gruppo scelto nel modulo ("members_active" oppure "activity:12" / "session:34") => [gruppo, id]. */
	public static function parse_audience( string $v ): array {
		if ( preg_match( '/^(activity|session):(\d+)$/', $v, $m ) ) {
			return array( $m[1], (int) $m[2] );
		}
		return array( $v, 0 );
	}

	private static function audience_options( string $selected ): string {
		$html = '<optgroup label="Soci e persone">';
		foreach ( Broadcasts::audiences() as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $selected, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</optgroup><optgroup label="Chi partecipa a un corso o a un evento">';
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$html .= '<option value="activity:' . (int) $a['id'] . '"' . selected( $selected, 'activity:' . (int) $a['id'], false ) . '>' . esc_html( $a['name'] . ' (' . $a['social_year'] . ')' ) . '</option>';
		}
		$html .= '</optgroup><optgroup label="Prenotati a una data">';
		foreach ( Plugin::activities()->upcoming_sessions( 60 ) as $s ) {
			$html .= '<option value="session:' . (int) $s['id'] . '"' . selected( $selected, 'session:' . (int) $s['id'], false ) . '>' . esc_html( $s['activity_name'] . ' · ' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) ) . '</option>';
		}
		return $html . '</optgroup>';
	}

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view ) {
			self::render_detail( $view );
			return;
		}
		$aud     = Ui::get_str( 'audience', 'members_active' );
		$subject = Ui::get_str( 'subject' );
		$body    = isset( $_GET['body'] ) ? sanitize_textarea_field( wp_unslash( $_GET['body'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$preview = '1' === Ui::get_str( 'preview' );
		$sms_on  = \AssociazioneSemplice\Edition::has( 'sms' ) && \AssociazioneSemplice\Sms::available();
		$chosen  = isset( $_GET['channels'] ) ? array_values( array_intersect( array( 'email', 'sms' ), array_map( 'sanitize_key', (array) wp_unslash( $_GET['channels'] ) ) ) ) : array( 'email' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $sms_on ) {
			$chosen = array( 'email' );
		}
		Ui::header( 'Comunicazioni' );
		echo '<p class="description">Un messaggio email a un gruppo di persone: ognuno riceve la propria email, senza vedere gli indirizzi degli altri. Nel testo puoi usare <code>{nome}</code> e <code>{associazione}</code>. '
			. 'Chi non ha un\'email (un ospite) riceve il messaggio tramite il socio che lo ospita. Se i destinatari sono molti l\'invio continua da solo in background.</p>';
		echo '<form method="get" class="asem-form"><input type="hidden" name="page" value="asem-messages"><input type="hidden" name="preview" value="1">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Canali</th><td><label><input type="checkbox" name="channels[]" value="email"' . checked( in_array( 'email', $chosen, true ), true, false ) . '> Email</label> '
			. '<label><input type="checkbox" name="channels[]" value="sms"' . checked( in_array( 'sms', $chosen, true ), true, false ) . disabled( $sms_on, false, false ) . '> SMS</label>'
			. ( $sms_on ? '<p class="description">Gli SMS partono solo a chi ha un numero valido e ha dato il consenso agli SMS; massimo ' . (int) \AssociazioneSemplice\SmsRules::MAX_CHARS . ' caratteri. Per i soli SMS l\'oggetto si può lasciare vuoto.</p>' : '<p class="description">Gli SMS non sono attivi: scegli un fornitore in <a href="' . esc_url( Ui::url( 'asem-tech' ) ) . '">Strumenti → Integrazioni</a>.</p>' ) . '</td></tr>';
		echo '<tr><th>A chi</th><td><select name="audience">' . self::audience_options( $aud ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Oggetto</th><td><input type="text" name="subject" value="' . esc_attr( $subject ) . '" class="large-text" maxlength="' . (int) Broadcasts::MAX_SUBJ . '"></td></tr>';
		echo '<tr><th>Messaggio</th><td><textarea name="body" rows="10" class="large-text" maxlength="' . (int) Broadcasts::MAX_BODY . '">' . esc_textarea( $body ) . '</textarea></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Anteprima dei destinatari</button></p></form>';

		if ( $preview ) {
			list( $g, $ref ) = self::parse_audience( $aud );
			$r   = null;
			$sms = null;
			try {
				$r   = in_array( 'email', $chosen, true ) ? Broadcasts::recipients( $g, $ref ) : array( 'list' => array(), 'no_email' => 0 );
				$sms = in_array( 'sms', $chosen, true ) ? Broadcasts::sms_recipients( $g, $ref ) : array( 'list' => array(), 'no_phone' => 0, 'no_consent' => 0 );
			} catch ( \InvalidArgumentException $e ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
				$r = null;
			}
			if ( $r ) {
				$n  = count( $r['list'] );
				$ns = count( $sms['list'] );
				echo '<div class="asem-card"><h2>Destinatari: ' . (int) max( $n, $ns ) . '</h2>';
				if ( in_array( 'email', $chosen, true ) ) {
					echo '<p><strong>Email: ' . (int) $n . '</strong>';
					if ( $r['no_email'] ) {
						echo ' <span class="description">' . (int) $r['no_email'] . ' persone del gruppo non hanno un indirizzo email raggiungibile e non riceveranno il messaggio.</span>';
					}
					echo '</p>';
				}
				if ( in_array( 'sms', $chosen, true ) ) {
					echo '<p><strong>SMS: ' . (int) $ns . '</strong>';
					if ( $sms['no_consent'] || $sms['no_phone'] ) {
						echo ' <span class="description">' . (int) $sms['no_consent'] . ' non hanno dato il consenso agli SMS, ' . (int) $sms['no_phone'] . ' non hanno un numero di cellulare valido.</span>';
					}
					$m = \AssociazioneSemplice\SmsRules::measure( $body );
					if ( $m['parts'] > 0 ) {
						echo '<br><span class="description">Il testo occupa ' . (int) $m['parts'] . ( 1 === $m['parts'] ? ' SMS' : ' SMS' ) . ' per destinatario (' . (int) $m['units'] . ' caratteri, ' . esc_html( $m['encoding'] ) . '). I crediti consumati sono quelli del fornitore.</span>';
					}
					echo '</p>';
				}
				if ( $n || $ns ) {
					$names = array_merge( $r['list'], array_filter( $sms['list'], function ( $x ) use ( $r ) {
						return ! in_array( $x['person_id'], array_column( $r['list'], 'person_id' ), true );
					} ) );
					echo '<p>' . esc_html( implode( ', ', array_map( function ( $x ) {
						return $x['name'];
					}, array_slice( $names, 0, 40 ) ) ) ) . ( count( $names ) > 40 ? ' … e altri ' . (int) ( count( $names ) - 40 ) : '' ) . '</p>';
					$fields = Ui::hidden( 'audience', $aud ) . Ui::hidden( 'subject', $subject ) . '<textarea name="body" hidden>' . esc_textarea( $body ) . '</textarea>';
					foreach ( $chosen as $c ) {
						$fields .= Ui::hidden( 'channels[]', $c );
					}
					Ui::form_open( 'asem_broadcast_send', Ui::url( 'asem-messages' ), false, 'asem-inline' );
					echo $fields . '<button class="button button-primary" data-confirm="Inviare il messaggio a ' . (int) max( $n, $ns, count( $names ) ) . ' persone?">Invia a ' . (int) count( $names ) . ' persone</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					if ( in_array( 'email', $chosen, true ) && $n ) {
						echo ' ';
						Ui::form_open( 'asem_broadcast_test', Ui::url( 'asem-messages', array( 'audience' => $aud, 'subject' => $subject, 'body' => $body, 'preview' => 1 ) ), false, 'asem-inline' );
						echo Ui::hidden( 'subject', $subject ) . '<textarea name="body" hidden>' . esc_textarea( $body ) . '</textarea><button class="button">Invia una prova solo a me</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
						Ui::form_close();
					}
				} else {
					echo '<p>Nessun destinatario raggiungibile con i canali scelti.</p>';
				}
				echo '</div>';
			}
		}

		$recent = Broadcasts::recent();
		echo '<h2>Storico</h2>';
		if ( ! $recent ) {
			echo '<p class="description">Nessuna comunicazione inviata finora.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Oggetto</th><th>A chi</th><th>Inviate</th><th>Stato</th></tr></thead><tbody>';
			foreach ( $recent as $b ) {
				echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $b['created_at'] ) ) . '</td><td><a href="' . esc_url( Ui::url( 'asem-messages', array( 'view' => (int) $b['id'] ) ) ) . '">' . esc_html( $b['subject'] ) . '</a></td><td>' . esc_html( Broadcasts::audience_label( $b ) ) . '</td>'
					. '<td>' . (int) $b['sent'] . ' / ' . (int) $b['total'] . ( (int) $b['failed'] ? ' <span class="asem-neg">(' . (int) $b['failed'] . ' non partite)</span>' : '' ) . self::sms_summary( (int) $b['id'] ) . '</td>'
					. '<td>' . ( 'done' === $b['status'] ? 'Completato' : '<span class="asem-warn">In invio…</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Ui::footer();
	}

	/** «SMS: 12 / 14» accanto al conteggio delle email, se la comunicazione ne ha. */
	private static function sms_summary( int $id ): string {
		if ( ! \AssociazioneSemplice\Edition::installed( 'sms' ) ) {
			return '';
		}
		$c     = \AssociazioneSemplice\Sms::counts( $id );
		$total = array_sum( $c );
		if ( 0 === $total ) {
			return '';
		}
		return '<br>SMS: ' . (int) $c['sent'] . ' / ' . (int) $total . ( $c['failed'] ? ' <span class="asem-neg">(' . (int) $c['failed'] . ' non partiti)</span>' : '' ) . ( $c['queued'] + $c['sending'] ? ' <span class="asem-warn">(' . (int) ( $c['queued'] + $c['sending'] ) . ' in coda)</span>' : '' );
	}

	/** Gli SMS di una comunicazione: stato, tentativi e, per quelli falliti, il pulsante per riprovare. */
	private static function sms_detail( int $id ): void {
		if ( ! \AssociazioneSemplice\Edition::installed( 'sms' ) ) {
			return;
		}
		$c = \AssociazioneSemplice\Sms::counts( $id );
		if ( 0 === array_sum( $c ) ) {
			return;
		}
		echo '<h3>SMS</h3>';
		if ( $c['failed'] > 0 ) {
			Ui::form_open( 'asem_sms_retry', Ui::url( 'asem-messages', array( 'view' => $id ) ), false, 'asem-inline' );
			echo Ui::hidden( 'broadcast_id', $id ) . '<p><button class="button">Riprova i ' . (int) $c['failed'] . ' SMS non partiti</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		$labels = array( 'sent' => 'Inviato', 'failed' => 'Non partito', 'queued' => 'In coda', 'sending' => 'In invio', 'skipped' => 'Saltato' );
		echo '<table class="widefat striped"><thead><tr><th>Destinatario</th><th>Numero</th><th>Stato</th><th>Tentativi</th><th>Note</th></tr></thead><tbody>';
		foreach ( \AssociazioneSemplice\Sms::recent( 500, $id ) as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['name'] ) . '</td><td>' . esc_html( \AssociazioneSemplice\SmsRules::mask( (string) $r['phone'] ) ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? (string) $r['status'] ) . '</td><td>' . (int) $r['attempts'] . '</td><td>' . esc_html( (string) $r['error'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_detail( int $id ): void {
		$b = Broadcasts::get( $id );
		Ui::header( 'Comunicazione' );
		if ( ! $b ) {
			echo '<p>Comunicazione non trovata.</p>';
			Ui::footer();
			return;
		}
		echo '<p><a href="' . esc_url( Ui::url( 'asem-messages' ) ) . '">← Tutte le comunicazioni</a></p>';
		echo '<h2>' . esc_html( $b['subject'] ) . '</h2><p class="description">' . esc_html( mysql2date( 'd/m/Y H:i', $b['created_at'] ) ) . ' · ' . esc_html( Broadcasts::audience_label( $b ) ) . ' · '
			. (int) $b['sent'] . ' inviate su ' . (int) $b['total'] . '</p>';
		echo '<div class="asem-card" style="white-space:pre-wrap">' . esc_html( $b['body'] ) . '</div>';
		if ( (int) $b['failed'] > 0 ) {
			Ui::form_open( 'asem_broadcast_retry', Ui::url( 'asem-messages', array( 'view' => $id ) ), false, 'asem-inline' );
			echo Ui::hidden( 'id', $id ) . '<p><button class="button">Riprova le ' . (int) $b['failed'] . ' non partite</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<table class="widefat striped"><thead><tr><th>Destinatario</th><th>Email</th><th>Stato</th></tr></thead><tbody>';
		$labels = array( 'sent' => 'Inviata', 'failed' => 'Non partita', 'queued' => 'In coda' );
		foreach ( Broadcasts::recipients_of( $id ) as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::sms_detail( $id );
		Ui::footer();
	}
}

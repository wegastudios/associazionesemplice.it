<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\RefundRules;
use AssociazioneSemplice\Refunds;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Cassa → Rimborsi: richieste dei volontari, approvazione, pagamento con registrazione in prima nota, impostazioni. */
final class RefundsPage {

	const CLASSES = array(
		RefundRules::REQUESTED => 'asem-warn',
		RefundRules::APPROVED  => 'asem-warn',
		RefundRules::PAID      => 'asem-ok',
		RefundRules::REJECTED  => 'asem-neg',
		RefundRules::CANCELLED => '',
	);

	public static function render(): void {
		if ( ! Refunds::can_manage() ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$view = Ui::get_int( 'view' );
		if ( $view ) {
			self::detail( $view );
			return;
		}
		$status = Ui::get_str( 'status' );
		$status = isset( RefundRules::statuses()[ $status ] ) ? $status : '';
		Ui::header( 'Rimborsi ai volontari' );
		echo '<p class="description">I volontari chiedono il rimborso dall\'area riservata (o la segreteria lo registra per loro). Chi approva e paga lo decide qui: il pagamento registra l\'uscita in prima nota e la collega alla richiesta. Per le spese documentate serve lo scontrino o la fattura; l\'autocertificazione vale fino a 10 € al giorno e 150 € al mese per volontario, solo se l\'assemblea ha deliberato i tipi di spesa.</p>';
		$counts = Refunds::counts();
		echo '<p>';
		foreach ( RefundRules::statuses() as $k => $label ) {
			echo '<a class="button' . ( $status === $k ? ' button-primary' : '' ) . '" href="' . esc_url( Ui::url( 'asem-refunds', array( 'status' => $k ) ) ) . '">' . esc_html( $label ) . ' · ' . (int) $counts[ $k ] . '</a> ';
		}
		echo '<a class="button" href="' . esc_url( Ui::url( 'asem-refunds' ) ) . '">Tutti</a> <a class="button" href="' . esc_url( BlockDExports::url( 'asem_refunds_csv', array( 'status' => $status ) ) ) . '">Esporta (CSV)</a></p>';
		$kinds = RefundRules::kinds();
		echo '<table class="widefat striped"><thead><tr><th>N.</th><th>Volontario</th><th>Data spesa</th><th>Descrizione</th><th>Tipo</th><th>Importo</th><th>Stato</th></tr></thead><tbody>';
		$rows = Refunds::all( $status );
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nessuna richiesta.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-refunds', array( 'view' => (int) $r['id'] ) ) ) . '">' . (int) $r['id'] . '</a></td><td>' . esc_html( Refunds::person_name( $r ) ) . '</td><td>' . esc_html( ( new \DateTimeImmutable( (string) $r['expense_date'] ) )->format( 'd/m/Y' ) )
				. '</td><td>' . esc_html( $r['description'] ) . '</td><td>' . esc_html( explode( ' (', $kinds[ $r['kind'] ] ?? '' )[0] ) . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) )
				. '</td><td class="' . esc_attr( self::CLASSES[ $r['status'] ] ?? '' ) . '">' . esc_html( RefundRules::statuses()[ $r['status'] ] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::new_form();
		self::settings_form();
		Ui::footer();
	}

	private static function new_form(): void {
		$rate = Refunds::km_rate();
		echo '<h2>Registra una richiesta per un volontario</h2>';
		Ui::form_open( 'asem_refund_request', Ui::url( 'asem-refunds' ), true );
		$people = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( \AssociazioneSemplice\MemberType::is_member( $p['type'] ) ) {
				$people[] = $p;
			}
		}
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Volontario *</th><td>' . Ui::person_select( 'person_id', $people, null, '— scegli —', 'asem-refund-person' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Tipo *</th><td><select name="kind">' . Ui::options( RefundRules::available_kinds( $rate ), RefundRules::DOCUMENTED ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Data della spesa *</th><td><input type="date" name="expense_date" value="' . esc_attr( Db::today() ) . '" max="' . esc_attr( Db::today() ) . '" required></td></tr>';
		echo '<tr><th>Descrizione *</th><td><input type="text" name="description" class="large-text" maxlength="255" required></td></tr>';
		echo '<tr><th>Importo (€)</th><td><input type="text" name="amount" inputmode="decimal"></td></tr>';
		if ( $rate > 0 ) {
			echo '<tr><th>Chilometri</th><td><input type="number" name="km" min="0" style="width:90px"> <span class="description">Solo per il rimborso chilometrico: ' . esc_html( Money::format( $rate ) ) . ' al chilometro, l\'importo si calcola da solo.</span></td></tr>';
		}
		echo '<tr><th>Giustificativo</th><td><input type="file" name="file" accept="image/*,application/pdf"> <span class="description">Obbligatorio per le spese documentate.</span></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Registra la richiesta' );
		Ui::form_close();
	}

	private static function settings_form(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		echo '<h2>Impostazioni</h2>';
		Ui::form_open( 'asem_save_refund_settings', Ui::url( 'asem-refunds' ) );
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Tariffa al chilometro</th><td><input type="text" name="refund_km_rate" inputmode="decimal" value="' . esc_attr( Refunds::km_rate() > 0 ? Money::plain( Refunds::km_rate() ) : '' ) . '" style="width:90px"> € <span class="description">Lascia vuoto per non offrire il rimborso chilometrico. Usa la tariffa deliberata dall\'assemblea (per esempio le tabelle ACI).</span></td></tr>';
		echo '<tr><th>Approvazione del proprio rimborso</th><td><label><input type="checkbox" name="refund_self_approve" value="1"' . checked( Refunds::self_approval_allowed(), true, false ) . '> Chi ha chiesto il rimborso può anche approvarlo e pagarlo</label><p class="description">Spento: serve un\'altra persona della segreteria o del direttivo. Accendilo solo se l\'ente ha una sola persona che gestisce la cassa.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva le impostazioni' );
		Ui::form_close();
	}

	private static function detail( int $id ): void {
		$r = Refunds::get( $id );
		Ui::header( 'Rimborso n. ' . $id, '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-refunds' ) ) . '">← Tutti i rimborsi</a>' );
		if ( ! $r ) {
			echo '<p>Richiesta non trovata.</p>';
			Ui::footer();
			return;
		}
		$back = Ui::url( 'asem-refunds', array( 'view' => $id ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Volontario</th><td>' . esc_html( Refunds::person_name( $r ) ) . '</td></tr>';
		echo '<tr><th>Tipo</th><td>' . esc_html( RefundRules::kinds()[ $r['kind'] ] ?? '' ) . ( null !== $r['km'] ? ' — ' . (int) $r['km'] . ' km' : '' ) . '</td></tr>';
		echo '<tr><th>Data della spesa</th><td>' . esc_html( ( new \DateTimeImmutable( (string) $r['expense_date'] ) )->format( 'd/m/Y' ) ) . '</td></tr>';
		echo '<tr><th>Descrizione</th><td>' . esc_html( $r['description'] ) . '</td></tr>';
		echo '<tr><th>Importo</th><td><strong>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</strong></td></tr>';
		if ( ! empty( $r['activity_id'] ) ) {
			$a = Plugin::activities()->get( (int) $r['activity_id'] );
			echo '<tr><th>Attività</th><td>' . esc_html( $a['name'] ?? '' ) . '</td></tr>';
		}
		if ( ! empty( $r['file_stored'] ) ) {
			echo '<tr><th>Giustificativo</th><td><a target="_blank" href="' . esc_url( Refunds::file_url( $id ) ) . '">Apri il file</a></td></tr>';
		}
		echo '<tr><th>Stato</th><td class="' . esc_attr( self::CLASSES[ $r['status'] ] ?? '' ) . '"><strong>' . esc_html( RefundRules::statuses()[ $r['status'] ] ?? '' ) . '</strong>';
		if ( RefundRules::REJECTED === $r['status'] ) {
			echo ' — ' . esc_html( (string) $r['reject_reason'] );
		}
		if ( RefundRules::PAID === $r['status'] ) {
			echo ' il ' . esc_html( ( new \DateTimeImmutable( (string) $r['paid_on'] ) )->format( 'd/m/Y' ) ) . ' — <a href="' . esc_url( Ui::url( 'asem-ledger' ) ) . '">movimento n. ' . (int) $r['tx_id'] . ' in prima nota</a>';
		}
		echo '</td></tr></tbody></table>';

		if ( RefundRules::REQUESTED === $r['status'] ) {
			echo '<p>' . Ui::confirm_button( 'asem_refund_approve', $back, array( 'id' => $id ), 'Approva', 'Approvare questo rimborso?' ) . ' ' . Ui::confirm_button( 'asem_refund_cancel', $back, array( 'id' => $id ), 'Annulla la richiesta', 'Annullare la richiesta?' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( in_array( $r['status'], array( RefundRules::REQUESTED, RefundRules::APPROVED ), true ) ) {
			Ui::form_open( 'asem_refund_reject', $back );
			echo Ui::hidden( 'id', $id ) . '<p><input type="text" name="reason" maxlength="255" placeholder="Motivo del rifiuto" required> '; // phpcs:ignore WordPress.Security.EscapeOutput
			submit_button( 'Rifiuta', 'secondary', 'submit', false );
			echo '</p>';
			Ui::form_close();
		}
		if ( RefundRules::APPROVED === $r['status'] ) {
			$accounts = array();
			foreach ( Plugin::ledger()->balances() as $a ) {
				$accounts[ $a['id'] ] = $a['name'] . ' (' . Money::format( $a['balance'] ) . ')';
			}
			$default = Plugin::ledger()->default_account_for( 'cash' );
			echo '<h2>Pagamento</h2>';
			Ui::form_open( 'asem_refund_pay', $back );
			echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p>Pagato dal conto <select name="account_id">' . Ui::options( $accounts, $default ? $default['id'] : null ) . '</select> il <input type="date" name="paid_on" value="' . esc_attr( Db::today() ) . '" max="' . esc_attr( Db::today() ) . '" required> '; // phpcs:ignore WordPress.Security.EscapeOutput
			submit_button( 'Segna come pagato e registra in prima nota', 'primary', 'submit', false );
			echo '</p><p class="description">Registra un\'uscita nella categoria «Rimborso spese socio/volontario» e la collega a questa richiesta.</p>';
			Ui::form_close();
		}
		Ui::footer();
	}

	/** Riquadro della Bacheca: richieste da approvare e rimborsi approvati da pagare. */
	public static function alert(): void {
		if ( ! Edition::has( 'refunds' ) || ! Refunds::can_manage() ) {
			return;
		}
		$n = Refunds::counts();
		$todo = $n[ RefundRules::REQUESTED ] + $n[ RefundRules::APPROVED ];
		if ( $todo > 0 ) {
			echo Dismiss::html( 'refunds-' . $n[ RefundRules::REQUESTED ] . '-' . $n[ RefundRules::APPROVED ], 'info', 'Rimborsi ai volontari: ' . (int) $n[ RefundRules::REQUESTED ] . ' da approvare e ' . (int) $n[ RefundRules::APPROVED ] . ' da pagare. <a href="' . esc_url( Ui::url( 'asem-refunds' ) ) . '">Apri i rimborsi</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
	}
}

<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\RefundRules;
use AssociazioneSemplice\Refunds;

defined( 'ABSPATH' ) || exit;

/** Area soci (Pro): i rimborsi spese del volontario, con la richiesta di un nuovo rimborso. Ci vede solo chi è socio o volontario. */
final class RefundsFront {

	public static function section( array $p ): string {
		if ( ! Edition::has( 'refunds' ) || ! MemberType::is_member( (string) $p['type'] ) ) {
			return '';
		}
		Assets::enqueue();
		$pid   = (int) $p['id'];
		$rate  = Refunds::km_rate();
		$kinds = RefundRules::available_kinds( $rate );
		$html  = '<section class="asemf-section asemf-refunds"><h3>Rimborsi spese</h3>';
		$rows  = Refunds::all( '', $pid );
		if ( $rows ) {
			$html .= '<ul class="asemf-list">';
			foreach ( $rows as $r ) {
				$id    = (int) $r['id'];
				$html .= '<li><div><strong>' . esc_html( $r['description'] ) . '</strong> <span class="asemf-small asemf-muted">' . esc_html( ( new \DateTimeImmutable( (string) $r['expense_date'] ) )->format( 'd/m/Y' ) ) . ' · ' . esc_html( Money::format( (int) $r['amount_cents'] ) )
					. ' · ' . esc_html( RefundRules::statuses()[ $r['status'] ] ?? '' ) . '</span>';
				if ( RefundRules::REJECTED === $r['status'] && '' !== (string) $r['reject_reason'] ) {
					$html .= '<div class="asemf-small">Motivo: ' . esc_html( (string) $r['reject_reason'] ) . '</div>';
				}
				if ( RefundRules::REQUESTED === $r['status'] ) {
					$html .= Views::form( 'asem_front_refund_cancel', Views::hidden( 'refund_id', $id ), 'Ritira la richiesta', true );
				}
				$html .= '</div></li>';
			}
			$html .= '</ul>';
		}
		$fields = '<label class="asemf-field">Tipo <select name="kind">';
		foreach ( $kinds as $k => $label ) {
			$fields .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		$fields .= '</select></label>'
			. '<label class="asemf-field">Data della spesa <input type="date" name="expense_date" value="' . esc_attr( Db::today() ) . '" max="' . esc_attr( Db::today() ) . '" required></label>'
			. '<label class="asemf-field">A cosa si riferisce <input type="text" name="description" maxlength="255" required></label>'
			. '<label class="asemf-field">Importo in euro <input type="text" name="amount" inputmode="decimal"></label>';
		if ( $rate > 0 ) {
			$fields .= '<label class="asemf-field">Chilometri (solo rimborso chilometrico: ' . esc_html( Money::format( $rate ) ) . ' al km) <input type="number" name="km" min="0"></label>';
		}
		$fields .= '<label class="asemf-field">Scontrino o fattura (PDF o foto; obbligatorio per le spese documentate) <input type="file" name="file" accept="image/*,application/pdf"></label>';
		$html   .= '<h4>Chiedi un rimborso</h4>' . Views::form( 'asem_front_refund', $fields, 'Invia la richiesta', false, '', true ) . '</section>';
		return $html;
	}

	/** La vista da shortcode. */
	public static function view(): string {
		return Views::with_person(
			function ( $p ) {
				$html = self::section( $p );
				return '' !== $html ? $html : '<div class="asemf-notice">I rimborsi spese sono riservati a soci e volontari.</div>';
			}
		);
	}

	public static function do_request( array $post ): string {
		if ( ! Edition::has( 'refunds' ) ) {
			throw new \InvalidArgumentException( Edition::missing_message( 'refunds' ) );
		}
		$actor = Actions::actor();
		Refunds::request( (int) $actor['id'], $post, Documents::prepare_upload( 'file' ), get_current_user_id() );
		return 'Richiesta inviata. La segreteria la esamina e ti avvisa per email.';
	}

	public static function do_cancel( array $post ): string {
		if ( ! Edition::has( 'refunds' ) ) {
			throw new \InvalidArgumentException( Edition::missing_message( 'refunds' ) );
		}
		$actor = Actions::actor();
		Refunds::cancel( (int) ( $post['refund_id'] ?? 0 ), (int) $actor['id'] );
		return 'Richiesta ritirata.';
	}
}

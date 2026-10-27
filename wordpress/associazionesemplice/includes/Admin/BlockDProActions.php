<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Budget;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Refunds;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli di preventivo e rimborsi (funzioni di Associazione Semplice Pro). Si registrano su Actions::add_handler(): nonce e
 * permessi li controlla Associazione Semplice; senza la funzione l'azione si rifiuta con il messaggio standard.
 */
final class BlockDProActions {

	/** Azione => [metodo, funzione, riservata all'amministratore]. */
	const MAP = array(
		'asem_budget_save'          => array( 'budget_save', 'budget', false ),
		'asem_budget_lines'         => array( 'budget_lines', 'budget', false ),
		'asem_budget_copy'          => array( 'budget_copy', 'budget', false ),
		'asem_budget_approve'       => array( 'budget_approve', 'budget', false ),
		'asem_budget_reopen'        => array( 'budget_reopen', 'budget', false ),
		'asem_budget_delete'        => array( 'budget_delete', 'budget', false ),
		'asem_refund_request'       => array( 'refund_request', 'refunds', false ),
		'asem_refund_approve'       => array( 'refund_approve', 'refunds', false ),
		'asem_refund_reject'        => array( 'refund_reject', 'refunds', false ),
		'asem_refund_cancel'        => array( 'refund_cancel', 'refunds', false ),
		'asem_refund_pay'           => array( 'refund_pay', 'refunds', false ),
		'asem_save_refund_settings' => array( 'save_refund_settings', 'refunds', true ),
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $row ) {
			Actions::add_handler( $action, self::guarded( $row[0], $row[1] ), $row[2] );
		}
	}

	private static function guarded( string $method, string $feature ): callable {
		return function ( array $p ) use ( $method, $feature ) {
			if ( ! Edition::has( $feature ) ) {
				throw new \InvalidArgumentException( Edition::missing_message( $feature ) );
			}
			return self::$method( $p );
		};
	}

	private static function budget_url( int $id ): string {
		return Ui::url( 'asem-budget', array( 'view' => $id ) );
	}

	// ---------- Preventivo ----------

	public static function budget_save( array $p ): array {
		$id = Budget::save( (int) ( $p['id'] ?? 0 ), $p, get_current_user_id() );
		return array( self::budget_url( $id ), 'Preventivo salvato.' );
	}

	public static function budget_lines( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = Budget::set_lines( $id, (array) ( $p['amount'] ?? array() ), (array) ( $p['note'] ?? array() ) );
		return array( self::budget_url( $id ), 'Voci salvate: ' . $n . '.' );
	}

	public static function budget_copy( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = Budget::copy_from_previous( $id, 'budget' === ( $p['source'] ?? '' ) ? 'budget' : 'actual', (int) ( $p['percent'] ?? 0 ) );
		return array( self::budget_url( $id ), 'Voci copiate: ' . $n . '. Controllale e correggi gli importi.' );
	}

	public static function budget_approve( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Budget::approve( $id, (string) ( $p['approved_on'] ?? '' ), (int) ( $p['assembly_id'] ?? 0 ) );
		return array( self::budget_url( $id ), 'Preventivo segnato come approvato.' );
	}

	public static function budget_reopen( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Budget::reopen( $id );
		return array( self::budget_url( $id ), 'Preventivo riaperto.' );
	}

	public static function budget_delete( array $p ): array {
		Budget::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-budget' ), 'Preventivo eliminato.' );
	}

	// ---------- Rimborsi ----------

	private static function refund_url( int $id ): string {
		return Ui::url( 'asem-refunds', array( 'view' => $id ) );
	}

	public static function refund_request( array $p ): array {
		$id = Refunds::request( (int) ( $p['person_id'] ?? 0 ), $p, Documents::prepare_upload( 'file' ), get_current_user_id() );
		return array( self::refund_url( $id ), 'Richiesta registrata.' );
	}

	public static function refund_approve( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Refunds::approve( $id, get_current_user_id() );
		return array( self::refund_url( $id ), 'Rimborso approvato. Il volontario è stato avvisato per email.' );
	}

	public static function refund_reject( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Refunds::reject( $id, (string) ( $p['reason'] ?? '' ), get_current_user_id() );
		return array( self::refund_url( $id ), 'Rimborso rifiutato.' );
	}

	public static function refund_cancel( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Refunds::cancel( $id );
		return array( self::refund_url( $id ), 'Richiesta annullata.' );
	}

	public static function refund_pay( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Refunds::pay( $id, (int) ( $p['account_id'] ?? 0 ), (string) ( $p['paid_on'] ?? '' ), get_current_user_id() );
		return array( self::refund_url( $id ), 'Rimborso pagato e registrato in prima nota.' );
	}

	public static function save_refund_settings( array $p ): array {
		$raw   = trim( (string) ( $p['refund_km_rate'] ?? '' ) );
		$cents = '' === $raw ? 0 : Money::parse( $raw );
		if ( null === $cents || $cents < 0 ) {
			throw new \InvalidArgumentException( 'Tariffa al chilometro non valida.' );
		}
		Settings::update( array( 'refund_km_rate_cents' => $cents, 'refund_self_approve' => ! empty( $p['refund_self_approve'] ) ? 1 : 0 ) );
		return array( Ui::url( 'asem-refunds' ), 'Impostazioni dei rimborsi salvate.' );
	}
}

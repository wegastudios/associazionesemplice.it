<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\BalanceWatch;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpenseFunds;
use AssociazioneSemplice\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli del controllo di bilancio e delle spese attribuite ai fondi (Associazione Semplice Pro Fiscale). Si registrano su
 * Actions::add_handler(): nonce e permessi li controlla Associazione Semplice.
 */
final class WatchActions {

	const MAP = array(
		'asem_expense_fund_attach' => 'attach',
		'asem_expense_fund_undo'   => 'undo',
		'asem_watch_snapshot'      => 'snapshot',
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $method ) {
			Actions::add_handler(
				$action,
				function ( array $p ) use ( $method ) {
					if ( ! Edition::has( 'watch' ) ) {
						throw new \InvalidArgumentException( Edition::missing_message( 'watch' ) );
					}
					return self::$method( $p );
				}
			);
		}
	}

	private static function attach( array $p ): array {
		$cents = ExpenseFunds::attach( (int) ( $p['tx_id'] ?? 0 ), (int) ( $p['fund_id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'asem-accounts' ), 'Spesa attribuita al fondo per ' . Money::format( $cents ) . '.' );
	}

	private static function undo( array $p ): array {
		$n = ExpenseFunds::undo( (int) ( $p['tx_id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'asem-accounts' ), $n ? 'Attribuzione annullata: la spesa non viene riattribuita da sola.' : 'Nessuna attribuzione da annullare.' );
	}

	private static function snapshot( array $p ): array {
		$n = BalanceWatch::take_missing();
		return array( Ui::url( 'asem-watch' ), 0 === $n ? 'Le foto dei mesi conclusi ci sono già tutte.' : $n . ( 1 === $n ? ' foto salvata.' : ' foto salvate.' ) );
	}
}

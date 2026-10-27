<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Budget;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Refunds;

defined( 'ABSPATH' ) || exit;

/** Download CSV del confronto preventivo/consuntivo e dei rimborsi ai volontari. */
final class BlockDExports {

	public static function register(): void {
		add_action( 'admin_post_asem_budget_csv', array( __CLASS__, 'budget_csv' ) );
		add_action( 'admin_post_asem_refunds_csv', array( __CLASS__, 'refunds_csv' ) );
	}

	/** Indirizzo di un download (con nonce). */
	public static function url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	private static function send( string $name, string $csv ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- file CSV, non HTML
		exit;
	}

	public static function budget_csv(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) || ! Edition::has( 'budget' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_budget_csv' );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$b  = Budget::get( $id );
		if ( ! $b ) {
			wp_die( 'Preventivo non trovato.', 404 );
		}
		Audit::log( 'budget.exported', 'budget', $id );
		self::send( 'preventivo-' . (int) $b['year'] . '.csv', Budget::csv( $id ) );
	}

	public static function refunds_csv(): void {
		if ( ! Refunds::can_manage() || ! Edition::has( 'refunds' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_refunds_csv' );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		Audit::log( 'refund.exported', 'settings', 0 );
		self::send( 'rimborsi-' . Db::today() . '.csv', Refunds::csv( $status ) );
	}
}

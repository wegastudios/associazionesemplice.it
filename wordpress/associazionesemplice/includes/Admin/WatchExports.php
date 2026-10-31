<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\BalanceWatch;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpenseFunds;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Download CSV dello storico mensile e dei due elenchi dei fondi (Associazione Semplice Pro Fiscale). */
final class WatchExports {

	public static function register(): void {
		add_action( 'admin_post_asem_watch_csv', array( __CLASS__, 'download' ) );
	}

	/** Indirizzo di un download (con nonce): `snapshots` (anche con `year`), `uncovered`, `undocumented`. */
	public static function url( string $what, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'asem_watch_csv', 'what' => $what ), $args ), admin_url( 'admin-post.php' ) ), 'asem_watch_csv' );
	}

	public static function download(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) || ! Edition::has( 'watch' ) ) {
			wp_die( esc_html( Edition::missing_message( 'watch' ) ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'asem_watch_csv' );
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		if ( 'snapshots' === $what ) {
			$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0;
			self::send( 'bilancio-mensile' . ( $year ? '-' . $year : '' ) . '.csv', BalanceWatch::snapshots_csv( $year ) );
		} elseif ( 'uncovered' === $what ) {
			self::send( 'spese-documentate-non-rimborsate.csv', ExpenseFunds::uncovered_csv() );
		} elseif ( 'recon' === $what || 'recon_pdf' === $what ) {
			$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : \AssociazioneSemplice\FiscalYears::current();
			if ( 'recon' === $what ) {
				self::send( 'rimborsi-e-spese-' . $year . '.csv', \AssociazioneSemplice\FundReconciliation::csv( $year ) );
			}
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="rimborsi-e-spese-' . $year . '.pdf"' );
			echo \AssociazioneSemplice\FundReconciliation::pdf( $year ); // phpcs:ignore WordPress.Security.EscapeOutput -- file PDF, non HTML
			exit;
		} elseif ( 'undocumented' === $what ) {
			self::send( 'fondi-non-documentati.csv', ExpenseFunds::undocumented_csv() );
		}
		wp_die( 'Esportazione non trovata.', '', array( 'response' => 404 ) );
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
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Affiliations;
use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Certificates;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Download CSV dei certificati medici e delle tessere degli enti (separatore «;», si apre bene in Excel italiano). */
final class BlockCExports {

	public static function register(): void {
		add_action( 'admin_post_asem_cert_csv', array( __CLASS__, 'cert_csv' ) );
		add_action( 'admin_post_asem_aff_csv', array( __CLASS__, 'aff_csv' ) );
	}

	/** Indirizzo di un download (con nonce). */
	public static function url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	private static function line( array $cells ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = Exports::neutralize( null === $c ? '' : (string) $c );
			$out[] = preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ';', $out ) . "\r\n";
	}

	private static function send( string $name, array $rows ): void {
		$csv = '';
		foreach ( $rows as $r ) {
			$csv .= self::line( $r );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"' );
		echo "\xEF\xBB\xBF" . $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- file CSV, non HTML
		exit;
	}

	private static function get( string $key ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- il nonce è controllato da chi chiama
	}

	/** Elenco dei certificati medici: solo staff. Nel registro azioni resta solo che è stato scaricato. */
	public static function cert_csv(): void {
		if ( ! Certificates::can_manage() || ! Edition::has( 'medical' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_cert_csv' );
		Audit::log( 'medical.exported', 'settings', 0 );
		self::send( 'certificati-medici-' . Db::today() . '.csv', Certificates::csv_rows( self::get( 'filter' ), (int) self::get( 'activity' ), '1' === self::get( 'all' ) ) );
	}

	/** Tessere degli enti: `kind=portal` è il file per il caricamento sui portali, `kind=list` l'elenco con numeri e costi. */
	public static function aff_csv(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) || ! Edition::has( 'affiliations' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_aff_csv' );
		$f = array(
			'body'     => self::get( 'body' ),
			'season'   => self::get( 'season' ),
			'status'   => self::get( 'status' ),
			'activity' => (int) self::get( 'activity' ),
		);
		Audit::log( 'body_card.exported', 'settings', 0, array( 'kind' => 'portal' === self::get( 'kind' ) ? 'portal' : 'list' ) );
		if ( 'portal' === self::get( 'kind' ) ) {
			self::send( 'tesseramento-portale-' . Db::today() . '.csv', Affiliations::portal_rows( $f ) );
		}
		self::send( 'tessere-enti-' . Db::today() . '.csv', Affiliations::list_rows( $f ) );
	}
}

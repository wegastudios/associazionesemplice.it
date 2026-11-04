<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Conventions;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Azioni delle convenzioni per i soci. Servono i permessi operativi (segreteria, presidente e vicepresidente, amministratori). */
final class BlockFActions {

	const ACTIONS = array(
		'asem_convention_save'       => 'convention_save',
		'asem_convention_delete'     => 'convention_delete',
		'asem_convention_archive'    => 'convention_archive',
		'asem_convention_announce'   => 'convention_announce',
		'asem_convention_categories' => 'convention_categories',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP_OPS ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem-conventions' );
					try {
						$res = self::$method( $post );
						Ui::redirect( $res[0], $res[1] );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', 'Errore imprevisto: ' . $e->getMessage() );
					}
				}
			);
		}
		add_action( 'admin_post_asem_conventions_csv', array( __CLASS__, 'csv' ) );
	}

	public static function convention_save( array $p ): array {
		$id = Conventions::save( (int) ( $p['id'] ?? 0 ), $p );
		return array( Ui::url( 'asem-conventions', array( 'view' => $id ) ), 'Convenzione salvata.' );
	}

	public static function convention_delete( array $p ): array {
		Conventions::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-conventions' ), 'Convenzione eliminata.' );
	}

	public static function convention_archive( array $p ): array {
		$id      = (int) ( $p['id'] ?? 0 );
		$archive = ! empty( $p['archive'] );
		Conventions::set_archived( $id, $archive );
		return array( Ui::url( 'asem-conventions', array( 'view' => $id ) ), $archive ? 'Convenzione archiviata: non compare più ai soci.' : 'Convenzione riportata in elenco.' );
	}

	public static function convention_announce( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = Conventions::announce( $id, true );
		return array( Ui::url( 'asem-conventions', array( 'view' => $id ) ), $n > 0 ? ( 1 === $n ? '1 avviso inviato.' : $n . ' avvisi inviati.' ) : 'Nessun avviso partito (ai soci a cui spetta manca l\'indirizzo email o la posta non ha funzionato): puoi riprovare.' );
	}

	public static function convention_categories( array $p ): array {
		Conventions::save_categories( (string) ( $p['categories'] ?? '' ) );
		return array( Ui::url( 'asem-conventions' ), 'Categorie salvate.' );
	}

	/** Link al CSV (con nonce). */
	public static function csv_link(): string {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'asem_conventions_csv' ), admin_url( 'admin-post.php' ) ), 'asem_conventions_csv' );
		return '<a class="button" href="' . esc_url( $url ) . '">Esporta (CSV)</a>';
	}

	public static function csv(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_conventions_csv' );
		Audit::log( 'convention.exported', 'convention', 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="convenzioni.csv"' );
		\AssociazioneSemplice\Download::csv( Conventions::csv() );
		exit;
	}
}

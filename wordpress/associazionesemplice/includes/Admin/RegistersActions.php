<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Guide;
use AssociazioneSemplice\MemberBook;
use AssociazioneSemplice\Minutes;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Azioni dei registri: cessazione dei soci, verbali, guida iniziale. Servono i permessi operativi (anche la segreteria). */
final class RegistersActions {

	const ACTIONS = array(
		'asem_member_left'      => 'member_left',
		'asem_minute_save'      => 'minute_save',
		'asem_minute_delete'    => 'minute_delete',
		'asem_guide_dismiss'    => 'guide_dismiss',
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
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem' );
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
	}

	public static function member_left( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		MemberBook::set_left( $id, (string) ( $p['left_on'] ?? '' ), (string) ( $p['left_reason'] ?? '' ) );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), '' === trim( (string) ( $p['left_on'] ?? '' ) ) ? 'Cessazione tolta: il socio torna in carica.' : 'Cessazione registrata nel libro soci.' );
	}

	public static function minute_save( array $p ): array {
		$id = Minutes::save( (int) ( $p['id'] ?? 0 ), $p, get_current_user_id() );
		return array( Ui::url( 'asem-minutes', array( 'view' => $id ) ), 'Verbale salvato.' );
	}

	public static function minute_delete( array $p ): array {
		Minutes::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-minutes' ), 'Verbale eliminato.' );
	}










	public static function guide_dismiss( array $p ): array {
		Guide::dismiss( get_current_user_id(), ! empty( $p['on'] ) && '0' !== (string) $p['on'] );
		return array( Ui::url( 'asem-guide' ), 'Preferenza salvata.' );
	}

}

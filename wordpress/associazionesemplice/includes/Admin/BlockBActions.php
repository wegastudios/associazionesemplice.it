<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Documents;
use AssociazioneSemplice\Inventory;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Polls;

defined( 'ABSPATH' ) || exit;

/** Azioni di votazioni, inventario e documenti. Servono i permessi operativi (segreteria, presidente e vicepresidente, amministratori). */
final class BlockBActions {

	const ACTIONS = array(
		'asem_poll_save'        => 'poll_save',
		'asem_poll_delete'      => 'poll_delete',
		'asem_poll_announce'    => 'poll_announce',
		'asem_item_save'        => 'item_save',
		'asem_item_delete'      => 'item_delete',
		'asem_loan_save'        => 'loan_save',
		'asem_loan_return'      => 'loan_return',
		'asem_document_save'    => 'document_save',
		'asem_document_delete'  => 'document_delete',
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

	public static function poll_save( array $p ): array {
		$id = Polls::save( (int) ( $p['id'] ?? 0 ), $p, get_current_user_id() );
		return array( Ui::url( 'asem-polls', array( 'view' => $id ) ), 'Votazione salvata.' );
	}

	public static function poll_delete( array $p ): array {
		Polls::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-polls' ), 'Votazione eliminata.' );
	}

	public static function poll_announce( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = Polls::announce( $id );
		return array( Ui::url( 'asem-polls', array( 'view' => $id ) ), $n . ' avvisi inviati.' );
	}

	public static function item_save( array $p ): array {
		$id = Inventory::save_item( (int) ( $p['id'] ?? 0 ), $p, Documents::prepare_upload( 'file' ) );
		return array( Ui::url( 'asem-inventory', array( 'view' => $id ) ), 'Bene salvato.' );
	}

	public static function item_delete( array $p ): array {
		Inventory::delete_item( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-inventory' ), 'Bene eliminato.' );
	}

	public static function loan_save( array $p ): array {
		$item = (int) ( $p['item_id'] ?? 0 );
		Inventory::lend( $item, (int) ( $p['person_id'] ?? 0 ), (int) ( $p['quantity'] ?? 1 ), (string) ( $p['lent_on'] ?? '' ), (string) ( $p['due_on'] ?? '' ), (string) ( $p['notes'] ?? '' ) );
		return array( Ui::url( 'asem-inventory', array( 'view' => $item ) ), 'Prestito registrato.' );
	}

	public static function loan_return( array $p ): array {
		$loan = Inventory::loan( (int) ( $p['id'] ?? 0 ) );
		Inventory::return_loan( (int) ( $p['id'] ?? 0 ), (string) ( $p['returned_on'] ?? '' ), (string) ( $p['notes_in'] ?? '' ), (string) ( $p['new_state'] ?? '' ) );
		return array( Ui::url( 'asem-inventory', array( 'view' => (int) ( $loan['item_id'] ?? 0 ) ) ), 'Rientro registrato.' );
	}

	public static function document_save( array $p ): array {
		$id = Documents::save( (int) ( $p['id'] ?? 0 ), $p, Documents::prepare_upload( 'file' ), get_current_user_id() );
		return array( Ui::url( 'asem-documents', array( 'view' => $id ) ), 'Documento salvato.' );
	}

	public static function document_delete( array $p ): array {
		Documents::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-documents' ), 'Documento eliminato.' );
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Assemblies;
use AssociazioneSemplice\AssemblyRules;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Azioni delle assemblee. Servono i permessi operativi (segreteria, presidente e vicepresidente, amministratori). */
final class BlockDActions {

	const ACTIONS = array(
		'asem_assembly_save'         => 'save',
		'asem_assembly_delete'       => 'delete',
		'asem_assembly_convene'      => 'convene',
		'asem_assembly_hold'         => 'hold',
		'asem_assembly_cancel'       => 'cancel',
		'asem_assembly_draft'        => 'draft',
		'asem_assembly_attendance'   => 'attendance',
		'asem_assembly_proxy_add'    => 'proxy_add',
		'asem_assembly_proxy_revoke' => 'proxy_revoke',
		'asem_assembly_minutes'      => 'minutes',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP_OPS ) || ! Edition::has( 'assemblies' ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem-assemblies' );
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

	private static function view( int $id ): string {
		return Ui::url( 'asem-assemblies', array( 'view' => $id ) );
	}

	public static function save( array $p ): array {
		$id = Assemblies::save( (int) ( $p['id'] ?? 0 ), $p, get_current_user_id() );
		return array( self::view( $id ), 'Assemblea salvata.' );
	}

	public static function delete( array $p ): array {
		Assemblies::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-assemblies' ), 'Assemblea eliminata.' );
	}

	public static function convene( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$r  = Assemblies::convene( $id );
		$m  = 'Assemblea convocata: ' . ( 1 === (int) $r['sent'] ? '1 avviso inviato.' : $r['sent'] . ' avvisi inviati.' ) . ( $r['no_email'] ? ' ' . $r['no_email'] . ' soci senza email non sono stati avvisati.' : '' ) . ( '' !== $r['warning'] ? ' ' . $r['warning'] : '' );
		return array( self::view( $id ), $m );
	}

	public static function hold( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Assemblies::hold( $id, (int) ( $p['call'] ?? 1 ) );
		return array( self::view( $id ), 'Assemblea chiusa come svolta. Ora puoi creare il verbale.' );
	}

	public static function cancel( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Assemblies::move( $id, AssemblyRules::CANCELLED );
		return array( self::view( $id ), 'Assemblea annullata.' );
	}

	public static function draft( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Assemblies::move( $id, AssemblyRules::DRAFT );
		return array( self::view( $id ), 'Assemblea riportata in bozza.' );
	}

	public static function attendance( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = Assemblies::set_attendance( $id, (array) ( $p['present'] ?? array() ) );
		return array( self::view( $id ), 'Presenze salvate: ' . $n . '.' );
	}

	public static function proxy_add( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Assemblies::add_proxy( $id, (int) ( $p['delegator_id'] ?? 0 ), (int) ( $p['delegate_id'] ?? 0 ) );
		return array( self::view( $id ), 'Delega registrata.' );
	}

	public static function proxy_revoke( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Assemblies::revoke_proxy( $id, (int) ( $p['delegator_id'] ?? 0 ) );
		return array( self::view( $id ), 'Delega revocata.' );
	}

	public static function minutes( array $p ): array {
		$id  = (int) ( $p['id'] ?? 0 );
		$mid = Assemblies::create_minutes( $id, get_current_user_id() );
		return array( Ui::url( 'asem-minutes', array( 'view' => $mid ) ), 'Verbale creato: completa lo svolgimento e le deliberazioni.' );
	}
}

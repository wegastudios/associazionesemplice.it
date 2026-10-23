<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Resources;

defined( 'ABSPATH' ) || exit;

/** Azioni dell'amministrazione sulle risorse prenotabili. Servono i permessi operativi (anche la segreteria). */
final class ResourcesActions {

	const ACTIONS = array(
		'asem_resource_save'   => 'save',
		'asem_resource_delete' => 'delete',
		'asem_res_book'        => 'book',
		'asem_res_assign'      => 'assign',
		'asem_res_block'       => 'block',
		'asem_res_cancel'      => 'cancel',
		'asem_res_pay'         => 'pay',
		'asem_res_wait_remove' => 'wait_remove',
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
					$post = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem-resources' );
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

	private static function cents( array $p, string $key ): int {
		$raw = isset( $p[ $key ] ) ? trim( (string) $p[ $key ] ) : '';
		return '' === $raw ? 0 : (int) ( Money::parse( $raw ) ?? 0 );
	}

	/** Quota per il fondo: euro (fisso) o percentuale, in centesimi / centesimi di punto percentuale (25,5% = 2550). */
	private static function fund_value( array $p ): int {
		$raw = trim( (string) ( $p['fund_value'] ?? '' ) );
		if ( '' === $raw || '' === (string) ( $p['fund_mode'] ?? '' ) ) {
			return 0;
		}
		if ( 'percent' === $p['fund_mode'] ) {
			return (int) round( (float) str_replace( ',', '.', $raw ) * 100 );
		}
		return (int) ( Money::parse( $raw ) ?? 0 );
	}

	private static function detail( int $id, array $args = array() ): string {
		return Ui::url( 'asem-resource', array_merge( array( 'id' => $id ), $args ) );
	}

	public static function save( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$in = $p;
		$in['active']          = ! empty( $p['active'] ) ? 1 : 0;
		$in['fee_cents']       = self::cents( $p, 'fee' );
		$in['guest_fee_cents'] = self::cents( $p, 'guest_fee' );
		$in['rates_text']      = (string) ( $p['rates_text'] ?? '' );
		if ( isset( $p['fund_mode'] ) ) { // il campo c'è solo con la funzione dei fondi
			$in['fund_value'] = self::fund_value( $p );
		}
		$id                    = Resources::save( $id, $in );
		return array( self::detail( $id ), 'Risorsa salvata.' );
	}

	public static function delete( array $p ): array {
		$gone = Resources::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-resources' ), $gone ? 'Risorsa eliminata.' : 'Ci sono prenotazioni future: la risorsa è stata disattivata (non si può più prenotare) e non eliminata.' );
	}

	private static function guest_lines( array $p ): array {
		return preg_split( '/\R/u', (string) ( $p['guests'] ?? '' ) ) ?: array();
	}

	public static function book( array $p ): array {
		$rid = (int) ( $p['resource_id'] ?? 0 );
		$day = (string) ( $p['date'] ?? '' );
		$weeks = max( 1, (int) ( $p['weeks'] ?? 1 ) );
		if ( $weeks > 1 ) { // «ogni settimana alla stessa ora»: tutte le date o nessuna
			$ids = Resources::book_series( $rid, (int) ( $p['person_id'] ?? 0 ), $day, (string) ( $p['start'] ?? '' ), max( 1, (int) ( $p['slots'] ?? 1 ) ), $weeks, self::guest_lines( $p ), (string) ( $p['note'] ?? '' ) );
			return array( self::detail( $rid, array( 'd' => $day ) ), count( $ids ) . ' prenotazioni registrate (una a settimana).' );
		}
		Resources::book_slot( $rid, (int) ( $p['person_id'] ?? 0 ), $day, (string) ( $p['start'] ?? '' ), max( 1, (int) ( $p['slots'] ?? 1 ) ), self::guest_lines( $p ), true, (string) ( $p['note'] ?? '' ) );
		return array( self::detail( $rid, array( 'd' => $day ) ), 'Prenotazione registrata.' );
	}

	public static function assign( array $p ): array {
		$rid = (int) ( $p['resource_id'] ?? 0 );
		Resources::assign( $rid, (int) ( $p['person_id'] ?? 0 ), (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), (string) ( $p['note'] ?? '' ) );
		return array( self::detail( $rid ), 'Risorsa assegnata.' );
	}

	public static function block( array $p ): array {
		$rid = (int) ( $p['resource_id'] ?? 0 );
		$t   = function ( string $k ) use ( $p ) {
			$v = trim( (string) ( $p[ $k ] ?? '' ) );
			return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? str_replace( 'T', ' ', $v ) . ':00' : '';
		};
		Resources::block( $rid, $t( 'starts' ), $t( 'ends' ), (string) ( $p['note'] ?? '' ) );
		return array( self::detail( $rid ), 'Blocco registrato.' );
	}

	public static function cancel( array $p ): array {
		$r   = Resources::reservation( (int) ( $p['id'] ?? 0 ) );
		$rid = $r ? (int) $r['resource_id'] : 0;
		$to  = $rid ? self::detail( $rid ) : Ui::url( 'asem-resources' );
		if ( ! empty( $p['series'] ) ) { // questa e le successive della serie
			$n = Resources::cancel_series( (int) ( $p['id'] ?? 0 ) );
			return array( $to, $n . ( 1 === $n ? ' prenotazione annullata.' : ' prenotazioni annullate (questa e le successive).' ) . ' Gli eventuali incassi restano in prima nota: l\'eventuale rimborso è a mano.' );
		}
		Resources::cancel( (int) ( $p['id'] ?? 0 ), true );
		return array( $to, 'Prenotazione annullata. Gli eventuali incassi restano in prima nota: l\'eventuale rimborso è a mano.' );
	}

	public static function wait_remove( array $p ): array {
		$rid = (int) ( $p['resource_id'] ?? 0 );
		Resources::waitlist_leave( $rid, (int) ( $p['person_id'] ?? 0 ) );
		return array( self::detail( $rid ), 'Tolto dalla lista d\'attesa.' );
	}

	public static function pay( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$r  = Resources::reservation( $id );
		Resources::record_payment( $id, (int) ( $p['account_id'] ?? 0 ), (string) ( $p['method'] ?? '' ), self::cents( $p, 'amount' ), current_time( 'Y-m-d' ) );
		return array( $r ? self::detail( (int) $r['resource_id'] ) : Ui::url( 'asem-resources' ), 'Incasso registrato.' );
	}
}

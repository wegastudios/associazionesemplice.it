<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Azioni dell'amministrazione sui crediti. Servono i permessi operativi (anche la segreteria). */
final class CreditsActions {

	const ACTIONS = array(
		'asem_credit_packs'  => 'packs',
		'asem_credit_sell'   => 'sell',
		'asem_credit_adjust' => 'adjust',
		'asem_credit_name'   => 'name',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( in_array( $action, array( 'asem_credit_packs', 'asem_credit_name' ), true ) ? Plugin::CAP : Plugin::CAP_OPS ) ) { // il listino dei pacchetti lo cambia solo l'amministratore
						wp_die( 'Non hai il permesso per questa operazione: chiedilo a un amministratore.', 403 );
					}
					check_admin_referer( $action );
					$post = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem-credits' );
					try {
						Ui::redirect( $back, self::$method( $post ) );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', \AssociazioneSemplice\UnexpectedError::report( $e ) );
					}
				}
			);
		}
	}

	public static function name( array $p ): string {
		\AssociazioneSemplice\Settings::update( array( 'coin_singular' => (string) ( $p['coin_singular'] ?? '' ), 'coin_plural' => (string) ( $p['coin_plural'] ?? '' ) ) );
		$warn = trim( \AssociazioneSemplice\CoinName::warning( \AssociazioneSemplice\CoinName::singular() ) . ' ' . ( \AssociazioneSemplice\CoinName::plural() !== \AssociazioneSemplice\CoinName::singular() ? \AssociazioneSemplice\CoinName::warning( \AssociazioneSemplice\CoinName::plural() ) : '' ) );
		return 'Nome salvato: ' . \AssociazioneSemplice\CoinName::singular() . ' / ' . \AssociazioneSemplice\CoinName::plural() . '.' . ( '' !== $warn ? ' ' . $warn : '' );
	}

	public static function packs( array $p ): string {
		Credits::save_packs( (string) ( $p['packs'] ?? '' ) );
		return 'Pacchetti salvati.';
	}

	public static function sell( array $p ): string {
		Credits::sell_at_desk( (int) ( $p['person_id'] ?? 0 ), (int) ( $p['credits'] ?? 0 ), (int) ( $p['account_id'] ?? 0 ), (string) ( $p['method'] ?? '' ) );
		return 'Pacchetto venduto: ' . \AssociazioneSemplice\CoinName::plural() . ' già sul conto del socio.';
	}

	public static function adjust( array $p ): string {
		Credits::adjust( (int) ( $p['person_id'] ?? 0 ), (int) ( $p['delta'] ?? 0 ), (string) ( $p['note'] ?? '' ) );
		return 'Rettifica registrata.';
	}
}

<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Flash;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\ResourceGate;
use AssociazioneSemplice\Resources;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * La pagina che si apre inquadrando il QR di una prenotazione: dice se è valida (risorsa, giorno e ora, socio, ospiti, pagamento) e, per lo staff
 * che ha fatto l'accesso, permette di registrare l'ingresso (o di toglierlo se è per sbaglio). Esiste solo per le risorse con l'ingresso con QR.
 */
final class ResourcesGate {

	const ACTION = 'asem_front_res_gate';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'do_gate' ) );
		add_action(
			'admin_post_nopriv_' . self::ACTION,
			function () {
				$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
				wp_safe_redirect( wp_login_url( self::url( (string) ( $post['param'] ?? '' ) ) ) );
				exit;
			}
		);
	}

	private static function url( string $param ): string {
		return add_query_arg( 'asem_res', $param, home_url( '/' ) );
	}

	/** Chi può registrare gli ingressi: la segreteria. */
	private static function staff(): bool {
		return is_user_logged_in() && current_user_can( Plugin::CAP_OPS );
	}

	/**
	 * @return array state (ok|used|early|late|cancelled|invalid), r (prenotazione), res (risorsa), person
	 */
	public static function result( string $param ): array {
		$none   = array( 'state' => 'invalid', 'r' => null, 'res' => null, 'person' => null );
		$parsed = ResourceGate::parse( $param );
		if ( ! $parsed || ! ResourceGate::valid( $parsed[0], $parsed[1], $parsed[2], Settings::card_secret() ) ) {
			return $none;
		}
		$r      = Resources::reservation( $parsed[0] );
		$res    = $r ? Resources::get( (int) $r['resource_id'] ) : null;
		$person = $r ? Plugin::people()->get( (int) $r['person_id'] ) : null;
		if ( ! $r || ! $res || ! $person || 'slot' !== $r['kind'] || (int) $r['person_id'] !== $parsed[1] || empty( $res['entry_qr'] ) ) {
			return $none;
		}
		return array( 'state' => Resources::gate_state( $r ), 'r' => $r, 'res' => $res, 'person' => $person );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['asem_res'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET['asem_res'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		CardVerify::send_headers();
		echo self::page( $param, Flash::read( 'asemf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}

	private static function color( string $state ): string {
		return 'ok' === $state ? '#1a7f37' : ( in_array( $state, array( 'used', 'early' ), true ) ? '#996800' : '#b32d2e' );
	}

	/** La pagina completa. @param array $flash esito dell'ultima azione (ok, err) */
	public static function page( string $param, array $flash ): string {
		$x = self::result( $param );
		if ( 'invalid' === $x['state'] ) {
			return CardVerify::layout( 'Non valida', '#b32d2e', '<div class="a">Questo QR non corrisponde a nessuna prenotazione.</div>' );
		}
		$r      = $x['r'];
		$res    = $x['res'];
		$staff  = self::staff();
		$d      = new \DateTimeImmutable( (string) $r['starts_at'] );
		$name   = $staff ? trim( $x['person']['first_name'] . ' ' . $x['person']['last_name'] ) : ResourceGate::short_name( (string) $x['person']['first_name'], (string) $x['person']['last_name'] );
		$paid   = Resources::decorated( $r );
		$body   = '<div class="n">' . esc_html( (string) $res['name'] ) . '</div><div class="t">' . esc_html( $d->format( 'd/m/Y' ) . ' · ' . $d->format( 'H:i' ) . ( $r['ends_at'] ? '–' . substr( (string) $r['ends_at'], 11, 5 ) : '' ) ) . '</div>'
			. '<dl><div><dt>Socio</dt><dd>' . esc_html( $name ) . '</dd></div>'
			. ( $paid['guests'] ? '<div><dt>Ospiti</dt><dd>' . esc_html( implode( ', ', $paid['guests'] ) ) . '</dd></div>' : '' )
			. ( (int) $r['fee_due_cents'] > 0 ? '<div><dt>Contributo</dt><dd>' . ( $paid['remaining'] > 0 ? 'da pagare ' . esc_html( Money::format( (int) $paid['remaining'] ) ) : 'pagato' ) . '</dd></div>' : '' )
			. ( ! empty( $r['checked_in_at'] ) ? '<div><dt>Entrato</dt><dd>' . esc_html( ( new \DateTimeImmutable( (string) $r['checked_in_at'] ) )->format( 'd/m H:i' ) ) . '</dd></div>' : '' )
			. '</dl>';
		if ( '' !== $flash['err'] ) {
			$body .= '<div class="w" style="color:#b32d2e">' . esc_html( $flash['err'] ) . '</div>';
		} elseif ( '' !== $flash['ok'] ) {
			$body .= '<div class="w" style="color:#1a7f37">' . esc_html( $flash['ok'] ) . '</div>';
		}
		if ( $staff && in_array( $x['state'], array( 'ok', 'used' ), true ) ) {
			$do    = 'ok' === $x['state'] ? 'in' : 'undo';
			$body .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. '<input type="hidden" name="param" value="' . esc_attr( $param ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . wp_nonce_field( self::ACTION, '_wpnonce', false, false )
				. '<button style="width:100%;font-size:20px;padding:14px;border:0;border-radius:10px;background:' . ( 'in' === $do ? '#1a7f37' : '#50575e' ) . ';color:#fff;font-weight:700">' . ( 'in' === $do ? 'Registra l\'ingresso' : 'Togli l\'ingresso' ) . '</button></form>';
		} elseif ( ! $staff && in_array( $x['state'], array( 'ok', 'early' ), true ) ) {
			$body .= '<div class="a" style="margin-top:10px">Mostra questo QR allo staff.</div>';
		}
		return CardVerify::layout( ResourceGate::label( $x['state'] ), self::color( $x['state'] ), $body );
	}

	/** Lo staff registra (o toglie) l'ingresso. */
	public static function do_gate(): void {
		check_admin_referer( self::ACTION );
		$post  = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
		$param = (string) ( $post['param'] ?? '' );
		$back  = self::url( $param );
		try {
			if ( ! self::staff() ) {
				throw new \InvalidArgumentException( 'Solo lo staff registra gli ingressi: accedi con il tuo account.' );
			}
			$x = self::result( $param );
			if ( 'invalid' === $x['state'] ) {
				throw new \InvalidArgumentException( 'QR non valido.' );
			}
			if ( 'undo' === ( $post['do'] ?? '' ) ) {
				Resources::undo_check_in( (int) $x['r']['id'] );
				wp_safe_redirect( Flash::url( $back, 'asemf', 'Ingresso tolto.', '' ) );
			} else {
				Resources::check_in( (int) $x['r']['id'], get_current_user_id() );
				wp_safe_redirect( Flash::url( $back, 'asemf', 'Ingresso registrato.', '' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( Flash::url( $back, 'asemf', '', $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			wp_safe_redirect( Flash::url( $back, 'asemf', '', 'Operazione non riuscita. Riprova più tardi.' ) );
		}
		exit;
	}
}

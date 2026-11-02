<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Protezione dalle richieste false (CSRF) per i moduli e i collegamenti dell'area soci. Per chi è collegato a WordPress sono i nonce
 * di sempre, identici a prima; per chi è entrato con l'accesso proprio è il token legato alla sua sessione (MemberSession::csrf).
 * Sostituisce wp_nonce_field, wp_create_nonce, wp_verify_nonce, check_admin_referer e check_ajax_referer nei punti dei soci.
 */
final class Csrf {

	/** Il nome del campo dei moduli (quello che WordPress usa per i nonce). */
	const FIELD = '_wpnonce';

	public static function create( string $action ): string {
		return MemberSession::is_own_session() ? MemberSession::csrf( $action ) : wp_create_nonce( $action );
	}

	public static function verify( string $token, string $action ): bool {
		$token = trim( $token );
		if ( MemberSession::is_own_session() ) {
			return MemberSession::check_csrf( $action, $token );
		}
		return false !== wp_verify_nonce( $token, $action );
	}

	/** Campo nascosto per un modulo. */
	public static function field( string $action, string $name = self::FIELD ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::create( $action ) ) . '">';
	}

	private static function request_token( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- è proprio il controllo del token
		return isset( $_REQUEST[ $name ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $name ] ) ) : '';
	}

	/** Come check_admin_referer: se il token non vale, la richiesta si ferma. */
	public static function check( string $action, string $name = self::FIELD ): void {
		if ( ! MemberSession::is_own_session() ) {
			check_admin_referer( $action, $name );
			return;
		}
		if ( ! MemberSession::check_csrf( $action, self::request_token( $name ) ) ) {
			wp_die( esc_html( 'Il link che hai seguito è scaduto. Torna indietro, ricarica la pagina e riprova.' ), '', array( 'response' => 403 ) );
		}
	}

	/** Come check_ajax_referer. */
	public static function check_ajax( string $action, string $name = 'nonce' ): void {
		if ( ! MemberSession::is_own_session() ) {
			check_ajax_referer( $action, $name );
			return;
		}
		if ( ! MemberSession::check_csrf( $action, self::request_token( $name ) ) ) {
			wp_die( '-1', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Per le azioni di admin-post.php riservate a chi è entrato: chi ha l'accesso proprio non è un utente WordPress e finirebbe
	 * nel ramo «nopriv». Qui lo si fa passare sull'azione vera; tutti gli altri vanno dove andavano prima.
	 */
	public static function nopriv( callable $handler, callable $fallback ): callable {
		return static function () use ( $handler, $fallback ) {
			if ( MemberSession::is_own_session() ) {
				$handler();
				return;
			}
			$fallback();
		};
	}
}

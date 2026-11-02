<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\ClientIp;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MemberLogin;
use AssociazioneSemplice\MemberSession;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\SessionToken;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina di accesso dei soci senza utente WordPress: email con link o codice, oppure password. Con la sessione aperta mostra
 * chi sei, l'uscita e la scelta della password. Con l'interruttore «member_sessions» spento non fa niente e il sito resta com'è.
 * Gli indirizzi sono ?asem_member_login=1 (pagina), ?asem_ml=… (link dell'email).
 */
final class MemberLoginPage {

	const SENT = 'Se l\'email è di un socio ti abbiamo scritto: apri il link oppure scrivi qui sotto il codice a 6 cifre (controlla anche la posta indesiderata).';
	const FAIL = 'Email, codice o password non corretti, oppure troppi tentativi: riprova tra qualche minuto.';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function url(): string {
		return add_query_arg( 'asem_member_login', '1', home_url( '/' ) );
	}

	private static function post( string $k ): string {
		$d = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification -- il controllo è in handle()
		return isset( $d[ $k ] ) && is_string( $d[ $k ] ) ? trim( $d[ $k ] ) : '';
	}

	/** Troppi tentativi dalla stessa connessione? Conta ogni invio. */
	private static function throttled(): bool {
		$key   = 'asem_ml_ip_' . md5( ClientIp::get() );
		$tries = (int) get_transient( $key );
		if ( $tries >= max( 20, 2 * Limits::get( 'first_access_per_ip' ) ) ) {
			return true;
		}
		set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function handle(): void {
		$is_page = isset( $_GET['asem_member_login'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$token   = isset( $_GET['asem_ml'] ) ? sanitize_text_field( wp_unslash( $_GET['asem_ml'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $is_page && '' === $token ) {
			return;
		}
		if ( ! MemberSession::enabled() || ! Edition::allows( 'member_area' ) ) {
			return; // spento: la pagina non esiste e il sito si comporta come prima
		}
		$msg = '';
		$err = '';
		$step = '';
		$mail = '';
		$post = isset( $_SERVER['REQUEST_METHOD'], $_POST['asem_ml_do'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$do   = $post ? sanitize_key( self::post( 'asem_ml_do' ) ) : '';

		if ( $post ) {
			$session = MemberSession::current();
			if ( $session ) { // già dentro: azioni protette dal token della sessione
				if ( ! MemberSession::check_csrf( 'account', self::post( '_csrf' ) ) ) {
					$err = 'Sessione scaduta: ricarica la pagina e riprova.';
				} elseif ( 'logout' === $do ) {
					Audit::log( 'member.logout', 'person', (int) $session['person_id'] );
					MemberSession::destroy();
					self::done( 'Sei uscito.' );
				} elseif ( 'logout_all' === $do ) {
					Audit::log( 'member.logout_all', 'person', (int) $session['person_id'] );
					MemberSession::destroy_all( (int) $session['person_id'] );
					MemberSession::destroy();
					self::done( 'Sei uscito da tutti i dispositivi.' );
				} elseif ( 'password' === $do ) {
					$err = MemberLogin::set_password( (int) $session['person_id'], self::post( 'password' ) );
					$msg = '' === $err ? 'Password salvata. Da ora puoi entrare anche con email e password.' : '';
				}
			} elseif ( ! wp_verify_nonce( self::post( '_asem_nonce' ), 'asem_member_login' ) ) {
				$err = 'Sessione scaduta: riprova.';
			} elseif ( self::throttled() ) {
				$err = self::FAIL;
			} elseif ( 'request' === $do ) {
				MemberLogin::request( self::post( 'email' ) );
				$msg  = self::SENT; // sempre la stessa risposta: non si scopre chi è socio
				$step = 'code';
				$mail = self::post( 'email' );
			} elseif ( 'code' === $do || 'password_login' === $do || 'token' === $do ) {
				$who = 'code' === $do ? MemberLogin::login_with_code( self::post( 'email' ), self::post( 'code' ) )
					: ( 'token' === $do ? MemberLogin::login_with_token( $token ) : MemberLogin::login_with_password( self::post( 'email' ), self::post( 'password' ) ) );
				if ( $who ) {
					wp_safe_redirect( self::url(), 303 );
					exit;
				}
				$err  = self::FAIL;
				$step = 'code' === $do ? 'code' : '';
				$mail = self::post( 'email' );
			}
		}

		CardVerify::send_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		CardVerify::output( self::page( $token, $msg, $err, $step, $mail ) );
		exit;
	}

	private static function done( string $message ): void {
		set_transient( 'asem_ml_bye_' . md5( ClientIp::get() ), $message, 60 );
		wp_safe_redirect( self::url(), 303 );
		exit;
	}

	private static function style(): array {
		$input = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;margin:4px 0 12px;border:1px solid #8c8f94;border-radius:8px';
		$btn   = 'width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . Assets::accent( '#2271b1' ) . ';color:#fff';
		return array( $input, $btn );
	}

	private static function field( string $label, string $name, string $type, string $auto, string $value = '', string $extra = '' ): string {
		list( $input ) = self::style();
		return '<label>' . esc_html( $label ) . '<input style="' . $input . '" type="' . $type . '" name="' . $name . '" value="' . esc_attr( $value ) . '" required autocomplete="' . $auto . '"' . $extra . '></label>';
	}

	private static function form( string $do, string $inner, string $button, bool $csrf = false ): string {
		list( , $btn ) = self::style();
		$guard = $csrf ? '<input type="hidden" name="_csrf" value="' . esc_attr( MemberSession::csrf( 'account' ) ) . '">' : wp_nonce_field( 'asem_member_login', '_asem_nonce', false, false );
		return '<form method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="asem_ml_do" value="' . esc_attr( $do ) . '">' . $guard . $inner
			. '<button type="submit" style="' . $btn . '">' . esc_html( $button ) . '</button></form>';
	}

	public static function page( string $token, string $msg, string $err, string $step, string $mail ): string {
		$body = ( '' !== $msg ? '<div class="w" style="color:#1a7f37">' . esc_html( $msg ) . '</div>' : '' ) . ( '' !== $err ? '<div class="w" style="color:#b32d2e">' . esc_html( $err ) . '</div>' : '' );
		$gap  = '<hr style="border:0;border-top:1px solid #dcdcde;margin:18px 0">';
		$session = MemberSession::current();
		if ( $session ) {
			$p     = Plugin::people()->get( (int) $session['person_id'] );
			$body .= '<div class="n">Sei dentro</div><p>' . esc_html( trim( (string) ( $p['first_name'] ?? '' ) . ' ' . (string) ( $p['last_name'] ?? '' ) ) ) . '</p>'
				. self::form( 'logout', '', 'Esci', true ) . $gap
				. '<div class="n">' . ( MemberLogin::has_password( (int) $session['person_id'] ) ? 'Cambia la password' : 'Scegli una password (facoltativa)' ) . '</div>'
				. '<p>Senza password entri sempre con il link o il codice che ti arriva per email.</p>'
				. self::form( 'password', self::field( 'Nuova password (almeno 8 caratteri)', 'password', 'password', 'new-password' ), 'Salva la password', true ) . $gap
				. self::form( 'logout_all', '', 'Esci da tutti i dispositivi', true );
			return CardVerify::layout( 'Il tuo accesso', Assets::accent( '#2271b1' ), $body );
		}
		$bye = (string) get_transient( 'asem_ml_bye_' . md5( ClientIp::get() ) );
		if ( '' !== $bye ) {
			delete_transient( 'asem_ml_bye_' . md5( ClientIp::get() ) );
			$body .= '<div class="w" style="color:#1a7f37">' . esc_html( $bye ) . '</div>';
		}
		if ( '' !== $token && SessionToken::is_well_formed( $token ) ) { // il link apre solo la conferma: i programmi che aprono i link delle email non lo consumano
			$body .= '<div class="n">Entra</div><p>Premi il pulsante per entrare.</p>'
				. '<form method="post" action="' . esc_url( add_query_arg( 'asem_ml', $token, home_url( '/' ) ) ) . '"><input type="hidden" name="asem_ml_do" value="token">' . wp_nonce_field( 'asem_member_login', '_asem_nonce', false, false )
				. '<button type="submit" style="' . self::style()[1] . '">Entra</button></form>';
			return CardVerify::layout( 'Accesso', Assets::accent( '#2271b1' ), $body );
		}
		$body .= '<div class="n">Accesso soci</div><p>Scrivi la tua email: ti mandiamo un link e un codice per entrare, senza password.</p>'
			. self::form( 'request', self::field( 'La tua email', 'email', 'email', 'email', $mail ), 'Mandami il link' );
		if ( 'code' === $step ) {
			$body .= $gap . self::form( 'code', self::field( 'Email', 'email', 'email', 'email', $mail ) . self::field( 'Codice a 6 cifre', 'code', 'text', 'one-time-code', '', ' inputmode="numeric" pattern="[0-9]{6}" maxlength="6"' ), 'Entra con il codice' );
		}
		$body .= $gap . '<div class="n">Hai scelto una password?</div>'
			. self::form( 'password_login', self::field( 'Email', 'email', 'email', 'username', $mail ) . self::field( 'Password', 'password', 'password', 'current-password' ), 'Entra con la password' );
		return CardVerify::layout( 'Accesso soci', Assets::accent( '#2271b1' ), $body );
	}
}

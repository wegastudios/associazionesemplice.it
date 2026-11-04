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
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina di accesso dei soci senza utente WordPress. Si entra con email e password. Chi non ricorda la password riceve un link che fa
 * entrare ma obbliga a sceglierne una nuova prima di navigare; chi preferisce può entrare senza password con un link o un codice via email.
 * La password va scelta alla prima entrata. Con l'accesso proprio spento la pagina non esiste.
 * Gli indirizzi sono ?asem_member_login=1 (pagina) e ?asem_ml=… (link dell'email).
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

	/** Dove si va dopo l'accesso: la pagina da cui si veniva (se è del sito), altrimenti l'area riservata o la pagina «Il tuo accesso». */
	private static function after_url( string $back ): string {
		$back = '' !== $back ? wp_validate_redirect( esc_url_raw( $back ), '' ) : '';
		if ( '' !== $back ) {
			return $back;
		}
		$page = (int) Settings::get( 'member_area_page_id' );
		$url  = $page ? (string) get_permalink( $page ) : '';
		return '' !== $url ? $url : self::url();
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
		MemberLogin::defer( true ); // le email di accesso partono dopo la risposta: il tempo di risposta non rivela chi è socio
		$msg  = '';
		$err  = '';
		$step = '';
		$mail = '';
		$back = isset( $_GET['_back'] ) ? esc_url_raw( wp_unslash( $_GET['_back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$post = isset( $_SERVER['REQUEST_METHOD'], $_POST['asem_ml_do'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$do   = $post ? sanitize_key( self::post( 'asem_ml_do' ) ) : '';
		if ( $post && '' !== self::post( '_back' ) ) {
			$back = esc_url_raw( self::post( '_back' ) );
		}

		if ( ! $post && '' !== $back && '' === $token && null !== MemberSession::session_person() ) { // già dentro e con la password: si torna da dove si veniva
			wp_safe_redirect( self::after_url( $back ), 303 );
			exit;
		}
		if ( $post ) {
			$session = MemberSession::current();
			if ( $session ) { // già dentro (anche se deve ancora scegliere la password): azioni protette dal token della sessione
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
					$pending = null !== MemberSession::pending_setup();
					if ( self::post( 'password' ) !== self::post( 'password2' ) ) {
						$err = 'Le due password non coincidono.';
					} elseif ( ! $pending && ! MemberLogin::check_password( (int) $session['person_id'], self::post( 'current_password' ) ) ) {
						$err = 'La password attuale non è corretta.'; // chi ha solo rubato una sessione non può cambiarla
					} else {
						$err = MemberLogin::set_password( (int) $session['person_id'], self::post( 'password' ) );
						if ( '' === $err ) {
							if ( $pending ) { // scelta la password si può navigare
								wp_safe_redirect( self::after_url( $back ), 303 );
								exit;
							}
							$msg = 'Password salvata. Gli altri dispositivi dovranno rientrare.';
						}
					}
				}
			} elseif ( ! wp_verify_nonce( self::post( '_asem_nonce' ), 'asem_member_login' ) ) {
				$err = 'Sessione scaduta: riprova.';
			} elseif ( self::throttled() ) {
				$err = self::FAIL;
			} elseif ( 'request' === $do || 'recover' === $do ) {
				MemberLogin::request( self::post( 'email' ), 'recover' === $do ? 'reset' : 'login' );
				$msg  = self::SENT; // sempre la stessa risposta: non si scopre chi è socio
				$step = 'code';
				$mail = self::post( 'email' );
			} elseif ( 'code' === $do || 'password_login' === $do || 'token' === $do ) {
				$who = 'code' === $do ? MemberLogin::login_with_code( self::post( 'email' ), self::post( 'code' ) )
					: ( 'token' === $do ? MemberLogin::login_with_token( $token ) : MemberLogin::login_with_password( self::post( 'email' ), self::post( 'password' ) ) );
				if ( $who ) {
					wp_safe_redirect( 'password_login' === $do ? self::after_url( $back ) : ( '' !== $back ? add_query_arg( '_back', rawurlencode( $back ), self::url() ) : self::url() ), 303 ); // dal link o dal codice si passa dalla pagina: se manca la password lì si sceglie, poi si torna da dove si veniva
					exit;
				}
				$err  = self::FAIL;
				$step = 'code' === $do ? 'code' : '';
				$mail = self::post( 'email' );
			}
		}

		CardVerify::send_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		CardVerify::output( self::page( $token, $msg, $err, $step, $mail, $back ) );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request(); // la risposta parte subito; l'email, se c'è, dopo
		} else {
			flush();
		}
		MemberLogin::send_queued();
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

	private static function form( string $do, string $inner, string $button, bool $csrf = false, string $back = '' ): string {
		list( , $btn ) = self::style();
		$guard = $csrf ? '<input type="hidden" name="_csrf" value="' . esc_attr( MemberSession::csrf( 'account' ) ) . '">' : wp_nonce_field( 'asem_member_login', '_asem_nonce', false, false );
		return '<form method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="asem_ml_do" value="' . esc_attr( $do ) . '">' . $guard
			. ( '' !== $back ? '<input type="hidden" name="_back" value="' . esc_url( $back ) . '">' : '' ) . $inner
			. '<button type="submit" style="' . $btn . '">' . esc_html( $button ) . '</button></form>';
	}

	/** Modulo email e password per l'area riservata (quando non si è entrati): quello predefinito dei soci senza utente WordPress. */
	public static function inline_login( string $back ): string {
		$f = '<p><label>Email<br><input type="email" name="email" required autocomplete="username" class="asemf-input"></label></p>'
			. '<p><label>Password<br><input type="password" name="password" required autocomplete="current-password" class="asemf-input"></label></p>';
		return '<form class="asemf-form" method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="asem_ml_do" value="password_login">'
			. wp_nonce_field( 'asem_member_login', '_asem_nonce', false, false ) . '<input type="hidden" name="_back" value="' . esc_url( $back ) . '">' . $f
			. '<button type="submit" class="asemf-btn wp-element-button">Accedi</button></form>';
	}

	public static function page( string $token, string $msg, string $err, string $step, string $mail, string $back = '' ): string {
		$body = ( '' !== $msg ? '<div class="w" style="color:#1a7f37">' . esc_html( $msg ) . '</div>' : '' ) . ( '' !== $err ? '<div class="w" style="color:#b32d2e">' . esc_html( $err ) . '</div>' : '' );
		$gap  = '<hr style="border:0;border-top:1px solid #dcdcde;margin:18px 0">';

		$pending = MemberSession::pending_setup(); // entrato ma senza password valida: solo scelta della password
		if ( $pending ) {
			$intro = 'reset' === $pending['state']
				? 'Hai chiesto di reimpostare la password: scegline una nuova per continuare.'
				: 'Prima di continuare scegli la tua password: la userai per entrare ogni volta (potrai sempre usare anche il link che ti arriva per email).';
			$body .= '<div class="n">Scegli la tua password</div><p>' . esc_html( $intro ) . '</p>'
				. self::form(
					'password',
					self::field( 'Nuova password (almeno 8 caratteri)', 'password', 'password', 'new-password', '', ' minlength="8"' ) . self::field( 'Ripeti la password', 'password2', 'password', 'new-password', '', ' minlength="8"' ),
					'Salva e continua',
					true,
					$back
				) . $gap . self::form( 'logout', '', 'Esci', true );
			return CardVerify::layout( 'Scegli la password', Assets::accent( '#2271b1' ), $body );
		}

		$session = MemberSession::current();
		if ( $session && MemberSession::session_person() ) {
			$p     = Plugin::people()->get( (int) $session['person_id'] );
			$area  = (int) Settings::get( 'member_area_page_id' ) ? (string) get_permalink( (int) Settings::get( 'member_area_page_id' ) ) : '';
			list( , $btn ) = self::style();
			$body .= '<div class="n">Sei dentro</div><p>' . esc_html( trim( (string) ( $p['first_name'] ?? '' ) . ' ' . (string) ( $p['last_name'] ?? '' ) ) ) . '</p>'
				. ( '' !== $area ? '<p><a href="' . esc_url( $area ) . '" style="' . $btn . ';display:block;text-align:center;text-decoration:none;box-sizing:border-box">Vai alla tua area</a></p>' : '' )
				. self::form( 'logout', '', 'Esci', true ) . $gap
				. '<div class="n">Cambia la password</div>'
				. self::form( 'password', self::field( 'Password attuale', 'current_password', 'password', 'current-password' ) . self::field( 'Nuova password (almeno 8 caratteri)', 'password', 'password', 'new-password', '', ' minlength="8"' ) . self::field( 'Ripeti la password', 'password2', 'password', 'new-password', '', ' minlength="8"' ), 'Salva la password', true ) . $gap
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
		$body .= '<div class="n">Accesso soci</div>'
			. self::form( 'password_login', self::field( 'Email', 'email', 'email', 'username', $mail ) . self::field( 'Password', 'password', 'password', 'current-password' ), 'Entra', false, $back )
			. $gap . '<div class="n">Non ricordi la password?</div><p>Scrivi la tua email: ti mandiamo un link che ti fa scegliere una nuova password.</p>'
			. self::form( 'recover', self::field( 'La tua email', 'email', 'email', 'email', $mail ), 'Mandami il link per reimpostarla', false, $back )
			. $gap . '<div class="n">Preferisci entrare senza password?</div><p>Ti mandiamo un link e un codice per email.</p>'
			. self::form( 'request', self::field( 'La tua email', 'email', 'email', 'email', $mail ), 'Mandami un link o un codice', false, $back );
		if ( 'code' === $step ) {
			$body .= $gap . '<div class="n">Hai il codice?</div>'
				. self::form( 'code', self::field( 'Email', 'email', 'email', 'email', $mail ) . self::field( 'Codice a 6 cifre', 'code', 'text', 'one-time-code', '', ' inputmode="numeric" pattern="[0-9]{6}" maxlength="6"' ), 'Entra con il codice', false, $back );
		}
		return CardVerify::layout( 'Accesso soci', Assets::accent( '#2271b1' ), $body );
	}
}

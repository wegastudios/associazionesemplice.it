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
 * entrare ma obbliga a sceglierne una nuova prima di navigare («Password dimenticata?» apre la schermata «Reimposta la password»); chi preferisce
 * può entrare senza password con «Mandami un link» (il codice a 6 cifre dell'email serve a chi non può aprire il link da quel dispositivo).
 * La password va scelta alla prima entrata. Con l'accesso proprio spento la pagina non esiste.
 * Gli indirizzi sono ?asem_member_login=1 (pagina) e ?asem_ml=… (link dell'email).
 */
final class MemberLoginPage {

	const SENT    = 'Se l\'email è di un socio ti abbiamo scritto: apri il link che trovi nell\'email (controlla anche la posta indesiderata).';
	const FAIL    = 'Email, codice o password non corretti. Controlla e riprova, oppure premi «Mandami un link» per entrare senza password.';
	const BLOCKED = 'Troppi tentativi da questa connessione. Aspetta un\'ora e riprova, oppure chiedi aiuto alla segreteria.';

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
		if ( isset( $_GET['asem_ml_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- solo mostra la schermata «Reimposta la password»
			$step = 'reset';
		}
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
				$err = self::BLOCKED;
			} elseif ( 'request' === $do || 'recover' === $do ) {
				MemberLogin::request( self::post( 'email' ), 'recover' === $do ? 'reset' : 'login' );
				$msg  = self::SENT; // sempre la stessa risposta: non si scopre chi è socio
				$step = 'recover' === $do ? 'reset_code' : 'code';
				$mail = self::post( 'email' );
			} elseif ( 'code' === $do || 'password_login' === $do || 'token' === $do ) {
				$who = 'code' === $do ? MemberLogin::login_with_code( self::post( 'email' ), self::post( 'code' ) )
					: ( 'token' === $do ? MemberLogin::login_with_token( $token ) : MemberLogin::login_with_password( self::post( 'email' ), self::post( 'password' ), '' !== self::post( 'remember' ) ) );
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

	/** Pulsante «Esci» per l'area soci: solo per chi è entrato con l'accesso proprio dei soci (invio con il segnale di sicurezza della sessione). */
	public static function logout_button(): string {
		if ( ! MemberSession::current() ) {
			return '';
		}
		return '<form class="asemf-logout" method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="asem_ml_do" value="logout">'
			. '<input type="hidden" name="_csrf" value="' . esc_attr( MemberSession::csrf( 'account' ) ) . '">'
			. '<button type="submit" class="asemf-btn asemf-btn-ghost wp-element-button">Esci</button></form>';
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
		// Il codice si mostra solo dopo aver chiesto il link: serve a chi non può aprirlo da questo dispositivo.
		$code_box = 'code' === $step || 'reset_code' === $step
			? $gap . '<div class="n">Non riesci ad aprire il link da questo dispositivo?</div><p>Scrivi qui il codice a 6 cifre che trovi nell\'email.</p>'
				. self::form( 'code', self::field( 'Email', 'email', 'email', 'email', $mail ) . self::field( 'Codice a 6 cifre', 'code', 'text', 'one-time-code', '', ' inputmode="numeric" pattern="[0-9]{6}" maxlength="6"' ), 'Entra con il codice', false, $back )
			: '';
		if ( 'reset' === $step || 'reset_code' === $step ) { // «Password dimenticata?»: una schermata sola, con la sola email
			$body .= '<div class="n">Reimposta la password</div><p>Scrivi la tua email: ti mandiamo un link che ti fa scegliere una nuova password.</p>'
				. self::form( 'recover', self::field( 'Email', 'email', 'email', 'username', $mail ), 'Mandami il link per reimpostarla', false, $back )
				. $code_box
				. '<p style="margin:18px 0 0"><a href="' . esc_url( '' !== $back ? add_query_arg( '_back', rawurlencode( $back ), self::url() ) : self::url() ) . '">Torna all\'accesso</a></p>';
			return CardVerify::layout( 'Reimposta la password', Assets::accent( '#2271b1' ), $body );
		}
		$reset_url = add_query_arg( 'asem_ml_reset', '1', self::url() );
		if ( '' !== $back ) {
			$reset_url = add_query_arg( '_back', rawurlencode( $back ), $reset_url );
		}
		$body .= '<div class="n">Accesso soci</div>' . self::login_form( $mail, $back )
			. '<p style="margin:14px 0 0"><a href="' . esc_url( $reset_url ) . '">Password dimenticata?</a></p>'
			. $code_box;
		return CardVerify::layout( 'Accesso soci', Assets::accent( '#2271b1' ), $body );
	}

	/**
	 * Schermata di accesso: un solo campo email e un solo campo password, con due pulsanti affiancati (impilati a tutta larghezza da telefono):
	 * «Entra» con la password e «Mandami un link» per entrare senza password. Il primo pulsante è quello dell'invio con la tastiera.
	 */
	private static function login_form( string $mail, string $back ): string {
		list( $input, $btn ) = self::style();
		$main = str_replace( 'width:100%;', '', $btn ) . ';flex:1 1 200px;min-height:48px';
		$alt  = str_replace( 'width:100%;', '', $btn ) . ';flex:1 1 200px;min-height:48px;background:#fff;color:' . Assets::accent( '#2271b1' ) . ';border:2px solid ' . Assets::accent( '#2271b1' );
		return '<form method="post" action="' . esc_url( self::url() ) . '">' . wp_nonce_field( 'asem_member_login', '_asem_nonce', false, false )
			. ( '' !== $back ? '<input type="hidden" name="_back" value="' . esc_url( $back ) . '">' : '' )
			. self::field( 'Email', 'email', 'email', 'username', $mail )
			. '<label>Password<input style="' . $input . '" type="password" name="password" autocomplete="current-password"></label>'
			. '<label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;font-weight:400"><input type="checkbox" name="remember" value="1" style="width:auto;margin:0">Ricordami su questo dispositivo</label>'
			. '<div style="display:flex;flex-wrap:wrap;gap:10px">'
			. '<button type="submit" name="asem_ml_do" value="password_login" style="' . $main . '">Entra</button>'
			. '<button type="submit" name="asem_ml_do" value="request" formnovalidate style="' . $alt . '">Mandami un link</button></div>'
			. '<p style="font-size:14px;color:#50575e;margin:10px 0 0">Con «Mandami un link» entri senza password: riceverai in email un link valido ' . (int) ( MemberLogin::TTL_SECONDS / 60 ) . ' minuti, con quello potrai entrare.</p></form>';
	}
}

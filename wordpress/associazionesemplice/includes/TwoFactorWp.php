<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Verifica in due passaggi per gli utenti WordPress e obbligo deciso dall'ente. Il motore (codici, riserve, registro) è {@see TwoFactor}; il soggetto
 * di un utente è {@see TwoFactor::user()} e i suoi dati stanno nelle usermeta, che WordPress cancella insieme all'utente.
 *
 * - Login di wp-login.php: dopo la password giusta, se la verifica è accesa, non si entra. Il filtro «authenticate» risponde con un errore
 *   (asem_tfa_required) e la pagina di accesso mostra al suo posto il modulo del codice; il codice giusto mette il cookie di WordPress.
 * - Password per le applicazioni e XML-RPC passano dallo stesso filtro: con la verifica accesa o imposta dall'ente sono rifiutate (e le
 *   password per le applicazioni non si possono creare), perché non chiedono il secondo passaggio.
 * - Obbligo dell'ente (Limiti: tfa_required_staff, tfa_required_members): chi deve averla e non l'ha accesa va subito alla pagina per accenderla
 *   (pannello) o vede solo «Il mio profilo» con la verifica (area soci). L'amministratore di WordPress (ruolo administrator) segue le regole di
 *   WordPress: l'obbligo dell'ente non lo tocca.
 * - Coesistenza: con un altro plugin di verifica in due passaggi attivo (Wordfence, Two Factor, WP 2FA, Solid Security, miniOrange) per gli utenti
 *   WordPress ci facciamo da parte e lo si dice in Impostazioni → Limiti e soglie. L'area soci con l'accesso proprio resta nostra.
 */
final class TwoFactorWp {

	const PENDING_TTL = 600;
	const REQUIRED    = 'asem_tfa_required';
	const API_REFUSED = 'asem_tfa_api';

	/** @var string token del passaggio a metà appena creato (per la pagina di accesso di questa richiesta) */
	private static $last_token = '';

	/** Un altro plugin di verifica in due passaggi attivo: il suo nome, vuoto se nessuno. Filtro `asem_tfa_other_plugin`. */
	public static function other_plugin(): string {
		$known = array(
			'Two_Factor_Core'                     => 'Two Factor',
			'WP2FA\\WP2FA'                        => 'WP 2FA',
			'WordfenceLS\\Controller_WordfenceLS' => 'Wordfence Login Security',
			'ITSEC_Two_Factor'                    => 'Solid Security (iThemes Security)',
			'Miniorange_Authentication'           => 'miniOrange 2-Factor',
		);
		$found = '';
		foreach ( $known as $class => $name ) {
			if ( class_exists( $class, false ) ) {
				$found = $name;
				break;
			}
		}
		return (string) apply_filters( 'asem_tfa_other_plugin', $found );
	}

	public static function register(): void {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 99, 3 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'login_errors' ), 10, 2 );
		add_action( 'login_form_asem_tfa', array( __CLASS__, 'login_code' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'app_passwords_available' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 30, 3 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_admin' ), 1 );
	}

	// ---------- Chi la deve avere ----------

	/** L'amministratore di WordPress segue le regole di WordPress: l'obbligo dell'ente non lo tocca. */
	public static function is_wp_admin( \WP_User $u ): bool {
		return in_array( 'administrator', (array) $u->roles, true ) || ( is_multisite() && is_super_admin( $u->ID ) );
	}

	/** Lavora nel pannello del plugin (segreteria, dipendenti, presidente e vice con utente) senza essere amministratore di WordPress. */
	public static function is_staff( \WP_User $u ): bool {
		return ! self::is_wp_admin( $u ) && ( user_can( $u, Plugin::CAP_OPS ) || Employees::is_employee( $u ) || in_array( Plugin::ROLE_SECRETARY, (array) $u->roles, true ) );
	}

	/** L'ente richiede la verifica a questo utente WordPress? (dipendenti e segreteria, oppure soci con utente WordPress: due spunte separate) */
	public static function required_for_user( \WP_User $u ): bool {
		if ( ! $u->exists() || self::is_wp_admin( $u ) || '' !== self::other_plugin() ) {
			return false;
		}
		if ( self::is_staff( $u ) ) {
			return Limits::flag( 'tfa_required_staff' );
		}
		return Limits::flag( 'tfa_required_members' ) && null !== Access::person_for_user( (int) $u->ID );
	}

	/** L'ente richiede la verifica ai soci (accesso proprio dell'area soci)? */
	public static function required_for_person( ?array $p ): bool {
		return $p && Limits::flag( 'tfa_required_members' ) && MemberType::can_login( (string) $p['type'] );
	}

	/** Messaggio per chi deve ancora accenderla. */
	const MUST_SETUP = 'L\'associazione richiede la verifica in due passaggi: accendila in «Il mio profilo» per continuare.';

	/** Azioni dell'area soci che restano aperte a chi deve ancora accenderla. */
	const FRONT_SETUP_ACTIONS = array( 'asem_front_tfa_start', 'asem_front_tfa_confirm', 'asem_front_tfa_cancel' );

	/** Messaggio per chi prova a spegnerla mentre l'ente la richiede. */
	const CANT_OFF = 'L\'associazione richiede la verifica in due passaggi: non si può spegnere. Per un telefono nuovo crea nuovi codici di riserva o chiedi all\'amministratore.';

	/** L'ente la richiede a questo soggetto (scheda o utente WordPress)? */
	public static function subject_required( int $subject ): bool {
		if ( $subject > 0 ) {
			return self::required_for_person( Plugin::people()->get( $subject ) );
		}
		$u = $subject < 0 ? get_userdata( TwoFactor::user_of( $subject ) ) : false;
		return $u instanceof \WP_User && self::required_for_user( $u );
	}

	/** Deve accenderla prima di continuare? */
	public static function user_must_setup( \WP_User $u ): bool {
		return self::required_for_user( $u ) && ! TwoFactor::is_on( TwoFactor::user( (int) $u->ID ) );
	}

	/**
	 * Il soggetto della verifica di chi sta guardando l'area soci: la scheda per l'accesso proprio, l'utente per chi è entrato da WordPress
	 * (0 se nessuno, o se per gli utenti WordPress c'è un altro plugin).
	 */
	public static function viewer_subject(): int {
		if ( MemberSession::is_own_session() ) {
			$p = MemberSession::session_person();
			return $p ? (int) $p['id'] : 0;
		}
		$uid = get_current_user_id();
		return $uid > 0 && '' === self::other_plugin() ? TwoFactor::user( $uid ) : 0;
	}

	/** Chi sta guardando l'area soci deve accendere la verifica prima di tutto il resto? */
	public static function viewer_must_setup(): bool {
		if ( MemberSession::is_own_session() ) {
			$p = MemberSession::session_person();
			return $p && self::required_for_person( $p ) && ! TwoFactor::is_on( (int) $p['id'] );
		}
		$u = wp_get_current_user();
		return $u && $u->exists() && self::user_must_setup( $u );
	}

	// ---------- Login di WordPress ----------

	/** Richiesta senza persona davanti (XML-RPC, REST con password per le applicazioni): il secondo passaggio non si può chiedere. */
	private static function api_request(): bool {
		return ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || did_action( 'application_password_did_authenticate' ) > 0 || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Filtro «authenticate», dopo il controllo della password: con la verifica accesa non si entra ancora. Le richieste delle applicazioni
	 * (password per le applicazioni, XML-RPC) con la verifica accesa o imposta sono rifiutate.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public static function authenticate( $user, $username, $password ) {
		if ( ! $user instanceof \WP_User || '' === (string) $password || '' !== self::other_plugin() ) {
			return $user;
		}
		$on = TwoFactor::is_on( TwoFactor::user( (int) $user->ID ) );
		if ( self::api_request() ) {
			if ( $on || self::required_for_user( $user ) ) {
				Audit::log( 'user.tfa_api_refused', 'user', (int) $user->ID );
				return new \WP_Error( self::API_REFUSED, 'Con la verifica in due passaggi le password per le applicazioni e XML-RPC non si possono usare.' );
			}
			return $user;
		}
		if ( ! $on ) {
			return $user;
		}
		$remember = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification -- la casella «Ricordami» del modulo di accesso di WordPress, che non ha nonce
		self::$last_token = self::begin( (int) $user->ID, $remember );
		return new \WP_Error( self::REQUIRED, 'Scrivi il codice della verifica in due passaggi. <a href="' . esc_url( wp_login_url() ) . '">Entra dalla pagina di accesso</a> per scriverlo.' );
	}

	private static function pending_key( string $raw ): string {
		return 'asem_tfaw_p_' . substr( SessionToken::hash( $raw ), 0, 40 );
	}

	/** Passaggio a metà: token casuale (nel modulo), di 10 minuti. @return string il token */
	public static function begin( int $user_id, bool $remember ): string {
		$raw = SessionToken::generate();
		Counters::set( self::pending_key( $raw ), array( 'u' => $user_id, 'r' => $remember ? 1 : 0 ), self::PENDING_TTL );
		Audit::log( 'user.tfa_asked', 'user', $user_id );
		if ( TwoFactor::needs_reset( TwoFactor::user( $user_id ) ) ) {
			Audit::log( 'user.tfa_unreadable', 'user', $user_id );
		}
		return $raw;
	}

	/** @return array{u:int,r:int}|null */
	public static function pending( string $raw ): ?array {
		if ( ! SessionToken::is_well_formed( $raw ) ) {
			return null;
		}
		$v = Counters::get( self::pending_key( $raw ) );
		return is_array( $v ) && ! empty( $v['u'] ) ? array( 'u' => (int) $v['u'], 'r' => (int) ( $v['r'] ?? 0 ) ) : null;
	}

	/**
	 * Secondo passaggio: il codice dell'app o un codice di riserva. @return array{0:string,1:int,2:bool} esito («ok», «wrong», «blocked»,
	 * «expired»), utente, «Ricordami». Con «ok» il passaggio a metà sparisce (il cookie lo mette chi chiama).
	 */
	public static function finish( string $raw, string $code ): array {
		$p = self::pending( $raw );
		if ( ! $p || ! get_userdata( $p['u'] ) ) {
			return array( 'expired', 0, false );
		}
		$r = TwoFactor::check( TwoFactor::user( $p['u'] ), $code, 'wp_login' );
		if ( 'ok' === $r ) {
			Counters::delete( self::pending_key( $raw ) );
			Audit::log( 'user.tfa_login', 'user', $p['u'] );
		}
		return array( $r, $p['u'], 1 === $p['r'] );
	}

	/** La pagina di accesso mostra il modulo del codice al posto dell'errore. */
	public static function login_errors( $errors, $redirect_to ) {
		if ( $errors instanceof \WP_Error && in_array( self::REQUIRED, $errors->get_error_codes(), true ) && '' !== self::$last_token ) {
			self::code_page( self::$last_token, (string) $redirect_to, '' );
			exit;
		}
		return $errors;
	}

	/** Modulo del codice (pagina di accesso di WordPress). */
	public static function form_html( string $raw, string $redirect_to, string $error, bool $reset = false ): string {
		return ( '' !== $error ? '<div id="login_error" class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>' : '' )
			. '<form name="asem_tfa" id="loginform" method="post" action="' . esc_url( site_url( 'wp-login.php?action=asem_tfa', 'login_post' ) ) . '">'
			. '<p>' . esc_html( $reset ? TwoFactor::RESET_MSG : 'Apri l\'app di autenticazione sul telefono e scrivi il codice di 6 cifre che vedi adesso. Se non hai il telefono, scrivi uno dei tuoi codici di riserva.' ) . '</p>'
			. '<p><label for="asem_tfa_code">Codice</label><input type="text" name="asem_tfa_code" id="asem_tfa_code" class="input" required autocomplete="one-time-code" inputmode="numeric" maxlength="11" autofocus></p>'
			. '<input type="hidden" name="asem_tfa_token" value="' . esc_attr( $raw ) . '">'
			. '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_to ) . '">'
			. '<p class="submit"><input type="submit" class="button button-primary button-large" value="Entra"></p></form>'
			. '<p style="clear:both;padding-top:12px">Hai perso il telefono e i codici di riserva? Chiedi all\'amministratore di spegnere la verifica dalla tua scheda.</p>';
	}

	private static function code_page( string $raw, string $redirect_to, string $error ): void {
		$p     = self::pending( $raw );
		$reset = $p && TwoFactor::needs_reset( TwoFactor::user( $p['u'] ) );
		nocache_headers();
		if ( function_exists( 'login_header' ) ) {
			login_header( 'Verifica in due passaggi' );
		}
		echo self::form_html( $raw, $redirect_to, $error, $reset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito con esc_html, esc_attr ed esc_url
		if ( function_exists( 'login_footer' ) ) {
			login_footer( 'asem_tfa_code' );
		}
	}

	/** wp-login.php?action=asem_tfa: arriva il codice. */
	public static function login_code(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- il token casuale del passaggio a metà fa da prova: senza, la richiesta non vale
		$raw  = isset( $_POST['asem_tfa_token'] ) ? sanitize_text_field( wp_unslash( $_POST['asem_tfa_token'] ) ) : '';
		$code = isset( $_POST['asem_tfa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['asem_tfa_code'] ) ) : '';
		$to   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		list( $res, $uid, $remember ) = self::finish( $raw, $code );
		if ( 'ok' === $res ) {
			$user = get_userdata( $uid );
			wp_set_auth_cookie( $uid, $remember, is_ssl() );
			wp_set_current_user( $uid );
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di WordPress: l'accesso è completo
			$dest = (string) apply_filters( 'login_redirect', '' !== $to ? $to : admin_url(), $to, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WordPress
			wp_safe_redirect( '' !== $dest ? $dest : admin_url() );
			exit;
		}
		if ( 'expired' === $res ) {
			wp_safe_redirect( wp_login_url( $to ) );
			exit;
		}
		self::code_page( $raw, $to, 'blocked' === $res ? TwoFactor::BLOCKED_MSG : TwoFactor::WRONG_MSG );
		exit;
	}

	/** Le password per le applicazioni non si creano per chi ha la verifica accesa o imposta. */
	public static function app_passwords_available( $available, $user ) {
		if ( $user instanceof \WP_User && '' === self::other_plugin() && ( TwoFactor::is_on( TwoFactor::user( (int) $user->ID ) ) || self::required_for_user( $user ) ) ) {
			return false;
		}
		return $available;
	}

	// ---------- Obbligo dell'ente ----------

	/** Indirizzo della pagina per accendere la verifica (pannello). */
	public static function setup_url(): string {
		return admin_url( 'admin.php?page=asem-tfa' );
	}

	/** Dopo il login chi deve accenderla va subito alla pagina per farlo. */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User && self::user_must_setup( $user ) && ! Gatekeeper::is_member_only( (array) $user->roles ) ) {
			return self::setup_url();
		}
		return $redirect_to;
	}

	/** Le azioni e le pagine che restano aperte a chi deve ancora accenderla. */
	const SETUP_ACTIONS = array( 'asem_tfa_wp_start', 'asem_tfa_wp_confirm', 'asem_tfa_wp_cancel' );

	/** Nel pannello, chi deve accenderla non usa altro finché non l'ha confermata. */
	public static function enforce_admin(): void {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->exists() || ! self::user_must_setup( $u ) || Gatekeeper::is_member_only( (array) $u->roles ) ) {
			return;
		}
		global $pagenow;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo il nome della pagina
		$action = Plugin::requested_action();
		if ( 'asem-tfa' === $page || in_array( $action, self::SETUP_ACTIONS, true ) || ( 'admin-ajax.php' === $pagenow && 'heartbeat' === $action ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			wp_die( '-1', '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( self::setup_url() );
		exit;
	}
}

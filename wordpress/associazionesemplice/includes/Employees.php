<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Utenti «Dipendente». Un dipendente è un utente WordPress con il ruolo `asem_employee`, che parte con le stesse capability della segreteria
 * (`asem_operate`). NON è una persona del libro soci: non ha tessera, non conta per le assemblee e per i soci del RUNTS. Se è anche socio
 * sono due cose separate: come dipendente entra con l'utente WordPress, come socio con l'accesso personale all'area soci
 * ({@see MemberSession}); nessun flusso li collega (vedi PeopleService::ensure_wp_user).
 *
 * I permessi che si possono togliere sono quelli di {@see EmployeeRules} e si tolgono per singolo utente (user meta). Il controllo è in tre punti:
 *  1. {@see Employees::gate()} su `admin_init`: pagine di amministrazione, admin-post (moduli, esportazioni, download) e admin-ajax;
 *  2. {@see Access::user_can()}: le capability «asem_*» (area riservata, REST) per incassi, spese, eventi e corsi;
 *  3. gli elenchi (attività, schede, riquadri della Bacheca) che mostrano solo ciò che resta permesso.
 */
final class Employees {

	const META_DENIED = 'asem_emp_denied'; // elenco dei permessi tolti
	const META_FROM   = 'asem_emp_from';   // primo giorno di servizio (Y-m-d, facoltativo)
	const META_TO     = 'asem_emp_to';     // ultimo giorno di servizio (Y-m-d, facoltativo)

	/** @var array<int,string[]> permessi tolti, per utente, in questa richiesta */
	private static $denied_cache = array();

	public static function register(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'inactive_has_no_caps' ), 9, 4 );
		add_action( 'admin_init', array( __CLASS__, 'gate' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), 999 );
		add_filter( 'asem_runts_workers_count', array( __CLASS__, 'runts_workers' ), 10, 2 );
		add_filter( 'asem_admin_hidden_pages', array( __CLASS__, 'hidden_pages' ) );
		add_filter( 'asem_admin_groups', array( __CLASS__, 'groups' ), 20 );
	}

	// ---------- Ruolo ----------

	/** Il ruolo «Dipendente»: stessi permessi della segreteria ({@see Plugin::ROLE_SECRETARY}). */
	public static function ensure_role(): void {
		if ( ! get_role( Plugin::ROLE_EMPLOYEE ) ) {
			add_role( Plugin::ROLE_EMPLOYEE, 'Dipendente', array( 'read' => true, Plugin::CAP_OPS => true ) );
		}
	}

	/** @param \WP_User|int $user */
	public static function is_employee( $user ): bool {
		$u = $user instanceof \WP_User ? $user : ( (int) $user > 0 ? get_userdata( (int) $user ) : false );
		return $u && in_array( Plugin::ROLE_EMPLOYEE, (array) $u->roles, true );
	}

	/**
	 * Permessi tolti a un utente. Vale solo per i dipendenti che non sono anche amministratori del sito: un amministratore non si limita mai.
	 *
	 * @return string[]
	 */
	public static function denied_for( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( isset( self::$denied_cache[ $user_id ] ) ) {
			return self::$denied_cache[ $user_id ];
		}
		$denied = array();
		if ( self::is_employee( $user_id ) && ! user_can( $user_id, Plugin::CAP ) ) {
			$denied = EmployeeRules::sanitize_denied( get_user_meta( $user_id, self::META_DENIED, true ) );
		}
		return self::$denied_cache[ $user_id ] = $denied;
	}

	/** Dimentica i permessi letti in questa richiesta (dopo un cambio di ruolo o di permessi). */
	public static function reset_cache(): void {
		self::$denied_cache = array();
	}

	/** L'utente corrente ha questo permesso (rimovibile)? Chi non è un dipendente limitato lo ha sempre. */
	public static function can( string $key ): bool {
		return EmployeeRules::has( self::denied_for( get_current_user_id() ), $key );
	}

	/** L'utente corrente può aprire questa pagina di amministrazione (per nascondere link e schede)? */
	public static function page_allowed( string $slug ): bool {
		return null === EmployeeRules::blocked_by( self::denied_for( get_current_user_id() ), EmployeeRules::page_need( $slug ) );
	}

	/** Tiene, in un elenco di attività (o di date, con la colonna «kind»), solo i tipi che il dipendente corrente può vedere. */
	public static function only_allowed_kinds( array $rows ): array {
		$denied = self::denied_for( get_current_user_id() );
		if ( ! $denied ) {
			return $rows;
		}
		return array_values( array_filter( $rows, function ( $r ) use ( $denied ) {
			return EmployeeRules::has( $denied, EmployeeRules::activity_key( (string) ( $r['kind'] ?? ActivityKind::EVENT ) ) );
		} ) );
	}

	/** Il dipendente è in servizio oggi? Fuori dal periodo di servizio non opera (come se non avesse il ruolo). */
	public static function is_in_service( int $user_id, ?string $on = null ): bool {
		return EmployeeRules::is_active( self::date_of( $user_id, self::META_FROM ), self::date_of( $user_id, self::META_TO ), $on ?? Db::today() );
	}

	private static function date_of( int $user_id, string $meta ): ?string {
		return EmployeeRules::clean_date( get_user_meta( $user_id, $meta, true ) );
	}

	/** Prima o dopo il periodo di servizio il ruolo non dà permessi operativi. */
	public static function inactive_has_no_caps( $allcaps, $caps, $args, $user ) {
		if ( $user instanceof \WP_User && ! empty( $allcaps[ Plugin::CAP_OPS ] ) && self::is_employee( $user ) && ! empty( $user->ID ) && ! self::is_in_service( (int) $user->ID ) ) {
			$allcaps[ Plugin::CAP_OPS ] = false;
		}
		return $allcaps;
	}

	// ---------- Chi può togliere i permessi ----------

	/** Amministratori del sito (capability di amministrazione del plugin), presidente e vicepresidente in regola (con il loro utente WordPress). */
	public static function can_manage( int $user_id ): bool {
		return $user_id > 0 && ( user_can( $user_id, Plugin::CAP ) || Access::is_board_operator( $user_id ) );
	}

	// ---------- Controllo unico ----------

	/** Controlla pagine, azioni, esportazioni e chiamate ajax del dipendente corrente. */
	public static function gate(): void {
		self::ensure_role();
		$denied = self::denied_for( get_current_user_id() );
		if ( ! $denied ) {
			return;
		}
		global $pagenow;
		$need = null;
		$req  = self::request_values();
		if ( 'admin-post.php' === $pagenow || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			$need = self::action_need( sanitize_key( (string) $req['action'] ), $req );
		} elseif ( '' !== $req['page'] ) {
			$page = sanitize_key( (string) $req['page'] );
			$need = 'asem-activity' === $page ? self::activities_need( $req, 'asem_save_activity' ) : EmployeeRules::page_need( $page );
		}
		$blocked = EmployeeRules::blocked_by( $denied, $need );
		if ( null !== $blocked ) {
			self::deny( $blocked );
		}
	}

	/** I soli dati della richiesta che servono a decidere (numeri e testi brevi ripuliti): il nonce e i dati veri li tratta il gestore dell'azione. @return array<string,mixed> */
	private static function request_values(): array {
		$out = array();
		// phpcs:disable WordPress.Security.NonceVerification -- qui si decide solo se la richiesta è permessa; il nonce lo controlla il gestore
		foreach ( array( 'activity_id', 'session_id', 'id', 'category_id' ) as $k ) {
			$out[ $k ] = isset( $_REQUEST[ $k ] ) && is_scalar( $_REQUEST[ $k ] ) ? absint( wp_unslash( $_REQUEST[ $k ] ) ) : 0;
		}
		foreach ( array( 'action', 'page', 'target', 'kind', 'what' ) as $k ) {
			$out[ $k ] = isset( $_REQUEST[ $k ] ) && is_scalar( $_REQUEST[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ $k ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification
		return $out;
	}

	/** Cosa serve per l'azione richiesta, anche quando dipende dai dati (tipo di attività, categoria, esportazione). @param array<string,mixed> $req */
	private static function action_need( string $action, array $req ): ?array {
		if ( 'asem_export' === $action ) {
			return EmployeeRules::export_need( sanitize_key( (string) $req['what'] ) );
		}
		if ( 'asem_quick_cash' === $action ) { // la «cassa rapida» registra un incasso o una spesa secondo la categoria scelta
			return array( 'all' => array( self::quick_cash_is_expense( (int) ( $req['category_id'] ?? 0 ) ) ? EmployeeRules::EXPENSES : EmployeeRules::COLLECT ) );
		}
		if ( in_array( $action, EmployeeRules::activity_actions(), true ) ) {
			return self::activities_need( $req, $action );
		}
		return EmployeeRules::action_need( $action );
	}

	/** Vero se la categoria della cassa rapida è una spesa. Una categoria sconosciuta conta come spesa (la più prudente: serve la registrazione delle spese). */
	private static function quick_cash_is_expense( int $category_id ): bool {
		foreach ( Plugin::ledger()->categories() as $c ) {
			if ( (int) $c['id'] === $category_id ) {
				return 'general_cost' === $c['kind'];
			}
		}
		return true;
	}

	/**
	 * Quali permessi servono per un'azione sulle attività: quello del tipo dell'attività (corso o evento). Se dai dati ricevuti non si riesce
	 * a capire di quale attività si tratta servono entrambi: nel dubbio si nega.
	 *
	 * @param array<string,mixed> $req dati della richiesta
	 */
	public static function activities_need( array $req, string $action ): array {
		$svc    = Plugin::activities();
		$keys   = array();
		$known  = true;
		$any_id = false;
		$of_activity = function ( int $id ) use ( $svc, &$keys, &$known ) {
			$a = $svc->get( $id );
			if ( $a ) {
				$keys[] = EmployeeRules::activity_key( (string) $a['kind'] );
			} else {
				$known = false;
			}
		};
		$of_session = function ( int $id ) use ( $svc, $of_activity, &$known ) {
			$s = $svc->session( $id );
			if ( $s ) {
				$of_activity( (int) $s['activity_id'] );
			} else {
				$known = false;
			}
		};
		if ( ! empty( $req['activity_id'] ) ) {
			$any_id = true;
			$of_activity( (int) $req['activity_id'] );
		}
		if ( ! empty( $req['session_id'] ) ) {
			$any_id = true;
			$of_session( (int) $req['session_id'] );
		}
		if ( ! empty( $req['target'] ) && preg_match( '/^([as]):(\d+)$/', (string) $req['target'], $m ) ) { // iscrizione rapida: «a:» corso, «s:» data di un evento
			$any_id = true;
			if ( 'a' === $m[1] ) {
				$of_activity( (int) $m[2] );
			} else {
				$of_session( (int) $m[2] );
			}
		}
		if ( ! empty( $req['id'] ) && in_array( $action, array( 'asem_save_activity', 'asem_duplicate_activity', 'asem_delete_activity', 'asem_update_session', 'asem_cancel_session' ), true ) ) {
			$any_id = true;
			if ( in_array( $action, array( 'asem_update_session', 'asem_cancel_session' ), true ) ) {
				$of_session( (int) $req['id'] );
			} else {
				$of_activity( (int) $req['id'] );
			}
		}
		if ( ! empty( $req['kind'] ) && ActivityKind::is_valid( (string) $req['kind'] ) ) { // il tipo scritto nel modulo conta insieme a quello dell'attività esistente
			$any_id = true;
			$keys[] = EmployeeRules::activity_key( (string) $req['kind'] );
		}
		if ( ! $any_id || ! $known ) {
			return array( 'all' => array( EmployeeRules::COURSES, EmployeeRules::EVENTS ) );
		}
		return array( 'all' => array_values( array_unique( $keys ) ) );
	}

	/** Chiude la porta con un messaggio chiaro (pagina, admin-post o ajax). */
	private static function deny( string $key ): void {
		$msg = EmployeeRules::denied_message( $key );
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => $msg ), 403 );
		}
		wp_die( esc_html( $msg ), 'Permesso non disponibile', array( 'response' => 403, 'back_link' => true ) );
	}

	/** Le capability «asem_*» (area riservata e REST): il dipendente senza il permesso non ha nemmeno quella. */
	public static function ability_denied( int $user_id, string $ability, int $object_id ): bool {
		$denied = self::denied_for( $user_id );
		if ( ! $denied ) {
			return false;
		}
		$kind = null;
		if ( in_array( $ability, array( 'asem_manage_event', 'asem_view_activity', 'asem_view_participants', 'asem_notify_activity', 'asem_door_cash' ), true ) ) {
			$a    = $object_id > 0 ? Plugin::activities()->get( $object_id ) : null;
			$kind = $a ? (string) $a['kind'] : ActivityKind::EVENT;
		}
		return null !== EmployeeRules::blocked_by( $denied, EmployeeRules::ability_need( $ability, $kind ) );
	}

	/** Toglie dal menu le voci che il dipendente non può aprire. */
	public static function trim_menu(): void {
		if ( ! self::denied_for( get_current_user_id() ) ) {
			return;
		}
		foreach ( array( 'asem-activities', 'asem-money' ) as $slug ) {
			if ( ! self::page_allowed( $slug ) ) {
				remove_submenu_page( 'asem', $slug );
			}
		}
	}

	// ---------- Schermata di gestione ----------

	public static function hidden_pages( array $pages ): array {
		$pages[] = array( 'asem-employees', 'Dipendenti', array( Admin\EmployeesPage::class, 'render' ) );
		return $pages;
	}

	/** La scheda «Dipendenti» accanto alla Rubrica soci, solo per chi può gestirli. */
	public static function groups( array $groups ): array {
		if ( isset( $groups['asem-people'][1] ) && self::can_manage( get_current_user_id() ) ) {
			$groups['asem-people'][1]['asem-employees'] = 'Dipendenti';
		}
		return $groups;
	}

	/** @return array<int,array{user:\WP_User,from:?string,to:?string,denied:string[],active:bool}> i dipendenti, in ordine di nome */
	public static function all(): array {
		$out = array();
		foreach ( get_users( array( 'role' => Plugin::ROLE_EMPLOYEE, 'orderby' => 'display_name', 'order' => 'ASC' ) ) as $u ) {
			$from  = self::date_of( (int) $u->ID, self::META_FROM );
			$to    = self::date_of( (int) $u->ID, self::META_TO );
			$out[] = array(
				'user'   => $u,
				'from'   => $from,
				'to'     => $to,
				'denied' => EmployeeRules::sanitize_denied( get_user_meta( (int) $u->ID, self::META_DENIED, true ) ),
				'active' => EmployeeRules::is_active( $from, $to, Db::today() ),
			);
		}
		return $out;
	}

	/** Il numero dei lavoratori per il RUNTS: i dipendenti in servizio alla data (senza date si è in servizio). */
	public static function runts_workers( $count, $date = '' ): int {
		$date = EmployeeRules::clean_date( (string) $date ) ?? Db::today();
		$rows = array();
		foreach ( self::all() as $e ) {
			$rows[] = array( 'from' => $e['from'], 'to' => $e['to'] );
		}
		return max( (int) $count, 0 ) + EmployeeRules::count_active( $rows, $date );
	}

	/**
	 * Crea un dipendente: un utente WordPress con il ruolo «Dipendente», una password casuale (mai scritta da nessuna parte) e l'invito per email
	 * a sceglierla. Solo chi può gestire i dipendenti. Non crea nessuna persona nel libro soci.
	 *
	 * @param array{first_name?:string,last_name?:string,email?:string,from?:string,to?:string} $d
	 * @return int id dell'utente WordPress
	 * @throws \InvalidArgumentException
	 */
	public static function create( array $d ): int {
		self::assert_manager();
		$first = trim( sanitize_text_field( (string) ( $d['first_name'] ?? '' ) ) );
		$last  = trim( sanitize_text_field( (string) ( $d['last_name'] ?? '' ) ) );
		$email = strtolower( trim( sanitize_email( (string) ( $d['email'] ?? '' ) ) ) );
		if ( '' === $first || '' === $last ) {
			throw new \InvalidArgumentException( 'Scrivi nome e cognome del dipendente.' );
		}
		if ( ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'Scrivi un\'email valida: serve per l\'invito e per l\'accesso.' );
		}
		list( $from, $to ) = self::clean_period( $d );
		if ( get_user_by( 'email', $email ) ) {
			throw new \InvalidArgumentException( 'Con questa email esiste già un utente del sito. L\'accesso da dipendente è separato da quello da socio: usa un\'altra email per il dipendente (anche lo stesso socio ne può avere due).' );
		}
		self::ensure_role();
		$uid = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => Plugin::ROLE_EMPLOYEE,
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw new \InvalidArgumentException( 'Impossibile creare l\'utente: ' . $uid->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$uid = (int) $uid;
		self::save_period( $uid, $from, $to );
		Audit::log( 'employee.created', 'user', $uid, array( 'from' => (string) $from, 'to' => (string) $to ) );
		return $uid;
	}

	/** Manda l'invito: il link per scegliere la password (vale 24 ore). @return bool true se l'email è partita */
	public static function send_invite( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::is_employee( $user ) ) {
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$link  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$text  = 'Ciao ' . $user->first_name . ",\n\nè stato creato per te l'accesso come dipendente" . ( '' !== $assoc ? ' di ' . $assoc : '' ) . ":\n\n" . $link
			. "\n\nApri il link, scegli la tua password e poi entra con questa email. Il link vale 24 ore: se scade, usa «Password dimenticata» nella pagina di accesso.\n\nSe non te lo aspettavi, ignora questo messaggio.";
		return (bool) Texts::mail( $user->user_email, 'Il tuo accesso come dipendente' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $text );
	}

	/** Cambia il periodo di servizio. @throws \InvalidArgumentException */
	public static function set_period( int $user_id, array $d ): void {
		self::assert_manager();
		self::assert_employee( $user_id );
		list( $from, $to ) = self::clean_period( $d );
		self::save_period( $user_id, $from, $to );
		Audit::log( 'employee.period', 'user', $user_id, array( 'from' => (string) $from, 'to' => (string) $to ) );
	}

	/**
	 * Fissa quali permessi sono tolti a un dipendente. Ogni cambio finisce nel registro azioni: chi (utente connesso), a chi, quale permesso, quando.
	 *
	 * @param string[] $denied permessi che devono risultare tolti (gli altri sono dati); i nomi fuori elenco si ignorano
	 * @throws \InvalidArgumentException se chi agisce non ha titolo o l'utente non è un dipendente
	 */
	public static function set_denied( int $user_id, array $denied ): void {
		self::assert_manager();
		self::assert_employee( $user_id );
		$new = EmployeeRules::sanitize_denied( $denied );
		$old = EmployeeRules::sanitize_denied( get_user_meta( $user_id, self::META_DENIED, true ) );
		if ( $new === $old ) {
			return;
		}
		if ( $new ) {
			update_user_meta( $user_id, self::META_DENIED, $new );
		} else {
			delete_user_meta( $user_id, self::META_DENIED );
		}
		unset( self::$denied_cache[ $user_id ] );
		foreach ( array_diff( $new, $old ) as $k ) {
			Audit::log( 'employee.permission_removed', 'user', $user_id, array( 'permission' => $k ) );
		}
		foreach ( array_diff( $old, $new ) as $k ) {
			Audit::log( 'employee.permission_restored', 'user', $user_id, array( 'permission' => $k ) );
		}
	}

	private static function assert_manager(): void {
		if ( ! self::can_manage( get_current_user_id() ) ) {
			throw new \InvalidArgumentException( 'Solo il presidente, il vicepresidente e gli amministratori del sito possono gestire i dipendenti.' );
		}
	}

	private static function assert_employee( int $user_id ): void {
		if ( ! self::is_employee( $user_id ) ) {
			throw new \InvalidArgumentException( 'Questo utente non è un dipendente.' );
		}
	}

	/** @return array{0:?string,1:?string} inizio e fine del servizio, già controllati */
	private static function clean_period( array $d ): array {
		$raw_from = trim( (string) ( $d['from'] ?? '' ) );
		$raw_to   = trim( (string) ( $d['to'] ?? '' ) );
		$from     = EmployeeRules::clean_date( $raw_from );
		$to       = EmployeeRules::clean_date( $raw_to );
		if ( ( '' !== $raw_from && null === $from ) || ( '' !== $raw_to && null === $to ) ) {
			throw new \InvalidArgumentException( 'Una data non è valida: scrivila come giorno/mese/anno.' );
		}
		if ( null !== $from && null !== $to && $to < $from ) {
			throw new \InvalidArgumentException( 'L\'ultimo giorno di servizio non può essere prima del primo.' );
		}
		return array( $from, $to );
	}

	private static function save_period( int $user_id, ?string $from, ?string $to ): void {
		foreach ( array( self::META_FROM => $from, self::META_TO => $to ) as $meta => $v ) {
			if ( null === $v ) {
				delete_user_meta( $user_id, $meta );
			} else {
				update_user_meta( $user_id, $meta, $v );
			}
		}
	}

	private static function unique_login( string $email ): string {
		$base  = sanitize_user( $email, true );
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		return $login;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Cache delle parti pubbliche delle pagine degli eventi (elenco, calendario, dati dell'evento, prossime date): sono uguali per tutti gli anonimi,
 * quindi si disegnano una volta e si riusano per pochi minuti invece di rifare le stesse letture a ogni visita.
 *
 * Regole che non si toccano: la cache si legge e si scrive solo per un visitatore anonimo (nessun utente collegato, nessun cookie di accesso
 * dei soci, solo GET, nessun parametro personale nell'indirizzo); non entra mai un modulo, un nonce o un token (controllo sul testo prima di salvare);
 * la parte con la prenotazione non si conserva mai. Ogni cambio dei dati (prenotazioni, date, attività, tariffe, posti, impostazioni, contenuti evento)
 * la svuota cambiando una «generazione» che fa parte della chiave; in ogni caso ogni voce scade da sola dopo {@see CachePolicy::PAGE_TTL} secondi.
 */
final class PageCache {

	const GEN_OPTION = 'asem_pc_gen';

	/** Tra due svuotamenti delle cache di pagina esterne passano almeno tanti secondi. */
	const EXTERNAL_GAP = 300;

	const EXTERNAL_LAST = 'asem_pc_ext_last';

	const EXTERNAL_HOOK = 'asem_pc_flush_external';

	/** Tabelle che, se scritte, cambiano ciò che le pagine pubbliche mostrano (date, posti, tariffe, prenotazioni, richieste dei non soci). */
	const TABLES = 'activities|sessions|bookings|event_requests';

	/** @var int */
	private static $hits = 0;

	/** @var int */
	private static $misses = 0;

	/** @var string 'hit' | 'miss' | 'bypass' | '' (ultima chiamata di questa richiesta) */
	private static $last = '';

	/** @var bool la generazione è già stata cambiata e nessuno l'ha letta da allora */
	private static $bumped = false;

	/** @var bool lo svuotamento delle cache esterne è già programmato */
	private static $scheduled = false;

	/** @var bool una scrittura eseguita da {@see self::write()}: lo svuotamento lo decide il numero di righe toccate, non il testo */
	private static $muted = false;

	public static function register(): void {
		add_filter( 'query', array( __CLASS__, 'on_query' ) );
		add_action( self::EXTERNAL_HOOK, array( __CLASS__, 'flush_external' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'purge' ) );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'purge' ) );
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'add_attachment', 'edit_attachment', 'delete_attachment' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_post' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta' ), 10, 2 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_term' ), 10, 3 );
		}
		add_action( 'set_object_terms', array( __CLASS__, 'on_object_terms' ), 10, 4 );
		add_filter( 'rocket_cache_reject_cookies', array( __CLASS__, 'reject_session_cookies' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WP Rocket
	}

	/** Chi ha un cookie di accesso dei soci (anche solo quello della pagina di accesso) non riceve né dà pagine conservate da WP Rocket. */
	public static function reject_session_cookies( $cookies ): array {
		$cookies = is_array( $cookies ) ? $cookies : array();
		return array_values( array_unique( array_merge( $cookies, array( MemberSession::COOKIE, PreSession::COOKIE ) ) ) );
	}

	// ---------- Chi può leggere e scrivere ----------

	/** Questa richiesta può ricevere (e dare) una parte conservata? Vedi {@see CachePolicy::page_cacheable()}. */
	public static function eligible(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( '' === $method ) {
			return false; // riga di comando, lavori programmati: non sono visite
		}
		$other = is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( isset( $GLOBALS['wp_query'] ) && ( is_preview() || is_feed() ) ) || ! empty( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- si guarda solo se c'è un invio, non lo si legge
		$cookie = isset( $_COOKIE[ MemberSession::COOKIE ] ) || isset( $_COOKIE[ PreSession::COOKIE ] );
		foreach ( array_keys( $_COOKIE ) as $name ) { // anche i cookie di WordPress per chi è collegato o ha sbloccato un contenuto con password
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) || 0 === strpos( (string) $name, 'wp-postpass_' ) ) {
				$cookie = true;
			}
		}
		return CachePolicy::page_cacheable( $method, is_user_logged_in(), $cookie, array_keys( $_GET ), $other ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo i nomi dei parametri
	}

	/** Stato dell'ultima chiamata di questa richiesta: 'hit', 'miss' o 'bypass' (per i test e per l'intestazione X-Asem-Cache). */
	public static function last(): string {
		return self::$last;
	}

	// ---------- Lettura e scrittura ----------

	/**
	 * La parte di pagina «$name» per questi parametri: dalla cache se c'è (e chi guarda è un anonimo), altrimenti disegnata e, se è sicuro, conservata.
	 *
	 * @param string   $name   nome della parte
	 * @param array    $parts  tutto ciò da cui dipende il risultato (attributi, contenuto, indirizzo)
	 * @param callable $render disegna la parte
	 */
	public static function remember( string $name, array $parts, callable $render ): string {
		if ( ! self::eligible() ) {
			self::note( 'bypass' );
			return (string) $render();
		}
		$gen  = self::generation();
		$key  = 'asem_pc_' . md5( wp_json_encode( array( $gen, $name, $parts, Db::today(), determine_locale(), home_url( '/' ) ) ) );
		$hit  = get_transient( $key );
		if ( is_string( $hit ) ) {
			Frontend\Assets::enqueue(); // chi ha disegnato la parte aveva chiesto gli stili: con la copia li chiediamo noi
			self::note( 'hit' );
			return $hit;
		}
		$html = (string) $render();
		self::note( 'miss' );
		if ( CachePolicy::storable_html( $html ) && self::stored_generation() === $gen ) { // se nel frattempo i dati sono cambiati non si salva una copia vecchia
			set_transient( $key, $html, CachePolicy::PAGE_TTL );
		}
		return $html;
	}

	/** Una parte che non passa mai dalla cache (moduli, prenotazione, stato di chi guarda): lo si segna, così si vede che è stata disegnata ogni volta. */
	public static function bypass(): void {
		self::note( 'bypass' );
	}

	private static function note( string $status ): void {
		self::$last = $status;
		if ( 'hit' === $status ) {
			++self::$hits;
		} elseif ( 'miss' === $status ) {
			++self::$misses;
		}
		if ( ! headers_sent() ) {
			header( 'X-Asem-Cache: ' . ( 0 === self::$hits + self::$misses ? 'BYPASS' : ( self::$misses > 0 ? 'MISS' : 'HIT' ) ) ); // intestazione di controllo: non porta dati
		}
	}

	/**
	 * Intestazioni della pagina che contiene una parte pubblica: a un anonimo le cache condivise la possono tenere pochi minuti,
	 * a chiunque altro (collegato, con cookie dei soci, invio di moduli, parametri personali) no. Se un'altra parte della pagina ha già detto «non in cache», vale quella.
	 */
	public static function announce_public(): void {
		if ( ! self::eligible() ) {
			Frontend\Views::no_cache();
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) && ! headers_sent() ) {
			foreach ( CachePolicy::public_headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
	}

	// ---------- Svuotamento ----------

	/** La generazione corrente (parte della chiave di ogni voce). */
	private static function generation(): string {
		self::$bumped = false;
		return (string) get_option( self::GEN_OPTION, '0' );
	}

	/** La generazione scritta ora nel database (una lettura diretta: chi ha disegnato mentre un altro scriveva non salva una copia vecchia). */
	private static function stored_generation(): string {
		$db = Db::db();
		$v  = $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', self::GEN_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella di WordPress, valore preparato
		return null === $v ? '0' : (string) $v;
	}

	/** Le copie conservate non valgono più; le cache di pagina esterne si svuotano a fine richiesta, a scrittura conclusa. */
	public static function purge(): void {
		self::bump();
		if ( ! self::$scheduled ) {
			self::$scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'finish' ) );
		}
	}

	/** Cambia la generazione, salvo che sia già cambiata e nessuno l'abbia letta da allora (più scritture di fila costano una sola scrittura). */
	private static function bump(): void {
		if ( ! self::$bumped ) {
			update_option( self::GEN_OPTION, sprintf( '%.6f-%d', microtime( true ), wp_rand() ), true );
			self::$bumped = true;
		}
	}

	/** A fine richiesta: di nuovo la generazione (dopo che la scrittura è conclusa) e le cache di pagina dei plugin più diffusi. */
	public static function finish(): void {
		self::$scheduled = false;
		self::$bumped    = false;
		self::bump();
		do_action( 'asem_public_pages_changed' ); // per chi ha una cache propria
		self::flush_external_throttled();
	}

	/**
	 * Le cache di pagina dei plugin esterni si svuotano per intero: al più una volta ogni {@see self::EXTERNAL_GAP} secondi.
	 * Se ne arriva un'altra richiesta nell'attesa, lo svuotamento viene programmato per quando l'attesa finisce (i posti liberi mostrati
	 * da quelle cache restano vecchi al massimo di pochi minuti). La generazione interna, invece, cambia sempre subito.
	 */
	private static function flush_external_throttled(): void {
		$last = get_transient( self::EXTERNAL_LAST );
		if ( false === $last ) {
			self::flush_external();
			return;
		}
		if ( false === wp_next_scheduled( self::EXTERNAL_HOOK ) ) {
			wp_schedule_single_event( max( time() + 1, (int) $last + self::EXTERNAL_GAP ), self::EXTERNAL_HOOK );
		}
	}

	/** Svuota ora le cache di pagina dei plugin più diffusi e segna quando. */
	public static function flush_external(): void {
		set_transient( self::EXTERNAL_LAST, time(), self::EXTERNAL_GAP );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di LiteSpeed Cache
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache
		}
		do_action( 'sg_cachepress_purge_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di SiteGround Optimizer
		do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di Cache Enabler
	}

	/** Ogni scrittura sulle tabelle di date, posti, tariffe, prenotazioni e richieste svuota la cache, da qualunque parte arrivi (anche dal Pro e dai lavori programmati). */
	public static function on_query( $query ) {
		if ( ! self::$muted && is_string( $query ) && 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
			if ( 1 === preg_match( '/\b' . preg_quote( Db::db()->prefix . 'asem_', '/' ) . '(' . self::TABLES . ')\b/', $query ) ) {
				self::purge();
			}
		}
		return $query;
	}

	/**
	 * Una scrittura (già preparata) su queste tabelle che svuota la cache solo se ha toccato almeno una riga: per i lavori periodici
	 * che il più delle volte non trovano niente da fare. Restituisce le righe toccate.
	 */
	public static function write( string $sql ): int {
		$db          = Db::db();
		self::$muted = true;
		try {
			$db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- già preparata da chi chiama
		} finally {
			self::$muted = false;
		}
		$n = (int) $db->rows_affected;
		if ( $n > 0 ) {
			self::purge();
		}
		return $n;
	}

	private static function is_event_content( int $post_id ): bool {
		return PublicEvents::POST_TYPE === get_post_type( $post_id );
	}

	/** Un contenuto «Evento» o un allegato di un evento (la locandina) è stato salvato, spostato nel cestino o eliminato: le altre immagini della libreria non contano. */
	public static function on_post( $post_id ): void {
		$id   = (int) $post_id;
		$type = get_post_type( $id );
		if ( PublicEvents::POST_TYPE === $type || ( 'attachment' === $type && self::is_event_content( (int) wp_get_post_parent_id( $id ) ) ) ) {
			self::purge();
		}
	}

	/** Un dato di un contenuto «Evento» (locandina, contatti, opzioni) è cambiato. */
	public static function on_meta( $meta_id, $post_id ): void {
		if ( self::is_event_content( (int) $post_id ) ) {
			self::purge();
		}
	}

	/** Una categoria degli eventi è stata creata, rinominata o tolta. */
	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		if ( PublicEvents::TAXONOMY === $taxonomy ) {
			self::purge();
		}
	}

	/** Le categorie di un evento sono cambiate. */
	public static function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( PublicEvents::TAXONOMY === $taxonomy ) {
			self::purge();
		}
	}
}

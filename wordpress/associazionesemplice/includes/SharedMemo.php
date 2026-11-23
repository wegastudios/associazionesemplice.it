<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Letture ricordate tra una richiesta e l'altra, solo se il sito ha una cache degli oggetti persistente (Redis, Memcached e simili):
 * senza, {@see SharedMemo::remember()} legge sempre dal database, esattamente come prima.
 *
 * Ogni area (calendario, elenco leggero delle persone, saldi, anni fiscali) ha una «generazione» salvata nella cache: i valori si
 * salvano sotto la generazione corrente e una scrittura sulle tabelle dell'area la cambia, da qualunque parte arrivi (il plugin, il Pro,
 * i lavori programmati, un'estensione che scrive con $wpdb). I valori della generazione vecchia non si leggono più e scadono da soli.
 *
 * Regole per non mostrare mai un dato vecchio:
 * - la generazione cambia prima della scrittura e di nuovo appena la scrittura è finita (alla query successiva o a fine richiesta),
 *   così anche chi ha letto nel mezzo non lascia un valore vecchio sotto la generazione nuova;
 * - dentro una transazione non si legge né si salva nulla dalla cache (si va al database) e la generazione cambia dopo il COMMIT;
 * - un valore si salva solo se la generazione non è cambiata mentre lo si calcolava;
 * - i valori durano al massimo {@see SharedMemo::TTL} secondi: rete di sicurezza per le scritture fatte fuori da WordPress.
 * Si ricordano solo letture che non dipendono da chi guarda (i filtri per utente si applicano dopo) e mai dati cifrati o riservati.
 * In una rete di siti (multisite) resta spenta.
 */
final class SharedMemo {

	const GROUP = 'asem_shared';

	/** Durata massima di un valore ricordato, in secondi. */
	const TTL = 600;

	/** Aree e tabelle (nome senza prefisso) da cui dipendono: una scrittura su una di queste cambia la generazione dell'area. */
	const AREAS = array(
		'calendar'      => array( 'activities', 'sessions', 'lesson_exceptions' ),
		'people_light'  => array( 'people', 'memberships' ),
		'ledger_deltas' => array( 'transactions' ),
		'fiscal_years'  => array( 'fiscal_years' ),
	);

	/** @var bool */
	private static $on = false;

	/** @var bool una transazione è aperta (START TRANSACTION visto, COMMIT o ROLLBACK non ancora) */
	private static $in_tx = false;

	/** @var array<string,true> aree scritte la cui generazione va cambiata di nuovo, a scrittura finita */
	private static $pending = array();

	/** Accende la cache tra richieste se il sito ne ha una persistente (si chiama all'avvio del plugin). */
	public static function boot(): void {
		if ( self::$on || ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() || ( function_exists( 'is_multisite' ) && is_multisite() ) ) {
			return;
		}
		self::$on      = true;
		self::$in_tx   = false;
		self::$pending = array();
		add_filter( 'query', array( __CLASS__, 'on_query' ) );
		add_action( 'shutdown', array( __CLASS__, 'end_request' ) );
	}

	/** Spegne la cache tra richieste (per le prove): cambia le generazioni ancora da cambiare e smette di guardare le query. */
	public static function stop(): void {
		if ( ! self::$on ) {
			return;
		}
		self::end_request();
		remove_filter( 'query', array( __CLASS__, 'on_query' ) );
		remove_action( 'shutdown', array( __CLASS__, 'end_request' ) );
		self::$on    = false;
		self::$in_tx = false;
	}

	public static function enabled(): bool {
		return self::$on;
	}

	/**
	 * Il valore di $fn per questa area e chiave: dalla cache se c'è (stessa generazione), altrimenti calcolato e salvato.
	 * Senza cache persistente, o dentro una transazione, è semplicemente $fn().
	 *
	 * @param string $key tutto ciò da cui il risultato dipende oltre alle tabelle dell'area (date, impostazioni, la query stessa)
	 * @return mixed
	 */
	public static function remember( string $area, string $key, callable $fn ) {
		if ( ! self::$on || self::$in_tx || ! isset( self::AREAS[ $area ] ) ) {
			return $fn();
		}
		if ( self::$pending ) {
			self::flush_pending(); // una scrittura di questa richiesta appena finita: prima si cambia la generazione
		}
		$gen   = self::gen( $area );
		$ck    = $area . ':' . $gen . ':' . md5( $key );
		$found = false;
		$hit   = wp_cache_get( $ck, self::GROUP, false, $found );
		if ( $found && is_array( $hit ) && array_key_exists( 'v', $hit ) ) {
			return $hit['v'];
		}
		$val = $fn();
		if ( ! self::$in_tx && ! self::$pending && self::gen( $area ) === $gen ) { // nessuna scrittura mentre si calcolava
			wp_cache_set( $ck, array( 'v' => $val ), self::GROUP, self::TTL );
		}
		return $val;
	}

	/** Generazione corrente di un'area, letta sempre dalla cache vera (non dalla copia della richiesta): se manca se ne crea una nuova. */
	private static function gen( string $area ): string {
		$k     = 'g:' . $area;
		$found = false;
		$g     = wp_cache_get( $k, self::GROUP, true, $found );
		if ( $found && is_string( $g ) && '' !== $g ) {
			return $g;
		}
		$g = self::new_gen();
		if ( ! wp_cache_add( $k, $g, self::GROUP ) ) { // un'altra richiesta l'ha creata nello stesso momento
			$g2 = wp_cache_get( $k, self::GROUP, true );
			return is_string( $g2 ) && '' !== $g2 ? $g2 : $g;
		}
		return $g;
	}

	/** Sempre diversa: se la generazione sparisce dalla cache (memoria piena) non torna mai un valore salvato sotto una vecchia. */
	private static function new_gen(): string {
		return uniqid( (string) wp_rand(), true );
	}

	private static function bump( string $area ): void {
		wp_cache_set( 'g:' . $area, self::new_gen(), self::GROUP, 0 );
	}

	private static function flush_pending(): void {
		$areas         = array_keys( self::$pending );
		self::$pending = array();
		foreach ( $areas as $a ) {
			self::bump( $a );
		}
	}

	/** Fine della richiesta: le generazioni delle aree scritte cambiano un'ultima volta, a scritture finite. */
	public static function end_request(): void {
		if ( self::$pending ) {
			self::flush_pending();
		}
	}

	/** Guarda ogni query: tiene il conto delle transazioni e cambia la generazione delle aree scritte (prima e dopo la scrittura). */
	public static function on_query( $query ) {
		if ( ! self::$on || ! is_string( $query ) ) {
			return $query;
		}
		if ( 1 !== preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE|RENAME|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $query, $m ) ) {
			if ( self::$pending && ! self::$in_tx ) {
				self::flush_pending(); // la scrittura precedente è finita
			}
			return $query;
		}
		$verb = strtoupper( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
		if ( 'START TRANSACTION' === $verb || 'BEGIN' === $verb ) {
			if ( self::$pending && ! self::$in_tx ) {
				self::flush_pending();
			}
			self::$in_tx = true;
			return $query;
		}
		if ( 'COMMIT' === $verb || 'ROLLBACK' === $verb ) {
			self::$in_tx = false; // le aree scritte restano da cambiare: si cambiano alla query dopo, quando il COMMIT è avvenuto
			if ( 'ROLLBACK' === $verb ) {
				self::$pending = array_fill_keys( array_keys( self::AREAS ), true ); // come RequestMemo: dopo un annullamento si riparte da zero
			}
			return $query;
		}
		$hit = self::areas_in( $query, Db::db()->prefix );
		if ( self::$in_tx ) {
			foreach ( $hit as $a ) {
				self::$pending[ $a ] = true; // dentro la transazione gli altri vedono ancora i dati di prima: si cambia dopo il COMMIT
			}
		} else {
			foreach ( array_unique( array_merge( array_keys( self::$pending ), $hit ) ) as $a ) {
				self::bump( $a ); // le scritture precedenti sono finite; questa sta per avvenire
			}
			self::$pending = array_fill_keys( $hit, true ); // e a scrittura finita si cambia ancora
		}
		return $query;
	}

	/**
	 * Aree le cui tabelle compaiono nella query (nomi interi, con il prefisso del sito).
	 *
	 * @return string[]
	 */
	public static function areas_in( string $query, string $prefix ): array {
		static $by_table = null;
		if ( null === $by_table ) {
			$by_table = array();
			foreach ( self::AREAS as $area => $tables ) {
				foreach ( $tables as $t ) {
					$by_table[ $t ][] = $area;
				}
			}
		}
		if ( ! preg_match_all( '/(?<![A-Za-z0-9_])' . preg_quote( $prefix . 'asem_', '/' ) . '(' . implode( '|', array_keys( $by_table ) ) . ')(?![A-Za-z0-9_])/i', $query, $mm ) ) {
			return array();
		}
		$out = array();
		foreach ( array_unique( array_map( 'strtolower', $mm[1] ) ) as $t ) {
			foreach ( $by_table[ $t ] as $a ) {
				$out[ $a ] = true;
			}
		}
		return array_keys( $out );
	}
}

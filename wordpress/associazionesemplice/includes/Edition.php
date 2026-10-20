<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Quali estensioni sono presenti in questa installazione.
 *
 * Il plugin contiene il nucleo; ciò che si aggiunge lo porta un plugin a parte, che indica la propria cartella con {@see Edition::add_dir()} e
 * dichiara con {@see Edition::register()} quali funzioni mette a disposizione. Il resto del plugin chiede `Edition::has( 'nome' )` prima di
 * usare una funzione aggiunta; senza estensioni il registro è vuoto e `has()` è sempre falso. Chi estende può sospendere o limitare le sue funzioni
 * con i filtri `asem_feature_available` e `asem_features_suspended`.
 */
final class Edition {

	/** @var array<string,string> funzione => file (dentro includes/) che la contiene */
	private static $features = array();

	/** @var array<string,string[]> funzione => pagine di amministrazione */
	private static $pages = array();

	/** @var array<string,string[]> funzione => azioni di amministrazione (`admin-post`) */
	private static $actions = array();

	/** @var array<string,bool> funzioni già cercate sul disco (si svuota quando cambiano le cartelle o il registro) */
	private static $found = array();

	/** @var string[] cartelle (con la barra finale) in cui cercare i file: la prima è quella di questo plugin */
	private static $dirs = array();

	/** @var string livello di compatibilità dell'estensione rifiutata perché diverso da quello di questo plugin ('' = nessun problema) */
	private static $mismatch = '';

	/**
	 * L'estensione dichiara le funzioni che porta.
	 *
	 * @param array<string,string>   $features funzione => file (dentro includes/)
	 * @param array<string,string[]> $pages    funzione => pagine di amministrazione (spariscono dal menu se la funzione non c'è)
	 * @param array<string,string[]> $actions  funzione => azioni di amministrazione (senza la funzione si bloccano con una spiegazione)
	 */
	public static function register( array $features, array $pages = array(), array $actions = array() ): void {
		self::$features = array_merge( self::$features, $features );
		self::$pages    = array_merge( self::$pages, $pages );
		self::$actions  = array_merge( self::$actions, $actions );
		self::$found    = array();
	}

	/** @return array<string,string[]> azioni di amministrazione delle funzioni dichiarate */
	public static function actions(): array {
		return self::$actions;
	}

	/** @return string[] pagine di amministrazione delle funzioni dichiarate che questa installazione non può usare */
	public static function missing_pages(): array {
		$out = array();
		foreach ( self::$pages as $feature => $pages ) {
			if ( ! self::has( $feature ) ) {
				$out = array_merge( $out, $pages );
			}
		}
		return $out;
	}

	/** @return string[] */
	public static function dirs(): array {
		if ( ! self::$dirs ) {
			self::$dirs = array( ASEM_DIR . 'includes/' );
		}
		return self::$dirs;
	}

	/**
	 * Questo plugin e le sue estensioni hanno versioni indipendenti (si aggiorna uno o l'altro, in qualunque ordine); si parlano finché hanno lo
	 * stesso «livello di compatibilità», un numero che cambia solo quando cambia ciò che si scambiano.
	 */
	public static function compatible( int $ext_api, int $own_api ): bool {
		return $ext_api === $own_api;
	}

	/** Il livello di compatibilità dell'estensione rifiutata perché non corrisponde (vuoto se tutto a posto). */
	public static function mismatch(): string {
		return self::$mismatch;
	}

	/** L'estensione indica qui la propria cartella `includes/`. Se il suo livello di compatibilità non è quello di questo plugin non si carica niente. */
	public static function add_dir( string $dir ): void {
		if ( defined( 'ASEM_PRO_API' ) && defined( 'ASEM_API' ) && ! self::compatible( (int) ASEM_PRO_API, (int) ASEM_API ) ) {
			self::$mismatch = (string) ASEM_PRO_API;
			return;
		}
		$dir = rtrim( $dir, '/\\' ) . '/';
		if ( ! in_array( $dir, self::dirs(), true ) ) {
			self::$dirs[] = $dir;
			self::$found  = array();
		}
	}

	/** Percorso del file di una classe (null se non esiste in nessuna cartella). */
	public static function locate( string $relative ): ?string {
		foreach ( self::dirs() as $d ) {
			if ( is_readable( $d . $relative ) ) {
				return $d . $relative;
			}
		}
		return null;
	}

	/** La funzione è dichiarata e il suo file c'è? */
	public static function installed( string $feature ): bool {
		if ( ! isset( self::$features[ $feature ] ) ) {
			return false;
		}
		if ( ! isset( self::$found[ $feature ] ) ) { // il disco si interroga una volta sola per funzione e per richiesta
			self::$found[ $feature ] = null !== self::locate( self::$features[ $feature ] );
		}
		return self::$found[ $feature ];
	}

	/** C'è almeno un'estensione (ha dichiarato qualche funzione)? */
	public static function pro_present(): bool {
		return (bool) self::$features;
	}

	/** Le funzioni aggiunte sono sospese (lo decide l'estensione con il filtro `asem_features_suspended`)? */
	public static function degraded(): bool {
		return function_exists( 'apply_filters' ) && (bool) apply_filters( 'asem_features_suspended', false );
	}

	/** La funzione si può usare? C'è e l'estensione non l'ha limitata (filtro `asem_feature_available`). */
	public static function has( string $feature ): bool {
		return self::installed( $feature ) && ( ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'asem_feature_available', true, $feature ) );
	}

	/** Le funzioni di base non dipendono da altro: tutto ciò che c'è si può usare. Resta per i controlli già scritti. */
	public static function allows( string $feature ): bool {
		return true;
	}

	/** Perché una funzione aggiunta non è disponibile (da mostrare a chi prova a usarla). */
	public static function missing_message( string $feature = '' ): string {
		$default = 'Questa funzione fa parte di Associazione Semplice Pro e non è inclusa in questa edizione.';
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'asem_feature_unavailable_message', $default, $feature ) : $default;
	}

	/** Indirizzo del sito dove si presenta e si acquista Associazione Semplice Pro (modificabile con il filtro `asem_pro_url`). */
	public static function pro_url(): string {
		return (string) apply_filters( 'asem_pro_url', 'https://associazionesemplice.it' );
	}

	/** Come {@see Edition::missing_message()}, con il collegamento a cosa comprende il Pro (HTML già pronto; l'estensione può sostituirlo con `asem_feature_unavailable_html`). */
	public static function missing_html(): string {
		$html = '<p>' . esc_html( self::missing_message() ) . '</p>';
		if ( ! self::degraded() && function_exists( 'admin_url' ) ) {
			$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=asem-pro' ) ) . '">Scopri cosa comprende Associazione Semplice Pro →</a></p>';
		}
		return (string) apply_filters( 'asem_feature_unavailable_html', $html );
	}

	/** Azione di una funzione aggiunta che non c'è (o è sospesa): chi ci arriva legge perché, invece di una pagina vuota. */
	public static function block_action( string $action ): void {
		add_action(
			'admin_post_' . $action,
			function () {
				wp_die( self::missing_html(), 'Funzione non disponibile', array( 'response' => 403, 'back_link' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);
	}
}

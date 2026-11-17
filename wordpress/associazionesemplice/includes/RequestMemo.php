<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Memoria di una sola richiesta, sempre coerente con il database: ogni valore ricordato dichiara da quali tabelle dipende e si svuota da solo
 * appena il sito scrive su una di quelle tabelle (o annulla una transazione), da qualunque parte arrivi la scrittura.
 * Serve a non ripetere la stessa lettura più volte nella stessa pagina senza mai mostrare un dato vecchio dopo un salvataggio.
 */
final class RequestMemo {

	/** @var array<string,array<string,mixed>> area => chiave => valore */
	private static $vals = array();

	/** @var array<string,string[]> area => parti di nome di tabella che, se scritte, svuotano l'area */
	private static $watch = array();

	/** @var bool */
	private static $hooked = false;

	public static function has( string $area, $key ): bool {
		return isset( self::$vals[ $area ] ) && array_key_exists( (string) $key, self::$vals[ $area ] );
	}

	/** @return mixed null se non c'è (usa {@see RequestMemo::has()} per distinguere un null ricordato) */
	public static function get( string $area, $key ) {
		return self::$vals[ $area ][ (string) $key ] ?? null;
	}

	/**
	 * Ricorda un valore e lo restituisce.
	 *
	 * @param string[] $tables nomi (interi) delle tabelle da cui il valore dipende
	 * @param mixed    $value
	 * @return mixed
	 */
	public static function put( string $area, $key, $value, array $tables ) {
		self::$vals[ $area ][ (string) $key ] = $value;
		self::$watch[ $area ]                 = array_values( array_filter( array_map( 'strval', $tables ) ) );
		self::hook();
		return $value;
	}

	/** Svuota un'area (o tutta la memoria). */
	public static function forget( ?string $area = null ): void {
		if ( null === $area ) {
			self::$vals = array();
			return;
		}
		unset( self::$vals[ $area ] );
	}

	private static function hook(): void {
		if ( ! self::$hooked && function_exists( 'add_filter' ) ) {
			self::$hooked = true;
			add_filter( 'query', array( __CLASS__, 'on_query' ) );
		}
	}

	/** Controlla ogni query: una scrittura sulle tabelle di un'area la svuota; una transazione annullata svuota tutto. */
	public static function on_query( $query ) {
		if ( self::$vals && is_string( $query ) && 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|ROLLBACK)\b/i', $query, $m ) ) {
			if ( 'ROLLBACK' === strtoupper( $m[1] ) ) {
				self::$vals = array();
				return $query;
			}
			foreach ( self::$watch as $area => $tables ) {
				foreach ( $tables as $t ) {
					if ( false !== stripos( $query, $t ) ) {
						unset( self::$vals[ $area ] );
						break;
					}
				}
			}
		}
		return $query;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class Db {

	public static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Nome completo di una tabella del plugin: Db::t('people') => wp_asem_people */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'asem_' . $name;
	}

	/** Data di oggi (Y-m-d) nel fuso orario del sito. */
	public static function today(): string {
		return current_time( 'Y-m-d' );
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}

	/** Solo per le prove: 'x' = blocco vero; '0' = non preso; 'null' = errore del server; '1' = preso. @var string */
	public static $lock_fake = 'x';

	/**
	 * Nome del blocco sul server MySQL. GET_LOCK vale per tutto il server: su un hosting condiviso siti diversi userebbero gli stessi nomi e si
	 * bloccherebbero a vicenda. Il nome porta quindi un'impronta breve del sito (database e prefisso delle tabelle) e sta sempre entro i 64 caratteri di MySQL.
	 *
	 * @param string      $logical nome dell'operazione
	 * @param string|null $scope   da cosa si ricava l'impronta (solo nelle prove); di norma database e prefisso di questo sito
	 */
	public static function lock_name( string $logical, ?string $scope = null ): string {
		if ( null === $scope ) {
			$scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . self::db()->prefix;
		}
		$base = 'asem_' . substr( md5( $scope ), 0, 10 ) . '_';
		$name = $base . $logical;
		if ( strlen( $name ) > 64 ) {
			$name = $base . substr( $logical, 0, 10 ) . '_' . substr( md5( $logical ), 0, 16 );
		}
		return $name;
	}

	/**
	 * Esegue $fn tenendo un blocco con nome (GET_LOCK): due richieste insieme non possono fare la stessa operazione.
	 * Se il blocco non si ottiene entro $wait secondi l'operazione non parte (si riprova); se il database non supporta i blocchi, si procede senza.
	 *
	 * @return mixed il risultato di $fn
	 * @throws \InvalidArgumentException
	 */
	public static function with_lock( string $name, callable $fn, int $wait = 5, bool $strict = false ) {
		$db   = self::db();
		$name = self::lock_name( $name );
		$got = 'x' !== self::$lock_fake ? ( 'null' === self::$lock_fake ? null : self::$lock_fake ) : $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		// Con $strict anche un errore del server (GET_LOCK dà NULL) vale «blocco non preso»; senza, come sempre, si procede senza blocco.
		if ( '0' === (string) $got || ( $strict && '1' !== (string) $got ) ) {
			throw new \InvalidArgumentException( 'Un\'altra operazione è in corso: riprova tra qualche secondo.' );
		}
		try {
			return $fn();
		} finally {
			if ( null !== $got ) {
				$db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}
}

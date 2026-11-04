<?php
namespace Portale;

/** Limite orario di richieste per chiave (indirizzo IP, e-mail…), nella tabella `rate_limits`. */
final class RateLimit {

	/**
	 * Conta una richiesta; false se la chiave ha già raggiunto il massimo nell'ora corrente.
	 * Il controllo e l'aumento sono una sola istruzione: richieste contemporanee non possono superare il limite.
	 */
	public static function hit( Db $db, string $kind, string $subject, int $max, ?int $now = null ): bool {
		$now    = $now ?? time();
		$hour   = gmdate( 'YmdH', $now );
		$bucket = $kind . ':' . hash( 'sha256', $subject );
		return self::take( $db, $bucket, $hour, $max, $now );
	}

	/** Riserva un posto nel contenitore (bucket) dell'ora: true se c'era ancora posto. */
	public static function take( Db $db, string $bucket, string $hour, int $max, ?int $now = null ): bool {
		$now = $now ?? time();
		if ( 0 === (int) ( $now % 16 ) ) { // la pulizia delle ore vecchie non serve a ogni richiesta
			$db->run( 'DELETE FROM rate_limits WHERE hour < ?', array( gmdate( 'YmdH', $now - 7200 ) ) );
		}
		$up = 'UPDATE rate_limits SET n = n + 1 WHERE bucket = ? AND hour = ? AND n < ?';
		if ( 1 === $db->run( $up, array( $bucket, $hour, $max ) )->rowCount() ) {
			return true;
		}
		if ( $db->one( 'SELECT 1 AS x FROM rate_limits WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) ) ) {
			return false; // c'è ed è al massimo
		}
		try {
			$db->run( 'INSERT INTO rate_limits (bucket, hour, n) VALUES (?,?,1)', array( $bucket, $hour ) );
			return $max >= 1;
		} catch ( \PDOException $e ) { // un'altra richiesta l'ha creato un attimo prima
			return 1 === $db->run( $up, array( $bucket, $hour, $max ) )->rowCount();
		}
	}

	/** Restituisce un posto (un tentativo andato a buon fine non conta). */
	public static function give_back( Db $db, string $bucket, string $hour ): void {
		$db->run( 'UPDATE rate_limits SET n = n - 1 WHERE bucket = ? AND hour = ? AND n > 0', array( $bucket, $hour ) );
	}
}

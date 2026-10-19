<?php
namespace Portale;

/** Limite orario di richieste per chiave (indirizzo IP, e-mail…), nella tabella `rate_limits`. */
final class RateLimit {

	/** Conta una richiesta; false se la chiave ha già raggiunto il massimo nell'ora corrente. */
	public static function hit( Db $db, string $kind, string $subject, int $max, ?int $now = null ): bool {
		$now    = $now ?? time();
		$hour   = gmdate( 'YmdH', $now );
		$bucket = $kind . ':' . hash( 'sha256', $subject );
		$db->run( 'DELETE FROM rate_limits WHERE hour < ?', array( gmdate( 'YmdH', $now - 7200 ) ) );
		$row = $db->one( 'SELECT n FROM rate_limits WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		if ( ! $row ) {
			$db->run( 'INSERT INTO rate_limits (bucket, hour, n) VALUES (?,?,1)', array( $bucket, $hour ) );
			return true;
		}
		if ( (int) $row['n'] >= $max ) {
			return false;
		}
		$db->run( 'UPDATE rate_limits SET n = n + 1 WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		return true;
	}
}

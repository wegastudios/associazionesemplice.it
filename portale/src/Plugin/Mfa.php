<?php
namespace Portale\Plugin;

use Portale\Db;
use Portale\RateLimit;
use Portale\Secrets;
use Portale\UserError;

/**
 * Verifica in due passaggi OPZIONALE dello staff del plugin: ognuno la attiva o la spegne per sé (di base è spenta per tutti).
 * Chi l'ha attiva deve inserire il codice a ogni nuovo accesso e poi ogni 8 ore. Il segreto è cifrato con `app_key`.
 */
final class Mfa {

	const VALID_SECONDS = 28800; // 8 ore

	public static function enabled( Db $db, string $email ): bool {
		$row = $db->one( 'SELECT totp_secret_enc FROM plugin_staff WHERE email = ?', array( strtolower( trim( $email ) ) ) );
		return $row && ! empty( $row['totp_secret_enc'] );
	}

	/** La sessione ha superato la verifica per questo indirizzo, di recente? */
	public static function sessionOk( string $email, ?int $now = null ): bool {
		$m = $_SESSION['plugin_mfa'] ?? null;
		return is_array( $m ) && strtolower( trim( $email ) ) === ( $m['email'] ?? '' ) && ( $now ?? time() ) - (int) ( $m['at'] ?? 0 ) < self::VALID_SECONDS;
	}

	public static function markSession( string $email, ?int $now = null ): void {
		$_SESSION['plugin_mfa'] = array( 'email' => strtolower( trim( $email ) ), 'at' => $now ?? time() );
	}

	/** Controlla il codice per l'accesso (max 5 tentativi all'ora per indirizzo). @throws UserError */
	public static function check( Db $db, string $email, string $code, ?int $now = null ): bool {
		$email = strtolower( trim( $email ) );
		if ( ! RateLimit::hit( $db, 'plugin_mfa', $email, 5, $now ) ) {
			throw new UserError( 'Troppi tentativi: riprova tra un\'ora.' );
		}
		$row = $db->one( 'SELECT totp_secret_enc FROM plugin_staff WHERE email = ?', array( $email ) );
		if ( ! $row || empty( $row['totp_secret_enc'] ) ) {
			return false;
		}
		return Totp::verify( Secrets::open( (string) $row['totp_secret_enc'] ), $code, $now );
	}

	/** Conferma l'attivazione: il codice deve corrispondere al segreto proposto. Crea la riga se manca (amministratori di config.php). @throws UserError */
	public static function enable( Db $db, string $email, string $secret32, string $code, ?int $now = null ): void {
		$email = strtolower( trim( $email ) );
		if ( '' === $secret32 || ! Totp::verify( $secret32, $code, $now ) ) {
			throw new UserError( 'Il codice non corrisponde: controlla l\'ora del telefono e riprova.' );
		}
		$sealed = Secrets::seal( $secret32 );
		if ( $db->one( 'SELECT 1 AS x FROM plugin_staff WHERE email = ?', array( $email ) ) ) {
			$db->run( 'UPDATE plugin_staff SET totp_secret_enc = ?, mfa_at = ? WHERE email = ?', array( $sealed, Db::now(), $email ) );
		} else {
			$role = Staff::role( $db, $email ) ?? Staff::SUPPORT;
			$db->run( 'INSERT INTO plugin_staff (email, role, created_at, totp_secret_enc, mfa_at) VALUES (?,?,?,?,?)', array( $email, $role, Db::now(), $sealed, Db::now() ) );
		}
	}

	/** Spegne la verifica: serve un codice valido. @throws UserError */
	public static function disable( Db $db, string $email, string $code, ?int $now = null ): void {
		if ( ! self::check( $db, $email, $code, $now ) ) {
			throw new UserError( 'Codice non corretto: la verifica resta attiva.' );
		}
		$db->run( 'UPDATE plugin_staff SET totp_secret_enc = NULL, mfa_at = NULL WHERE email = ?', array( strtolower( trim( $email ) ) ) );
	}
}

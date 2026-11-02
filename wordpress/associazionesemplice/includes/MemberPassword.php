<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Password dei soci per l'accesso proprio. Argon2id se il server lo ha, altrimenti bcrypt; le impronte dei vecchi utenti WordPress
 * (phpass «$P$» e «$wp$») si riconoscono per poterle portare così come sono e rifare al primo accesso con l'algoritmo nuovo.
 * Classe "pura": non usa né WordPress né il database.
 */
final class MemberPassword {

	const MIN_LENGTH = 8;

	private static function algo() {
		return defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
	}

	public static function make( string $password ): string {
		return password_hash( $password, self::algo() );
	}

	/** Solo per le impronte nate qui: quelle di WordPress le controlla MemberSession con le funzioni di WordPress. */
	public static function verify( string $password, string $hash ): bool {
		return '' !== $hash && ! self::is_legacy( $hash ) && password_verify( $password, $hash );
	}

	public static function needs_rehash( string $hash ): bool {
		return self::is_legacy( $hash ) || password_needs_rehash( $hash, self::algo() );
	}

	/** Impronta nata in WordPress (phpass o il formato nuovo «$wp$»). */
	public static function is_legacy( string $hash ): bool {
		return 0 === strpos( $hash, '$P$' ) || 0 === strpos( $hash, '$wp$' ) || 0 === strpos( $hash, '$H$' );
	}

	/** @return string vuoto se va bene, altrimenti cosa non va (da mostrare al socio) */
	public static function problem( string $password ): string {
		if ( mb_strlen( $password, 'UTF-8' ) < self::MIN_LENGTH ) {
			return 'La password deve avere almeno ' . self::MIN_LENGTH . ' caratteri.';
		}
		if ( strlen( $password ) > 200 ) {
			return 'La password è troppo lunga.';
		}
		return '';
	}

	const MAX_FAILED = 5; // errori di fila prima del blocco

	/** Secondi di blocco dopo tanti errori di fila: nessuno fino a 4, poi un minuto che raddoppia a ogni errore, al massimo un'ora. */
	public static function lock_seconds( int $failed ): int {
		if ( $failed < self::MAX_FAILED ) {
			return 0;
		}
		return min( 3600, 60 * ( 1 << min( 10, $failed - self::MAX_FAILED ) ) );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Password dei soci per l'accesso proprio. Argon2id se il server lo ha, altrimenti bcrypt.
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

	public static function verify( string $password, string $hash ): bool {
		return '' !== $hash && password_verify( $password, $hash );
	}

	/** L'impronta è stata fatta con un algoritmo o un costo più vecchio di quello di oggi: si rifà al prossimo accesso riuscito. */
	public static function needs_rehash( string $hash ): bool {
		return password_needs_rehash( $hash, self::algo() );
	}

	/** @return string vuoto se va bene, altrimenti cosa non va (da mostrare al socio) */
	public static function problem( string $password ): string {
		if ( mb_strlen( $password, 'UTF-8' ) < self::MIN_LENGTH ) {
			return 'La password deve avere almeno ' . self::MIN_LENGTH . ' caratteri.';
		}
		if ( strlen( $password ) > 200 || ( ! defined( 'PASSWORD_ARGON2ID' ) && strlen( $password ) > 72 ) ) { // con bcrypt oltre 72 byte il resto verrebbe ignorato
			return ! defined( 'PASSWORD_ARGON2ID' ) ? 'La password è troppo lunga: al massimo 72 caratteri.' : 'La password è troppo lunga.';
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

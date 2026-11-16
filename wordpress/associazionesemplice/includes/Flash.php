<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Messaggi di esito ("Spesa registrata", "Posti esauriti"…) che viaggiano nell'indirizzo dopo un'azione. Sono FIRMATI: una pagina
 * mostra solo i messaggi scritti dal sito stesso, mai un testo qualunque messo da qualcuno in un link (altrimenti un link come
 * `?asemf_err=Il tuo conto è stato sospeso, chiama il…` mostrerebbe quel testo come se fosse un avviso ufficiale).
 *
 * La firma è legata anche a chi ha fatto l'azione (l'utente, o il socio con l'accesso proprio) e a una scadenza breve: un indirizzo con
 * un messaggio si legge solo da chi l'ha generato, e per pochi minuti. Un messaggio generato da chi non aveva fatto l'accesso resta
 * leggibile da chiunque, sempre entro la scadenza. Il valore del parametro «_sig» è «scadenza.firma».
 */
final class Flash {

	/** Per quanti secondi un messaggio si può ancora mostrare dopo l'azione. */
	const TTL = 600;

	/** Chi sta agendo: l'utente WordPress, o il socio con l'accesso proprio; 0 se nessuno. */
	public static function binding(): int {
		return MemberSession::actor_key();
	}

	/** Firma di un messaggio: lega il testo al sito, al tipo (ok o errore), alla scadenza e a chi l'ha generato. */
	public static function sign( string $kind, string $msg, int $expires = 0, int $actor = 0 ): string {
		return substr( hash_hmac( 'sha256', 'flash|' . $kind . '|' . $msg . '|' . $expires . '|' . $actor, wp_salt( 'auth' ) ), 0, 24 );
	}

	/** Indirizzo con il messaggio (errore se c'è, altrimenti esito positivo). $prefix: "asemf" (area soci) o "asem" (amministrazione). */
	public static function url( string $url, string $prefix, string $ok = '', string $err = '' ): string {
		$url  = remove_query_arg( array( $prefix . '_ok', $prefix . '_err', $prefix . '_sig' ), $url );
		$kind = '' !== $err ? 'err' : ( '' !== $ok ? 'ok' : '' );
		if ( '' === $kind ) {
			return $url;
		}
		$msg     = str_replace( "'", '’', Texts::plain( 'err' === $kind ? $err : $ok ) ); // testi personalizzati; l'apostrofo dritto verrebbe tolto dal reindirizzamento e la firma non tornerebbe: si usa quello tipografico
		$expires = time() + self::TTL;
		return add_query_arg( array( $prefix . '_' . $kind => $msg, $prefix . '_sig' => $expires . '.' . self::sign( $kind, $msg, $expires, self::binding() ) ), $url );
	}

	/** Messaggio da mostrare (firma valida, non scaduto, di chi lo legge) oppure stringhe vuote. @return array{ok:string,err:string} */
	public static function read( string $prefix ): array {
		$out  = array( 'ok' => '', 'err' => '' );
		$raw  = isset( $_GET[ $prefix . '_sig' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_sig' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$bits = explode( '.', $raw, 2 );
		if ( 2 !== count( $bits ) || ! ctype_digit( $bits[0] ) || (int) $bits[0] < time() ) {
			return $out; // senza scadenza valida (o scaduto) non si mostra nulla
		}
		$expires = (int) $bits[0];
		$sig     = $bits[1];
		$actors  = array_unique( array( self::binding(), 0 ) );
		foreach ( array( 'err', 'ok' ) as $kind ) {
			$msg = isset( $_GET[ $prefix . '_' . $kind ] ) ? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_' . $kind ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' === $msg || '' === $sig ) {
				continue;
			}
			foreach ( $actors as $actor ) {
				if ( hash_equals( self::sign( $kind, $msg, $expires, (int) $actor ), $sig ) ) {
					$out[ $kind ] = $msg;
					break 2;
				}
			}
		}
		return $out;
	}
}

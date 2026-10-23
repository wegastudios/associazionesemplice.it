<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Registro dell'esito delle email spedite dal plugin (ultime 30): data, destinatario (in parte nascosto), oggetto, riuscita o errore.
 * Serve a capire perché un messaggio non parte (indirizzo mittente rifiutato dal server, posta del sito non configurata…): nessun testo di messaggio
 * e nessun indirizzo completo vengono conservati.
 */
final class MailLog {

	const OPTION = 'asem_mail_log';
	const KEEP   = 30;

	/** @var string ultimo errore segnalato da WordPress durante la spedizione in corso */
	private static $last_error = '';

	public static function register(): void {
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ) );
	}

	public static function failed( $error ): void {
		self::$last_error = is_wp_error( $error ) ? mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 200 ) : 'errore sconosciuto';
	}

	/** Prepara la registrazione di una spedizione. */
	public static function begin(): void {
		self::$last_error = '';
	}

	/** Annota l'esito. @param string|array $to */
	public static function record( $to, string $subject, bool $ok ): void {
		$to   = is_array( $to ) ? (string) reset( $to ) : (string) $to;
		$log  = (array) get_option( self::OPTION, array() );
		$log[] = array(
			't'     => time(),
			'to'    => self::mask( $to ),
			'what'  => mb_substr( trim( $subject ), 0, 80 ),
			'ok'    => $ok ? 1 : 0,
			'error' => $ok ? '' : ( '' !== self::$last_error ? self::$last_error : 'WordPress non ha potuto consegnare il messaggio al server di posta' ),
		);
		update_option( self::OPTION, array_slice( $log, -self::KEEP ), false );
	}

	/** «m***@dominio.it». */
	public static function mask( string $email ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** @return array[] dal più recente */
	public static function recent( int $limit = 10 ): array {
		return array_slice( array_reverse( (array) get_option( self::OPTION, array() ) ), 0, $limit );
	}

	/** Spedizioni fallite negli ultimi giorni. */
	public static function failures( int $days = 7 ): int {
		$since = time() - $days * DAY_IN_SECONDS;
		$n     = 0;
		foreach ( (array) get_option( self::OPTION, array() ) as $r ) {
			if ( empty( $r['ok'] ) && (int) $r['t'] >= $since ) {
				$n++;
			}
		}
		return $n;
	}
}

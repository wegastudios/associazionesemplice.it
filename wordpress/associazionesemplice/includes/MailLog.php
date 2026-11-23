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

	/** @var array[] email annotate in questa richiesta e non ancora scritte nel registro */
	private static $pending = array();

	/** @var bool la scrittura di fine richiesta è già programmata */
	private static $hooked = false;

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
		$to = is_array( $to ) ? (string) reset( $to ) : (string) $to;
		self::$pending[] = array(
			't'     => time(),
			'to'    => self::mask( $to ),
			'what'  => mb_substr( trim( $subject ), 0, 80 ),
			'ok'    => $ok ? 1 : 0,
			'error' => $ok ? '' : ( '' !== self::$last_error ? self::$last_error : 'WordPress non ha potuto consegnare il messaggio al server di posta' ),
		);
		self::$pending = array_slice( self::$pending, -self::KEEP ); // un invio lungo non fa crescere la memoria: contano solo le ultime
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 ); // una sola scrittura a fine richiesta, non una per ogni email
		}
	}

	/** Scrive nel registro le email annotate in questa richiesta: una sola scrittura (a fine richiesta, o subito se serve leggerle dal database). */
	public static function flush(): void {
		self::$hooked = false;
		if ( ! self::$pending ) {
			return;
		}
		$log           = array_merge( (array) get_option( self::OPTION, array() ), self::$pending );
		self::$pending = array();
		update_option( self::OPTION, array_slice( $log, -self::KEEP ), false );
	}

	/** Il registro com'è adesso: quello salvato più le email annotate in questa richiesta e non ancora scritte. @return array[] */
	private static function all(): array {
		return array_slice( array_merge( (array) get_option( self::OPTION, array() ), self::$pending ), -self::KEEP );
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
		return array_slice( array_reverse( self::all() ), 0, $limit );
	}

	/** Spedizioni fallite negli ultimi giorni. */
	public static function failures( int $days = 7 ): int {
		$since = time() - $days * DAY_IN_SECONDS;
		$n     = 0;
		foreach ( self::all() as $r ) {
			if ( empty( $r['ok'] ) && (int) $r['t'] >= $since ) {
				$n++;
			}
		}
		return $n;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Email delle pagine pubbliche (accesso, primo accesso, domanda di adesione): con la coda accesa partono dopo che la risposta è stata consegnata
 * al visitatore, così il tempo di risposta non dipende dall'esito (indirizzo noto o no). A coda spenta (il caso normale, ad esempio dal pannello) si spedisce subito.
 */
final class ResponseMail {

	/** @var bool */
	private static $defer = false;
	/** @var array<int,array{0:mixed,1:string,2:string}> */
	private static $queue = array();
	/** @var bool */
	private static $hooked = false;

	/** Accende o spegne la coda; accesa, a fine richiesta le email in attesa partono da sole. */
	public static function defer( bool $on ): void {
		self::$defer = $on;
		if ( $on && ! self::$hooked && function_exists( 'add_action' ) ) {
			self::$hooked = true;
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
	}

	public static function deferring(): bool {
		return self::$defer;
	}

	/** Email in attesa di partire. */
	public static function pending(): int {
		return count( self::$queue );
	}

	/** Spedisce subito, oppure mette in coda (e dice true: l'esito vero non si conosce prima della risposta). */
	public static function send( $to, string $subject, string $message ): bool {
		if ( ! self::$defer ) {
			return Texts::mail( $to, $subject, $message );
		}
		self::$queue[] = array( $to, $subject, $message );
		return true;
	}

	/** Chiude la risposta verso il visitatore (dove il server lo permette) e spedisce le email in coda. */
	public static function flush(): void {
		if ( ! self::$queue ) {
			return;
		}
		$jobs        = self::$queue;
		self::$queue = array();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $jobs as $job ) {
			Texts::mail( $job[0], $job[1], $job[2] );
		}
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Errore imprevisto nelle azioni dell'amministrazione: a chi usa il pannello si mostra un messaggio fisso con un codice,
 * il dettaglio tecnico (che può contenere percorsi, nomi di tabelle, parti di query) va solo nel registro degli errori del server.
 */
final class UnexpectedError {

	const TEMPLATE = 'Errore imprevisto (codice {codice}). Riprova; se si ripete scrivi al supporto indicando il codice.';

	/** Codice breve da citare al supporto: lo stesso compare nella riga del registro del server. */
	public static function new_code(): string {
		return strtoupper( bin2hex( random_bytes( 3 ) ) );
	}

	/** Riga per il registro del server: codice e dettaglio tecnico. */
	public static function log_line( string $code, \Throwable $e ): string {
		return 'Associazione Semplice: errore imprevisto ' . $code . ': ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}

	/** Scrive il dettaglio nel registro del server e restituisce il messaggio fisso da mostrare. */
	public static function report( \Throwable $e ): string {
		$code = self::new_code();
		error_log( self::log_line( $code, $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- il dettaglio resta sul server
		return Texts::t( self::TEMPLATE, array( 'codice' => $code ) );
	}
}

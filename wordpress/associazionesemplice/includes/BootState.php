<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Piccoli segnali letti a ogni avvio del sito (anno fiscale già aperto, indirizzi già ricaricati, pagine già create, procedura guidata da aprire),
 * riuniti in una sola opzione caricata insieme alle altre: nessuna lettura in più dal database a ogni pagina.
 * Chi ha ancora il segnale nella vecchia opzione la legge una volta sola e da lì in poi vale quella riunita.
 */
final class BootState {

	const OPTION = 'asem_boot';

	/** @var array|null */
	private static $data = null;

	private static function load(): array {
		if ( null === self::$data ) {
			$v = get_option( self::OPTION, null );
			if ( ! is_array( $v ) ) {
				$v = array();
				add_option( self::OPTION, $v, '', true ); // esiste sempre e si carica con le altre opzioni
			}
			self::$data = $v;
		}
		return self::$data;
	}

	/**
	 * @param string $legacy nome della vecchia opzione che teneva questo segnale ('' se non c'era)
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, string $legacy = '', $default = '' ) {
		$d = self::load();
		if ( array_key_exists( $key, $d ) ) {
			return $d[ $key ];
		}
		if ( '' !== $legacy ) {
			$old = get_option( $legacy, null );
			if ( null !== $old ) {
				self::set( $key, $old );
				return $old;
			}
			self::set( $key, $default ); // nemmeno la vecchia opzione c'è: si ricorda il valore di partenza, così la vecchia non si cerca più a ogni richiesta
		}
		return $default;
	}

	/** @param mixed $value */
	public static function set( string $key, $value ): void {
		$d         = self::load();
		$d[ $key ] = $value;
		self::$data = $d;
		update_option( self::OPTION, $d, true );
	}

	/** Toglie un segnale (e rilegge dal database la volta dopo). */
	public static function forget( string $key ): void {
		$d = self::load();
		unset( $d[ $key ] );
		self::$data = $d;
		update_option( self::OPTION, $d, true );
	}

	/** Solo per le prove: dimentica quanto tenuto in memoria. */
	public static function reset_memory(): void {
		self::$data = null;
	}
}

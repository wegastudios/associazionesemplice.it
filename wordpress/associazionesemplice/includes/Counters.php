<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Contatori a vita breve (tentativi di accesso, richieste del socio in una finestra di tempo).
 * Senza cache degli oggetti i transient di WordPress sono righe di wp_options, e quella tabella si riscrive a ogni tentativo: qui ogni contatore
 * è una riga di una tabellina propria (chiave, valore, scadenza) con la pulizia dei scaduti nel giro orario. Dove il sito ha una cache degli
 * oggetti (o la tabella non c'è ancora, aggiornamento a metà) si usano i transient di sempre, che lì stanno in memoria.
 * Il valore è un numero o una lista di numeri; una chiave scaduta è come se non ci fosse.
 */
final class Counters {

	/** I contatori stanno nella tabella? (no con una cache degli oggetti persistente, o con lo schema a metà) */
	public static function uses_table(): bool {
		return ! wp_using_ext_object_cache() && Plugin::schema_ready();
	}

	/** @return int|array|null il valore valido adesso, null se manca o è scaduto */
	public static function get( string $key ) {
		if ( ! self::uses_table() ) {
			$v = get_transient( $key );
			return false === $v ? null : $v;
		}
		$db = Db::db();
		$raw = $db->get_var( $db->prepare( 'SELECT v FROM ' . Db::t( 'counters' ) . ' WHERE k = %s AND expires > %d', $key, time() ) );
		if ( null === $raw ) {
			return null;
		}
		$v = json_decode( (string) $raw, true );
		return is_int( $v ) || is_array( $v ) ? $v : null;
	}

	/** Scrive il valore, valido per $ttl secondi (almeno uno). @param int|array $value */
	public static function set( string $key, $value, int $ttl ): void {
		$ttl = max( 1, $ttl );
		if ( ! self::uses_table() ) {
			set_transient( $key, $value, $ttl );
			return;
		}
		$db = Db::db();
		$db->query( $db->prepare( 'REPLACE INTO ' . Db::t( 'counters' ) . ' (k, v, expires) VALUES (%s, %s, %d)', $key, (string) wp_json_encode( $value ), time() + $ttl ) );
	}

	public static function delete( string $key ): void {
		if ( ! self::uses_table() ) {
			delete_transient( $key );
			return;
		}
		Db::db()->delete( Db::t( 'counters' ), array( 'k' => $key ) );
	}

	/** Giro orario: toglie i contatori scaduti. @return int righe tolte */
	public static function purge(): int {
		if ( ! Plugin::schema_ready() ) {
			return 0;
		}
		$db = Db::db();
		return (int) $db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'counters' ) . ' WHERE expires <= %d', time() ) );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Soci con la scheda incompleta: alla segreteria ricordano cosa manca (codice fiscale, nascita, residenza, recapiti, genitore per i minori). */
final class IncompleteCards {

	/**
	 * Cosa manca nella scheda di un socio.
	 *
	 * @param array   $person    il socio
	 * @param array[] $guardians i suoi genitori o tutori (con is_operative)
	 * @return string[] voci mancanti, vuoto = scheda completa
	 */
	public static function missing( array $person, array $guardians, string $today ): array {
		$has = function ( string $k ) use ( $person ): bool {
			return '' !== trim( (string) ( $person[ $k ] ?? '' ) );
		};
		$out = array();
		if ( ! $has( 'tax_code' ) ) {
			$out[] = 'codice fiscale';
		}
		if ( ! $has( 'birth_date' ) ) {
			$out[] = 'data di nascita';
		}
		if ( ! $has( 'birth_place' ) ) {
			$out[] = 'luogo di nascita';
		}
		if ( ! $has( 'address' ) || ! $has( 'city' ) ) {
			$out[] = 'residenza';
		}
		$minor = $has( 'birth_date' ) && Age::is_minor( (string) $person['birth_date'], $today );
		if ( $minor ) {
			if ( ! $guardians ) {
				$out[] = 'genitore o tutore';
			} else {
				$op = null;
				foreach ( $guardians as $g ) {
					if ( ! empty( $g['is_operative'] ) ) {
						$op = $g;
					}
				}
				$op = $op ?: $guardians[0];
				if ( '' === trim( (string) ( $op['email'] ?? '' ) ) ) {
					$out[] = 'email del tutore che opera sul sito';
				}
				foreach ( $guardians as $g ) {
					if ( '' === trim( (string) ( $g['tax_code'] ?? '' ) ) ) {
						$out[] = 'codice fiscale di ' . trim( $g['first_name'] . ' ' . $g['last_name'] );
					}
				}
			}
		} elseif ( ! $has( 'email' ) && ! $has( 'phone' ) ) {
			$out[] = 'email o telefono';
		}
		return (array) apply_filters( 'asem_incomplete_card_items', $out, $person, $guardians ); // un'estensione aggiunge le sue voci (es. il tipo di tesseramento)
	}

	/** I soci in carica con la scheda incompleta. @return array[] ['person' => socio, 'missing' => string[]] */
	public static function all(): array {
		$db   = Db::db();
		$rows = $db->get_results(
			'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status IS NULL AND anonymized_at IS NULL AND left_on IS NULL AND type NOT IN ('guest','guardian') ORDER BY last_name, first_name",
			ARRAY_A
		) ?: array();
		$map   = Guardians::map_for( array_column( $rows, 'id' ) );
		$today = Db::today();
		$out   = array();
		foreach ( $rows as $p ) {
			$m = self::missing( $p, $map[ (int) $p['id'] ] ?? array(), $today );
			if ( $m ) {
				$out[] = array( 'person' => $p, 'missing' => $m );
			}
		}
		return $out;
	}
}

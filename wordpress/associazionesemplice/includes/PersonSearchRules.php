<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ricerca minima delle persone per chi non ha l'area «Anagrafica soci» ma deve scegliere chi paga o chi iscrivere (parte pura, si prova con i test unitari).
 *
 * Servono almeno tre lettere, i risultati sono al più dieci e di ogni persona si mostrano solo nome, numero di tessera e stato della quota:
 * niente email, telefono, codice fiscale, indirizzo. Si cerca solo nel nome e nel numero di tessera, mai negli altri dati.
 */
final class PersonSearchRules {

	const MIN_CHARS = 3;
	const MAX_RESULTS = 10;

	/** Il testo cercato, ripulito: spazi singoli, al più 60 caratteri. */
	public static function clean( $q ): string {
		$q = is_string( $q ) ? trim( (string) preg_replace( '/\s+/u', ' ', $q ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 60 ) : substr( $q, 0, 60 );
	}

	/** Il testo è abbastanza lungo per cercare? */
	public static function is_searchable( string $clean ): bool {
		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean ) ) >= self::MIN_CHARS;
	}

	/** La persona corrisponde? Solo nome, cognome (in entrambi gli ordini) e numero di tessera; maiuscole e minuscole non contano. */
	public static function matches( array $person, string $clean ): bool {
		if ( ! self::is_searchable( $clean ) ) {
			return false;
		}
		$first = (string) ( $person['first_name'] ?? '' );
		$last  = (string) ( $person['last_name'] ?? '' );
		$low   = function ( string $s ): string {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		};
		$q = $low( $clean );
		foreach ( array( $first, $last, $first . ' ' . $last, $last . ' ' . $first, (string) ( $person['card_number'] ?? '' ) ) as $hay ) {
			if ( '' !== $hay && false !== strpos( $low( $hay ), $q ) ) {
				return true;
			}
		}
		return false;
	}

	/** Stato della quota in due parole (vuoto per chi non è socio). $until è l'ultimo giorno di validità della tessera (Y-m-d) o null. */
	public static function quota_text( bool $is_member, ?string $until, string $today ): string {
		if ( ! $is_member ) {
			return '';
		}
		if ( null === $until || '' === $until || $until < $today ) {
			return 'tessera non in regola';
		}
		return 'tessera valida fino al ' . substr( $until, 8, 2 ) . '/' . substr( $until, 5, 2 ) . '/' . substr( $until, 0, 4 );
	}

	/**
	 * Dal risultato della ricerca alle sole righe che si possono mostrare: al più dieci, solo id, nome con tessera, tipo e stato della quota.
	 *
	 * @param array[] $people righe delle persone (con 'active_until' se c'è)
	 * @param callable $is_member  tipo di persona => è socio?
	 * @param callable $label      riga => testo «n. tessera · nome»
	 * @return array<int,array{id:int,label:string,type:string,quota:string}>
	 */
	public static function results( array $people, string $clean, string $today, callable $is_member, callable $label ): array {
		$out = array();
		if ( ! self::is_searchable( $clean ) ) {
			return $out;
		}
		foreach ( $people as $p ) {
			if ( ! self::matches( $p, $clean ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $p['id'],
				'label' => (string) $label( $p ),
				'type'  => (string) ( $p['type'] ?? '' ),
				'quota' => self::quota_text( (bool) $is_member( (string) ( $p['type'] ?? '' ) ), isset( $p['active_until'] ) ? (string) $p['active_until'] : null, $today ),
			);
			if ( count( $out ) >= self::MAX_RESULTS ) {
				break;
			}
		}
		return $out;
	}
}

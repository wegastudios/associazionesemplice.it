<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Diritto di voto per livello di socio (solo Pro, con la funzione «levels»): ogni livello vota «sì» o «no» (colonna `member_levels.votes`).
 * Si aggancia a {@see VotingRight}, che resta il punto unico: nell'edizione gratuita votano tutti i soci maggiorenni.
 * Un socio senza livello, o con un livello di un'altra base, vota. Se la funzione «levels» non c'è (licenza scaduta o piano senza livelli) il voto
 * dei livelli non conta e votano tutti, come le quote dei livelli tornano a una sola.
 */
final class LevelVoting {

	/** @var array<int,array{votes:int,base:string}>|null livelli di questa richiesta */
	private static $cache = null;

	public static function register(): void {
		add_filter( 'asem_voting_right_level', array( __CLASS__, 'level_votes' ), 10, 2 );
		add_filter( 'asem_voting_right_sql', array( __CLASS__, 'sql' ) );
		add_action( 'asem_levels_saved', array( __CLASS__, 'flush' ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return array<int,array{votes:int,base:string}> */
	private static function levels(): array {
		if ( null === self::$cache ) {
			self::$cache = array();
			foreach ( Levels::all() as $lv ) {
				self::$cache[ (int) $lv['id'] ] = array( 'votes' => (int) ( $lv['votes'] ?? 1 ), 'base' => (string) $lv['base_type'] );
			}
		}
		return self::$cache;
	}

	/** Il livello della persona vota? @param mixed $votes */
	public static function level_votes( $votes, $person ) {
		if ( ! $votes || ! is_array( $person ) || ! Edition::has( 'levels' ) || empty( $person['level_id'] ) ) {
			return $votes;
		}
		$lv = self::levels()[ (int) $person['level_id'] ] ?? null;
		if ( ! $lv || $lv['base'] !== (string) ( $person['type'] ?? '' ) ) {
			return $votes; // livello sconosciuto o di un'altra base: conta come se non ne avesse uno
		}
		return 1 === $lv['votes'];
	}

	/** Condizione SQL equivalente (alias `p`), senza segnaposti. @param mixed $sql */
	public static function sql( $sql ) {
		if ( ! Edition::has( 'levels' ) ) {
			return $sql;
		}
		return $sql . ' AND NOT EXISTS (SELECT 1 FROM ' . Db::t( 'member_levels' ) . ' vl WHERE vl.id = p.level_id AND vl.votes = 0 AND vl.base_type = p.type)';
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure sul motore delle tabelle del database (senza WordPress: si provano con i test unitari).
 *
 * Una tabella InnoDB annulla da sola le scritture rimaste a metà (le «transazioni» che il plugin usa per salvare insieme, ad esempio, una comunicazione
 * e i suoi destinatari, la chiusura di un anno fiscale o il ripristino di una copia); una tabella MyISAM no. Qui si decide quali tabelle sono da convertire
 * e in che ordine.
 */
final class DbEngineRules {

	const TARGET = 'InnoDB';

	/** La tabella usa il motore giusto? Una vista (motore vuoto) non conta. */
	public static function is_ok( $engine ): bool {
		return null === $engine || '' === (string) $engine || 0 === strcasecmp( (string) $engine, self::TARGET );
	}

	/**
	 * Le tabelle da convertire, dalla più piccola alla più grande (le piccole finiscono subito, la grande va per ultima e da sola).
	 *
	 * @param array<int,array{name:string,engine:?string,bytes:int}> $rows
	 * @return array<int,array{name:string,engine:string,bytes:int}>
	 */
	public static function to_convert( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( self::is_ok( $r['engine'] ?? null ) || '' === (string) ( $r['name'] ?? '' ) ) {
				continue;
			}
			$out[] = array( 'name' => (string) $r['name'], 'engine' => (string) $r['engine'], 'bytes' => (int) ( $r['bytes'] ?? 0 ) );
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $a['bytes'] === $b['bytes'] ? strcmp( $a['name'], $b['name'] ) : $a['bytes'] <=> $b['bytes'];
			}
		);
		return $out;
	}

	/** Il motore è dato dal server come «Support» di SHOW ENGINES: disponibile se YES o DEFAULT. */
	public static function engine_usable( $support ): bool {
		return in_array( strtoupper( (string) $support ), array( 'YES', 'DEFAULT' ), true );
	}

	/** Il nome è una tabella del plugin (prefisso del sito + «asem_») e contiene solo caratteri sicuri per essere citato in una query? */
	public static function is_plugin_table( string $name, string $prefix ): bool {
		return 0 === strpos( $name, $prefix . 'asem_' ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}

	/**
	 * Si può tentare un'altra tabella in questo giro? La prima sempre; poi finché le convertite sono meno del massimo e non è passato il tempo concesso
	 * (le tabelle che non si convertono costano pochissimo e non occupano un posto: una tabella ostinata non ne blocca altre).
	 * Una tabella grande può richiedere più del tempo che resta: per questo si decide prima di ogni conversione.
	 */
	public static function may_continue( int $converted, int $attempted, float $elapsed, int $max_tables, float $max_seconds ): bool {
		if ( 0 === $attempted ) {
			return true;
		}
		return $converted < $max_tables && $elapsed < $max_seconds;
	}
}

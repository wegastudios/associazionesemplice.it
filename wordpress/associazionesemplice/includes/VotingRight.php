<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Diritto di voto dei soci: è una proprietà del TIPO di socio («questo tipo vota: sì/no», in Impostazioni → Soci e quote) e i minorenni
 * NON votano mai. Vota chi è di un tipo con diritto di voto ed è maggiorenne alla data considerata.
 *
 * Di partenza votano tutti e tre i tipi di socio (fondatore, ordinario, socio e volontario): è ciò che il programma già faceva per votazioni e
 * assemblee (ogni socio in regola), quindi per i tipi esistenti non cambia nulla. Cambia solo che i minorenni, di cui si conosce la data di
 * nascita, ora sono esclusi; senza data di nascita non si sa e si lascia il voto (come prima).
 * Un livello di socio (Pro) segue il tipo di base da cui deriva.
 */
final class VotingRight {

	/** I tipi di socio che votano (da Impostazioni; di partenza tutti). @return string[] */
	public static function types(): array {
		$saved = function_exists( 'get_option' ) ? Settings::get( 'voting_types' ) : null;
		return self::clean_types( is_array( $saved ) ? $saved : MemberType::member_types() );
	}

	/** Solo tipi di socio veri; se la scelta è vuota o sbagliata votano tutti (un'associazione dove nessuno vota non avrebbe senso). @return string[] */
	public static function clean_types( array $types ): array {
		$ok = array_values( array_intersect( MemberType::member_types(), array_map( 'strval', $types ) ) );
		return $ok ? $ok : MemberType::member_types();
	}

	public static function type_votes( string $type ): bool {
		return in_array( $type, self::types(), true );
	}

	/**
	 * La regola: il tipo vota e la persona è maggiorenne alla data (senza data di nascita si ritiene maggiorenne).
	 */
	public static function decide( bool $type_votes, ?string $birth_date, string $date ): bool {
		return $type_votes && ! Age::is_minor( $birth_date, $date );
	}

	/** Una persona (riga di `people`) ha diritto di voto alla data? */
	public static function person_votes( array $person, string $date ): bool {
		return MemberType::is_member( (string) $person['type'] ) && self::decide( self::type_votes( (string) $person['type'] ), $person['birth_date'] ?? null, $date );
	}

	/**
	 * Condizione SQL (alias `p`) equivalente a person_votes alla data: tipo che vota e maggiorenne (o senza data di nascita). Va preparata con
	 * {@see \wpdb::prepare()}: contiene un segnaposto %s per la data.
	 */
	public static function sql_condition(): string {
		$types = "'" . implode( "','", array_map( 'esc_sql', self::types() ) ) . "'";
		return "p.type IN ($types) AND (p.birth_date IS NULL OR p.birth_date <= DATE_SUB(%s, INTERVAL " . (int) Age::ADULT_AT . ' YEAR))';
	}
}

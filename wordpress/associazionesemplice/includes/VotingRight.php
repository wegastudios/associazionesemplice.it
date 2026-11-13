<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Diritto di voto dei soci. È il PUNTO UNICO: votazioni, assemblee e «Dati per il RUNTS» passano da qui.
 *
 * Nell'edizione gratuita hanno diritto di voto TUTTI i soci (fondatori, ordinari, soci e volontari), non è configurabile. I minorenni non votano mai:
 * vota chi è socio ed è maggiorenne alla data considerata (senza data di nascita non si sa e si lascia il voto).
 *
 * Nell'edizione Pro il voto si decide per singolo livello di socio (nell'editor dei livelli): il Pro si aggancia con due filtri, uno per la singola
 * persona (`asem_voting_right_level`) e uno per le interrogazioni SQL (`asem_voting_right_sql`). Senza il Pro i filtri non fanno nulla.
 */
final class VotingRight {

	/** Il livello del socio dà diritto di voto? Di partenza sì (gratuito: sempre). Il Pro risponde in base al livello. */
	public static function level_votes( array $person ): bool {
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'asem_voting_right_level', true, $person ) : true;
	}

	/** La regola: il livello vota e la persona è maggiorenne alla data (senza data di nascita si ritiene maggiorenne). */
	public static function decide( bool $level_votes, ?string $birth_date, string $date ): bool {
		return $level_votes && ! Age::is_minor( $birth_date, $date );
	}

	/** Una persona (riga di `people`) ha diritto di voto alla data? */
	public static function person_votes( array $person, string $date ): bool {
		return MemberType::is_member( (string) $person['type'] ) && self::decide( self::level_votes( $person ), $person['birth_date'] ?? null, $date );
	}

	/**
	 * Perché una persona non vota (o vota senza certezza), in parole: '' = vota senza dubbi; 'minor' = minorenne; 'level' = il suo livello (o tipo) non
	 * vota; 'nobirth' = vota ma manca la data di nascita.
	 */
	public static function reason( array $person, string $date ): string {
		if ( ! self::level_votes( $person ) ) {
			return 'level';
		}
		if ( Age::is_minor( $person['birth_date'] ?? null, $date ) ) {
			return 'minor';
		}
		return empty( $person['birth_date'] ) ? 'nobirth' : '';
	}

	/**
	 * Condizione SQL (alias `p`) equivalente a person_votes alla data: socio maggiorenne (o senza data di nascita), più la condizione del livello
	 * se il Pro ne aggiunge una. Va preparata con {@see \wpdb::prepare()}: contiene un segnaposto %s per la data (e nient'altro).
	 */
	public static function sql_condition(): string {
		$types = "'" . implode( "','", array_map( 'esc_sql', MemberType::member_types() ) ) . "'";
		$extra = function_exists( 'apply_filters' ) ? (string) apply_filters( 'asem_voting_right_sql', '' ) : '';
		return "p.type IN ($types) AND (p.birth_date IS NULL OR p.birth_date <= DATE_SUB(%s, INTERVAL " . (int) Age::ADULT_AT . ' YEAR))' . $extra;
	}
}

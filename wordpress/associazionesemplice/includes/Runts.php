<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati per il RUNTS: i numeri da riportare nel portale del Registro unico del Terzo settore, riferiti a una data (di norma il 31 dicembre),
 * e il controllo del numero minimo di soci per le ODV e le APS. Le regole pure stanno in {@see RuntsRules}.
 *
 * Chi è socio a una data lo decide il criterio unico del libro soci ({@see MemberBook::qualifies()}), qui applicato a un solo giorno.
 */
final class Runts {

	private static function db(): \wpdb {
		return Db::db();
	}

	/** La sezione del RUNTS indicata nei dati dell'ente ('' = non indicata). */
	public static function section(): string {
		$s = (string) Settings::get( 'runts_section' );
		return RuntsRules::section_valid( $s ) ? $s : '';
	}

	/** L'ente ha a che fare con il RUNTS (ha indicato la sezione o il numero di iscrizione)? Senza, non si mostrano promemoria. */
	public static function applies(): bool {
		return '' !== self::section() || '' !== trim( (string) Settings::get( 'runts_number' ) );
	}

	/** Il numero dei volontari del registro, inserito a mano, e l'anno a cui si riferisce. @return array{volunteers:int,year:int} */
	public static function manual(): array {
		return array(
			'volunteers' => max( 0, (int) Settings::get( 'runts_volunteers' ) ),
			'year'       => max( 0, (int) Settings::get( 'runts_manual_year' ) ),
		);
	}

	/**
	 * Lavoratori (dipendenti a qualsiasi titolo) alla data: il numero per il RUNTS è il conteggio degli utenti «Dipendente», un tipo di utente che
	 * verrà aggiunto in una fase a parte. Finché non esiste restituisce 0. È l'UNICO punto da cui il numero entra nei Dati per il RUNTS: chi
	 * costruirà il ruolo si aggancia al filtro `asem_runts_workers_count`.
	 *
	 * @param int    $count valore di partenza (0)
	 * @param string $date  data di riferimento (Y-m-d)
	 */
	public static function workers_count( string $date ): int {
		return max( 0, (int) apply_filters( 'asem_runts_workers_count', 0, $date ) );
	}

	/**
	 * I numeri riferiti a una data.
	 *
	 * Vota chi è di un tipo di socio con diritto di voto (scelta di OGGI in Impostazioni: il programma non tiene lo storico) ed era maggiorenne alla data.
	 *
	 * @return array{date:string,persons:int,persons_vote:int,persons_no_vote:int,entities:array[],entities_same_section:int,volunteer_members:int}
	 */
	public static function counts_at( string $date ): array {
		$out = array( 'date' => $date, 'persons' => 0, 'persons_vote' => 0, 'persons_no_vote' => 0, 'entities' => array(), 'entities_same_section' => 0, 'volunteer_members' => 0 );
		foreach ( MemberBook::qualifying_people( $date, $date ) as $p ) {
			if ( RuntsRules::ENTITY === RuntsRules::kind( $p['subject_kind'] ?? '' ) ) {
				$same              = null === ( $p['runts_same_section'] ?? null ) ? null : ( 1 === (int) $p['runts_same_section'] );
				$out['entities'][] = array(
					'id'       => (int) $p['id'],
					'name'     => empty( $p['anonymized_at'] ) ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '(dati anonimizzati)',
					'tax_code' => empty( $p['anonymized_at'] ) ? (string) $p['tax_code'] : '',
					'same'     => $same,
				);
				$out['entities_same_section'] += true === $same ? 1 : 0;
				continue;
			}
			++$out['persons'];
			if ( MemberType::VOLUNTEER === $p['type'] ) {
				++$out['volunteer_members'];
			}
			if ( VotingRight::person_votes( $p, $date ) ) { // tipo di socio che vota e maggiorenne ALLA DATA
				++$out['persons_vote'];
			} else {
				++$out['persons_no_vote'];
			}
		}
		return $out;
	}

	/**
	 * Il numero minimo di soci per le ODV e le APS. Qui si contano i soci NON cessati (anche con la tessera ancora da rinnovare: una tessera scaduta
	 * non è una cessazione), perché a gennaio, prima dei rinnovi, l'avviso scatterebbe per tutti.
	 *
	 * @return array{applies:bool,section:string,persons:int,entities:int,below:bool,since:?string,deadline:?string}
	 */
	public static function minimum_state( ?string $today = null ): array {
		$today   = $today ?? Db::today();
		$section = self::section();
		$out     = array( 'applies' => RuntsRules::has_minimum( $section ), 'section' => $section, 'persons' => 0, 'entities' => 0, 'below' => false, 'since' => null, 'deadline' => null );
		if ( ! $out['applies'] ) {
			return $out;
		}
		$members = array();
		foreach ( self::db()->get_results( 'SELECT subject_kind, runts_same_section, joined_on, left_on FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status IS NULL AND type NOT IN ('guest','guardian')", ARRAY_A ) ?: array() as $r ) {
			$members[] = array(
				'kind'   => RuntsRules::kind( $r['subject_kind'] ),
				'same'   => 1 === (int) $r['runts_same_section'],
				'joined' => (string) $r['joined_on'],
				'left'   => (string) $r['left_on'],
			);
		}
		list( $out['persons'], $out['entities'] ) = RuntsRules::counts_on( $members, $today );
		$out['below'] = RuntsRules::below_minimum( $out['persons'], $out['entities'] );
		if ( $out['below'] ) {
			$out['since']    = RuntsRules::below_since( $members, $today );
			$out['deadline'] = null === $out['since'] ? null : RuntsRules::notice_deadline( $out['since'] );
		}
		return $out;
	}
}

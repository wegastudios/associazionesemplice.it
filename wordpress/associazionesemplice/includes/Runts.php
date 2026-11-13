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
	 * Lavoratori (dipendenti a qualsiasi titolo) alla data: il numero per il RUNTS è il conteggio degli utenti «Dipendente». È l'UNICO punto da cui il
	 * numero entra nei Dati per il RUNTS, attraverso il filtro `asem_runts_workers_count` (valore, data). Il valore di partenza è null = «non disponibile»:
	 * finché nessuno risponde con un numero (intero, zero compreso) la pagina non scrive un falso 0 ma dice che il programma non li conta ancora.
	 *
	 * @return int|null il numero, oppure null se il programma non lo sa
	 */
	public static function workers_count( string $date ): ?int {
		$v = apply_filters( 'asem_runts_workers_count', null, $date );
		return ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) ? max( 0, (int) $v ) : null;
	}

	/**
	 * I numeri riferiti a una data.
	 *
	 * Il diritto di voto è quello di OGGI ({@see VotingRight}: nel gratuito tutti i soci, nel Pro per livello; il programma non tiene lo storico) e
	 * vale per chi era maggiorenne alla data. `persons_vote_no_birth` sono i votanti senza data di nascita: contati come maggiorenni.
	 *
	 * @return array{date:string,persons:int,persons_vote:int,persons_no_vote:int,persons_vote_no_birth:int,entities:array[],entities_same_section:int,volunteer_members:int}
	 */
	public static function counts_at( string $date ): array {
		$out = array( 'date' => $date, 'persons' => 0, 'persons_vote' => 0, 'persons_no_vote' => 0, 'persons_vote_no_birth' => 0, 'entities' => array(), 'entities_same_section' => 0, 'volunteer_members' => 0 );
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
				if ( empty( $p['birth_date'] ) ) {
					++$out['persons_vote_no_birth'];
				}
			} else {
				++$out['persons_no_vote'];
			}
		}
		return $out;
	}

	/**
	 * Il numero minimo di soci per le ODV e le APS. Si contano come nel conteggio del 31 dicembre: i soci con la tessera VALIDA oggi (criterio unico
	 * {@see MemberBook::qualifies()} sul giorno corrente), non i non cessati. Un socio che non ha rinnovato non conta finché non rinnova; i cessati veri
	 * (espulsi, dimissionari) hanno la loro data. Per non dare falsi allarmi nei mesi dei rinnovi si contano a parte i soci con la tessera scaduta da poco
	 * ({@see RuntsRules::RECENT_EXPIRY_DAYS} giorni): `recent` = quanti sono, `recoverable` = se rinnovando tutti si tornerebbe sopra il minimo.
	 *
	 * La data da cui si è scesi (`since`) NON è memorizzata: si ricalcola ogni volta dalla storia (ingressi, cessazioni, inizio e fine delle tessere),
	 * con lo stesso criterio, cercando all'indietro l'ultima data in cui il conteggio era ancora sopra il minimo. Così non dipende da chi apre la pagina.
	 *
	 * @return array{applies:bool,section:string,persons:int,entities:int,below:bool,since:?string,deadline:?string,recent:int,recoverable:bool}
	 */
	public static function minimum_state( ?string $today = null ): array {
		$today   = $today ?? Db::today();
		$section = self::section();
		$out     = array( 'applies' => RuntsRules::has_minimum( $section ), 'section' => $section, 'persons' => 0, 'entities' => 0, 'below' => false, 'since' => null, 'deadline' => null, 'recent' => 0, 'recoverable' => false );
		if ( ! $out['applies'] ) {
			return $out;
		}
		list( $rows, $cards ) = MemberBook::candidates();
		$count                = function ( string $d ) use ( $rows, $cards ): array {
			$p = 0;
			$e = 0;
			foreach ( $rows as $r ) {
				if ( ! MemberBook::qualifies( $r, $cards[ (int) $r['id'] ] ?? array(), $d, $d ) ) {
					continue;
				}
				if ( RuntsRules::ENTITY === RuntsRules::kind( $r['subject_kind'] ?? '' ) ) {
					$e += 1 === (int) ( $r['runts_same_section'] ?? 0 ) ? 1 : 0;
				} else {
					++$p;
				}
			}
			return array( $p, $e );
		};
		list( $out['persons'], $out['entities'] ) = $count( $today );
		$out['below'] = RuntsRules::below_minimum( $out['persons'], $out['entities'] );
		$rp = 0;
		$re = 0;
		$members = array();
		foreach ( $rows as $r ) {
			$mine      = array();
			foreach ( $cards[ (int) $r['id'] ] ?? array() as $c ) {
				$mine[] = array( (string) $c['valid_from'], (string) $c['valid_to'] );
			}
			$members[] = array( 'joined' => (string) $r['joined_on'], 'left' => (string) $r['left_on'], 'cards' => $mine );
			if ( MemberType::is_auto_renewed( (string) $r['type'] ) || ! RuntsRules::recently_expired( $mine, (string) $r['left_on'], $today ) ) {
				continue;
			}
			if ( RuntsRules::ENTITY === RuntsRules::kind( $r['subject_kind'] ?? '' ) ) {
				$re += 1 === (int) ( $r['runts_same_section'] ?? 0 ) ? 1 : 0;
			} else {
				++$rp;
			}
		}
		$out['recent']      = $rp + $re;
		$out['recoverable'] = $out['below'] && $out['recent'] > 0 && ! RuntsRules::below_minimum( $out['persons'] + $rp, $out['entities'] + $re );
		if ( ! $out['below'] ) {
			return $out;
		}
		$out['since']       =RuntsRules::below_since( RuntsRules::change_dates( $members, $today ), $count );
		$out['deadline']    = null === $out['since'] ? null : RuntsRules::notice_deadline( $out['since'] );
		return $out;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Assemblee dei soci: convocazione, presenze, deleghe tra soci, numero legale e verbale collegato. Le regole pure stanno in {@see AssemblyRules}.
 * Ha diritto di intervenire chi ha diritto di voto (come nelle votazioni): socio in regola, non sospeso, non uscito. Tutori e ospiti no.
 */
final class Assemblies {

	const MAX_AGENDA = 5000;

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Registro ----------

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in, ?int $user_id = null ): int {
		$old = $id ? self::get( $id ) : null;
		if ( $id && ! $old ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( $old && AssemblyRules::HELD === $old['status'] ) {
			throw new \InvalidArgumentException( 'L\'assemblea è già stata svolta: non si modifica più.' );
		}
		$title = mb_substr( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), 0, 190 );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Scrivi il titolo.' );
		}
		$kind   = isset( AssemblyRules::kinds()[ (string) ( $in['kind'] ?? '' ) ] ) ? (string) $in['kind'] : AssemblyRules::ORDINARY;
		$first  = PollRules::parse_local( (string) ( $in['first_call_at'] ?? '' ) );
		$second = PollRules::parse_local( (string) ( $in['second_call_at'] ?? '' ) );
		if ( ! $first ) {
			throw new \InvalidArgumentException( 'Indica data e ora della prima convocazione.' );
		}
		$err = AssemblyRules::check_calls( $first, (string) $second );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$defaults = AssemblyRules::default_quorum( $kind );
		$d        = array(
			'kind'           => $kind,
			'title'          => $title,
			'first_call_at'  => $first,
			'second_call_at' => $second,
			'place'          => mb_substr( trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) ), 0, 190 ),
			'agenda'         => mb_substr( trim( sanitize_textarea_field( (string) ( $in['agenda'] ?? '' ) ) ), 0, self::MAX_AGENDA ),
			'quorum_first'   => isset( $in['quorum_first'] ) && '' !== (string) $in['quorum_first'] ? AssemblyRules::clean_percent( $in['quorum_first'] ) : $defaults[0],
			'quorum_second'  => isset( $in['quorum_second'] ) && '' !== (string) $in['quorum_second'] ? AssemblyRules::clean_percent( $in['quorum_second'] ) : $defaults[1],
		);
		$db = self::db();
		if ( $id ) {
			$db->update( Db::t( 'assemblies' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
		} else {
			$ok = $db->insert( Db::t( 'assemblies' ), $d + array( 'status' => AssemblyRules::DRAFT, 'created_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
			if ( ! $ok ) {
				throw new \InvalidArgumentException( 'Impossibile salvare (errore del database).' );
			}
			$id = (int) $db->insert_id;
		}
		Audit::log( $old ? 'assembly.updated' : 'assembly.created', 'assembly', $id, array( 'kind' => $kind ) );
		return $id;
	}

	public static function delete( int $id ): void {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( AssemblyRules::HELD === $a['status'] ) {
			throw new \InvalidArgumentException( 'Un\'assemblea svolta resta nel registro: si può solo consultare.' );
		}
		self::db()->update( Db::t( 'assemblies' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'assembly.deleted', 'assembly', $id );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'assemblies' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** @return array[] dalla più recente */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'assemblies' ) . ' WHERE deleted_at IS NULL ORDER BY first_call_at DESC, id DESC', ARRAY_A ) ?: array();
	}

	// ---------- Chi ha diritto di intervenire ----------

	private static function members_audience(): array {
		return array( 'audience' => PollRules::AUDIENCE_MEMBERS, 'activity_id' => 0 );
	}

	public static function is_eligible( array $person ): bool {
		return MemberType::is_member( (string) $person['type'] ) && Polls::is_eligible( self::members_audience(), $person );
	}

	/** Quanti soci hanno diritto di intervenire (oggi). */
	public static function eligible_count(): int {
		return Polls::eligible_count( self::members_audience() );
	}

	/** @return array[] persone */
	public static function eligible_people(): array {
		return Polls::eligible_people( self::members_audience() );
	}

	private static function name( ?array $r ): string {
		if ( ! $r || ! empty( $r['anonymized_at'] ) ) {
			return 'Persona anonimizzata';
		}
		return trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
	}

	// ---------- Convocazione ----------

	/**
	 * Convoca l'assemblea: lo stato diventa «Convocata» e ogni socio con diritto riceve l'avviso per email (una sola volta per persona).
	 * Richiamandola su un'assemblea già convocata si mandano gli avvisi a chi non li ha ricevuti.
	 *
	 * @return array{sent:int,no_email:int,warning:string}
	 * @throws \InvalidArgumentException
	 */
	public static function convene( int $id, bool $send = true ): array {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( AssemblyRules::CONVENED !== $a['status'] && ! AssemblyRules::can_move( (string) $a['status'], AssemblyRules::CONVENED ) ) {
			throw new \InvalidArgumentException( 'Questa assemblea non si può convocare (stato: ' . AssemblyRules::status_labels()[ $a['status'] ] . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( '' === trim( (string) $a['agenda'] ) ) {
			throw new \InvalidArgumentException( 'Scrivi l\'ordine del giorno prima di convocare.' );
		}
		$today = Db::today();
		if ( AssemblyRules::CONVENED !== $a['status'] ) {
			self::db()->update( Db::t( 'assemblies' ), array( 'status' => AssemblyRules::CONVENED, 'convened_on' => $today, 'updated_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'assembly.convened', 'assembly', $id );
			$a['convened_on'] = $today;
		}
		$warning = AssemblyRules::notice_warning( AssemblyRules::notice_days( (string) $a['convened_on'], substr( (string) $a['first_call_at'], 0, 10 ) ) );
		$sent    = 0;
		$none    = 0;
		if ( $send ) {
			list( $subject, $body ) = self::call_text( $a );
			foreach ( self::eligible_people() as $p ) {
				if ( null === Reminders::recipient( $p ) ) {
					$none++;
				} elseif ( Reminders::notify( $p, 'assembly_call', (string) $id, $subject, $body ) ) {
					$sent++;
				}
			}
			Audit::log( 'assembly.announced', 'assembly', $id, array( 'sent' => $sent ) );
		}
		return array( 'sent' => $sent, 'no_email' => $none, 'warning' => $warning );
	}

	/** Oggetto e testo dell'email di convocazione. */
	private static function call_text( array $a ): array {
		$kinds = AssemblyRules::kinds();
		$text  = 'sei convocato all\'' . mb_strtolower( $kinds[ $a['kind'] ] ) . ' «' . $a['title'] . '».' . "\n"
			. 'Prima convocazione: ' . mysql2date( 'd/m/Y \a\l\l\e H:i', (string) $a['first_call_at'] ) . ( '' !== (string) $a['place'] ? ', ' . $a['place'] : '' ) . ".\n";
		if ( ! empty( $a['second_call_at'] ) ) {
			$text .= 'Seconda convocazione: ' . mysql2date( 'd/m/Y \a\l\l\e H:i', (string) $a['second_call_at'] ) . ".\n";
		}
		$text .= "\nOrdine del giorno:\n" . $a['agenda'] . "\n\n"
			. 'Se non puoi partecipare puoi delegare un altro socio dall\'area riservata: ' . Gatekeeper::area_url();
		return array( 'Convocazione: ' . $a['title'], $text );
	}

	/** Cambia stato: annullata, tornata in bozza. @throws \InvalidArgumentException */
	public static function move( int $id, string $to ): void {
		$a = self::get( $id );
		if ( ! $a || ! AssemblyRules::can_move( (string) $a['status'], $to ) ) {
			throw new \InvalidArgumentException( 'Questa operazione non è possibile per lo stato attuale dell\'assemblea.' );
		}
		self::db()->update( Db::t( 'assemblies' ), array( 'status' => $to, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'assembly.' . $to, 'assembly', $id );
	}

	// ---------- Presenze ----------

	/** @return int[] id delle persone presenti */
	public static function present_ids( int $id ): array {
		return array_map( 'intval', self::db()->get_col( self::db()->prepare( 'SELECT person_id FROM ' . Db::t( 'assembly_attendees' ) . ' WHERE assembly_id = %d', $id ) ) ?: array() );
	}

	/**
	 * Registra chi è presente (sostituisce l'elenco). Si registrano solo i soci con diritto di intervenire.
	 *
	 * @param int[] $person_ids
	 * @throws \InvalidArgumentException
	 */
	public static function set_attendance( int $id, array $person_ids ): int {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( AssemblyRules::HELD === $a['status'] || AssemblyRules::CANCELLED === $a['status'] ) {
			throw new \InvalidArgumentException( 'Le presenze si registrano finché l\'assemblea non è svolta o annullata.' );
		}
		$ids = array();
		foreach ( array_unique( array_map( 'intval', $person_ids ) ) as $pid ) {
			$p = $pid > 0 ? Plugin::people()->get( $pid ) : null;
			if ( $p && self::is_eligible( $p ) ) {
				$ids[] = $pid;
			}
		}
		$db = self::db();
		$db->delete( Db::t( 'assembly_attendees' ), array( 'assembly_id' => $id ) );
		foreach ( $ids as $pid ) {
			$db->insert( Db::t( 'assembly_attendees' ), array( 'assembly_id' => $id, 'person_id' => $pid, 'marked_at' => Db::now() ) );
		}
		Audit::log( 'assembly.attendance', 'assembly', $id, array( 'present' => count( $ids ) ) );
		return count( $ids );
	}

	/** @return array[] cognome, nome: i presenti con il nome */
	public static function present_people( int $id ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT p.id, p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'assembly_attendees' ) . ' a LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = a.person_id WHERE a.assembly_id = %d ORDER BY p.last_name, p.first_name', $id ),
			ARRAY_A
		) ?: array();
	}

	// ---------- Deleghe ----------

	/** @return array[] delegator_id, delegate_id, nomi */
	public static function proxies( int $id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT x.delegator_id, x.delegate_id, x.created_at, d1.first_name AS f1, d1.last_name AS l1, d1.anonymized_at AS an1, d2.first_name AS f2, d2.last_name AS l2, d2.anonymized_at AS an2 FROM '
				. Db::t( 'assembly_proxies' ) . ' x LEFT JOIN ' . Db::t( 'people' ) . ' d1 ON d1.id = x.delegator_id LEFT JOIN ' . Db::t( 'people' ) . ' d2 ON d2.id = x.delegate_id WHERE x.assembly_id = %d ORDER BY d2.last_name, d1.last_name',
				$id
			),
			ARRAY_A
		) ?: array();
	}

	public static function proxy_name( array $row, string $side ): string {
		return 'a' === $side
			? self::name( array( 'first_name' => $row['f1'], 'last_name' => $row['l1'], 'anonymized_at' => $row['an1'] ) )
			: self::name( array( 'first_name' => $row['f2'], 'last_name' => $row['l2'], 'anonymized_at' => $row['an2'] ) );
	}

	/** La delega che un socio ha dato per questa assemblea, se c'è. */
	public static function proxy_of( int $id, int $delegator ): ?array {
		foreach ( self::proxies( $id ) as $x ) {
			if ( (int) $x['delegator_id'] === $delegator ) {
				return $x;
			}
		}
		return null;
	}

	/**
	 * Registra una delega. Vale per una sola assemblea, tra soci in regola, senza catene (chi riceve non delega e chi delega non riceve).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function add_proxy( int $id, int $delegator, int $delegate ): void {
		Db::with_lock( 'asem_assembly_' . $id, function () use ( $id, $delegator, $delegate ) {
			self::add_proxy_locked( $id, $delegator, $delegate );
		} );
	}

	private static function add_proxy_locked( int $id, int $delegator, int $delegate ): void {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( ! in_array( $a['status'], array( AssemblyRules::DRAFT, AssemblyRules::CONVENED ), true ) ) {
			throw new \InvalidArgumentException( 'Le deleghe si danno prima dell\'assemblea.' );
		}
		$p1 = Plugin::people()->get( $delegator );
		$p2 = Plugin::people()->get( $delegate );
		if ( ! $p1 || ! $p2 ) {
			throw new \InvalidArgumentException( 'Socio non trovato.' );
		}
		$all     = self::proxies( $id );
		$max     = AssemblyRules::max_proxies( self::eligible_count() );
		$reason  = AssemblyRules::proxy_refusal(
			$delegator,
			$delegate,
			self::is_eligible( $p1 ),
			self::is_eligible( $p2 ),
			array_map( 'intval', array_column( array_filter( $all, function ( $x ) use ( $delegate ) {
				return (int) $x['delegate_id'] === $delegate;
			} ), 'delegator_id' ) ),
			array_map( 'intval', array_column( $all, 'delegator_id' ) ),
			array_map( 'intval', array_column( $all, 'delegate_id' ) ),
			$max
		);
		if ( '' !== $reason ) {
			throw new \InvalidArgumentException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::db()->insert( Db::t( 'assembly_proxies' ), array( 'assembly_id' => $id, 'delegator_id' => $delegator, 'delegate_id' => $delegate, 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now() ) );
		Audit::log( 'assembly.proxy_added', 'assembly', $id, array( 'delegator' => $delegator, 'delegate' => $delegate ) );
	}

	/** @throws \InvalidArgumentException */
	public static function revoke_proxy( int $id, int $delegator ): void {
		$a = self::get( $id );
		if ( ! $a || ! in_array( $a['status'], array( AssemblyRules::DRAFT, AssemblyRules::CONVENED ), true ) ) {
			throw new \InvalidArgumentException( 'Le deleghe si revocano prima dell\'assemblea.' );
		}
		self::db()->delete( Db::t( 'assembly_proxies' ), array( 'assembly_id' => $id, 'delegator_id' => $delegator ) );
		Audit::log( 'assembly.proxy_revoked', 'assembly', $id, array( 'delegator' => $delegator ) );
	}

	// ---------- Numero legale ----------

	/**
	 * Situazione delle presenze. Le deleghe contano solo se chi le ha ricevute è presente e chi ha delegato non è venuto di persona.
	 *
	 * @param int $call 1 = prima convocazione, 2 = seconda (0 = quella già scelta, altrimenti la prima)
	 * @return array{eligible:int,present:int,proxies:int,quorum:array,call:int,percent:int}
	 */
	public static function tally( int $id, int $call = 0 ): array {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		$present  = self::present_ids( $id );
		$proxies  = 0;
		foreach ( self::proxies( $id ) as $x ) {
			if ( in_array( (int) $x['delegate_id'], $present, true ) && ! in_array( (int) $x['delegator_id'], $present, true ) ) {
				$proxies++;
			}
		}
		$eligible = AssemblyRules::HELD === $a['status'] && null !== $a['eligible_at_close'] ? (int) $a['eligible_at_close'] : self::eligible_count();
		$call     = $call ?: ( (int) $a['call_held'] ?: 1 );
		$percent  = AssemblyRules::percent_for_call( $call, (int) $a['quorum_first'], (int) $a['quorum_second'] );
		return array(
			'eligible' => $eligible,
			'present'  => count( $present ),
			'proxies'  => $proxies,
			'quorum'   => AssemblyRules::quorum( $eligible, count( $present ), $proxies, $percent ),
			'call'     => $call,
			'percent'  => $percent,
		);
	}

	/**
	 * Chiude l'assemblea come «Svolta» nella convocazione indicata. Fotografa il numero degli aventi diritto.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function hold( int $id, int $call ): array {
		$a = self::get( $id );
		if ( ! $a || AssemblyRules::CONVENED !== $a['status'] ) {
			throw new \InvalidArgumentException( 'Si può chiudere solo un\'assemblea convocata.' );
		}
		$call = 2 === $call && ! empty( $a['second_call_at'] ) ? 2 : 1;
		$t    = self::tally( $id, $call );
		if ( 0 === $t['present'] ) {
			throw new \InvalidArgumentException( 'Registra prima le presenze.' );
		}
		if ( ! $t['quorum']['reached'] ) {
			throw new \InvalidArgumentException( 'Non c\'è il numero legale (' . $t['quorum']['counted'] . ' su ' . $t['quorum']['required'] . ' richiesti): se l\'assemblea è andata deserta, annullala o passa alla seconda convocazione.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		self::db()->update(
			Db::t( 'assemblies' ),
			array( 'status' => AssemblyRules::HELD, 'call_held' => $call, 'eligible_at_close' => $t['eligible'], 'updated_at' => Db::now() ),
			array( 'id' => $id )
		);
		Audit::log( 'assembly.held', 'assembly', $id, array( 'call' => $call, 'present' => $t['present'], 'proxies' => $t['proxies'] ) );
		return $t;
	}

	// ---------- Verbale ----------

	/**
	 * Crea il verbale dell'assemblea già compilato con convocazione, presenti, deleghe e numero legale, e lo collega.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function create_minutes( int $id, ?int $user_id = null ): int {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( AssemblyRules::HELD !== $a['status'] ) {
			throw new \InvalidArgumentException( 'Il verbale si crea dopo aver chiuso l\'assemblea come «Svolta».' );
		}
		if ( ! empty( $a['minutes_id'] ) && Minutes::get( (int) $a['minutes_id'] ) ) {
			throw new \InvalidArgumentException( 'L\'assemblea ha già un verbale collegato.' );
		}
		$t     = self::tally( $id );
		$names = array();
		foreach ( self::present_people( $id ) as $r ) {
			$names[] = self::name( $r );
		}
		$by_proxy = array();
		$present  = self::present_ids( $id );
		foreach ( self::proxies( $id ) as $x ) {
			if ( in_array( (int) $x['delegate_id'], $present, true ) && ! in_array( (int) $x['delegator_id'], $present, true ) ) {
				$by_proxy[] = self::proxy_name( $x, 'a' ) . ' (delega a ' . self::proxy_name( $x, 'b' ) . ')';
			}
		}
		$call = $t['call'];
		$body = AssemblyRules::summary_line( $t['eligible'], $t['present'], $t['proxies'], $t['quorum'], $call ) . "\n\n"
			. 'Ordine del giorno:' . "\n" . $a['agenda'] . "\n\n"
			. 'Svolgimento e deliberazioni:' . "\n" . '(da compilare)';
		$date = substr( (string) ( 2 === $call && ! empty( $a['second_call_at'] ) ? $a['second_call_at'] : $a['first_call_at'] ), 0, 10 );
		$mid  = Minutes::save(
			0,
			array(
				'kind'         => Minutes::ASSEMBLY,
				'meeting_date' => $date,
				'place'        => (string) $a['place'],
				'title'        => (string) $a['title'],
				'attendees'    => implode( ', ', $names ) . ( $by_proxy ? "\nRappresentati per delega: " . implode( ', ', $by_proxy ) : '' ),
				'agenda'       => (string) $a['agenda'],
				'body'         => $body,
			),
			$user_id
		);
		self::db()->update( Db::t( 'assemblies' ), array( 'minutes_id' => $mid, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'assembly.minutes', 'assembly', $id, array( 'minutes' => $mid ) );
		return $mid;
	}

	// ---------- Area soci ----------

	/**
	 * Le assemblee convocate non ancora svolte che riguardano il socio, con la sua delega e quelle ricevute.
	 *
	 * @return array[]
	 */
	public static function for_person( array $person ): array {
		if ( ! self::is_eligible( $person ) ) {
			return array();
		}
		$pid = (int) $person['id'];
		$out = array();
		foreach ( self::all() as $a ) {
			if ( AssemblyRules::CONVENED !== $a['status'] ) {
				continue;
			}
			$id          = (int) $a['id'];
			$a['given']  = self::proxy_of( $id, $pid );
			$a['gotten'] = array();
			foreach ( self::proxies( $id ) as $x ) {
				if ( (int) $x['delegate_id'] === $pid ) {
					$a['gotten'][] = self::proxy_name( $x, 'a' );
				}
			}
			$out[] = $a;
		}
		return $out;
	}

	/** Il file CSV del foglio presenze: tutti i soci con diritto, con presente / delegato / assente. */
	public static function sheet_csv( int $id ): string {
		$a = self::get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		$present = self::present_ids( $id );
		$given   = array();
		foreach ( self::proxies( $id ) as $x ) {
			$given[ (int) $x['delegator_id'] ] = self::proxy_name( $x, 'b' );
		}
		$rows = array();
		foreach ( self::eligible_people() as $p ) {
			$pid    = (int) $p['id'];
			$status = in_array( $pid, $present, true ) ? 'Presente' : ( isset( $given[ $pid ] ) ? 'Delegato' : 'Assente' );
			$rows[] = array( $p['last_name'], $p['first_name'], $p['card_number'] ?? '', $status, $given[ $pid ] ?? '' );
		}
		return Docs::csv( array( array( 'label' => 'Cognome' ), array( 'label' => 'Nome' ), array( 'label' => 'Tessera' ), array( 'label' => 'Presenza' ), array( 'label' => 'Delegato a' ) ), $rows );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Corsi a lezioni: una lezione annullata dall'associazione in un mese già fissato. Per ogni iscritto per cui quella lezione contava nasce una riga
 * (course_credits) che toglie la lezione dal dovuto del mese. Chi aveva già pagato ha la differenza a favore: si scala dal rinnovo dello stesso corso
 * (course_credit_uses), oppure la segreteria la restituisce, oppure segna un recupero concordato (allora la lezione resta dovuta).
 * L'importo a favore vale fino alla fine del primo mese dell'anno fiscale dopo quello della lezione; scaduto, la segreteria lo chiude come contributo per
 * le attività (nessuna scrittura in prima nota: l'incasso c'era già). Per il gruppo il recupero è uno spostamento della lezione (non nasce nulla).
 * Quota dell'istruttore: si riprende dal suo fondo all'annullamento (solo per chi aveva pagato), torna con il recupero, si riattribuisce quando si scala.
 */
final class CourseCredits {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'asem_registration_deleted', array( __CLASS__, 'registration_deleted' ), 5, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymized' ), 5, 1 );
	}

	/** Per un ospite (non socio) l'importo a favore non scade, finché i consulenti non dicono altrimenti. */
	const NEVER = '9999-12-31';

	/** Fino a quando vale l'importo a favore di una riga (con persona): per i soci la fine del primo mese dell'anno fiscale successivo, per gli ospiti mai. */
	public static function expires_for( array $row ): string {
		$p = Plugin::people()->get( (int) $row['person_id'] );
		return $p && ! MemberType::is_member( (string) $p['type'] ) ? self::NEVER : self::expires_on( (string) $row['lesson_date'] );
	}

	/** Fino a quando vale l'importo a favore di una lezione: fine del primo mese dell'anno fiscale successivo. */
	public static function expires_on( string $lesson_date ): string {
		$next = FiscalYears::range( FiscalYears::year_of( $lesson_date ) + 1 );
		return ( new \DateTimeImmutable( (string) $next[0] ) )->format( 'Y-m-t' );
	}

	/** Le righe di una persona in un corso. @return array[] */
	public static function rows( int $activity_id, int $person_id ): array {
		$key = $activity_id . '|' . $person_id;
		if ( ! RequestMemo::has( 'course_credits', $key ) ) {
			$rows = self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d ORDER BY lesson_date, id', $activity_id, $person_id ), ARRAY_A ) ?: array();
			$uses = array();
			if ( $rows ) {
				foreach ( self::db()->get_results( 'SELECT * FROM ' . Db::t( 'course_credit_uses' ) . ' WHERE credit_id IN (' . implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) ) . ')', ARRAY_A ) ?: array() as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
					$uses[ (int) $u['credit_id'] ][] = $u;
				}
			}
			foreach ( $rows as &$r ) {
				$r['uses'] = $uses[ (int) $r['id'] ] ?? array();
				$r['used'] = (int) array_sum( array_column( $r['uses'], 'cents' ) );
			}
			unset( $r );
			RequestMemo::put( 'course_credits', $key, $rows, array( Db::t( 'course_credits' ), Db::t( 'course_credit_uses' ) ) );
		}
		return RequestMemo::get( 'course_credits', $key );
	}

	/**
	 * Applica le righe al dovuto e ai versamenti di un iscritto: la lezione esce dal dovuto del suo mese, ciò che è già stato scalato passa al mese
	 * dove si è usato, ciò che resta a favore (entro la scadenza) copre i mesi dopo ancora da pagare. Nessuna scrittura.
	 *
	 * @param array<string,int> $due  mese => dovuto
	 * @param array<string,int> $paid mese => versato
	 * @return array{0:array,1:array,2:array} dovuto, versato, righe con «avail» (ancora a favore) e «moved» (coperto adesso, non ancora scritto) per mese
	 */
	public static function adjust( int $activity_id, int $person_id, array $due, array $paid ): array {
		$rows = self::rows( $activity_id, $person_id );
		if ( ! $rows ) {
			return array( $due, $paid, array() );
		}
		foreach ( $rows as $r ) {
			$m = (string) $r['month'];
			if ( 'makeup' !== $r['status'] && isset( $due[ $m ] ) ) {
				$due[ $m ] = max( 0, $due[ $m ] - ( (int) $r['cents'] - (int) $r['expired_cents'] ) );
			}
			$paid[ $m ] = (int) ( $paid[ $m ] ?? 0 ) - (int) $r['refunded_cents'];
			foreach ( $r['uses'] as $u ) {
				$paid[ $m ]                   -= (int) $u['cents'];
				$paid[ (string) $u['month'] ] = (int) ( $paid[ (string) $u['month'] ] ?? 0 ) + (int) $u['cents'];
			}
		}
		$months = array_keys( $due );
		sort( $months );
		$who  = Plugin::people()->get( $person_id );
		$guest = $who && ! MemberType::is_member( (string) $who['type'] );
		$left = array(); // mese => quanto del versato in più è ancora disponibile
		$rows = array_reverse( $rows ); // il versato in più nasce dalle lezioni annullate dopo il pagamento: si attribuisce dalle più recenti
		foreach ( $rows as &$r ) {
			$m          = (string) $r['month'];
			$left[ $m ] = $left[ $m ] ?? max( 0, (int) ( $paid[ $m ] ?? 0 ) - (int) ( $due[ $m ] ?? 0 ) );
			$own        = 'makeup' === $r['status'] ? 0 : max( 0, (int) $r['cents'] - (int) $r['expired_cents'] - (int) $r['refunded_cents'] - (int) $r['used'] );
			$r['avail'] = min( $own, $left[ $m ] );
			$left[ $m ] -= $r['avail'];
			$r['moved'] = array();
			$exp = $guest ? self::NEVER : self::expires_on( (string) $r['lesson_date'] );
			if ( $r['avail'] <= 0 || Db::today() > $exp ) {
				continue;
			}
			$limit = substr( $exp, 0, 7 );
			foreach ( $months as $m2 ) {
				$miss = (int) $due[ $m2 ] - (int) ( $paid[ $m2 ] ?? 0 );
				if ( $m2 <= $m || $m2 > $limit || $miss <= 0 || $r['avail'] <= 0 ) {
					continue;
				}
				$x               = min( $miss, $r['avail'] );
				$paid[ $m ]     -= $x;
				$paid[ $m2 ]     = (int) ( $paid[ $m2 ] ?? 0 ) + $x;
				$r['avail']     -= $x;
				$r['moved'][ $m2 ] = $x;
			}
		}
		unset( $r );
		return array( $due, $paid, $rows );
	}

	/**
	 * Scrive ciò che copre un mese (lo scalo al rinnovo): si chiama dentro l'incasso di quel mese, prima del controllo del dovuto. La quota dell'istruttore
	 * ripresa all'annullamento torna, in proporzione, al fondo del corso.
	 */
	public static function persist( array $activity, int $person_id, string $month, array $due, array $paid ): void {
		list( , , $rows ) = self::adjust( (int) $activity['id'], $person_id, $due, $paid );
		foreach ( $rows as $r ) {
			$x = (int) ( $r['moved'][ $month ] ?? 0 );
			if ( $x <= 0 ) {
				continue;
			}
			self::db()->insert( Db::t( 'course_credit_uses' ), array( 'credit_id' => (int) $r['id'], 'month' => $month, 'cents' => $x, 'created_at' => Db::now() ) );
			$back = (int) floor( (int) $r['fund_base'] * $x / max( 1, (int) $r['cents'] ) );
			self::fund_back( $r, min( $back, (int) $r['fund_released'] ), 'Lezione annullata scalata dal rinnovo' );
			Audit::log( 'course.credit_used', 'activity', (int) $activity['id'], array( 'person_id' => $person_id, 'lesson' => $r['lesson_date'], 'month' => $month, 'cents' => $x ) );
		}
		RequestMemo::forget( 'course_credits' );
	}

	/**
	 * Allinea le righe a una lezione dopo che il calendario è cambiato: annullata (e senza presenze) = riga per ogni iscritto; rimessa, spostata o tenuta =
	 * nessuna riga. Solo per i mesi già fissati: prima di allora la lezione esce o entra da sola nel conteggio.
	 */
	/** Un dovuto è appena nato: le lezioni del mese annullate prima si applicano anche a lui (una volta sola, senza rientrare da sync()). */
	public static function catch_up( array $activity, string $month ): void {
		if ( self::$syncing ) {
			return;
		}
		$fixed = CourseMonths::fixed( (int) $activity['id'], $month ) ?? array();
		foreach ( self::db()->get_col( self::db()->prepare( 'SELECT lesson_date FROM ' . Db::t( 'lesson_exceptions' ) . ' WHERE activity_id = %d AND action = %s AND lesson_date BETWEEN %s AND %s', (int) $activity['id'], LessonExceptions::CANCEL, $month . '-01', $month . '-31' ) ) ?: array() as $d ) {
			if ( in_array( (string) $d, $fixed, true ) ) {
				self::sync( (int) $activity['id'], (string) $d );
			}
		}
	}

	/** @var bool sync() in corso: i dovuti che nascono dentro non la richiamano */
	private static $syncing = false;

	public static function sync( int $activity_id, string $date ): void {
		if ( self::$syncing ) {
			return;
		}
		self::$syncing = true;
		try {
			self::sync_now( $activity_id, $date );
		} finally {
			self::$syncing = false;
		}
	}

	private static function sync_now( int $activity_id, string $date ): void {
		$a     = Plugin::activities()->get( $activity_id );
		$month = substr( $date, 0, 7 );
		if ( ! $a || ! CourseMonths::applies( $a ) ) {
			return;
		}
		$fixed = CourseMonths::fixed( $activity_id, $month );
		if ( null === $fixed || ! in_array( $date, $fixed, true ) ) {
			return;
		}
		$action    = (string) self::db()->get_var( self::db()->prepare( 'SELECT action FROM ' . Db::t( 'lesson_exceptions' ) . ' WHERE activity_id = %d AND lesson_date = %s', $activity_id, $date ) );
		$records   = (array) apply_filters( 'asem_lessons_with_records', array(), $activity_id, array( $date ) );
		$cancelled = LessonExceptions::CANCEL === $action && ! in_array( $date, array_map( 'strval', $records ), true );
		if ( ! $cancelled ) {
			foreach ( self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND lesson_date = %s', $activity_id, $date ), ARRAY_A ) ?: array() as $r ) {
				self::fund_back( $r, (int) $r['fund_released'], 'Lezione rimessa in calendario' );
				self::db()->delete( Db::t( 'course_credits' ), array( 'id' => (int) $r['id'] ) );
			}
			RequestMemo::forget( 'course_credits' );
			return;
		}
		foreach ( Plugin::activities()->month_dues( $a, $month ) as $d ) {
			$pid = (int) $d['person_id'];
			if ( '' !== (string) $d['from'] && $date < (string) $d['from'] ) {
				continue; // per questa persona la lezione non contava (iscritta dopo)
			}
			$price = (int) $d['price_cents'];
			if ( $price <= 0 ) {
				continue;
			}
			$ok = self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_credits' ) . ' (activity_id, person_id, month, lesson_date, cents, status, created_at) VALUES (%d, %d, %s, %s, %d, %s, %s)', $activity_id, $pid, $month, $date, $price, 'open', Db::now() ) );
			if ( 1 !== (int) $ok ) {
				continue;
			}
			$id   = (int) self::db()->insert_id;
			$paid = (int) $d['paid'];
			$due  = max( 1, (int) $d['due_cents'] );
			if ( $paid > 0 ) { // solo chi aveva pagato: la quota dell'istruttore accantonata su quel mese, per questa lezione, torna all'associazione
				$share = Plugin::funds()->share_for_course_month( $activity_id, $pid, $month );
				if ( $share['cents'] > 0 ) {
					$base = (int) floor( $share['cents'] * $price / $due );
					self::db()->update( Db::t( 'course_credits' ), array( 'fund_id' => $share['fund_id'], 'fund_base' => $base ), array( 'id' => $id ) );
					self::release( array( 'id' => $id, 'fund_id' => $share['fund_id'], 'fund_released' => 0, 'fund_entries' => '' ), $base, 'Lezione annullata' );
				}
				$p = Plugin::people()->get( $pid );
				if ( $p ) {
					Reminders::notify( $p, 'course_credit', $activity_id . ':' . $date, 'Lezione annullata: ' . $a['name'], 'la lezione di «' . $a['name'] . '» del ' . ( new \DateTimeImmutable( $date ) )->format( 'd/m/Y' ) . ' è annullata. ' . Money::format( $price ) . ' restano a tuo favore e si scalano dal prossimo rinnovo del corso, oppure puoi concordare con la segreteria il recupero della lezione o la restituzione.' . ( MemberType::is_member( (string) $p['type'] ) ? ' L\'importo vale fino al ' . ( new \DateTimeImmutable( self::expires_on( $date ) ) )->format( 'd/m/Y' ) . '.' : '' ) );
				}
			}
		}
		RequestMemo::forget( 'course_credits' );
	}

	/** Prima di cambiare una lezione annullata: se l'importo a favore è già stato scalato non si rimette. @throws \InvalidArgumentException */
	public static function assert_changeable( int $activity_id, string $date ): void {
		$used = (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credit_uses' ) . ' u JOIN ' . Db::t( 'course_credits' ) . ' c ON c.id = u.credit_id WHERE c.activity_id = %d AND c.lesson_date = %s', $activity_id, $date ) );
		if ( $used > 0 ) {
			throw new \InvalidArgumentException( 'L\'importo a favore di questa lezione è già stato scalato da un rinnovo: la lezione resta annullata. Per un recupero, segnalo sulla persona.' );
		}
	}

	/** Recupero concordato con una persona (data di una lezione del corso), o tolto ('' = nessuno). @throws \InvalidArgumentException */
	public static function set_makeup( int $id, int $activity_id, string $date ): void {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE id = %d AND activity_id = %d', $id, $activity_id ), ARRAY_A );
		if ( ! $r ) {
			throw new \InvalidArgumentException( 'Lezione annullata non trovata.' );
		}
		YearLock::assert_date_open( (string) $r['lesson_date'] );
		$used = (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credit_uses' ) . ' WHERE credit_id = %d', $id ) );
		if ( $used || (int) $r['refunded_cents'] || (int) $r['expired_cents'] ) {
			throw new \InvalidArgumentException( 'L\'importo di questa lezione è già stato scalato, restituito o chiuso: il recupero non si segna più.' );
		}
		if ( '' !== $date ) {
			$a = Plugin::activities()->get( $activity_id );
			if ( ! $a || ! in_array( $date, CourseCalendar::dates( $a, $date, $date ), true ) ) {
				throw new \InvalidArgumentException( 'Il recupero va in un giorno in cui il corso ha lezione.' );
			}
			YearLock::assert_date_open( $date );
			self::db()->update( Db::t( 'course_credits' ), array( 'status' => 'makeup', 'makeup_date' => $date ), array( 'id' => $id ) );
			self::fund_back( $r, (int) $r['fund_released'], 'Lezione recuperata' );
		} else {
			self::db()->update( Db::t( 'course_credits' ), array( 'status' => 'open', 'makeup_date' => null ), array( 'id' => $id ) );
			if ( (int) $r['fund_base'] > 0 ) {
				self::release( $r, (int) $r['fund_base'], 'Recupero saltato' );
			}
		}
		RequestMemo::forget( 'course_credits' );
		Audit::log( 'course.credit_makeup', 'activity', $activity_id, array( 'person_id' => (int) $r['person_id'], 'lesson' => $r['lesson_date'], 'makeup' => $date ) );
	}

	/**
	 * Chiude ciò che resta a favore: restituito a mano (refunded) o scaduto e lasciato come contributo per le attività (expired).
	 *
	 * @return int centesimi chiusi
	 * @throws \InvalidArgumentException
	 */
	/**
	 * Restituisce in denaro ciò che è a favore: l'uscita entra in prima nota («Restituzione quote») sul conto e alla data scelti, nella stessa operazione.
	 *
	 * @return int centesimi restituiti
	 * @throws \InvalidArgumentException
	 */
	public static function refund( int $id, int $activity_id, int $account_id, string $method, string $date ): int {
		$x = 0;
		Plugin::ledger()->in_batch(
			function () use ( $id, $activity_id, $account_id, $method, $date, &$x ) {
				$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE id = %d AND activity_id = %d FOR UPDATE', $id, $activity_id ), ARRAY_A );
				if ( ! $r ) {
					throw new \InvalidArgumentException( 'Lezione annullata non trovata.' );
				}
				$a = Plugin::activities()->get( $activity_id );
				$p = Plugin::people()->get( (int) $r['person_id'] );
				$x = 'makeup' === $r['status'] ? 0 : self::available( $r );
				if ( $x <= 0 ) {
					throw new \InvalidArgumentException( 'Non resta niente a favore per questa lezione.' );
				}
				Plugin::ledger()->record_expense(
					array(
						'date' => $date, 'account_id' => $account_id, 'method' => $method, 'category_id' => ActivityReset::refund_category_id(), 'amount_cents' => $x, 'activity_id' => $activity_id, 'person_id' => (int) $r['person_id'],
						'description' => mb_substr( 'Restituzione: lezione annullata del ' . ( new \DateTimeImmutable( (string) $r['lesson_date'] ) )->format( 'd/m/Y' ) . ' di ' . ( $a ? $a['name'] : 'corso' ) . ( $p ? ' (' . trim( $p['first_name'] . ' ' . $p['last_name'] ) . ')' : '' ), 0, 255 ),
					)
				);
				self::close( $id, $activity_id, 'refunded', 'restituito in denaro' );
			}
		);
		return $x;
	}

	public static function close( int $id, int $activity_id, string $how, string $note = '' ): int {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE id = %d AND activity_id = %d', $id, $activity_id ), ARRAY_A );
		if ( ! $r || ! in_array( $how, array( 'refunded', 'expired' ), true ) ) {
			throw new \InvalidArgumentException( 'Lezione annullata non trovata.' );
		}
		$x = 'makeup' === $r['status'] ? 0 : self::available( $r ); // solo ciò che è davvero a favore: lo sconto di chi non aveva pagato resta
		if ( 'expired' === $how && Db::today() <= self::expires_for( $r ) && 'dati della persona cancellati' !== $note ) {
			throw new \InvalidArgumentException( self::NEVER === self::expires_for( $r ) ? 'Per un ospite l\'importo a favore non scade.' : 'L\'importo vale ancora fino al ' . ( new \DateTimeImmutable( self::expires_for( $r ) ) )->format( 'd/m/Y' ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $x <= 0 ) {
			throw new \InvalidArgumentException( 'Non resta niente a favore per questa lezione.' );
		}
		$col = 'refunded' === $how ? 'refunded_cents' : 'expired_cents';
		self::db()->update( Db::t( 'course_credits' ), array( $col => (int) $r[ $col ] + $x, 'closed_at' => Db::now(), 'closed_by' => get_current_user_id() ?: null, 'note' => '' !== $note ? mb_substr( $note, 0, 190 ) : $r['note'] ), array( 'id' => $id ) );
		RequestMemo::forget( 'course_credits' );
		Audit::log( 'course.credit_' . $how, 'activity', $activity_id, array( 'person_id' => (int) $r['person_id'], 'lesson' => $r['lesson_date'], 'cents' => $x ) );
		return $x;
	}

	/** Quanto di una riga è a favore adesso (versato in più, non scalato, non chiuso). */
	public static function available( array $row ): int {
		foreach ( Plugin::activities()->credit_rows( (int) $row['activity_id'], (int) $row['person_id'] ) as $r ) {
			if ( (int) $r['id'] === (int) $row['id'] ) {
				return (int) $r['avail'] + (int) array_sum( $r['moved'] );
			}
		}
		return 0;
	}

	private static function row_used( int $id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COALESCE(SUM(cents),0) FROM ' . Db::t( 'course_credit_uses' ) . ' WHERE credit_id = %d', $id ) );
	}

	/** Le righe di un corso, per la sua scheda. @return array[] */
	public static function of_activity( int $activity_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT c.*, p.first_name, p.last_name FROM ' . Db::t( 'course_credits' ) . ' c JOIN ' . Db::t( 'people' ) . ' p ON p.id = c.person_id WHERE c.activity_id = %d ORDER BY c.lesson_date DESC, p.last_name', $activity_id ), ARRAY_A ) ?: array();
	}

	/** Importi a favore scaduti e non ancora chiusi, per la Bacheca. @return array[] riga + avail */
	public static function expired_open(): array {
		$out = array();
		$y   = FiscalYears::year_of( Db::today() );
		$cut = FiscalYears::range( Db::today() > self::expires_on( FiscalYears::range( $y - 1 )[0] ) ? $y - 1 : $y - 2 )[1]; // le lezioni fino a questa data hanno l'importo già scaduto
		foreach ( self::page( 'c.lesson_date <= %s', array( $cut ) ) as $r ) {
			$left = self::available( $r );
			if ( $left > 0 ) {
				$r['left'] = $left;
				$out[]     = $r;
			}
		}
		return $out;
	}

	/**
	 * Righe aperte dei soci (gli ospiti non scadono) con una condizione sulle date, dalla più vecchia, a pagine: nessuna resta fuori.
	 *
	 * @param array $args valori per i segnaposto
	 * @return array[]
	 */
	private static function page( string $where, array $args ): array {
		$db    = self::db();
		$out   = array();
		$after = 0;
		$types = MemberType::member_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		do {
			$rows = $db->get_results( $db->prepare( 'SELECT c.*, a.name AS activity_name, p.first_name, p.last_name FROM ' . Db::t( 'course_credits' ) . ' c JOIN ' . Db::t( 'activities' ) . ' a ON a.id = c.activity_id JOIN ' . Db::t( 'people' ) . " p ON p.id = c.person_id AND p.type IN ($in) WHERE c.status = 'open' AND c.refunded_cents = 0 AND c.expired_cents = 0 AND c.id > %d AND $where ORDER BY c.id LIMIT 500", array_merge( $types, array( $after ), $args ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- tabelle interne, segnaposto generati
			foreach ( $rows as $r ) {
				$out[] = $r;
				$after = (int) $r['id'];
			}
		} while ( 500 === count( $rows ) );
		usort( $out, function ( $x, $y ) {
			return strcmp( (string) $x['lesson_date'], (string) $y['lesson_date'] );
		} );
		return $out;
	}

	/** Avviso al socio 30 giorni prima della scadenza (uno per lezione). @return int avvisi (o da mandare) */
	public static function expiry_notices( string $today, bool $send ): int {
		$n = 0;
		$y    = FiscalYears::year_of( $today );
		$soon = array();
		foreach ( array( $y - 2, $y - 1, $y ) as $k ) { // gli anni le cui lezioni scadono nei prossimi 30 giorni
			$e = self::expires_on( FiscalYears::range( $k )[0] );
			if ( $e >= $today && $e <= gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) ) ) {
				$soon[] = FiscalYears::range( $k );
			}
		}
		$rows = array();
		foreach ( $soon as $rg ) {
			$rows = array_merge( $rows, self::page( 'c.lesson_date BETWEEN %s AND %s', array( (string) $rg[0], (string) $rg[1] ) ) );
		}
		foreach ( $rows as $r ) {
			$exp = self::expires_on( (string) $r['lesson_date'] );
			if ( $today > $exp || $today < gmdate( 'Y-m-d', strtotime( $exp . ' -30 days' ) ) || self::available( $r ) <= 0 ) {
				continue;
			}
			$p = Plugin::people()->get( (int) $r['person_id'] );
			$a = Plugin::activities()->get( (int) $r['activity_id'] );
			if ( ! $p || ! $a ) {
				continue;
			}
			$ref = (int) $r['id'] . ':scade';
			if ( ! $send ) {
				$n += Reminders::pending( $p, 'course_credit', $ref ) ? 1 : 0;
				continue;
			}
			if ( Reminders::notify( $p, 'course_credit', $ref, 'Importo a favore in scadenza: ' . $a['name'], 'per la lezione di «' . $a['name'] . '» del ' . ( new \DateTimeImmutable( (string) $r['lesson_date'] ) )->format( 'd/m/Y' ) . ' hai ' . Money::format( self::available( $r ) ) . ' a favore fino al ' . ( new \DateTimeImmutable( $exp ) )->format( 'd/m/Y' ) . '. Si scalano dal prossimo rinnovo del corso; se non rinnovi, chiedi alla segreteria la restituzione entro quella data. Dopo, restano all\'associazione come contributo per le attività.' ) ) {
				$n++;
			}
		}
		return $n;
	}

	// ---------- Fondo dell'istruttore ----------

	/** Riprende dal fondo (mai sotto zero) e lo scrive sulla riga. */
	private static function release( array $r, int $cents, string $note ): void {
		if ( $cents <= 0 || empty( $r['fund_id'] ) ) {
			return;
		}
		$entry = 0;
		$got   = Plugin::funds()->take_back( (int) $r['fund_id'], $cents, Db::today(), $note, $entry );
		if ( $got > 0 && $entry > 0 ) {
			self::db()->update( Db::t( 'course_credits' ), array( 'fund_released' => (int) $r['fund_released'] + $got, 'fund_short' => $cents - $got, 'fund_entries' => trim( (string) $r['fund_entries'] . ',' . $entry, ',' ) ), array( 'id' => (int) $r['id'] ) );
		} else {
			self::db()->update( Db::t( 'course_credits' ), array( 'fund_short' => $cents ), array( 'id' => (int) $r['id'] ) ); // quota già liquidata: la differenza resta scritta, la decide la segreteria
		}
	}

	/** Rimette nel fondo ciò che era stato ripreso (al recupero, al rinnovo, alla lezione rimessa). */
	private static function fund_back( array $r, int $cents, string $note ): void {
		if ( $cents <= 0 || empty( $r['fund_id'] ) ) {
			return;
		}
		$fund = Plugin::funds()->instructor_fund_id( (int) $r['activity_id'] ) ?: (int) $r['fund_id'];
		$entry = 0;
		$got   = Plugin::funds()->share_to_fund( $fund, $cents, Db::today(), null, $note, $entry );
		if ( $got > 0 && $entry > 0 ) {
			$row = self::db()->get_row( self::db()->prepare( 'SELECT fund_released, fund_entries FROM ' . Db::t( 'course_credits' ) . ' WHERE id = %d', (int) $r['id'] ), ARRAY_A );
			if ( $row ) {
				self::db()->update( Db::t( 'course_credits' ), array( 'fund_released' => max( 0, (int) $row['fund_released'] - $got ), 'fund_entries' => trim( (string) $row['fund_entries'] . ',' . $entry, ',' ) ), array( 'id' => (int) $r['id'] ) );
			}
		}
	}

	/** Iscrizione cancellata: via le righe e le loro scritture nel fondo (le quote degli incassi si tolgono già con gli incassi). */
	public static function registration_deleted( $activity_id, $person_id ): void {
		$rows = self::db()->get_results( self::db()->prepare( 'SELECT id, fund_entries FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d', (int) $activity_id, (int) $person_id ), ARRAY_A ) ?: array();
		$ids  = array();
		foreach ( $rows as $r ) {
			$ids = array_merge( $ids, array_filter( array_map( 'intval', explode( ',', (string) $r['fund_entries'] ) ) ) );
		}
		if ( $ids ) { // una scrittura nel fondo in un anno fiscale chiuso non si cancella: allora non si cancella nemmeno l'iscrizione
			foreach ( self::db()->get_col( 'SELECT entry_date FROM ' . Db::t( 'fund_entries' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ) ?: array() as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
				YearLock::assert_date_open( (string) $d );
			}
		}
		foreach ( $rows as $r ) {
			foreach ( array_filter( array_map( 'intval', explode( ',', (string) $r['fund_entries'] ) ) ) as $e ) {
				self::db()->delete( Db::t( 'fund_entries' ), array( 'id' => $e ) );
			}
			self::db()->delete( Db::t( 'course_credit_uses' ), array( 'credit_id' => (int) $r['id'] ) );
			self::db()->delete( Db::t( 'course_credits' ), array( 'id' => (int) $r['id'] ) );
		}
		RequestMemo::forget( 'course_credits' );
	}

	/** Persona resa anonima: ciò che restava a favore si chiude con il motivo. */
	public static function anonymized( $person_id ): void {
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . " WHERE person_id = %d AND status = 'open'", (int) $person_id ), ARRAY_A ) ?: array() as $r ) {
			$left = self::available( $r );
			if ( $left > 0 ) {
				self::db()->update( Db::t( 'course_credits' ), array( 'expired_cents' => (int) $r['expired_cents'] + $left, 'closed_at' => Db::now(), 'note' => 'dati della persona cancellati' ), array( 'id' => (int) $r['id'] ) );
			}
		}
		RequestMemo::forget( 'course_credits' );
	}
}

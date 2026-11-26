<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Mensilità di un corso pagata nella moneta interna (AssoCredito o il nome scelto): per un corso con un costo nella moneta il socio, o un genitore
 * per il figlio minore, sceglie euro oppure moneta, per intero; con la moneta il mese risulta pagato. Niente pagamento misto. In prima nota non si
 * scrive nulla (l'entrata è la vendita dei pacchetti); la quota dell'istruttore si accantona sul valore in euro del mese.
 * Una lezione annullata, un'iscrizione cancellata o un corso eliminato restituiscono moneta, mai euro, con la regola degli euro: i mesi con lezioni
 * già tenute restano pagati; eliminando il corso con «Restituisci» torna anche la parte delle lezioni non tenute dei mesi in corso.
 */
final class CourseCoins {

	/** Minuti in cui un pagamento online aperto sullo stesso mese blocca il pagamento in moneta. */
	const CHECKOUT_MINUTES = 30;

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'asem_course_coin_lesson', array( __CLASS__, 'lesson_cancelled' ), 10, 5 );
		add_action( 'asem_registration_deleted', array( __CLASS__, 'registration_deleted' ), 4, 3 );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ), 4, 2 );
		add_filter( 'asem_reset_other_payments', array( __CLASS__, 'reset_lines' ), 10, 5 );
		add_action( 'asem_payment_item_already_paid', array( __CLASS__, 'online_after_coins' ), 10, 2 );
		add_filter( 'asem_pay_section_extra', array( __CLASS__, 'pay_section' ), 10, 2 );
		add_filter( 'asem_course_enrolled_extra', array( __CLASS__, 'enrolled_extra' ), 10, 4 );
	}

	/** La riga di un mese pagato (null se non c'è). */
	public static function row( int $activity_id, int $person_id, string $month ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credit_payments' ) . " WHERE activity_id = %d AND person_id = %d AND month = %s AND status = 'paid'", $activity_id, $person_id, $month ), ARRAY_A );
		return $r ?: null;
	}

	/** Quanta moneta costa quel mese a quella persona (0 = non si paga in moneta): lo stesso conto degli euro, in proporzione, intero al più vicino. */
	public static function price( array $activity, array $person, string $month ): int {
		$cp = (int) ( $activity['credit_price'] ?? 0 );
		if ( $cp <= 0 || ActivityKind::COURSE !== (string) $activity['kind'] ) {
			return 0;
		}
		$acts = Plugin::activities();
		$enr  = $acts->enrollment_row( (int) $activity['id'], (int) $person['id'] );
		$due  = $acts->course_month_fee( $activity, $person, $month );
		$base = $enr ? $acts->price_of( $activity, $enr, (string) $person['type'] ) : $acts->fee_for( $activity, (string) $person['type'] );
		if ( $due <= 0 || $base <= 0 ) {
			return 0;
		}
		return max( 1, (int) round( $cp * $due / $base ) ); // a lezioni il contributo è di una lezione: costo per lezione × lezioni; a mese in proporzione
	}

	/**
	 * Paga in moneta un mese di corso, per intero, dal saldo di chi paga. Saldo e mensilità nella stessa operazione: mai saldo negativo, mai due volte.
	 *
	 * @return int moneta spesa
	 * @throws \InvalidArgumentException
	 */
	public static function pay( int $activity_id, int $person_id, string $month, int $payer_id ): int {
		$a     = Plugin::activities()->get( $activity_id );
		$p     = Plugin::people()->get( $person_id );
		$payer = Plugin::people()->get( $payer_id );
		if ( ! $a || ! $p || ! $payer || ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			throw new \InvalidArgumentException( 'Mese del corso non trovato.' );
		}
		if ( $payer_id !== $person_id && ! self::minor_child_of( $payer_id, $p ) ) {
			throw new \InvalidArgumentException( 'Si paga in ' . CoinName::plural() . ' per sé o per un figlio minorenne (con la data di nascita in anagrafica) di cui si risulta genitore.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! MemberType::is_member( (string) $payer['type'] ) || ! Plugin::people()->is_active_member( $payer_id ) ) {
			throw new \InvalidArgumentException( CoinName::plural() . ': solo per i soci con la tessera valida.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		YearLock::assert_date_open( $month . '-01' );
		if ( self::checkout_open( $activity_id, $person_id, $month ) ) {
			throw new \InvalidArgumentException( 'Per questo mese c\'è un pagamento online appena aperto: finiscilo o aspetta ' . self::CHECKOUT_MINUTES . ' minuti prima di pagare in ' . CoinName::plural() . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$spent = 0;
		Plugin::ledger()->in_batch(
			function () use ( $a, $p, $payer_id, $month, &$spent ) {
				$db  = self::db();
				$aid = (int) $a['id'];
				$pid = (int) $p['id'];
				$db->get_var( $db->prepare( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE id = %d FOR UPDATE', $aid ) ); // come gli incassi: prima il corso, poi la persona
				$acts = Plugin::activities();
				$due  = $acts->course_month_fee( $a, $p, $month );
				$left = $acts->enrollment_left( $aid, $pid, $month );
				if ( null === $left || $due <= 0 ) {
					throw new \InvalidArgumentException( 'Per questo mese non c\'è nulla da pagare.' );
				}
				if ( $left < $due ) {
					throw new \InvalidArgumentException( 'Questo mese è già pagato in parte in euro: si completa in euro, non in ' . CoinName::plural() . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$cost = self::price( $a, $p, $month );
				if ( $cost <= 0 ) {
					throw new \InvalidArgumentException( 'Questo corso non si paga in ' . CoinName::plural() . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$old = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::t( 'course_credit_payments' ) . ' WHERE activity_id = %d AND person_id = %d AND month = %s FOR UPDATE', $aid, $pid, $month ), ARRAY_A );
				if ( $old && 'paid' === $old['status'] ) {
					throw new \InvalidArgumentException( 'Questo mese è già pagato in ' . CoinName::plural() . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$entry = Credits::spend( $payer_id, $cost, $a['name'] . ' — ' . $month, 'course_month', $aid ); // saldo controllato sotto blocco: mai sotto zero
				$row   = array( 'activity_id' => $aid, 'person_id' => $pid, 'payer_id' => $payer_id, 'month' => $month, 'credits' => $cost, 'cents' => $due, 'status' => 'paid', 'entry_id' => $entry, 'fund_entry' => 0, 'fund_cents' => 0, 'created_at' => Db::now(), 'created_by' => get_current_user_id() ?: null );
				if ( $old ) {
					$db->update( Db::t( 'course_credit_payments' ), $row, array( 'id' => (int) $old['id'] ) );
					$rid = (int) $old['id'];
				} else {
					$db->insert( Db::t( 'course_credit_payments' ), $row );
					$rid = (int) $db->insert_id;
				}
				$fe = 0;
				$fc = Plugin::funds()->attribute_credits( $a, $due, Db::today(), 'Mensilità di «' . $a['name'] . '» pagata in ' . CoinName::plural(), $fe );
				$db->update( Db::t( 'course_credit_payments' ), array( 'fund_entry' => $fe, 'fund_cents' => $fc ), array( 'id' => $rid ) );
				RequestMemo::forget();
				$spent = $cost;
				Audit::log( 'course.paid_in_coins', 'activity', $aid, array( 'person_id' => $pid, 'payer_id' => $payer_id, 'month' => $month, 'coins' => $cost, 'cents' => $due ) );
			}
		);
		if ( $spent > 0 ) {
			$who = $payer_id !== $person_id ? ' per ' . trim( $p['first_name'] . ' ' . $p['last_name'] ) : '';
			self::tell( $payer_id, 'p:' . $activity_id . ':' . $person_id . ':' . $month . ':' . time(), 'Mese pagato in ' . CoinName::plural() . ': ' . $a['name'], 'il mese ' . self::month_text( $month ) . ' di «' . $a['name'] . '»' . $who . ' è pagato con ' . CoinName::n( $spent ) . '. Ti restano ' . CoinName::n( Credits::balance( $payer_id ) ) . '.' );
		}
		return $spent;
	}

	/** Figlio minorenne oggi (data di nascita obbligatoria, qualunque sia l'impostazione del tutore) e collegato al genitore in anagrafica. */
	private static function minor_child_of( int $payer_id, array $child ): bool {
		return Age::is_minor( (string) ( $child['birth_date'] ?? '' ), Db::today() ) && Guardians::is_guardian_of( $payer_id, (int) $child['id'] );
	}

	/** Avviso a chi ha speso la moneta (il genitore, se ha pagato per il figlio). */
	private static function tell( int $payer_id, string $ref, string $subject, string $body ): void {
		$who = Plugin::people()->get( $payer_id );
		if ( $who ) {
			Reminders::notify( $who, 'course_coin', substr( $ref, 0, 60 ), $subject, $body );
		}
	}

	private static function month_text( string $month ): string {
		return substr( $month, 5, 2 ) . '/' . substr( $month, 0, 4 );
	}

	/** C'è un pagamento online aperto da poco con questo mese? */
	public static function checkout_open( int $activity_id, int $person_id, string $month ): bool {
		$key = 'm:' . $activity_id . ':' . $person_id . ':' . $month;
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND created_at >= %s AND items LIKE %s", gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - self::CHECKOUT_MINUTES * MINUTE_IN_SECONDS ), '%' . self::db()->esc_like( '"' . $key . '"' ) . '%' ) ) > 0;
	}

	/** Lezione annullata in un mese pagato in moneta: torna la moneta di quella lezione, e la quota dell'istruttore in proporzione. */
	public static function lesson_cancelled( $activity_id, $person_id, $month, $price, $date = '' ): void {
		$r = self::row( (int) $activity_id, (int) $person_id, (string) $month );
		if ( ! $r || (int) $r['cents'] <= 0 ) {
			return;
		}
		$price = min( (int) $price, (int) $r['cents'] );
		$back  = min( (int) $r['credits'], (int) round( (int) $r['credits'] * $price / (int) $r['cents'] ) );
		$fund  = (int) floor( (int) $r['fund_cents'] * $price / (int) $r['cents'] );
		self::db()->update( Db::t( 'course_credit_payments' ), array( 'credits' => (int) $r['credits'] - $back, 'cents' => (int) $r['cents'] - $price, 'fund_cents' => (int) $r['fund_cents'] - $fund ), array( 'id' => (int) $r['id'] ) );
		if ( $back > 0 ) {
			Credits::refund( (int) $r['payer_id'], $back, 'Lezione annullata del corso (' . $r['month'] . ')', 'course_month', (int) $r['activity_id'] );
			$a = Plugin::activities()->get( (int) $r['activity_id'] );
			if ( $a ) {
				$day = '' !== (string) $date ? ' del ' . ( new \DateTimeImmutable( (string) $date ) )->format( 'd/m/Y' ) : '';
				self::tell( (int) $r['payer_id'], 'l:' . (int) $r['activity_id'] . ':' . (int) $r['person_id'] . ':' . ( '' !== (string) $date ? (string) $date : $r['month'] . ':' . time() ), 'Lezione annullata: ' . $a['name'], 'la lezione di «' . $a['name'] . '»' . $day . ' è annullata. Il mese era pagato in ' . CoinName::plural() . ': ti tornano ' . CoinName::n( $back ) . '.' );
			}
		}
		if ( $fund > 0 && (int) $r['fund_entry'] ) {
			$fid = (int) self::db()->get_var( self::db()->prepare( 'SELECT fund_id FROM ' . Db::t( 'fund_entries' ) . ' WHERE id = %d', (int) $r['fund_entry'] ) );
			Plugin::funds()->take_back( $fid, $fund, Db::today(), 'Lezione annullata (mese pagato in ' . CoinName::plural() . ')' );
		}
		RequestMemo::forget();
	}

	/**
	 * Iscrizione cancellata: come per gli euro, i mesi con almeno una lezione già tenuta restano pagati; la moneta degli altri torna a chi l'ha spesa
	 * e la quota dell'istruttore di quei mesi esce dal fondo. La scelta sulle somme (restituire o annullare) non cambia l'esito per la moneta.
	 */
	public static function registration_deleted( $activity_id, $person_id, $mode = ActivityReset::REFUND ): void {
		self::settle( self::paid_rows( (int) $activity_id, (int) $person_id ), false, (string) $mode, 'Iscrizione cancellata' );
	}

	/** Corso eliminato: mesi non cominciati, tutta la moneta; mesi in corso, con «Restituisci» la parte delle lezioni non tenute, con «Annulla» niente. */
	public static function activity_deleted( $activity_id, $mode = ActivityReset::REFUND ): void {
		self::settle( self::paid_rows( (int) $activity_id, 0 ), true, (string) $mode, 'Corso eliminato' );
		self::db()->delete( Db::t( 'course_credit_payments' ), array( 'activity_id' => (int) $activity_id ) ); // il corso non c'è più: le righe non restano appese
	}

	/** Mesi pagati in moneta di un corso (di una persona, se $person_id). @return array[] */
	private static function paid_rows( int $activity_id, int $person_id ): array {
		$sql  = 'SELECT * FROM ' . Db::t( 'course_credit_payments' ) . " WHERE activity_id = %d AND status = 'paid'";
		$args = array( $activity_id );
		if ( $person_id ) {
			$sql   .= ' AND person_id = %d';
			$args[] = $person_id;
		}
		return self::db()->get_results( self::db()->prepare( $sql . ' ORDER BY month', $args ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- segnaposto generati qui
	}

	/**
	 * Quanto torna di ogni mese, con la regola degli incassi in euro ({@see ActivityReset::is_fruited()} e {@see ActivityReset::refund_cents()}).
	 *
	 * @return array[] row, back_cents (valore in euro che torna), back (moneta che torna), fund (quota del fondo da togliere), full
	 */
	private static function plan( array $rows, bool $whole, string $mode ): array {
		if ( ! $rows ) {
			return array();
		}
		$a   = Plugin::activities()->get( (int) $rows[0]['activity_id'] );
		$ctx = $a ? ActivityReset::fruit_context( $a ) : null;
		$out = array();
		foreach ( $rows as $r ) {
			$cents = (int) $r['cents'];
			$m     = array( 'amount_cents' => $cents, 'competence_month' => (string) $r['month'], 'session_id' => 0, 'person_id' => (int) $r['person_id'], 'description' => '' );
			if ( null === $ctx || $cents <= 0 ) {
				$bc = $cents;
			} elseif ( ! ActivityReset::is_fruited( $m, $ctx ) ) {
				$bc = $cents; // mese non ancora cominciato: torna tutto
			} else {
				$bc = $whole && ActivityReset::REFUND === $mode ? min( $cents, ActivityReset::refund_cents( $m, $ctx ) ) : 0;
			}
			$full  = $cents <= 0 || $bc >= $cents;
			$out[] = array(
				'row'        => $r,
				'back_cents' => $bc,
				'back'       => $full ? (int) $r['credits'] : (int) round( (int) $r['credits'] * $bc / $cents ),
				'fund'       => $full ? (int) $r['fund_cents'] : (int) floor( (int) $r['fund_cents'] * $bc / max( 1, $cents ) ),
				'full'       => $full,
			);
		}
		return $out;
	}

	/** @throws \InvalidArgumentException se una quota del fondo da togliere è in un anno fiscale chiuso */
	private static function settle( array $rows, bool $whole, string $mode, string $why ): void {
		$plan = array_filter(
			self::plan( $rows, $whole, $mode ),
			function ( $x ) {
				return $x['back_cents'] > 0 || $x['back'] > 0;
			}
		);
		foreach ( $plan as $x ) {
			if ( ! $whole && $x['fund'] > 0 && (int) $x['row']['fund_entry'] ) {
				$d = (string) self::db()->get_var( self::db()->prepare( 'SELECT entry_date FROM ' . Db::t( 'fund_entries' ) . ' WHERE id = %d', (int) $x['row']['fund_entry'] ) );
				if ( '' !== $d ) {
					YearLock::assert_date_open( $d );
				}
			}
		}
		foreach ( $plan as $x ) {
			$r = $x['row'];
			if ( $x['back'] > 0 ) {
				Credits::refund( (int) $r['payer_id'], $x['back'], $why . ' (' . $r['month'] . ')', 'course_month', (int) $r['activity_id'] );
				$a = Plugin::activities()->get( (int) $r['activity_id'] );
				self::tell( (int) $r['payer_id'], 'r:' . (int) $r['id'] . ':' . time(), $why . ': ' . ( $a ? $a['name'] : CoinName::plural() ), ( $a ? 'per «' . $a['name'] . '», mese ' . self::month_text( (string) $r['month'] ) . ', ' : 'per il mese ' . self::month_text( (string) $r['month'] ) . ' ' ) . 'ti tornano ' . CoinName::n( $x['back'] ) . ' (' . mb_strtolower( $why ) . ').' );
			}
			if ( ! $whole && $x['fund'] > 0 && (int) $r['fund_entry'] ) { // corso eliminato: le quote del fondo del corso cadono tutte con l'eliminazione
				if ( $x['full'] ) {
					self::db()->delete( Db::t( 'fund_entries' ), array( 'id' => (int) $r['fund_entry'] ) );
				} else {
					$fid = (int) self::db()->get_var( self::db()->prepare( 'SELECT fund_id FROM ' . Db::t( 'fund_entries' ) . ' WHERE id = %d', (int) $r['fund_entry'] ) );
					Plugin::funds()->take_back( $fid, $x['fund'], Db::today(), $why . ' (mese pagato in ' . CoinName::plural() . ')' );
				}
			}
			$upd = $x['full'] ? array( 'status' => 'refunded', 'refunded_at' => Db::now() ) : array( 'credits' => (int) $r['credits'] - $x['back'], 'cents' => (int) $r['cents'] - $x['back_cents'], 'fund_cents' => (int) $r['fund_cents'] - $x['fund'] );
			self::db()->update( Db::t( 'course_credit_payments' ), $upd, array( 'id' => (int) $r['id'] ) );
		}
		RequestMemo::forget();
	}

	/** Le frasi per le pagine di eliminazione (iscrizione o corso): cosa succede ai mesi pagati in moneta. @return string[] */
	public static function reset_lines( $lines, $activity_id, $person_id, $session_id, $mode ): array {
		$lines = (array) $lines;
		if ( (int) $session_id ) {
			return $lines;
		}
		$rows = self::paid_rows( (int) $activity_id, (int) $person_id );
		if ( ! $rows ) {
			return $lines;
		}
		$n    = count( $rows );
		$tot  = (int) array_sum( array_column( $rows, 'credits' ) );
		$head = ( 1 === $n ? '1 mese pagato' : $n . ' mesi pagati' ) . ' in ' . CoinName::plural() . ' (' . CoinName::n( $tot ) . ').';
		$back = function ( string $m, bool $whole ) use ( $rows ): int {
			return (int) array_sum( array_column( self::plan( $rows, $whole, $m ), 'back' ) );
		};
		if ( (int) $person_id ) {
			$b       = $back( ActivityReset::REFUND, false );
			$lines[] = $head . ' I mesi con lezioni già tenute restano pagati; ' . ( $b > 0 ? 'per gli altri tornano ' . CoinName::n( $b ) . ' a chi li ha spesi, con qualunque scelta sulle somme.' : 'non torna niente.' );
			return $lines;
		}
		$r = $back( ActivityReset::REFUND, true );
		$v = $back( ActivityReset::VOID, true );
		if ( ActivityReset::REFUND === $mode ) {
			$lines[] = $head . ' Con «Restituisci» tornano ' . CoinName::n( $r ) . ' a chi li ha spesi: i mesi non cominciati e le lezioni non tenute dei mesi in corso.';
		} elseif ( ActivityReset::VOID === $mode ) {
			$lines[] = $head . ' Con «Annulla» tornano ' . CoinName::n( $v ) . ' a chi li ha spesi: solo i mesi non cominciati; i mesi in corso restano pagati.';
		} else {
			$lines[] = $head . ' Con «Restituisci» tornano ' . CoinName::n( $r ) . ' (mesi non cominciati e lezioni non tenute dei mesi in corso); con «Annulla» ' . CoinName::n( $v ) . ' (solo i mesi non cominciati).';
		}
		return $lines;
	}

	/** Pagamento online arrivato per un mese già pagato in moneta: la segreteria lo trova «da controllare»; qui si avvisa chi ha pagato online. */
	public static function online_after_coins( $payment, $items ): void {
		try {
			foreach ( (array) $items as $i ) {
				if ( PaymentItems::COURSE_MONTH !== (string) ( $i['type'] ?? '' ) ) {
					continue;
				}
				$r = self::row( (int) $i['activity_id'], (int) $i['person_id'], (string) $i['month'] );
				$a = $r ? Plugin::activities()->get( (int) $r['activity_id'] ) : null;
				if ( ! $r || ! $a ) {
					continue;
				}
				$to = (int) ( $payment['payer_person_id'] ?? 0 );
				self::tell( $to ? $to : (int) $r['payer_id'], 'o:' . (int) ( $payment['id'] ?? 0 ) . ':' . (int) $r['id'], 'Mese già pagato: ' . $a['name'], 'il mese ' . self::month_text( (string) $r['month'] ) . ' di «' . $a['name'] . '» era già pagato in ' . CoinName::plural() . ', ma è arrivato anche il pagamento online di ' . Money::format( (int) ( $i['amount_cents'] ?? 0 ) ) . '. Il mese resta pagato una volta sola: la segreteria controlla il pagamento e ti contatta per restituire gli euro.' );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // l'avviso non deve mai fermare la registrazione dell'incasso: il pagamento è già «da controllare»
		}
	}

	// ---------- Area soci e pannello ----------

	/** I mesi pagabili in moneta per il socio e i suoi figli minori. @return array[] activity, person, month, cost, due */
	public static function payable( array $actor ): array {
		$out = array();
		if ( ! MemberType::is_member( (string) $actor['type'] ) ) {
			return $out;
		}
		$people = array( $actor );
		foreach ( Guardians::dependents_of( (int) $actor['id'] ) as $kid ) {
			if ( self::minor_child_of( (int) $actor['id'], $kid ) ) {
				$people[] = $kid; // solo i figli minorenni: da maggiorenni pagano da sé
			}
		}
		foreach ( $people as $p ) {
			foreach ( Plugin::activities()->status_for_person( (int) $p['id'] ) as $s ) {
				$a = Plugin::activities()->get( (int) $s['enrollment']['activity_id'] );
				if ( ! $a || (int) ( $a['credit_price'] ?? 0 ) <= 0 ) {
					continue;
				}
				foreach ( $s['summary']['unpaid_months'] as $m ) {
					$due = Plugin::activities()->course_month_fee( $a, $p, (string) $m['month'] );
					if ( (int) $m['paid'] > 0 || (int) $m['missing'] < $due ) {
						continue; // già pagato in parte: si completa in euro
					}
					$out[] = array( 'activity' => $a, 'person' => $p, 'month' => (string) $m['month'], 'cost' => self::price( $a, $p, (string) $m['month'] ), 'due' => $due );
				}
			}
		}
		return $out;
	}

	/** Nella sezione Pagamenti dell'area soci: un pulsante per ogni mese pagabile in moneta. */
	public static function pay_section( $html, $p ): string {
		if ( ! Edition::has( 'credits' ) || ! is_array( $p ) ) {
			return (string) $html;
		}
		$rows = array_filter( self::payable( $p ), function ( $x ) {
			return $x['cost'] > 0;
		} );
		if ( ! $rows ) {
			return (string) $html;
		}
		$bal = Credits::balance( (int) $p['id'] );
		$out = '<div class="asemf-coins"><h4>' . esc_html( 'Oppure paga in ' . CoinName::plural() ) . '</h4><p class="asemf-small asemf-muted">' . esc_html( 'Saldo: ' . CoinName::n( $bal ) . '. Si paga il mese per intero, o in euro o in ' . CoinName::plural() . '.' ) . '</p><ul class="asemf-list">';
		foreach ( $rows as $x ) {
			$ok   = $bal >= $x['cost'];
			$out .= '<li><div><strong>' . esc_html( $x['activity']['name'] . ' — ' . $x['month'] ) . '</strong><div class="asemf-small">' . esc_html( trim( $x['person']['first_name'] . ' ' . $x['person']['last_name'] ) . ' · ' . Money::format( (int) $x['due'] ) ) . '</div></div>'
				. ( $ok ? Frontend\Views::form( 'asem_front_course_coins', Frontend\Views::hidden( 'activity_id', (int) $x['activity']['id'] ) . Frontend\Views::hidden( 'person_id', (int) $x['person']['id'] ) . Frontend\Views::hidden( 'month', $x['month'] ), 'Paga ' . CoinName::n( (int) $x['cost'] ), false, 'asemf-inline' ) : '<span class="asemf-small asemf-muted">' . esc_html( 'Servono ' . CoinName::n( (int) $x['cost'] ) . ', saldo ' . $bal ) . '</span>' ) . '</li>';
		}
		return (string) $html . $out . '</ul></div>';
	}


	/** Nella scheda del corso, per chi ha il permesso Incassi: pagare il mese in moneta dal saldo del socio. */
	public static function enrolled_extra( $html, $activity, $enrollment, $summary ): string {
		if ( ! Edition::has( 'credits' ) || (int) ( $activity['credit_price'] ?? 0 ) <= 0 || ! Employees::can( EmployeeRules::COLLECT ) || empty( $summary['unpaid_months'] ) ) {
			return (string) $html;
		}
		$p = Plugin::people()->get( (int) $enrollment['person_id'] );
		if ( ! $p || ! MemberType::is_member( (string) $p['type'] ) ) {
			return (string) $html;
		}
		$m    = (string) $summary['unpaid_months'][0]['month'];
		$cost = self::price( $activity, $p, $m );
		if ( $cost <= 0 || (int) $summary['unpaid_months'][0]['paid'] > 0 ) {
			return (string) $html;
		}
		return (string) $html . ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="asem-inline">' . wp_nonce_field( 'asem_course_coins', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="asem_course_coins"><input type="hidden" name="activity_id" value="' . (int) $activity['id'] . '"><input type="hidden" name="person_id" value="' . (int) $p['id'] . '"><input type="hidden" name="month" value="' . esc_attr( $m ) . '">'
			. '<button class="button button-small">' . esc_html( 'Paga ' . $m . ' con ' . CoinName::n( $cost ) . ' (saldo ' . Credits::balance( (int) $p['id'] ) . ')' ) . '</button></form>';
	}
}

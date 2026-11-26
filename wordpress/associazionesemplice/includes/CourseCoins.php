<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Mensilità di un corso pagata nella moneta interna (AssoCredito o il nome scelto): per un corso con un costo nella moneta il socio, o un genitore
 * per il figlio minore, sceglie euro oppure moneta, per intero; con la moneta il mese risulta pagato. Niente pagamento misto. In prima nota non si
 * scrive nulla (l'entrata è la vendita dei pacchetti); la quota dell'istruttore si accantona sul valore in euro del mese.
 * Una lezione annullata, un'iscrizione cancellata o un corso eliminato restituiscono moneta, mai euro.
 */
final class CourseCoins {

	/** Minuti in cui un pagamento online aperto sullo stesso mese blocca il pagamento in moneta. */
	const CHECKOUT_MINUTES = 30;

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'asem_course_coin_lesson', array( __CLASS__, 'lesson_cancelled' ), 10, 4 );
		add_action( 'asem_registration_deleted', array( __CLASS__, 'registration_deleted' ), 4, 2 );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ), 4, 1 );
		add_filter( 'asem_pay_section_extra', array( __CLASS__, 'pay_section' ), 10, 2 );
		add_filter( 'asem_course_enrolled_extra', array( __CLASS__, 'enrolled_extra' ), 10, 4 );
		Frontend\Actions::add_handler( 'asem_front_course_coins', array( __CLASS__, 'do_front_pay' ) );
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
		if ( $payer_id !== $person_id && ! Guardians::is_guardian_of( $payer_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'Si paga in ' . CoinName::plural() . ' per sé o per un figlio minore di cui si risulta genitore.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
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
		return $spent;
	}

	/** C'è un pagamento online aperto da poco con questo mese? */
	public static function checkout_open( int $activity_id, int $person_id, string $month ): bool {
		$key = 'm:' . $activity_id . ':' . $person_id . ':' . $month;
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND created_at >= %s AND items LIKE %s", gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - self::CHECKOUT_MINUTES * MINUTE_IN_SECONDS ), '%' . self::db()->esc_like( '"' . $key . '"' ) . '%' ) ) > 0;
	}

	/** Lezione annullata in un mese pagato in moneta: torna la moneta di quella lezione, e la quota dell'istruttore in proporzione. */
	public static function lesson_cancelled( $activity_id, $person_id, $month, $price ): void {
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
		}
		if ( $fund > 0 && (int) $r['fund_entry'] ) {
			$fid = (int) self::db()->get_var( self::db()->prepare( 'SELECT fund_id FROM ' . Db::t( 'fund_entries' ) . ' WHERE id = %d', (int) $r['fund_entry'] ) );
			Plugin::funds()->take_back( $fid, $fund, Db::today(), 'Lezione annullata (mese pagato in ' . CoinName::plural() . ')' );
		}
		RequestMemo::forget();
	}

	/** Iscrizione cancellata: la moneta dei mesi pagati torna a chi l'ha spesa, la quota dell'istruttore esce dal fondo. */
	public static function registration_deleted( $activity_id, $person_id ): void {
		self::give_back( self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credit_payments' ) . " WHERE activity_id = %d AND person_id = %d AND status = 'paid'", (int) $activity_id, (int) $person_id ), ARRAY_A ) ?: array(), 'Iscrizione cancellata' );
	}

	public static function activity_deleted( $activity_id ): void {
		self::give_back( self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'course_credit_payments' ) . " WHERE activity_id = %d AND status = 'paid'", (int) $activity_id ), ARRAY_A ) ?: array(), 'Corso eliminato' );
	}

	/** @throws \InvalidArgumentException se una quota del fondo è in un anno fiscale chiuso */
	private static function give_back( array $rows, string $why ): void {
		foreach ( $rows as $r ) {
			if ( (int) $r['fund_entry'] ) {
				$d = (string) self::db()->get_var( self::db()->prepare( 'SELECT entry_date FROM ' . Db::t( 'fund_entries' ) . ' WHERE id = %d', (int) $r['fund_entry'] ) );
				if ( '' !== $d ) {
					YearLock::assert_date_open( $d );
				}
			}
		}
		foreach ( $rows as $r ) {
			if ( (int) $r['credits'] > 0 ) {
				Credits::refund( (int) $r['payer_id'], (int) $r['credits'], $why . ' (' . $r['month'] . ')', 'course_month', (int) $r['activity_id'] );
			}
			if ( (int) $r['fund_entry'] ) {
				self::db()->delete( Db::t( 'fund_entries' ), array( 'id' => (int) $r['fund_entry'] ) );
			}
			self::db()->update( Db::t( 'course_credit_payments' ), array( 'status' => 'refunded', 'refunded_at' => Db::now() ), array( 'id' => (int) $r['id'] ) );
		}
		RequestMemo::forget();
	}

	// ---------- Area soci e pannello ----------

	/** I mesi pagabili in moneta per il socio e i suoi figli minori. @return array[] activity, person, month, cost, due */
	public static function payable( array $actor ): array {
		$out = array();
		if ( ! MemberType::is_member( (string) $actor['type'] ) ) {
			return $out;
		}
		$people = array_merge( array( $actor ), Guardians::dependents_of( (int) $actor['id'] ) );
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

	public static function do_front_pay( array $post ): string {
		$actor = Frontend\Actions::actor();
		$n     = self::pay( (int) ( $post['activity_id'] ?? 0 ), (int) ( $post['person_id'] ?? 0 ), (string) ( $post['month'] ?? '' ), (int) $actor['id'] );
		return 'Pagato con ' . CoinName::n( $n ) . '.';
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

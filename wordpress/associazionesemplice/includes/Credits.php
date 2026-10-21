<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Crediti: il socio compra pacchetti di crediti (online o in segreteria) e li spende nelle risorse e nelle attività per cui è previsto il
 * pagamento in crediti, anche dal vivo con un QR (addebito dello staff confermato dal socio). Il saldo è sempre la somma dei movimenti.
 * La vendita di un pacchetto è l'entrata in prima nota; la spesa non registra niente in prima nota. Specifica: docs/CREDITI.md.
 */
final class Credits {

	const PACKS_OPTION = 'asem_credit_packs';

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Pacchetti ----------

	/** @return array[] credits, price_cents (per crediti crescenti) */
	public static function packs(): array {
		return CreditRules::clean_packs( get_option( self::PACKS_OPTION, '' ) );
	}

	/** @throws \InvalidArgumentException */
	public static function save_packs( string $text ): void {
		$packs = CreditRules::parse_packs( $text );
		update_option( self::PACKS_OPTION, wp_json_encode( $packs ), false );
		Audit::log( 'credits.packs', 'settings', null, array( 'count' => count( $packs ) ) );
	}

	private static function pack( int $credits ): ?array {
		foreach ( self::packs() as $p ) {
			if ( (int) $p['credits'] === $credits ) {
				return $p;
			}
		}
		return null;
	}

	// ---------- Saldo e movimenti ----------

	public static function balance( int $person_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COALESCE(SUM(delta), 0) FROM ' . Db::t( 'credit_entries' ) . ' WHERE person_id = %d', $person_id ) );
	}

	/** Movimenti di un socio, dal più recente. */
	public static function history( int $person_id, int $limit = 30 ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'credit_entries' ) . ' WHERE person_id = %d ORDER BY id DESC LIMIT %d', $person_id, max( 1, $limit ) ), ARRAY_A ) ?: array();
	}

	/** Ultimi movimenti di tutti i soci, per l'amministrazione. */
	public static function recent( int $limit = 50 ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT e.*, p.first_name, p.last_name FROM ' . Db::t( 'credit_entries' ) . ' e JOIN ' . Db::t( 'people' ) . ' p ON p.id = e.person_id ORDER BY e.id DESC LIMIT %d', max( 1, $limit ) ),
			ARRAY_A
		) ?: array();
	}

	/** Totale dei crediti in circolazione (la somma dei saldi). */
	public static function outstanding(): int {
		return (int) self::db()->get_var( 'SELECT COALESCE(SUM(delta), 0) FROM ' . Db::t( 'credit_entries' ) );
	}

	/** @throws \InvalidArgumentException */
	private static function assert_member( int $person_id ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person || ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'I crediti sono riservati ai soci.' );
		}
		return $person;
	}

	private static function insert_entry( int $person_id, int $delta, string $kind, string $label, ?string $ref_type, ?int $ref_id, ?int $by ): int {
		$ok = self::db()->insert(
			Db::t( 'credit_entries' ),
			array(
				'person_id'  => $person_id,
				'delta'      => $delta,
				'kind'       => $kind,
				'label'      => mb_substr( $label, 0, 190 ),
				'ref_type'   => $ref_type,
				'ref_id'     => $ref_id,
				'created_by' => $by ?: ( get_current_user_id() ?: null ),
				'created_at' => Db::now(),
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Operazione sui crediti non riuscita: riprova.' );
		}
		return (int) self::db()->insert_id;
	}

	/**
	 * Spende crediti. Il controllo del saldo e la scrittura avvengono sotto blocco per socio: due richieste insieme non portano il saldo sotto zero.
	 *
	 * @return int numero del movimento
	 * @throws \InvalidArgumentException
	 */
	public static function spend( int $person_id, int $credits, string $label, ?string $ref_type = null, ?int $ref_id = null ): int {
		if ( $credits < 1 || $credits > CreditRules::MAX_CREDITS ) {
			throw new \InvalidArgumentException( 'Numero di crediti non valido.' );
		}
		$person = self::assert_member( $person_id );
		$id     = Db::with_lock(
			'asem_credits_' . $person_id,
			function () use ( $person_id, $credits, $label, $ref_type, $ref_id ) {
				$have = self::balance( $person_id );
				if ( $have < $credits ) {
					throw new \InvalidArgumentException( 'Crediti insufficienti: ne hai ' . $have . ', ne servono ' . $credits . '.' );
				}
				return self::insert_entry( $person_id, -$credits, 'spend', $label, $ref_type, $ref_id, null );
			}
		);
		Audit::log( 'credits.spent', 'person', (int) $person['id'], array( 'credits' => $credits, 'ref' => $ref_type, 'ref_id' => $ref_id ) );
		return (int) $id;
	}

	/** Riaccredita crediti già spesi (annullamento di una prenotazione pagata in crediti). */
	public static function refund( int $person_id, int $credits, string $label, ?string $ref_type = null, ?int $ref_id = null ): int {
		if ( $credits < 1 ) {
			throw new \InvalidArgumentException( 'Numero di crediti non valido.' );
		}
		$id = Db::with_lock(
			'asem_credits_' . $person_id,
			function () use ( $person_id, $credits, $label, $ref_type, $ref_id ) {
				return self::insert_entry( $person_id, $credits, 'refund', $label, $ref_type, $ref_id, null );
			}
		);
		Audit::log( 'credits.refunded', 'person', $person_id, array( 'credits' => $credits, 'ref' => $ref_type, 'ref_id' => $ref_id ) );
		return (int) $id;
	}

	/** Correzione a mano della segreteria (anche negativa, ma mai sotto zero). @throws \InvalidArgumentException */
	public static function adjust( int $person_id, int $delta, string $note ): int {
		if ( 0 === $delta || abs( $delta ) > CreditRules::MAX_CREDITS ) {
			throw new \InvalidArgumentException( 'Indica quanti crediti aggiungere o togliere.' );
		}
		if ( '' === trim( $note ) ) {
			throw new \InvalidArgumentException( 'Scrivi il motivo della rettifica.' );
		}
		self::assert_member( $person_id );
		$id = Db::with_lock(
			'asem_credits_' . $person_id,
			function () use ( $person_id, $delta, $note ) {
				if ( $delta < 0 && self::balance( $person_id ) + $delta < 0 ) {
					throw new \InvalidArgumentException( 'Il saldo non può scendere sotto zero.' );
				}
				return self::insert_entry( $person_id, $delta, 'adjust', trim( $note ), null, null, null );
			}
		);
		Audit::log( 'credits.adjusted', 'person', $person_id, array( 'delta' => $delta ) );
		return (int) $id;
	}

	// ---------- Acquisto ----------

	/**
	 * Vendita di un pacchetto in segreteria (contanti, POS…): entra in prima nota come «Altra entrata» e il socio riceve i crediti.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function sell_at_desk( int $person_id, int $credits, int $account_id, string $method ): void {
		$pack = self::pack( $credits );
		if ( ! $pack ) {
			throw new \InvalidArgumentException( 'Scegli uno dei pacchetti in vendita.' );
		}
		self::assert_member( $person_id );
		$cat = Plugin::ledger()->category_id_of_kind( 'other_income' );
		if ( ! $cat ) {
			throw new \InvalidArgumentException( 'Manca la voce «Altra entrata» in prima nota.' );
		}
		Plugin::ledger()->record_receipt(
			array(
				'date'       => Db::today(),
				'account_id' => $account_id,
				'method'     => $method,
				'person_id'  => $person_id,
				'lines'      => array( array( 'category_id' => $cat, 'amount_cents' => (int) $pack['price_cents'], 'description' => 'Acquisto crediti: ' . $credits ) ),
			)
		);
		self::insert_entry( $person_id, $credits, 'buy', 'Acquisto di ' . $credits . ' crediti in segreteria', 'desk', null, null );
		Audit::log( 'credits.sold', 'person', $person_id, array( 'credits' => $credits, 'cents' => (int) $pack['price_cents'] ) );
	}

	/** I pacchetti acquistabili online dal socio, come voci per {@see PaymentItems} (non sono dovuti: si scelgono). @return array[] chiave => voce */
	public static function pack_items( array $actor ): array {
		$out = array();
		if ( ! MemberType::is_member( $actor['type'] ) ) {
			return $out;
		}
		foreach ( self::packs() as $p ) {
			$i                = array(
				'type' => PaymentItems::CREDITS, 'person_id' => (int) $actor['id'], 'person_name' => trim( $actor['first_name'] . ' ' . $actor['last_name'] ),
				'credits' => (int) $p['credits'], 'amount_cents' => (int) $p['price_cents'], 'label' => $p['credits'] . ' crediti',
			);
			$i['key']         = PaymentItems::key( $i );
			$out[ $i['key'] ] = $i;
		}
		return $out;
	}

	/** Pagamento online di un pacchetto riuscito: i crediti arrivano al socio. */
	public static function granted_online( int $person_id, int $credits, int $payment_id ): void {
		self::insert_entry( $person_id, $credits, 'buy', 'Acquisto online di ' . $credits . ' crediti', 'payment', $payment_id, null );
		Audit::log( 'credits.bought', 'person', $person_id, array( 'credits' => $credits, 'payment' => $payment_id ) );
	}

	// ---------- Addebito dal vivo con il QR ----------

	/** Chi può addebitare crediti dal vivo: segreteria, tesoriere e lo staff degli eventi con il permesso di incasso. */
	public static function can_charge( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		if ( user_can( $user_id, Plugin::CAP_OPS ) || Access::is_treasurer( $user_id ) ) {
			return true;
		}
		$person = Access::person_for_user( $user_id );
		return $person && (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'activity_staff' ) . ' WHERE person_id = %d AND can_cash = 1', (int) $person['id'] ) ) > 0;
	}

	/** Attività per cui è previsto il pagamento in crediti. @return array[] id, name, credit_price */
	public static function chargeable_activities(): array {
		return self::db()->get_results( 'SELECT id, name, credit_price FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL AND credit_price IS NOT NULL AND credit_price > 0 ORDER BY name', ARRAY_A ) ?: array();
	}

	/** I crediti previsti per partecipare a un'attività (0 o vuoto = non si paga in crediti). */
	public static function set_activity_price( int $activity_id, int $credits ): void {
		$credits = max( 0, min( CreditRules::MAX_CREDITS, $credits ) );
		self::db()->update( Db::t( 'activities' ), array( 'credit_price' => $credits > 0 ? $credits : null ), array( 'id' => $activity_id ) );
		Audit::log( 'activity.credit_price', 'activity', $activity_id, array( 'credits' => $credits ) );
	}

	public static function url( string $code ): string {
		return add_query_arg( 'asem_credit', $code, home_url( '/' ) );
	}

	/**
	 * Crea un addebito dal vivo valido {@see CreditRules::CHARGE_MINUTES} minuti. Senza l'importo si usano i crediti previsti dall'attività.
	 *
	 * @return array{id:int,code:string}
	 * @throws \InvalidArgumentException
	 */
	public static function create_charge( int $staff_person_id, int $credits, string $label, ?int $activity_id ): array {
		$activity = null;
		if ( $activity_id ) {
			$activity = Plugin::activities()->get( $activity_id );
			if ( ! $activity || empty( $activity['credit_price'] ) ) {
				throw new \InvalidArgumentException( 'Questa attività non prevede il pagamento in crediti.' );
			}
			if ( $credits < 1 ) {
				$credits = (int) $activity['credit_price'];
			}
			if ( '' === trim( $label ) ) {
				$label = (string) $activity['name'];
			}
		}
		if ( $credits < 1 || $credits > CreditRules::MAX_CREDITS ) {
			throw new \InvalidArgumentException( 'Indica quanti crediti addebitare.' );
		}
		$label = trim( $label );
		if ( '' === $label ) {
			throw new \InvalidArgumentException( 'Scrivi per cosa è l\'addebito.' );
		}
		$code = bin2hex( random_bytes( 12 ) );
		$now  = Db::now();
		$ok   = self::db()->insert(
			Db::t( 'credit_charges' ),
			array(
				'code'            => $code,
				'credits'         => $credits,
				'label'           => mb_substr( $label, 0, 190 ),
				'activity_id'     => $activity_id ?: null,
				'staff_person_id' => $staff_person_id,
				'status'          => CreditRules::CHARGE_PENDING,
				'created_at'      => $now,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . CreditRules::CHARGE_MINUTES . ' minutes' ) ),
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Addebito non riuscito: riprova.' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'credits.charge_created', 'person', $staff_person_id, array( 'charge' => $id, 'credits' => $credits ) );
		return array( 'id' => $id, 'code' => $code );
	}

	public static function charge( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'credit_charges' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function charge_by_code( string $code ): ?array {
		if ( ! CreditRules::is_code( $code ) ) {
			return null;
		}
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'credit_charges' ) . ' WHERE code = %s', $code ), ARRAY_A );
		return $row ?: null;
	}

	/** Valore in euro (centesimi) di una spesa in crediti, dal pacchetto più piccolo: serve per calcolare le quote dei fondi quando l'attività non ha un prezzo in euro. */
	private static function euro_value( int $credits ): int {
		foreach ( self::packs() as $p ) {
			return (int) round( $credits * (int) $p['price_cents'] / max( 1, (int) $p['credits'] ) );
		}
		return 0;
	}

	/** Attività pagata in crediti: la quota prevista per il fondo del volontario (se c'è) viene accantonata, come per un pagamento in denaro. */
	private static function share_activity_to_fund( array $charge, array $person ): void {
		if ( empty( $charge['activity_id'] ) || ! Edition::has( 'funds' ) ) {
			return;
		}
		$a = Plugin::activities()->get( (int) $charge['activity_id'] );
		if ( ! $a ) {
			return;
		}
		$base = (int) Plugin::activities()->fee_for( $a, (string) $person['type'] );
		if ( $base <= 0 ) {
			$base = self::euro_value( (int) $charge['credits'] );
		}
		Plugin::funds()->attribute_credits( $a, $base, Db::today(), 'Quota di «' . $a['name'] . '» pagata in crediti' );
	}

	/**
	 * Il socio conferma e paga un addebito. L'addebito si incassa una sola volta (anche con due conferme insieme) e i crediti si scalano
	 * solo se bastano; se non bastano l'addebito resta pagabile.
	 *
	 * @return array l'addebito pagato
	 * @throws \InvalidArgumentException
	 */
	public static function pay_charge( string $code, int $person_id ): array {
		$c = self::charge_by_code( $code );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'QR non valido.' );
		}
		$state = CreditRules::charge_state( $c, Db::now() );
		if ( 'ok' !== $state ) {
			throw new \InvalidArgumentException( CreditRules::charge_message( $state ) );
		}
		$person = self::assert_member( $person_id );
		if ( ! Plugin::people()->is_active_member( $person_id ) ) {
			throw new \InvalidArgumentException( 'La tua tessera non è valida: rinnovala per spendere i crediti.' );
		}
		$tbl = Db::t( 'credit_charges' );
		Db::with_lock(
			'asem_credits_' . $person_id,
			function () use ( $c, $person_id, $tbl ) {
				$have = self::balance( $person_id );
				if ( $have < (int) $c['credits'] ) {
					throw new \InvalidArgumentException( 'Crediti insufficienti: ne hai ' . $have . ', ne servono ' . (int) $c['credits'] . '.' );
				}
				$claimed = self::db()->query( self::db()->prepare( "UPDATE $tbl SET status = 'paid', payer_person_id = %d, paid_at = %s WHERE id = %d AND status = 'pending' AND expires_at >= %s", $person_id, Db::now(), (int) $c['id'], Db::now() ) );
				if ( 1 !== (int) $claimed ) {
					throw new \InvalidArgumentException( 'Questo addebito non è più disponibile.' );
				}
				$entry = self::insert_entry( $person_id, -(int) $c['credits'], 'spend', (string) $c['label'], 'charge', (int) $c['id'], (int) get_current_user_id() );
				self::db()->update( $tbl, array( 'entry_id' => $entry ), array( 'id' => (int) $c['id'] ) );
			}
		);
		self::share_activity_to_fund( $c, $person );
		Audit::log( 'credits.charge_paid', 'person', (int) $person['id'], array( 'charge' => (int) $c['id'], 'credits' => (int) $c['credits'] ) );
		return self::charge( (int) $c['id'] );
	}

	/** Lo staff annulla un addebito non ancora pagato. @throws \InvalidArgumentException */
	public static function cancel_charge( int $id, int $user_id ): void {
		$c = self::charge( $id );
		if ( ! $c || ! self::owns_charge( $c, $user_id ) ) {
			throw new \InvalidArgumentException( 'Addebito non trovato.' );
		}
		$n = (int) self::db()->query( self::db()->prepare( 'UPDATE ' . Db::t( 'credit_charges' ) . " SET status = 'cancelled' WHERE id = %d AND status = 'pending'", $id ) );
		if ( 1 !== $n ) {
			throw new \InvalidArgumentException( 'L\'addebito è già stato pagato o annullato.' );
		}
		Audit::log( 'credits.charge_cancelled', 'person', (int) $c['staff_person_id'], array( 'charge' => $id ) );
	}

	/** Un addebito lo vede e lo gestisce chi l'ha creato e la segreteria. */
	public static function owns_charge( array $c, int $user_id ): bool {
		$person = Access::person_for_user( $user_id );
		return ( $person && (int) $person['id'] === (int) $c['staff_person_id'] ) || user_can( $user_id, Plugin::CAP_OPS );
	}

	/** Stato di un addebito per lo schermo dello staff (si aggiorna da solo). @return array state, label, payer */
	public static function charge_status( int $id, int $user_id ): array {
		$c = self::charge( $id );
		if ( ! $c || ! self::owns_charge( $c, $user_id ) ) {
			return array( 'state' => 'missing', 'message' => 'Addebito non trovato.', 'payer' => '' );
		}
		$state = 'paid' === $c['status'] ? 'paid' : CreditRules::charge_state( $c, Db::now() );
		$payer = '';
		if ( 'paid' === $state && $c['payer_person_id'] ) {
			$p     = Plugin::people()->get( (int) $c['payer_person_id'] );
			$payer = $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '';
		}
		return array( 'state' => $state, 'message' => 'ok' === $state ? 'In attesa del socio…' : ( 'paid' === $state ? 'Incassato' . ( '' !== $payer ? ' da ' . $payer : '' ) : CreditRules::charge_message( $state ) ), 'payer' => $payer );
	}
}

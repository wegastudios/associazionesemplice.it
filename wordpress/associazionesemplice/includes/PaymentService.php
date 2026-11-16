<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Pagamenti online su pagina ospitata dal gateway: Stripe Checkout oppure PayPal.
 *
 * Flusso: il socio sceglie le voci da pagare nell'area soci → il server RICALCOLA importi e voci → crea il pagamento sul gateway
 * e manda il socio alla pagina del gateway → al ritorno (e con il webhook di Stripe, e con un controllo periodico) il pagamento
 * viene confermato direttamente dal gateway → l'incasso entra in prima nota (quota associativa, mensilità, prenotazioni) sul
 * conto "Stripe" o "PayPal". La conferma è idempotente: lo stesso pagamento non si registra due volte.
 *
 * I dati della carta non passano mai dal sito.
 */
class PaymentService extends OfflinePayments {

	/** @var PaymentService|null */
	private static $engine = null;

	/**
	 * Il motore dei pagamenti online per chiudere quelli già avviati (webhook, ritorno dal gateway, controllo periodico), anche se la licenza è
	 * scaduta: i soldi in arrivo vanno registrati comunque. I nuovi pagamenti passano da {@see Plugin::payments()}.
	 */
	public static function engine(): PaymentService {
		return self::$engine ?? ( self::$engine = new PaymentService() );
	}

	const PENDING_AFTER_MINUTES = 10;
	const EXPIRE_AFTER_DAYS     = 3;
	const STUCK_AFTER_MINUTES   = 15;

	/** Stati da cui un pagamento si può ancora registrare: il gateway può confermare i soldi anche dopo che il sito lo aveva chiuso (annullo, scadenza). */
	const OPEN_STATUSES = array( 'created', 'pending', 'cancelled', 'expired', 'failed' );

	/** @var callable|null rete iniettabile per i test */
	private $http = null;

	public function set_http( ?callable $http ): void {
		$this->http = $http;
	}

	private function http(): callable {
		return $this->http ?: array( Gateways::class, 'wp_http' );
	}


	// ---------- Configurazione ----------

	/**
	 * Gateway utilizzabili ora: stripe e/o paypal (anche insieme), oppure woocommerce. Un gateway con la configurazione incompleta non compare
	 * (l'altro, se a posto, funziona lo stesso).
	 *
	 * @return string[]
	 */
	public function providers(): array {
		$c = ProSettings::payment_config();
		if ( PaymentConfig::WOOCOMMERCE === $c['payment_provider'] ) {
			return WooBridge::active() ? array( PaymentConfig::WOOCOMMERCE ) : array();
		}
		$out = array();
		foreach ( PaymentConfig::gateways_of( (string) $c['payment_provider'] ) as $g ) {
			if ( array() === PaymentConfig::validate_gateway( $c, $g )['errors'] ) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/** Il primo gateway utilizzabile (o '' se non ce n'è): per i controlli che non dipendono dalla scelta. */
	public function provider(): string {
		return $this->providers()[0] ?? '';
	}

	public function enabled(): bool {
		return array() !== $this->providers() && Edition::has( 'payments' );
	}


	public function get_by_public( string $public_id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE public_id = %s', $public_id ), ARRAY_A );
		return $row ?: null;
	}

	private function get( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	private function update( int $id, array $data ): void {
		$data['updated_at'] = Db::now();
		$this->db()->update( Db::t( 'payments' ), $data, array( 'id' => $id ) );
	}

	/** Pagamenti, dal più recente. @param array $f status, payer_person_id, review */
	public function list( array $f = array(), int $limit = 100 ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'p.status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['payer_person_id'] ) ) {
			$where[] = 'p.payer_person_id = %d';
			$args[]  = (int) $f['payer_person_id'];
		}
		if ( ! empty( $f['review'] ) ) {
			$where[] = 'p.review = 1';
		}
		$sql    = 'SELECT p.*, CONCAT(pe.first_name, " ", pe.last_name) AS payer_name FROM ' . Db::t( 'payments' ) . ' p LEFT JOIN ' . Db::t( 'people' ) . ' pe ON pe.id = p.payer_person_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.id DESC LIMIT %d';
		$args[] = max( 1, $limit );
		return $this->db()->get_results( $this->db()->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function mark_reviewed( int $id ): void {
		$this->update( $id, array( 'review' => 0 ) );
		$p = $this->get( $id );
		if ( $p && self::refund_pending_stale( $p ) ) { // chi ha controllato il pannello del gestore: un tentativo non confermato di oltre 23 ore fa non blocca più
			$this->update( $id, array( 'refund_pending_cents' => 0 ) );
		}
		Audit::log( 'payment.reviewed', 'payment', $id );
	}

	// ---------- Creazione del pagamento ----------

	/**
	 * Crea il pagamento sul gateway e restituisce l'indirizzo della pagina di pagamento a cui mandare il socio.
	 *
	 * @param array    $actor     persona collegata all'utente che paga (socio)
	 * @param string[] $keys      chiavi delle voci scelte (si accettano solo quelle realmente dovute da lui o dai suoi ospiti)
	 * @param string   $back_url  pagina del sito a cui tornare dopo il pagamento
	 */
	public function create_checkout( array $actor, int $user_id, array $keys, string $back_url, string $provider = '' ): string {
		$available = $this->providers();
		if ( ! $available || ! Edition::has( 'payments' ) ) {
			throw new \InvalidArgumentException( 'I pagamenti online non sono attivi.' );
		}
		if ( '' === $provider ) {
			$provider = $available[0];
		} elseif ( ! in_array( $provider, $available, true ) ) {
			throw new \InvalidArgumentException( 'Questo metodo di pagamento non è disponibile.' );
		}
		// Un utente (o un programma automatico) non può creare centinaia di pagamenti pendenti sul gateway.
		$tbl  = Db::t( 'payments' );
		$open = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE payer_user_id = %d AND created_at >= %s", $user_id, gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - HOUR_IN_SECONDS ) ) );
		if ( $open >= Limits::get( 'pay_open_per_hour' ) ) {
			throw new \InvalidArgumentException( 'Hai avviato molti pagamenti in poco tempo: aspetta un po\' prima di riprovare.' );
		}
		$dues  = $this->dues_for( $actor );
		if ( Edition::has( 'credits' ) ) { // i pacchetti di crediti non sono dovuti: si scelgono e si comprano
			$dues = array_merge( $dues, Credits::pack_items( $actor ) );
		}
		$items = array();
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $k ) {
			if ( isset( $dues[ $k ] ) ) {
				$items[] = $dues[ $k ];
			}
		}
		if ( ! $items ) {
			throw new \InvalidArgumentException( 'Scegli almeno una voce da pagare.' );
		}
		$total = PaymentItems::total( $items );
		$min = Limits::get( 'pay_min_cents' );
		if ( $total < $min ) {
			throw new \InvalidArgumentException( 'L\'importo minimo per pagare online è ' . Money::format( $min ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}

		$public = wp_generate_uuid4();
		$now    = Db::now();
		if ( PaymentConfig::WOOCOMMERCE === $provider ) { // negozio WooCommerce: le voci vanno nel carrello e si paga al checkout
			$url = WooBridge::checkout_url( $items, $public );
			$this->db()->insert(
				Db::t( 'payments' ),
				array(
					'public_id' => $public, 'provider' => $provider, 'status' => 'pending', 'amount_cents' => $total, 'currency' => 'EUR',
					'payer_person_id' => (int) $actor['id'], 'payer_user_id' => $user_id, 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now,
				)
			);
			$wid = (int) $this->db()->insert_id;
			Audit::log( 'payment.created', 'payment', $wid, array( 'provider' => $provider, 'cents' => $total, 'items' => count( $items ) ) );
			return $url;
		}
		$items = $this->with_fee( $items, $provider, (int) $actor['id'], trim( (string) $actor['first_name'] . ' ' . (string) $actor['last_name'] ) ); // commissione a carico di chi paga, se il metodo la ribalta
		$total = PaymentItems::total( $items );
		$this->db()->insert(
			Db::t( 'payments' ),
			array(
				'public_id' => $public, 'provider' => $provider, 'status' => 'created', 'amount_cents' => $total, 'currency' => 'EUR',
				'payer_person_id' => (int) $actor['id'], 'payer_user_id' => $user_id, 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now,
			)
		);
		$id        = (int) $this->db()->insert_id;
		$success   = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'ok' ), $back_url );
		$cancel    = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'cancel' ), $back_url );
		$cfg       = ProSettings::payment_config();
		$brand     = (string) Settings::get( 'association_name' );
		try {
			$res = $this->gateway_session( $provider, $items, $public, (string) $actor['email'], $success, $cancel, $cfg, $brand );
		} catch ( \RuntimeException $e ) {
			$this->update( $id, array( 'status' => 'failed', 'error' => mb_substr( $e->getMessage(), 0, 250 ) ) );
			Audit::log( 'payment.failed', 'payment', $id, array( 'provider' => $provider ) );
			throw new \InvalidArgumentException( 'Il servizio di pagamento non risponde in questo momento. Riprova più tardi.' );
		}
		$this->update( $id, array( 'status' => 'pending', 'provider_ref' => $res['id'] ) );
		Audit::log( 'payment.created', 'payment', $id, array( 'provider' => $provider, 'cents' => $total, 'items' => count( $items ) ) );
		return $res['url'];
	}

	/** Crea la pagina di pagamento sul gateway scelto. @return array ['id' => riferimento, 'url' => pagina di pagamento] @throws \RuntimeException */
	private function gateway_session( string $provider, array $items, string $public, string $email, string $success, string $cancel, array $cfg, string $brand ): array {
		if ( PaymentConfig::STRIPE === $provider ) {
			$klarna = KlarnaRules::check( ! empty( $cfg['stripe_klarna'] ), PaymentItems::total( $items ), $email )['ok']; // Klarna solo se l'interruttore è acceso e l'acquisto ne rispetta i requisiti
			$params = StripeApi::checkout_params( $items, $public, $email, $success, $cancel, $klarna );
			return StripeApi::create_session( $this->http(), $cfg['stripe_secret_key'], $params, $public );
		}
		if ( PaymentConfig::SUMUP === $provider ) {
			return SumUpApi::create_checkout( $this->http(), $cfg['sumup_api_key'], SumUpApi::checkout_payload( $items, $public, $cfg['sumup_merchant_code'], $success, $brand ) );
		}
		if ( SatispayRules::ID === $provider ) {
			$callback = SatispayRules::callback_url( rest_url( 'associazionesemplice/v1/webhooks/satispay' ) );
			return SatispayApi::create_payment( $this->http(), $cfg, SatispayRules::payment_payload( $items, $public, $callback, $success, $brand ) );
		}
		$live  = 'live' === $cfg['paypal_mode'];
		$token = PayPalApi::access_token( $this->http(), $live, $cfg['paypal_client_id'], $cfg['paypal_client_secret'] );
		return PayPalApi::create_order( $this->http(), $live, $token, PayPalApi::order_payload( $items, $public, $success, $cancel, $brand ), $public );
	}

	/**
	 * Aggiunge la voce «commissione di incasso» se il metodo è impostato per ribaltarla su chi paga (spento di default: va usato solo dove la
	 * legge lo consente). Il supplemento è calcolato perché, tolta la commissione dal totale addebitato, resti l'importo dovuto.
	 */
	private function with_fee( array $items, string $provider, int $person_id, string $person_name ): array {
		if ( ! in_array( $provider, PaymentConfig::fee_gateways(), true ) ) {
			return $items;
		}
		list( $fixed, $bp, $pass ) = PaymentConfig::fee_of( Settings::all(), $provider );
		if ( ! $pass || ( 0 === $fixed && 0 === $bp ) ) {
			return $items;
		}
		$base = PaymentItems::total( $items );
		$fee  = PaymentConfig::gross_up( $base, $fixed, $bp ) - $base;
		if ( $fee > 0 ) {
			$items[] = array( 'type' => PaymentItems::FEE, 'person_id' => $person_id, 'person_name' => $person_name, 'amount_cents' => $fee, 'label' => 'Commissione di incasso' );
		}
		return $items;
	}

	/**
	 * Registra in prima nota, come spesa, la commissione di incasso stimata dalle impostazioni (fisso + percentuale sul totale addebitato) quando
	 * l'amministratore lo ha scelto. Se non riesce non succede nulla: l'incasso è già registrato. @return int centesimi registrati
	 */
	private function book_fee( array $p, int $charged_cents, string $account_method, int $account_id, string $ref ): int {
		if ( ! Settings::get( 'pay_fee_book' ) || ! in_array( $p['provider'], PaymentConfig::fee_gateways(), true ) ) {
			return 0;
		}
		list( $fixed, $bp ) = PaymentConfig::fee_of( Settings::all(), $p['provider'] );
		$fee = PaymentConfig::fee_cents( $charged_cents, $fixed, $bp );
		if ( $fee <= 0 ) {
			return 0;
		}
		try {
			$ledger = Plugin::ledger();
			$ledger->record_expense(
				array(
					'date'         => Db::today(),
					'account_id'   => $account_id,
					'method'       => $account_method,
					'category_id'  => $ledger->category_id_of_kind( 'general_cost' ),
					'amount_cents' => $fee,
					'description'  => 'Commissione di incasso (stima) — pagamento online n. ' . (int) $p['id'],
					'document_ref' => $ref,
				)
			);
			Audit::log( 'payment.fee_booked', 'payment', (int) $p['id'], array( 'cents' => $fee ) );
			return $fee;
		} catch ( \Throwable $e ) {
			Audit::log( 'payment.fee_failed', 'payment', (int) $p['id'], array( 'error' => mb_substr( $e->getMessage(), 0, 120 ) ) );
			return 0;
		}
	}

	// ---------- Quota della domanda di adesione ----------

	/** Chi chiede di iscriversi non ha un accesso: può pagare solo con Stripe o PayPal (il negozio WooCommerce richiede un utente). */
	public function application_online(): bool {
		return Edition::has( 'payments' ) && array() !== array_diff( $this->providers(), array( PaymentConfig::WOOCOMMERCE ) );
	}

	/**
	 * Pagamento online della quota di una domanda di adesione. Il pagamento nasce senza utente collegato; il ritorno dal gateway e l'incasso in prima nota
	 * (e l'iscrizione) avvengono solo se la domanda viene approvata: se è rifiutata il pagamento resta «da rimborsare».
	 */
	public function application_checkout( array $person, int $cents, string $back_url, string $provider = '' ): string {
		$available = array_values( array_diff( $this->providers(), array( PaymentConfig::WOOCOMMERCE ) ) );
		if ( ! $available || ! Edition::has( 'payments' ) ) {
			throw new \InvalidArgumentException( 'Il pagamento online non è disponibile: la quota si paga in sede.' );
		}
		if ( '' === $provider ) {
			$provider = $available[0];
		} elseif ( ! in_array( $provider, $available, true ) ) {
			throw new \InvalidArgumentException( 'Questo metodo di pagamento non è disponibile.' );
		}
		$min = Limits::get( 'pay_min_cents' );
		if ( $cents < $min ) {
			throw new \InvalidArgumentException( 'L\'importo minimo per pagare online è ' . Money::format( $min ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$tbl  = Db::t( 'payments' );
		$open = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE payer_person_id = %d AND created_at >= %s", (int) $person['id'], gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - HOUR_IN_SECONDS ) ) );
		if ( $open >= 5 ) {
			throw new \InvalidArgumentException( 'Hai avviato molti pagamenti in poco tempo: aspetta un po\' prima di riprovare.' );
		}
		$year = Settings::membership_year()->label();
		$item = array(
			'type' => PaymentItems::APPLICATION, 'person_id' => (int) $person['id'], 'person_name' => trim( $person['first_name'] . ' ' . $person['last_name'] ),
			'social_year' => $year, 'amount_cents' => $cents, 'label' => 'Quota associativa ' . $year . ' (domanda di adesione)',
		);
		$item['key'] = PaymentItems::key( $item );
		$items       = array( $item );
		$public      = wp_generate_uuid4();
		$now         = Db::now();
		$this->db()->insert(
			$tbl,
			array(
				'public_id' => $public, 'provider' => $provider, 'status' => 'created', 'amount_cents' => $cents, 'currency' => 'EUR',
				'payer_person_id' => (int) $person['id'], 'payer_user_id' => 0, 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now,
			)
		);
		$id      = (int) $this->db()->insert_id;
		$success = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'ok' ), $back_url );
		$cancel  = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'cancel' ), $back_url );
		$cfg     = ProSettings::payment_config();
		$brand   = (string) Settings::get( 'association_name' );
		$email   = (string) ( $person['email'] ?? '' );
		if ( '' === $email ) {
			$g     = Applications::guardian_data( $person );
			$email = $g ? (string) $g['email'] : '';
		}
		try {
			$res = $this->gateway_session( $provider, $items, $public, $email, $success, $cancel, $cfg, $brand );
		} catch ( \RuntimeException $e ) {
			$this->update( $id, array( 'status' => 'failed', 'error' => mb_substr( $e->getMessage(), 0, 250 ) ) );
			Audit::log( 'payment.failed', 'payment', $id, array( 'provider' => $provider ) );
			throw new \InvalidArgumentException( 'Il servizio di pagamento non risponde in questo momento. Riprova più tardi.' );
		}
		$this->update( $id, array( 'status' => 'pending', 'provider_ref' => $res['id'] ) );
		Audit::log( 'payment.created', 'payment', $id, array( 'provider' => $provider, 'cents' => $cents, 'items' => 1, 'application' => 1 ) );
		return $res['url'];
	}

	/** Pagamenti della domanda già incassati dal gateway e non ancora registrati in prima nota né restituiti. @return array[] */
	private function application_payments( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE payer_person_id = %d AND status = 'paid' AND allocated_cents = 0 AND refund_due = 0 AND refunded_cents = 0 ORDER BY id", $person_id ), // un pagamento già restituito (anche in parte) non entra più in prima nota
			ARRAY_A
		) ?: array();
		return array_values( array_filter( $rows, function ( $r ) {
			$items = (array) json_decode( (string) $r['items'], true );
			return isset( $items[0]['type'] ) && PaymentItems::APPLICATION === $items[0]['type'];
		} ) );
	}

	public function application_paid( int $person_id ): ?array {
		$rows = $this->application_payments( $person_id );
		return $rows ? array( 'cents' => (int) $rows[0]['amount_cents'], 'id' => (int) $rows[0]['id'] ) : null;
	}

	/**
	 * Prenota la quota di un pagamento per la prima nota: la riga passa da «non registrata» a «registrata» in un solo passo, quindi due approvazioni
	 * in contemporanea non registrano l'incasso due volte. @return bool true se questa chiamata ha preso la quota
	 */
	public function application_claim( int $id ): bool {
		return 1 === (int) $this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'payments' ) . ' SET allocated_cents = amount_cents, updated_at = %s WHERE id = %d AND allocated_cents = 0', Db::now(), $id ) );
	}

	/** Domanda approvata: la quota pagata online entra in prima nota (e con lei l'iscrizione all'anno). */
	public function application_settle( int $person_id, string $social_year ): bool {
		$done   = false;
		$ledger = Plugin::ledger();
		foreach ( $this->application_payments( $person_id ) as $p ) {
			if ( ! $this->application_claim( (int) $p['id'] ) ) {
				continue; // un'altra approvazione, in contemporanea, ha già preso questa quota
			}
			try {
				$ledger->record_receipt(
					array(
						'date' => Db::today(), 'account_id' => $ledger->online_account( $p['provider'] ), 'method' => $p['provider'], 'person_id' => $person_id,
						'document_ref' => substr( (string) $p['provider_ref'], 0, 80 ),
						'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => (int) $p['amount_cents'], 'social_year' => $social_year, 'description' => 'Pagamento online della quota con la domanda di adesione' ) ),
					)
				);
				$done = true;
			} catch ( \InvalidArgumentException $e ) { // nulla è stato scritto: la quota torna da registrare e il pagamento da controllare
				$this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'payments' ) . ' SET allocated_cents = 0 WHERE id = %d AND allocated_cents = amount_cents', (int) $p['id'] ) );
				$this->update( (int) $p['id'], array( 'review' => 1, 'error' => mb_substr( 'Quota della domanda non registrata: ' . $e->getMessage(), 0, 250 ) ) );
			} catch ( \Throwable $e ) { // non si sa quanto sia stato scritto: la quota resta presa (nessun secondo incasso) e il pagamento da controllare
				$this->update( (int) $p['id'], array( 'review' => 1, 'error' => mb_substr( 'Registrazione della quota incompleta: ' . $e->getMessage(), 0, 250 ) ) );
				throw $e;
			}
		}
		return $done;
	}

	/** Domanda rifiutata: i pagamenti già incassati vanno restituiti (il rimborso si fa dal pannello del gateway). @return int pagamenti segnati */
	public function application_refund_due( int $person_id ): int {
		$n = 0;
		foreach ( $this->application_payments( $person_id ) as $p ) {
			$this->update( (int) $p['id'], array( 'refund_due' => 1 ) );
			Audit::log( 'payment.refund_due', 'payment', (int) $p['id'], array( 'reason' => 'application_rejected' ) );
			$n++;
		}
		return $n;
	}

	/** Il rimborso è stato fatto dal gateway. */
	public function mark_refunded( int $id ): void {
		$this->update( $id, array( 'refund_due' => 0 ) );
		Audit::log( 'payment.refunded', 'payment', $id );
	}

	/**
	 * Un pagamento di quota arrivato dal gateway per una domanda: non entra in prima nota finché la segreteria non approva (lo fa {@see application_settle()}).
	 * Se la domanda non esiste più (rifiutata nel frattempo) il pagamento è da rimborsare; se è già stata approvata si registra subito.
	 */
	private function finalize_application( array $p, int $paid_cents, string $provider_payment_id ): bool {
		$person = Plugin::people()->get( (int) $p['payer_person_id'] );
		$data   = array( 'status' => 'paid', 'paid_at' => Db::now(), 'provider_payment_id' => mb_substr( $provider_payment_id, 0, 120 ) );
		if ( $paid_cents !== (int) $p['amount_cents'] ) {
			$data['review'] = 1;
			$data['error']  = 'Importo pagato (' . Money::format( $paid_cents ) . ') diverso da quello atteso (' . Money::format( (int) $p['amount_cents'] ) . ').';
		}
		if ( ! $person ) {
			$data['refund_due'] = 1;
			$data['review']     = 1;
			$data['error']      = 'Pagamento arrivato dopo il rifiuto della domanda: da rimborsare.';
		}
		$this->update( (int) $p['id'], $data );
		Audit::log( 'payment.paid', 'payment', (int) $p['id'], array( 'provider' => $p['provider'], 'cents' => $paid_cents, 'application' => 1 ) );
		if ( $person && empty( $person['application_status'] ) && $paid_cents === (int) $p['amount_cents'] ) { // domanda già approvata: la quota si registra ora
			$this->application_settle( (int) $person['id'], Settings::membership_year()->label() );
		}
		return true;
	}

	// ---------- Rimborso dal gateway ----------

	/** Rimborso minimo: sotto questa cifra i gestori lo rifiutano o costa più della somma. */
	const REFUND_MIN_CENTS = 50;

	/** Metodi che si rimborsano da qui (gli altri si restituiscono dal loro pannello). */
	const REFUND_GATEWAYS = array( 'stripe' => 'Stripe', 'paypal' => 'PayPal' );

	/** Incassi in prima nota collegati al pagamento (stesso riferimento e metodo): quanti, e quanti non annullati. @return array{total:int,active:int} */
	private function linked_income( array $p ): array {
		$ref = substr( (string) $p['provider_ref'], 0, 80 );
		if ( '' === $ref ) {
			return array( 'total' => 0, 'active' => 0 );
		}
		$row = $this->db()->get_row(
			$this->db()->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(voided_at IS NULL),0) AS a FROM ' . Db::t( 'transactions' ) . " WHERE document_ref = %s AND method = %s AND type = 'income'", $ref, (string) $p['provider'] ),
			ARRAY_A
		);
		return array( 'total' => (int) ( $row['n'] ?? 0 ), 'active' => (int) ( $row['a'] ?? 0 ) );
	}

	/**
	 * Si può restituire questo pagamento da qui? Sì per i pagamenti Stripe e PayPal incassati che (a) non sono mai entrati in prima nota (domanda rifiutata,
	 * pagamento arrivato dopo il rifiuto, pagamento da controllare) o (b) sono entrati ma tutti i loro incassi sono stati annullati. Se l'incasso è
	 * ancora valido in prima nota non si rimborsa: va prima annullato.
	 *
	 * @return array{ok:bool,why:string,case:string,remaining:int} case: 'a' (mai registrato) o 'b' (incasso annullato)
	 */
	public function refund_info( array $p ): array {
		$no = function ( string $why ): array {
			return array( 'ok' => false, 'why' => $why, 'case' => '', 'remaining' => 0 );
		};
		if ( ! isset( self::REFUND_GATEWAYS[ $p['provider'] ] ) ) {
			if ( PaymentConfig::WOOCOMMERCE === $p['provider'] ) {
				return $no( Texts::t( 'Questo pagamento è passato dal negozio WooCommerce: rimborsalo dall\'ordine nel negozio.' ) );
			}
			$name = array( 'sumup' => 'SumUp', 'satispay' => 'Satispay' )[ $p['provider'] ] ?? (string) $p['provider'];
			return $no( Texts::t( 'I pagamenti con {metodo} non si rimborsano da qui: restituisci i soldi dal pannello di {metodo}.', array( 'metodo' => $name ) ) );
		}
		if ( 'paid' !== $p['status'] ) {
			return $no( Texts::t( 'Il pagamento non risulta incassato: non c\'è nulla da restituire.' ) );
		}
		if ( '' === (string) $p['provider_payment_id'] ) {
			return $no( Texts::t( 'Di questo pagamento manca il riferimento sul gestore: restituisci i soldi dal suo pannello.' ) );
		}
		$remaining = (int) $p['amount_cents'] - (int) ( $p['refunded_cents'] ?? 0 );
		if ( $remaining <= 0 ) {
			return $no( Texts::t( 'Questo pagamento è già stato restituito per intero.' ) );
		}
		$linked = $this->linked_income( $p );
		if ( $linked['active'] > 0 ) {
			return $no( Texts::t( 'L\'incasso è ancora registrato in prima nota: annullalo prima dalla prima nota, poi potrai restituire i soldi da qui.' ) );
		}
		if ( 0 === $linked['total'] ) {
			$items = (array) json_decode( (string) $p['items'], true );
			if ( isset( $items[0]['type'] ) && PaymentItems::APPLICATION === $items[0]['type'] && empty( $p['refund_due'] ) ) {
				$person = Plugin::people()->get( (int) $p['payer_person_id'] );
				if ( $person && ! empty( $person['application_status'] ) ) {
					return $no( Texts::t( 'La domanda di adesione è ancora in esame: se la rifiuti la quota risulterà da rimborsare.' ) );
				}
			}
		}
		return array( 'ok' => true, 'why' => '', 'case' => 0 === $linked['total'] ? 'a' : 'b', 'remaining' => $remaining );
	}

	/** Frase per chi ha provato un rimborso che il gestore ha rifiutato. */
	private function refund_error( string $provider, string $reason, string $detail ): string {
		$name = self::REFUND_GATEWAYS[ $provider ] ?? $provider;
		switch ( $reason ) {
			case 'permission':
				$tail = '' !== $detail ? ' ' . Texts::t( 'Messaggio del gestore: {dettaglio}', array( 'dettaglio' => mb_substr( $detail, 0, 160 ) ) ) : '';
				return ( 'stripe' === $provider
					? Texts::t( 'La chiave Stripe non ha il permesso di fare rimborsi: controlla le autorizzazioni della chiave su Stripe (serve poter scrivere i rimborsi).' )
					: Texts::t( 'L\'app PayPal non ha il permesso di fare rimborsi: controlla le autorizzazioni dell\'app su developer.paypal.com e che l\'account PayPal possa rimborsare, poi riprova.' ) ) . $tail;
			case 'key':
				return Texts::t( 'Le credenziali di {metodo} non sono valide: controllale in Impostazioni → Pagamenti online.', array( 'metodo' => $name ) );
			case 'already':
				return Texts::t( '{metodo} dice che questo pagamento è già stato rimborsato per intero: controlla dal suo pannello.', array( 'metodo' => $name ) );
			case 'not_found':
				return Texts::t( '{metodo} non trova questo pagamento: controlla che le chiavi siano quelle dell\'account che lo ha incassato (prova o reale).', array( 'metodo' => $name ) );
			case 'balance':
				return Texts::t( 'Sul tuo conto {metodo} non c\'è abbastanza saldo per il rimborso: aspetta che arrivino gli accrediti o ricarica il saldo, poi riprova.', array( 'metodo' => $name ) );
			case 'amount':
				return Texts::t( 'L\'importo è più di quello che {metodo} può ancora restituire su questo pagamento: controlla dal suo pannello se è già stato rimborsato in parte.', array( 'metodo' => $name ) );
		}
		return Texts::t( 'Rimborso non riuscito: {fornitore}', array( 'fornitore' => $name . ( '' !== $detail ? ': ' . mb_substr( $detail, 0, 160 ) : '' ) ) );
	}

	/**
	 * Restituisce al socio (tutto o parte di) un pagamento Stripe o PayPal. NON scrive in prima nota: lascia traccia solo nel pagamento e nel registro
	 * azioni. Non si annulla. Il rimborso parte con una chiave di idempotenza legata al pagamento e a quanto era già stato restituito: lo stesso invio
	 * ripetuto non rimborsa due volte.
	 *
	 * @param int $cents    importo da restituire
	 * @param int $expected quanto risultava già restituito quando l'amministratore ha aperto la pagina (se nel frattempo è cambiato, non si procede)
	 * @return array{cents:int,total:int,full:bool,mailed:bool}
	 * @throws \InvalidArgumentException con la frase da mostrare
	 */
	public function refund( int $id, int $cents, int $expected, string $reason ): array {
		$p = $this->get( $id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( Texts::t( 'Pagamento non trovato.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$info = $this->refund_info( $p );
		if ( ! $info['ok'] ) {
			throw new \InvalidArgumentException( $info['why'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$before = (int) $p['refunded_cents'];
		if ( $before !== $expected ) {
			throw new \InvalidArgumentException( Texts::t( 'Il pagamento è cambiato dopo che hai aperto la pagina (già restituiti {importo}): ricarica la pagina e controlla prima di restituire altro.', array( 'importo' => Money::format( $before ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( Texts::t( 'Scrivi il motivo del rimborso: lo leggerà il socio.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $cents < self::REFUND_MIN_CENTS || $cents > $info['remaining'] ) {
			throw new \InvalidArgumentException( Texts::t( 'Importo non valido: si può restituire da {min} a {max}.', array( 'min' => Money::format( self::REFUND_MIN_CENTS ), 'max' => Money::format( $info['remaining'] ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$provider = (string) $p['provider'];
		$name     = self::REFUND_GATEWAYS[ $provider ];
		$tbl      = Db::t( 'payments' );
		$pending  = (int) ( $p['refund_pending_cents'] ?? 0 );
		if ( $pending > 0 ) { // un tentativo precedente è ancora in corso o il gestore non ha mai confermato: si può solo ripeterlo, con la stessa chiave e lo stesso importo
			$this->refund_check_pending( $p, $cents, $name );
		} else { // presa atomica prima di chiamare il gestore: chi arriva insieme a un altro con un importo diverso si ferma qui
			$took = $this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'payments' ) . ' SET refund_pending_cents = %d, refund_pending_at = %s, updated_at = %s WHERE id = %d AND refunded_cents = %d AND refund_pending_cents = 0', $cents, Db::now(), Db::now(), $id, $before ) );
			if ( 1 !== (int) $took ) {
				$q = $this->get( $id );
				if ( $q && (int) $q['refund_pending_cents'] > 0 ) {
					$this->refund_check_pending( $q, $cents, $name );
				}
				throw new \InvalidArgumentException( Texts::t( 'Il pagamento è cambiato dopo che hai aperto la pagina (già restituiti {importo}): ricarica la pagina e controlla prima di restituire altro.', array( 'importo' => Money::format( (int) ( $q['refunded_cents'] ?? $before ) ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		$cfg      = ProSettings::payment_config();
		$key      = 'refund-' . $p['public_id'] . '-' . $before . '-' . $cents;
		try {
			if ( 'stripe' === $provider ) {
				if ( '' === (string) $cfg['stripe_secret_key'] ) {
					throw new \InvalidArgumentException( Texts::t( 'Le credenziali di {metodo} non sono impostate: inseriscile in Impostazioni → Pagamenti online.', array( 'metodo' => $name ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$res = StripeApi::refund( $this->http(), $cfg['stripe_secret_key'], (string) $p['provider_payment_id'], $cents, $key, (string) $p['public_id'] );
			} else {
				if ( '' === (string) $cfg['paypal_client_id'] || '' === (string) $cfg['paypal_client_secret'] ) {
					throw new \InvalidArgumentException( Texts::t( 'Le credenziali di {metodo} non sono impostate: inseriscile in Impostazioni → Pagamenti online.', array( 'metodo' => $name ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$live = 'live' === $cfg['paypal_mode'];
				try {
					$token = PayPalApi::access_token( $this->http(), $live, $cfg['paypal_client_id'], $cfg['paypal_client_secret'] );
				} catch ( \RuntimeException $e ) {
					throw new \InvalidArgumentException( $this->refund_error( $provider, 'key', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$res = PayPalApi::refund( $this->http(), $live, $token, (string) $p['provider_payment_id'], $cents, mb_substr( $key, 0, 100 ), (string) $p['public_id'] );
			}
		} catch ( \InvalidArgumentException $e ) { // nulla è partito verso il gestore: il tentativo si libera
			$this->refund_release( $id, $cents );
			throw $e;
		} catch ( \RuntimeException $e ) { // rete: non si sa se il gestore ha ricevuto la richiesta. Il tentativo resta salvato e il pagamento «da controllare»: si ripete solo con lo stesso importo (stessa chiave)
			$this->update( $id, array( 'review' => 1, 'error' => mb_substr( Texts::t( 'Rimborso di {importo} non confermato dal gestore: da controllare.', array( 'importo' => Money::format( $cents ) ) ), 0, 250 ) ) );
			Audit::log( 'payment.refund_unreachable', 'payment', $id, array( 'provider' => $provider, 'cents' => $cents ) );
			throw new \InvalidArgumentException( Texts::t( 'Il servizio di {metodo} non risponde: il rimborso di {importo} non è stato confermato. Riprova tra poco con lo stesso importo: ripetere lo stesso invio non rimborsa due volte. Con un importo diverso non si riparte finché questo non è chiarito.', array( 'metodo' => $name, 'importo' => Money::format( $cents ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( empty( $res['ok'] ) ) {
			$msg = $this->refund_error( $provider, (string) $res['reason'], (string) $res['message'] );
			if ( empty( $res['certain'] ) ) { // errore del server, troppe richieste, risposta illeggibile o errore non riconosciuto: il rimborso può essere partito
				$this->update( $id, array( 'review' => 1, 'error' => mb_substr( Texts::t( 'Rimborso di {importo} non confermato dal gestore: da controllare.', array( 'importo' => Money::format( $cents ) ) ), 0, 250 ) ) );
				Audit::log( 'payment.refund_unconfirmed', 'payment', $id, array( 'provider' => $provider, 'cents' => $cents, 'reason' => $res['reason'] ) );
				throw new \InvalidArgumentException( $msg . ' ' . Texts::t( 'Non è certo che il rimborso non sia partito: il pagamento resta «da controllare» e si può ripetere solo lo stesso importo ({importo}).', array( 'importo' => Money::format( $cents ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$this->refund_release( $id, $cents ); // rifiuto certo del gestore: nulla è stato restituito
			Audit::log( 'payment.refund_refused', 'payment', $id, array( 'provider' => $provider, 'cents' => $cents, 'reason' => $res['reason'] ) );
			throw new \InvalidArgumentException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$total = $before + $cents;
		$full  = $total >= (int) $p['amount_cents'];
		$data  = array( 'refunded_cents' => $total, 'refund_pending_cents' => 0, 'refund_id' => mb_substr( (string) $res['id'], 0, 120 ), 'refunded_at' => Db::now(), 'updated_at' => Db::now(), 'error' => mb_substr( Texts::t( 'Restituiti {importo} al socio.', array( 'importo' => Money::format( $total ) ) ), 0, 250 ) );
		if ( $full ) {
			$data['refund_due'] = 0;
			$data['review']     = 0; // tutto restituito: nulla più da controllare
		}
		$done = $this->db()->update( $tbl, $data, array( 'id' => $id, 'refunded_cents' => $before, 'refund_pending_cents' => $cents ) ); // solo se la riga è ancora quella di prima
		if ( 1 !== (int) $done ) { // il gestore ha confermato ma la scrittura non è passata: l'importo si somma comunque e il pagamento resta «da controllare»
			$this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'payments' ) . ' SET refunded_cents = refunded_cents + %d, refund_pending_cents = 0, review = 1, refund_id = %s, refunded_at = %s, updated_at = %s, error = %s WHERE id = %d', $cents, mb_substr( (string) $res['id'], 0, 120 ), Db::now(), Db::now(), mb_substr( Texts::t( 'Rimborso di {importo} confermato dal gestore ma registrato in modo anomalo: da controllare.', array( 'importo' => Money::format( $cents ) ) ), 0, 250 ), $id ) );
			$row   = $this->get( $id );
			$total = (int) ( $row['refunded_cents'] ?? $total );
			$full  = $total >= (int) $p['amount_cents'];
			if ( $full ) {
				$this->update( $id, array( 'refund_due' => 0 ) );
			}
		}
		Audit::log( 'payment.refunded', 'payment', $id, array( 'provider' => $provider, 'cents' => $cents, 'total' => $total, 'refund' => (string) $res['id'], 'case' => $info['case'], 'reason' => mb_substr( $reason, 0, 200 ), 'recorded' => 1 === (int) $done ) );
		return array( 'cents' => $cents, 'total' => $total, 'full' => $full, 'mailed' => $this->send_refund_email( $this->get( $id ), $cents, $reason ) );
	}

	/** Dopo quanto il gestore non riconosce più lo stesso invio come uno già fatto (Stripe conserva la chiave 24 ore): un po' meno, per sicurezza. */
	const REFUND_KEY_SECONDS = 82800;

	/** Il tentativo non confermato è più vecchio di {@see REFUND_KEY_SECONDS}? */
	private static function refund_pending_stale( array $p ): bool {
		$at = (string) ( $p['refund_pending_at'] ?? '' );
		return (int) ( $p['refund_pending_cents'] ?? 0 ) > 0 && '' !== $at && strtotime( $at ) < strtotime( Db::now() ) - self::REFUND_KEY_SECONDS;
	}

	/** Libera il tentativo di rimborso preso da {@see refund()} (solo se è proprio questo importo). */
	private function refund_release( int $id, int $cents ): void {
		$this->db()->update( Db::t( 'payments' ), array( 'refund_pending_cents' => 0 ), array( 'id' => $id, 'refund_pending_cents' => $cents ) );
	}

	/**
	 * Su questo pagamento c'è un rimborso in corso o non confermato: si può ripetere solo lo stesso importo, e non oltre {@see REFUND_KEY_SECONDS}.
	 * Se va bene torna senza fare nulla.
	 *
	 * @throws \InvalidArgumentException con la frase da mostrare
	 */
	private function refund_check_pending( array $p, int $cents, string $name ): void {
		// (il controllo dell'età sta in refund_pending_stale)
		$pending = (int) $p['refund_pending_cents'];
		if ( $pending !== $cents ) {
			throw new \InvalidArgumentException( Texts::t( 'Su questo pagamento c\'è un rimborso di {importo} in corso o non confermato dal gestore: si può ripetere solo lo stesso importo, così non si restituisce due volte.', array( 'importo' => Money::format( $pending ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( self::refund_pending_stale( $p ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Il rimborso di {importo} non è stato confermato da più di 24 ore: il gestore non lo riconosce più come lo stesso invio. Controlla dal pannello di {metodo} se è partito; se non è partito, premi «Controllato» e riprova.', array( 'importo' => Money::format( $pending ), 'metodo' => $name ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Email al socio che ha pagato: gli diciamo quanto gli è stato restituito. Senza email non si manda nulla (il rimborso è già partito). */
	private function send_refund_email( ?array $p, int $cents, string $reason ): bool {
		$payer = $p ? Plugin::people()->get( (int) $p['payer_person_id'] ) : null;
		if ( ! $p || ! $payer || empty( $payer['email'] ) ) {
			return false;
		}
		$assoc   = (string) Settings::get( 'association_name' );
		$assoc   = '' !== $assoc ? $assoc : Texts::t( 'l\'associazione' );
		$subject = Texts::t( 'Rimborso del tuo pagamento · {associazione}', array( 'associazione' => $assoc ) );
		$tpl     = 'Ciao, abbiamo restituito {importo} del pagamento che hai fatto il {data} a {associazione}. Motivo: {motivo}. L\'accredito compare sul tuo metodo di pagamento in genere entro pochi giorni lavorativi, fino a 10 a seconda della banca o del metodo di pagamento. Se hai dubbi, scrivi alla segreteria. — {associazione}';
		if ( Contacts::complete() ) { // con i contatti della segreteria la chiusa li riporta (e Texts::mail aggiunge il Reply-To); senza, resta la frase generica
			$tpl = str_replace( 'scrivi alla segreteria. —', 'scrivi alla segreteria: {email}, tel. {telefono}. —', $tpl );
		}
		$body    = Texts::t(
			$tpl,
			array(
				'importo'      => Money::format( $cents ),
				'data'         => ( new \DateTimeImmutable( (string) ( $p['paid_at'] ?: $p['created_at'] ) ) )->format( 'd/m/Y' ),
				'associazione' => $assoc,
				'motivo'       => $reason,
				'email'        => Contacts::email(),
				'telefono'     => Contacts::phone(),
			)
		);
		return Texts::mail( $payer['email'], $subject, $body );
	}

	/**
	 * Azione `asem_tx_voided`: se l'incasso annullato era l'ultimo ancora valido di un pagamento online Stripe o PayPal, il pagamento si segna «da controllare»
	 * con la domanda se i soldi vanno restituiti al socio. Non rimborsa nulla e non tocca altro: qualunque errore qui non deve fermare l'annullo.
	 */
	public static function on_tx_voided( $tx_id, $reason = '' ): void {
		try {
			$svc = self::engine();
			$db  = $svc->db();
			$tx  = $db->get_row( $db->prepare( 'SELECT type, method, document_ref FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', (int) $tx_id ), ARRAY_A );
			if ( ! $tx || 'income' !== $tx['type'] || '' === (string) $tx['document_ref'] || ! isset( self::REFUND_GATEWAYS[ $tx['method'] ] ) ) {
				return;
			}
			$p = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = %s AND status = 'paid' AND LEFT(provider_ref,80) = %s ORDER BY id DESC LIMIT 1", (string) $tx['method'], (string) $tx['document_ref'] ), ARRAY_A );
			if ( ! $p || (int) $p['refunded_cents'] >= (int) $p['amount_cents'] ) {
				return;
			}
			$linked = $svc->linked_income( $p );
			if ( $linked['total'] < 1 || $linked['active'] > 0 ) {
				return; // restano incassi validi: non è ancora il caso di chiedersi se rimborsare
			}
			$data = array( 'review' => 1 );
			if ( '' === (string) $p['error'] ) {
				$data['error'] = Texts::t( 'Incasso annullato in prima nota: se i soldi vanno restituiti al socio usa «Rimborsa».' );
			}
			$svc->update( (int) $p['id'], $data );
			Audit::log( 'payment.void_signal', 'payment', (int) $p['id'], array( 'tx' => (int) $tx_id ) );
		} catch ( \Throwable $e ) {
			unset( $e ); // l'annullo dell'incasso è già riuscito: il segnale è un di più
		}
	}

	// ---------- Conferme ----------

	/** Messaggio al socio a pagamento ricevuto: se il pagamento resta da controllare lo sa, senza dettagli. */
	private function received_message( ?array $p ): string {
		return $p && ! empty( $p['review'] ) ? Texts::t( 'Pagamento ricevuto: la segreteria lo sta controllando.' ) : 'Pagamento ricevuto: grazie!';
	}

	/** Ritorno del socio dalla pagina del gateway. @return string messaggio per l'utente */
	public function handle_return( string $public_id, string $ret, int $user_id ): string {
		$p = $this->get_by_public( $public_id );
		if ( ! $p || (int) $p['payer_user_id'] !== $user_id ) {
			throw new \InvalidArgumentException( 'Pagamento non trovato.' );
		}
		if ( 'paid' === $p['status'] ) {
			return $this->received_message( $p );
		}
		if ( 'cancel' === $ret ) {
			if ( in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
				if ( $this->confirm_with_gateway( $p ) ) {
					return $this->received_message( $this->get( (int) $p['id'] ) ); // il gateway risulta pagato anche se l'indirizzo era quello dell'annullo: non si perde un incasso
				}
				$this->update( (int) $p['id'], array( 'status' => 'cancelled' ) );
			}
			return 'Pagamento annullato: non è stato addebitato nulla.';
		}
		$this->confirm_with_gateway( $p );
		$p = $this->get( (int) $p['id'] );
		if ( 'paid' === $p['status'] ) {
			return $this->received_message( $p );
		}
		return 'Pagamento in attesa di conferma: appena il gestore del pagamento lo conferma, lo registriamo.';
	}

	/** Interroga il gateway e, se il pagamento risulta riuscito, lo registra. @return bool true se è stato registrato ora */
	private function confirm_with_gateway( array $p ): bool {
		if ( ! in_array( $p['status'], self::OPEN_STATUSES, true ) || empty( $p['provider_ref'] ) || PaymentConfig::WOOCOMMERCE === $p['provider'] ) {
			return false; // WooCommerce non si interroga: è il negozio a segnalare l'ordine pagato
		}
		$cfg = ProSettings::payment_config();
		try {
			if ( 'stripe' === $p['provider'] ) {
				$s = StripeApi::retrieve_session( $this->http(), $cfg['stripe_secret_key'], $p['provider_ref'] );
				if ( StripeApi::is_paid( $s ) ) {
					return $this->finalize( $p, StripeApi::paid_eur_cents( $s ), (string) ( $s['payment_intent'] ?? '' ) );
				}
				if ( 'expired' === ( $s['status'] ?? '' ) ) {
					$this->update( (int) $p['id'], array( 'status' => 'expired' ) );
				}
				return false;
			}
			if ( PaymentConfig::SUMUP === $p['provider'] ) {
				$c = SumUpApi::retrieve_checkout( $this->http(), $cfg['sumup_api_key'], $p['provider_ref'] );
				if ( SumUpApi::is_paid( $c ) ) {
					return $this->finalize( $p, SumUpApi::paid_eur_cents( $c ), SumUpApi::transaction_id( $c ) );
				}
				if ( SumUpApi::is_failed( $c ) ) {
					$this->update( (int) $p['id'], array( 'status' => 'failed', 'error' => 'SumUp: pagamento non riuscito o scaduto.' ) );
				}
				return false;
			}
			if ( SatispayRules::ID === $p['provider'] ) {
				$c = SatispayApi::retrieve_payment( $this->http(), $cfg, $p['provider_ref'] );
				if ( SatispayRules::is_accepted( $c ) ) {
					return $this->finalize( $p, SatispayRules::paid_eur_cents( $c ), (string) ( $c['id'] ?? $p['provider_ref'] ) );
				}
				if ( SatispayRules::is_failed( $c ) ) {
					$this->update( (int) $p['id'], array( 'status' => 'failed', 'error' => 'Satispay: pagamento annullato o scaduto.' ) );
				}
				return false;
			}
			$live  = 'live' === $cfg['paypal_mode'];
			$token = PayPalApi::access_token( $this->http(), $live, $cfg['paypal_client_id'], $cfg['paypal_client_secret'] );
			$order = PayPalApi::get_order( $this->http(), $live, $token, $p['provider_ref'] );
			if ( 'APPROVED' === ( $order['status'] ?? '' ) ) {
				$order = PayPalApi::capture_order( $this->http(), $live, $token, $p['provider_ref'] ); // l'utente ha approvato: si incassa
			}
			if ( PayPalApi::is_completed( $order ) ) {
				return $this->finalize( $p, PayPalApi::captured_cents( $order ), PayPalApi::capture_id( $order ) );
			}
		} catch ( \RuntimeException $e ) {
			$this->update( (int) $p['id'], array( 'error' => mb_substr( $e->getMessage(), 0, 250 ) ) );
		}
		return false;
	}

	/**
	 * Avviso (callback) di Satispay: il contenuto della richiesta non si crede, serve solo a sapere QUALE pagamento ricontrollare;
	 * l'esito vero si legge da Satispay con una richiesta firmata. @return string esito per il log
	 */
	public function handle_satispay_callback( string $payment_id ): string {
		$payment_id = SatispayRules::clean_id( $payment_id );
		if ( '' === $payment_id ) {
			return 'ignorato';
		}
		$p = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = 'satispay' AND provider_ref = %s", $payment_id ), ARRAY_A );
		if ( ! $p ) {
			return 'pagamento sconosciuto';
		}
		return $this->confirm_with_gateway( $p ) ? 'registrato' : 'in attesa';
	}

	/** Webhook di Stripe già verificato (firma). @return string esito per il log */
	public function handle_stripe_event( array $event ): string {
		$type = (string) ( $event['type'] ?? '' );
		$obj  = $event['data']['object'] ?? array();
		if ( 0 !== strpos( $type, 'checkout.session.' ) || ! is_array( $obj ) ) {
			return 'ignorato';
		}
		$public = (string) ( $obj['client_reference_id'] ?? ( $obj['metadata']['asem_payment'] ?? '' ) );
		$p      = '' !== $public ? $this->get_by_public( $public ) : null;
		if ( ! $p || 'stripe' !== $p['provider'] ) {
			return 'pagamento sconosciuto';
		}
		if ( isset( $event['livemode'] ) && (bool) $event['livemode'] !== ( 'live' === (string) Settings::get( 'stripe_mode' ) ) ) {
			return 'modalità diversa'; // un evento di prova non registra incassi veri (e viceversa)
		}
		if ( ! empty( $p['provider_ref'] ) && ! empty( $obj['id'] ) && $p['provider_ref'] !== $obj['id'] ) {
			return 'sessione diversa';
		}
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && StripeApi::is_paid( $obj ) ) {
			$this->finalize( $p, StripeApi::paid_eur_cents( $obj ), (string) ( $obj['payment_intent'] ?? '' ) );
			return 'registrato';
		}
		if ( in_array( $type, array( 'checkout.session.expired', 'checkout.session.async_payment_failed' ), true ) && in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
			$this->update( (int) $p['id'], array( 'status' => 'checkout.session.expired' === $type ? 'expired' : 'failed' ) );
			return 'chiuso';
		}
		if ( 'checkout.session.completed' === $type && KlarnaRules::is_deferred( $obj ) ) {
			return 'in attesa del pagamento differito'; // Klarna e gli altri metodi differiti: l'esito arriva con async_payment_succeeded / async_payment_failed
		}
		return 'in attesa';
	}

	/** Lavoro periodico di WP-Cron (pianificato da Plugin::schedule_jobs()). */
	public static function check_pending_job(): void {
		PaymentService::engine()->check_pending();
	}

	/** Ricontrolla i pagamenti rimasti in sospeso (chiamato ogni ora da WP-Cron e dal pulsante in amministrazione). */
	public function check_pending( bool $include_recent = false ): array {
		$this->recover_stuck();
		$limit = gmdate( 'Y-m-d H:i:s', time() - ( $include_recent ? 0 : Limits::get( 'pay_pending_minutes' ) * 60 ) );
		$rows  = $this->db()->get_results(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE status = 'pending' AND created_at <= %s ORDER BY id LIMIT 50", get_date_from_gmt( $limit ) ),
			ARRAY_A
		) ?: array();
		$out = array( 'checked' => 0, 'registered' => 0, 'expired' => 0 );
		foreach ( $rows as $p ) {
			$out['checked']++;
			if ( $this->confirm_with_gateway( $p ) ) {
				$out['registered']++;
				continue;
			}
			$fresh = $this->get( (int) $p['id'] );
			if ( 'pending' === $fresh['status'] && strtotime( $p['created_at'] ) < time() - Limits::get( 'pay_expire_days' ) * DAY_IN_SECONDS ) {
				$this->update( (int) $p['id'], array( 'status' => 'expired' ) );
				$out['expired']++;
			}
		}
		return $out;
	}

	/**
	 * Un pagamento rimasto "in registrazione" (il server si è fermato a metà): i soldi sono arrivati ma non si sa quanto sia stato scritto in prima nota.
	 * Non si riprova da soli (si rischierebbe di registrare due volte una parte): lo si segna "da controllare".
	 */
	private function recover_stuck(): void {
		$tbl   = Db::t( 'payments' );
		$limit = gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - self::STUCK_AFTER_MINUTES * 60 );
		foreach ( $this->db()->get_results( $this->db()->prepare( "SELECT id FROM $tbl WHERE status = 'processing' AND updated_at <= %s LIMIT 50", $limit ), ARRAY_A ) ?: array() as $r ) {
			$this->update( (int) $r['id'], array( 'status' => 'paid', 'paid_at' => Db::now(), 'review' => 1, 'error' => 'Registrazione interrotta a metà: controlla la prima nota prima di correggere a mano.' ) );
			Audit::log( 'payment.stuck', 'payment', (int) $r['id'] );
		}
	}

	// ---------- Registrazione in prima nota ----------

	/**
	 * Registra l'incasso (una sola volta). Il pagamento è già arrivato: se qualcosa non torna (prenotazione annullata nel frattempo,
	 * importo diverso) i soldi entrano comunque come "pagamento online non abbinato" e il pagamento viene segnato "da controllare".
	 *
	 * @return bool true se questa chiamata ha registrato il pagamento
	 */
	private function finalize( array $p, int $paid_cents, string $provider_payment_id ): bool {
		$tbl     = Db::t( 'payments' );
		$open    = "'" . implode( "','", self::OPEN_STATUSES ) . "'";
		$claimed = $this->db()->query( $this->db()->prepare( "UPDATE $tbl SET status = 'processing', updated_at = %s WHERE id = %d AND status IN ($open)", Db::now(), (int) $p['id'] ) );
		if ( 1 !== (int) $claimed ) {
			return false; // già registrato (o in registrazione) da un altro passaggio
		}
		$items       = (array) json_decode( (string) $p['items'], true );
		if ( isset( $items[0]['type'] ) && PaymentItems::APPLICATION === $items[0]['type'] ) {
			return $this->finalize_application( $p, $paid_cents, $provider_payment_id ); // la quota di una domanda entra in prima nota quando la domanda è approvata
		}
		$ledger      = Plugin::ledger();
		$account     = $ledger->online_account( $p['provider'] );
		$method      = $p['provider'];
		$today       = Db::today();
		$ref         = substr( (string) $p['provider_ref'], 0, 80 );
		$labels      = array( 'paypal' => 'PayPal', 'sumup' => 'SumUp', 'satispay' => 'Satispay', 'woocommerce' => 'WooCommerce' );
		$note        = 'Pagamento online (' . ( $labels[ $p['provider'] ] ?? 'Stripe' ) . ')';
		$allocated   = 0;
		$review      = false;
		$error       = null;
		$unallocated = 0;
		try {
			if ( $paid_cents !== (int) $p['amount_cents'] ) {
				$review      = true;
				$error       = 'Importo pagato (' . Money::format( $paid_cents ) . ') diverso da quello atteso (' . Money::format( (int) $p['amount_cents'] ) . ').';
				$unallocated = $paid_cents;
			} else {
				foreach ( PaymentItems::group_by_person( $items ) as $person_id => $group ) {
					$lines = array();
					foreach ( $group as $i ) {
						$lines[] = $this->line_for( $i, $note, $ledger );
					}
					try {
						$ledger->record_receipt( array( 'date' => $today, 'account_id' => $account, 'method' => $method, 'person_id' => (int) $person_id, 'document_ref' => $ref, 'lines' => $lines ) );
					} catch ( \InvalidArgumentException $e ) {
						$review       = true;
						$error        = mb_substr( $e->getMessage(), 0, 250 );
						$unallocated += PaymentItems::total( $group );
						continue;
					}
					$allocated += PaymentItems::total( $group );
					foreach ( $group as $i ) { // l'incasso è già in prima nota: se un passo successivo non riesce il pagamento resta «da controllare», senza riscrivere l'incasso
						try {
							if ( PaymentItems::RESERVATION === $i['type'] ) {
								Resources::confirm( (int) $i['reservation_id'] ); // pagata: la fascia non è più provvisoria
							}
							if ( PaymentItems::CREDITS === $i['type'] ) {
								Credits::granted_online( (int) $i['person_id'], (int) $i['credits'], (int) $p['id'] ); // pacchetto pagato: i crediti arrivano al socio
							}
						} catch ( \Throwable $e ) {
							$review = true;
							$error  = mb_substr( Texts::t( 'Incasso registrato, ma un passo successivo non è riuscito ({voce}): controlla il pagamento.', array( 'voce' => (string) ( $i['label'] ?? $i['type'] ) ) ), 0, 250 );
							Audit::log( 'payment.after_receipt_failed', 'payment', (int) $p['id'], array( 'item' => (string) $i['type'] ) );
						}
					}
				}
			}
			if ( $unallocated > 0 ) {
				$ledger->record_receipt(
					array(
						'date' => $today, 'account_id' => $account, 'method' => $method, 'person_id' => (int) $p['payer_person_id'], 'document_ref' => $ref,
						'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'other_income' ), 'amount_cents' => $unallocated, 'description' => $note . ' non abbinato a una voce' ) ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$review = true;
			$error  = mb_substr( 'Registrazione incompleta: ' . $e->getMessage(), 0, 250 );
		}
		$this->update( (int) $p['id'], array( 'status' => 'paid', 'paid_at' => Db::now(), 'provider_payment_id' => mb_substr( $provider_payment_id, 0, 120 ), 'allocated_cents' => $allocated, 'review' => $review ? 1 : 0, 'error' => $error ) );
		Audit::log( 'payment.paid', 'payment', (int) $p['id'], array( 'provider' => $p['provider'], 'cents' => $paid_cents, 'review' => $review ) );
		$this->book_fee( $p, $paid_cents, $method, $account, $ref ); // spesa «commissioni di incasso» (stima), se l'amministratore l'ha scelta
		$this->send_receipt_email( $this->get( (int) $p['id'] ), $items );
		return true;
	}

	/** Ordine WooCommerce pagato: registra l'incasso del pagamento corrispondente (una sola volta).  bool true se registrato ora */
	public function finalize_external( string $public_id, int $paid_cents, string $ref ): bool {
		$p = $this->get_by_public( $public_id );
		if ( ! $p || PaymentConfig::WOOCOMMERCE !== $p['provider'] ) {
			return false;
		}
		$p['provider_ref'] = substr( $ref, 0, 120 ); // in memoria: serve alla registrazione; si salva solo se questo ordine è quello che incassa
		$done              = $this->finalize( $p, $paid_cents, $ref );
		if ( $done ) {
			$this->update( (int) $p['id'], array( 'provider_ref' => $p['provider_ref'] ) );
		} else {
			$now = $this->get( (int) $p['id'] );
			if ( $now && 'paid' === $now['status'] && (string) $now['provider_ref'] !== $p['provider_ref'] ) { // un secondo ordine pagato per lo stesso pagamento: i soldi sono arrivati due volte
				$this->update( (int) $p['id'], array( 'review' => 1, 'error' => mb_substr( 'Pagato di nuovo con un altro ordine (' . $p['provider_ref'] . '): controlla se va restituito.', 0, 250 ) ) );
				Audit::log( 'payment.duplicate_external', 'payment', (int) $p['id'], array( 'ref' => $p['provider_ref'] ) );
			}
		}
		return $done;
	}

	/** Ordine WooCommerce annullato o fallito: il pagamento in attesa si chiude. */
	public function cancel_external( string $public_id ): void {
		$p = $this->get_by_public( $public_id );
		if ( $p && PaymentConfig::WOOCOMMERCE === $p['provider'] && in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
			$this->update( (int) $p['id'], array( 'status' => 'cancelled' ) );
			Audit::log( 'payment.cancelled', 'payment', (int) $p['id'], array( 'provider' => 'woocommerce' ) );
		}
	}

	private function line_for( array $i, string $note, LedgerService $ledger ): array {
		switch ( $i['type'] ) {
			case PaymentItems::MEMBERSHIP:
				return array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => (int) $i['amount_cents'], 'social_year' => $i['social_year'], 'description' => $note );
			case PaymentItems::COURSE_MONTH:
				return array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => (int) $i['amount_cents'], 'activity_id' => (int) $i['activity_id'], 'competence_month' => $i['month'], 'description' => $note );
			case PaymentItems::CREDITS:
				return array( 'category_id' => $ledger->category_id_of_kind( 'other_income' ), 'amount_cents' => (int) $i['amount_cents'], 'description' => mb_substr( 'Acquisto crediti: ' . (int) $i['credits'] . ' — ' . $note, 0, 255 ) );
			case PaymentItems::RESERVATION:
				return array( 'category_id' => Resources::income_category_for( (int) $i['reservation_id'] ), 'amount_cents' => (int) $i['amount_cents'], 'reservation_id' => (int) $i['reservation_id'], 'description' => mb_substr( $note . ' — ' . ( $i['label'] ?? '' ), 0, 255 ) );
			case PaymentItems::FEE:
				return array( 'category_id' => $ledger->category_id_of_kind( 'other_income' ), 'amount_cents' => (int) $i['amount_cents'], 'description' => mb_substr( 'Commissione di incasso a carico di chi paga — ' . $note, 0, 255 ) );
			default:
				return array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => (int) $i['amount_cents'], 'activity_id' => (int) $i['activity_id'], 'session_id' => (int) $i['session_id'], 'description' => $note );
		}
	}

	/** Ricevuta via email al socio che ha pagato (se l'invio non riesce non succede nulla: il pagamento è già registrato). */
	private function send_receipt_email( ?array $p, array $items ): void {
		$payer = $p ? Plugin::people()->get( (int) $p['payer_person_id'] ) : null;
		if ( ! $p || ! $payer || empty( $payer['email'] ) ) {
			return;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$lines = array_map( function ( $i ) {
			return '- ' . PaymentItems::line_name( $i ) . ': ' . Money::format( (int) $i['amount_cents'] );
		}, $items );
		$body  = 'Ciao ' . $payer['first_name'] . ",\n\nabbiamo ricevuto il tuo pagamento online" . ( '' !== $assoc ? ' per ' . $assoc : '' ) . ".\n\n" . implode( "\n", $lines )
			. "\n\nTotale: " . Money::format( (int) $p['amount_cents'] ) . "\nData: " . ( new \DateTimeImmutable( (string) $p['paid_at'] ) )->format( 'd/m/Y' ) . "\nRiferimento: " . $p['public_id'] . "\n\nGrazie!";
		\AssociazioneSemplice\Texts::mail( $payer['email'], 'Ricevuta del pagamento' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $body );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Domande di adesione online. Chi non è ancora socio compila il modulo pubblico: la domanda entra come persona con stato «richiesta»
 * (fuori dall'anagrafica, dagli elenchi e dai conti) finché la segreteria non la approva, assegnando il numero di tessera, o la rifiuta.
 *
 * Chi risulta minorenne dalla data di nascita indica anche un genitore o tutore: all'approvazione il genitore viene collegato (ed eventualmente
 * registrato) e riceve il link per entrare nell'area riservata, da dove segue il minore. Nessun dato personale va nel registro azioni.
 */
final class Applications {

	const REQUESTED = 'requested';
	const REJECTED  = 'rejected';
	const PAY_DAYS  = 7;

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function enabled(): bool {
		return ! empty( Settings::get( 'apply_enabled' ) ) && Edition::allows( 'member_area' );
	}

	/** Tipi di socio che si possono chiedere: tipo => etichetta. */
	public static function types(): array {
		$out = array( MemberType::ORDINARY => MemberType::label( MemberType::ORDINARY ) );
		if ( ! empty( Settings::get( 'apply_volunteer' ) ) ) {
			$out[ MemberType::VOLUNTEER ] = MemberType::label( MemberType::VOLUNTEER );
		}
		return $out;
	}

	/** Quota associativa per un tipo di socio (centesimi). */
	public static function fee_cents( string $type ): int {
		return Levels::fee_for( array( 'type' => $type, 'level_id' => null, 'family_head_id' => null ) );
	}

	/** La quota si può pagare online dalla domanda? */
	public static function online_payment(): bool {
		return ! empty( Settings::get( 'apply_pay_online' ) ) && Plugin::payments()->enabled() && Plugin::payments()->application_online();
	}

	public static function notify_email(): string {
		$mail = trim( (string) Settings::get( 'apply_email' ) );
		return is_email( $mail ) ? $mail : (string) get_option( 'admin_email' );
	}

	// ---------- Lettura ----------

	public static function pending_count(): int {
		return (int) self::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status = 'requested'" );
	}

	private static function row( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE id = %d AND deleted_at IS NULL AND application_status = 'requested'", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** @return array|null i dati del genitore scritti nella domanda (nome, cognome, email, telefono, codice fiscale) */
	public static function guardian_data( array $row ): ?array {
		$d = json_decode( (string) ( $row['application_data'] ?? '' ), true );
		return is_array( $d ) && ! empty( $d['guardian'] ) && is_array( $d['guardian'] ) ? $d['guardian'] : null;
	}

	/** Domande in attesa, dalla più vecchia. @return array[] persona + 'guardian', 'fee_cents', 'paid', 'suggested_card' */
	public static function pending(): array {
		$rows = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status = 'requested' ORDER BY application_at, id", ARRAY_A ) ?: array();
		$card = Plugin::people()->next_free_card();
		foreach ( $rows as $i => &$r ) {
			$r['guardian']       = self::guardian_data( $r );
			$r['fee_cents']      = self::fee_cents( (string) $r['type'] );
			$r['paid']           = Plugin::payments()->application_paid( (int) $r['id'] );
			$r['suggested_card'] = (string) ( (int) $card + $i );
		}
		unset( $r );
		return $rows;
	}

	// ---------- Nuova domanda ----------

	/**
	 * Registra una domanda. Non dice mai se l'email o la persona esistono già: se c'è un doppione, o se la coda è piena, la domanda non viene
	 * registrata e l'esito per chi scrive è lo stesso.
	 *
	 * @param array $in dati del modulo (vedi {@see ApplicationRules::check()})
	 * @return array{status:string,errors:string[]} status: invalid (dati scritti male) | off | ok
	 */
	public static function submit( array $in ): array {
		if ( ! self::enabled() ) {
			return array( 'status' => 'off', 'errors' => array() );
		}
		$chk = ApplicationRules::check( $in, Db::today(), Regulation::enabled(), array_keys( self::types() ) );
		if ( $chk['errors'] ) {
			return array( 'status' => 'invalid', 'errors' => $chk['errors'] );
		}
		$d      = $chk['data'];
		$people = Plugin::people();
		if ( '' !== $d['email'] && $people->find_by_email( $d['email'] ) ) {
			return array( 'status' => 'ok', 'errors' => array() ); // già nell'anagrafica o già in coda: nessuna differenza per chi scrive
		}
		if ( self::pending_count() >= Limits::get( 'applications_max' ) ) {
			return array( 'status' => 'ok', 'errors' => array() );
		}
		$now = Db::now();
		$ok  = self::db()->insert(
			Db::t( 'people' ),
			array(
				'type'               => $d['type'],
				'first_name'         => $d['first_name'],
				'last_name'          => $d['last_name'],
				'email'              => '' === $d['email'] ? null : $d['email'],
				'phone'              => '' === $d['phone'] ? null : $d['phone'],
				'tax_code'           => $d['tax_code'],
				'birth_date'         => $d['birth_date'],
				'joined_on'          => Db::today(),
				'application_status' => self::REQUESTED,
				'application_at'     => $now,
				'application_data'   => $chk['minor'] ? wp_json_encode( array( 'guardian' => $d['guardian'] ) ) : null,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		if ( ! $ok ) {
			return array( 'status' => 'ok', 'errors' => array() );
		}
		$id = (int) self::db()->insert_id;
		Privacy::set_consent( $id, 'web' ); // informativa accettata nel modulo (per un minore, dal genitore: lo si registra all'approvazione)
		if ( Regulation::enabled() ) {
			Regulation::accept( $id, 'web' ); // con la versione in vigore
		}
		Audit::log( 'application.received', 'person', $id, array( 'minor' => $chk['minor'] ? 1 : 0 ) );
		self::mail_secretary( $d, $chk['minor'] );
		self::mail_applicant( $id, $d, $chk['minor'] );
		return array( 'status' => 'ok', 'errors' => array() );
	}

	private static function assoc_suffix(): string {
		$assoc = (string) Settings::get( 'association_name' );
		return '' !== $assoc ? ' — ' . $assoc : '';
	}

	private static function mail_secretary( array $d, bool $minor ): void {
		$who  = trim( $d['first_name'] . ' ' . $d['last_name'] );
		$text = "È arrivata una nuova domanda di adesione.\n\n" . $who . ' (' . ( $minor ? 'minorenne, con un genitore indicato' : MemberType::label( $d['type'] ) ) . ")\n\n"
			. 'Approvala o rifiutala dalla Bacheca: ' . admin_url( 'admin.php?page=asem' );
		Texts::mail( self::notify_email(), 'Nuova domanda di adesione' . self::assoc_suffix(), $text );
	}

	/** Conferma a chi ha chiesto di iscriversi (per un minore, al genitore): cosa succede adesso e come pagare la quota. */
	private static function mail_applicant( int $id, array $d, bool $minor ): void {
		$to   = $minor ? $d['guardian']['email'] : $d['email'];
		$name = $minor ? $d['guardian']['first_name'] : $d['first_name'];
		$fee  = self::fee_cents( $d['type'] );
		$text = 'Ciao ' . $name . ",\n\nabbiamo ricevuto la domanda di adesione" . ( $minor ? ' di ' . $d['first_name'] : '' ) . ". La segreteria la guarda e ti scrive appena è approvata.\n\n";
		if ( $fee > 0 ) {
			if ( self::online_payment() ) {
				$text .= 'La quota associativa è di ' . Money::format( $fee ) . '. Se vuoi puoi pagarla subito online da questo link (vale ' . self::PAY_DAYS . " giorni):\n" . self::pay_url( $id ) . "\n\nSe la domanda non venisse accettata, la quota ti viene restituita.\n\n";
			} else {
				$text .= 'La quota associativa è di ' . Money::format( $fee ) . ": si paga in sede, alla segreteria.\n\n";
			}
		}
		$text .= 'Se non sei stato tu a chiederlo, ignora questo messaggio.';
		Texts::mail( $to, 'Domanda di adesione ricevuta' . self::assoc_suffix(), $text );
	}

	// ---------- Pagamento della quota ----------

	private static function secret(): string {
		return wp_salt( 'auth' ) . '|apply-pay';
	}

	public static function pay_url( int $person_id ): string {
		return add_query_arg( 'asem_apply_pay', ActivationToken::param( $person_id, time() + self::PAY_DAYS * DAY_IN_SECONDS, self::secret(), 'applypay' ), home_url( '/' ) );
	}

	/** @return array{status:string,person:?array} status: ok | invalid | expired | closed (domanda già lavorata) */
	public static function resolve_pay( string $param ): array {
		$state = ActivationToken::check( $param, self::secret(), time(), 'applypay' );
		if ( 'ok' !== $state ) {
			return array( 'status' => $state, 'person' => null );
		}
		$p = self::row( (int) ActivationToken::parse( $param )[0] );
		return $p ? array( 'status' => 'ok', 'person' => $p ) : array( 'status' => 'closed', 'person' => null );
	}

	// ---------- Approvazione e rifiuto ----------

	/**
	 * Approva una domanda: assegna la tessera, registra l'iscrizione all'anno sociale (e l'incasso, se la quota è stata pagata online) e
	 * manda il link per scegliere la password. Per un minore collega il genitore (registrandolo se non c'è) e scrive a lui.
	 *
	 * @return string messaggio per la segreteria
	 * @throws \InvalidArgumentException
	 */
	public static function approve( int $id, string $card = '' ): string {
		$row = self::row( $id );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Domanda non trovata: forse è già stata approvata o rifiutata.' );
		}
		$people = Plugin::people();
		$card   = trim( $card );
		if ( '' === $card ) {
			$card = $people->next_free_card();
		}
		if ( strlen( $card ) > 40 ) {
			throw new \InvalidArgumentException( 'Il numero di tessera è troppo lungo (massimo 40 caratteri).' );
		}
		$holder = $people->find_by_card( $card, $id );
		if ( $holder ) {
			throw new \InvalidArgumentException( 'La tessera ' . $card . ' è già assegnata a ' . $people->full_name( $holder ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$gd       = self::guardian_data( $row );
		$guardian = null;
		$created  = false;
		if ( $gd ) {
			$guardian = $people->find_by_email( strtolower( (string) $gd['email'] ), $id );
			if ( $guardian && ! empty( $guardian['application_status'] ) ) {
				throw new \InvalidArgumentException( 'Il genitore ha chiesto a sua volta di iscriversi con la stessa email: approva prima la sua domanda.' );
			}
		}
		$year = Settings::membership_year()->label();
		if ( $gd && ! $guardian ) {
			$guardian_id = self::register_guardian( $gd );
			$guardian    = $people->get( $guardian_id );
			$created     = true;
		}
		self::db()->update(
			Db::t( 'people' ),
			array( 'card_number' => $card, 'joined_on' => Db::today(), 'application_status' => null, 'application_data' => null, 'updated_at' => Db::now() ),
			array( 'id' => $id )
		);
		if ( ! Plugin::payments()->application_settle( $id, $year ) ) {
			$people->set_membership( $id, $year, true, 'application' );
		}
		if ( $guardian ) {
			Guardians::link( $id, (int) $guardian['id'] );
			// A nome del minore hanno accettato regolamento e privacy il genitore: resta registrato chi.
			self::db()->update( Db::t( 'people' ), array( 'rules_accepted_by' => Regulation::enabled() ? (int) $guardian['id'] : null, 'privacy_consent_by' => (int) $guardian['id'] ), array( 'id' => $id ) );
		}
		Audit::log( 'application.approved', 'person', $id, array( 'guardian' => $guardian ? (int) $guardian['id'] : 0 ) );
		self::mail_approved( $people->get( $id ), $guardian );
		$msg = 'Domanda approvata: tessera n. ' . $card . '. ' . ( $guardian ? 'È arrivata l\'email al genitore.' : 'Al nuovo socio è arrivato il link per scegliere la password.' );
		if ( $created ) {
			$msg .= ' Il genitore è stato registrato come socio ordinario, senza quota né tessera: puoi completarlo dalla sua scheda.';
		}
		return $msg;
	}

	/** Registra il genitore indicato nella domanda come persona (senza accesso: lo attiva dal link ricevuto). @return int id */
	private static function register_guardian( array $g ): int {
		$now = Db::now();
		self::db()->insert(
			Db::t( 'people' ),
			array(
				'type'       => MemberType::ORDINARY,
				'first_name' => mb_substr( (string) $g['first_name'], 0, 120 ),
				'last_name'  => mb_substr( (string) $g['last_name'], 0, 120 ),
				'email'      => strtolower( (string) $g['email'] ),
				'phone'      => (string) $g['phone'],
				'tax_code'   => '' === (string) ( $g['tax_code'] ?? '' ) ? null : (string) $g['tax_code'],
				'joined_on'  => Db::today(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) self::db()->insert_id;
		if ( $id <= 0 ) {
			throw new \InvalidArgumentException( 'Impossibile registrare il genitore (errore del database).' );
		}
		Privacy::set_consent( $id, 'web' );
		Audit::log( 'person.created', 'person', $id, array( 'type' => MemberType::ORDINARY, 'from' => 'application' ) );
		return $id;
	}

	private static function mail_approved( ?array $person, ?array $guardian ): void {
		if ( ! $person ) {
			return;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$of    = '' !== $assoc ? ' a ' . $assoc : '';
		$days  = Frontend\Activation::validity_text();
		if ( $guardian ) {
			$text = 'Ciao ' . $guardian['first_name'] . ",\n\nla domanda di adesione di " . $person['first_name'] . ' ' . $person['last_name'] . ' è stata approvata: la tessera è la n. ' . $person['card_number'] . ".\n\n";
			if ( empty( $guardian['wp_user_id'] ) ) {
				$text .= 'Da genitore o tutore segui ' . $person['first_name'] . ' dalla tua area riservata (tessera, prenotazioni, pagamenti). Scegli la tua password da questo link (vale ' . $days . "):\n" . Frontend\Activation::url( (int) $guardian['id'] ) . "\n";
			} else {
				$text .= $person['first_name'] . ' compare ora nella tua area riservata: ' . Gatekeeper::area_url() . "\n";
			}
			$to = (string) $guardian['email'];
		} else {
			$text = 'Ciao ' . $person['first_name'] . ",\n\nla tua domanda di adesione" . $of . ' è stata approvata: la tua tessera è la n. ' . $person['card_number'] . ".\n\nScegli la tua password da questo link (vale " . $days . "):\n" . Frontend\Activation::url( (int) $person['id'] )
				. "\n\nSe il link scade, dal sito vai su «Primo accesso» e ne ricevi uno nuovo.";
			$to = (string) $person['email'];
		}
		if ( '' !== $to && is_email( $to ) ) {
			Texts::mail( $to, 'Domanda di adesione approvata' . self::assoc_suffix(), $text );
		}
	}

	/**
	 * Rifiuta una domanda. Se la quota era stata pagata online il pagamento resta segnato «da rimborsare»; senza pagamento la domanda sparisce del tutto,
	 * senza lasciare dati personali. A chi l'ha inviata (per un minore, al genitore) arriva un breve messaggio.
	 *
	 * @return string messaggio per la segreteria
	 * @throws \InvalidArgumentException
	 */
	public static function reject( int $id ): string {
		$row = self::row( $id );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Domanda non trovata: forse è già stata approvata o rifiutata.' );
		}
		$gd     = self::guardian_data( $row );
		$to     = $gd ? (string) $gd['email'] : (string) $row['email'];
		$name   = $gd ? (string) $gd['first_name'] : (string) $row['first_name'];
		$refund = Plugin::payments()->application_refund_due( $id );
		if ( $refund > 0 ) { // il nome serve a riconoscere il pagamento da restituire; il resto si cancella
			self::db()->update(
				Db::t( 'people' ),
				array( 'application_status' => self::REJECTED, 'deleted_at' => Db::now(), 'tax_code' => null, 'phone' => null, 'birth_date' => null, 'application_data' => null, 'updated_at' => Db::now() ),
				array( 'id' => $id )
			);
		} else {
			self::db()->delete( Db::t( 'people' ), array( 'id' => $id ) );
		}
		Audit::log( 'application.rejected', 'person', $id, array( 'refund' => $refund ) );
		if ( '' !== $to && is_email( $to ) ) {
			$text = 'Ciao ' . $name . ",\n\npurtroppo la domanda di adesione" . ( $gd ? ' di ' . $row['first_name'] : '' ) . ' non è stata accettata.'
				. ( $refund > 0 ? "\n\nLa quota che hai pagato online ti sarà restituita." : '' ) . "\n\nPer chiarimenti rivolgiti alla segreteria.";
			Texts::mail( $to, 'Domanda di adesione' . self::assoc_suffix(), $text );
		}
		return 'Domanda rifiutata.' . ( $refund > 0 ? ' Il pagamento online risulta «da rimborsare» nei Pagamenti online.' : '' );
	}
}

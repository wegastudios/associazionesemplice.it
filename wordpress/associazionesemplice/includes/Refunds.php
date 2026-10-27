<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Rimborsi ai volontari (funzione di Associazione Semplice Pro): richiesta (dal volontario o dalla segreteria), approvazione, pagamento e
 * registrazione in prima nota come uscita «Rimborso spese socio/volontario». Le regole pure stanno in {@see RefundRules}.
 * Il giustificativo sta nella cartella privata degli allegati e lo aprono solo la segreteria e chi ha chiesto il rimborso.
 */
final class Refunds {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'admin_post_asem_refund_file', array( __CLASS__, 'handle_file' ) );
	}

	/** Chi gestisce i rimborsi: segreteria, presidente e vicepresidente, amministratori. */
	public static function can_manage(): bool {
		return Access::is_admin_user( get_current_user_id() );
	}

	public static function km_rate(): int {
		return max( 0, (int) Settings::get( 'refund_km_rate_cents' ) );
	}

	public static function self_approval_allowed(): bool {
		return ! empty( Settings::get( 'refund_self_approve' ) );
	}

	/** La persona collegata all'utente corrente (0 se non è un socio). */
	private static function actor_person_id(): int {
		$p = Access::person_for_user( get_current_user_id() );
		return $p ? (int) $p['id'] : 0;
	}

	// ---------- Richiesta ----------

	/**
	 * Registra una richiesta di rimborso.
	 *
	 * @param array      $in   kind, expense_date, description, amount | km, activity_id
	 * @param array|null $file giustificativo già controllato (Documents::prepare_upload)
	 * @throws \InvalidArgumentException
	 */
	public static function request( int $person_id, array $in, ?array $file = null, ?int $user_id = null ): int {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! MemberType::is_member( (string) $p['type'] ) || ! empty( $p['anonymized_at'] ) ) {
			throw new \InvalidArgumentException( 'Il rimborso si chiede per un socio o un volontario.' );
		}
		$kind = (string) ( $in['kind'] ?? RefundRules::DOCUMENTED );
		if ( ! isset( RefundRules::available_kinds( self::km_rate() )[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Tipo di rimborso non valido.' );
		}
		$date = (string) ( $in['expense_date'] ?? '' );
		$err  = RefundRules::check_expense_date( $date, Db::today() );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$desc = mb_substr( trim( sanitize_text_field( (string) ( $in['description'] ?? '' ) ) ), 0, 255 );
		if ( '' === $desc ) {
			throw new \InvalidArgumentException( 'Scrivi a cosa si riferisce la spesa.' );
		}
		$km = null;
		if ( RefundRules::KM === $kind ) {
			$km = (int) ( $in['km'] ?? 0 );
			if ( $km <= 0 ) {
				throw new \InvalidArgumentException( 'Indica i chilometri percorsi.' );
			}
			$cents = RefundRules::km_amount( $km, self::km_rate() );
		} else {
			$cents = Money::parse( (string) ( $in['amount'] ?? '' ) ) ?? 0;
		}
		list( $day, $month ) = RefundRules::SELFCERT === $kind ? self::selfcert_totals( $person_id, $date ) : array( 0, 0 );
		$err = RefundRules::amount_refusal( $kind, $cents, $day, $month );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( RefundRules::needs_receipt( $kind ) && ! $file ) {
			throw new \InvalidArgumentException( 'Allega lo scontrino o la fattura (PDF o foto).' );
		}
		$activity = (int) ( $in['activity_id'] ?? 0 );
		if ( $activity && ! Plugin::activities()->get( $activity ) ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		$row = array(
			'person_id'    => $person_id,
			'kind'         => $kind,
			'expense_date' => $date,
			'description'  => $desc,
			'activity_id'  => $activity ?: null,
			'km'           => $km,
			'amount_cents' => $cents,
			'status'       => RefundRules::REQUESTED,
			'requested_by' => $user_id,
			'created_at'   => Db::now(),
			'updated_at'   => Db::now(),
		);
		if ( $file ) {
			$row['file_name']   = $file['name'];
			$row['file_stored'] = Attachments::save_upload( $file );
		}
		if ( ! self::db()->insert( Db::t( 'refunds' ), $row ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la richiesta (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'refund.requested', 'refund', $id, array( 'kind' => $kind, 'cents' => $cents ) );
		return $id;
	}

	/** Autocertificazioni già chieste dalla persona nel giorno e nel mese della spesa (escluse quelle rifiutate o annullate). @return int[] [giorno, mese] */
	public static function selfcert_totals( int $person_id, string $date ): array {
		$db = self::db();
		$sql = 'SELECT COALESCE(SUM(CASE WHEN expense_date = %s THEN amount_cents ELSE 0 END),0) AS d, COALESCE(SUM(amount_cents),0) AS m FROM ' . Db::t( 'refunds' )
			. " WHERE person_id = %d AND kind = 'selfcert' AND status IN ('requested','approved','paid') AND expense_date BETWEEN %s AND %s";
		$r   = $db->get_row( $db->prepare( $sql, $date, $person_id, substr( $date, 0, 7 ) . '-01', gmdate( 'Y-m-t', strtotime( $date ) ) ), ARRAY_A );
		return array( (int) ( $r['d'] ?? 0 ), (int) ( $r['m'] ?? 0 ) );
	}

	// ---------- Lettura ----------

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT r.*, p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'refunds' ) . ' r LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = r.person_id WHERE r.id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** @return array[] dal più recente; $status vuoto = tutti */
	public static function all( string $status = '', int $person_id = 0 ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( isset( RefundRules::statuses()[ $status ] ) ) {
			$where[] = 'r.status = %s';
			$args[]  = $status;
		}
		if ( $person_id ) {
			$where[] = 'r.person_id = %d';
			$args[]  = $person_id;
		}
		$sql = 'SELECT r.*, p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'refunds' ) . ' r LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = r.person_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 500';
		return ( $args ? self::db()->get_results( self::db()->prepare( $sql, $args ), ARRAY_A ) : self::db()->get_results( $sql, ARRAY_A ) ) ?: array();
	}

	/** @return int[] stato => quante */
	public static function counts(): array {
		$out = array_fill_keys( array_keys( RefundRules::statuses() ), 0 );
		foreach ( self::db()->get_results( 'SELECT status, COUNT(*) AS n FROM ' . Db::t( 'refunds' ) . ' GROUP BY status', ARRAY_A ) ?: array() as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function person_name( array $r ): string {
		return ! empty( $r['anonymized_at'] ) ? 'Persona anonimizzata' : trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
	}

	// ---------- Decisioni ----------

	/** Rinnovo dello stato con controllo del passaggio e blocco contro le richieste simultanee. */
	private static function transition( int $id, string $to, callable $fn ) {
		return Db::with_lock( 'asem_refund_' . $id, function () use ( $id, $to, $fn ) {
			$r = self::get( $id );
			if ( ! $r ) {
				throw new \InvalidArgumentException( 'Richiesta non trovata.' );
			}
			if ( ! RefundRules::can_move( (string) $r['status'], $to ) ) {
				throw new \InvalidArgumentException( 'Questa operazione non è possibile: la richiesta è «' . mb_strtolower( RefundRules::statuses()[ $r['status'] ] ?? '' ) . '».' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			return $fn( $r );
		} );
	}

	private static function guard_self( array $r ): void {
		if ( RefundRules::self_approval_blocked( self::actor_person_id(), (int) $r['person_id'], self::self_approval_allowed() ) ) {
			throw new \InvalidArgumentException( 'Non puoi approvare o pagare un rimborso a tuo nome: lo fa un altro membro della segreteria o del direttivo (oppure attiva l\'opzione nelle impostazioni dei rimborsi).' );
		}
	}

	private static function notify( array $r, string $kind, string $subject, string $body ): void {
		$p = Plugin::people()->get( (int) $r['person_id'] );
		if ( $p ) {
			Reminders::notify( $p, $kind, (string) $r['id'], $subject, $body );
		}
	}

	private static function summary( array $r ): string {
		return '«' . $r['description'] . '» del ' . ( new \DateTimeImmutable( (string) $r['expense_date'] ) )->format( 'd/m/Y' ) . ', ' . Money::format( (int) $r['amount_cents'] );
	}

	/** @throws \InvalidArgumentException */
	public static function approve( int $id, ?int $user_id = null ): void {
		self::transition(
			$id,
			RefundRules::APPROVED,
			function ( array $r ) use ( $id, $user_id ) {
				self::guard_self( $r );
				self::db()->update( Db::t( 'refunds' ), array( 'status' => RefundRules::APPROVED, 'decided_by' => $user_id, 'decided_on' => Db::today(), 'reject_reason' => null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
				Audit::log( 'refund.approved', 'refund', $id );
				self::notify( $r, 'refund_approved', 'Rimborso approvato', 'la tua richiesta di rimborso ' . self::summary( $r ) . ' è stata approvata. Ti sarà pagato dalla segreteria.' );
			}
		);
	}

	/** @throws \InvalidArgumentException */
	public static function reject( int $id, string $reason, ?int $user_id = null ): void {
		$reason = mb_substr( trim( sanitize_text_field( $reason ) ), 0, 255 );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'Scrivi il motivo del rifiuto.' );
		}
		self::transition(
			$id,
			RefundRules::REJECTED,
			function ( array $r ) use ( $id, $user_id, $reason ) {
				self::guard_self( $r );
				self::db()->update( Db::t( 'refunds' ), array( 'status' => RefundRules::REJECTED, 'decided_by' => $user_id, 'decided_on' => Db::today(), 'reject_reason' => $reason, 'updated_at' => Db::now() ), array( 'id' => $id ) );
				Audit::log( 'refund.rejected', 'refund', $id );
				self::notify( $r, 'refund_rejected', 'Rimborso non accolto', 'la tua richiesta di rimborso ' . self::summary( $r ) . ' non è stata accolta. Motivo: ' . $reason );
			}
		);
	}

	/** Chi ha chiesto il rimborso lo ritira finché non è stato deciso; la segreteria può annullare una richiesta ancora da approvare. @throws \InvalidArgumentException */
	public static function cancel( int $id, int $by_person_id = 0 ): void {
		self::transition(
			$id,
			RefundRules::CANCELLED,
			function ( array $r ) use ( $id, $by_person_id ) {
				if ( $by_person_id && (int) $r['person_id'] !== $by_person_id ) {
					throw new \InvalidArgumentException( 'Puoi ritirare solo le tue richieste.' );
				}
				self::db()->update( Db::t( 'refunds' ), array( 'status' => RefundRules::CANCELLED, 'updated_at' => Db::now() ), array( 'id' => $id ) );
				Audit::log( 'refund.cancelled', 'refund', $id );
			}
		);
	}

	/**
	 * Paga il rimborso: registra l'uscita in prima nota dal conto indicato (categoria «Rimborso spese socio/volontario») e collega il movimento.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function pay( int $id, int $account_id, string $date, ?int $user_id = null ): int {
		return (int) self::transition(
			$id,
			RefundRules::PAID,
			function ( array $r ) use ( $id, $account_id, $date, $user_id ) {
				self::guard_self( $r );
				$ledger = Plugin::ledger();
				$cat    = $ledger->category_id_of_kind( 'member_reimbursement' );
				if ( ! $cat ) {
					throw new \InvalidArgumentException( 'In prima nota manca la categoria «Rimborso spese socio/volontario»: creala dalle categorie.' );
				}
				$date = '' === $date ? Db::today() : $date;
				$tx   = $ledger->record_expense(
					array(
						'date'         => $date,
						'account_id'   => $account_id,
						'method'       => '',
						'category_id'  => $cat,
						'amount_cents' => (int) $r['amount_cents'],
						'activity_id'  => $r['activity_id'] ?: null,
						'person_id'    => (int) $r['person_id'],
						'description'  => mb_substr( 'Rimborso n. ' . $id . ': ' . $r['description'], 0, 255 ),
						'document_ref' => 'RIMB-' . $id,
					)
				);
				$ok = self::db()->update( Db::t( 'refunds' ), array( 'status' => RefundRules::PAID, 'paid_on' => $date, 'paid_by' => $user_id, 'account_id' => $account_id, 'tx_id' => $tx, 'updated_at' => Db::now() ), array( 'id' => $id ) );
				if ( false === $ok ) {
					$ledger->void( $tx, 'Rimborso n. ' . $id . ' non registrato' );
					throw new \InvalidArgumentException( 'Il pagamento non è stato registrato: riprova.' );
				}
				Audit::log( 'refund.paid', 'refund', $id, array( 'tx' => $tx, 'cents' => (int) $r['amount_cents'] ) );
				self::notify( $r, 'refund_paid', 'Rimborso pagato', 'il rimborso ' . self::summary( $r ) . ' è stato pagato il ' . ( new \DateTimeImmutable( $date ) )->format( 'd/m/Y' ) . '.' );
				return $tx;
			}
		);
	}

	// ---------- File ----------

	public static function file_url( int $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_refund_file', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'asem_refund_file_' . $id );
	}

	public static function handle_file(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! Edition::has( 'refunds' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_refund_file_' . $id );
		$r = self::get( $id );
		if ( ! $r || ! ( self::can_manage() || ( self::actor_person_id() && (int) $r['person_id'] === self::actor_person_id() ) ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$path = ! empty( $r['file_stored'] ) ? Attachments::dir() . '/' . AttachmentRules::stored_name( (string) $r['file_stored'] ) : '';
		if ( '' === $path || '' === basename( $path ) || ! is_readable( $path ) ) {
			wp_die( 'File non trovato.', 404 );
		}
		$ext = AttachmentRules::extension( (string) $r['file_name'] );
		Attachments::stream( $path, $ext, 'rimborso-' . (int) $id . ( '' !== $ext ? '.' . $ext : '' ) );
	}

	// ---------- Esportazione e privacy ----------

	public static function csv( string $status = '' ): string {
		$kinds  = RefundRules::kinds();
		$labels = RefundRules::statuses();
		$rows   = array();
		foreach ( self::all( $status ) as $r ) {
			$rows[] = array( $r['id'], self::person_name( $r ), $r['expense_date'], $kinds[ $r['kind'] ] ?? '', $r['description'], null === $r['km'] ? '' : $r['km'], Money::plain( (int) $r['amount_cents'] ), $labels[ $r['status'] ] ?? '', $r['paid_on'] ?? '', $r['tx_id'] ?? '' );
		}
		return Docs::csv(
			array( array( 'label' => 'N.' ), array( 'label' => 'Volontario' ), array( 'label' => 'Data spesa' ), array( 'label' => 'Tipo' ), array( 'label' => 'Descrizione' ), array( 'label' => 'Km' ), array( 'label' => 'Importo' ), array( 'label' => 'Stato' ), array( 'label' => 'Pagato il' ), array( 'label' => 'Movimento di prima nota' ) ),
			$rows
		);
	}

	public static function privacy_export( array $data, int $person_id ): array {
		$out = array();
		foreach ( self::all( '', $person_id ) as $r ) {
			$out[] = array( 'data_spesa' => $r['expense_date'], 'descrizione' => $r['description'], 'importo_euro' => Money::plain( (int) $r['amount_cents'] ), 'stato' => RefundRules::statuses()[ $r['status'] ] ?? '', 'pagato_il' => $r['paid_on'] );
		}
		$data['rimborsi_spese'] = $out;
		return $data;
	}

	/** La persona è anonimizzata: i giustificativi si cancellano; richieste e importi restano perché collegati alla prima nota. */
	public static function anonymize( int $person_id ): void {
		$db = self::db();
		foreach ( $db->get_results( $db->prepare( 'SELECT id, file_stored FROM ' . Db::t( 'refunds' ) . ' WHERE person_id = %d', $person_id ), ARRAY_A ) ?: array() as $r ) {
			if ( ! empty( $r['file_stored'] ) ) {
				Attachments::delete_stored( (string) $r['file_stored'] );
			}
		}
		$db->query( $db->prepare( 'UPDATE ' . Db::t( 'refunds' ) . " SET file_name = NULL, file_stored = NULL, description = 'Dato cancellato' WHERE person_id = %d", $person_id ) );
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ricevute di pagamento e attestazioni annuali dei versamenti, in PDF.
 *
 * Una ricevuta è un incasso (tutte le righe con lo stesso `receipt_id`; per i movimenti vecchi senza codice, la singola riga).
 * Il numero progressivo (N/AAAA, per anno fiscale dell'ente) si assegna alla prima emissione e non cambia più, nemmeno dopo un annullo.
 * Alla prima emissione i dati si fotografano (colonna `data`): la ricevuta emessa si rigenera sempre uguale, anche se poi cambiano il nome, il codice
 * fiscale o le impostazioni. Un incasso annullato non ha ricevuta: l'annullo resta scritto sulla riga (mai cancellata). Un incasso di un esercizio
 * chiuso non riceve un numero nuovo.
 * Le vede chi amministra e chi ha pagato (o il socio che ospita chi ha pagato).
 */
final class Receipts {

	const MONTHS = array( 1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' );

	public static function register(): void {
		foreach ( array( 'asem_receipt' => 'handle_receipt', 'asem_statement' => 'handle_statement' ) as $a => $m ) {
			add_action( 'admin_post_' . $a, array( __CLASS__, $m ) );
			add_action( 'admin_post_nopriv_' . $a, Csrf::nopriv( array( __CLASS__, $m ), array( Csrf::class, 'to_login' ) ) );
		}
		add_action( 'asem_tx_voided', array( __CLASS__, 'on_void' ), 10, 2 ); // l'annullo di un incasso annulla anche la ricevuta già emessa (resta traccia, non si cancella)
	}

	// ---------- Dati ----------

	public static function clean_key( string $key ): string {
		return (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $key );
	}

	/** Codice di una ricevuta a partire da una riga di movimento. */
	public static function key_of( array $tx ): string {
		return ! empty( $tx['receipt_id'] ) ? (string) $tx['receipt_id'] : 'tx' . (int) $tx['id'];
	}

	/** Righe di incasso (non annullate) di una ricevuta, con voce, attività, data dell'evento e beneficiario. */
	public static function rows( string $key ): array {
		$key = self::clean_key( $key );
		if ( '' === $key ) {
			return array();
		}
		$db  = Db::db();
		$sql = 'SELECT t.*, c.name AS category_name, c.kind AS category_kind, a.name AS activity_name, s.session_date, bp.first_name AS b_first, bp.last_name AS b_last '
			. 'FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'LEFT JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id LEFT JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = t.session_id '
			. 'LEFT JOIN ' . Db::t( 'people' ) . " bp ON bp.id = t.person_id WHERE t.type = 'income' AND t.voided_at IS NULL AND ";
		if ( preg_match( '/^tx(\d+)$/', $key, $m ) ) {
			$sql = $db->prepare( $sql . 't.id = %d AND t.receipt_id IS NULL ORDER BY t.id', (int) $m[1] );
		} else {
			$sql = $db->prepare( $sql . 't.receipt_id = %s ORDER BY t.id', $key );
		}
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}

	/** Chi ha pagato (persona), o null se l'incasso non è intestato a nessuno. */
	public static function payer( array $rows ): ?array {
		foreach ( $rows as $r ) {
			$pid = (int) ( $r['payer_person_id'] ?: $r['person_id'] );
			if ( $pid ) {
				return Plugin::people()->get( $pid );
			}
		}
		return null;
	}

	/** Ricevute di una persona (come chi ha pagato), dalla più recente. @return array[] key, date, cents, what */
	public static function list_for_payer( int $person_id, int $limit = 30 ): array {
		$db   = Db::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT t.id, t.tx_date, t.amount_cents, t.receipt_id, c.name AS category_name FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
				. "WHERE t.type = 'income' AND t.voided_at IS NULL AND COALESCE(t.payer_person_id, t.person_id) = %d ORDER BY t.tx_date DESC, t.id DESC LIMIT 500",
				$person_id
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$k = self::key_of( $r );
			if ( ! isset( $out[ $k ] ) ) {
				if ( count( $out ) >= $limit ) {
					continue;
				}
				$out[ $k ] = array( 'key' => $k, 'date' => $r['tx_date'], 'cents' => 0, 'what' => array() );
			}
			$out[ $k ]['cents'] += (int) $r['amount_cents'];
			$out[ $k ]['what'][ $r['category_name'] ] = true;
		}
		foreach ( $out as &$o ) {
			$o['what'] = implode( ', ', array_keys( $o['what'] ) );
		}
		unset( $o );
		return array_values( $out );
	}

	/** Anni (solari) in cui una persona ha versato qualcosa, dal più recente. @return int[] */
	public static function years_for_payer( int $person_id ): array {
		$db = Db::db();
		return array_map(
			'intval',
			$db->get_col(
				$db->prepare(
					'SELECT DISTINCT YEAR(tx_date) AS y FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND COALESCE(payer_person_id, person_id) = %d ORDER BY y DESC",
					$person_id
				)
			) ?: array()
		);
	}

	// ---------- Permessi e indirizzi ----------

	public static function can_view( string $key ): bool {
		$rows = self::rows( $key );
		if ( ! $rows ) {
			return false;
		}
		if ( current_user_can( Plugin::CAP_OPS ) ) {
			return true;
		}
		$payer = self::payer( $rows );
		return $payer && current_user_can( 'asem_view_payments', (int) $payer['id'] ); // solo l'interessato o il suo tutore: l'ospitante non vede codice fiscale e pagamenti di un ospite
	}

	public static function can_statement( int $person_id ): bool {
		return $person_id > 0 && ( current_user_can( Plugin::CAP_OPS ) || current_user_can( 'asem_view_payments', $person_id ) );
	}

	public static function url( string $key ): string {
		$key = self::clean_key( $key );
		return Csrf::url( add_query_arg( array( 'action' => 'asem_receipt', 'key' => $key ), admin_url( 'admin-post.php' ) ), 'asem_receipt_' . $key );
	}

	public static function statement_url( int $person_id, int $year ): string {
		return Csrf::url( add_query_arg( array( 'action' => 'asem_statement', 'person' => $person_id, 'year' => $year ), admin_url( 'admin-post.php' ) ), 'asem_statement_' . $person_id . '_' . $year );
	}

	// ---------- Numerazione ----------

	/** La riga della ricevuta emessa (numero, dati fotografati, eventuale annullo), oppure null se non è mai stata emessa. */
	public static function record( string $key ): ?array {
		$db  = Db::db();
		$row = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::t( 'receipts' ) . ' WHERE receipt_key = %s', $key ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Segna come annullata la ricevuta emessa di un incasso. La riga e il numero restano: è un annullamento tracciato, non una cancellazione.
	 *
	 * @return bool true se la ricevuta c'era e non era già annullata
	 */
	public static function mark_voided( string $key, string $reason = '' ): bool {
		$db = Db::db();
		$n  = $db->query(
			$db->prepare(
				'UPDATE ' . Db::t( 'receipts' ) . ' SET voided_at = %s, voided_by = %d, void_reason = %s WHERE receipt_key = %s AND voided_at IS NULL',
				Db::now(),
				get_current_user_id(),
				mb_substr( '' === trim( $reason ) ? 'Incasso annullato' : trim( $reason ), 0, 255 ),
				$key
			)
		);
		if ( $n ) {
			$rec = self::record( $key );
			Audit::log( 'receipt.voided', 'receipt', $rec ? (int) $rec['id'] : 0, array( 'number' => $rec ? $rec['number'] . '/' . $rec['year'] : '' ) );
		}
		return (bool) $n;
	}

	/** Azione `asem_tx_voided`: la ricevuta emessa per l'incasso annullato risulta annullata. */
	public static function on_void( int $tx_id, string $reason = '' ): void {
		$db  = Db::db();
		$rid = $db->get_var( $db->prepare( 'SELECT receipt_id FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx_id ) );
		self::mark_voided( $rid ? (string) $rid : 'tx' . $tx_id, $reason );
	}

	/**
	 * Numero progressivo della ricevuta: si assegna alla prima emissione, per ANNO FISCALE dell'ente (con inizio a gennaio coincide con l'anno
	 * solare), sotto blocco, e non si riassegna mai (la riga non si cancella). Per un incasso di un esercizio chiuso non si emette un numero
	 * nuovo: finirebbe dentro un anno chiuso. Una ricevuta già emessa si ritrova sempre.
	 *
	 * @return array{number:int,year:int}
	 * @throws \InvalidArgumentException
	 */
	public static function number_for( string $key, string $date ): array {
		$db   = Db::db();
		$tbl  = Db::t( 'receipts' );
		$have = $db->get_row( $db->prepare( "SELECT number, year FROM $tbl WHERE receipt_key = %s", $key ), ARRAY_A );
		if ( $have ) {
			return array( 'number' => (int) $have['number'], 'year' => (int) $have['year'] );
		}
		YearLock::assert_date_open( $date );
		$year = FiscalYears::year_of( $date );
		$lock = (int) $db->get_var( "SELECT GET_LOCK('asem_receipt_number', 5)" );
		try {
			$have = $db->get_row( $db->prepare( "SELECT number, year FROM $tbl WHERE receipt_key = %s", $key ), ARRAY_A );
			if ( $have ) {
				return array( 'number' => (int) $have['number'], 'year' => (int) $have['year'] );
			}
			$next = 1 + (int) $db->get_var( $db->prepare( "SELECT MAX(number) FROM $tbl WHERE year = %d", $year ) );
			if ( ! $db->insert( $tbl, array( 'receipt_key' => $key, 'year' => $year, 'number' => $next, 'issued_at' => Db::now(), 'issued_by' => get_current_user_id() ?: null ) ) ) {
				throw new \RuntimeException( 'Impossibile assegnare il numero della ricevuta.' );
			}
			Audit::log( 'receipt.issued', 'receipt', (int) $db->insert_id, array( 'number' => $next . '/' . $year ) );
			return array( 'number' => $next, 'year' => $year );
		} finally {
			if ( $lock ) {
				$db->get_var( "SELECT RELEASE_LOCK('asem_receipt_number')" );
			}
		}
	}

	// ---------- Testi ----------

	private static function d( string $ymd ): string {
		return ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' );
	}

	private static function month_label( string $ym ): string {
		return ( self::MONTHS[ (int) substr( $ym, 5, 2 ) ] ?? '' ) . ' ' . substr( $ym, 0, 4 );
	}

	/** Descrizione di una riga di incasso, per chi legge la ricevuta. */
	public static function describe( array $r, ?array $payer ): string {
		switch ( $r['category_kind'] ) {
			case 'membership':
				$what = 'Quota associativa' . ( $r['social_year'] ? ' ' . $r['social_year'] : '' );
				break;
			case 'activity_fee':
				if ( ! empty( $r['session_date'] ) ) {
					$what = 'Contributo evento: ' . $r['activity_name'] . ' (' . self::d( $r['session_date'] ) . ')';
				} elseif ( ! empty( $r['competence_month'] ) ) {
					$what = 'Quota corso: ' . $r['activity_name'] . ' — ' . self::month_label( $r['competence_month'] );
				} else {
					$what = 'Contributo: ' . ( $r['activity_name'] ?: $r['category_name'] );
				}
				break;
			default:
				$what = $r['category_name'] . ( in_array( $r['category_kind'], array( 'donation', 'other_income' ), true ) && '' !== trim( (string) $r['description'] ) ? ' — ' . $r['description'] : '' );
		}
		$ben = (int) $r['person_id'];
		if ( $ben && $payer && $ben !== (int) $payer['id'] && ! empty( $r['b_first'] ) ) {
			$what .= ' — per ' . trim( $r['b_first'] . ' ' . $r['b_last'] );
		}
		return $what;
	}

	private static function president_name(): string {
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				return trim( $b['first_name'] . ' ' . $b['last_name'] );
			}
		}
		return '';
	}

	/** Imponibile e IVA delle righe di una ricevuta, per aliquota (solo le righe con IVA). @return array<int,array{net:int,vat:int}> */
	public static function vat_breakdown( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( null === ( $r['vat_rate'] ?? null ) || '' === $r['vat_rate'] || (int) ( $r['vat_cents'] ?? 0 ) <= 0 ) {
				continue;
			}
			$rate = (int) $r['vat_rate'];
			$out[ $rate ] = $out[ $rate ] ?? array( 'net' => 0, 'vat' => 0 );
			$out[ $rate ]['vat'] += (int) $r['vat_cents'];
			$out[ $rate ]['net'] += (int) $r['amount_cents'] - (int) $r['vat_cents'];
		}
		ksort( $out );
		return $out;
	}

	private static function header( Pdf $pdf ): float {
		return self::header_with( $pdf, (string) Settings::get( 'association_name' ), (string) Settings::get( 'tax_code' ), Fiscal::vat_applies() ? (string) Settings::get( 'vat_number' ) : '' );
	}

	private static function header_with( Pdf $pdf, string $name, string $cf, string $vat ): float {
		$pdf->text( 50, 62, '' !== $name ? $name : 'Associazione', 17, true );
		if ( '' !== $cf || '' !== $vat ) {
			$pdf->text( 50, 80, trim( ( '' !== $cf ? 'Codice fiscale: ' . $cf : '' ) . ( '' !== $cf && '' !== $vat ? ' · ' : '' ) . ( '' !== $vat ? 'Partita IVA: ' . $vat : '' ) ), 10 );
		}
		$pdf->line( 50, 92, Pdf::W - 50, 92, 1.2 );
		return 118;
	}

	private static function footer( Pdf $pdf, float $y ): void {
		self::footer_with( $pdf, $y, (string) Settings::get( 'receipt_footer' ) );
	}

	private static function footer_with( Pdf $pdf, float $y, string $foot ): void {
		if ( '' !== $foot ) {
			foreach ( Pdf::wrap( $foot, Pdf::W - 100, 9 ) as $line ) {
				$pdf->text( 50, $y, $line, 9 );
				$y += 12;
			}
		}
	}

	// ---------- Ricevuta ----------

	/** Metodi di pagamento tracciabili (valgono per la detrazione); contanti e «altro» no. */
	const TRACKED_METHODS = array( 'bank_transfer', 'pos', 'check', 'stripe', 'paypal', 'sumup', 'woocommerce' );

	/**
	 * La ricevuta di un minore va intestata a un genitore: chi opera sul sito (o chi ha pagato, se è un genitore), con i codici fiscali di tutti i genitori
	 * e, per i ragazzi dai 5 ai 18 anni, l'indicazione se il pagamento è tracciato (serve per la detrazione delle spese sportive).
	 *
	 * @param array|null $payer     chi ha pagato
	 * @param array|null $ward      a chi si riferisce il pagamento
	 * @param array      $guardians i genitori o tutori del minore (con is_operative)
	 * @param string     $method_label  come si chiama il metodo di pagamento
	 * @param string[]   $kinds     tipi di voce della ricevuta (category_kind)
	 * @return array{holder:?array,lines:string[],note:string}
	 */
	public static function minor_block( ?array $payer, ?array $ward, array $guardians, string $date, string $method, string $method_label, array $kinds ): array {
		$none = array( 'holder' => $payer, 'lines' => array(), 'note' => '' );
		if ( ! $ward || ! $guardians || ! Age::is_minor( $ward['birth_date'] ?? null, $date ) ) {
			return $none;
		}
		$holder = $guardians[0];
		foreach ( $guardians as $g ) {
			if ( ! empty( $g['is_operative'] ) ) {
				$holder = $g;
				break;
			}
		}
		if ( $payer ) {
			foreach ( $guardians as $g ) {
				if ( (int) $g['id'] === (int) $payer['id'] ) {
					$holder = $g; // ha pagato l'altro genitore: la ricevuta è sua
				}
			}
		}
		$lines = array();
		foreach ( $guardians as $g ) {
			if ( (int) $g['id'] !== (int) $holder['id'] ) {
				$lines[] = 'Altro genitore o tutore: ' . trim( $g['first_name'] . ' ' . $g['last_name'] ) . ' — C.F. ' . ( ! empty( $g['tax_code'] ) ? $g['tax_code'] : 'non indicato' );
			}
		}
		$lines[] = 'Per conto di ' . trim( $ward['first_name'] . ' ' . $ward['last_name'] ) . ' — C.F. ' . ( ! empty( $ward['tax_code'] ) ? $ward['tax_code'] : 'non indicato' ) . ', nato/a il ' . Age::display( $ward['birth_date'] );
		$note  = '';
		$years = Age::years( $ward['birth_date'], $date );
		if ( null !== $years && $years >= 5 && $years <= 18 && array_intersect( $kinds, array( 'activity_fee', 'membership' ) ) ) {
			$note = in_array( $method, self::TRACKED_METHODS, true )
				? 'Pagamento tracciato (' . $method_label . '): idoneo alla detrazione delle spese sportive per ragazzi dai 5 ai 18 anni (art. 15, c. 1, lett. i-quinquies, TUIR).'
				: 'Pagamento non tracciato (' . $method_label . '): non consente la detrazione delle spese sportive per ragazzi dai 5 ai 18 anni.';
		}
		return array( 'holder' => $holder, 'lines' => $lines, 'note' => $note );
	}

	/**
	 * Fotografa i dati di una ricevuta al momento dell'emissione: da qui in poi il PDF si disegna solo da questi, non dai dati vivi.
	 *
	 * @param array[] $rows righe di incasso (vedi {@see Receipts::rows()})
	 * @param array   $num  numero e anno (vedi {@see Receipts::number_for()})
	 */
	private static function capture( array $rows, array $num ): array {
		$payer   = self::payer( $rows );
		$date    = (string) $rows[0]['tx_date'];
		$kinds   = array_values( array_unique( array_column( $rows, 'category_kind' ) ) );
		$method  = (string) $rows[0]['method'];
		$mlabel  = Labels::methods()[ $method ] ?? $method;
		$ward_id = (int) $rows[0]['person_id'];
		$ward    = $ward_id ? Plugin::people()->get( $ward_id ) : $payer;
		$minor   = self::minor_block( $payer, $ward, $ward ? Guardians::of( (int) $ward['id'] ) : array(), $date, $method, $mlabel, $kinds );
		$holder  = $minor['holder'];
		$lines   = array();
		$total   = 0;
		foreach ( $rows as $r ) {
			$total   += (int) $r['amount_cents'];
			$lines[] = array( 'what' => self::describe( $r, $payer ), 'cents' => (int) $r['amount_cents'] );
		}
		$vat_rows = array();
		foreach ( self::vat_breakdown( $rows ) as $rate => $b ) {
			$vat_rows[] = array( 'rate' => (int) $rate, 'net' => (int) $b['net'], 'vat' => (int) $b['vat'] );
		}
		return array(
			'v'          => 1,
			'number'     => (int) $num['number'],
			'year'       => (int) $num['year'],
			'date'       => $date,
			'title'      => array( 'donation' ) === $kinds ? 'RICEVUTA DI EROGAZIONE LIBERALE' : 'RICEVUTA DI PAGAMENTO',
			'assoc_name' => (string) Settings::get( 'association_name' ),
			'assoc_cf'   => (string) Settings::get( 'tax_code' ),
			'assoc_vat'  => Fiscal::vat_applies() ? (string) Settings::get( 'vat_number' ) : '',
			'holder'     => $holder ? array( 'name' => Plugin::people()->full_name( $holder ), 'tax_code' => (string) ( $holder['tax_code'] ?? '' ) ) : null,
			'extra'      => array_values( $minor['lines'] ),
			'note'       => (string) $minor['note'],
			'lines'      => $lines,
			'total'      => $total,
			'vat_rows'   => $vat_rows,
			'method'     => $mlabel,
			'ref'        => (string) ( $rows[0]['document_ref'] ?? '' ),
			'president'  => self::president_name(),
			'footer'     => (string) Settings::get( 'receipt_footer' ),
			'payer_id'   => $payer ? (int) $payer['id'] : 0,
			'payer_last' => $payer ? (string) $payer['last_name'] : '',
			'tx_ids'     => array_map( 'intval', array_column( $rows, 'id' ) ),
		);
	}

	/**
	 * Le ricevute emesse sono immutabili: i dati fotografati alla prima emissione (colonna `data`) e il loro hash. Una ricevuta emessa prima che
	 * esistesse la fotografia si fotografa alla prima lettura (con i dati di quel momento).
	 *
	 * @return array{0:array,1:string} dati e hash
	 */
	private static function issued( string $key, array $rows ): array {
		$db  = Db::db();
		$tbl = Db::t( 'receipts' );
		$rec = self::record( $key );
		if ( $rec && null !== $rec['voided_at'] ) {
			throw new \InvalidArgumentException( 'Ricevuta annullata: l\'incasso è stato annullato.' );
		}
		if ( $rec && ! empty( $rec['data'] ) ) {
			$data = json_decode( (string) $rec['data'], true );
			if ( is_array( $data ) && isset( $data['tx_ids'] ) ) {
				$live = array_map( 'intval', array_column( $rows, 'id' ) );
				$old  = array_map( 'intval', $data['tx_ids'] );
				sort( $live );
				sort( $old );
				if ( $live !== $old ) { // una delle righe è stata annullata dopo l'emissione: la ricevuta emessa non vale più
					self::mark_voided( $key, 'Incasso annullato' );
					throw new \InvalidArgumentException( 'Ricevuta annullata: l\'incasso è stato annullato.' );
				}
				return array( $data, (string) $rec['content_hash'] );
			}
		}
		$num  = self::number_for( $key, (string) $rows[0]['tx_date'] ); // se è nuova assegna il numero (un esercizio chiuso non ne riceve di nuovi)
		$data = self::capture( $rows, $num );
		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		$hash = hash( 'sha256', $json );
		$db->query( $db->prepare( "UPDATE $tbl SET data = %s, content_hash = %s WHERE receipt_key = %s AND (data IS NULL OR data = '')", $json, $hash, $key ) );
		$rec = self::record( $key );
		if ( $rec && ! empty( $rec['data'] ) && is_array( json_decode( (string) $rec['data'], true ) ) ) { // se un'altra richiesta è arrivata prima, vale la sua fotografia
			return array( json_decode( (string) $rec['data'], true ), (string) $rec['content_hash'] );
		}
		return array( $data, $hash );
	}

	/** @return array pdf (byte), filename, number (stringa N/AAAA), payer (persona o null), hash (della fotografia)
	 *  @throws \InvalidArgumentException */
	public static function build( string $key ): array {
		$key  = self::clean_key( $key );
		$rows = self::rows( $key );
		if ( ! $rows ) {
			self::mark_voided( $key, 'Incasso annullato' ); // se la ricevuta era stata emessa, l'annullo resta scritto
			throw new \InvalidArgumentException( 'Ricevuta non trovata (o incasso annullato).' );
		}
		list( $s, $hash ) = self::issued( $key, $rows );
		$label            = $s['number'] . '/' . $s['year'];
		$payer            = $s['payer_id'] ? Plugin::people()->get( (int) $s['payer_id'] ) : null;
		$who              = '' !== (string) $s['payer_last'] ? Text::normalize( $s['payer_last'] ) : 'ricevuta';
		return array( 'pdf' => self::render( $s ), 'filename' => 'ricevuta-' . $s['number'] . '-' . $s['year'] . '-' . preg_replace( '/[^a-z0-9]/', '', $who ) . '.pdf', 'number' => $label, 'payer' => $payer, 'hash' => $hash );
	}

	/** Disegna il PDF della ricevuta dai soli dati fotografati. */
	private static function render( array $s ): string {
		$label  = $s['number'] . '/' . $s['year'];
		$date   = (string) $s['date'];
		Pdf::$filter = array( Texts::class, 'plain' ); // testi personalizzati nel PDF
		$pdf    = new Pdf();
		$y      = self::header_with( $pdf, (string) $s['assoc_name'], (string) $s['assoc_cf'], (string) $s['assoc_vat'] );
		$pdf->text( 50, $y, (string) $s['title'], 14, true );
		$pdf->text( Pdf::W - 50, $y, 'N. ' . $label, 14, true, 'R' );
		$y += 20;
		$pdf->text( 50, $y, 'Data: ' . self::d( $date ), 11 );
		$y += 30;
		$pdf->text( 50, $y, 'Ricevuto da', 9 );
		$y += 15;
		$holder = $s['holder'];
		if ( $holder ) {
			$pdf->text( 50, $y, (string) $holder['name'], 12, true );
			$y += 15;
			if ( '' !== (string) $holder['tax_code'] ) {
				$pdf->text( 50, $y, 'Codice fiscale: ' . $holder['tax_code'], 10 );
				$y += 14;
			}
			foreach ( $s['extra'] as $line ) {
				foreach ( Pdf::wrap( $line, Pdf::W - 100, 10 ) as $l ) {
					$pdf->text( 50, $y, $l, 10 );
					$y += 14;
				}
			}
		} else {
			$pdf->text( 50, $y, '—', 12 );
			$y += 15;
		}
		$y += 14;
		$pdf->rect( 50, $y - 12, Pdf::W - 100, 20 );
		$pdf->text( 56, $y + 2, 'Descrizione', 10, true );
		$pdf->text( Pdf::W - 56, $y + 2, 'Importo', 10, true, 'R' );
		$y    += 24;
		$total = (int) $s['total'];
		foreach ( $s['lines'] as $r ) {
			$cents = (int) $r['cents'];
			$lines = Pdf::wrap( (string) $r['what'], Pdf::W - 100 - 110, 11 );
			foreach ( $lines as $i => $line ) {
				$pdf->text( 56, $y, $line, 11 );
				if ( 0 === $i ) {
					$pdf->text( Pdf::W - 56, $y, Money::format( $cents ), 11, false, 'R' );
				}
				$y += 15;
			}
			$y += 3;
		}
		$pdf->line( 50, $y - 6, Pdf::W - 50, $y - 6 );
		$y += 8;
		$pdf->text( 56, $y, 'Totale', 12, true );
		$pdf->text( Pdf::W - 56, $y, Money::format( $total ), 12, true, 'R' );
		$y += 18;
		foreach ( $s['vat_rows'] as $b ) { // imponibile e IVA contenuta, per aliquota
			$pdf->text( 56, $y, 'di cui imponibile ' . $b['rate'] . '%: ' . Money::format( (int) $b['net'] ) . ' · IVA: ' . Money::format( (int) $b['vat'] ), 10 );
			$y += 14;
		}
		$y += 6;
		$pdf->text( 56, $y, 'Pagato con: ' . $s['method'], 10 );
		if ( '' !== (string) $s['ref'] ) {
			$y += 14;
			$pdf->text( 56, $y, 'Riferimento: ' . $s['ref'], 10 );
		}
		if ( '' !== (string) $s['note'] ) {
			foreach ( Pdf::wrap( (string) $s['note'], Pdf::W - 112, 10 ) as $l ) {
				$y += 14;
				$pdf->text( 56, $y, $l, 10 );
			}
		}
		$y += 60;
		$pdf->text( Pdf::W - 60, $y, 'Per l\'associazione', 10, false, 'R' );
		$pres = (string) $s['president'];
		$y   += 34;
		$pdf->line( Pdf::W - 230, $y, Pdf::W - 60, $y );
		if ( '' !== $pres ) {
			$pdf->text( Pdf::W - 60, $y + 13, 'Il Presidente ' . $pres, 9, false, 'R' );
		}
		self::footer_with( $pdf, 770, (string) $s['footer'] );
		$bytes       = $pdf->output();
		Pdf::$filter = null;
		return $bytes;
	}

	// ---------- Attestazione annuale ----------

	/** @return array pdf, filename, total_cents
	 *  @throws \InvalidArgumentException */
	public static function statement( int $person_id, int $year ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$db   = Db::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT t.*, c.name AS category_name, c.kind AS category_kind, a.name AS activity_name, s.session_date, bp.first_name AS b_first, bp.last_name AS b_last '
				. 'FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id LEFT JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id '
				. 'LEFT JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = t.session_id LEFT JOIN ' . Db::t( 'people' ) . ' bp ON bp.id = t.person_id '
				. "WHERE t.type = 'income' AND t.voided_at IS NULL AND COALESCE(t.payer_person_id, t.person_id) = %d AND YEAR(t.tx_date) = %d ORDER BY t.tx_date, t.id",
				$person_id,
				$year
			),
			ARRAY_A
		) ?: array();
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'Nessun versamento nel ' . $year . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$groups = array( 'membership' => 'Quote associative', 'activity_fee' => 'Contributi per attività ed eventi', 'donation' => 'Erogazioni liberali' );
		$sums   = array();
		$total  = 0;
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf    = new Pdf();
		$y      = self::header( $pdf );
		$pdf->text( 50, $y, 'ATTESTAZIONE DEI VERSAMENTI — ANNO ' . $year, 14, true );
		$y += 24;
		$pdf->text( 50, $y, 'Si attesta che ' . Plugin::people()->full_name( $person ) . ( ! empty( $person['tax_code'] ) ? ' (C.F. ' . $person['tax_code'] . ')' : '' ) . ' ha versato nel ' . $year . ':', 11 );
		$y += 28;
		$head = function ( Pdf $pdf, float $y ) {
			$pdf->rect( 50, $y - 12, Pdf::W - 100, 20 );
			$pdf->text( 56, $y + 2, 'Data', 10, true );
			$pdf->text( 120, $y + 2, 'Descrizione', 10, true );
			$pdf->text( Pdf::W - 56, $y + 2, 'Importo', 10, true, 'R' );
			return $y + 24;
		};
		$y = $head( $pdf, $y );
		foreach ( $rows as $r ) {
			if ( $y > 740 ) {
				self::footer( $pdf, 800 );
				$pdf->add_page();
				$y = $head( $pdf, 70 );
			}
			$cents = (int) $r['amount_cents'];
			$total += $cents;
			$g = $groups[ $r['category_kind'] ] ?? 'Altri versamenti';
			$sums[ $g ] = ( $sums[ $g ] ?? 0 ) + $cents;
			$lines = Pdf::wrap( self::describe( $r, $person ), Pdf::W - 120 - 110, 10 );
			foreach ( $lines as $i => $line ) {
				if ( 0 === $i ) {
					$pdf->text( 56, $y, self::d( $r['tx_date'] ), 10 );
					$pdf->text( Pdf::W - 56, $y, Money::format( $cents ), 10, false, 'R' );
				}
				$pdf->text( 120, $y, $line, 10 );
				$y += 13;
			}
			$y += 2;
		}
		if ( $y > 640 ) {
			self::footer( $pdf, 800 );
			$pdf->add_page();
			$y = 80;
		}
		$pdf->line( 50, $y - 4, Pdf::W - 50, $y - 4 );
		$y += 14;
		foreach ( $sums as $g => $c ) {
			$pdf->text( 56, $y, $g, 11 );
			$pdf->text( Pdf::W - 56, $y, Money::format( $c ), 11, false, 'R' );
			$y += 16;
		}
		$pdf->text( 56, $y + 4, 'Totale versato nel ' . $year, 12, true );
		$pdf->text( Pdf::W - 56, $y + 4, Money::format( $total ), 12, true, 'R' );
		$y += 50;
		$pdf->text( 50, $y, 'Emessa il ' . self::d( current_time( 'Y-m-d' ) ), 10 );
		$pres = self::president_name();
		$pdf->text( Pdf::W - 60, $y, 'Per l\'associazione', 10, false, 'R' );
		$pdf->line( Pdf::W - 230, $y + 34, Pdf::W - 60, $y + 34 );
		if ( '' !== $pres ) {
			$pdf->text( Pdf::W - 60, $y + 47, 'Il Presidente ' . $pres, 9, false, 'R' );
		}
		self::footer( $pdf, 800 );
		Pdf::$filter = null;
		return array( 'pdf' => $pdf->output(), 'filename' => 'attestazione-' . $year . '-' . preg_replace( '/[^a-z0-9]/', '', Text::normalize( $person['last_name'] ) ) . '.pdf', 'total_cents' => $total );
	}

	// ---------- Invio per email ----------

	/** Manda la ricevuta in PDF all'email di chi ha pagato. @throws \InvalidArgumentException */
	public static function email( string $key ): void {
		$r     = self::build( $key );
		$payer = $r['payer'];
		$to    = $payer && ! empty( $payer['email'] ) ? (string) $payer['email'] : '';
		if ( '' === $to && $payer && ! empty( $payer['host_person_id'] ) ) {
			$host = Plugin::people()->get( (int) $payer['host_person_id'] );
			$to   = $host && ! empty( $host['email'] ) ? (string) $host['email'] : '';
		}
		if ( '' === $to && $payer && class_exists( Guardians::class ) ) { // un minore senza email: la ricevuta va al tutore
			foreach ( Guardians::recipients( $payer ) as $g ) {
				$to = (string) $g['email'];
				break;
			}
		}
		if ( '' === $to || ! is_email( $to ) ) {
			throw new \InvalidArgumentException( 'Chi ha pagato non ha un indirizzo email (né il socio che lo ospita).' );
		}
		$dir = trailingslashit( get_temp_dir() ) . 'asem-' . bin2hex( random_bytes( 12 ) ); // cartella con nome casuale: il PDF (nome e cognome, codice fiscale) non è indovinabile né sostituibile da altri
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \InvalidArgumentException( 'Impossibile preparare la ricevuta: controlla i permessi della cartella temporanea.' );
		}
		chmod( $dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$dst = $dir . '/' . $r['filename'];
		file_put_contents( $dst, $r['pdf'] );
		$assoc = (string) Settings::get( 'association_name' );
		$ok    = \AssociazioneSemplice\Texts::mail(
			$to,
			'Ricevuta n. ' . $r['number'] . ( '' !== $assoc ? ' — ' . $assoc : '' ),
			'Ciao ' . ( $payer ? $payer['first_name'] : '' ) . ",\n\nin allegato la ricevuta n. " . $r['number'] . " del tuo pagamento.\n\nGrazie!",
			array(),
			array( $dst )
		);
		wp_delete_file( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Invio non riuscito: controlla la posta in uscita del sito.' );
		}
		Audit::log( 'receipt.emailed', 'receipt', 0, array( 'number' => $r['number'] ) );
	}

	// ---------- Download ----------

	private static function send_pdf( string $bytes, string $filename ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- file binario
		exit;
	}

	public static function handle_receipt(): void {
		$key = isset( $_GET['key'] ) ? self::clean_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		Csrf::check( 'asem_receipt_' . $key );
		if ( ! self::can_view( $key ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$r = self::build( $key );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send_pdf( $r['pdf'], $r['filename'] );
	}

	public static function handle_statement(): void {
		$pid  = isset( $_GET['person'] ) ? (int) $_GET['person'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		Csrf::check( 'asem_statement_' . $pid . '_' . $year );
		if ( ! self::can_statement( $pid ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$r = self::statement( $pid, $year );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send_pdf( $r['pdf'], $r['filename'] );
	}
}

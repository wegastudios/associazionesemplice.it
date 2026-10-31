<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Spese attribuite ai fondi (funzione di Associazione Semplice Pro Fiscale).
 *
 * Un fondo accantona i pagamenti di un corso per rimborsare il volontario; la spesa che il volontario ha sostenuto, quando è documentata
 * (scontrino o fattura allegati, o file del rimborso), dimostra a cosa servono quelle somme. L'attribuzione collega una spesa a uno o più
 * fondi, aperti o già liquidati, finché la documentazione copre quanto accantonato. Si fa da sola alla registrazione della spesa (appena ha i
 * documenti), si può scegliere a mano e si annulla. Tabella `expense_funds`: la riga con fondo 0 vuol dire «nessun fondo, non attribuire».
 */
final class ExpenseFunds {

	const AUTO   = 'auto';
	const MANUAL = 'manual';

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function enabled(): bool {
		return Edition::has( 'watch' ) && (bool) Limits::get( 'watch_auto_attribute' );
	}

	// ---------- Aggancio alla registrazione delle spese ----------

	/** Dopo la registrazione di una spesa: se chi registra ha scelto un fondo (o «nessuno») si applica subito; altrimenti si aspettano i documenti. */
	public static function on_recorded( int $tx_id, array $d ): void {
		if ( ! Edition::has( 'watch' ) ) {
			return;
		}
		$choice = (string) ( $d['fund_choice'] ?? '' );
		try {
			if ( 'none' === $choice ) {
				self::mark_none( $tx_id );
			} elseif ( '' !== $choice && ctype_digit( $choice ) ) {
				self::attach( $tx_id, (int) $choice, self::MANUAL );
			}
		} catch ( \InvalidArgumentException $e ) {
			Audit::log( 'expense_fund.skipped', 'transaction', $tx_id, array( 'reason' => $e->getMessage() ) ); // la spesa è registrata comunque
		}
	}

	/** Dopo l'aggiunta dei documenti a una spesa: se serve, attribuzione automatica. */
	public static function on_attachments( int $tx_id ): void {
		if ( self::enabled() ) {
			self::auto( $tx_id );
		}
	}

	// ---------- Documentazione e coperture ----------

	/** La spesa ha un documento: un allegato attivo, oppure il file di una richiesta di rimborso pagata con quel movimento. */
	public static function documented( int $tx_id ): bool {
		$db = self::db();
		if ( $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'attachments' ) . ' WHERE transaction_id = %d AND removed_at IS NULL', $tx_id ) ) ) {
			return true;
		}
		return Edition::installed( 'refunds' ) && (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'refunds' ) . " WHERE tx_id = %d AND file_stored IS NOT NULL AND file_stored <> ''", $tx_id ) );
	}

	/** Il movimento è il pagamento di un fondo estinto (non è una spesa da attribuire). */
	private static function is_payout( int $tx_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'fund_entries' ) . " WHERE kind = 'payout' AND tx_id = %d", $tx_id ) );
	}

	/**
	 * Tutti i fondi (aperti e liquidati) con quanto è stato accantonato, quanto è documentato e quanto manca.
	 *
	 * @return array[] id, name, person_id, activity_id, created_at, closed_at, accrued, documented, missing
	 */
	public static function coverage(): array {
		$db    = self::db();
		$e     = Db::t( 'fund_entries' );
		$t     = Db::t( 'transactions' );
		$x     = Db::t( 'expense_funds' );
		$funds = $db->get_results( 'SELECT id, name, person_id, activity_id, created_at, closed_at FROM ' . Db::t( 'funds' ) . ' ORDER BY created_at, id', ARRAY_A ) ?: array();
		$acc   = array();
		foreach ( $db->get_results( "SELECT e.fund_id, SUM(CASE e.kind WHEN 'share' THEN e.cents WHEN 'release' THEN -e.cents ELSE 0 END) AS cents FROM $e e LEFT JOIN $t t ON t.id = e.tx_id WHERE (e.tx_id IS NULL OR t.voided_at IS NULL) GROUP BY e.fund_id", ARRAY_A ) ?: array() as $r ) {
			$acc[ (int) $r['fund_id'] ] = (int) $r['cents'];
		}
		$doc = array();
		foreach ( $db->get_results( "SELECT x.fund_id, SUM(x.cents) AS cents FROM $x x JOIN $t t ON t.id = x.tx_id AND t.voided_at IS NULL WHERE x.undone_at IS NULL AND x.fund_id > 0 GROUP BY x.fund_id", ARRAY_A ) ?: array() as $r ) {
			$doc[ (int) $r['fund_id'] ] = (int) $r['cents'];
		}
		foreach ( $funds as &$f ) {
			$id             = (int) $f['id'];
			$f['accrued']   = max( 0, $acc[ $id ] ?? 0 );
			$f['documented'] = $doc[ $id ] ?? 0;
			$f['missing']   = max( 0, $f['accrued'] - $f['documented'] );
		}
		unset( $f );
		return $funds;
	}

	/** Attribuzioni attive di una spesa. @return array[] fund_id, cents, source, name */
	public static function allocations( int $tx_id ): array {
		$db = self::db();
		return $db->get_results( $db->prepare( 'SELECT x.id, x.fund_id, x.cents, x.source, f.name FROM ' . Db::t( 'expense_funds' ) . ' x LEFT JOIN ' . Db::t( 'funds' ) . ' f ON f.id = x.fund_id WHERE x.tx_id = %d AND x.undone_at IS NULL AND x.fund_id > 0 ORDER BY x.id', $tx_id ), ARRAY_A ) ?: array();
	}

	private static function has_rows( int $tx_id ): bool {
		$db = self::db();
		return (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'expense_funds' ) . ' WHERE tx_id = %d', $tx_id ) );
	}

	private static function tx( int $tx_id ): ?array {
		$db  = self::db();
		$row = $db->get_row( $db->prepare( 'SELECT id, type, amount_cents, person_id, activity_id, tx_date, voided_at FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx_id ), ARRAY_A );
		return $row ?: null;
	}

	// ---------- Attribuzione ----------

	/**
	 * Attribuzione automatica: se la spesa è documentata e non è mai stata attribuita (né annullata, né esclusa a mano), la divide tra i fondi del
	 * volontario che l'ha sostenuta — i più vecchi prima, aperti o liquidati — fino a coprire quanto manca. Senza volontario si usano i fondi
	 * della stessa attività. @return array<int,int> fund_id => centesimi attribuiti
	 */
	public static function auto( int $tx_id ): array {
		$tx = self::tx( $tx_id );
		if ( ! $tx || 'expense' !== $tx['type'] || $tx['voided_at'] || self::has_rows( $tx_id ) || self::is_payout( $tx_id ) || ! self::documented( $tx_id ) ) {
			return array();
		}
		return (array) Db::with_lock(
			'asem_expense_funds_' . $tx_id,
			function () use ( $tx ) {
				if ( self::has_rows( (int) $tx['id'] ) ) {
					return array();
				}
				$room  = array();
				$funds = array();
				foreach ( self::coverage() as $f ) {
					$mine = ! empty( $tx['person_id'] )
						? (int) $f['person_id'] === (int) $tx['person_id']
						: ( ! empty( $tx['activity_id'] ) && (int) $f['activity_id'] === (int) $tx['activity_id'] );
					if ( $mine && $f['missing'] > 0 ) {
						$funds[] = $f;
					}
				}
				foreach ( BalanceWatchRules::order_funds( $funds, (int) $tx['activity_id'] ) as $f ) {
					$room[ (int) $f['id'] ] = (int) $f['missing'];
				}
				$parts = BalanceWatchRules::split( (int) $tx['amount_cents'], $room );
				foreach ( $parts as $fund_id => $cents ) {
					self::insert( (int) $tx['id'], $fund_id, $cents, self::AUTO );
				}
				if ( $parts ) {
					Audit::log( 'expense_fund.auto', 'transaction', (int) $tx['id'], array( 'funds' => $parts ) );
				}
				return $parts;
			}
		);
	}

	private static function insert( int $tx_id, int $fund_id, int $cents, string $source ): void {
		self::db()->insert( Db::t( 'expense_funds' ), array( 'tx_id' => $tx_id, 'fund_id' => $fund_id, 'cents' => $cents, 'source' => $source, 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now() ) );
	}

	/**
	 * Attribuzione a mano di una spesa a un fondo (aperto o liquidato): al massimo quanto resta della spesa e quanto manca al fondo.
	 *
	 * @return int centesimi attribuiti
	 * @throws \InvalidArgumentException
	 */
	public static function attach( int $tx_id, int $fund_id, string $source = self::MANUAL ): int {
		$tx = self::tx( $tx_id );
		if ( ! $tx || 'expense' !== $tx['type'] || $tx['voided_at'] ) {
			throw new \InvalidArgumentException( 'Spesa non trovata o annullata.' );
		}
		$fund = null;
		foreach ( self::coverage() as $f ) {
			if ( (int) $f['id'] === $fund_id ) {
				$fund = $f;
			}
		}
		if ( ! $fund ) {
			throw new \InvalidArgumentException( 'Fondo non trovato.' );
		}
		$already = 0;
		foreach ( self::allocations( $tx_id ) as $a ) {
			$already += (int) $a['cents'];
		}
		$cents = min( (int) $tx['amount_cents'] - $already, (int) $fund['missing'] );
		if ( $cents <= 0 ) {
			throw new \InvalidArgumentException( $fund['missing'] <= 0 ? 'Il fondo «' . $fund['name'] . '» è già coperto dai documenti.' : 'La spesa è già attribuita per intero.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::insert( $tx_id, $fund_id, $cents, $source );
		Audit::log( 'expense_fund.attached', 'transaction', $tx_id, array( 'fund' => $fund_id, 'cents' => $cents ) );
		return $cents;
	}

	/** «Nessun fondo»: la spesa non si attribuisce, nemmeno quando arrivano i documenti. */
	public static function mark_none( int $tx_id ): void {
		if ( self::has_rows( $tx_id ) ) {
			return;
		}
		self::insert( $tx_id, 0, 0, self::MANUAL );
		Audit::log( 'expense_fund.none', 'transaction', $tx_id );
	}

	/** Annulla le attribuzioni di una spesa (automatiche o a mano): la spesa non viene riattribuita da sola. @return int attribuzioni annullate */
	public static function undo( int $tx_id ): int {
		$db = self::db();
		$n  = (int) $db->query( $db->prepare( 'UPDATE ' . Db::t( 'expense_funds' ) . ' SET undone_at = %s, undone_by = %d WHERE tx_id = %d AND undone_at IS NULL AND fund_id > 0', Db::now(), get_current_user_id(), $tx_id ) );
		if ( $n > 0 ) { // le righe annullate restano: dicono che la spesa è già stata trattata e non va riattribuita da sola
			Audit::log( 'expense_fund.undone', 'transaction', $tx_id, array( 'rows' => $n ) );
		}
		return $n;
	}

	// ---------- Elenchi ----------

	/**
	 * Spese documentate non (del tutto) rimborsate: spese con beneficiario, con documento, non annullate, di cui resta una parte non attribuita a
	 * nessun fondo. Si guarda indietro quanto dice il limite «watch_lookback_months». @return array[] id, tx_date, description, person_id, activity_id, amount_cents, attributed, remaining
	 */
	public static function uncovered_expenses(): array {
		$db     = self::db();
		$t      = Db::t( 'transactions' );
		$x      = Db::t( 'expense_funds' );
		$e      = Db::t( 'fund_entries' );
		$since  = ( new \DateTimeImmutable( Db::today() ) )->modify( '-' . Limits::get( 'watch_lookback_months' ) . ' months' )->format( 'Y-m-d' );
		$refund = Edition::installed( 'refunds' ) ? " OR EXISTS (SELECT 1 FROM " . Db::t( 'refunds' ) . " r WHERE r.tx_id = t.id AND r.file_stored IS NOT NULL AND r.file_stored <> '')" : '';
		$rows   = $db->get_results(
			$db->prepare(
				"SELECT t.id, t.tx_date, t.description, t.person_id, t.activity_id, t.amount_cents, COALESCE((SELECT SUM(x.cents) FROM $x x WHERE x.tx_id = t.id AND x.undone_at IS NULL AND x.fund_id > 0), 0) AS attributed FROM $t t "
				. "WHERE t.voided_at IS NULL AND t.type = 'expense' AND t.person_id IS NOT NULL AND t.tx_date >= %s AND t.id NOT IN (SELECT tx_id FROM $e WHERE kind = 'payout' AND tx_id IS NOT NULL) "
				. "AND NOT EXISTS (SELECT 1 FROM $x n WHERE n.tx_id = t.id AND n.fund_id = 0) "
				. "AND (EXISTS (SELECT 1 FROM " . Db::t( 'attachments' ) . " a WHERE a.transaction_id = t.id AND a.removed_at IS NULL)$refund) ORDER BY t.tx_date DESC, t.id DESC LIMIT 500",
				$since
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$r['remaining'] = (int) $r['amount_cents'] - (int) $r['attributed'];
			if ( $r['remaining'] > 0 ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/** Fondi accantonati non documentati (o documentati solo in parte), aperti o liquidati. @return array[] come {@see ExpenseFunds::coverage()} */
	public static function undocumented_funds(): array {
		return array_values(
			array_filter(
				self::coverage(),
				function ( $f ) {
					return $f['missing'] > 0;
				}
			)
		);
	}

	/** Le ultime spese attribuite (con le parti tra i fondi), per poterle annullare. @return array[] tx_id, tx_date, description, amount_cents, parts[] (fund name, cents, source) */
	public static function recent( int $limit = 20 ): array {
		$db   = self::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT x.tx_id, x.cents, x.source, f.name AS fund_name, t.tx_date, t.description, t.amount_cents FROM ' . Db::t( 'expense_funds' ) . ' x JOIN ' . Db::t( 'funds' ) . ' f ON f.id = x.fund_id JOIN ' . Db::t( 'transactions' ) . ' t ON t.id = x.tx_id AND t.voided_at IS NULL WHERE x.undone_at IS NULL AND x.fund_id > 0 ORDER BY x.id DESC LIMIT %d',
				max( 1, $limit ) * 3
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['tx_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array( 'tx_id' => $id, 'tx_date' => $r['tx_date'], 'description' => $r['description'], 'amount_cents' => (int) $r['amount_cents'], 'parts' => array() );
			}
			$out[ $id ]['parts'][] = array( 'fund' => $r['fund_name'], 'cents' => (int) $r['cents'], 'source' => $r['source'] );
		}
		return array_slice( array_values( $out ), 0, $limit );
	}

	public static function uncovered_csv(): string {
		$rows = array();
		foreach ( self::uncovered_expenses() as $r ) {
			$p      = ! empty( $r['person_id'] ) ? Plugin::people()->get( (int) $r['person_id'] ) : null;
			$rows[] = array( (string) $r['tx_date'], $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '', (string) $r['description'], Money::plain( (int) $r['amount_cents'] ), Money::plain( (int) $r['attributed'] ), Money::plain( (int) $r['remaining'] ) );
		}
		return Docs::csv(
			array( array( 'label' => 'Data' ), array( 'label' => 'Beneficiario' ), array( 'label' => 'Descrizione' ), array( 'label' => 'Importo' ), array( 'label' => 'Attribuito ai fondi' ), array( 'label' => 'Non attribuito' ) ),
			$rows
		);
	}

	public static function undocumented_csv(): string {
		$rows = array();
		foreach ( self::undocumented_funds() as $f ) {
			$rows[] = array( (string) $f['name'], null === $f['closed_at'] ? 'aperto' : 'liquidato', Money::plain( (int) $f['accrued'] ), Money::plain( (int) $f['documented'] ), Money::plain( (int) $f['missing'] ) );
		}
		return Docs::csv(
			array( array( 'label' => 'Fondo' ), array( 'label' => 'Stato' ), array( 'label' => 'Accantonato' ), array( 'label' => 'Documentato' ), array( 'label' => 'Da documentare' ) ),
			$rows
		);
	}

	// ---------- Privacy ----------

	/** Le attribuzioni dei fondi intestati alla persona, nell'esportazione dei suoi dati. */
	public static function privacy_export( array $data, int $person_id ): array {
		$db = self::db();
		$data['spese_attribuite_ai_fondi'] = $db->get_results(
			$db->prepare( 'SELECT f.name AS fondo, t.tx_date AS data, x.cents / 100 AS importo, x.source AS origine FROM ' . Db::t( 'expense_funds' ) . ' x JOIN ' . Db::t( 'funds' ) . ' f ON f.id = x.fund_id JOIN ' . Db::t( 'transactions' ) . ' t ON t.id = x.tx_id WHERE f.person_id = %d AND x.undone_at IS NULL ORDER BY t.tx_date', $person_id ),
			ARRAY_A
		) ?: array();
		return $data;
	}
}

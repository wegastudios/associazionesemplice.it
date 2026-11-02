<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Discrepanze da verificare a fine anno fiscale (Pro Fiscale), una per una. Ognuna può essere approvata a mano per portarla al nuovo anno:
 * l'approvazione vale finché la discrepanza resta com'è (stesso tipo, stessa voce, stesso importo); se cambia torna da verificare.
 * Le discrepanze approvate non fanno avvisi né fermano la chiusura dell'anno; restano elencate come «riportate».
 */
final class Discrepancies {

	const LABELS = array(
		'fund'              => 'fondo accantonato non documentato',
		'expense'           => 'spesa documentata non rimborsata',
		'credit'            => 'volontario in credito',
		'recon_uncovered'   => 'rimborsi senza documentazione a copertura',
		'recon_unpaid'      => 'spese documentate oltre i rimborsi ricevuti',
		'unattached'        => 'spesa con beneficiario non attribuita a nessun fondo',
		'alert'             => 'sbilancio di fine anno oltre le soglie',
		'budget'            => 'voce fuori preventivo',
		'uncategorized'     => 'movimento senza una categoria vera',
	);

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Le approvazioni: «tipo:voce» => riga. @return array<string,array> */
	private static function approvals(): array {
		$out = array();
		foreach ( self::db()->get_results( 'SELECT a.*, u.display_name AS who FROM ' . Db::t( 'discrepancy_approvals' ) . ' a LEFT JOIN ' . self::db()->users . ' u ON u.ID = a.approved_by', ARRAY_A ) ?: array() as $r ) {
			$out[ $r['kind'] . ':' . $r['ref'] ] = $r;
		}
		return $out;
	}

	public static function is_approved( string $kind, string $ref, int $cents ): bool {
		$a = self::approvals();
		return isset( $a[ $kind . ':' . $ref ] ) && (int) $a[ $kind . ':' . $ref ]['cents'] === $cents;
	}

	/**
	 * Tutte le discrepanze dell'anno fiscale, con lo stato di approvazione.
	 *
	 * @return array[] kind, ref, cents, desc, url, approved, approval (riga o null)
	 */
	public static function all( int $year ): array {
		$db  = self::db();
		$t   = Db::t( 'transactions' );
		$x   = Db::t( 'expense_funds' );
		$e   = Db::t( 'fund_entries' );
		$c   = Db::t( 'categories' );
		list( $from, $to ) = FiscalYears::range( $year );
		$items    = array();
		$accounts = Admin\Ui::url( 'asem-accounts' );
		$watch    = Admin\Ui::url( 'asem-watch', array( 'ry' => $year ) ) . '#asem-recon';
		$ledger   = Admin\Ui::url( 'asem-ledger', array( 'year' => $year ) );
		$add      = function ( string $kind, $ref, int $cents, string $desc, string $url ) use ( &$items ) {
			$items[] = array( 'kind' => $kind, 'ref' => (string) $ref, 'cents' => $cents, 'desc' => $desc, 'url' => $url );
		};
		foreach ( ExpenseFunds::undocumented_funds() as $f ) {
			$add( 'fund', $f['id'], (int) $f['missing'], (string) $f['name'], $accounts );
		}
		foreach ( ExpenseFunds::uncovered_expenses() as $r ) {
			$add( 'expense', $r['id'], (int) $r['remaining'], trim( $r['tx_date'] . ' ' . $r['description'] ), $accounts );
		}
		foreach ( ExpenseFunds::credits() as $cr ) {
			$add( 'credit', $cr['person_id'], (int) $cr['credit'], (string) $cr['name'], $accounts );
		}
		foreach ( FundReconciliation::rows( $year ) as $r ) {
			if ( BalanceWatchRules::RECON_UNCOVERED === $r['status'] ) {
				$add( 'recon_uncovered', $r['person_id'], (int) $r['diff'], (string) $r['name'], $watch );
			} elseif ( BalanceWatchRules::RECON_UNPAID === $r['status'] ) {
				$add( 'recon_unpaid', $r['person_id'], -(int) $r['diff'], (string) $r['name'], $watch );
			}
		}
		foreach ( $db->get_results( $db->prepare( "SELECT t.id, t.tx_date, t.description, t.amount_cents FROM $t t WHERE t.voided_at IS NULL AND t.type = 'expense' AND t.person_id IS NOT NULL AND t.tx_date BETWEEN %s AND %s AND t.id NOT IN (SELECT tx_id FROM $e WHERE kind = 'payout' AND tx_id IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM $x n WHERE n.tx_id = t.id) LIMIT 200", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$add( 'unattached', $r['id'], (int) $r['amount_cents'], trim( $r['tx_date'] . ' ' . $r['description'] ), $ledger );
		}
		$s = BalanceWatch::status();
		if ( (int) $s['year'] === $year ) {
			foreach ( $s['alerts'] as $a ) {
				$add( 'alert', $a['kind'], (int) $a['gap'], BalanceWatchRules::title( $a['kind'] ), Admin\Ui::url( 'asem-watch' ) );
			}
		}
		if ( Edition::has( 'budget' ) ) {
			$b = Budget::by_year( $year );
			if ( $b ) {
				foreach ( Budget::compare( (int) $b['id'] )['rows'] as $r ) {
					if ( in_array( $r['status'], array( BudgetRules::OVER, BudgetRules::UNPLANNED ), true ) ) {
						$add( 'budget', $r['category_id'], abs( (int) $r['diff'] ), (string) $r['name'], Admin\Ui::url( 'asem-budget', array( 'view' => (int) $b['id'] ) ) );
					}
				}
			}
		}
		foreach ( $db->get_results( $db->prepare( "SELECT t.id, t.tx_date, t.description, t.amount_cents FROM $t t LEFT JOIN $c c ON c.id = t.category_id WHERE t.voided_at IS NULL AND t.type IN ('income','expense') AND t.tx_date BETWEEN %s AND %s AND (c.id IS NULL OR c.deleted_at IS NOT NULL OR c.kind = 'adjustment') LIMIT 200", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$add( 'uncategorized', $r['id'], (int) $r['amount_cents'], trim( $r['tx_date'] . ' ' . $r['description'] ), $ledger );
		}
		$ap = self::approvals();
		foreach ( $items as &$i ) {
			$a             = $ap[ $i['kind'] . ':' . $i['ref'] ] ?? null;
			$i['approved'] = $a && (int) $a['cents'] === $i['cents'];
			$i['approval'] = $i['approved'] ? $a : null;
		}
		unset( $i );
		return $items;
	}

	/** Solo le discrepanze ancora da verificare. */
	public static function open( int $year ): array {
		return array_values(
			array_filter(
				self::all( $year ),
				function ( $i ) {
					return ! $i['approved'];
				}
			)
		);
	}

	/**
	 * Approva una discrepanza per portarla al nuovo anno. @throws \InvalidArgumentException
	 */
	public static function approve( string $kind, string $ref, int $cents, string $note, int $year ): void {
		if ( ! isset( self::LABELS[ $kind ] ) || '' === $ref ) {
			throw new \InvalidArgumentException( 'Discrepanza non riconosciuta.' );
		}
		$found = false;
		foreach ( self::all( $year ) as $i ) {
			if ( $i['kind'] === $kind && $i['ref'] === $ref && $i['cents'] === $cents ) {
				$found = true;
			}
		}
		if ( ! $found ) {
			throw new \InvalidArgumentException( 'La discrepanza è cambiata: ricarica la pagina e controllala di nuovo.' );
		}
		$db = self::db();
		$db->delete( Db::t( 'discrepancy_approvals' ), array( 'kind' => $kind, 'ref' => $ref ) );
		$db->insert( Db::t( 'discrepancy_approvals' ), array( 'kind' => $kind, 'ref' => $ref, 'cents' => $cents, 'fiscal_year' => $year, 'note' => mb_substr( trim( $note ), 0, 255 ), 'approved_by' => get_current_user_id() ?: null, 'approved_at' => Db::now() ) );
		Audit::log( 'discrepancy.approved', 'discrepancy', (int) $db->insert_id, array( 'kind' => $kind, 'ref' => $ref, 'cents' => $cents, 'year' => $year ) );
	}

	public static function revoke( int $id ): void {
		$db = self::db();
		$db->delete( Db::t( 'discrepancy_approvals' ), array( 'id' => $id ) );
		Audit::log( 'discrepancy.revoked', 'discrepancy', $id );
	}

	/** Le discrepanze ancora da verificare raggruppate per tipo, per l'avviso. @return array[] label, count, url */
	public static function grouped( int $year ): array {
		$by = array();
		foreach ( self::open( $year ) as $i ) {
			if ( ! isset( $by[ $i['kind'] ] ) ) {
				$by[ $i['kind'] ] = array( 'label' => self::LABELS[ $i['kind'] ], 'count' => 0, 'url' => $i['url'] );
			}
			$by[ $i['kind'] ]['count']++;
		}
		return array_values( $by );
	}
}

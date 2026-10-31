<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Verifiche di fine anno fiscale (Pro Fiscale): dal mese che precede l'ultimo mese dell'anno (limite `watch_yearend_lead_months`) un avviso fisso
 * per i ruoli amministrativi elenca le discrepanze da correggere prima della chiusura, con il link a ciascuna. Se non ce ne sono, non si mostra niente.
 * Facoltativo: un promemoria email all'inizio di quel mese (`watch_yearend_email`, spento di partenza).
 */
final class YearEndCheck {

	const MAIL_OPTION = 'asem_watch_yearend_mail';

	public static function window( ?string $today = null ): array {
		return BalanceWatchRules::year_end_window( $today ?: Db::today(), FiscalYears::start_month(), Limits::get( 'watch_yearend_lead_months' ) );
	}

	/** Le discrepanze dell'anno fiscale. @return array[] label, count, url */
	public static function discrepancies( int $year ): array {
		$db = Db::db();
		$t  = Db::t( 'transactions' );
		$x  = Db::t( 'expense_funds' );
		$e  = Db::t( 'fund_entries' );
		$c  = Db::t( 'categories' );
		list( $from, $to ) = FiscalYears::range( $year );
		$items = array();
		$add   = function ( string $label, int $n, string $url ) use ( &$items ) {
			if ( $n > 0 ) {
				$items[] = array( 'label' => $label, 'count' => $n, 'url' => $url );
			}
		};
		$accounts = Admin\Ui::url( 'asem-accounts' );
		$watch    = Admin\Ui::url( 'asem-watch', array( 'ry' => $year ) );
		$add( 'fondi accantonati non documentati (o documentati solo in parte)', count( ExpenseFunds::undocumented_funds() ), $accounts );
		$add( 'spese documentate non rimborsate', count( ExpenseFunds::uncovered_expenses() ), $accounts );
		$recon = FundReconciliation::rows( $year );
		$add( 'persone con rimborsi senza documentazione a copertura', count( BalanceWatchRules::gaps( $recon, true ) ), $watch . '#asem-recon' );
		$add(
			'persone con spese documentate oltre i rimborsi ricevuti',
			count(
				array_filter(
					$recon,
					function ( $r ) {
						return BalanceWatchRules::RECON_UNPAID === $r['status'];
					}
				)
			),
			$watch . '#asem-recon'
		);
		$unattached = (int) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM $t t WHERE t.voided_at IS NULL AND t.type = 'expense' AND t.person_id IS NOT NULL AND t.tx_date BETWEEN %s AND %s AND t.id NOT IN (SELECT tx_id FROM $e WHERE kind = 'payout' AND tx_id IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM $x n WHERE n.tx_id = t.id)",
				$from,
				$to
			)
		);
		$add( 'spese con beneficiario non attribuite a nessun fondo', $unattached, Admin\Ui::url( 'asem-ledger', array( 'year' => $year ) ) );
		$s = BalanceWatch::status();
		if ( (int) $s['year'] === $year ) {
			$add( 'sbilancio di fine anno oltre le soglie degli avvisi', count( $s['alerts'] ), Admin\Ui::url( 'asem-watch' ) );
		}
		if ( Edition::has( 'budget' ) ) {
			$b = Budget::by_year( $year );
			if ( $b ) {
				$out = 0;
				foreach ( Budget::compare( (int) $b['id'] )['rows'] as $r ) {
					if ( in_array( $r['status'], array( BudgetRules::OVER, BudgetRules::UNPLANNED ), true ) ) {
						$out++;
					}
				}
				$add( 'voci fuori preventivo (oltre il previsto o non previste)', $out, Admin\Ui::url( 'asem-budget', array( 'view' => (int) $b['id'] ) ) );
			}
		}
		$uncat = (int) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM $t t LEFT JOIN $c c ON c.id = t.category_id WHERE t.voided_at IS NULL AND t.type IN ('income','expense') AND t.tx_date BETWEEN %s AND %s AND (c.id IS NULL OR c.deleted_at IS NOT NULL OR c.kind = 'adjustment')",
				$from,
				$to
			)
		);
		$add( 'movimenti senza una categoria vera (rettifiche o categorie eliminate)', $uncat, Admin\Ui::url( 'asem-ledger', array( 'year' => $year ) ) );
		return $items;
	}

	/** Chi vede l'avviso nell'amministrazione: chi opera sulla contabilità (amministratori, segreteria, presidente e vicepresidente). */
	private static function can_see(): bool {
		return Edition::has( 'watch' ) && current_user_can( Plugin::CAP_OPS );
	}

	/** Avviso fisso (non chiudibile) con l'elenco; vuoto fuori dalla finestra o senza discrepanze: niente messaggi «tutto a posto». */
	public static function html(): string {
		if ( ! self::can_see() ) {
			return '';
		}
		$w = self::window();
		if ( ! $w['active'] ) {
			return '';
		}
		$items = self::discrepancies( (int) $w['year'] );
		if ( ! $items ) {
			return '';
		}
		$li = '';
		foreach ( $items as $i ) {
			$li .= '<li>' . (int) $i['count'] . ' ' . esc_html( $i['label'] ) . ' — <a href="' . esc_url( $i['url'] ) . '">verifica</a></li>';
		}
		return '<div class="notice notice-warning inline asem-yearend"><p><strong>Verifiche di fine anno fiscale.</strong> L\'anno finisce il ' . esc_html( ( new \DateTimeImmutable( $w['end'] ) )->format( 'd/m/Y' ) ) . ': conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura.</p><ul style="list-style:disc;margin-left:2em">' . $li . '</ul></div>';
	}

	public static function show(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
	}

	/** Ruoli amministrativi da avvisare: amministratori, segreteria, tesorieri, presidente e vicepresidente in regola. @return string[] email */
	public static function recipients(): array {
		$users = array_merge(
			get_users( array( 'role__in' => array( 'administrator', Plugin::ROLE_SECRETARY ) ) ),
			get_users( array( 'meta_key' => Access::TREASURER_META, 'meta_value' => '1' ) ) // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		foreach ( Db::db()->get_col( 'SELECT wp_user_id FROM ' . Db::t( 'people' ) . " WHERE wp_user_id IS NOT NULL AND board_role IN ('president','vice_president')" ) ?: array() as $uid ) {
			if ( Access::is_board_operator( (int) $uid ) && get_userdata( (int) $uid ) ) {
				$users[] = get_userdata( (int) $uid );
			}
		}
		$out = array();
		foreach ( $users as $u ) {
			if ( $u && is_email( $u->user_email ) ) {
				$out[ strtolower( $u->user_email ) ] = $u->user_email;
			}
		}
		return array_values( $out );
	}

	/** Lavoro giornaliero: dall'inizio della finestra, se il promemoria è attivo e ci sono discrepanze, una sola email per anno fiscale. @return int email inviate */
	public static function mail_job( ?string $today = null ): int {
		if ( ! Edition::has( 'watch' ) || ! Limits::get( 'watch_yearend_email' ) ) {
			return 0;
		}
		$w = self::window( $today );
		if ( ! $w['active'] || (int) get_option( self::MAIL_OPTION, 0 ) === (int) $w['year'] ) {
			return 0;
		}
		$items = self::discrepancies( (int) $w['year'] );
		if ( ! $items ) {
			return 0; // niente da segnalare: si riprova nei giorni seguenti
		}
		update_option( self::MAIL_OPTION, (int) $w['year'], false );
		$lines = array();
		foreach ( $items as $i ) {
			$lines[] = '- ' . $i['count'] . ' ' . $i['label'] . ': ' . $i['url'];
		}
		$body = "L'anno fiscale finisce il " . ( new \DateTimeImmutable( $w['end'] ) )->format( 'd/m/Y' ) . ". Conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura:\n\n" . implode( "\n", $lines );
		$n    = 0;
		foreach ( self::recipients() as $to ) {
			if ( wp_mail( $to, 'Verifiche di fine anno fiscale', $body ) ) {
				$n++;
			}
		}
		return $n;
	}
}

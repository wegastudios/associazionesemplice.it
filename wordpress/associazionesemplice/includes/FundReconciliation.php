<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Quadratura per persona (Pro Fiscale): per l'anno fiscale, quanto è stato rimborsato dai fondi a ogni volontario o consigliere e quante spese
 * documentate sono attribuite ai suoi fondi. Chi ha avuto rimborsi deve trovarsi con le spese sostenute: a fine anno la chiusura lo controlla
 * (comportamento nel limite `watch_close_mode`). Regole pure in {@see BalanceWatchRules::reconcile()}.
 */
final class FundReconciliation {

	const MODE_OFF     = 0; // nessun controllo
	const MODE_WARN    = 1; // solo avviso in Bacheca
	const MODE_CONFIRM = 2; // la chiusura chiede una conferma con l'elenco
	const MODE_BLOCK   = 3; // la chiusura si ferma

	public static function register(): void {
		add_filter( 'asem_year_close_blockers', array( __CLASS__, 'blockers' ), 10, 2 );
		add_filter( 'asem_year_close_confirm', array( __CLASS__, 'confirm' ), 10, 2 );
		add_action( 'asem_year_close_form', array( __CLASS__, 'close_form' ) );
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alert' ), 25 );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Righe del prospetto di un anno fiscale. @return array[] person_id, name, reimbursed, documented, diff, status */
	public static function rows( int $year ): array {
		list( $from, $to ) = FiscalYears::range( $year );
		$db  = self::db();
		$e   = Db::t( 'fund_entries' );
		$f   = Db::t( 'funds' );
		$t   = Db::t( 'transactions' );
		$x   = Db::t( 'expense_funds' );
		$rei = array();
		foreach ( $db->get_results( $db->prepare( "SELECT f.person_id, SUM(e.cents) AS cents FROM $e e JOIN $f f ON f.id = e.fund_id JOIN $t t ON t.id = e.tx_id AND t.voided_at IS NULL WHERE e.kind = 'payout' AND f.person_id IS NOT NULL AND e.entry_date BETWEEN %s AND %s GROUP BY f.person_id", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$rei[ (int) $r['person_id'] ] = (int) $r['cents'];
		}
		$doc = array();
		foreach ( $db->get_results( $db->prepare( "SELECT f.person_id, SUM(x.cents) AS cents FROM $x x JOIN $f f ON f.id = x.fund_id JOIN $t t ON t.id = x.tx_id AND t.voided_at IS NULL WHERE x.undone_at IS NULL AND x.fund_id > 0 AND f.person_id IS NOT NULL AND t.tx_date BETWEEN %s AND %s GROUP BY f.person_id", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$doc[ (int) $r['person_id'] ] = (int) $r['cents'];
		}
		$rows = BalanceWatchRules::reconcile( $rei, $doc, Limits::get( 'watch_recon_tolerance_cents' ) );
		foreach ( $rows as &$r ) {
			$p         = Plugin::people()->get( (int) $r['person_id'] );
			$r['name'] = $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : 'Persona eliminata';
		}
		unset( $r );
		return $rows;
	}

	public static function status_label( string $status ): string {
		$map = array(
			BalanceWatchRules::RECON_OK        => 'in pari',
			BalanceWatchRules::RECON_UNCOVERED => 'rimborsato senza documenti: da regolarizzare',
			BalanceWatchRules::RECON_UNPAID    => 'documentato e non rimborsato: da rimborsare',
		);
		return $map[ $status ] ?? '';
	}

	/** Le persone che bloccano (o fanno chiedere conferma): rimborsato oltre il documentato. @return string[] frasi con nome e importo */
	private static function open_items( int $year ): array {
		$out = array();
		foreach ( BalanceWatchRules::gaps( self::rows( $year ), true ) as $r ) {
			$out[] = $r['name'] . ': rimborsati ' . Money::format( (int) $r['reimbursed'] ) . ', documentati ' . Money::format( (int) $r['documented'] ) . ' (scoperto ' . Money::format( (int) $r['diff'] ) . ')';
		}
		return $out;
	}

	public static function mode(): int {
		return Edition::has( 'watch' ) ? Limits::get( 'watch_close_mode' ) : self::MODE_OFF;
	}

	/** Chiusura dell'anno: con il comportamento «blocca» le differenze la fermano. */
	public static function blockers( $list, $year ) {
		if ( self::MODE_BLOCK === self::mode() ) {
			$items = self::open_items( (int) $year );
			if ( $items ) {
				$list[] = 'chi ha avuto rimborsi non ha le spese documentate a copertura (' . implode( '; ', $items ) . '): allega i documenti o attribuisci le spese ai fondi da Cassa → Conti e fondi';
			}
		}
		return $list;
	}

	/** Con il comportamento «chiedi conferma» la chiusura riparte solo con la conferma. */
	public static function confirm( $list, $year ) {
		return self::MODE_CONFIRM === self::mode() ? array_merge( (array) $list, self::open_items( (int) $year ) ) : $list;
	}

	/** Casella di conferma nel modulo «Chiudi l'anno». */
	public static function close_form( $year ): void {
		if ( self::MODE_CONFIRM !== self::mode() || ! self::open_items( (int) $year ) ) {
			return;
		}
		echo '<label class="description"><input type="checkbox" name="confirm_gaps" value="1"> chiudo anche se qualcuno ha rimborsi non documentati</label> ';
	}

	/** Avviso non bloccante in Bacheca: differenze dell'anno fiscale in corso (si ricalcola a ogni apertura, quindi anche a fine mese). */
	public static function alert(): void {
		if ( ! Edition::has( 'watch' ) || ! current_user_can( Plugin::CAP_OPS ) || ! Limits::get( 'watch_recon_alerts' ) ) {
			return;
		}
		$year = FiscalYears::current();
		$gaps = BalanceWatchRules::gaps( self::rows( $year ) );
		if ( ! $gaps ) {
			return;
		}
		$unc = count( BalanceWatchRules::gaps( $gaps, true ) );
		$msg = '<strong>Rimborsi e spese non quadrano</strong> per ' . count( $gaps ) . ( 1 === count( $gaps ) ? ' persona' : ' persone' ) . ' (' . (int) $unc . ' con rimborsi senza documenti). A fine anno chi ha avuto rimborsi deve trovarsi con le spese sostenute. <a href="' . esc_url( Admin\Ui::url( 'asem-watch', array( 'ry' => $year ) ) ) . '#asem-recon">Apri il prospetto</a>.';
		echo Admin\Dismiss::html( 'watch-recon-' . $year . '-' . count( $gaps ) . '-' . $unc . '-' . substr( Db::today(), 0, 7 ), 'warning', $msg, 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
	}

	// ---------- Esportazioni ----------

	private static function table( int $year ): array {
		$cols = array( array( 'label' => 'Persona' ), array( 'label' => 'Rimborsato dai fondi' ), array( 'label' => 'Spese documentate attribuite' ), array( 'label' => 'Differenza' ), array( 'label' => 'Situazione' ) );
		$rows = array();
		foreach ( self::rows( $year ) as $r ) {
			$rows[] = array( $r['name'], Money::plain( (int) $r['reimbursed'] ), Money::plain( (int) $r['documented'] ), Money::plain( (int) $r['diff'] ), self::status_label( $r['status'] ) );
		}
		return array( $cols, $rows );
	}

	public static function csv( int $year ): string {
		list( $cols, $rows ) = self::table( $year );
		return Docs::csv( $cols, $rows );
	}

	public static function pdf( int $year ): string {
		list( $cols, $rows ) = self::table( $year );
		list( $from, $to )   = FiscalYears::range( $year );
		foreach ( $cols as $i => &$c ) {
			$c['w'] = array( 120.0, 70.0, 80.0, 70.0, 150.0 )[ $i ];
			$c['align'] = ( $i > 0 && $i < 4 ) ? 'R' : 'L';
		}
		unset( $c );
		return Docs::table_pdf( 'Rimborsi e spese documentate per persona', 'Anno fiscale ' . FiscalYears::label( $year ) . ' (' . Docs::d( $from ) . ' – ' . Docs::d( $to ) . ')', $cols, $rows, 'Differenza = rimborsato − documentato. Positiva: rimborsi senza documentazione, da regolarizzare. Negativa: spese documentate non ancora rimborsate.' );
	}
}

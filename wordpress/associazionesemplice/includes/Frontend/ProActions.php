<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Azioni dei moduli del sito delle funzioni aggiuntive: pagamento online, cassa per più persone, incasso sul posto. */
final class ProActions {

	public static function register(): void {
		Actions::add_handler( 'asem_front_pay', array( __CLASS__, 'do_pay' ), true );
		Actions::add_handler( 'asem_front_group', array( __CLASS__, 'do_group_collect' ) );
		Actions::add_handler( 'asem_front_door', array( __CLASS__, 'do_door' ) );
		Actions::add_handler( 'asem_front_door_group', array( __CLASS__, 'do_door_group' ) );
	}

	/** Le azioni si rifiutano se la funzione non c'è. */
	private static function require_feature( string $feature ): void {
		if ( ! Edition::has( $feature ) ) {
			throw new \InvalidArgumentException( Edition::missing_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	public static function do_pay( array $post ): string {
		self::require_feature( 'payments' );
		$actor = Actions::actor();
		Actions::assert_profile( $actor );
		Actions::require_cap( 'asem_view_payments', (int) $actor['id'] );
		$back = remove_query_arg( array( 'asemf_ok', 'asemf_err', 'asemf_sig', 'asem_pay', 'asem_ret', 'token', 'PayerID' ), Actions::back_url( $post ) );
		return Plugin::payments()->create_checkout( $actor, get_current_user_id(), (array) ( $post['items'] ?? array() ), $back, sanitize_key( (string) ( $post['provider'] ?? '' ) ) );
	}

	/** Cassa per più persone del tesoriere (stesse regole degli amministratori). */
	public static function do_group_collect( array $post ): string {
		self::require_feature( 'funds' );
		Actions::require_cap( 'asem_collect', 0 );
		$month  = Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );
		$people = array();
		foreach ( (array) ( $post['rows'] ?? array() ) as $i => $r ) {
			$r    = (array) $r;
			$what = (string) ( $r['what'] ?? '' );
			if ( '' === $what ) {
				continue;
			}
			$pid   = (int) ( $r['person'] ?? 0 );
			$first = trim( (string) ( $r['first'] ?? '' ) );
			$last  = trim( (string) ( $r['last'] ?? '' ) );
			if ( ! $pid && '' === $first && '' === $last ) {
				throw new \InvalidArgumentException( 'Riga ' . ( (int) $i + 1 ) . ': scegli la persona oppure scrivi nome e cognome del nuovo ospite.' );
			}
			$person = $pid ? Plugin::people()->get( $pid ) : null;
			if ( 'm' === $what ) {
				$line = array( 'kind' => 'membership', 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = Actions::standard_cents( 'membership', 0, $person );
			} elseif ( 0 === strpos( $what, 's:' ) ) {
				$sid  = (int) substr( $what, 2 );
				$s    = Plugin::activities()->session( $sid );
				$line = array( 'kind' => 'event', 'session_id' => $sid, 'activity_id' => $s ? (int) $s['activity_id'] : 0, 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = Actions::standard_cents( 'event', $sid, $person );
			} elseif ( 0 === strpos( $what, 'k:' ) ) {
				$aid  = (int) substr( $what, 2 );
				$line = array( 'kind' => 'course', 'activity_id' => $aid, 'month' => $month, 'amount' => (string) ( $r['amount'] ?? '' ) );
				$std  = Actions::standard_cents( 'course', $aid, $person );
			} else {
				throw new \InvalidArgumentException( 'Voce non riconosciuta.' );
			}
			if ( '' === trim( $line['amount'] ) ) {
				$line['amount'] = Money::plain( $std );
			}
			$key = $pid ? 'p' . $pid : 'n' . $i;
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = $pid ? array( 'id' => $pid, 'lines' => array() ) : array( 'new' => 1, 'first' => $first, 'last' => $last, 'phone' => (string) ( $r['phone'] ?? '' ), 'lines' => array() );
			}
			$people[ $key ]['lines'][] = $line;
		}
		$s = \AssociazioneSemplice\GroupCash::record(
			array( 'payer_id' => (int) ( $post['payer_id'] ?? 0 ), 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'people' => array_values( $people ) )
		);
		return 'Incasso registrato: ' . $s['lines'] . ( 1 === $s['lines'] ? ' voce' : ' voci' ) . ' per ' . $s['people'] . ( 1 === $s['people'] ? ' persona' : ' persone' ) . ', totale ' . Money::format( $s['cents'] ) . '.';
	}

	/** Cassa per più soci sul posto (staff): un socio paga il biglietto per sé e per altri soci, importi calcolati dal sito. */
	public static function do_door_group( array $post ): string {
		self::require_feature( 'door_sales' );
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$aid = (int) $session['activity_id'];
		Actions::require_cap( 'asem_door_cash', $aid );
		$people = array();
		foreach ( (array) ( $post['rows'] ?? array() ) as $r ) {
			$pid = (int) ( ( (array) $r )['person'] ?? 0 );
			if ( $pid && ! isset( $people[ $pid ] ) ) {
				$people[ $pid ] = array( 'id' => $pid, 'lines' => array( array( 'kind' => 'event', 'session_id' => $sid, 'activity_id' => $aid, 'amount' => '' ) ) );
			}
		}
		if ( ! $people ) {
			throw new \InvalidArgumentException( 'Scegli almeno un socio.' );
		}
		$s = \AssociazioneSemplice\GroupCash::record(
			array( 'payer_id' => (int) ( $post['payer_id'] ?? 0 ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'people' => array_values( $people ) ),
			array( 'staff' => true, 'activity_ids' => array( $aid ) )
		);
		foreach ( array_keys( $people ) as $pid ) { // chi paga all'ingresso entra: ingresso registrato per tutti
			try {
				Plugin::activities()->check_in( $sid, (int) $pid, false, current_user_can( Plugin::CAP_OPS ) );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
		}
		return 'Incasso registrato per ' . $s['people'] . ( 1 === $s['people'] ? ' socio' : ' soci' ) . ': ' . Money::format( $s['cents'] ) . ', ingressi registrati.';
	}

	/** Ingresso sul posto: chi non ha prenotato viene prenotato (se c'è posto), paga il biglietto in contanti o con il POS e entra. Solo soci e solo con l'incasso abilitato. */
	public static function do_door( array $post ): string {
		self::require_feature( 'door_sales' );
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$aid = (int) $session['activity_id'];
		Actions::require_cap( 'asem_door_cash', $aid );
		if ( $session['session_date'] !== current_time( 'Y-m-d' ) && ! current_user_can( Plugin::CAP_OPS ) ) {
			throw new \InvalidArgumentException( 'L\'ingresso sul posto si registra nel giorno dell\'evento.' );
		}
		$account = (int) ( $post['account_id'] ?? 0 );
		foreach ( $account ? array() : Plugin::ledger()->accounts() as $acc ) {
			if ( 'cash' === $acc['type'] ) {
				$account = (int) $acc['id'];
				break;
			}
		}
		$pay = ! empty( $post['pay'] );
		if ( $pay && ! $account ) {
			throw new \InvalidArgumentException( 'Manca un conto di tipo "Cassa contanti": chiedi alla segreteria di crearlo.' );
		}
		return \AssociazioneSemplice\DoorSales::sell(
			array(
				'session_id' => $sid, 'activity_id' => $aid, 'person_id' => (int) ( $post['person_id'] ?? 0 ),
				'pay' => $pay, 'account_id' => $account, 'checkin' => true,
			),
			true
		);
	}

}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Calendar;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Modules;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Widget nella Bacheca di WordPress (la pagina che si apre entrando in wp-admin): le richieste da lavorare e gli eventi della settimana,
 * con i link alle funzioni principali. Li vede chi può operare (amministratori, segreteria, consiglieri con accesso).
 */
final class WpDashboard {

	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'setup' ) );
	}

	public static function setup(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		wp_add_dashboard_widget( 'asem_todo', 'Associazione Semplice — Da lavorare', array( __CLASS__, 'todo' ) );
		if ( Modules::on( 'activities' ) ) {
			wp_add_dashboard_widget( 'asem_week', 'Associazione Semplice — Eventi e corsi della settimana', array( __CLASS__, 'week' ) );
		}
	}

	/** Soci con la tessera scaduta o in scadenza entro 30 giorni (rinnovo manuale). */
	private static function renewals(): int {
		$today = current_time( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$n     = 0;
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::GUEST === $p['type'] || empty( $p['active_until'] ) || MemberType::is_auto_renewed( $p['type'] ) || ! empty( $p['suspended_at'] ) ) {
				continue;
			}
			if ( $p['active_until'] <= $soon ) {
				$n++;
			}
		}
		return $n;
	}

	/** Le cose da lavorare: [numero, testo, indirizzo]. */
	public static function todo_items(): array {
		$items = array();
		$req   = count( \AssociazioneSemplice\AccessRequests::pending() );
		if ( $req > 0 ) {
			$items[] = array( $req, 1 === $req ? 'richiesta di accesso da controllare' : 'richieste di accesso da controllare', Ui::url( 'asem' ) );
		}
		$ren = self::renewals();
		if ( $ren > 0 ) {
			$items[] = array( $ren, 1 === $ren ? 'socio da rinnovare' : 'soci da rinnovare', Ui::url( 'asem' ) );
		}
		$inv = count( Plugin::people()->guests_to_invite() );
		if ( $inv > 0 ) {
			$items[] = array( $inv, 1 === $inv ? 'ospite da invitare a iscriversi' : 'ospiti da invitare a iscriversi', Ui::url( 'asem-people', array( 'type' => 'guest', 'at_limit' => 1 ) ) );
		}
		if ( current_user_can( Plugin::CAP ) ) {
			$mail = \AssociazioneSemplice\MailLog::failures();
			if ( $mail > 0 ) {
				$items[] = array( $mail, 1 === $mail ? 'email non partita' : 'email non partite', Ui::url( 'asem-tech' ) );
			}
		}
		return $items;
	}

	public static function todo(): void {
		$items = self::todo_items();
		if ( ! $items ) {
			echo '<p>Niente da lavorare: è tutto in ordine.</p>';
		} else {
			echo '<ul class="asem-todo" style="margin:0 0 12px">';
			foreach ( $items as $it ) {
				echo '<li style="margin:6px 0"><a href="' . esc_url( $it[2] ) . '"><strong>' . (int) $it[0] . '</strong> ' . esc_html( $it[1] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( Ui::url( 'asem' ) ) . '">Apri la Bacheca di Associazione Semplice</a></p>';
	}

	/** Date dei prossimi 7 giorni (oggi compreso), raggruppate per giorno. @return array<string,array[]> */
	public static function week_days(): array {
		$today = current_time( 'Y-m-d' );
		$days  = array();
		foreach ( Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +6 days' ) ) ) as $o ) {
			$days[ $o['date'] ][] = $o;
		}
		ksort( $days );
		return $days;
	}

	public static function week(): void {
		$days = self::week_days();
		if ( ! $days ) {
			echo '<p>Nessun evento né lezione nei prossimi sette giorni.</p>';
		} else {
			foreach ( $days as $date => $list ) {
				echo '<h4 style="margin:10px 0 4px">' . esc_html( date_i18n( 'l j F', strtotime( $date . ' 12:00:00' ) ) ) . '</h4><ul style="margin:0">';
				foreach ( $list as $o ) {
					$when  = $o['start'] ? $o['start'] . ( $o['end'] ? '–' . $o['end'] : '' ) . ' · ' : '';
					$extra = '';
					if ( ! empty( $o['session_id'] ) ) {
						$booked = 0;
						foreach ( Plugin::activities()->bookings_for_session( (int) $o['session_id'] ) as $b ) {
							$booked += $b['active'] ? 1 : 0;
						}
						$extra = ' · ' . $booked . ( 1 === $booked ? ' prenotato' : ' prenotati' );
					}
					echo '<li style="margin:3px 0">' . esc_html( $when ) . '<a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => (int) $o['activity_id'] ) ) ) . '">' . esc_html( $o['title'] ) . '</a>'
						. ( '' !== (string) $o['location'] ? ' <span class="description">' . esc_html( (string) $o['location'] ) . '</span>' : '' ) . '<span class="description">' . esc_html( $extra ) . '</span></li>';
				}
				echo '</ul>';
			}
		}
		echo '<p style="margin-top:14px">'
			. '<a class="button button-primary" href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Nuovo incasso</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-bookdesk' ) ) . '">Prenota</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-activities' ) ) . '">Corsi ed eventi</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-people' ) ) . '">Rubrica</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-ledger' ) ) . '">Prima nota</a></p>';
	}
}

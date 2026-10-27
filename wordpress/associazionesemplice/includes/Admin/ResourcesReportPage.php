<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Money;
use AssociazioneSemplice\ResourceRules;
use AssociazioneSemplice\Resources;

defined( 'ABSPATH' ) || exit;

/** Occupazione delle risorse in un periodo: ore prenotate, ore di corsi ed eventi, allestimenti, blocchi, incassi. */
final class ResourcesReportPage {

	private static function hours( int $minutes ): string {
		return 0 === $minutes ? '—' : esc_html( number_format( $minutes / 60, 1, ',', '.' ) . ' h' );
	}

	public static function render(): void {
		$from = Ui::get_str( 'from', current_time( 'Y-m' ) . '-01' );
		$to   = Ui::get_str( 'to', gmdate( 'Y-m-t', strtotime( $from ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from || ( strtotime( $to ) - strtotime( $from ) ) > 366 * DAY_IN_SECONDS ) {
			$from = current_time( 'Y-m' ) . '-01';
			$to   = gmdate( 'Y-m-t', strtotime( $from ) );
		}
		Ui::header( 'Occupazione delle risorse' );
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="asem-res-report">dal <input type="date" name="from" value="' . esc_attr( $from ) . '"> al <input type="date" name="to" value="' . esc_attr( $to ) . '"> <button class="button">Mostra</button> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-res-report' ) ) . '">Questo mese</a></form>';
		$rows = Resources::occupancy( $from, $to );
		if ( ! $rows ) {
			echo '<p>Nessuna risorsa.</p>';
			Ui::footer();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Risorsa</th><th>Disponibile</th><th>Prenotata dai soci</th><th>Corsi ed eventi</th><th>Allestimenti</th><th>Bloccata</th><th>Occupazione</th><th>Incassi</th><th>Crediti spesi</th></tr></thead><tbody>';
		$tot = array( 'available' => 0, 'booked' => 0, 'activities' => 0, 'setup' => 0, 'blocked' => 0, 'cents' => 0, 'credits' => 0, 'bookings' => 0 );
		foreach ( $rows as $r ) {
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += (int) $r[ $k ];
			}
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $r['id'] ) ) ) . '"><strong>' . esc_html( $r['name'] ) . '</strong></a></td>';
			if ( ResourceRules::SLOT === $r['mode'] ) {
				echo '<td>' . self::hours( (int) $r['available'] ) . '</td><td>' . self::hours( (int) $r['booked'] ) . ( $r['bookings'] ? ' <span class="description">(' . (int) $r['bookings'] . ')</span>' : '' ) . '</td><td>' . self::hours( (int) $r['activities'] ) . '</td><td>' . self::hours( (int) $r['setup'] ) . '</td><td>' . self::hours( (int) $r['blocked'] ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
					. '<td><strong>' . (int) $r['occupancy'] . '%</strong></td>';
			} else {
				echo '<td colspan="5"><span class="description">assegnata a periodo: ' . (int) $r['holders'] . ( 1 === (int) $r['holders'] ? ' assegnazione in corso' : ' assegnazioni in corso' ) . '</span></td><td>—</td>';
			}
			echo '<td>' . ( $r['cents'] ? Ui::money( (int) $r['cents'] ) : '—' ) . '</td><td>' . ( $r['credits'] ? (int) $r['credits'] : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$occ = $tot['available'] > 0 ? (int) min( 100, round( 100 * ( $tot['booked'] + $tot['activities'] ) / $tot['available'] ) ) : 0;
		echo '</tbody><tfoot><tr><th>Totale</th><th>' . self::hours( $tot['available'] ) . '</th><th>' . self::hours( $tot['booked'] ) . '</th><th>' . self::hours( $tot['activities'] ) . '</th><th>' . self::hours( $tot['setup'] ) . '</th><th>' . self::hours( $tot['blocked'] ) . '</th><th>' . $occ . '%</th><th>' . ( $tot['cents'] ? Ui::money( $tot['cents'] ) : '—' ) . '</th><th>' . ( $tot['credits'] ?: '—' ) . '</th></tr></tfoot></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">L\'occupazione è la quota di ore disponibili (tra apertura e chiusura) prenotate dai soci o occupate da corsi ed eventi; gli allestimenti e i blocchi si vedono a parte e non la fanno salire. '
			. 'Gli incassi sono quelli registrati nel periodo per le prenotazioni della risorsa; i crediti sono quelli spesi per le prenotazioni del periodo (l\'entrata è la vendita dei crediti).</p>';
		Ui::footer();
	}
}

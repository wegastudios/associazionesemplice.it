<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\Levels;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cassa → Prenota: si sceglie la persona e poi o la data (e si vede ciò che è disponibile quel giorno: eventi, corsi, risorse) oppure la cosa
 * (e si vedono le date disponibili). Prenota con le stesse azioni delle schede; dopo la prenotazione si passa all'incasso già compilato,
 * dove l'importo si può ridurre a un acconto.
 */
final class BookDeskPage {

	private static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Dove si torna dopo l'azione se non serve passare all'incasso. */
	private static function back( array $person ): string {
		return Ui::url( 'asem-bookdesk', array( 'person_id' => (int) $person['id'], 'mode' => Ui::get_str( 'mode', 'date' ), 'date' => Ui::get_str( 'date' ), 'item' => Ui::get_str( 'item' ) ) );
	}

	/** Pulsante «Prenota» di una data di un evento. */
	private static function book_button( array $person, array $s ): string {
		ob_start();
		Ui::form_open( 'asem_book', self::back( $person ), false, 'asem-inline' );
		$act = Plugin::activities()->get( (int) $s['activity_id'] );
		echo Ui::hidden( 'person_id', (int) $person['id'] ) . Ui::hidden( 'session_id', (int) $s['id'] ) . Ui::hidden( 'activity_id', (int) $s['activity_id'] ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ( $act ? ActivitiesPage::booking_fields( $act, (int) $person['id'] ) : '' )
			. '<button class="button button-primary">Prenota</button>';
		Ui::form_close();
		return (string) ob_get_clean();
	}

	/** Righe delle date di eventi (con posti e contributo) e relativo pulsante. */
	private static function sessions_table( array $sessions, ?array $person, bool $with_date ): void {
		if ( ! $sessions ) {
			echo '<p class="description">Nessun evento.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . ( $with_date ? 'Data' : 'Orario' ) . '</th><th>Evento</th><th>Posti</th><th>Contributo</th><th></th></tr></thead><tbody>';
		foreach ( $sessions as $s ) {
			$seat = Plugin::activities()->seats( (int) $s['id'] );
			$full = null !== $seat['free'] && 0 === $seat['free'];
			$when = ( $with_date ? Docs::d( $s['session_date'] ) . ' ' : '' ) . ( $s['start_time'] ? substr( (string) $s['start_time'], 0, 5 ) : '' );
			$fee  = Plugin::activities()->fee_for( $s, $person ? $person['type'] : MemberType::ORDINARY );
			$act  = '';
			if ( $person ) {
				if ( Plugin::activities()->has_active_booking( (int) $s['id'], (int) $person['id'] ) ) {
					$act = '<span class="asem-ok">già prenotato</span>';
				} elseif ( $full ) {
					$act = '<span class="asem-neg">posti esauriti</span>';
				} else {
					$act = self::book_button( $person, $s );
				}
			}
			echo '<tr><td>' . esc_html( trim( $when ) ) . '</td><td><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => (int) $s['activity_id'] ) ) ) . '">' . esc_html( $s['activity_name'] ) . '</a>'
				. ( ! empty( $s['location'] ) ? ' <span class="description">· ' . esc_html( $s['location'] ) . '</span>' : '' ) . '</td><td>'
				. ( null === $seat['free'] ? 'senza limite' : (int) $seat['free'] . ' liberi su ' . (int) $seat['capacity'] ) . '</td><td>' . ( $fee > 0 ? Ui::money( $fee ) : 'gratuito' ) . '</td><td>' . $act . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
	}

	/** Un corso: iscrizione da un mese. */
	private static function course_row( array $a, ?array $person, string $month ): string {
		$fee = Plugin::activities()->fee_for( $a, $person ? $person['type'] : MemberType::ORDINARY );
		$act = '';
		if ( $person ) {
			if ( in_array( (int) $a['id'], array_map( 'intval', Plugin::activities()->active_activity_ids( (int) $person['id'] ) ), true ) ) {
				$act = '<span class="asem-ok">già iscritto</span>';
			} else {
				ob_start();
				Ui::form_open( 'asem_enroll', self::back( $person ), false, 'asem-inline' );
				echo Ui::hidden( 'person_id', (int) $person['id'] ) . Ui::hidden( 'activity_id', (int) $a['id'] ) . Ui::hidden( 'start_month', $month ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '<button class="button button-primary">Iscrivi da ' . esc_html( $month ) . '</button>';
				Ui::form_close();
				$act = (string) ob_get_clean();
			}
		}
		return '<tr><td><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => (int) $a['id'] ) ) ) . '">' . esc_html( $a['name'] ) . '</a></td><td>' . ( $fee > 0 ? Ui::money( $fee ) . ' al mese' : 'gratuito' ) . '</td><td>' . $act . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Righe [titolo, info, indirizzo] aggiunte da un'estensione (ad esempio le risorse). */
	private static function extra_table( string $title, array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['title'] ) . '</td><td>' . esc_html( (string) $r['info'] ) . '</td><td><a class="button" href="' . esc_url( (string) $r['url'] ) . '">' . esc_html( (string) ( $r['label'] ?? 'Apri' ) ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render(): void {
		$today  = current_time( 'Y-m-d' );
		$mode   = 'item' === Ui::get_str( 'mode' ) ? 'item' : 'date';
		$pid    = Ui::get_int( 'person_id' );
		$date   = self::valid_date( Ui::get_str( 'date' ) ) ? Ui::get_str( 'date' ) : $today;
		$item   = Ui::get_str( 'item' );
		$person = $pid ? Plugin::people()->get( $pid ) : null;
		$sy     = Settings::social_year( $date );
		Ui::header( 'Prenota' );
		echo '<p class="description">Scegli la persona e poi la data (vedi cosa è disponibile quel giorno) oppure la cosa (vedi le date disponibili). Dopo la prenotazione si passa all\'incasso già compilato: se la persona versa solo una parte, riduci l\'importo e il resto resta come acconto.</p>';

		// Cose prenotabili: eventi e corsi dell'anno sociale, più quelle aggiunte da un'estensione
		$groups = array( 'Eventi' => array(), 'Corsi' => array() );
		foreach ( Plugin::activities()->for_year( $sy->label() ) as $a ) {
			$groups[ ActivityKind::COURSE === $a['kind'] ? 'Corsi' : 'Eventi' ][ 'a:' . (int) $a['id'] ] = $a['name'];
		}
		foreach ( (array) apply_filters( 'asem_bookdesk_items', array() ) as $g => $opts ) {
			$groups[ $g ] = (array) $opts;
		}

		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-bookdesk">'
			. '<p><strong>Chi</strong> ' . Ui::person_select( 'person_id', Plugin::people()->search(), $pid ?: null, '— scegli la persona —', 'asem-desk-person' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' <a href="' . esc_url( Ui::url( 'asem-person', array( 'type' => 'ordinary' ) ) ) . '">+ nuovo socio</a></p>'
			. '<p><label><input type="radio" name="mode" value="date"' . checked( 'date' === $mode, true, false ) . '> <strong>Parto dalla data</strong></label> '
			. '<input type="date" name="date" value="' . esc_attr( $date ) . '"> &nbsp; '
			. '<label><input type="radio" name="mode" value="item"' . checked( 'item' === $mode, true, false ) . '> <strong>Parto dalla cosa</strong></label> <select name="item"><option value="">— scegli —</option>';
		foreach ( $groups as $label => $opts ) {
			if ( ! $opts ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( (string) $label ) . '">';
			foreach ( $opts as $val => $name ) {
				echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( $item, (string) $val, false ) . '>' . esc_html( (string) $name ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select> <button class="button button-primary">Mostra</button></p></form>';

		if ( $pid && ! $person ) {
			echo '<p class="asem-neg">Persona non trovata.</p>';
		}
		if ( $person && MemberType::GUEST !== $person['type'] && Plugin::people()->is_suspended( (int) $person['id'] ) ) {
			echo '<p class="asem-info asem-info-warn">⚠ Socio sospeso (inattivo): riattivalo dalla sua scheda prima di prenotare.</p>';
			$person = null;
		}
		if ( ! $person ) {
			echo '<p class="description">Scegli una persona per poter prenotare; intanto puoi guardare cosa è disponibile.</p>';
		} else {
			echo '<p><strong>' . esc_html( trim( $person['first_name'] . ' ' . $person['last_name'] ) ) . '</strong> · ' . esc_html( Levels::label( $person ) ) . '</p>';
		}

		if ( 'date' === $mode ) {
			echo '<h2>Disponibile il ' . esc_html( Docs::d( $date ) ) . '</h2><h3>Eventi</h3>';
			self::sessions_table( Plugin::activities()->sessions_on( $date ), $person, false );
			echo '<h3>Corsi (anno sociale ' . esc_html( $sy->label() ) . ')</h3>';
			$courses = array_filter(
				Plugin::activities()->for_year( $sy->label() ),
				function ( $a ) {
					return ActivityKind::COURSE === $a['kind'];
				}
			);
			if ( $courses ) {
				$month = $sy->clamp( substr( $date, 0, 7 ) );
				echo '<table class="widefat striped"><thead><tr><th>Corso</th><th>Quota</th><th></th></tr></thead><tbody>';
				foreach ( $courses as $a ) {
					echo self::course_row( $a, $person, $month ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			} else {
				echo '<p class="description">Nessun corso.</p>';
			}
			self::extra_table( 'Risorse', (array) apply_filters( 'asem_bookdesk_date', array(), $date, $person ) );
		} elseif ( '' === $item ) {
			echo '<p class="description">Scegli una cosa dall\'elenco per vedere le date disponibili.</p>';
		} elseif ( 0 === strpos( $item, 'a:' ) ) {
			$a = Plugin::activities()->get( (int) substr( $item, 2 ) );
			if ( $a && ActivityKind::COURSE === $a['kind'] ) {
				echo '<h2>' . esc_html( $a['name'] ) . '</h2><table class="widefat striped"><thead><tr><th>Corso</th><th>Quota</th><th></th></tr></thead><tbody>'
					. self::course_row( $a, $person, Settings::social_year()->clamp( substr( $today, 0, 7 ) ) ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( $a ) {
				echo '<h2>' . esc_html( $a['name'] ) . ': prossime date</h2>';
				self::sessions_table( Plugin::activities()->upcoming_sessions( 40, (int) $a['id'] ), $person, true );
			}
		} else {
			$extra = (array) apply_filters( 'asem_bookdesk_item', array(), $item, $person );
			if ( $extra ) {
				self::extra_table( 'Giorni con posto libero', $extra );
			} else {
				echo '<p class="description">Niente di disponibile nei prossimi giorni.</p>';
			}
		}
		Ui::footer();
	}
}

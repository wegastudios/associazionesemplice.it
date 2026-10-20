<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\TribeImport;

defined( 'ABSPATH' ) || exit;

/** Importazione degli eventi di The Events Calendar, per poterli riproporre. */
final class TribeImportPage {

	public static function render(): void {
		Ui::header( 'Importa da The Events Calendar' );
		$found = TribeImport::found();
		echo '<p class="description">Porta qui gli eventi di The Events Calendar (passati e futuri) come eventi una tantum (o come corsi, per le categorie che indichi), con titolo, data, orario, luogo e descrizione. Così un evento già fatto si può <strong>riproporre</strong> in una nuova data dalla sua scheda, senza rifare tutto. '
			. 'Non si importano costi, prenotazioni, immagini e categorie. The Events Calendar può anche essere disattivato: gli eventi si leggono dal database del sito.</p>';
		if ( ! $found ) {
			echo '<p>Non ho trovato eventi di The Events Calendar su questo sito.</p>';
			Ui::footer();
			return;
		}
		$todo = count( array_filter( $found, function ( $f ) {
			return ! $f['imported'];
		} ) );
		echo '<p><strong>' . (int) count( $found ) . '</strong> eventi trovati, <strong>' . (int) $todo . '</strong> ancora da importare.</p>';
		if ( $todo ) {
			Ui::form_open( 'asem_tec_import', Ui::url( 'asem-tec' ) );
			$cats = TribeImport::categories();
			if ( $cats ) {
				echo '<div class="asem-card"><h2>Quali categorie sono corsi?</h2><p class="description">The Events Calendar non distingue i corsi: di solito sono eventi ripetuti ogni settimana, con una categoria. '
					. 'Gli eventi delle categorie che spunti diventano <strong>corsi</strong>: tutte le lezioni con lo stesso titolo nello stesso anno sociale formano un solo corso, e giorno e orario che si ripetono diventano la lezione settimanale. '
					. 'Gli altri eventi restano eventi una tantum.</p>';
				foreach ( $cats as $c ) {
					echo '<p><label><input type="checkbox" name="course_cats[]" value="' . (int) $c['id'] . '"' . checked( ! empty( $c['course'] ), true, false ) . '> ' . esc_html( $c['name'] ) . ' <span class="description">(' . (int) $c['count'] . ' eventi)</span></label></p>';
				}
				echo '</div>';
			}
			echo '<p><button class="button button-primary">Importa i ' . (int) $todo . ' eventi</button> <span class="description">Si può rilanciare senza creare doppioni.</span></p>';
			Ui::form_close();
		}
		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Evento</th><th>Stato</th></tr></thead><tbody>';
		foreach ( array_slice( $found, 0, 300 ) as $f ) {
			echo '<tr><td>' . esc_html( '' !== $f['date'] ? Ui::date( $f['date'] ) : '—' ) . '</td><td>' . esc_html( '' !== $f['title'] ? $f['title'] : '(senza titolo)' ) . '</td><td>'
				. ( $f['imported'] ? '<a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $f['imported'] ) ) ) . '">importato</a>' : 'da importare' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $found ) > 300 ) {
			echo '<p class="description">Sono mostrati i primi 300; l\'importazione riguarda tutti.</p>';
		}
		Ui::footer();
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\TribeImport;

defined( 'ABSPATH' ) || exit;

/** Importazione degli eventi di The Events Calendar, per poterli riproporre. */
final class TribeImportPage {

	public static function render(): void {
		Ui::header( 'Importa da The Events Calendar' );
		$found = TribeImport::found();
		echo '<p class="description">Porta qui gli eventi di The Events Calendar (passati e futuri) come eventi una tantum (o come corsi, per le categorie che indichi), con titolo, data, orario, luogo e descrizione. Gli eventi ripetuti con lo stesso titolo nello stesso anno sociale diventano un solo evento ricorrente. Così un evento già fatto si può <strong>riproporre</strong> in una nuova data dalla sua scheda, senza rifare tutto. '
			. 'Per ogni evento si compila anche la <strong>pagina pubblica</strong> (Eventi sul sito): descrizione, immagine in evidenza, categoria, organizzatore, sito dell\'evento e giorni; di norma resta in bozza, così la controlli prima di pubblicarla. '
			. 'Non si importano costi e prenotazioni. The Events Calendar può anche essere disattivato: gli eventi si leggono dal database del sito.</p>';
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
			self::steps( $todo );
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

	/** I due passi: 1. quali categorie sono dei corsi; 2. anteprima per titolo (con correzioni a mano) e importazione. */
	private static function steps( int $todo ): void {
		$cats = TribeImport::categories();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- scelte di sola lettura per l'anteprima (da qui non si importa nulla); i valori diventano numeri interi
		$preview = isset( $_GET['preview'] ) || ! $cats;
		$picked  = isset( $_GET['course_cats'] ) && is_array( $_GET['course_cats'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_GET['course_cats'] ) ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $preview ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="asem-tec"><input type="hidden" name="preview" value="1">';
			echo '<div class="asem-card"><h2>1. Quali categorie sono corsi?</h2><p class="description">The Events Calendar non distingue i corsi: di solito sono eventi ripetuti ogni settimana, con una categoria. '
				. 'Regola: un titolo diventa un <strong>corso</strong> solo se ha una categoria spuntata <strong>e</strong> si ripete come lezione settimanale (almeno 3 volte lo stesso giorno della settimana alla stessa ora): tutte le lezioni con lo stesso titolo nello stesso anno sociale formano un solo corso. '
				. 'Tutto il resto resta evento (una tantum, o ricorrente se ha più date). Al passo successivo vedi cosa diventa ogni titolo e puoi correggerlo.</p>';
			foreach ( $cats as $c ) {
				echo '<p><label><input type="checkbox" name="course_cats[]" value="' . (int) $c['id'] . '"' . checked( ! empty( $c['course'] ), true, false ) . '> ' . esc_html( $c['name'] ) . ' <span class="description">(' . (int) $c['count'] . ' eventi)</span></label></p>';
			}
			echo '</div><p><button class="button button-primary">Avanti: vedi cosa diventa ogni titolo</button></p></form>';
			return;
		}
		$plan = TribeImport::plan( $picked );
		Ui::form_open( 'asem_tec_import', Ui::url( 'asem-tec' ) );
		foreach ( $picked as $cid ) {
			echo '<input type="hidden" name="course_cats[]" value="' . (int) $cid . '">';
		}
		echo '<div class="asem-card"><h2>2. Cosa diventa ogni titolo</h2><p class="description">Un titolo è un <strong>corso</strong> se ha una categoria dei corsi e almeno 3 lezioni lo stesso giorno alla stessa ora; altrimenti è un <strong>evento</strong>. Se la proposta non ti convince, cambiala qui sotto.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Titolo</th><th>Anno sociale</th><th>Date</th><th>Diventa</th><th>Perché</th></tr></thead><tbody>';
		foreach ( $plan as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['year'] ) . '</td><td>' . (int) $row['count'] . '</td><td><select name="kind[' . esc_attr( $row['id'] ) . ']">'
				. '<option value="event"' . selected( 'event', $row['kind'], false ) . '>Evento</option><option value="course"' . selected( 'course', $row['kind'], false ) . '>Corso</option></select></td><td class="description">' . esc_html( $row['reason'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p><label><input type="checkbox" name="publish_future" value="1"> Pubblica subito le pagine degli eventi futuri</label> <span class="description">Spenta: le pagine restano in bozza. Acceso: solo gli eventi con una data futura vengono pubblicati.</span></p>';
		echo '<p><button class="button button-primary">Importa i ' . (int) $todo . ' eventi</button> '
			. ( $cats ? '<a class="button" href="' . esc_url( Ui::url( 'asem-tec' ) ) . '">Indietro</a> ' : '' ) . '<span class="description">Si può rilanciare senza creare doppioni.</span></p>';
		Ui::form_close();
	}
}

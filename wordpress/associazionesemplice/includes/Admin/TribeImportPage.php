<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\TribeImport;

defined( 'ABSPATH' ) || exit;

/** Importazione degli eventi di The Events Calendar, per poterli riproporre. */
final class TribeImportPage {

	public static function render(): void {
		Ui::header( 'Importa da The Events Calendar' );
		$found = TribeImport::found();
		echo '<p class="description">Porta qui gli eventi di The Events Calendar (passati e futuri) come eventi una tantum (o come corsi, per le categorie che indichi tu), con titolo, data, orario, luogo e descrizione. Ogni evento di The Events Calendar diventa una sola attività: niente viene raggruppato, nemmeno gli eventi con lo stesso titolo. Così un evento già fatto si può <strong>riproporre</strong> in una nuova data dalla sua scheda, senza rifare tutto. '
			. 'Per ogni evento si compila anche la <strong>pagina pubblica</strong> (Eventi sul sito): descrizione, immagine in evidenza, categorie, organizzatore, sito dell\'evento e giorni; di norma resta in bozza, così la controlli prima di pubblicarla. '
			. 'Non si importano costi e prenotazioni. The Events Calendar può anche essere disattivato: gli eventi si leggono dal database del sito. Se ci ripensi, ogni import si può <strong>annullare</strong> da «Import già fatti» in fondo a questa pagina: eventi e corsi creati senza prenotazioni né iscritti vengono tolti (con la loro pagina pubblica), gli altri restano.</p>';
		if ( ! $found ) {
			echo '<p>Non ho trovato eventi di The Events Calendar su questo sito.</p>';
			ImportPage::history( 'tribe', 'asem-tec' );
			Ui::footer();
			return;
		}
		$todo = count( array_filter( $found, function ( $f ) {
			return ! $f['imported'];
		} ) );
		echo '<p><strong>' . (int) count( $found ) . '</strong> eventi trovati, <strong>' . (int) $todo . '</strong> ancora da importare.</p>';
		$listed = $todo ? self::steps() : false; // true se il riepilogo ha già elencato gli eventi senza data
		if ( ! $listed ) {
			$none = TribeImport::undatable();
			if ( $none ) {
				echo '<div class="asem-card"><h2>Eventi che non si possono importare</h2><p>Manca la data, quindi non si importano. Correggili in The Events Calendar e rilancia l\'import:' . self::undatable_list( $none ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- elenco già escapato
			}
		}
		ImportPage::history( 'tribe', 'asem-tec' ); // import già fatti, con «Annulla questo import»
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

	/** Gli eventi senza data, per titolo, con il link per correggerli in The Events Calendar (se si può aprire). */
	private static function undatable_list( array $list ): string {
		$h = '<ul style="list-style:circle;margin-left:1.5em">';
		foreach ( $list as $f ) {
			$h .= '<li>' . esc_html( $f['title'] ) . ( '' !== $f['link'] ? ' <a href="' . esc_url( $f['link'] ) . '">modifica in The Events Calendar</a>' : '' ) . '</li>';
		}
		return $h . '</ul>';
	}

	/** I due passi: 1. per ogni categoria, «Evento» o «Corso»; 2. riepilogo di cosa succede e importazione. Nessuna scelta per singolo titolo. */
	private static function steps(): bool {
		$cats = TribeImport::categories();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- scelte di sola lettura per il riepilogo (da qui non si importa nulla); gli indici diventano numeri interi
		$preview = isset( $_GET['preview'] ) || ! $cats;
		$picked  = array();
		if ( isset( $_GET['cat_kind'] ) && is_array( $_GET['cat_kind'] ) ) {
			foreach ( (array) wp_unslash( $_GET['cat_kind'] ) as $cid => $kind ) {
				if ( 'course' === sanitize_key( (string) $kind ) && (int) $cid > 0 ) {
					$picked[] = (int) $cid;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $preview ) {
			$none = TribeImport::uncategorized();
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="asem-tec"><input type="hidden" name="preview" value="1">';
			echo '<div class="asem-card"><h2>1. Ogni categoria è un evento o un corso?</h2><p class="description">The Events Calendar non distingue i corsi: lo decidi tu, categoria per categoria, prima di partire. '
				. 'Ti propongo «Corso» solo per le categorie che nel nome dicono «corso» o «lezione»; tutte le altre sono «Evento». Gli eventi senza categoria sono eventi. '
				. 'Un evento è un corso solo se nessuna delle sue categorie è «Evento» e almeno una è «Corso». Prima vengono importati tutti gli eventi, poi i corsi. '
				. 'Le categorie di The Events Calendar (anche quelle dei corsi) vengono ricreate come categorie pubbliche con lo stesso nome e assegnate a tutte le pagine create.</p>';
			echo '<table class="widefat striped"><thead><tr><th>Categoria</th><th>Eventi</th><th>Diventa</th></tr></thead><tbody>';
			foreach ( $cats as $c ) {
				echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>' . (int) $c['count'] . '</td><td><select name="cat_kind[' . (int) $c['id'] . ']">'
					. '<option value="event"' . ( empty( $c['course'] ) ? ' selected' : '' ) . '>Evento</option><option value="course"' . ( empty( $c['course'] ) ? '' : ' selected' ) . '>Corso</option></select></td></tr>';
			}
			echo '</tbody></table>';
			if ( $none ) {
				echo '<p class="description">' . (int) $none . ( 1 === $none ? ' evento senza categoria: è un evento.' : ' eventi senza categoria: sono eventi.' ) . '</p>';
			}
			echo '</div><p><button class="button button-primary">Continua</button></p></form>';
			return false;
		}
		$sum = TribeImport::summary( $picked );
		Ui::form_open( 'asem_tec_import', Ui::url( 'asem-tec' ) );
		foreach ( $picked as $cid ) {
			echo '<input type="hidden" name="course_cats[]" value="' . (int) $cid . '">';
		}
		echo '<div class="asem-card"><h2>2. Cosa succederà</h2><ul style="list-style:disc;margin-left:1.5em">';
		echo '<li><strong>' . (int) $sum['events'] . '</strong> ' . ( 1 === (int) $sum['events'] ? 'evento' : 'eventi' ) . ', importati per primi.</li>';
		echo '<li><strong>' . (int) $sum['courses'] . '</strong> ' . ( 1 === (int) $sum['courses'] ? 'corso' : 'corsi' ) . ', importati dopo gli eventi.</li>';
		echo '<li class="description">Ogni evento di The Events Calendar diventa una sola attività, anche se ce ne sono altri con lo stesso titolo: niente viene raggruppato.</li>';
		if ( $sum['categories'] ) {
			echo '<li>Categorie pubbliche ricreate (o riusate se esistono) e assegnate a tutte le pagine create: <span class="description">' . esc_html( implode( ', ', $sum['categories'] ) ) . '</span>.</li>';
		}
		if ( $sum['failed'] ) {
			echo '<li>' . (int) $sum['failed'] . ( 1 === (int) $sum['failed'] ? ' evento non si importa' : ' eventi non si importano' ) . ' perché manca la data:' . self::undatable_list( $sum['failed_list'] ) . '</li>';
		}
		echo '</ul></div>';
		echo '<p><label><input type="checkbox" name="publish_future" value="1"> Pubblica subito le pagine degli eventi futuri</label> <span class="description">Spenta: le pagine restano in bozza. Acceso: solo gli eventi con una data futura vengono pubblicati.</span></p>';
		echo '<p><button class="button button-primary">Importa</button> '
			. ( $cats ? '<a class="button" href="' . esc_url( Ui::url( 'asem-tec' ) ) . '">Indietro</a> ' : '' ) . '<span class="description">Si può rilanciare senza creare doppioni.</span></p>';
		Ui::form_close();
		return true;
	}
}

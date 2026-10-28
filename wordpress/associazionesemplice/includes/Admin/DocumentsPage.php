<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Documents;
use AssociazioneSemplice\DocumentRules;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Registri → Documenti: statuto, regolamenti, verbali firmati, moduli. */
final class DocumentsPage {

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::form( $view );
			return;
		}
		$cat = Ui::get_str( 'category' );
		Ui::header( 'Documenti', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-documents', array( 'new' => 1 ) ) ) . '">Carica un documento</a>' );
		echo '<p class="description">L\'archivio dei documenti dell\'associazione. I file non hanno un indirizzo pubblico: si aprono solo con il controllo dei permessi. Per mostrare i documenti «Pubblico» sul sito usa lo shortcode <code>[associazionesemplice_documenti]</code>.</p>';
		echo wp_kses( '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-documents"><select name="category">' . Ui::options( array_combine( Documents::categories(), Documents::categories() ), $cat, 'Tutte le categorie' ) . '</select> <button class="button">Filtra</button></form>', Html::allowed() );
		echo '<table class="widefat striped"><thead><tr><th>Titolo</th><th>Categoria</th><th>Data</th><th>Chi lo vede</th><th>File</th></tr></thead><tbody>';
		$rows = Documents::all( $cat );
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun documento.</td></tr>';
		}
		foreach ( $rows as $d ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-documents', array( 'view' => (int) $d['id'] ) ) ) . '">' . esc_html( $d['title'] ) . '</a></td><td>' . esc_html( $d['category'] ) . '</td><td>'
				. ( $d['doc_date'] ? esc_html( ( new \DateTimeImmutable( $d['doc_date'] ) )->format( 'd/m/Y' ) ) : '—' ) . '</td><td>' . esc_html( DocumentRules::visibilities()[ $d['visibility'] ] ?? '' )
				. '</td><td><a href="' . esc_url( Documents::url( (int) $d['id'] ) ) . '" target="_blank">' . esc_html( $d['file_name'] ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function form( int $id ): void {
		$d = $id ? Documents::get( $id ) : null;
		Ui::header( $d ? $d['title'] : 'Carica un documento', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-documents' ) ) . '">← Tutti i documenti</a>' );
		if ( $id && ! $d ) {
			echo '<p>Documento non trovato.</p>';
			Ui::footer();
			return;
		}
		$v = function ( string $k, string $default = '' ) use ( $d ) {
			return $d ? (string) ( $d[ $k ] ?? '' ) : $default;
		};
		Ui::form_open( 'asem_document_save', Ui::url( 'asem-documents', $d ? array( 'view' => $id ) : array( 'new' => 1 ) ), true );
		echo wp_kses( Ui::hidden( 'id', $id ), Html::allowed() );
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Titolo *</th><td><input type="text" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Categoria</th><td><input type="text" name="category" value="' . esc_attr( $v( 'category' ) ) . '" class="regular-text" maxlength="80" list="asem-doc-cats"><datalist id="asem-doc-cats">';
		foreach ( Documents::categories() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '">';
		}
		echo '</datalist></td></tr>';
		echo '<tr><th>Data del documento</th><td><input type="date" name="doc_date" value="' . esc_attr( $v( 'doc_date' ) ) . '"></td></tr>';
		echo wp_kses( '<tr><th>Chi lo vede</th><td><select name="visibility">' . Ui::options( DocumentRules::visibilities(), $v( 'visibility', DocumentRules::MEMBERS ) ) . '</select><p class="description">«Soci»: i soci in regola, dall\'area riservata. «Pubblico»: chiunque, con lo shortcode.</p></td></tr>', Html::allowed() );
		echo '<tr><th>Descrizione</th><td><input type="text" name="description" value="' . esc_attr( $v( 'description' ) ) . '" class="large-text" maxlength="255"></td></tr>';
		echo '<tr><th>File' . ( $d ? '' : ' *' ) . '</th><td><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.odt,.xls,.xlsx,.ods,.txt"' . ( $d ? '' : ' required' ) . '>';
		if ( $d ) {
			echo ' <a href="' . esc_url( Documents::url( $id ) ) . '" target="_blank">' . esc_html( $d['file_name'] ) . '</a> <span class="description">(se ne scegli un altro, lo sostituisce)</span>';
		}
		echo '<p class="description">PDF, foto, Word, Excel o LibreOffice, fino a ' . (int) \AssociazioneSemplice\Limits::get( 'attach_max_mb' ) . ' MB.</p></td></tr></tbody></table>';
		submit_button( $d ? 'Salva' : 'Carica' );
		Ui::form_close();
		if ( $d ) {
			echo wp_kses( Ui::confirm_button( 'asem_document_delete', Ui::url( 'asem-documents' ), array( 'id' => $id ), 'Elimina', 'Eliminare questo documento dall\'archivio?' ), Html::allowed() );
		}
		Ui::footer();
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\MemberBook;
use AssociazioneSemplice\Minutes;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Registri: libro soci e verbali. */
final class RegistersPage {

	public static function date( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	public static function downloads( string $what, array $args ): string {
		return '<a class="button" target="_blank" href="' . esc_url( Docs::url( $what, $args ) ) . '">PDF</a> <a class="button" href="' . esc_url( Docs::url( $what, $args, 'csv' ) ) . '">CSV</a>';
	}

	// ---------- Libro soci ----------

	public static function render_book(): void {
		$filter = Ui::get_str( 'filter' );
		$filter = in_array( $filter, array( MemberBook::IN_FORCE, MemberBook::LEFT ), true ) ? $filter : '';
		Ui::header( 'Libro soci' );
		$rows = MemberBook::rows( $filter );
		echo '<p class="description">L\'elenco progressivo dei soci con data di ingresso e, se c\'è stata, di cessazione (recesso, esclusione, decesso). Gli ospiti non sono soci e non compaiono. '
			. 'La cessazione si registra dalla scheda del socio.</p>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-book"><select name="filter">'
			. Ui::options( array( MemberBook::IN_FORCE => 'In carica', MemberBook::LEFT => 'Cessati' ), $filter, 'Tutti' ) . '</select> <button class="button">Filtra</button> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. self::downloads( 'book', array( 'filter' => $filter ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$grid = \AssociazioneSemplice\CardLayout::sheet_grid();
		$per  = Ui::get_int( 'per' );
		$opts = array( 0 => 'il massimo (' . $grid['max'] . ')' );
		foreach ( array( 4, 6, 8, 10, 12, 16 ) as $n ) {
			if ( $n < $grid['max'] ) {
				$opts[ $n ] = (string) $n;
			}
		}
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-book"><input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">'
			. '<strong>Tessere in PDF</strong> (fogli A4 da stampare e ritagliare; i posti che restano sono modelli vuoti): per foglio <select name="per">' . Ui::options( $opts, isset( $opts[ $per ] ) ? $per : 0 ) . '</select> <button class="button">Applica</button> ' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<a class="button button-primary" target="_blank" href="' . esc_url( Docs::url( 'cards', array( 'filter' => $filter, 'per' => isset( $opts[ $per ] ) ? $per : 0 ) ) ) . '">Genera le tessere (' . ( '' === $filter ? 'soci in carica' : ( MemberBook::IN_FORCE === $filter ? 'in carica' : 'cessati' ) ) . ')</a></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">' . count( $rows ) . ' soci.</p>';
		echo '<table class="widefat striped"><thead><tr><th>N.</th><th>Cognome e nome</th><th>Codice fiscale</th><th>Livello</th><th>Ingresso</th><th>Cessazione</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessun socio.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . (int) $r['n'] . '</td><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $r['id'] ) ) ) . '">' . esc_html( $r['name'] ) . '</a></td><td>' . esc_html( $r['tax_code'] ) . '</td><td>' . esc_html( $r['level'] ) . '</td><td>' . self::date( $r['joined_on'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '</td><td>' . ( $r['left_on'] ? self::date( $r['left_on'] ) . ( '' !== $r['left_reason'] ? ' · ' . esc_html( $r['left_reason'] ) : '' ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	/** Riquadro nella scheda del socio: registra la cessazione. */
	public static function panel_left( array $p ): void {
		if ( ! \AssociazioneSemplice\MemberType::is_member( $p['type'] ) ) {
			return;
		}
		echo '<div class="asem-card"><h2>Libro soci</h2>';
		Ui::form_open( 'asem_member_left', Ui::url( 'asem-person', array( 'id' => (int) $p['id'] ) ) );
		echo Ui::hidden( 'id', $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Ingresso: <strong>' . self::date( (string) $p['joined_on'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Cessazione <input type="date" name="left_on" value="' . esc_attr( (string) $p['left_on'] ) . '"> motivo <input type="text" name="left_reason" value="' . esc_attr( (string) $p['left_reason'] ) . '" placeholder="recesso, esclusione, decesso…" maxlength="190"> <button class="button">Salva</button></p>';
		Ui::form_close();
		echo '<p class="description">Lascia vuota la data per tenere il socio in carica. Il socio cessato resta nel libro soci con le sue date.</p></div>';
	}

	// ---------- Verbali ----------

	public static function render_minutes(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::minute_form( $view );
			return;
		}
		$kind = Ui::get_str( 'kind' );
		$year = Ui::get_int( 'year' );
		Ui::header( 'Verbali', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-minutes', array( 'new' => 1 ) ) ) . '">Nuovo verbale</a>' );
		echo '<p class="description">Il libro dei verbali delle assemblee dei soci e delle riunioni del consiglio direttivo, numerati per tipo e anno. Da ogni verbale si stampa il PDF da firmare.</p>';
		$years = array();
		for ( $y = (int) substr( Db::today(), 0, 4 ); $y >= (int) substr( Db::today(), 0, 4 ) - 10; $y-- ) {
			$years[ $y ] = (string) $y;
		}
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-minutes"><select name="kind">' . Ui::options( Minutes::kinds(), $kind, 'Tutti i tipi' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. '</select> <select name="year">' . Ui::options( $years, $year ?: null, 'Tutti gli anni' ) . '</select> <button class="button">Filtra</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$rows  = Minutes::all( $kind ?: null, $year ?: null );
		$kinds = Minutes::kinds();
		echo '<table class="widefat striped"><thead><tr><th>N.</th><th>Data</th><th>Tipo</th><th>Titolo</th><th>Approvato</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun verbale.</td></tr>';
		}
		foreach ( $rows as $m ) {
			echo '<tr><td>' . esc_html( $m['number'] ) . '</td><td>' . self::date( $m['meeting_date'] ) . '</td><td>' . esc_html( $kinds[ $m['kind'] ] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '</td><td><a href="' . esc_url( Ui::url( 'asem-minutes', array( 'view' => (int) $m['id'] ) ) ) . '">' . esc_html( $m['title'] ) . '</a></td><td>' . ( $m['approved_on'] ? self::date( $m['approved_on'] ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function minute_form( int $id ): void {
		$m = $id ? Minutes::get( $id ) : null;
		Ui::header( $m ? 'Verbale ' . $m['number'] : 'Nuovo verbale', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-minutes' ) ) . '">← Tutti i verbali</a>' );
		if ( $id && ! $m ) {
			echo '<p>Verbale non trovato.</p>';
			Ui::footer();
			return;
		}
		$v = function ( string $k, string $default = '' ) use ( $m ) {
			return $m ? (string) ( $m[ $k ] ?? '' ) : $default;
		};
		if ( $m ) {
			echo '<p><a class="button" target="_blank" href="' . esc_url( Docs::url( 'minute', array( 'id' => $id ) ) ) . '">Stampa il PDF</a></p>';
		}
		Ui::form_open( 'asem_minute_save', Ui::url( 'asem-minutes', $m ? array( 'view' => $id ) : array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Tipo *</th><td><select name="kind">' . Ui::options( Minutes::kinds(), $v( 'kind', Minutes::ASSEMBLY ) ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Data *</th><td><input type="date" name="meeting_date" value="' . esc_attr( $v( 'meeting_date', Db::today() ) ) . '" required></td></tr>';
		echo '<tr><th>Luogo</th><td><input type="text" name="place" value="' . esc_attr( $v( 'place' ) ) . '" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th>Titolo *</th><td><input type="text" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Presenti</th><td><textarea name="attendees" rows="4" class="large-text">' . esc_textarea( $v( 'attendees' ) ) . '</textarea><p class="description">Nomi dei presenti, deleghe, numero dei soci presenti.</p></td></tr>';
		echo '<tr><th>Ordine del giorno</th><td><textarea name="agenda" rows="4" class="large-text">' . esc_textarea( $v( 'agenda' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Svolgimento e deliberazioni *</th><td><textarea name="body" rows="16" class="large-text" required>' . esc_textarea( $v( 'body' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Approvato il</th><td><input type="date" name="approved_on" value="' . esc_attr( $v( 'approved_on' ) ) . '"><p class="description">La data in cui il verbale è stato letto e approvato (di solito alla riunione successiva).</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $m ? 'Salva' : 'Crea il verbale' );
		Ui::form_close();
		if ( $m ) {
			Ui::form_open( 'asem_minute_delete', Ui::url( 'asem-minutes' ), false, 'asem-confirm' );
			echo Ui::hidden( 'id', $id ) . '<button class="button button-link-delete" data-confirm="Eliminare questo verbale?">Elimina il verbale</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		Ui::footer();
	}

}

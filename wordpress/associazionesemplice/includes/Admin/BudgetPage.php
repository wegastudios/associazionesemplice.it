<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Assemblies;
use AssociazioneSemplice\Budget;
use AssociazioneSemplice\BudgetRules;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Money;

defined( 'ABSPATH' ) || exit;

/** Contabilità → Preventivo: elenco dei preventivi, voci per categoria e confronto con il consuntivo della prima nota. */
final class BudgetPage {

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::detail( $view );
			return;
		}
		Ui::header( 'Bilancio preventivo', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-budget', array( 'new' => 1 ) ) ) . '">Nuovo preventivo</a>' );
		echo '<p class="description">Per ogni anno scrivi quanto prevedi di incassare e di spendere per categoria; il confronto con il consuntivo si aggiorna da solo con i movimenti della prima nota (i giroconti e i movimenti annullati non contano).</p>';
		echo '<table class="widefat striped"><thead><tr><th>Anno</th><th>Preventivo</th><th>Stato</th><th>Entrate previste</th><th>Uscite previste</th></tr></thead><tbody>';
		$rows = Budget::all();
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun preventivo.</td></tr>';
		}
		foreach ( $rows as $b ) {
			$t = BudgetRules::totals( array_map( function ( $l ) {
				return array( 'type' => $l['type'], 'planned' => (int) $l['planned_cents'], 'actual' => 0 );
			}, Budget::lines( (int) $b['id'] ) ) );
			echo '<tr><td>' . (int) $b['year'] . '</td><td><a href="' . esc_url( Ui::url( 'asem-budget', array( 'view' => (int) $b['id'] ) ) ) . '">' . esc_html( $b['title'] ) . '</a></td><td>' . esc_html( BudgetRules::statuses()[ $b['status'] ] ?? '' )
				. '</td><td>' . esc_html( Money::format( $t['income_planned'] ) ) . '</td><td>' . esc_html( Money::format( $t['expense_planned'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function back( int $id ): string {
		return Ui::url( 'asem-budget', array( 'view' => $id ) );
	}

	private static function detail( int $id ): void {
		$b = $id ? Budget::get( $id ) : null;
		Ui::header( $b ? $b['title'] : 'Nuovo preventivo', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-budget' ) ) . '">← Tutti i preventivi</a>' );
		if ( $id && ! $b ) {
			echo '<p>Preventivo non trovato.</p>';
			Ui::footer();
			return;
		}
		self::form( $b );
		if ( $b ) {
			self::compare_table( $b );
			if ( BudgetRules::DRAFT === $b['status'] ) {
				self::lines_form( $b );
				self::copy_form( $b );
			}
			self::approval( $b );
		}
		Ui::footer();
	}

	private static function form( ?array $b ): void {
		$id = $b ? (int) $b['id'] : 0;
		Ui::form_open( 'asem_budget_save', $b ? self::back( $id ) : Ui::url( 'asem-budget', array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Anno *</th><td><input type="number" name="year" min="2000" max="2100" value="' . esc_attr( $b ? (string) $b['year'] : (string) ( (int) substr( Db::today(), 0, 4 ) + ( (int) substr( Db::today(), 5, 2 ) >= 9 ? 1 : 0 ) ) ) . '" required></td></tr>';
		echo '<tr><th>Titolo</th><td><input type="text" name="title" class="large-text" maxlength="190" value="' . esc_attr( $b ? $b['title'] : '' ) . '" placeholder="Bilancio preventivo"></td></tr>';
		echo '<tr><th>Note</th><td><textarea name="notes" rows="3" class="large-text">' . esc_textarea( $b ? (string) $b['notes'] : '' ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $b ? 'Salva' : 'Crea' );
		Ui::form_close();
	}

	private static function compare_table( array $b ): void {
		$id = (int) $b['id'];
		$c  = Budget::compare( $id );
		echo '<h2>Preventivo e consuntivo ' . (int) $b['year'] . '</h2>';
		if ( ! $c['rows'] ) {
			echo '<p>Nessuna voce: scrivi gli importi qui sotto.</p>';
			return;
		}
		$labels = BudgetRules::status_labels();
		$cls    = array( BudgetRules::OVER => 'asem-neg', BudgetRules::UNPLANNED => 'asem-warn', BudgetRules::NEAR => 'asem-warn', BudgetRules::OK => 'asem-ok', BudgetRules::NONE => '' );
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Voce</th><th>Preventivo</th><th>Consuntivo</th><th>Differenza a favore</th><th>Consuntivo sul preventivo</th><th>Stato</th></tr></thead><tbody>';
		$type = '';
		foreach ( $c['rows'] as $r ) {
			if ( $type !== $r['type'] ) {
				$type = $r['type'];
				echo '<tr><th colspan="6">' . ( BudgetRules::INCOME === $type ? 'Entrate' : 'Uscite' ) . '</th></tr>';
			}
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( Money::format( $r['planned'] ) ) . '</td><td>' . esc_html( Money::format( $r['actual'] ) ) . '</td><td>' . esc_html( Money::format( $r['diff'] ) )
				. '</td><td>' . ( null === $r['percent'] ? '—' : (int) $r['percent'] . '%' ) . '</td><td class="' . esc_attr( $cls[ $r['status'] ] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</td></tr>';
		}
		$t = $c['totals'];
		echo '<tr><th>Totale entrate</th><td>' . esc_html( Money::format( $t['income_planned'] ) ) . '</td><td>' . esc_html( Money::format( $t['income_actual'] ) ) . '</td><td colspan="3"></td></tr>';
		echo '<tr><th>Totale uscite</th><td>' . esc_html( Money::format( $t['expense_planned'] ) ) . '</td><td>' . esc_html( Money::format( $t['expense_actual'] ) ) . '</td><td colspan="3"></td></tr>';
		echo '<tr><th>Risultato</th><td>' . esc_html( Money::format( $t['result_planned'] ) ) . '</td><td>' . esc_html( Money::format( $t['result_actual'] ) ) . '</td><td colspan="3"></td></tr>';
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( BlockDExports::url( 'asem_budget_csv', array( 'id' => $id ) ) ) . '">Esporta il confronto (CSV)</a></p>';
	}

	private static function lines_form( array $b ): void {
		$id    = (int) $b['id'];
		$saved = array();
		foreach ( Budget::lines( $id ) as $l ) {
			$saved[ (int) $l['category_id'] ] = $l;
		}
		echo '<h2>Voci</h2><p class="description">Scrivi l\'importo previsto per ogni categoria; lascia vuoto dove non prevedi nulla.</p>';
		Ui::form_open( 'asem_budget_lines', self::back( $id ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Categoria</th><th>Tipo</th><th>Importo previsto (€)</th><th>Nota</th></tr></thead><tbody>';
		foreach ( Budget::categories() as $cid => $c ) {
			echo '<tr><td>' . esc_html( $c[0] ) . '</td><td>' . ( BudgetRules::INCOME === $c[1] ? 'Entrata' : 'Uscita' ) . '</td><td><input type="text" inputmode="decimal" name="amount[' . (int) $cid . ']" value="'
				. esc_attr( isset( $saved[ $cid ] ) ? Money::plain( (int) $saved[ $cid ]['planned_cents'] ) : '' ) . '" style="width:120px"></td><td><input type="text" name="note[' . (int) $cid . ']" maxlength="255" value="'
				. esc_attr( isset( $saved[ $cid ] ) ? (string) $saved[ $cid ]['notes'] : '' ) . '"></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( 'Salva le voci' );
		Ui::form_close();
	}

	private static function copy_form( array $b ): void {
		$id = (int) $b['id'];
		echo '<h2>Parti dall\'anno prima</h2>';
		Ui::form_open( 'asem_budget_copy', self::back( $id ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Copia le voci <select name="source"><option value="actual">dal consuntivo del ' . ( (int) $b['year'] - 1 ) . '</option><option value="budget">dal preventivo del ' . ( (int) $b['year'] - 1 ) . '</option></select> '
			. 'con una variazione del <input type="number" name="percent" value="0" min="-100" max="1000" style="width:80px"> %. <strong>Sostituisce le voci scritte sopra.</strong> ';
		submit_button( 'Copia', 'secondary', 'submit', false );
		echo '</p>';
		Ui::form_close();
	}

	private static function approval( array $b ): void {
		$id = (int) $b['id'];
		echo '<h2>Approvazione</h2>';
		if ( BudgetRules::APPROVED === $b['status'] ) {
			$a = ! empty( $b['assembly_id'] ) ? Assemblies::get( (int) $b['assembly_id'] ) : null;
			echo '<p>Approvato il <strong>' . esc_html( ( new \DateTimeImmutable( (string) $b['approved_on'] ) )->format( 'd/m/Y' ) ) . '</strong>'
				. ( $a && Edition::installed( 'budget' ) ? ' dall\'assemblea «<a href="' . esc_url( Ui::url( 'asem-assemblies', array( 'view' => (int) $a['id'] ) ) ) . '">' . esc_html( $a['title'] ) . '</a>»' : '' ) . '. ';
			echo Ui::confirm_button( 'asem_budget_reopen', self::back( $id ), array( 'id' => $id ), 'Riapri per modificare', 'Riaprire il preventivo? Torna in preparazione.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$asm = array();
		foreach ( Assemblies::all() as $a ) {
			$asm[ (int) $a['id'] ] = $a['title'] . ' (' . mysql2date( 'd/m/Y', $a['first_call_at'] ) . ')';
		}
		Ui::form_open( 'asem_budget_approve', self::back( $id ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Approvato il <input type="date" name="approved_on" value="' . esc_attr( Db::today() ) . '" required> dall\'assemblea <select name="assembly_id">' . Ui::options( $asm, null, '— nessuna —' ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Segna come approvato', 'secondary', 'submit', false );
		echo '</p>';
		Ui::form_close();
		echo Ui::confirm_button( 'asem_budget_delete', Ui::url( 'asem-budget' ), array( 'id' => $id ), 'Elimina il preventivo', 'Eliminare questo preventivo e le sue voci?' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Riquadro della Bacheca: spese oltre il preventivo dell'anno in corso. */
	public static function alert(): void {
		if ( ! Edition::has( 'budget' ) || ! current_user_can( \AssociazioneSemplice\Plugin::CAP_OPS ) ) {
			return;
		}
		$n = Budget::over_count();
		if ( $n > 0 ) {
			echo Dismiss::html( 'budget-over-' . $n, 'warning', 'Preventivo: ' . (int) $n . ( 1 === $n ? ' voce di spesa ha superato' : ' voci di spesa hanno superato' ) . ' quanto previsto per quest\'anno. <a href="' . esc_url( Ui::url( 'asem-budget' ) ) . '">Apri il preventivo</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
	}
}

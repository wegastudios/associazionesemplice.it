<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\Inventory;
use AssociazioneSemplice\LoanRules;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Registri → Inventario: registro dei beni, prestiti e rientri. Non c'entra con le risorse prenotabili. */
final class InventoryPage {

	private static function d( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	/** Soci e volontari a cui si può prestare un bene. */
	private static function borrowers(): array {
		return array_values( array_filter( Plugin::people()->search(), function ( $p ) {
			return MemberType::is_member( $p['type'] );
		} ) );
	}

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::item_page( $view );
			return;
		}
		$person = Ui::get_int( 'person' );
		if ( $person || 'loans' === Ui::get_str( 'tab' ) ) {
			self::loans_page( $person );
			return;
		}
		$q   = Ui::get_str( 'q' );
		$cat = Ui::get_str( 'category' );
		Ui::header( 'Inventario', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-inventory', array( 'new' => 1 ) ) ) . '">Nuovo bene</a>' );
		echo '<p class="description">Il registro dei beni dell\'associazione (attrezzature, strumenti, materiali) e di chi li ha in prestito. Per le sale e le attrezzature da prenotare si usano le Risorse.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Ui::url( 'asem-inventory' ) ) . '">Beni</a> <a class="button" href="' . esc_url( Ui::url( 'asem-inventory', array( 'tab' => 'loans' ) ) ) . '">Prestiti</a> '
			. '<a class="button" href="' . esc_url( Docs::url( 'items', array(), 'csv' ) ) . '">Esporta i beni (CSV)</a></p>';
		echo wp_kses( '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-inventory"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Nome, codice, ubicazione"> '
			. '<select name="category">' . Ui::options( array_combine( Inventory::categories(), Inventory::categories() ), $cat, 'Tutte le categorie' ) . '</select> <button class="button">Filtra</button></form>', Html::allowed() );
		echo '<table class="widefat striped"><thead><tr><th>Codice</th><th>Bene</th><th>Categoria</th><th>Pezzi</th><th>In prestito</th><th>Stato</th><th>Ubicazione</th></tr></thead><tbody>';
		$rows = Inventory::items( $q, $cat );
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nessun bene.</td></tr>';
		}
		foreach ( $rows as $i ) {
			echo '<tr><td>' . esc_html( (string) $i['code'] ) . '</td><td><a href="' . esc_url( Ui::url( 'asem-inventory', array( 'view' => (int) $i['id'] ) ) ) . '">' . esc_html( $i['name'] ) . '</a></td><td>' . esc_html( $i['category'] )
				. '</td><td>' . (int) $i['quantity'] . '</td><td>' . (int) $i['out'] . '</td><td>' . esc_html( LoanRules::item_states()[ $i['state'] ] ?? $i['state'] ) . '</td><td>' . esc_html( $i['location'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function loans_table( array $loans, bool $with_item = true, bool $with_person = true ): void {
		echo '<table class="widefat striped"><thead><tr>' . ( $with_item ? '<th>Bene</th>' : '' ) . ( $with_person ? '<th>Persona</th>' : '' ) . '<th>Pezzi</th><th>Uscita</th><th>Rientro previsto</th><th>Situazione</th><th>Note</th><th></th></tr></thead><tbody>';
		if ( ! $loans ) {
			echo '<tr><td colspan="8">Nessun prestito.</td></tr>';
		}
		foreach ( $loans as $l ) {
			$state = LoanRules::loan_labels()[ $l['status'] ];
			if ( LoanRules::OVERDUE === $l['status'] ) {
				$state = '<strong class="asem-neg">' . esc_html( $state ) . ' (' . (int) $l['days_late'] . ' giorni)</strong>';
			} elseif ( LoanRules::RETURNED === $l['status'] ) {
				$state = esc_html( $state ) . ' il ' . self::d( $l['returned_on'] );
			} else {
				$state = esc_html( $state );
			}
			echo wp_kses( '<tr>' . ( $with_item ? '<td><a href="' . esc_url( Ui::url( 'asem-inventory', array( 'view' => (int) $l['item_id'] ) ) ) . '">' . esc_html( $l['item_name'] ) . '</a></td>' : '' )
				. ( $with_person ? '<td><a href="' . esc_url( Ui::url( 'asem-inventory', array( 'person' => (int) $l['person_id'] ) ) ) . '">' . esc_html( $l['person_name'] ) . '</a></td>' : '' )
				. '<td>' . (int) $l['quantity'] . '</td><td>' . self::d( $l['lent_on'] ) . '</td><td>' . self::d( $l['due_on'] ) . '</td><td>' . $state . '</td><td>' . esc_html( trim( $l['notes_out'] . ' ' . ( '' !== $l['notes_in'] ? '→ ' . $l['notes_in'] : '' ) ) ) . '</td><td>', Html::allowed() );
			if ( LoanRules::RETURNED !== $l['status'] ) {
				Ui::form_open( 'asem_loan_return', Ui::url( 'asem-inventory', array( 'view' => (int) $l['item_id'] ) ), false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'id', $l['id'] ) . '<input type="date" name="returned_on" value="' . esc_attr( Db::today() ) . '"> <input type="text" name="notes_in" placeholder="Stato al rientro" maxlength="255" style="width:150px"> '
					. '<select name="new_state">' . Ui::options( LoanRules::item_states(), '', 'Stato invariato' ) . '</select> <button class="button button-small">Rientrato</button>', Html::allowed() );
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function loans_page( int $person_id ): void {
		$p = $person_id ? Plugin::people()->get( $person_id ) : null;
		Ui::header( $p ? 'Prestiti di ' . trim( $p['first_name'] . ' ' . $p['last_name'] ) : 'Prestiti', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-inventory' ) ) . '">← Inventario</a>' );
		echo '<p><a class="button" href="' . esc_url( Docs::url( 'loans', array(), 'csv' ) ) . '">Esporta i prestiti (CSV)</a></p>';
		self::loans_table( Inventory::loans( 0, $person_id ), true, ! $p );
		Ui::footer();
	}

	private static function item_page( int $id ): void {
		$i = $id ? Inventory::item( $id ) : null;
		Ui::header( $i ? $i['name'] : 'Nuovo bene', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-inventory' ) ) . '">← Inventario</a>' );
		if ( $id && ! $i ) {
			echo '<p>Bene non trovato.</p>';
			Ui::footer();
			return;
		}
		$v = function ( string $k, string $default = '' ) use ( $i ) {
			return $i ? (string) ( $i[ $k ] ?? '' ) : $default;
		};
		if ( $i ) {
			$out = Inventory::out_quantity( $id );
			$avail = LoanRules::available( (int) $i['quantity'], $out );
			echo '<h2>Prestito</h2><p>Disponibili <strong>' . (int) $avail . '</strong> su ' . (int) $i['quantity'] . '.</p>';
			if ( $avail > 0 && LoanRules::lendable_state( (string) $i['state'] ) ) {
				Ui::form_open( 'asem_loan_save', Ui::url( 'asem-inventory', array( 'view' => $id ) ) );
				echo wp_kses( Ui::hidden( 'item_id', $id ) . '<p>A ' . Ui::person_select( 'person_id', self::borrowers(), null, 'Scegli il socio o volontario' )
					. ' pezzi <input type="number" name="quantity" min="1" max="' . (int) $avail . '" value="1" style="width:70px"> uscita <input type="date" name="lent_on" value="' . esc_attr( Db::today() ) . '" required> '
					. 'rientro previsto <input type="date" name="due_on" value="' . esc_attr( gmdate( 'Y-m-d', strtotime( Db::today() . ' +14 days' ) ) ) . '" required> '
					. '<input type="text" name="notes" placeholder="Note sullo stato all\'uscita" maxlength="255" style="width:220px"> <button class="button button-primary">Registra il prestito</button></p>', Html::allowed() );
				Ui::form_close();
			}
			self::loans_table( Inventory::loans( $id ), false, true );
		}
		echo '<h2>' . ( $i ? 'Scheda del bene' : 'Nuovo bene' ) . '</h2>';
		Ui::form_open( 'asem_item_save', Ui::url( 'asem-inventory', $i ? array( 'view' => $id ) : array( 'new' => 1 ) ), true );
		echo wp_kses( Ui::hidden( 'id', $id ), Html::allowed() );
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Nome *</th><td><input type="text" name="name" value="' . esc_attr( $v( 'name' ) ) . '" class="regular-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Categoria</th><td><input type="text" name="category" value="' . esc_attr( $v( 'category' ) ) . '" class="regular-text" maxlength="80" list="asem-item-cats"><datalist id="asem-item-cats">';
		foreach ( Inventory::categories() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '">';
		}
		echo '</datalist></td></tr>';
		echo '<tr><th>Codice</th><td><input type="text" name="code" value="' . esc_attr( $v( 'code' ) ) . '" maxlength="60"><p class="description">Facoltativo, unico (ad esempio il numero scritto sull\'etichetta).</p></td></tr>';
		echo '<tr><th>Quantità *</th><td><input type="number" name="quantity" min="1" value="' . (int) $v( 'quantity', '1' ) . '" style="width:90px" required></td></tr>';
		echo wp_kses( '<tr><th>Stato</th><td><select name="state">' . Ui::options( LoanRules::item_states(), $v( 'state', 'good' ) ) . '</select></td></tr>', Html::allowed() );
		echo '<tr><th>Ubicazione</th><td><input type="text" name="location" value="' . esc_attr( $v( 'location' ) ) . '" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th>Valore (€)</th><td><input type="text" name="value" value="' . esc_attr( $i && null !== $i['value_cents'] ? Money::plain( (int) $i['value_cents'] ) : '' ) . '" style="width:110px" placeholder="0,00"></td></tr>';
		echo '<tr><th>Acquistato il</th><td><input type="date" name="purchased_on" value="' . esc_attr( $v( 'purchased_on' ) ) . '"></td></tr>';
		echo '<tr><th>Note</th><td><textarea name="notes" rows="3" class="large-text">' . esc_textarea( $v( 'notes' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Foto o allegato</th><td><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.odt,.xls,.xlsx,.ods,.txt">';
		if ( $i && ! empty( $i['file_stored'] ) ) {
			echo ' <a href="' . esc_url( Inventory::file_url( $id ) ) . '" target="_blank">' . esc_html( (string) $i['file_name'] ) . '</a> <span class="description">(se ne scegli un altro, lo sostituisce)</span>';
		}
		echo '</td></tr></tbody></table>';
		submit_button( $i ? 'Salva' : 'Aggiungi il bene' );
		Ui::form_close();
		if ( $i ) {
			echo wp_kses( Ui::confirm_button( 'asem_item_delete', Ui::url( 'asem-inventory' ), array( 'id' => $id ), 'Elimina il bene', 'Eliminare questo bene dall\'inventario? Lo storico dei prestiti resta.' ), Html::allowed() );
		}
		Ui::footer();
	}
}

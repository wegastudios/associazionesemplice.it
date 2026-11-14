<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Money;
use AssociazioneSemplice\Receipts;

defined( 'ABSPATH' ) || exit;

/**
 * Dopo l'annullo di una riga di un incasso con più voci: la ricevuta emessa risulta annullata e qui si propone di emettere la ricevuta
 * sostitutiva per le righe rimaste (numero nuovo, data di oggi, collegata alla ricevuta annullata).
 */
final class ReceiptReplacePage {

	public static function render(): void {
		$key  = Receipts::clean_key( Ui::get_str( 'key' ) );
		$back = esc_url_raw( Ui::get_str( 'back' ) );
		$back = '' !== $back ? $back : Ui::url( 'asem-ledger' );
		Ui::header( 'Ricevuta sostitutiva' );
		$rec = '' !== $key ? Receipts::record( $key ) : null;
		if ( ! $rec || null === $rec['voided_at'] ) {
			echo '<p>Questa ricevuta non è annullata: non serve una sostitutiva.</p><p><a class="button" href="' . esc_url( $back ) . '">Torna indietro</a></p>';
			Ui::footer();
			return;
		}
		$label = $rec['number'] . '/' . $rec['year'];
		echo '<div class="asem-card"><p>' . sprintf( 'La ricevuta <strong>n. %1$s</strong> è stata annullata il <strong>%2$s</strong>', esc_html( $label ), esc_html( Ui::date( substr( (string) $rec['voided_at'], 0, 10 ) ) ) )
			. ( '' !== trim( (string) $rec['void_reason'] ) ? ' (motivo: ' . esc_html( (string) $rec['void_reason'] ) . ')' : '' )
			. ': una ricevuta annullata vale per intero, anche per le voci che non sono state annullate. Il suo numero resta suo e non torna libero; la ricevuta resta consultabile, con la scritta «RICEVUTA ANNULLATA».</p></div>';
		$replacement = Receipts::replacement_of( (int) $rec['id'] );
		if ( $replacement ) {
			echo '<p>Esiste già la ricevuta sostitutiva <strong>n. ' . esc_html( $replacement['number'] . '/' . $replacement['year'] ) . '</strong>.</p>';
			echo '<p><a class="button" target="_blank" href="' . esc_url( Receipts::url( (string) $replacement['receipt_key'] ) ) . '">Ricevuta PDF</a> <a class="button" href="' . esc_url( $back ) . '">Torna indietro</a></p>';
			Ui::footer();
			return;
		}
		$rows = Receipts::rows( $key );
		if ( ! $rows ) {
			echo '<p>Non restano voci incassate: non c\'è nessuna ricevuta sostitutiva da emettere.</p><p><a class="button" href="' . esc_url( $back ) . '">Torna indietro</a></p>';
			Ui::footer();
			return;
		}
		$payer = Receipts::payer( $rows );
		echo '<h2>Vuoi emettere la ricevuta sostitutiva per le voci rimaste?</h2><table class="widefat striped" style="max-width:640px"><tbody>';
		$total = 0;
		foreach ( $rows as $r ) {
			$total += (int) $r['amount_cents'];
			echo '<tr><td>' . esc_html( Receipts::describe( $r, $payer ) ) . '</td><td style="text-align:right">' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td></tr>';
		}
		echo '<tr><th>Totale</th><th style="text-align:right">' . esc_html( Money::format( $total ) ) . '</th></tr></tbody></table>';
		echo '<p class="description">La nuova ricevuta ha un numero nuovo (nella serie dell\'anno di oggi), porta la data di oggi e i dati di oggi di chi ha pagato e dell\'associazione, e riporta la scritta «sostituisce la ricevuta n. ' . esc_html( $label ) . ' annullata il …».</p>';
		$problem = Receipts::replacement_problem( $key );
		if ( '' !== $problem ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $problem ) . '</p></div><p><a class="button" href="' . esc_url( $back ) . '">Torna indietro</a></p>';
			Ui::footer();
			return;
		}
		Ui::form_open( 'asem_receipt_replace', $back, false, 'asem-confirm' );
		echo Ui::hidden( 'key', $key ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><button class="button button-primary" data-confirm="Emettere la ricevuta sostitutiva? Prende un numero nuovo e non si può cancellare. Se serve, annulli l\'incasso e la ricevuta risulta annullata.">Emetti la ricevuta sostitutiva</button> <a class="button" href="' . esc_url( $back ) . '">Non ora</a></p>';
		Ui::form_close();
		Ui::footer();
	}
}

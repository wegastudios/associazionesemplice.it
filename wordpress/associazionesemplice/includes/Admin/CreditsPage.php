<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\CreditRules;
use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Crediti: pacchetti in vendita, vendita in segreteria, rettifiche a mano e ultimi movimenti. Specifica: docs/CREDITI.md. */
final class CreditsPage {

	/** Riga del modulo di un'attività: i crediti previsti per partecipare. Da chiamare solo se la funzione c'è. */
	public static function activity_row( ?int $activity_id ): string {
		$a = $activity_id ? Plugin::activities()->get( $activity_id ) : null;
		return '<tr><th>' . esc_html( \AssociazioneSemplice\CoinName::plural() ) . '</th><td><input type="number" name="credit_price" min="0" max="' . (int) CreditRules::MAX_CREDITS . '" style="width:80px" value="' . ( $a && ! empty( $a['credit_price'] ) ? (int) $a['credit_price'] : '' ) . '"> ' . esc_html( \AssociazioneSemplice\CoinName::plural() )
			. '<p class="description">' . esc_html( 'Vuoto o 0 = non si paga in ' . \AssociazioneSemplice\CoinName::plural() . '. Con un valore l\'attività compare nell\'incasso dal vivo dello staff e l\'importo si compila da solo.' ) . '</p></td></tr>';
	}

	private static function members(): array {
		return array_values(
			array_filter(
				Plugin::people()->search_light(),
				function ( $p ) {
					return \AssociazioneSemplice\MemberType::is_member( $p['type'] );
				}
			)
		);
	}

	/** Il nome della moneta: singolare e plurale, vuoti = AssoCredito. Solo l'amministratore lo cambia. */
	private static function name_card(): void {
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) {
			return;
		}
		echo '<div class="asem-card"><h2>Nome della moneta</h2>';
		Ui::form_open( 'asem_credit_name', Ui::url( 'asem-credits' ) );
		echo '<p><label>Singolare <input type="text" name="coin_singular" maxlength="' . (int) \AssociazioneSemplice\CoinName::MAX . '" placeholder="' . esc_attr( \AssociazioneSemplice\CoinName::SINGULAR ) . '" value="' . esc_attr( (string) \AssociazioneSemplice\Settings::get( 'coin_singular' ) ) . '"></label> '
			. '<label>Plurale <input type="text" name="coin_plural" maxlength="' . (int) \AssociazioneSemplice\CoinName::MAX . '" placeholder="' . esc_attr( \AssociazioneSemplice\CoinName::PLURAL ) . '" value="' . esc_attr( (string) \AssociazioneSemplice\Settings::get( 'coin_plural' ) ) . '"></label> <button class="button">Salva</button></p>'
			. '<p class="description">Come i soci vedono la moneta in area soci, email, ricevute ed esportazioni (per esempio «Stella» e «Stelle»). Vuoto = ' . esc_html( \AssociazioneSemplice\CoinName::SINGULAR . ' / ' . \AssociazioneSemplice\CoinName::PLURAL ) . '. I saldi non cambiano.</p>';
		Ui::form_close();
		echo '</div>';
	}

	public static function render(): void {
		Ui::header( \AssociazioneSemplice\CoinName::plural() );
		$back  = Ui::url( 'asem-credits' );
		$packs = Credits::packs();
		echo '<p class="description">' . esc_html( 'Il socio compra pacchetti di ' . \AssociazioneSemplice\CoinName::plural() . ' e li spende nelle risorse e nelle attività che li accettano, anche dal vivo con un QR. In circolazione: ' ) . '<strong>' . esc_html( \AssociazioneSemplice\CoinName::n( (int) Credits::outstanding() ) ) . '</strong>. '
			. 'Quando vendi un pacchetto l\'incasso entra in prima nota; quando il socio li spende la prima nota non cambia.</p>';
		self::name_card();

		echo '<div class="asem-card"><h2>Pacchetti in vendita</h2>';
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) { // il listino lo cambia solo l'amministratore
			echo '<p class="description">Il listino dei pacchetti lo modifica un amministratore.</p></div>';
			$packs_form = false;
		} else {
			$packs_form = true;
		}
		if ( $packs_form ) {
		Ui::form_open( 'asem_credit_packs', $back );
		echo '<p><textarea name="packs" rows="5" cols="30" placeholder="10 = 10,00&#10;50 = 45,00">' . esc_textarea( CreditRules::packs_text( $packs ) ) . '</textarea></p>'
			. '<p class="description">Una riga per pacchetto: quantità = prezzo in euro (al massimo 12). Il socio li compra online dalla sua area; in segreteria si vendono qui sotto.</p>'
			. '<p><button class="button button-primary">Salva i pacchetti</button></p>';
		Ui::form_close();
		echo '</div>';
		}

		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		if ( $packs && $accounts ) {
			$map = array();
			foreach ( $packs as $p ) {
				$map[ $p['credits'] ] = $p['credits'] . ' ' . ( 1 === (int) $p['credits'] ? \AssociazioneSemplice\CoinName::singular() : \AssociazioneSemplice\CoinName::plural() ) . ' — ' . Money::format( (int) $p['price_cents'] );
			}
			echo '<div class="asem-card"><h2>Vendi un pacchetto in segreteria</h2>';
			Ui::form_open( 'asem_credit_sell', $back );
			echo '<p>' . Ui::person_select( 'person_id', self::members(), null, '— socio —', 'asem-credit-sell' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ' <select name="credits">' . Ui::options( $map ) . '</select> <select name="method">' . Ui::options( Labels::methods(), 'cash' ) . '</select> <select name="account_id">' . Ui::options( $accounts ) . '</select> '
				. '<button class="button button-primary">Vendi</button></p><p class="description">L\'incasso entra in prima nota come «Altra entrata» e il socio li riceve subito.</p>';
			Ui::form_close();
			echo '</div>';
		}

		echo '<div class="asem-card"><h2>Rettifica a mano</h2>';
		Ui::form_open( 'asem_credit_adjust', $back );
		echo '<p>' . Ui::person_select( 'person_id', self::members(), null, '— socio —', 'asem-credit-adj' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' <input type="number" name="delta" style="width:90px" placeholder="± quantità"> <input type="text" name="note" class="regular-text" maxlength="190" placeholder="Motivo (obbligatorio)"> <button class="button">Applica</button></p>'
			. '<p class="description">Per regali, errori o rimborsi: si aggiunge o si toglie una quantità con il motivo, che resta nei movimenti. Il saldo non scende sotto zero.</p>';
		Ui::form_close();
		echo '</div>';

		echo '<div class="asem-card"><h2>Ultimi movimenti</h2>';
		$rows = Credits::recent( 40 );
		if ( ! $rows ) {
			echo '<p class="description">Nessun movimento.</p>';
		} else {
			$kinds = array( 'buy' => 'Acquisto', 'spend' => 'Spesa', 'refund' => 'Rimborso', 'adjust' => 'Rettifica' );
			echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Socio</th><th>Tipo</th><th>' . esc_html( \AssociazioneSemplice\CoinName::plural() ) . '</th><th>Note</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( Ui::date( substr( (string) $r['created_at'], 0, 10 ) ) ) . '</td><td>' . esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ) . '</td><td>' . esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] )
					. '</td><td><strong>' . ( (int) $r['delta'] > 0 ? '+' : '' ) . (int) $r['delta'] . '</strong></td><td>' . esc_html( (string) $r['label'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		Ui::footer();
	}
}

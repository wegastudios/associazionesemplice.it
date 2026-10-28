<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Donations;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Donazioni con PayPal. Il conto PayPal e la raccolta generale si impostano qui; ogni raccolta ha il suo shortcode, già pronto da incollare
 * dove si vuole nel sito. Il donatore paga sul sito di PayPal: il sito non tratta nessun dato di pagamento. Solo amministratori.
 */
final class DonatePage {

	/** Campo con lo shortcode e il pulsante per copiarlo. */
	private static function copy_field( string $code ): string {
		return '<input type="text" readonly class="regular-text code" value="' . esc_attr( $code ) . '" data-asem-select> '
			. '<button type="button" class="button" data-asem-copy="' . esc_attr( $code ) . '">Copia</button>';
	}

	public static function render(): void {
		$s    = Settings::all();
		$edit = Donations::campaign( Ui::get_int( 'campaign' ) );
		Ui::header( 'Impostazioni: donazioni con PayPal' );
		if ( ! Donations::enabled() ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( Donations::disclaimer() ) . '</strong> Inserisci il conto PayPal qui sotto e accendi la raccolta.</p></div>';
		} else {
			echo '<p class="description">' . esc_html( Donations::disclaimer() ) . '</p>';
		}
		echo '<p class="description">Ogni raccolta mostra gli importi proposti e un pulsante «Dona con PayPal»: il donatore paga sul sito di PayPal, il sito non tratta nessun dato di pagamento. '
			. 'Le donazioni arrivano direttamente sul tuo conto PayPal e si registrano in prima nota come «Erogazione liberale» (<a href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Nuovo incasso</a>).</p>';

		Ui::form_open( 'asem_save_donate', Ui::url( 'asem-donate' ) );
		echo '<h2>Conto e raccolta generale</h2><table class="form-table"><tbody>';
		echo '<tr><th>Raccolta donazioni</th><td><label><input type="checkbox" name="donate_enabled" value="1"' . checked( ! empty( $s['donate_enabled'] ), true, false ) . '> Accendi le donazioni</label>'
			. '<p class="description">Spento di default. Spento, nessun modulo di donazione compare nel sito.</p></td></tr>';
		echo '<tr><th>Conto PayPal</th><td><input type="text" name="donate_paypal" value="' . esc_attr( (string) $s['donate_paypal'] ) . '" class="regular-text" autocomplete="off">'
			. '<p class="description">L\'email del conto PayPal dell\'associazione, oppure il suo ID commerciante (13 caratteri, lo trovi nel profilo PayPal). Serve un conto Business abilitato a ricevere donazioni.</p></td></tr>';
		echo '<tr><th>Importi proposti</th><td><input type="text" name="donate_amounts" value="' . esc_attr( (string) $s['donate_amounts'] ) . '" class="regular-text">'
			. '<p class="description">In euro, separati da punto e virgola (al massimo 8), ad esempio <code>5;10;20;50</code>. Valgono per la raccolta generale e per le raccolte senza importi propri. Chi vuole può sempre scegliere un altro importo su PayPal.</p></td></tr>';
		echo '<tr><th>Causale generale</th><td><input type="text" name="donate_purpose" value="' . esc_attr( (string) $s['donate_purpose'] ) . '" class="regular-text" maxlength="120">'
			. '<p class="description">Il titolo del modulo e il motivo che compare su PayPal. Vuoto = «Donazione a» e il nome dell\'ente.</p></td></tr>';
		echo wp_kses( '<tr><th>Shortcode della raccolta generale</th><td>' . self::copy_field( Donations::shortcode() ) . '</td></tr>', Html::allowed() );
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();

		// ---------- Le raccolte, ciascuna con il suo shortcode ----------
		$list = Donations::campaigns();
		echo '<h2>Le tue raccolte</h2><p class="description">Ogni raccolta ha un nome, un titolo e dei testi suoi, e uno <strong>shortcode</strong> pronto: incollalo in una pagina, in un articolo o in un widget e compare il modulo con il pulsante PayPal già configurato.</p>';
		if ( $list ) {
			echo '<table class="widefat striped"><thead><tr><th>Raccolta</th><th>Shortcode</th><th>Stato</th><th></th></tr></thead><tbody>';
			foreach ( $list as $c ) {
				$state = ! $c['active'] ? 'Spenta' : ( Donations::enabled() ? '<span class="asem-ok">Attiva</span>' : '<span class="asem-warn">In attesa: PayPal non è configurato</span>' );
				echo wp_kses( '<tr><td><strong>' . esc_html( $c['name'] ) . '</strong>' . ( '' !== $c['purpose'] ? '<br><span class="description">' . esc_html( $c['purpose'] ) . '</span>' : '' ) . '</td>'
					. '<td>' . self::copy_field( Donations::shortcode( $c['id'] ) ) . '</td><td>' . $state . '</td><td>'
					. '<a class="button button-small" href="' . esc_url( Ui::url( 'asem-donate', array( 'campaign' => $c['id'] ) ) ) . '#asem-campaign">Modifica</a> ', Html::allowed() );
				Ui::form_open( 'asem_delete_campaign', Ui::url( 'asem-donate' ), false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'id', $c['id'] ) . '<button class="button button-small" data-confirm="Eliminare la raccolta «' . esc_attr( $c['name'] ) . '»? Il suo shortcode smette di mostrare il modulo.">Elimina</button>', Html::allowed() );
				Ui::form_close();
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nessuna raccolta ancora: creane una qui sotto.</p>';
		}

		$e = $edit ?: array( 'id' => 0, 'name' => '', 'purpose' => '', 'amounts' => '', 'note' => '', 'active' => 1 );
		echo '<h2 id="asem-campaign">' . ( $edit ? 'Modifica la raccolta' : 'Nuova raccolta' ) . '</h2>';
		Ui::form_open( 'asem_save_campaign', Ui::url( 'asem-donate' ) );
		echo wp_kses( Ui::hidden( 'id', $e['id'] ) . '<table class="form-table"><tbody>', Html::allowed() );
		echo '<tr><th>Nome *</th><td><input type="text" name="name" value="' . esc_attr( $e['name'] ) . '" class="regular-text" maxlength="80" required><p class="description">Serve a riconoscerla nell\'elenco, ad esempio «Restauro del tetto».</p></td></tr>';
		echo '<tr><th>Titolo e causale</th><td><input type="text" name="purpose" value="' . esc_attr( $e['purpose'] ) . '" class="regular-text" maxlength="120"><p class="description">Il titolo del modulo e il motivo che compare su PayPal. Vuoto = il nome della raccolta.</p></td></tr>';
		echo '<tr><th>Testo</th><td><textarea name="note" rows="3" class="large-text" maxlength="400">' . esc_textarea( $e['note'] ) . '</textarea><p class="description">Facoltativo: due righe per spiegare a cosa serve la raccolta.</p></td></tr>';
		echo '<tr><th>Importi proposti</th><td><input type="text" name="amounts" value="' . esc_attr( $e['amounts'] ) . '" class="regular-text"><p class="description">Facoltativi, come sopra. Vuoto = gli importi generali.</p></td></tr>';
		echo '<tr><th>Stato</th><td><label><input type="checkbox" name="active" value="1"' . checked( ! empty( $e['active'] ), true, false ) . '> Raccolta attiva</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $edit ? 'Salva la raccolta' : 'Crea la raccolta e il suo shortcode' );
		Ui::form_close();
		if ( $edit ) {
			echo '<p><a href="' . esc_url( Ui::url( 'asem-donate' ) ) . '">Annulla la modifica</a></p>';
		}

		if ( Donations::enabled() ) {
			echo wp_kses( '<h2>Anteprima della raccolta generale</h2>' . \AssociazioneSemplice\Frontend\Views::donate(), Html::allowed() );
		}
		Ui::footer();
	}
}

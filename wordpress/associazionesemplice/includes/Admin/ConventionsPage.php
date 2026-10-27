<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ConventionRules;
use AssociazioneSemplice\Conventions;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberType;

defined( 'ABSPATH' ) || exit;

/** Convenzioni: elenco, nuova convenzione, avviso ai soci, categorie. */
final class ConventionsPage {

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::form( $view );
			return;
		}
		$cat    = Ui::get_str( 'category' );
		$state  = Ui::get_str( 'state' );
		$labels = ConventionRules::status_labels();
		Ui::header( 'Convenzioni', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-conventions', array( 'new' => 1 ) ) ) . '">Nuova convenzione</a>' );
		echo '<p class="description">Sconti e vantaggi di esercenti ed enti partner per i soci. I soci in regola con la tessera vedono quelle valide oggi nell\'area riservata (sezione «Convenzioni», o lo shortcode <code>[convenzioni_soci]</code>). Per mostrare sul sito quelle segnate «pubbliche» usa lo shortcode <code>[convenzioni]</code>. Una convenzione scaduta o archiviata sparisce dall\'area soci ma resta qui.</p>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-conventions"><select name="category">' . Ui::options( array_combine( Conventions::categories(), Conventions::categories() ), $cat, 'Tutte le categorie' ) . '</select> '
			. '<select name="state">' . Ui::options( $labels, $state, 'Tutti gli stati' ) . '</select> <button class="button">Filtra</button> '
			. BlockFActions::csv_link() . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped"><thead><tr><th>Convenzione</th><th>Categoria</th><th>Vantaggio</th><th>Validità</th><th>Stato</th><th>Chi</th><th>Sito</th></tr></thead><tbody>';
		$n = 0;
		foreach ( Conventions::all( $cat ) as $c ) {
			if ( '' !== $state && $c['status'] !== $state ) {
				continue;
			}
			$n++;
			$period = $c['valid_from'] || $c['valid_to']
				? ( $c['valid_from'] ? 'dal ' . Ui::date( $c['valid_from'] ) . ' ' : '' ) . ( $c['valid_to'] ? 'al ' . Ui::date( $c['valid_to'] ) : '' )
				: 'senza scadenza';
			$who    = ConventionRules::AUDIENCE_TYPES === $c['audience'] ? implode( ', ', array_map( array( MemberType::class, 'label' ), $c['types_list'] ) ) : 'Tutti i soci in regola';
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-conventions', array( 'view' => (int) $c['id'] ) ) ) . '">' . esc_html( $c['name'] ) . '</a>' . ( $c['is_public'] ? ' <span class="description">(pubblica)</span>' : '' ) . '</td><td>'
				. esc_html( $c['category'] ) . '</td><td>' . esc_html( $c['benefit'] ) . '</td><td>' . esc_html( trim( $period ) ) . '</td><td>' . esc_html( $labels[ $c['status'] ] ?? '' )
				. '</td><td>' . esc_html( $who ) . '</td><td>' . ( '' !== $c['website'] ? '<a href="' . esc_url( $c['website'] ) . '" target="_blank" rel="noopener">sito</a>' : '—' ) . '</td></tr>';
		}
		if ( ! $n ) {
			echo '<tr><td colspan="7">Nessuna convenzione.</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>Categorie</h2><p class="description">L\'elenco proposto quando si scrive una convenzione: una categoria per riga. Quelle già in uso restano comunque disponibili.</p>';
		Ui::form_open( 'asem_convention_categories', Ui::url( 'asem-conventions' ) );
		echo '<textarea name="categories" rows="6" class="large-text" maxlength="2000">' . esc_textarea( implode( "\n", Conventions::categories() ) ) . '</textarea>';
		submit_button( 'Salva le categorie', 'secondary' );
		Ui::form_close();
		Ui::footer();
	}

	private static function form( int $id ): void {
		$c = $id ? Conventions::get( $id ) : null;
		Ui::header( $c ? $c['name'] : 'Nuova convenzione', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-conventions' ) ) . '">← Tutte le convenzioni</a>' );
		if ( $id && ! $c ) {
			echo '<p>Convenzione non trovata.</p>';
			Ui::footer();
			return;
		}
		wp_enqueue_media();
		$v      = function ( string $k, string $default = '' ) use ( $c ) {
			return $c ? (string) ( $c[ $k ] ?? '' ) : $default;
		};
		$labels = ConventionRules::status_labels();
		if ( $c ) {
			echo '<p><strong>Stato:</strong> ' . esc_html( $labels[ $c['status'] ] ?? '' ) . ( $c['announced_at'] ? ' · soci avvisati il ' . esc_html( mysql2date( 'd/m/Y', (string) $c['announced_at'] ) ) : '' ) . '</p>';
		}
		Ui::form_open( 'asem_convention_save', Ui::url( 'asem-conventions', $c ? array( 'view' => $id ) : array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Esercente o ente partner *</th><td><input type="text" name="name" value="' . esc_attr( $v( 'name' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Categoria</th><td><input type="text" name="category" value="' . esc_attr( $v( 'category' ) ) . '" class="regular-text" maxlength="80" list="asem-conv-cats"><datalist id="asem-conv-cats">';
		foreach ( Conventions::categories() as $cat ) {
			echo '<option value="' . esc_attr( $cat ) . '">';
		}
		echo '</datalist></td></tr>';
		echo '<tr><th>Vantaggio *</th><td><input type="text" name="benefit" value="' . esc_attr( $v( 'benefit' ) ) . '" class="large-text" maxlength="500" required><p class="description">Per esempio «Sconto del 10% su tutto», «Prezzo agevolato 8 €», «Un caffè in omaggio».</p></td></tr>';
		echo '<tr><th>Condizioni d\'uso</th><td><textarea name="terms" rows="4" class="large-text" maxlength="3000">' . esc_textarea( $v( 'terms' ) ) . '</textarea><p class="description">Solo per i soci: non compaiono nell\'elenco pubblico.</p></td></tr>';
		echo '<tr><th>Valida dal / al</th><td><input type="date" name="valid_from" value="' . esc_attr( $v( 'valid_from' ) ) . '"> — <input type="date" name="valid_to" value="' . esc_attr( $v( 'valid_to' ) ) . '"><p class="description">Lascia vuoto per nessun limite. L\'ultimo giorno è compreso. Dopo la fine la convenzione sparisce dall\'area soci.</p></td></tr>';
		echo '<tr><th>Indirizzo</th><td><input type="text" name="address" value="' . esc_attr( $v( 'address' ) ) . '" class="large-text" maxlength="190"></td></tr>';
		echo '<tr><th>Telefono</th><td><input type="text" name="phone" value="' . esc_attr( $v( 'phone' ) ) . '" class="regular-text" maxlength="40"></td></tr>';
		echo '<tr><th>Email</th><td><input type="email" name="email" value="' . esc_attr( $v( 'email' ) ) . '" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th>Sito</th><td><input type="text" name="website" value="' . esc_attr( $v( 'website' ) ) . '" class="regular-text" maxlength="255" placeholder="https://"></td></tr>';
		$logo_id = (int) $v( 'logo_id', '0' );
		$logo    = $c ? Conventions::logo_url( $c ) : '';
		echo '<tr><th>Logo</th><td><div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap"><div id="asem-conv-logo-preview" style="min-width:120px;min-height:56px;padding:8px;border:1px dashed #c3c4c7;background:#fff">'
			. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="max-height:56px;max-width:220px;display:block">' : '<span class="description">Nessun logo</span>' ) . '</div>'
			. '<input type="hidden" name="logo_id" id="asem-conv-logo-id" value="' . (int) $logo_id . '">'
			. '<button type="button" class="button" id="asem-conv-logo-pick">Scegli dalla libreria</button> '
			. '<button type="button" class="button" id="asem-conv-logo-clear"' . ( $logo_id ? '' : ' style="display:none"' ) . '>Togli il logo</button></div></td></tr>';
		$aud = $v( 'audience', ConventionRules::AUDIENCE_ALL );
		echo '<tr><th>A chi spetta</th><td><label><input type="radio" name="audience" value="all"' . checked( ConventionRules::AUDIENCE_ALL, $aud, false ) . '> Tutti i soci in regola con la tessera</label><br>'
			. '<label><input type="radio" name="audience" value="types"' . checked( ConventionRules::AUDIENCE_TYPES, $aud, false ) . '> Solo questi tipi di socio:</label> ';
		$chosen = $c ? (array) $c['types_list'] : array();
		foreach ( MemberType::member_types() as $t ) {
			echo '<label style="margin-right:12px"><input type="checkbox" name="types[]" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $chosen, true ), true, false ) . '> ' . esc_html( MemberType::label( $t ) ) . '</label>';
		}
		echo '<p class="description">Gli ospiti e i genitori o tutori che non sono soci non vedono mai le convenzioni.</p></td></tr>';
		echo '<tr><th>Visibile anche al pubblico</th><td><label><input type="checkbox" name="is_public" value="1"' . checked( ! empty( $c['is_public'] ), true, false ) . '> Mostra nome, logo, categoria e vantaggio sul sito (shortcode <code>[convenzioni]</code>)</label><p class="description">Il codice e le condizioni restano riservati ai soci.</p></td></tr>';
		echo '<tr><th>Codice convenzione</th><td><input type="text" name="code" value="' . esc_attr( $v( 'code' ) ) . '" class="regular-text" maxlength="60"><p class="description">Facoltativo: il socio lo mostra all\'esercente.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $c ? 'Salva' : 'Aggiungi la convenzione' );
		Ui::form_close();

		if ( $c ) {
			echo '<hr><p>';
			if ( ConventionRules::STATUS_ARCHIVED === $c['status'] ) {
				echo Ui::confirm_button( 'asem_convention_archive', Ui::url( 'asem-conventions', array( 'view' => $id ) ), array( 'id' => $id, 'archive' => 0 ), 'Riporta in elenco', 'Riportare in elenco questa convenzione?' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo Ui::confirm_button( 'asem_convention_archive', Ui::url( 'asem-conventions', array( 'view' => $id ) ), array( 'id' => $id, 'archive' => 1 ), 'Archivia', 'Archiviare questa convenzione? Sparisce dall\'area soci ma resta qui.' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo Ui::confirm_button( 'asem_convention_delete', Ui::url( 'asem-conventions' ), array( 'id' => $id ), 'Elimina', 'Eliminare questa convenzione?' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</p>';
			echo '<h2>Avviso ai soci</h2>';
			if ( ConventionRules::STATUS_ACTIVE !== $c['status'] ) {
				echo '<p class="description">L\'avviso si può mandare quando la convenzione è in corso.</p>';
			} elseif ( $c['announced_at'] ) {
				echo '<p class="description">I soci sono già stati avvisati il ' . esc_html( mysql2date( 'd/m/Y', (string) $c['announced_at'] ) ) . ': l\'email parte una sola volta per convenzione.</p>';
			} else {
				$n = Conventions::announce( $id, false );
				echo '<p class="description">Manda un\'email ai soci a cui spetta (tessera valida e tipo previsto): ora ' . (int) $n . ' con un indirizzo email. Parte una sola volta per convenzione.</p>';
				echo Ui::confirm_button( 'asem_convention_announce', Ui::url( 'asem-conventions', array( 'view' => $id ) ), array( 'id' => $id ), 'Avvisa i soci della nuova convenzione', 'Mandare ora l\'email ai soci a cui spetta? Non si può rimandare.' ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		Ui::inline_js( '(function(){var pick=document.getElementById("asem-conv-logo-pick"),clr=document.getElementById("asem-conv-logo-clear"),id=document.getElementById("asem-conv-logo-id"),pv=document.getElementById("asem-conv-logo-preview"),f;'
			. 'if(!pick){return;}'
			. 'pick.addEventListener("click",function(e){e.preventDefault();if(!window.wp||!wp.media){window.alert("La libreria media non è disponibile: ricarica la pagina.");return;}if(!f){f=wp.media({title:"Logo della convenzione",button:{text:"Usa questa immagine"},library:{type:"image"},multiple:false});'
			. 'f.on("select",function(){var a=f.state().get("selection").first().toJSON();id.value=a.id;var u=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;pv.innerHTML="<img src=\""+u+"\" alt=\"\" style=\"max-height:56px;max-width:220px;display:block\">";clr.style.display="";});}f.open();});'
			. 'clr.addEventListener("click",function(e){e.preventDefault();id.value="0";pv.innerHTML="<span class=\"description\">Nessun logo</span>";clr.style.display="none";});})();' );
		Ui::footer();
	}

	/** Riquadro della Bacheca: convenzioni che scadono entro 30 giorni. */
	public static function dashboard_card(): void {
		$list = Conventions::expiring();
		if ( ! $list ) {
			return;
		}
		echo '<div class="asem-card" id="asem-conventions-expiring"><h2>Convenzioni in scadenza (' . count( $list ) . ')</h2><p class="description">Scadono nei prossimi ' . (int) ConventionRules::EXPIRING_DAYS . ' giorni: poi spariscono dall\'area soci. Rinnova la data dalla scheda.</p><ul>';
		foreach ( array_slice( $list, 0, 10 ) as $c ) {
			echo '<li><a href="' . esc_url( Ui::url( 'asem-conventions', array( 'view' => (int) $c['id'] ) ) ) . '">' . esc_html( $c['name'] ) . '</a> <span class="description">fino al ' . esc_html( ( new \DateTimeImmutable( (string) $c['valid_to'] ) )->format( 'd/m/Y' ) ) . '</span></li>';
		}
		echo '</ul><p><a href="' . esc_url( Ui::url( 'asem-conventions' ) ) . '">Tutte le convenzioni →</a></p></div>';
	}
}

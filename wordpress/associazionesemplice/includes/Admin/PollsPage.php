<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PollRules;
use AssociazioneSemplice\Polls;

defined( 'ABSPATH' ) || exit;

/** Registri → Votazioni: elenco, nuova votazione o sondaggio, risultati. */
final class PollsPage {

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::form( $view );
			return;
		}
		Ui::header( 'Votazioni e sondaggi', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-polls', array( 'new' => 1 ) ) ) . '">Nuova votazione</a>' );
		echo '<p class="description">La segreteria crea una votazione o un sondaggio per tutti i soci in regola o per gli iscritti di un\'attività. I soci votano dall\'area riservata, una volta sola. I genitori e i tutori che non sono soci non votano.</p>';
		$labels = PollRules::status_labels();
		echo '<table class="widefat striped"><thead><tr><th>Titolo</th><th>Tipo</th><th>Apertura</th><th>Chiusura</th><th>Stato</th><th>Hanno votato</th></tr></thead><tbody>';
		$rows = Polls::all();
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessuna votazione.</td></tr>';
		}
		foreach ( $rows as $p ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-polls', array( 'view' => (int) $p['id'] ) ) ) . '">' . esc_html( $p['title'] ) . '</a>' . ( $p['secret'] ? ' <span class="description">(segreta)</span>' : '' ) . '</td><td>'
				. esc_html( PollRules::kinds()[ $p['kind'] ] ?? '' ) . '</td><td>' . esc_html( mysql2date( 'd/m/Y H:i', $p['opens_at'] ) ) . '</td><td>' . esc_html( mysql2date( 'd/m/Y H:i', $p['closes_at'] ) )
				. '</td><td>' . esc_html( $labels[ $p['status'] ] ) . '</td><td>' . (int) Polls::vote_count( (int) $p['id'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function form( int $id ): void {
		$p = $id ? Polls::get( $id ) : null;
		Ui::header( $p ? $p['title'] : 'Nuova votazione', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-polls' ) ) . '">← Tutte le votazioni</a>' );
		if ( $id && ! $p ) {
			echo '<p>Votazione non trovata.</p>';
			Ui::footer();
			return;
		}
		if ( $p ) {
			self::results( $p );
		}
		$locked = $p && Polls::vote_count( $id ) > 0;
		$v      = function ( string $k, string $default = '' ) use ( $p ) {
			return $p ? (string) ( $p[ $k ] ?? '' ) : $default;
		};
		$opts = '';
		if ( $p ) {
			$opts = implode( "\n", array_column( Polls::options( $id ), 'label' ) );
		}
		$acts = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$acts[ (int) $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		$now = Db::now();
		echo '<h2>' . ( $p ? 'Modifica' : 'Crea' ) . '</h2>';
		if ( $locked ) {
			echo '<div class="notice notice-info inline"><p>Qualcuno ha già votato: puoi cambiare titolo, testo, chiusura e avvisi. Risposte, tipo di scelta, segretezza e chi può votare non si cambiano più.</p></div>';
		}
		Ui::form_open( 'asem_poll_save', Ui::url( 'asem-polls', $p ? array( 'view' => $id ) : array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		$dis = $locked ? ' disabled' : '';
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Tipo</th><td><select name="kind">' . Ui::options( PollRules::kinds(), $v( 'kind', 'vote' ) ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Titolo *</th><td><input type="text" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Domanda o spiegazione</th><td><textarea name="question" rows="4" class="large-text">' . esc_textarea( $v( 'question' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Risposte *</th><td><textarea name="options" rows="5" class="large-text"' . $dis . '>' . esc_textarea( $opts ) . '</textarea><p class="description">Una risposta per riga (da 2 a ' . (int) PollRules::MAX_OPTIONS . '). Per sì o no: «Favorevole», «Contrario», «Astenuto».</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper, numeri interi o attributi di WordPress
		echo '<tr><th>Scelta</th><td><label><input type="checkbox" name="multiple" value="1"' . checked( ! empty( $p['multiple'] ), true, false ) . $dis . '> Si possono scegliere più risposte</label> '
			. '— al massimo <input type="number" min="0" max="' . (int) PollRules::MAX_OPTIONS . '" name="max_choices" value="' . (int) $v( 'max_choices', '0' ) . '" style="width:70px"' . $dis . '> (0 = senza limite)</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Voto segreto</th><td><label><input type="checkbox" name="secret" value="1"' . checked( ! empty( $p['secret'] ), true, false ) . $dis . '> Si registra chi ha votato ma non cosa</label></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Chi può votare</th><td><select name="audience"' . $dis . '>' . Ui::options( PollRules::audiences(), $v( 'audience', PollRules::AUDIENCE_MEMBERS ) ) . '</select> '
			. '<select name="activity_id"' . $dis . '>' . Ui::options( $acts, $v( 'activity_id' ), 'Attività (se scelti gli iscritti)' ) . '</select><p class="description">Votano solo i soci con la tessera valida: non i sospesi, non gli usciti, non i genitori o tutori che non sono soci.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>Si apre *</th><td><input type="datetime-local" name="opens_at" value="' . esc_attr( PollRules::to_local( $v( 'opens_at', $now ) ) ) . '" required></td></tr>';
		echo '<tr><th>Si chiude *</th><td><input type="datetime-local" name="closes_at" value="' . esc_attr( PollRules::to_local( $v( 'closes_at', gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +7 days' ) ) ) ) ) . '" required></td></tr>';
		echo '<tr><th>Risultati ai soci</th><td><label><input type="checkbox" name="show_results" value="1"' . checked( ! empty( $p['show_results'] ), true, false ) . '> Dopo la chiusura i soci vedono i risultati</label></td></tr>';
		echo '<tr><th>Avvisi</th><td><label><input type="checkbox" name="notify" value="1"' . checked( ! empty( $p['notify'] ), true, false ) . '> Avvisa i soci per email (all\'apertura e prima della chiusura)</label><p class="description">Serve che i promemoria siano attivi in Impostazioni → Privacy e regolamento. Oppure usa il pulsante «Avvisa i soci ora» qui sopra.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $p ? 'Salva' : 'Crea' );
		Ui::form_close();
		if ( $p ) {
			echo Ui::confirm_button( 'asem_poll_delete', Ui::url( 'asem-polls' ), array( 'id' => $id ), 'Elimina', 'Eliminare questa votazione? Non comparirà più negli elenchi.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		Ui::footer();
	}

	private static function results( array $p ): void {
		$id  = (int) $p['id'];
		$res = Polls::results( $id, true );
		echo '<h2>Risultati <span class="description">(' . esc_html( PollRules::status_labels()[ $p['status'] ] ) . ')</span></h2>';
		echo '<p>Hanno votato <strong>' . (int) $res['voters'] . '</strong> su <strong>' . (int) $res['eligible'] . '</strong> soci con diritto al voto.</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Risposta</th><th>Voti</th><th>%</th></tr></thead><tbody>';
		foreach ( $res['options'] as $o ) {
			echo '<tr><td>' . esc_html( $o['label'] ) . ( in_array( $o['id'], $res['winners'], true ) ? ' <strong>— più votata</strong>' : '' )
				. ( $o['names'] ? '<br><span class="description">' . esc_html( implode( ', ', $o['names'] ) ) . '</span>' : '' )
				. '</td><td>' . (int) $o['count'] . '</td><td>' . (int) $o['percent'] . '%</td></tr>';
		}
		echo '</tbody></table>';
		if ( $p['secret'] ) {
			echo '<p class="description">Voto segreto: chi ha scelto cosa non è registrato.</p>';
		}
		$names = Polls::voter_names( $id );
		if ( $names ) {
			echo '<details><summary>Chi ha votato (' . count( $names ) . ')</summary><p>' . esc_html( implode( ', ', $names ) ) . '</p></details>';
		}
		echo '<p><a class="button" target="_blank" href="' . esc_url( Docs::url( 'poll', array( 'id' => $id ) ) ) . '">Verbale dei risultati (PDF)</a> '
			. '<a class="button" href="' . esc_url( Docs::url( 'poll', array( 'id' => $id ), 'csv' ) ) . '">Risultati (CSV)</a> ';
		if ( PollRules::OPEN === $p['status'] ) {
			echo Ui::confirm_button( 'asem_poll_announce', Ui::url( 'asem-polls', array( 'view' => $id ) ), array( 'id' => $id ), 'Avvisa i soci ora', 'Mandare ora l\'email ai soci che non hanno ancora votato?' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</p>';
	}
}

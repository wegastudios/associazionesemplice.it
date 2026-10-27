<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Assemblies;
use AssociazioneSemplice\AssemblyRules;
use AssociazioneSemplice\Docs;
use AssociazioneSemplice\Minutes;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PollRules;

defined( 'ABSPATH' ) || exit;

/** Registri → Assemblee: elenco, convocazione, presenze, deleghe, numero legale e verbale collegato. */
final class AssembliesPage {

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::detail( $view );
			return;
		}
		Ui::header( 'Assemblee', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-assemblies', array( 'new' => 1 ) ) ) . '">Nuova assemblea</a>' );
		echo '<p class="description">Convoca l\'assemblea dei soci, registra chi è presente, le deleghe tra soci e il numero legale, poi crea il verbale già compilato. Hanno diritto di intervenire i soci in regola (non i tutori né gli ospiti). Il verbale si trova poi in Registri → Verbali.</p>';
		$labels = AssemblyRules::status_labels();
		echo '<table class="widefat striped"><thead><tr><th>Assemblea</th><th>Tipo</th><th>Prima convocazione</th><th>Stato</th><th>Verbale</th></tr></thead><tbody>';
		$rows = Assemblies::all();
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessuna assemblea.</td></tr>';
		}
		foreach ( $rows as $a ) {
			$m = ! empty( $a['minutes_id'] ) ? Minutes::get( (int) $a['minutes_id'] ) : null;
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-assemblies', array( 'view' => (int) $a['id'] ) ) ) . '">' . esc_html( $a['title'] ) . '</a></td><td>' . esc_html( AssemblyRules::kinds()[ $a['kind'] ] ?? '' )
				. '</td><td>' . esc_html( mysql2date( 'd/m/Y H:i', $a['first_call_at'] ) ) . '</td><td>' . esc_html( $labels[ $a['status'] ] ?? '' ) . '</td><td>'
				. ( $m ? esc_html( 'n. ' . $m['number'] ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function detail( int $id ): void {
		$a = $id ? Assemblies::get( $id ) : null;
		Ui::header( $a ? $a['title'] : 'Nuova assemblea', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-assemblies' ) ) . '">← Tutte le assemblee</a>' );
		if ( $id && ! $a ) {
			echo '<p>Assemblea non trovata.</p>';
			Ui::footer();
			return;
		}
		if ( $a ) {
			self::status_box( $a );
		}
		if ( ! $a || AssemblyRules::HELD !== $a['status'] ) {
			self::form( $a );
		}
		if ( $a && in_array( $a['status'], array( AssemblyRules::DRAFT, AssemblyRules::CONVENED ), true ) ) {
			self::proxies_box( $a );
			self::attendance_box( $a );
		} elseif ( $a ) {
			self::proxies_list( $a );
		}
		Ui::footer();
	}

	private static function back( int $id ): string {
		return Ui::url( 'asem-assemblies', array( 'view' => $id ) );
	}

	private static function status_box( array $a ): void {
		$id = (int) $a['id'];
		$t  = Assemblies::tally( $id );
		$q  = $t['quorum'];
		echo '<h2>Situazione <span class="description">(' . esc_html( AssemblyRules::status_labels()[ $a['status'] ] ) . ')</span></h2>';
		echo '<p>Aventi diritto: <strong>' . (int) $t['eligible'] . '</strong> · presenti: <strong>' . (int) $t['present'] . '</strong> · rappresentati per delega: <strong>' . (int) $t['proxies']
			. '</strong> · numero legale (' . ( 2 === $t['call'] ? 'seconda' : 'prima' ) . ' convocazione, ' . (int) $t['percent'] . '%): ' . ( $q['required'] > 0 ? 'servono <strong>' . (int) $q['required'] . '</strong>' : 'nessun minimo' )
			. ' — <strong class="' . ( $q['reached'] ? 'asem-ok' : 'asem-neg' ) . '">' . ( $q['reached'] ? 'raggiunto' : 'non raggiunto' ) . '</strong></p>';
		echo '<p>';
		if ( AssemblyRules::DRAFT === $a['status'] ) {
			echo Ui::confirm_button( 'asem_assembly_convene', self::back( $id ), array( 'id' => $id ), 'Convoca e avvisa i soci', 'Convocare l\'assemblea e mandare l\'email a tutti i soci con diritto di intervenire?' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( AssemblyRules::CONVENED === $a['status'] ) {
			echo Ui::confirm_button( 'asem_assembly_convene', self::back( $id ), array( 'id' => $id ), 'Rimanda l\'avviso a chi non l\'ha ricevuto', 'Mandare la convocazione ai soci che non l\'hanno ancora ricevuta?' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			echo Ui::confirm_button( 'asem_assembly_hold', self::back( $id ), array( 'id' => $id, 'call' => 1 ), 'Chiudi: svolta in prima convocazione', 'Chiudere l\'assemblea come svolta in prima convocazione? Dopo non si modifica più.' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! empty( $a['second_call_at'] ) ) {
				echo Ui::confirm_button( 'asem_assembly_hold', self::back( $id ), array( 'id' => $id, 'call' => 2 ), 'Chiudi: svolta in seconda convocazione', 'Chiudere l\'assemblea come svolta in seconda convocazione? Dopo non si modifica più.' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		if ( in_array( $a['status'], array( AssemblyRules::DRAFT, AssemblyRules::CONVENED ), true ) ) {
			echo Ui::confirm_button( 'asem_assembly_cancel', self::back( $id ), array( 'id' => $id ), 'Annulla l\'assemblea', 'Annullare l\'assemblea?' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( AssemblyRules::HELD === $a['status'] ) {
			$m = ! empty( $a['minutes_id'] ) ? Minutes::get( (int) $a['minutes_id'] ) : null;
			if ( $m ) {
				echo 'Verbale collegato: <a href="' . esc_url( Ui::url( 'asem-minutes', array( 'view' => (int) $m['id'] ) ) ) . '">n. ' . esc_html( $m['number'] ) . '</a> · <a class="button" target="_blank" href="' . esc_url( Docs::url( 'minute', array( 'id' => (int) $m['id'] ) ) ) . '">Verbale (PDF)</a> ';
			} else {
				echo Ui::confirm_button( 'asem_assembly_minutes', self::back( $id ), array( 'id' => $id ), 'Crea il verbale', 'Creare il verbale con presenti, deleghe e numero legale già scritti?' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '<a class="button" href="' . esc_url( Docs::url( 'assembly', array( 'id' => $id ), 'csv' ) ) . '">Foglio presenze (CSV)</a></p>';
		if ( AssemblyRules::CANCELLED === $a['status'] ) {
			echo Ui::confirm_button( 'asem_assembly_draft', self::back( $id ), array( 'id' => $id ), 'Riporta in bozza', 'Riportare l\'assemblea in bozza?' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private static function form( ?array $a ): void {
		$id = $a ? (int) $a['id'] : 0;
		$v  = function ( string $k, string $default = '' ) use ( $a ) {
			return $a ? (string) ( $a[ $k ] ?? '' ) : $default;
		};
		$def = AssemblyRules::default_quorum( $v( 'kind', AssemblyRules::ORDINARY ) );
		echo '<h2>' . ( $a ? 'Modifica' : 'Crea' ) . '</h2>';
		Ui::form_open( 'asem_assembly_save', Ui::url( 'asem-assemblies', $a ? array( 'view' => $id ) : array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Tipo</th><td><select name="kind">' . Ui::options( AssemblyRules::kinds(), $v( 'kind', AssemblyRules::ORDINARY ) ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Titolo *</th><td><input type="text" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Prima convocazione *</th><td><input type="datetime-local" name="first_call_at" value="' . esc_attr( PollRules::to_local( $v( 'first_call_at' ) ) ) . '" required></td></tr>';
		echo '<tr><th>Seconda convocazione</th><td><input type="datetime-local" name="second_call_at" value="' . esc_attr( PollRules::to_local( $v( 'second_call_at' ) ) ) . '"> <span class="description">Facoltativa.</span></td></tr>';
		echo '<tr><th>Luogo</th><td><input type="text" name="place" value="' . esc_attr( $v( 'place' ) ) . '" class="large-text" maxlength="190"></td></tr>';
		echo '<tr><th>Ordine del giorno *</th><td><textarea name="agenda" rows="6" class="large-text">' . esc_textarea( $v( 'agenda' ) ) . '</textarea><p class="description">Un punto per riga. Serve per convocare.</p></td></tr>';
		echo '<tr><th>Numero legale</th><td>Prima convocazione <input type="number" min="0" max="100" name="quorum_first" value="' . esc_attr( $a ? $v( 'quorum_first' ) : (string) $def[0] ) . '" style="width:70px"> % · seconda <input type="number" min="0" max="100" name="quorum_second" value="' . esc_attr( $a ? $v( 'quorum_second' ) : (string) $def[1] ) . '" style="width:70px"> %'
			. '<p class="description">Percentuale degli aventi diritto presenti o rappresentati. 50% vale «metà più uno»; 0% nessun minimo. Per l\'assemblea ordinaria si parte da 50% e 0%, per la straordinaria da 75% e 50%: controlla lo statuto, che può prevedere altro.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $a ? 'Salva' : 'Crea' );
		Ui::form_close();
		if ( $a && in_array( $a['status'], array( AssemblyRules::DRAFT, AssemblyRules::CANCELLED ), true ) ) {
			echo Ui::confirm_button( 'asem_assembly_delete', Ui::url( 'asem-assemblies' ), array( 'id' => $id ), 'Elimina', 'Eliminare questa assemblea?' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private static function proxies_list( array $a ): void {
		$rows = Assemblies::proxies( (int) $a['id'] );
		if ( ! $rows ) {
			return;
		}
		echo '<h2>Deleghe</h2><ul>';
		foreach ( $rows as $x ) {
			echo '<li>' . esc_html( Assemblies::proxy_name( $x, 'a' ) ) . ' → ' . esc_html( Assemblies::proxy_name( $x, 'b' ) ) . '</li>';
		}
		echo '</ul>';
	}

	private static function proxies_box( array $a ): void {
		$id     = (int) $a['id'];
		$people = Assemblies::eligible_people();
		echo '<h2>Deleghe</h2>';
		$rows = Assemblies::proxies( $id );
		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Chi delega</th><th>Delegato a</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $x ) {
				echo '<tr><td>' . esc_html( Assemblies::proxy_name( $x, 'a' ) ) . '</td><td>' . esc_html( Assemblies::proxy_name( $x, 'b' ) ) . '</td><td>'
					. Ui::confirm_button( 'asem_assembly_proxy_revoke', self::back( $id ), array( 'id' => $id, 'delegator_id' => (int) $x['delegator_id'] ), 'Revoca', 'Revocare questa delega?' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nessuna delega.</p>';
		}
		echo '<p class="description">Ogni socio può avere al massimo ' . (int) AssemblyRules::max_proxies( count( $people ) ) . ' deleghe. I soci possono delegare anche dalla loro area riservata; qui registri una delega scritta ricevuta in segreteria.</p>';
		Ui::form_open( 'asem_assembly_proxy_add', self::back( $id ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Chi delega ' . Ui::person_select( 'delegator_id', $people, null, '— scegli —', 'asem-delegator' ) . ' delega ' . Ui::person_select( 'delegate_id', $people, null, '— scegli —', 'asem-delegate' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Registra la delega', 'secondary', 'submit', false );
		echo '</p>';
		Ui::form_close();
	}

	private static function attendance_box( array $a ): void {
		$id      = (int) $a['id'];
		$present = Assemblies::present_ids( $id );
		echo '<h2>Presenze</h2>';
		Ui::form_open( 'asem_assembly_attendance', self::back( $id ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div style="columns:3 240px;max-width:900px">';
		foreach ( Assemblies::eligible_people() as $p ) {
			echo '<label style="display:block"><input type="checkbox" name="present[]" value="' . (int) $p['id'] . '"' . checked( in_array( (int) $p['id'], $present, true ), true, false ) . '> ' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</label>';
		}
		echo '</div>';
		submit_button( 'Salva le presenze' );
		Ui::form_close();
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Tecniche (integrazioni, ruoli e accessi) e Contabilità (opzioni contabili). Solo amministratori. */
final class TechPage {

	/** Ruoli che non si toccano: l'amministratore ha già tutto, la segreteria si gestisce dalle schede dei soci, il socio non è un ruolo di gestione. */
	const LOCKED_ROLES = array( 'administrator', 'asem_secretary', 'asem_member' );

	// ---------- Integrazioni ----------

	public static function render_integrations(): void {
		Ui::header( 'Integrazioni' );
		do_action( 'asem_integrations_top' ); // le estensioni aggiungono qui le loro sezioni
		echo '<h2>Ricerca dei luoghi con Google</h2>';
		echo '<p class="description">Nei campi «Luogo» di corsi ed eventi compaiono i suggerimenti di Google mentre si scrive. Spenta di default. Serve una chiave API di Google Maps dell\'ente (Google Cloud, servizio «Places API»): '
			. 'limitala ai siti autorizzati dal pannello di Google, perché è visibile nel codice della pagina. Il collegamento con Google avviene solo nelle pagine di amministrazione, dal browser di chi compila il campo; nessun dato dei soci viene inviato.</p>'
			. '<p><a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank" rel="noopener noreferrer">Come ottenere la chiave API di Google Maps (guida di Google, si apre in una nuova finestra) ↗</a></p>';
		Ui::form_open( 'asem_save_places', Ui::url( 'asem-tech' ) );
		echo '<table class="form-table"><tbody><tr><th>Ricerca dei luoghi</th><td><label><input type="checkbox" name="places_enabled" value="1"' . checked( ! empty( Settings::get( 'places_enabled' ) ), true, false ) . '> Accendi i suggerimenti di Google</label></td></tr>'
			. '<tr><th>Chiave API di Google Maps</th><td><input type="text" name="places_key" value="' . esc_attr( (string) Settings::get( 'places_key' ) ) . '" class="regular-text" autocomplete="off" maxlength="100"></td></tr></tbody></table>';
		echo '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<h2>Altre integrazioni</h2><ul>';
		do_action( 'asem_integrations_list' ); // elenco delle altre integrazioni
		echo '<li><strong>QR e calendario</strong>: <a href="' . esc_url( Ui::url( 'asem-card' ) ) . '">Tessera e QR</a>.</li>';
		echo '<li><strong>WP All Import</strong> — ' . ( class_exists( 'PMXI_Plugin' ) ? 'attivo su questo sito' : 'non attivo' ) . ': <a href="' . esc_url( Ui::url( 'asem-wpai' ) ) . '">Import con WP All Import</a>.</li>';
		echo '<li><strong>Elementor e Gutenberg</strong> — widget e blocchi «Associazione Semplice» disponibili dove gli editor sono attivi.</li></ul>';
		Ui::footer();
	}

	// ---------- Ruoli e accessi ----------

	/** @return array<string,array{name:string,has:bool,users:int}> */
	public static function roles(): array {
		$counts = count_users();
		$out    = array();
		foreach ( wp_roles()->roles as $key => $r ) {
			if ( in_array( $key, self::LOCKED_ROLES, true ) ) {
				continue;
			}
			$role        = get_role( $key );
			$out[ $key ] = array( 'name' => translate_user_role( (string) $r['name'] ), 'has' => (bool) ( $role && $role->has_cap( Plugin::CAP_OPS ) ), 'users' => (int) ( $counts['avail_roles'][ $key ] ?? 0 ) );
		}
		return $out;
	}

	/** Dà o toglie l'accesso operativo (come la segreteria) a un ruolo di WordPress. @throws \InvalidArgumentException */
	public static function set_role_access( string $key, bool $on ): void {
		if ( in_array( $key, self::LOCKED_ROLES, true ) ) {
			throw new \InvalidArgumentException( 'Questo ruolo non si cambia da qui.' );
		}
		$role = get_role( $key );
		if ( ! $role ) {
			throw new \InvalidArgumentException( 'Ruolo non trovato.' );
		}
		if ( $on && ! $role->has_cap( Plugin::CAP_OPS ) ) {
			$role->add_cap( Plugin::CAP_OPS );
		} elseif ( ! $on && $role->has_cap( Plugin::CAP_OPS ) ) {
			$role->remove_cap( Plugin::CAP_OPS );
		}
	}

	public static function render_roles(): void {
		Ui::header( 'Ruoli e accessi' );
		echo '<p class="description">Chi può usare il plugin: gli <strong>amministratori</strong> hanno tutto; la <strong>Segreteria</strong> lavora su soci, attività e contabilità senza toccare le impostazioni (si assegna dalla scheda del socio). '
			. 'Se ti serve, puoi dare lo stesso accesso operativo a un altro ruolo di WordPress (ad esempio «Editor» o un ruolo creato da te): chi lo ha vede e gestisce soci, attività, registri e prima nota, ma non le impostazioni, i pagamenti, la copia di sicurezza e i testi. '
			. 'Il tesoriere e lo staff degli eventi sono ruoli sulle persone (scheda del socio e scheda dell\'attività), non ruoli di WordPress.</p>';
		Ui::form_open( 'asem_save_roles', Ui::url( 'asem-roles' ) );
		echo '<table class="widefat striped"><thead><tr><th>Ruolo di WordPress</th><th>Utenti</th><th>Accesso operativo al plugin</th></tr></thead><tbody>';
		foreach ( self::roles() as $key => $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . ' <span class="description">(' . esc_html( $key ) . ')</span></td><td>' . (int) $r['users'] . '</td><td><label><input type="checkbox" name="roles[]" value="' . esc_attr( $key ) . '"' . checked( $r['has'], true, false ) . '> Come la segreteria</label></td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<p class="description">Per dare l\'accesso a una sola persona, crea o assegna il ruolo «Segreteria» dalla sua scheda in Rubrica soci.</p>';
		self::roles_matrix();
		self::roles_people();
		Ui::footer();
	}

	/** Chi può fare cosa: i ruoli dell'ente. */
	public static function matrix(): array {
		$yes = '✓';
		$no  = '—';
		return array(
			'columns' => array( 'Amministratore', 'Segreteria', 'Presidente e vice', 'Tesoriere', 'Volontario', 'Staff dell\'ente', 'Staff dell\'evento', 'Socio' ),
			'rows'    => array(
				'Impostazioni, pagamenti online, copia di sicurezza' => array( $yes, $no, $no, $no, $no, $no, $no, $no ),
				'Soci e iscrizioni'                                   => array( $yes, $yes, $yes, 'iscrive', $no, $no, $no, $no ),
				'Incassi e spese'                                     => array( $yes, $yes, $yes, $yes, $no, $no, $no, $no ),
				'Contabilità, registri e adempimenti'                 => array( $yes, $yes, $yes, $no, $no, $no, $no, $no ),
				'Comunicazioni agli iscritti'                         => array( $yes, $yes, $yes, $no, 'ai propri eventi', $no, $no, $no ),
				'Eventi e attività'                                   => array( $yes, $yes, $yes, 'vende', 'propri', $no, $no, $no ),
				'Verifica degli ingressi'                             => array( $yes, $yes, $yes, $no, 'propri eventi', 'tutti gli eventi', 'suo evento', $no ),
				'Incasso del biglietto sul posto'                     => array( $yes, $yes, $yes, $yes, 'propri eventi', $no, 'se abilitato', $no ),
				'Propri dati, tessera e pagamenti'                    => array( $yes, $yes, $yes, $yes, $yes, $yes, $yes, $yes ),
			),
		);
	}

	private static function roles_matrix(): void {
		$m = self::matrix();
		echo '<h2>Chi può fare cosa</h2><p class="description">Il presidente e il vicepresidente (in regola con la tessera) agiscono come la segreteria su tutto. Il tesoriere lavora dall\'area riservata: incassa, registra spese, iscrive soci e vende gli eventi, ma non vede comunicazioni né impostazioni. Il volontario non gestisce soldi: gestisce comunicazioni e staff dei propri eventi. Lo staff verifica gli ingressi: di tutti gli eventi se è staff dell\'ente, solo di quello indicato se è staff dell\'evento (e lì, se abilitato, può anche incassare).</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th></th>';
		foreach ( $m['columns'] as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $m['rows'] as $label => $cells ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			foreach ( $cells as $c ) {
				echo '<td>' . esc_html( $c ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Chi ha oggi un ruolo che dà permessi: cariche, segreteria, tesorieri e staff. */
	public static function role_holders(): array {
		$out = array();
		foreach ( Plugin::people()->search() as $p ) {
			$roles = array();
			if ( in_array( (string) $p['board_role'], array( \AssociazioneSemplice\BoardRole::PRESIDENT, \AssociazioneSemplice\BoardRole::VICE_PRESIDENT ), true ) ) {
				$roles[] = \AssociazioneSemplice\BoardRole::label( $p['board_role'] ) . ' (come la segreteria)';
			}
			if ( ! empty( $p['wp_user_id'] ) ) {
				$u = (int) $p['wp_user_id'];
				if ( user_can( $u, Plugin::CAP_OPS ) && ! user_can( $u, Plugin::CAP ) && $roles === array() ) {
					$roles[] = 'Segreteria';
				}
				if ( \AssociazioneSemplice\Access::is_treasurer( $u ) ) {
					$roles[] = 'Tesoriere';
				}
				if ( \AssociazioneSemplice\Access::is_entity_staff( $u ) ) {
					$roles[] = 'Staff dell\'ente';
				}
			}
			if ( $roles ) {
				$out[] = array( 'person' => $p, 'roles' => $roles );
			}
		}
		return $out;
	}

	private static function roles_people(): void {
		$rows = self::role_holders();
		echo '<h2>Chi ha un ruolo</h2>';
		if ( ! $rows ) {
			echo '<p class="description">Nessuno ha ancora un ruolo con permessi particolari. Si assegnano dalla scheda della persona in Rubrica soci.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Persona</th><th>Ruolo</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $r['person']['id'] ) ) ) . '">' . esc_html( trim( $r['person']['first_name'] . ' ' . $r['person']['last_name'] ) ) . '</a></td><td>' . esc_html( implode( ', ', $r['roles'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- Contabilità ----------

}

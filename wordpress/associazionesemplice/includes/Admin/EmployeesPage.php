<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\EmployeeRules;
use AssociazioneSemplice\Employees;
use AssociazioneSemplice\Html;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Dipendenti: elenco, creazione, periodo di servizio e permessi per area (si tolgono le aree che non servono).
 * La schermata la vedono e la usano solo gli amministratori del sito, il presidente e il vicepresidente.
 */
final class EmployeesPage {

	/** Le azioni usano il controllo generale (nonce, redirect, messaggi) e controllano da sé chi può gestire i dipendenti. */
	public static function register(): void {
		Actions::add_handler( 'asem_employee_create', array( __CLASS__, 'create' ), false );
		Actions::add_handler( 'asem_employee_save', array( __CLASS__, 'save' ), false );
		Actions::add_handler( 'asem_employee_invite', array( __CLASS__, 'invite' ), false );
	}

	public static function create( array $p ): array {
		$uid  = Employees::create(
			array(
				'first_name' => (string) ( $p['first_name'] ?? '' ),
				'last_name'  => (string) ( $p['last_name'] ?? '' ),
				'email'      => (string) ( $p['email'] ?? '' ),
				'from'       => (string) ( $p['from'] ?? '' ),
				'to'         => (string) ( $p['to'] ?? '' ),
			)
		);
		$sent = Employees::send_invite( $uid );
		return array( Ui::url( 'asem-employees' ), 'Dipendente creato.' . ( $sent ? ' Gli è arrivato l\'invito per scegliere la password.' : ' L\'invito per email non è partito: controlla la posta del sito, poi usa «Rimanda l\'invito».' ) );
	}

	public static function save( array $p ): array {
		$uid = (int) ( $p['user_id'] ?? 0 );
		Employees::set_period( $uid, array( 'from' => (string) ( $p['from'] ?? '' ), 'to' => (string) ( $p['to'] ?? '' ) ) );
		$given = array_map( 'strval', (array) ( $p['perm'] ?? array() ) ); // le caselle spuntate sono i permessi che il dipendente ha
		$denied = array_diff( EmployeeRules::enforced_keys(), $given );
		foreach ( Employees::denied_for( $uid ) as $kept ) { // le aree «in arrivo» non hanno la spunta attiva: restano come sono
			if ( in_array( $kept, EmployeeRules::pending_keys(), true ) ) {
				$denied[] = $kept;
			}
		}
		Employees::set_denied( $uid, array_values( $denied ) );
		return array( Ui::url( 'asem-employees' ), 'Dipendente aggiornato.' );
	}

	public static function invite( array $p ): array {
		$uid = (int) ( $p['user_id'] ?? 0 );
		if ( ! Employees::can_manage( get_current_user_id() ) || ! Employees::is_employee( $uid ) ) {
			throw new \InvalidArgumentException( 'Solo il presidente, il vicepresidente e gli amministratori del sito possono mandare l\'invito.' );
		}
		return array( Ui::url( 'asem-employees' ), Employees::send_invite( $uid ) ? 'Invito rimandato.' : 'L\'invito non è partito: controlla la posta del sito.' );
	}

	public static function render(): void {
		Ui::header( 'Dipendenti' );
		if ( ! Employees::can_manage( get_current_user_id() ) ) {
			echo '<p>Questa pagina è riservata al presidente, al vicepresidente e agli amministratori del sito.</p>';
			Ui::footer();
			return;
		}
		Employees::mark_areas_seen( get_current_user_id() );
		Employees::mark_legacy_roles_seen( get_current_user_id() );
		echo '<p class="description">Il dipendente è un utente del sito con gli <strong>stessi permessi della segreteria</strong>. Non è nel libro soci, non ha la tessera e non conta per le assemblee. '
			. 'Un dipendente nuovo ha tutti i permessi qui sotto: amministratori, presidente e vicepresidente possono toglierli area per area. Impostazioni, pagamenti e copie di sicurezza restano solo agli amministratori. '
			. 'Ogni cambio resta scritto nel registro azioni. Il numero dei lavoratori per il RUNTS è il numero dei dipendenti in servizio alla data di riferimento: nei «Dati per il RUNTS» è il 31 dicembre dell\'anno che scegli lì.</p>';
		echo '<p class="description">Se il dipendente è anche socio, i due accessi restano separati: da dipendente entra con questo utente del sito, da socio con il suo accesso personale all\'area soci (link o codice via email) e una scheda nel libro soci a parte. Per il socio usa un\'altra email, oppure l\'accesso personale con la stessa email.</p>';
		if ( current_user_can( Plugin::CAP ) ) {
			echo '<p><a href="' . esc_url( Ui::url( 'asem-audit', array( 'type' => 'employee.' ) ) ) . '">Vedi chi ha tolto o dato permessi, e quando →</a></p>';
		}
		self::list_table();
		self::create_form();
		Ui::footer();
	}

	private static function list_table(): void {
		$rows = Employees::all();
		echo '<h2>Dipendenti (' . (int) count( $rows ) . ')</h2>';
		if ( ! $rows ) {
			echo '<p>Nessun dipendente. Creane uno qui sotto.</p>';
			return;
		}
		foreach ( $rows as $e ) {
			$u = $e['user'];
			echo '<div class="asem-card">';
			Ui::form_open( 'asem_employee_save', Ui::url( 'asem-employees' ) );
			echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
			echo wp_kses( '<h3>' . esc_html( $u->display_name ) . ' <span class="description">' . esc_html( $u->user_email ) . '</span> '
				. ( $e['active'] ? Ui::state( 'ok', 'In servizio' ) : Ui::state( 'warn', 'Non in servizio' ) ) . '</h3>', Html::allowed() );
			echo '<p><label>Primo giorno di servizio <input type="date" name="from" value="' . esc_attr( (string) $e['from'] ) . '"></label> '
				. '<label>Ultimo giorno <input type="date" name="to" value="' . esc_attr( (string) $e['to'] ) . '"></label> '
				. '<span class="description">Facoltativi: senza date è in servizio. Fuori dal periodo non può usare il gestionale e non conta tra i lavoratori del RUNTS.</span></p>';
			echo '<fieldset><legend><strong>Permessi</strong> <span class="description">(togli la spunta alle aree che non gli servono)</span></legend>';
			echo '<p class="description">' . esc_html( \AssociazioneSemplice\Texts::t( 'Chi ha l\'area Corsi o Eventi vede i certificati degli iscritti alle attività di cui è referente e a quelle senza referente.' ) ) . '</p>';
			echo '<p class="description">' . esc_html( \AssociazioneSemplice\Texts::t( 'I dipendenti nuovi partono senza il permesso «Vedere dati riservati ed esportare»: va dato apposta a chi ne ha bisogno. Quelli già presenti restano come sono.' ) ) . '</p>';
			$hints = EmployeeRules::hints();
			foreach ( EmployeeRules::labels() as $key => $label ) {
				$soon = in_array( $key, EmployeeRules::pending_keys(), true ); // l'area non ha ancora i suoi controlli: spunta disattivata
				echo '<p style="margin:2px 0"><label><input type="checkbox" name="perm[]" value="' . esc_attr( $key ) . '"' . checked( ! in_array( $key, $e['denied'], true ), true, false ) . ( $soon ? ' disabled' : '' ) . '> ' . esc_html( $label ) . ( $soon ? ' <span class="description">' . esc_html( \AssociazioneSemplice\Texts::t( '(in arrivo)' ) ) . '</span>' : '' ) . '</label>' . ( isset( $hints[ $key ] ) ? '<br><span class="description" style="margin-left:24px">' . esc_html( $hints[ $key ] ) . '</span>' : '' ) . '</p>';
			}
			echo '</fieldset><p><button class="button button-primary">Salva</button></p>';
			Ui::form_close();
			echo wp_kses( Ui::confirm_button( 'asem_employee_invite', Ui::url( 'asem-employees' ), array( 'user_id' => (int) $u->ID ), 'Rimanda l\'invito', 'Mandare di nuovo a ' . $u->user_email . ' il link per scegliere la password?' ), Html::allowed() );
			echo wp_kses( TwoFactorPage::user_panel( $u, Ui::url( 'asem-employees' ) ), Html::allowed() ); // verifica in due passaggi: l'amministratore la può spegnere
			echo '</div>';
		}
	}

	private static function create_form(): void {
		echo '<h2>Nuovo dipendente</h2>';
		Ui::form_open( 'asem_employee_create', Ui::url( 'asem-employees' ) );
		echo '<table class="form-table"><tr><th>Nome *</th><td><input type="text" name="first_name" required></td></tr>'
			. '<tr><th>Cognome *</th><td><input type="text" name="last_name" required></td></tr>'
			. '<tr><th>Email *</th><td><input type="email" name="email" required> <span class="description">Gli arriva l\'invito per scegliere la password.</span></td></tr>'
			. '<tr><th>Primo giorno di servizio</th><td><input type="date" name="from"></td></tr>'
			. '<tr><th>Ultimo giorno di servizio</th><td><input type="date" name="to"> <span class="description">Facoltativo.</span></td></tr></table>'
			. '<p><button class="button button-primary">Crea il dipendente</button></p>';
		Ui::form_close();
	}
}

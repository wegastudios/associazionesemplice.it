<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Affiliations;
use AssociazioneSemplice\AffiliationRules;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpiryRules;
use AssociazioneSemplice\MemberQuery;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Affiliazioni agli enti (CONI/RAS, UISP, CSEN, ACSI, AICS, altri): affiliazione dell'associazione, tessere dei soci, tesseramento in blocco e file per i portali. */
final class AffiliationsPage {

	const CLASSES = array( ExpiryRules::VALID => 'asem-ok', ExpiryRules::EXPIRING => 'asem-warn', ExpiryRules::EXPIRED => 'asem-neg', ExpiryRules::NONE => 'asem-neg' );

	private static function badge( string $status ): string {
		return '<strong class="' . esc_attr( self::CLASSES[ $status ] ) . '">' . esc_html( ExpiryRules::labels()[ $status ] ) . '</strong>';
	}

	private static function date( $ymd ): string {
		return $ymd ? esc_html( ExpiryRules::it_date( (string) $ymd ) ) : '—';
	}

	private static function cost( $cents ): string {
		return null === $cents || '' === $cents ? '—' : esc_html( Money::format( (int) $cents ) );
	}

	/** Campi «ente» dei moduli: elenco e, per «Altro ente», il nome. */
	private static function body_fields( string $selected = '', string $custom = '' ): string {
		return '<select name="body">' . Ui::options( AffiliationRules::bodies(), $selected ?: 'coni' ) . '</select> <input type="text" name="body_label" maxlength="80" placeholder="Nome dell\'ente (se «Altro ente»)" value="' . esc_attr( $custom ) . '">';
	}

	public static function render(): void {
		$edit = Ui::get_int( 'edit' );
		$a    = $edit ? Affiliations::affiliation( $edit ) : null;
		Ui::header( 'Affiliazioni' );
		echo '<p class="description">L\'affiliazione dell\'associazione agli enti di promozione sportiva e il tesseramento dei soci. Il tutore e l\'ospite non sono soci: non si tesserano.</p>';
		self::affiliations_section( $a );
		self::cards_section();
		self::bulk_section();
		if ( current_user_can( Plugin::CAP ) ) {
			self::settings_card();
		}
		Ui::footer();
	}

	private static function affiliations_section( ?array $edit ): void {
		$back = Ui::url( 'asem-affiliations' );
		echo '<h2>Affiliazioni dell\'associazione</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Ente</th><th>Codice</th><th>Discipline</th><th>Stagione</th><th>Scade il</th><th>Costo</th><th>Stato</th><th></th></tr></thead><tbody>';
		$rows = Affiliations::affiliations();
		if ( ! $rows ) {
			echo '<tr><td colspan="8">Nessuna affiliazione registrata.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( Affiliations::body_name( $r ) ) . '</td><td>' . esc_html( $r['code'] ) . '</td><td>' . esc_html( (string) ( $r['disciplines'] ?? '' ) ) . '</td><td>' . esc_html( $r['season'] ) . '</td><td>' . self::date( $r['expires_on'] ) . '</td><td>' . self::cost( $r['cost_cents'] ) . '</td><td>' // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
				. ( $r['expires_on'] ? self::badge( $r['status'] ) : '—' ) . '</td><td><a href="' . esc_url( Ui::url( 'asem-affiliations', array( 'edit' => (int) $r['id'] ) ) ) . '#asem-aff-form">modifica</a> ';
			Ui::form_open( 'asem_aff_delete', $back, false, 'asem-inline' );
			echo Ui::hidden( 'id', $r['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa affiliazione? Le tessere dei soci restano.">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$v = $edit ?: array( 'body' => 'coni', 'body_label' => '', 'code' => '', 'disciplines' => '', 'season' => AffiliationRules::default_season( Db::today() ), 'expires_on' => '', 'cost_cents' => null );
		Ui::form_open( 'asem_aff_save', $back );
		echo '<div id="asem-aff-form"><h3>' . ( $edit ? 'Modifica l\'affiliazione' : 'Registra un\'affiliazione' ) . '</h3>' . Ui::hidden( 'id', $edit ? $edit['id'] : 0 ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p>' . self::body_fields( (string) $v['body'], (string) $v['body_label'] ) . ' <input type="text" name="code" maxlength="60" placeholder="Codice di affiliazione" required value="' . esc_attr( (string) $v['code'] ) . '"></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><input type="text" name="disciplines" class="large-text" maxlength="190" placeholder="Discipline per cui sei affiliato all\'ente (es. Yoga, Pilates, Danza)" value="' . esc_attr( (string) ( $v['disciplines'] ?? '' ) ) . '"></p>'
			. '<p>Stagione <input type="text" name="season" size="10" required value="' . esc_attr( (string) $v['season'] ) . '"> scade il <input type="date" name="expires_on" value="' . esc_attr( (string) $v['expires_on'] ) . '"> costo € <input type="text" name="cost" size="8" inputmode="decimal" value="' . esc_attr( null === $v['cost_cents'] ? '' : Money::plain( (int) $v['cost_cents'] ) ) . '"></p>'
			. '<p><button class="button button-primary">' . ( $edit ? 'Salva le modifiche' : 'Registra l\'affiliazione' ) . '</button>' . ( $edit ? ' <a class="button" href="' . esc_url( $back ) . '">Annulla</a>' : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
	}

	private static function cards_section(): void {
		$f = array(
			'body'     => Ui::get_str( 'body' ),
			'season'   => Ui::get_str( 'season' ),
			'status'   => Ui::get_str( 'status' ),
			'activity' => Ui::get_int( 'activity' ),
		);
		$f['status'] = in_array( $f['status'], array( ExpiryRules::VALID, ExpiryRules::EXPIRING, ExpiryRules::EXPIRED ), true ) ? $f['status'] : '';
		$seasons     = array_combine( Affiliations::seasons(), Affiliations::seasons() );
		$acts        = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$acts[ $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		$back = Ui::url( 'asem-affiliations', array_filter( $f ) );
		echo '<h2 id="asem-cards">Tessere dei soci</h2>';
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-affiliations">'
			. '<select name="body">' . Ui::options( AffiliationRules::bodies(), $f['body'], 'Tutti gli enti' ) . '</select> '
			. '<select name="season">' . Ui::options( $seasons, $f['season'], 'Tutte le stagioni' ) . '</select> '
			. '<select name="status">' . Ui::options( array( ExpiryRules::VALID => 'In regola', ExpiryRules::EXPIRING => 'In scadenza', ExpiryRules::EXPIRED => 'Scadute' ), $f['status'], 'Tutti gli stati' ) . '</select> '
			. '<select name="activity">' . Ui::options( $acts, $f['activity'] ?: null, 'Tutte le attività' ) . '</select> <button class="button">Filtra</button> '
			. '<a class="button" href="' . esc_url( BlockCExports::url( 'asem_aff_csv', $f + array( 'kind' => 'list' ) ) ) . '">Esporta l\'elenco (CSV)</a> '
			. '<a class="button button-primary" href="' . esc_url( BlockCExports::url( 'asem_aff_csv', $f + array( 'kind' => 'portal' ) ) ) . '">File per il portale dell\'ente (CSV)</a></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		echo '<p class="description">Il file per il portale ha le colonne: nome, cognome, codice fiscale, data di nascita, luogo di nascita, sesso, indirizzo, email, telefono, tipo tessera. Sesso e luogo di nascita (codice catastale) si leggono dal codice fiscale: controllali prima del caricamento.</p>';
		$rows = Affiliations::cards( $f );
		echo '<table class="widefat striped"><thead><tr><th>Socio</th><th>Ente</th><th>Tessera</th><th>Tipo</th><th>Stagione</th><th>Data</th><th>Scade il</th><th>Costo</th><th>Stato</th><th>Numero e scadenza</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="10">Nessuna tessera con questi criteri.</td></tr>';
		}
		foreach ( $rows as $c ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $c['person_id'] ) ) ) . '">' . esc_html( trim( $c['last_name'] . ' ' . $c['first_name'] ) ) . '</a></td><td>' . esc_html( Affiliations::body_name( $c ) ) . '</td><td>' . esc_html( (string) $c['card_number'] ) . '</td><td>' . esc_html( (string) $c['card_type'] ) . '</td><td>' . esc_html( $c['season'] ) . '</td><td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. self::date( $c['issued_on'] ) . '</td><td>' . self::date( $c['expires_on'] ) . '</td><td>' . self::cost( $c['cost_cents'] ) . '</td><td>' . ( $c['expires_on'] ? self::badge( $c['status'] ) : '—' ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::card_forms( $c, $back );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Modulo in riga per numero e scadenza di una tessera, e per eliminarla. */
	private static function card_forms( array $c, string $back ): void {
		Ui::form_open( 'asem_card_update', $back, false, 'asem-inline' );
		echo Ui::hidden( 'id', $c['id'] ) . '<input type="text" name="card_number" size="12" maxlength="60" placeholder="N. tessera" value="' . esc_attr( (string) $c['card_number'] ) . '"> <input type="date" name="expires_on" value="' . esc_attr( (string) $c['expires_on'] ) . '"> <button class="button button-small">Salva</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		Ui::form_open( 'asem_card_delete', $back, false, 'asem-inline' );
		echo Ui::hidden( 'id', $c['id'] ) . ' <button class="button-link-delete" data-confirm="Eliminare questa tessera?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
	}

	private static function card_fields(): string {
		return '<p>' . self::body_fields() . ' stagione <input type="text" name="season" size="10" required value="' . esc_attr( AffiliationRules::default_season( Db::today() ) ) . '"></p>'
			. '<p><input type="text" name="card_type" maxlength="60" placeholder="Tipo tessera (es. Atleta, Dirigente)"> data <input type="date" name="issued_on" value="' . esc_attr( Db::today() ) . '"> scade il <input type="date" name="expires_on"> costo € <input type="text" name="cost" size="8" inputmode="decimal"></p>';
	}

	private static function bulk_section(): void {
		$acts = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$acts[ $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		$back = Ui::url( 'asem-affiliations' );
		echo '<h2>Nuova tessera</h2>';
		Ui::form_open( 'asem_card_add', $back );
		echo '<p>' . Ui::person_select( 'person_id', MemberQuery::active(), null, 'Scegli il socio…' ) . ' <input type="text" name="card_number" maxlength="60" placeholder="N. tessera (facoltativo)"></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. self::card_fields() . '<p><button class="button button-primary">Registra la tessera</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		echo '<h2>Tesseramento in blocco</h2><p class="description">Crea la tessera per tutti i soci attivi, o per gli iscritti a un\'attività. Chi ha già una tessera dello stesso ente e stagione viene saltato. Il numero si aggiunge poi dall\'elenco.</p>';
		Ui::form_open( 'asem_card_bulk', $back );
		echo '<p><label><input type="radio" name="scope" value="all" checked> tutti i soci attivi</label> &nbsp; <label><input type="radio" name="scope" value="activity"> gli iscritti a <select name="activity_id">' . Ui::options( $acts, null, 'Scegli l\'attività…' ) . '</select></label></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. self::card_fields() . '<p><button class="button button-primary" data-confirm="Creare le tessere per tutti i soci scelti?">Tessera i soci</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
	}

	private static function settings_card(): void {
		echo '<div class="asem-card"><h2>Impostazioni</h2>';
		Ui::form_open( 'asem_save_aff_settings', Ui::url( 'asem-affiliations' ) );
		echo '<table class="form-table"><tr><th>Preavviso</th><td><input type="number" name="aff_warn_days" min="1" max="365" style="width:80px" value="' . (int) Affiliations::warn_days() . '"> giorni prima della scadenza un\'affiliazione o una tessera risulta «In scadenza».</td></tr>'
			. '<tr><th>Promemoria automatici</th><td><label><input type="checkbox" name="aff_reminders" value="1"' . checked( ! empty( Settings::get( 'aff_reminders' ) ), true, false ) . '> avvisa della scadenza di affiliazioni e tessere</label>'
			. '<p class="description">Il socio (o il suo tutore) riceve un\'email per la sua tessera; la segreteria riceve un\'unica email con l\'elenco di affiliazioni e tessere. Una sola volta per ogni scadenza e tipo di avviso, una volta al giorno.</p></td></tr></table>'
			. '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Scheda nella pagina di una persona: le sue tessere e una nuova. */
	public static function person_panel( array $p ): void {
		if ( ! Edition::has( 'affiliations' ) || ! current_user_can( Plugin::CAP_OPS ) || ! MemberType::is_member( (string) $p['type'] ) || ! MemberQuery::find( (int) $p['id'] ) ) { // un socio sospeso o uscito non ha il modulo: il salvataggio lo rifiuterebbe
			return;
		}
		$id   = (int) $p['id'];
		$back = Ui::url( 'asem-person', array( 'id' => $id ) );
		echo '<div class="asem-card"><h2>Tessere degli enti</h2>';
		$cards = Affiliations::cards_for_person( $id );
		if ( $cards ) {
			echo '<table class="widefat striped"><thead><tr><th>Ente</th><th>Tessera</th><th>Tipo</th><th>Stagione</th><th>Scade il</th><th>Costo</th><th></th></tr></thead><tbody>';
			foreach ( $cards as $c ) {
				echo '<tr><td>' . esc_html( Affiliations::body_name( $c ) ) . '</td><td>' . esc_html( (string) $c['card_number'] ) . '</td><td>' . esc_html( (string) $c['card_type'] ) . '</td><td>' . esc_html( $c['season'] ) . '</td><td>' . self::date( $c['expires_on'] ) . '</td><td>' . self::cost( $c['cost_cents'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_open( 'asem_card_delete', $back, false, 'asem-inline' );
				echo Ui::hidden( 'id', $c['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa tessera?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nessuna tessera.</p>';
		}
		Ui::form_open( 'asem_card_add', $back );
		echo Ui::hidden( 'person_id', $id ) . '<h3>Registra una tessera</h3><p><input type="text" name="card_number" maxlength="60" placeholder="N. tessera (facoltativo)"></p>' . self::card_fields() . '<p><button class="button button-primary">Registra la tessera</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		echo '</div>';
	}

	/** Avviso in Bacheca per lo staff. */
	public static function alert(): void {
		if ( ! Edition::has( 'affiliations' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		$n   = Affiliations::counts();
		$sum = array_sum( $n );
		if ( $sum > 0 ) {
			echo Dismiss::html( 'affiliations-' . implode( '-', $n ), 'warning', 'Affiliazioni: ' . (int) $n['aff_expired'] . ' affiliazioni scadute e ' . (int) $n['aff_expiring'] . ' in scadenza; ' . (int) $n['card_expired'] . ' tessere scadute e ' . (int) $n['card_expiring'] . ' in scadenza. <a href="' . esc_url( Ui::url( 'asem-affiliations' ) ) . '">Apri le affiliazioni</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
		}
	}
}

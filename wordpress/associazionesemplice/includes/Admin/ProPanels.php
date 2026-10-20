<?php
namespace AssociazioneSemplice\Admin;

defined( 'ABSPATH' ) || exit;

/** Ricevute e attestazioni (funzione aggiuntiva) nella scheda di una persona e nella prima nota. */
final class ProPanels {

	public static function register(): void {
		add_filter( 'asem_entity_page_title', function () {
			return 'Dati dell\'ente e fiscalità';
		} );
		add_action( 'asem_entity_rows', array( __CLASS__, 'entity_rows' ) );
		add_action( 'asem_entity_sections', array( __CLASS__, 'entity_sections' ) );
		add_action( 'asem_entity_page_end', array( __CLASS__, 'entity_page_end' ) );
		add_action( 'asem_person_receipts', array( __CLASS__, 'person_receipts' ) );
		add_action( 'asem_ledger_row_actions', array( __CLASS__, 'ledger_row_actions' ), 10, 2 );
	}

	/** Ricevute e attestazioni annuali della persona. */
	public static function person_receipts( array $p ): void {
		if ( ! \AssociazioneSemplice\Edition::has( 'receipts' ) ) {
			return;
		}
		$id    = (int) $p['id'];
		$years = \AssociazioneSemplice\Receipts::years_for_payer( $id );
		if ( ! $years ) {
			return;
		}
		echo '<div class="asem-card"><h2>Ricevute e attestazioni</h2><p class="description">Le ricevute dei singoli incassi sono in Contabilità → Prima nota.</p><p>';
		foreach ( $years as $y ) {
			echo '<a class="button" target="_blank" href="' . esc_url( \AssociazioneSemplice\Receipts::statement_url( $id, (int) $y ) ) . '">Attestazione ' . (int) $y . '</a> ';
		}
		echo '</p><ul>';
		foreach ( \AssociazioneSemplice\Receipts::list_for_payer( $id, 8 ) as $r ) {
			echo '<li>' . Ui::date( $r['date'] ) . ' · ' . esc_html( $r['what'] ) . ' · ' . esc_html( \AssociazioneSemplice\Money::format( $r['cents'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ' <a target="_blank" href="' . esc_url( \AssociazioneSemplice\Receipts::url( $r['key'] ) ) . '">ricevuta</a></li>';
		}
		echo '</ul></div>';
	}

	/** Nella riga di un incasso: ricevuta PDF e invio per email. */
	public static function ledger_row_actions( array $r, string $here ): void {
			if ( 'income' === $r['type'] && \AssociazioneSemplice\Edition::has( 'receipts' ) ) {
				$rkey = \AssociazioneSemplice\Receipts::key_of( $r );
				echo '<a class="button button-small" target="_blank" href="' . esc_url( \AssociazioneSemplice\Receipts::url( $rkey ) ) . '">Ricevuta PDF</a> ';
				Ui::form_open( 'asem_receipt_email', $here, false, 'asem-inline' );
				echo Ui::hidden( 'key', $rkey ) . '<button class="button-link" data-confirm="Mandare la ricevuta per email a chi ha pagato?">invia per email</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
	}

	/** Tipo di ente e termini nella scheda dei dati dell'ente. */
	public static function entity_rows( array $s ): void {
		if ( ! \AssociazioneSemplice\Edition::has( 'texts' ) ) {
			return;
		}
		$ents = array_keys( \AssociazioneSemplice\Terms::entity_types( (string) $s['entity_types_custom'] ) );
		$mems = array_keys( \AssociazioneSemplice\Terms::member_terms( (string) $s['member_terms_custom'] ) );
			echo '<tr><th>Tipo di ente</th><td><select name="entity_type">' . Ui::options( array_combine( $ents, $ents ), (string) $s['entity_type'] ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<select name="member_term">' . Ui::options( array_combine( $mems, $mems ), (string) $s['member_term'] ) . '</select><p class="description">Tipo di ente e termine per chi partecipa: tutti i testi si adattano. I tipi e i termini aggiuntivi si scrivono in Testi e lingua.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Partita IVA nella scheda dei dati dell'ente. */
	public static function entity_sections( array $s ): void {
		if ( ! \AssociazioneSemplice\Edition::has( 'vat' ) ) {
			return;
		}
		echo '<h2>Partita IVA</h2><table class="form-table"><tbody>';
		echo '<tr><th>L\'ente ha la partita IVA</th><td><label><input type="checkbox" name="has_vat" value="1" id="asem-has-vat"' . checked( ! empty( $s['has_vat'] ), true, false ) . '> Sì</label>'
			. '<p class="description">Senza partita IVA non compare nulla di fiscale: niente aliquote né importi IVA. Con la partita IVA, quote, attività, incassi e spese indicano se l\'importo è IVA compresa o esclusa e l\'aliquota. La gestione è volutamente essenziale: i quadri e le liquidazioni li completa il commercialista.</p></td></tr>';
		echo '</tbody></table><div id="asem-vat-block"><table class="form-table"><tbody>';
		echo '<tr><th>Partita IVA</th><td><input type="text" name="vat_number" value="' . esc_attr( (string) $s['vat_number'] ) . '" class="regular-text" maxlength="13" placeholder="11 cifre"></td></tr>';
		echo '<tr><th>Regime</th><td><select name="fiscal_regime">' . Ui::options( \AssociazioneSemplice\Fiscal::regimes(), (string) $s['fiscal_regime'] ) . '</select><p class="description">Nel regime forfettario l\'IVA non si applica: le voci restano senza aliquota.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$rates = array();
		foreach ( \AssociazioneSemplice\Fiscal::RATES as $r ) {
			$rates[ $r ] = $r . '%';
		}
		echo '<tr><th>Aliquota proposta</th><td><select name="vat_default_rate">' . Ui::options( $rates, (int) $s['vat_default_rate'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$mr = \AssociazioneSemplice\Fiscal::membership_rate();
		echo '<tr><th>IVA sulle quote associative</th><td><select name="vat_membership_rate">' . Ui::options( \AssociazioneSemplice\Fiscal::rate_options(), null === $mr ? 'none' : (string) $mr ) . '</select><p class="description">Di norma le quote dei soci sono fuori campo IVA. Se nel tuo caso sono soggette, scegli l\'aliquota: sulle quote incassate verrà indicata l\'IVA contenuta. Le attività hanno la propria aliquota nella loro scheda.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Gli importi si inseriscono</th><td><select name="vat_prices_mode">' . Ui::options( array( \AssociazioneSemplice\Fiscal::INCLUDED => 'IVA compresa', \AssociazioneSemplice\Fiscal::EXCLUDED => 'IVA esclusa' ), (string) $s['vat_prices_mode'] ) . '</select><p class="description">Vale come scelta iniziale: su ogni quota, attività, incasso e spesa si può indicare diversamente.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice destinatario (SDI)</th><td><input type="text" name="sdi_code" value="' . esc_attr( (string) $s['sdi_code'] ) . '" size="9" maxlength="7"><p class="description">Facoltativo: serve se emetti fatture elettroniche.</p></td></tr>';
		echo '</tbody></table></div>';
	}

	/** Mostra o nasconde i campi della partita IVA. */
	public static function entity_page_end(): void {
		if ( \AssociazioneSemplice\Edition::has( 'vat' ) ) {
			echo '<script>(function(){var c=document.getElementById("asem-has-vat"),b=document.getElementById("asem-vat-block");if(!c||!b)return;function s(){b.style.display=c.checked?"":"none";}c.addEventListener("change",s);s();})();</script>';
		}
	}
}

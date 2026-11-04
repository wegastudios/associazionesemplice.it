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
		add_filter( 'asem_comms_page_title', function () {
			return 'Promemoria, privacy, regolamento e ricevute';
		} );
		add_action( 'asem_comms_sections', array( __CLASS__, 'comms_sections' ) );
		add_filter( 'asem_comms_settings', array( __CLASS__, 'comms_settings' ), 10, 2 );
		add_filter( 'asem_bookdesk_items', array( __CLASS__, 'bookdesk_items' ) );
		add_filter( 'asem_bookdesk_date', array( __CLASS__, 'bookdesk_date' ), 10, 3 );
		add_filter( 'asem_bookdesk_item', array( __CLASS__, 'bookdesk_item' ), 10, 3 );
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
			Ui::inline_js( '(function(){var c=document.getElementById("asem-has-vat"),b=document.getElementById("asem-vat-block");if(!c||!b)return;function s(){b.style.display=c.checked?"":"none";}c.addEventListener("change",s);s();})();' );
		}
	}

	/** Riga in fondo alle ricevute, nella pagina di promemoria, privacy e regolamento. */
	public static function comms_sections( array $s ): void {
		echo '<h2>Ricevute</h2><table class="form-table"><tbody>';
		echo '<h2>Ricevute</h2><table class="form-table"><tbody>';
		echo '<tr><th>Riga in fondo alla ricevuta</th><td><textarea name="receipt_footer" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $s['receipt_footer'] ) . '</textarea>'
			. '<p class="description">Facoltativa: ad esempio il riferimento normativo che il tuo consulente ti indica per le quote associative. Le ricevute le trovi accanto a ogni incasso della Prima nota e nella scheda del socio; i soci le scaricano dalla loro area.</p></td></tr>';
	}

	public static function comms_settings( array $vals, array $p ): array {
		$vals['receipt_footer'] = trim( (string) ( $p['receipt_footer'] ?? '' ) );
		return $vals;
	}

	/** Risorse tra le cose prenotabili dalla pagina «Prenota» della cassa. */
	public static function bookdesk_items( array $groups ): array {
		if ( ! \AssociazioneSemplice\Edition::has( 'resources' ) ) {
			return $groups;
		}
		$opts = array();
		foreach ( \AssociazioneSemplice\Resources::all() as $r ) {
			$opts[ 'r:' . (int) $r['id'] ] = (string) $r['name'];
		}
		if ( $opts ) {
			$groups['Risorse'] = $opts;
		}
		return $groups;
	}

	/** Fasce libere di una risorsa in un giorno: [libere, totali] (null se non è a fasce). */
	private static function free_slots( array $res, string $date ): ?array {
		if ( \AssociazioneSemplice\ResourceRules::SLOT !== $res['mode'] ) {
			return null;
		}
		$free  = 0;
		$total = 0;
		foreach ( \AssociazioneSemplice\Resources::day_grid( $res, $date ) as $s ) {
			$total++;
			if ( empty( $s['reservation'] ) ) {
				$free++;
			}
		}
		return array( $free, $total );
	}

	/** Risorse con fasce libere in una data. */
	public static function bookdesk_date( array $rows, string $date, $person ): array {
		if ( ! \AssociazioneSemplice\Edition::has( 'resources' ) ) {
			return $rows;
		}
		foreach ( \AssociazioneSemplice\Resources::all() as $r ) {
			$f      = self::free_slots( $r, $date );
			$rows[] = array(
				'title' => (string) $r['name'],
				'info'  => null === $f ? 'assegnazione periodica' : $f[0] . ' fasce libere su ' . $f[1],
				'url'   => Ui::url( 'asem-resource', array( 'id' => (int) $r['id'], 'd' => $date ) ),
				'label' => 'Prenota',
			);
		}
		return $rows;
	}

	/** I prossimi giorni con posto libero di una risorsa. */
	public static function bookdesk_item( array $rows, string $item, $person ): array {
		if ( 0 !== strpos( $item, 'r:' ) || ! \AssociazioneSemplice\Edition::has( 'resources' ) ) {
			return $rows;
		}
		$res = \AssociazioneSemplice\Resources::get( (int) substr( $item, 2 ) );
		if ( ! $res ) {
			return $rows;
		}
		$day = current_time( 'Y-m-d' );
		for ( $i = 0; $i < 21 && count( $rows ) < 12; $i++ ) {
			$d = gmdate( 'Y-m-d', strtotime( $day . ' +' . $i . ' day' ) );
			$f = self::free_slots( $res, $d );
			if ( null === $f || $f[0] > 0 ) {
				$rows[] = array(
					'title' => \AssociazioneSemplice\Docs::d( $d ),
					'info'  => null === $f ? 'assegnazione periodica' : $f[0] . ' fasce libere su ' . $f[1],
					'url'   => Ui::url( 'asem-resource', array( 'id' => (int) $res['id'], 'd' => $d ) ),
					'label' => 'Prenota',
				);
				if ( null === $f ) {
					break;
				}
			}
		}
		return $rows;
	}
}

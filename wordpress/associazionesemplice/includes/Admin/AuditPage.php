<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

/** Registro delle azioni: chi ha fatto cosa e quando. */
final class AuditPage {

	public static function render(): void {
		$prefix = Ui::get_str( 'type' );
		$rows   = Audit::recent( 200, $prefix );
		Ui::header( 'Registro azioni' );
		$st = Audit::stats();
		echo wp_kses( '<div class="asem-card"><p><strong>Spazio occupato dal registro:</strong> ' . esc_html( size_format( $st['bytes'], 1 ) ) . ' · ' . esc_html( number_format_i18n( $st['rows'] ) ) . ' righe'
			. ( '' !== $st['oldest'] ? ' · la più vecchia è del ' . esc_html( mysql2date( 'd/m/Y', $st['oldest'] ) ) : '' ) . '.</p>'
			. '<p class="description">Le righe più vecchie di ' . (int) \AssociazioneSemplice\Limits::get( 'audit_keep_months' ) . ' mesi si cancellano da sole ogni giorno (si cambia da Limiti e soglie). Per alleggerire subito il database puoi eliminare tutto ciò che non è dell\'anno in corso.</p>'
			. \AssociazioneSemplice\Admin\Ui::confirm_button( 'asem_audit_free', Ui::url( 'asem-audit' ), array(), 'Libera spazio', 'Eliminare dal registro tutte le azioni degli anni scorsi? Non si può annullare.' )
			. '</div>', Html::allowed() );
		echo wp_kses( '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-audit"><select name="type" data-asem-autosubmit>'
			. Ui::options(
				array(
					'person.' => 'Persone', 'member.' => 'Libro soci', 'membership.' => 'Iscrizioni', 'activity.' => 'Attività', 'booking.' => 'Prenotazioni', 'attendance.' => 'Presenze',
					'tx.' => 'Movimenti', 'account.' => 'Conti', 'fund.' => 'Fondi', 'year.' => 'Anni solari', 'payment.' => 'Pagamenti online', 'cashcount.' => 'Verifiche cassa',
					'minutes.' => 'Verbali', 'insurance.' => 'Assicurazioni dei volontari', 'policy.' => 'Polizze', 'fivepm.' => '5x1000', 'privacy.' => 'Privacy', 'backup.' => 'Copie di sicurezza',
					'import.' => 'Import', 'settings.' => 'Impostazioni',
				),
				$prefix,
				'Tutte le azioni'
			) . '</select></form>', Html::allowed() );
		echo '<table class="widefat striped"><thead><tr><th>Quando</th><th>Chi</th><th>Azione</th><th>Oggetto</th><th>Dettagli</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessuna azione registrata.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$details = $r['details'] ? json_decode( $r['details'], true ) : array();
			$text    = array();
			foreach ( (array) $details as $k => $v ) {
				$text[] = $k . ': ' . ( is_array( $v ) ? implode( ' → ', $v ) : (string) $v );
			}
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ) . '</td><td>' . esc_html( (string) ( $r['display_name'] ?: '—' ) ) . '</td>'
				. '<td><code>' . esc_html( $r['action'] ) . '</code></td><td>' . esc_html( trim( $r['object_type'] . ' ' . $r['object_id'] ) ) . '</td><td>' . esc_html( implode( ' · ', $text ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}
}

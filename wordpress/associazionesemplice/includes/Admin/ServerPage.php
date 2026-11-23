<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ServerStatus;
use AssociazioneSemplice\ServerStatusRules;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/** Strumenti → Stato del server (solo amministratori): come è fatto l'hosting, cosa va bene e cosa conviene chiedere di cambiare. */
final class ServerPage {

	public static function render(): void {
		Ui::header( Texts::t( 'Stato del server' ) );
		echo '<p class="description">' . esc_html( Texts::t( 'Il plugin funziona anche con un hosting economico. Qui trovi come è fatto il tuo e cosa conviene chiedere a chi lo gestisce per avere più velocità e puntualità. Sono solo consigli: nulla qui blocca l\'uso del plugin.' ) ) . '</p>';

		$rows = ServerStatus::rows( true ); // i controlli si fanno solo ora, aprendo la scheda
		$sum  = ServerStatusRules::summary( $rows );
		if ( $sum['bad'] > 0 ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::n( $sum['bad'], 'Un controllo è sotto il minimo o ha una funzione spenta: vedi la riga in rosso.', '{n} controlli sono sotto il minimo o hanno una funzione spenta: vedi le righe in rosso.' ) ) . '</p></div>';
		} elseif ( $sum['warn'] > 0 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::n( $sum['warn'], 'Tutto funziona. Un controllo può migliorare: vedi la riga in giallo.', 'Tutto funziona. {n} controlli possono migliorare: vedi le righe in giallo.' ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html( Texts::t( 'Il server è in regola: tutto come consigliato.' ) ) . '</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( Texts::t( 'Controllo' ) ) . '</th><th>' . esc_html( Texts::t( 'Stato' ) ) . '</th><th>' . esc_html( Texts::t( 'Valore' ) ) . '</th><th>' . esc_html( Texts::t( 'Cosa fare' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><strong>' . esc_html( $r['label'] ) . '</strong></td><td>' . wp_kses( self::badge( $r['status'] ), array( 'span' => array( 'class' => array() ) ) ) . '</td><td>' . esc_html( $r['value'] ) . '</td><td>';
			if ( '' !== $r['advice'] ) {
				echo esc_html( $r['advice'] );
			} else {
				echo '<span class="description">—</span>';
			}
			if ( ! empty( $r['code'] ) ) {
				echo '<br><code>' . esc_html( $r['code'] ) . '</code>';
			}
			if ( ! empty( $r['page'] ) ) {
				echo '<br><a href="' . esc_url( Ui::url( $r['page'] ) . '#' . $r['anchor'] ) . '">' . esc_html( Texts::t( 'Apri la copia di sicurezza e il motore del database' ) ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html( Texts::t( 'I controlli si rifanno ogni volta che apri questa scheda. Alcuni, come le operazioni programmate e le tabelle, compaiono anche nella pagina «Salute del sito» di WordPress.' ) ) . '</p>';
		Ui::footer();
	}

	private static function badge( string $status ): string {
		if ( ServerStatusRules::BAD === $status ) {
			return '<span class="asem-badge asem-badge-danger">' . esc_html( Texts::t( 'Da sistemare' ) ) . '</span>';
		}
		if ( ServerStatusRules::WARN === $status ) {
			return '<span class="asem-badge asem-badge-warn">' . esc_html( Texts::t( 'Migliorabile' ) ) . '</span>';
		}
		return '<span class="asem-badge asem-badge-ok">' . esc_html( Texts::t( 'In regola' ) ) . '</span>';
	}
}

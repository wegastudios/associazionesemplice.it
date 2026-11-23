<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\DbEngine;
use AssociazioneSemplice\DbEngineRules;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Riquadro «Motore del database» nella pagina Copia di sicurezza (solo amministratori): quante tabelle del gestionale sono InnoDB,
 * quali no, e il pulsante facoltativo «Converti in InnoDB» (a tappe).
 */
final class DbEnginePanel {

	public static function render(): void {
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) {
			return;
		}
		$rows = DbEngine::tables();
		$st   = DbEngine::check( $rows ); // aprire questa pagina è raro: si legge lo stato vero e si aggiorna quello ricordato
		$bad  = DbEngineRules::to_convert( $rows );
		$back = Ui::url( 'asem-backup' ) . '#asem-db-engine';

		echo '<h2 id="asem-db-engine">' . esc_html( Texts::t( 'Motore del database' ) ) . '</h2>';
		echo '<p>' . esc_html( Texts::t( 'Il gestionale salva insieme più righe (ad esempio una comunicazione e i suoi destinatari, o la chiusura di un anno fiscale): con il motore InnoDB, se il server si ferma a metà, tutto torna com\'era. Con il vecchio motore MyISAM le righe già scritte restano e i dati possono risultare a metà.' ) ) . '</p>';

		if ( ! $bad ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( Texts::n( (int) $st['total'], 'La tabella del gestionale usa InnoDB.', 'Tutte le {n} tabelle del gestionale usano InnoDB.' ) ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::n( count( $bad ), 'Una tabella su {totale} non usa InnoDB:', '{n} tabelle su {totale} non usano InnoDB:', array( 'totale' => (int) $st['total'] ) ) ) . '</p></div>';
		echo '<ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $bad as $t ) {
			echo '<li><code>' . esc_html( $t['name'] ) . '</code> (' . esc_html( $t['engine'] ) . ', ' . esc_html( size_format( $t['bytes'] ) ) . ')</li>';
		}
		echo '</ul>';

		if ( ! $st['innodb'] ) {
			echo '<p>' . esc_html( Texts::t( 'Il server non offre InnoDB: non si può convertire da qui. Se vuoi le transazioni, chiedilo a chi gestisce l\'hosting.' ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( Texts::t( 'La conversione è facoltativa e non cambia i dati. Lavora a tappe: ogni volta si convertono poche tabelle, dalla più piccola; se restano tabelle, premi di nuovo. Durante la conversione di una tabella il sito può rallentare per qualche secondo.' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( 'Prima di convertire fai una copia di sicurezza.' ) ) . '</strong></p>';
		Ui::form_open( 'asem_db_convert', $back, false, 'asem-inline' );
		echo '<button class="button button-primary" data-confirm="' . esc_attr( Texts::t( 'Convertire le tabelle in InnoDB?' ) ) . '">' . esc_html( Texts::t( 'Converti in InnoDB' ) ) . '</button>';
		Ui::form_close();
	}
}

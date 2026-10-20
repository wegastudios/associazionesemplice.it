<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Tessera digitale: attivazione del QR e rigenerazione dei codici. */
final class CardPage {

	public static function render(): void {
		Ui::header( (string) apply_filters( 'asem_card_page_title', 'Impostazioni: tessera e QR' ) );
		self::switches_card();
		do_action( 'asem_card_page_end' ); // le estensioni aggiungono le loro sezioni
		Ui::footer();
	}

	/** Interruttori generali: tutto spento di default, si accende solo ciò che serve. */
	private static function switches_card(): void {
		echo '<div class="asem-card"><h2>Cosa vuoi usare</h2><p class="description">Tutte le funzioni sono disattivate per impostazione predefinita. Ciò che non serve resta invisibile ai soci e nelle schede degli eventi.</p>';
		Ui::form_open( 'asem_save_card', Ui::url( 'asem-card' ) );
		echo '<p><label><input type="checkbox" name="card_qr_enabled" value="1"' . checked( Settings::card_qr_enabled(), true, false ) . '> <strong>QR sulla tessera digitale</strong></label><br>'
			. '<span class="description">Ogni socio trova nella sua area riservata un QR: chi lo scansiona (anche senza accedere al sito) vede subito se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza e nient\'altro. Il QR non cambia quando la tessera si rinnova. Gli ospiti non hanno tessera.</span></p>';
		echo '<p><label><input type="checkbox" name="ticket_qr_enabled" value="1"' . checked( Settings::tickets_enabled(), true, false ) . '> <strong>Biglietti QR delle prenotazioni</strong></label><br>'
			. '<span class="description">Rende disponibile, nella scheda di ogni evento, l\'opzione "Biglietto QR" (da attivare evento per evento) e la lettura dei QR all\'ingresso.</span></p>';
		do_action( 'asem_card_switches' ); // le estensioni aggiungono i loro interruttori
		submit_button( 'Salva', 'primary', 'submit', false );
		Ui::form_close();
		if ( Settings::card_qr_enabled() || Settings::tickets_enabled() ) {
			Ui::form_open( 'asem_regen_qr', Ui::url( 'asem-card' ), false, 'asem-inline' );
			echo '<p><button class="button" data-confirm="Rigenerare tutti i QR? Quelli già stampati o salvati nei telefoni (tessere e biglietti) smetteranno di funzionare e i soci dovranno prendere il nuovo dalla loro area riservata.">Rigenera tutti i QR</button> '
				. '<span class="description">Da usare solo se un QR è stato diffuso per errore (vale anche per i biglietti degli eventi).</span></p>';
			Ui::form_close();
		}
		echo '</div>';
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Backup;
use AssociazioneSemplice\FileKey;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Copia di sicurezza. */
final class BackupPage {

	public static function render(): void {
		Ui::header( 'Copia di sicurezza' );
		PrivateFolderPanel::alerts();
		FileCryptPanel::alerts();
		$last = Backup::last();
		echo '<p>Scarica un file con <strong>tutti i dati</strong> del plugin (soci, attività, prima nota, fondi, ricevute, comunicazioni…) e le impostazioni. Conservalo in un posto sicuro, fuori dal sito. '
			. 'Le chiavi segrete dei pagamenti online non sono incluse.</p>';
		echo '<p>' . ( $last ? 'Ultima copia scaricata: <strong>' . esc_html( wp_date( 'd/m/Y H:i', $last ) ) . '</strong>' . ( time() - $last > 30 * DAY_IN_SECONDS ? ' <span class="asem-warn">(più di 30 giorni fa)</span>' : '' ) : '<span class="asem-warn">Nessuna copia scaricata finora.</span>' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Backup::download_url( false ) ) . '">Scarica la copia dei dati</a> '
			. '<a class="button" href="' . esc_url( Backup::download_url( true ) ) . '">Scarica la copia con gli allegati (scontrini e fatture)</a></p>';
		if ( FileKey::has_keys() ) {
			echo '<p class="description">' . esc_html( FileCryptPanel::copy_note() ) . '</p>';
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			echo '<div class="notice notice-error"><p>Questo server non ha l\'estensione zip di PHP: la copia non si può creare.</p></div>';
		}

		$plain = (int) get_option( Backup::OPT_PLAIN, 0 );
		if ( $plain > 0 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'Nell\'ultima copia {n} campi riservati (ad esempio il medico nei certificati) sono nelle tabelle in chiaro, perché su questo sito non si possono ancora cifrare: controlla la sezione «Documenti riservati» qui sotto. Nessun dato è andato perso: il manifest della copia ne dichiara il numero.', array( 'n' => $plain ) ) ) . '</p></div>';
		}

		echo '<h2>Ripristino</h2><p class="description">Ripristina una copia scaricata da questo sito: <strong>i dati attuali del plugin vengono sostituiti</strong> da quelli della copia. '
			. 'Prima del ripristino il sito salva da solo una copia dello stato attuale (qui sotto), e se qualcosa non va non viene cambiato nulla. '
			. esc_html( Texts::t( 'Una copia modificata dopo essere stata fatta viene rifiutata. Il registro delle azioni, gli utenti WordPress, le password dei soci e le notifiche non fanno parte del ripristino: i soci rientrano con il link che ricevono.' ) ) . '</p>'
			. '<p class="description">' . esc_html( Texts::t( 'Una copia fatta su un altro sito (o senza firma) si ripristina solo con una conferma in più, e il ripristino resta scritto nel registro delle azioni.' ) ) . '</p>';
		Ui::form_open( 'asem_backup_restore', Ui::url( 'asem-backup' ), true );
		echo '<p><input type="file" name="backup_file" accept=".zip" required></p>'
			. '<p><label>' . esc_html( Texts::t( 'Frase della copia (serve solo se la copia viene da un altro sito)' ) ) . '<br><input type="password" name="phrase" class="regular-text" autocomplete="off"></label><br><span class="description">' . esc_html( Texts::t( 'Scrivi la frase: se non l\'hai cambiata, è il codice di licenza (o, nel plugin gratuito, la data e l\'ora in cui era stata creata la chiave dei file, in UTC, non la data di installazione del plugin) che avevi quando hai fatto la copia. Va scritta esattamente com\'era, spazi e trattini compresi.' ) ) . ' ' . esc_html( Texts::t( 'Se la lasci vuota i dati si ripristinano lo stesso, e i documenti riservati che questo sito non sa aprire restano chiusi finché non riapri la chiave con la frase.' ) ) . '</span></p>'
			. '<p><label><input type="checkbox" name="confirm" value="1" required> Ho capito: i dati attuali verranno sostituiti da quelli della copia.</label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Per confermare scrivi {frase} (se la copia è stata fatta su un altro sito scrivi {frase_altro})', array( 'frase' => Backup::RESTORE_PHRASE, 'frase_altro' => Backup::RESTORE_PHRASE_OTHER ) ) ) . '<br><input type="text" name="confirm_text" class="regular-text" autocomplete="off" required></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'La tua password di amministratore' ) ) . '<br><input type="password" name="password" class="regular-text" autocomplete="current-password" required></label></p>'
			. '<p><button class="button" data-confirm="Sostituire i dati attuali con quelli della copia?">Ripristina dalla copia</button></p>';
		Ui::form_close();

		$saved = Backup::saved();
		if ( $saved ) {
			echo '<h2>Copie di sicurezza fatte prima dei ripristini</h2><ul>';
			foreach ( $saved as $s ) {
				echo '<li><a href="' . esc_url( Backup::saved_url( $s['name'] ) ) . '">' . esc_html( $s['name'] ) . '</a> <span class="description">' . esc_html( wp_date( 'd/m/Y H:i', $s['time'] ) ) . ' · ' . esc_html( size_format( $s['size'] ) ) . '</span></li>';
			}
			echo '</ul><p class="description">Si conservano al massimo ' . (int) \AssociazioneSemplice\Limits::get( 'backup_keep' ) . ' copie e solo per ' . (int) \AssociazioneSemplice\Limits::get( 'backup_hours' ) . ' ore, poi vengono cancellate da sole. Per tornare indietro scarica il file e ripristinalo da qui.</p>';
		}
		FileCryptPanel::render();
		PrivateFolderPanel::render();
		do_action( 'asem_backup_page_after' ); // un'estensione aggiunge i suoi riquadri (ad esempio la verifica dei PDF definitivi)
		Ui::footer();
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Html;
use AssociazioneSemplice\PrivateFolder;
use AssociazioneSemplice\PrivateFolderRules;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Riquadro «Cartella dei file riservati» nella pagina Copia di sicurezza (solo amministratori): dove sta la cartella, esito dell'ultimo controllo
 * dall'esterno, pulsante «Controlla ora», cosa chiedere a chi gestisce il server e pulsante «Sposta i file» dopo aver indicato un'altra cartella.
 */
final class PrivateFolderPanel {

	/** Avvisi chiudibili (percorso non utilizzabile, cartella raggiungibile): in cima alle pagine che li mostrano. */
	public static function alerts(): void {
		echo wp_kses( PrivateFolder::alerts_html(), Html::allowed() );
	}

	public static function render(): void {
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) {
			return;
		}
		$st   = PrivateFolder::status();
		$last = PrivateFolder::last();
		$back = Ui::url( 'asem-backup' );

		echo '<h2 id="asem-private-folder">' . esc_html( Texts::t( 'Cartella dei file riservati' ) ) . '</h2>';
		echo '<p>' . esc_html( Texts::t( 'Ricevute, allegati dei movimenti, documenti e scansioni, giustificativi dei rimborsi, foto dell\'inventario, certificati medici e copie di sicurezza stanno in una cartella a parte. I file hanno nomi casuali e il sito li apre solo dopo aver controllato i permessi.' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( 'Se queste parole non ti dicono nulla, premi «Controlla ora»: se il risultato è rosso, inoltra il messaggio qui sotto a chi gestisce il sito.' ) ) . '</strong></p>';
		echo '<p>' . esc_html( Texts::t( 'Cartella in uso:' ) ) . ' <code>' . esc_html( $st['dir'] ) . '</code></p>';

		if ( '' !== $st['error'] ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html( Texts::t( 'Il percorso indicato in wp-config.php non è utilizzabile.' ) ) . '</strong> <code>' . esc_html( $st['configured'] ) . '</code> ' . esc_html( PrivateFolder::error_text( $st['error'] ) ) . ' ' . esc_html( Texts::t( 'Finché non viene sistemato, i nuovi file vengono salvati nella cartella predefinita dentro wp-content/uploads.' ) ) . '</p></div>';
		} elseif ( $st['custom'] && ! $st['active'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'Il percorso è indicato in wp-config.php (ASEM_PRIVATE_DIR) ma la nuova cartella non è ancora in uso: ci passa il sito quando tutti i file della cartella predefinita sono stati copiati con «Sposta i file». Fino ad allora i file restano dove sono e il sito li legge da lì.' ) ) . '</p></div>';
		} elseif ( $st['custom'] && ! $st['in_web'] ) {
			echo '<p>' . esc_html( Texts::t( 'Il percorso è indicato in wp-config.php (ASEM_PRIVATE_DIR): la cartella sta fuori dalla parte pubblica del sito e non ha un indirizzo web.' ) ) . '</p>';
		} elseif ( $st['custom'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'Il percorso è indicato in wp-config.php (ASEM_PRIVATE_DIR), ma la cartella si trova ancora dentro la parte pubblica del sito: conviene sceglierne una fuori da essa.' ) ) . '</p></div>';
		} else {
			echo '<p>' . esc_html( Texts::t( 'È la cartella predefinita, dentro la parte pubblica del sito (wp-content/uploads). Sui server Apache è chiusa da un file .htaccess; altri server, come nginx, lo ignorano e vanno configurati a parte.' ) ) . '</p>';
		}

		if ( null === $last ) {
			echo '<p>' . esc_html( Texts::t( 'Nessun controllo eseguito finora.' ) ) . '</p>';
		} else {
			$cls = PrivateFolderRules::EXPOSED === $last['state'] ? 'notice-error' : ( PrivateFolderRules::UNKNOWN === $last['state'] ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $cls ) . ' inline"><p>' . esc_html( Texts::t( 'Ultimo controllo: {data}. Esito:', array( 'data' => wp_date( 'd/m/Y H:i', $last['at'] ) ) ) ) . ' ' . esc_html( PrivateFolder::outcome_text( $last ) ) . '</p></div>';
		}
		Ui::form_open( 'asem_private_check', $back, false, 'asem-inline' );
		echo '<button class="button">' . esc_html( Texts::t( 'Controlla ora' ) ) . '</button> <span class="description">' . esc_html( Texts::t( 'Il controllo crea un piccolo file di prova, senza dati, prova a scaricarlo dall\'indirizzo web del sito e lo cancella subito. Si ripete da solo circa una volta a settimana.' ) ) . '</span>';
		Ui::form_close();

		self::what_to_do( $st, null !== $last && PrivateFolderRules::EXPOSED === $last['state'] );
		self::mover( $st, $back );
	}

	/** Cosa chiedere a chi gestisce il server o l'hosting. */
	private static function what_to_do( array $st, bool $open ): void {
		$web   = PrivateFolder::web_url( $st['custom'] ? $st['dir'] : $st['default'] );
		$path  = '' !== $web ? (string) wp_parse_url( $web, PHP_URL_PATH ) : (string) wp_parse_url( PrivateFolder::web_url( $st['default'] ), PHP_URL_PATH );
		$path  = '' !== $path ? trailingslashit( $path ) : '/wp-content/uploads/' . \AssociazioneSemplice\Attachments::DIR_NAME . '/';
		$outer = dirname( untrailingslashit( str_replace( '\\', '/', ABSPATH ) ) ) . '/asem-riservata';

		echo '<details' . ( $open ? ' open' : '' ) . ' style="margin:1em 0"><summary><strong>' . esc_html( Texts::t( 'Cosa fare se la cartella è raggiungibile (o per metterla al sicuro)' ) ) . '</strong></summary>';
		echo '<p><strong>' . esc_html( Texts::t( 'Messaggio pronto da copiare e inoltrare a chi gestisce il sito o l\'hosting:' ) ) . '</strong></p>';
		echo '<p><textarea id="asem-private-msg" readonly rows="4" style="width:100%;max-width:640px">' . esc_textarea( Texts::t( 'Buongiorno, sul nostro sito la cartella {percorso} contiene documenti riservati e risulta raggiungibile da internet. Vi chiediamo di bloccare l\'accesso diretto a quella cartella oppure di aiutarci a spostarla fuori dalla parte pubblica del sito. Grazie.', array( 'percorso' => $st['dir'] ) ) ) . '</textarea><br>'
			. '<button type="button" class="button" id="asem-private-copy" data-done="' . esc_attr( Texts::t( 'Messaggio copiato' ) ) . '">' . esc_html( Texts::t( 'Copia il messaggio' ) ) . '</button></p>';
		Ui::inline_js( 'document.getElementById("asem-private-copy").addEventListener("click",function(){var t=document.getElementById("asem-private-msg");t.focus();t.select();try{if(navigator.clipboard){navigator.clipboard.writeText(t.value);}else{document.execCommand("copy");}this.textContent=this.getAttribute("data-done");}catch(e){}});' );
		echo '<p><strong>' . esc_html( Texts::t( 'Istruzioni tecniche, per chi gestisce il server:' ) ) . '</strong> ' . esc_html( Texts::t( 'Ci sono due strade, anche insieme.' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( '1. Bloccare l\'accesso diretto alla cartella.' ) ) . '</strong> ' . esc_html( Texts::t( 'Con nginx serve una regola come questa nella configurazione del sito:' ) ) . '</p>';
		echo '<pre style="background:#f6f7f7;padding:8px;overflow:auto">' . esc_html( 'location ^~ ' . $path . " {\n\tdeny all;\n\treturn 403;\n}" ) . '</pre>';
		echo '<p class="description">' . esc_html( Texts::t( 'Con Apache basta che il file .htaccess della cartella sia attivo (serve che sulla cartella uploads sia consentito AllowOverride). Dopo la modifica premi «Controlla ora».' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( '2. Spostare la cartella fuori dalla parte pubblica del sito.' ) ) . '</strong> ' . esc_html( Texts::t( 'Nel file wp-config.php, sopra la riga «That\'s all, stop editing!», si aggiunge una riga con un percorso assoluto fuori dalla cartella pubblica del sito, per esempio:' ) ) . '</p>';
		echo '<pre style="background:#f6f7f7;padding:8px;overflow:auto">' . esc_html( "define( 'ASEM_PRIVATE_DIR', '" . $outer . "' );" ) . '</pre>';
		echo '<p class="description">' . esc_html( Texts::t( 'La cartella deve esistere (o poter essere creata) e il server deve poterci scrivere. I file già salvati non si spostano da soli: dopo aver aggiunto la riga, il pulsante «Sposta i file» li porta nella nuova cartella controllando che ogni copia sia identica all\'originale.' ) ) . '</p>';
		echo '</details>';
	}

	/** Pulsante «Sposta i file»: solo se una cartella di wp-config.php è in uso e nella predefinita restano dei file. */
	private static function mover( array $st, string $back ): void {
		if ( ! $st['custom'] ) {
			return;
		}
		$left = count( PrivateFolder::pending( 5000 ) );
		echo '<h3>' . esc_html( Texts::t( 'Sposta i file nella nuova cartella' ) ) . '</h3>';
		if ( 0 === $left ) {
			echo '<p>' . esc_html( Texts::t( 'Nella cartella predefinita non resta nessun file da spostare.' ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( Texts::t( 'Nella cartella predefinita ci sono ancora {n} file da portare nella nuova cartella. Si procede a lotti di {lotto} file: prima ogni file viene copiato e controllato con la sua impronta, senza toccare l\'originale; quando sono stati copiati tutti, la nuova cartella entra in uso e i file vecchi vengono cancellati uno alla volta, solo se la copia è identica. Se ne restano, premi di nuovo.', array( 'n' => $left >= 5000 ? '5000+' : $left, 'lotto' => PrivateFolder::BATCH ) ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( 'Prima di spostare i file fai una copia di sicurezza.' ) ) . '</strong> <a href="' . esc_url( \AssociazioneSemplice\Backup::download_link( true ) ) . '">' . esc_html( Texts::t( 'Scarica la copia con gli allegati' ) ) . '</a></p>';
		Ui::form_open( 'asem_private_move', $back, false, 'asem-inline' );
		echo '<button class="button button-primary" data-confirm="' . esc_attr( Texts::t( 'Spostare i file nella nuova cartella?' ) ) . '">' . esc_html( Texts::t( 'Sposta i file' ) ) . '</button>';
		Ui::form_close();
	}
}

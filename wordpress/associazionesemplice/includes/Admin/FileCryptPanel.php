<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Backup;
use AssociazioneSemplice\FileCrypt;
use AssociazioneSemplice\FileKey;
use AssociazioneSemplice\FileMigration;
use AssociazioneSemplice\Html;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Riquadro «Cifratura dei file riservati» nella pagina Copia di sicurezza (solo amministratori): se la chiave è valida, come è protetta, il pulsante
 * per cifrare i file già caricati con il suo avanzamento, e cosa fare se la chiave non si apre più. Avvisi chiudibili per libreria mancante e codici
 * di sicurezza deboli.
 */
final class FileCryptPanel {

	/** Avvisi chiudibili, in cima alle pagine Copia di sicurezza e Certificati. */
	public static function alerts(): void {
		echo wp_kses( self::alerts_html(), Html::allowed() );
	}

	public static function alerts_html(): string {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return '';
		}
		$url = Ui::url( 'asem-backup' ) . '#asem-cifratura';
		$out = '';
		$st  = FileKey::state();
		if ( 'nosodium' === $st ) {
			$out .= Dismiss::html(
				'file-crypt-nosodium',
				'error',
				'<strong>' . esc_html( Texts::t( 'Su questo server manca la libreria di cifratura (sodium).' ) ) . '</strong> '
					. esc_html( Texts::t( 'I file riservati si salvano in chiaro sul disco e i nuovi certificati medici non si possono caricare. Va chiesto a chi gestisce il server o l\'hosting di attivare l\'estensione sodium di PHP.' ) ),
				7
			);
			return $out;
		}
		if ( 'mismatch' === $st ) {
			$out .= Dismiss::html(
				'file-crypt-mismatch',
				'error',
				'<strong>' . esc_html( Texts::t( 'La chiave di cifratura dei file riservati non si apre su questo sito.' ) ) . '</strong> '
					. esc_html( Texts::t( 'I file cifrati non sono leggibili e i nuovi non si salvano finché non viene sistemata.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi cosa fare' ) ) . '</a>.',
				1
			);
		}
		if ( FileKey::constant_weak() ) {
			$out .= Dismiss::html(
				'file-crypt-const-weak',
				'warning',
				'<strong>' . esc_html( Texts::t( 'La costante ASEM_ENC_KEY in wp-config.php è troppo corta e non viene usata.' ) ) . '</strong> '
					. esc_html( Texts::t( 'Serve un testo di almeno 16 caratteri, meglio lungo e casuale.' ) ),
				7
			);
		} elseif ( FileKey::salts_weak() ) {
			$out .= Dismiss::html(
				'file-crypt-salts-weak',
				'warning',
				'<strong>' . esc_html( Texts::t( 'I codici di sicurezza di wp-config.php (AUTH_KEY e AUTH_SALT) mancano o sono quelli di esempio.' ) ) . '</strong> '
					. esc_html( Texts::t( 'La chiave dei file riservati si ricava da lì, quindi è più debole: la protezione è parziale. Conviene aggiungere in wp-config.php la costante ASEM_ENC_KEY, con un testo lungo e casuale.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi come' ) ) . '</a>.',
				14
			);
		}
		return $out;
	}

	/** Una riga con lo stato della chiave, per altre pagine (Certificati). */
	public static function status_line(): string {
		if ( ! FileCrypt::available() ) {
			return '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Cifratura dei file: non attiva, manca la libreria sodium su questo server.' ) ) . '</p></div>';
		}
		$c = FileKey::check();
		if ( 'none' === $c['state'] ) {
			return '';
		}
		if ( $c['valid'] ) {
			return '<p class="description">' . esc_html( Texts::t( 'Chiave di cifratura: valida, i file si aprono.' ) ) . '</p>';
		}
		return '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Chiave di cifratura: non valida su questo sito, i file cifrati non si aprono.' ) )
			. ' <a href="' . esc_url( Ui::url( 'asem-backup' ) . '#asem-cifratura' ) . '">' . esc_html( Texts::t( 'Vedi cosa fare' ) ) . '</a></p></div>';
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$back = Ui::url( 'asem-backup' ) . '#asem-cifratura';
		echo '<h2 id="asem-cifratura">' . esc_html( Texts::t( 'Cifratura dei file riservati' ) ) . '</h2>';
		echo '<p>' . esc_html( Texts::t( 'Certificati medici, documenti e scansioni, allegati dei movimenti, giustificativi dei rimborsi e foto dell\'inventario si salvano cifrati sul disco: chi si portasse via la cartella senza la chiave non potrebbe leggerli. La chiave la gestisce il plugin da solo.' ) ) . '</p>';

		if ( ! FileCrypt::available() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Questo server non ha la libreria di cifratura (sodium): la funzione non è attiva. I file si salvano in chiaro (con questo avviso) e i nuovi certificati medici non si possono caricare. Va chiesto a chi gestisce il server o l\'hosting di attivare l\'estensione sodium di PHP.' ) ) . '</p></div>';
			return;
		}

		$c = FileKey::check();
		if ( 'none' === $c['state'] ) {
			echo '<p>' . esc_html( Texts::t( 'La chiave non è ancora stata creata: si crea da sola quando viene salvato il primo file riservato.' ) ) . '</p>';
		} elseif ( $c['valid'] ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html( Texts::t( 'Chiave valida.' ) ) . '</strong> ' . esc_html( Texts::t( 'La chiave di cifratura è presente, coincide con quella usata per salvare i file e il blocco di prova si apre: i file cifrati si leggono.' ) ) . '</p></div>';
		} elseif ( 'ok' === $c['state'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'La chiave attuale è valida, ma {n} chiavi più vecchie su {t} non si aprono: i file cifrati con quelle non sono leggibili.', array( 'n' => $c['locked'], 't' => $c['total'] ) ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html( Texts::t( 'Chiave non valida su questo sito.' ) ) . '</strong> ' . esc_html( Texts::t( 'La chiave salvata non si apre con quella di questo sito (codici di sicurezza di wp-config.php o costante ASEM_ENC_KEY cambiati, oppure database copiato da un altro sito). I file cifrati non sono leggibili e i nuovi non si salvano finché non viene sistemata.' ) ) . '</p></div>';
		}

		self::protection();
		self::migration( $c, $back );
		if ( 'ok' !== $c['state'] && 'none' !== $c['state'] ) {
			self::recovery( $back );
		}
	}

	private static function protection(): void {
		if ( 'const' === FileKey::source() ) {
			echo '<p>' . esc_html( Texts::t( 'La chiave principale viene dalla costante ASEM_ENC_KEY di wp-config.php, che sta fuori dal database: protezione completa. Va conservata con cura: senza di essa i file non si aprono più.' ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( Texts::t( 'La chiave principale è ricavata dai codici di sicurezza di wp-config.php. È la protezione parziale: chi avesse sia una copia del database sia il file wp-config.php potrebbe aprire i file.' ) ) . '</p>';
		if ( FileKey::salts_weak() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'I codici di sicurezza (AUTH_KEY e AUTH_SALT) mancano o sono quelli di esempio: la chiave è debole.' ) ) . '</p></div>';
		}
		echo '<details style="margin:1em 0"><summary><strong>' . esc_html( Texts::t( 'Come rendere la protezione completa' ) ) . '</strong></summary>';
		echo '<p>' . esc_html( Texts::t( 'Nel file wp-config.php, sopra la riga «That\'s all, stop editing!», si aggiunge una riga con un testo lungo e casuale (almeno 16 caratteri), per esempio:' ) ) . '</p>';
		echo '<pre style="background:#f6f7f7;padding:8px;overflow:auto">' . esc_html( "define( 'ASEM_ENC_KEY', '" . bin2hex( random_bytes( 24 ) ) . "' );" ) . '</pre>';
		echo '<p class="description">' . esc_html( Texts::t( 'Il testo va conservato in un posto sicuro, fuori dal sito: senza di esso i file cifrati non si aprono più. I file già cifrati non vanno cifrati di nuovo: il plugin riconosce la costante da solo e avvolge di nuovo la chiave.' ) ) . '</p>';
		echo '</details>';
	}

	private static function migration( array $c, string $back ): void {
		if ( 'ok' !== $c['state'] ) {
			return;
		}
		$n     = FileMigration::counts();
		$state = FileMigration::state();
		echo '<h3>' . esc_html( Texts::t( 'File già caricati' ) ) . '</h3>';
		if ( 0 === $n['pending'] && 0 === $n['fields'] ) {
			echo '<p>' . esc_html( Texts::t( 'Tutti i file riservati sono cifrati ({n} file).', array( 'n' => $n['encrypted'] ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( Texts::t( 'Ci sono ancora {n} file e {m} campi (nomi dei medici e dei file dei certificati) da cifrare.', array( 'n' => $n['pending'], 'm' => $n['fields'] ) ) ) . '</p>';
			if ( $state['running'] ) {
				echo '<div class="notice notice-info inline"><p><strong>' . esc_html( Texts::t( 'Cifratura in corso.' ) ) . '</strong> '
					. esc_html( Texts::t( 'Cifrati finora {n} file. Il lavoro prosegue da solo, un lotto al minuto: si può chiudere la pagina.', array( 'n' => $state['done'] + $state['already'] ) ) ) . '</p></div>';
			} else {
				echo '<p><strong>' . esc_html( Texts::t( 'Prima di cominciare fai una copia di sicurezza.' ) ) . '</strong> <a href="' . esc_url( Backup::download_link( true ) ) . '">' . esc_html( Texts::t( 'Scarica la copia con gli allegati' ) ) . '</a></p>';
			}
			echo '<p class="description">' . esc_html( Texts::t( 'Si procede a lotti di {lotto} file, prima i certificati medici e poi gli altri. Ogni file viene cifrato in un file temporaneo, riletto e controllato con la sua impronta SHA-256, e solo dopo prende il posto dell\'originale. Se il lavoro si interrompe non si perde nulla: si riparte da dove era arrivato.', array( 'lotto' => FileMigration::BATCH ) ) ) . '</p>';
			if ( $state['running'] ) {
				Ui::form_open( 'asem_enc_step', $back, false, 'asem-inline' );
				echo '<button class="button">' . esc_html( Texts::t( 'Continua adesso' ) ) . '</button>';
				Ui::form_close();
			} else {
				Ui::form_open( 'asem_enc_start', $back, false, 'asem-inline' );
				echo '<button class="button button-primary" data-confirm="' . esc_attr( Texts::t( 'Cifrare i file già caricati? Si consiglia di aver fatto prima una copia di sicurezza.' ) ) . '">' . esc_html( Texts::t( 'Cifra i file già caricati' ) ) . '</button>';
				Ui::form_close();
			}
		}
		if ( $state['started'] > 0 && ( $state['failed'] || $state['missing'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'Ultima cifratura: {f} file non si sono potuti cifrare (sono rimasti dove stavano, in chiaro) e {m} non sono stati trovati sul disco. Un file non si cifra se il suo contenuto non coincide più con l\'impronta registrata.', array( 'f' => $state['failed'], 'm' => $state['missing'] ) ) ) . '</p></div>';
		}
	}

	/** Cosa fare quando la chiave non si apre più. */
	private static function recovery( string $back ): void {
		echo '<h3>' . esc_html( Texts::t( 'Sistemare la chiave' ) ) . '</h3>';
		echo '<p>' . esc_html( Texts::t( 'Se i codici di sicurezza di wp-config.php o la costante ASEM_ENC_KEY sono stati cambiati, la strada più semplice è rimettere quelli di prima. Se non è possibile, qui sotto si incollano i vecchi valori: la chiave viene avvolta di nuovo con quelli di adesso, senza cifrare di nuovo nessun file.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_recover', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Vecchio valore di ASEM_ENC_KEY (se c\'era)' ) ) . '<br><input type="password" name="old_enc_const" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_KEY (se non c\'era la costante)' ) ) . '<br><input type="password" name="old_auth_key" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_SALT' ) ) . '<br><input type="password" name="old_auth_salt" class="regular-text" autocomplete="off"></label></p>'
			. '<p><button class="button">' . esc_html( Texts::t( 'Riapri la chiave con i vecchi valori' ) ) . '</button></p>';
		Ui::form_close();
		echo '<p>' . esc_html( Texts::t( 'Se i vecchi valori sono andati persi, i file cifrati si possono ritrovare ripristinando una copia di sicurezza con la sua frase segreta (sezione «Ripristino»). In alternativa si può ricominciare con una chiave nuova: da quel momento i nuovi file si salvano, ma quelli già cifrati restano illeggibili finché la chiave di prima non torna disponibile.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_newkey', $back, false, 'asem-inline' );
		echo '<button class="button" data-confirm="' . esc_attr( Texts::t( 'Ricominciare con una chiave nuova? I file già cifrati restano illeggibili finché la chiave di prima non torna disponibile.' ) ) . '">' . esc_html( Texts::t( 'Ricomincia con una chiave nuova' ) ) . '</button>';
		Ui::form_close();
	}
}

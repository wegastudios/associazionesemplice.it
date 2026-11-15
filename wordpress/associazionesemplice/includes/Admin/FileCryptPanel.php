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
 * Riquadro «Cifratura dei file riservati» nella pagina Copia di sicurezza (solo amministratori): la frase di recupero, se la chiave è valida, come è
 * protetta, il pulsante per cifrare i file già caricati con il suo avanzamento, l'elenco dei file rimasti in chiaro e cosa fare se la chiave non si apre
 * più. Avvisi chiudibili per libreria mancante, codici di sicurezza deboli e frase di recupero mancante.
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
					. esc_html( Texts::t( 'I file riservati si salvano in chiaro sul disco e i nuovi certificati medici non si possono caricare. Va chiesto a chi gestisce il server o l\'hosting di attivare l\'estensione sodium di PHP.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi il messaggio da inoltrare' ) ) . '</a>.',
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
		} elseif ( 'setup' === FileKey::mode() ) {
			$out .= Dismiss::html(
				'file-crypt-recovery',
				'warning',
				'<strong>' . esc_html( Texts::t( 'Manca la frase di recupero dei file riservati.' ) ) . '</strong> '
					. esc_html( Texts::t( 'Finché non la scegli, i nuovi certificati, documenti e allegati non si possono salvare.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Scegli la frase di recupero' ) ) . '</a>.',
				1
			);
		}
		if ( FileKey::constant_weak() ) {
			$out .= Dismiss::html(
				'file-crypt-const-weak',
				'warning',
				'<strong>' . esc_html( Texts::t( 'La costante ASEM_ENC_KEY in wp-config.php è troppo corta e non viene usata.' ) ) . '</strong> '
					. esc_html( Texts::t( 'Serve un testo di almeno 16 caratteri, meglio lungo e casuale.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi il messaggio da inoltrare' ) ) . '</a>.',
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

	/** Riga in cima ai riquadri tecnici: per chi non conosce le parole. */
	private static function forward_line(): void {
		echo '<p><strong>' . esc_html( Texts::t( 'Se queste parole non ti dicono nulla, inoltra il messaggio qui sotto a chi gestisce il sito.' ) ) . '</strong></p>';
	}

	/** Messaggio pronto da copiare e inoltrare, con il pulsante «Copia il messaggio» (come per la cartella riservata). */
	private static function mail_box( string $id, string $msg ): void {
		echo '<p><textarea id="asem-' . esc_attr( $id ) . '-msg" readonly rows="3" style="width:100%;max-width:640px">' . esc_textarea( $msg ) . '</textarea><br>'
			. '<button type="button" class="button" id="asem-' . esc_attr( $id ) . '-copy" data-done="' . esc_attr( Texts::t( 'Messaggio copiato' ) ) . '">' . esc_html( Texts::t( 'Copia il messaggio' ) ) . '</button></p>';
		Ui::inline_js( 'document.getElementById("asem-' . esc_js( $id ) . '-copy").addEventListener("click",function(){var t=document.getElementById("asem-' . esc_js( $id ) . '-msg");t.focus();t.select();try{if(navigator.clipboard){navigator.clipboard.writeText(t.value);}else{document.execCommand("copy");}this.textContent=this.getAttribute("data-done");}catch(e){}});' );
	}

	/** Cosa dice il metodo con cui si protegge una frase su questo server, se non è il più robusto. */
	public static function kdf_note(): string {
		$level = FileCrypt::kdf_level();
		if ( 'light' === $level ) {
			return Texts::t( 'Questo server ha poca memoria: la frase si protegge con un metodo più leggero, sicuro solo se la frase è lunga: almeno quattro parole.' );
		}
		if ( 'basic' === $level ) {
			return Texts::t( 'Questo server non può usare il metodo più robusto (poca memoria o libreria mancante): la frase si protegge con un metodo alternativo, sicuro solo se la frase è lunga: almeno quattro parole.' );
		}
		return '';
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
			self::forward_line();
			self::mail_box( 'sodium', Texts::t( 'Buongiorno, sul nostro sito serve attivare l\'estensione sodium di PHP (cifratura dei documenti riservati). Grazie.' ) );
			return;
		}

		$c = FileKey::check();
		if ( 'none' === $c['state'] ) {
			echo '<p>' . esc_html( Texts::t( 'La cifratura si attiva scegliendo la frase di recupero, qui sotto.' ) ) . '</p>';
		} elseif ( $c['valid'] ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html( Texts::t( 'Chiave valida.' ) ) . '</strong> ' . esc_html( Texts::t( 'La chiave di cifratura è presente, coincide con quella usata per salvare i file e il blocco di prova si apre: i file cifrati si leggono.' ) ) . '</p></div>';
		} elseif ( 'ok' === $c['state'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'La chiave attuale è valida, ma {n} chiavi più vecchie su {t} non si aprono: i file cifrati con quelle non sono leggibili.', array( 'n' => $c['locked'], 't' => $c['total'] ) ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html( Texts::t( 'Chiave non valida su questo sito.' ) ) . '</strong> ' . esc_html( Texts::t( 'La chiave salvata non si apre con quella di questo sito (codici di sicurezza di wp-config.php o costante ASEM_ENC_KEY cambiati, oppure database copiato da un altro sito). I file cifrati non sono leggibili e i nuovi non si salvano finché non viene sistemata.' ) ) . '</p></div>';
		}

		self::recovery_setup( $c, $back );
		self::protection();
		self::migration( $c, $back );
		if ( 'ok' !== $c['state'] && 'none' !== $c['state'] ) {
			self::recovery( $back );
		}
	}

	/** Scelta della frase di recupero: obbligatoria prima di cifrare, perché con i codici di sicurezza cambiati è l'unico modo di riaprire i documenti. */
	private static function recovery_setup( array $c, string $back ): void {
		if ( 'mismatch' === $c['state'] ) {
			return;
		}
		$has = FileKey::has_recovery();
		echo '<h3>' . esc_html( Texts::t( 'Frase di recupero' ) ) . '</h3>';
		if ( $has ) {
			echo '<p>' . esc_html( Texts::t( 'La frase di recupero è stata scelta. Se i codici di sicurezza del sito cambiano, con quella i documenti si riaprono.' ) ) . '</p>';
			echo '<details style="margin:1em 0"><summary>' . esc_html( Texts::t( 'Cambia la frase di recupero' ) ) . '</summary>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( Texts::t( 'Scegli la frase di recupero: finché non c\'è, i nuovi file riservati non si salvano.' ) ) . '</strong></p></div>';
		}
		echo '<p><strong>' . esc_html( Texts::t( 'Scegli una frase e conservala fuori dal sito (su carta o in un gestore di password): serve a riaprire i documenti se il sito viene spostato o riparato.' ) ) . '</strong></p>';
		echo '<p>' . esc_html( Texts::t( 'La frase di recupero serve a riaprire i documenti se i codici di sicurezza del sito vengono cambiati. Scegli una frase lunga (almeno {n} caratteri), scrivila su carta e tienila fuori dal sito.', array( 'n' => FileKey::RECOVERY_MIN ) ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( 'Cosa non fare: non rigenerare i codici di sicurezza di wp-config.php e non cambiare ASEM_ENC_KEY senza aver prima segnato la frase di recupero.' ) ) . '</strong> '
			. '<strong>' . esc_html( Texts::t( 'Cosa tenere da parte: la frase di recupero. Senza di essa, se quei valori cambiano, i documenti cifrati non si aprono più.' ) ) . '</strong></p>';
		$note = self::kdf_note();
		if ( '' !== $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
		Ui::form_open( 'asem_enc_setrecovery', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Frase di recupero' ) ) . '<br><input type="password" name="rphrase" class="regular-text" autocomplete="new-password" minlength="' . (int) FileKey::RECOVERY_MIN . '" required></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Ripeti la frase di recupero' ) ) . '<br><input type="password" name="rphrase2" class="regular-text" autocomplete="new-password" minlength="' . (int) FileKey::RECOVERY_MIN . '" required></label></p>'
			. '<p><button class="button button-primary">' . esc_html( 'none' === $c['state'] ? Texts::t( 'Attiva la cifratura e scegli la frase di recupero' ) : Texts::t( 'Scegli la frase di recupero' ) ) . '</button></p>';
		Ui::form_close();
		if ( $has ) {
			echo '</details>';
		}
	}

	private static function protection(): void {
		if ( 'const' === FileKey::source() ) {
			echo '<p>' . esc_html( Texts::t( 'La chiave principale viene dalla costante ASEM_ENC_KEY di wp-config.php, che sta fuori dal database: protezione completa. Va conservata con cura: senza di essa i file non si aprono più.' ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( Texts::t( 'La chiave principale è ricavata dai codici di sicurezza di wp-config.php. È la protezione parziale: chi avesse sia una copia del database sia il file wp-config.php potrebbe aprire i file.' ) ) . ' '
			. esc_html( Texts::t( 'Va bene per la maggior parte dei siti; la costante è per chi vuole il massimo.' ) ) . '</p>';
		if ( FileKey::salts_weak() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'I codici di sicurezza (AUTH_KEY e AUTH_SALT) mancano o sono quelli di esempio: la chiave è debole.' ) ) . '</p></div>';
		}
		echo '<details style="margin:1em 0"><summary><strong>' . esc_html( Texts::t( 'Come rendere la protezione completa' ) ) . '</strong></summary>';
		self::forward_line();
		self::mail_box( 'salts', Texts::t( 'Buongiorno, nel file wp-config.php del nostro sito servono codici di sicurezza (AUTH_KEY, AUTH_SALT) casuali e una costante ASEM_ENC_KEY con un testo lungo e casuale. Potete aggiungerli? Grazie.' ) );
		echo '<p><strong>' . esc_html( Texts::t( 'Prima di cambiare i codici di sicurezza, scegli la frase di recupero qui sopra.' ) ) . '</strong></p>';
		echo '<p>' . esc_html( Texts::t( 'Istruzioni per chi gestisce il sito: nel file wp-config.php, sopra la riga «That\'s all, stop editing!», si aggiunge una riga con un testo lungo e casuale (almeno 16 caratteri), per esempio:' ) ) . '</p>';
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
		$can   = 'encrypt' === FileKey::mode();
		echo '<h3>' . esc_html( Texts::t( 'File già caricati' ) ) . '</h3>';
		if ( 0 === $n['pending'] && 0 === $n['fields'] ) {
			echo '<p>' . esc_html( Texts::t( 'Tutti i file riservati sono cifrati ({n} file).', array( 'n' => $n['encrypted'] ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( Texts::t( 'Ci sono ancora {n} file e {m} campi (nomi dei medici e dei file dei certificati) da cifrare.', array( 'n' => $n['pending'], 'm' => $n['fields'] ) ) ) . '</p>';
			if ( ! $can ) {
				echo '<p>' . esc_html( Texts::t( 'Per cifrarli serve prima la frase di recupero.' ) ) . '</p>';
			} else {
				if ( $state['running'] ) {
					echo '<div class="notice notice-info inline"><p><strong>' . esc_html( Texts::t( 'Cifratura in corso.' ) ) . '</strong> '
						. esc_html( Texts::t( 'Cifrati finora {n} file. Il lavoro prosegue da solo, un lotto al minuto: si può chiudere la pagina.', array( 'n' => $state['done'] + $state['already'] ) ) ) . ' '
						. esc_html( Texts::t( 'Finché questa pagina resta aperta il lavoro va avanti più in fretta.' ) ) . '</p></div>';
				} else {
					echo '<p><strong>' . esc_html( Texts::t( 'Prima di cominciare fai una copia di sicurezza.' ) ) . '</strong> <a href="' . esc_url( Backup::download_link( true ) ) . '">' . esc_html( Texts::t( 'Scarica la copia con gli allegati' ) ) . '</a></p>';
				}
				echo '<p class="description">' . esc_html( Texts::t( 'Si procede a lotti di {lotto} file, prima i certificati medici e poi gli altri. Ogni file viene cifrato in un file temporaneo, riletto e controllato con la sua impronta, e solo dopo prende il posto dell\'originale. Se il lavoro si interrompe non si perde nulla: si riparte da dove era arrivato. Nel frattempo il sito resta utilizzabile.', array( 'lotto' => FileMigration::BATCH ) ) ) . '</p>';
				if ( $state['running'] ) {
					echo '<div id="asem-enc-auto">';
					Ui::form_open( 'asem_enc_step', $back, false, 'asem-inline' );
					echo '<button class="button">' . esc_html( Texts::t( 'Continua adesso' ) ) . '</button>';
					Ui::form_close();
					echo '</div>';
					Ui::inline_js( '(function(){var f=document.querySelector("#asem-enc-auto form");if(!f||!window.fetch){return;}setTimeout(function(){fetch(f.action,{method:"POST",body:new FormData(f),credentials:"same-origin"}).then(function(){location.reload();}).catch(function(){});},3000);})();' );
				} else {
					Ui::form_open( 'asem_enc_start', $back, false, 'asem-inline' );
					echo '<button class="button button-primary" data-confirm="' . esc_attr( Texts::t( 'Cifrare i file già caricati? Si consiglia di aver fatto prima una copia di sicurezza.' ) ) . '">' . esc_html( Texts::t( 'Cifra i file già caricati' ) ) . '</button>';
					Ui::form_close();
				}
			}
		}
		if ( ! $state['running'] && ( $n['pending'] > 0 ) && $can ) {
			self::leftovers( $state, $back );
		}
	}

	/** File rimasti in chiaro dopo un tentativo: perché, l'elenco e il pulsante per riprovare. */
	private static function leftovers( array $state, string $back ): void {
		if ( $state['started'] <= 0 || ! ( $state['failed'] || $state['missing'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'Ultima cifratura: {f} file non si sono potuti cifrare (sono rimasti dove stavano, in chiaro) e {m} non sono stati trovati sul disco. Un file non si cifra se il suo contenuto non coincide più con l\'impronta registrata.', array( 'f' => $state['failed'], 'm' => $state['missing'] ) ) ) . ' '
			. '<a href="#asem-enc-elenco">' . esc_html( Texts::t( 'Vedi l\'elenco dei file non cifrati' ) ) . '</a></p></div>';
		echo '<details id="asem-enc-elenco" open style="margin:1em 0"><summary><strong>' . esc_html( Texts::t( 'File non cifrati' ) ) . '</strong></summary>';
		echo '<p class="description">' . esc_html( Texts::t( 'Un file non trovato si può rimettere ripristinando una copia di sicurezza. Un file che non coincide più con la sua impronta è stato modificato: va controllato e, se serve, caricato di nuovo. «Riprova» ripete il lavoro solo per i file ancora in chiaro.' ) ) . '</p>';
		$kinds = array(
			'attachments'   => Texts::t( 'Allegato di un movimento' ),
			'documents'     => Texts::t( 'Documento' ),
			'items'         => Texts::t( 'Foto di un bene' ),
			'medical_certs' => Texts::t( 'Certificato medico' ),
			'refunds'       => Texts::t( 'Giustificativo di un rimborso' ),
		);
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html( Texts::t( 'Tipo' ) ) . '</th><th>' . esc_html( Texts::t( 'Numero' ) ) . '</th><th>' . esc_html( Texts::t( 'File sul disco' ) ) . '</th><th>' . esc_html( Texts::t( 'Stato' ) ) . '</th></tr></thead><tbody>';
		foreach ( FileMigration::unencrypted( 50 ) as $r ) {
			echo '<tr><td>' . esc_html( $kinds[ $r['key'] ] ?? $r['key'] ) . '</td><td>' . (int) $r['id'] . '</td><td><code>' . esc_html( $r['stored'] ) . '</code></td><td>'
				. esc_html( $r['exists'] ? Texts::t( 'in chiaro' ) : Texts::t( 'non trovato' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::form_open( 'asem_enc_start', $back, false, 'asem-inline' );
		echo '<p><button class="button">' . esc_html( Texts::t( 'Riprova' ) ) . '</button></p>';
		Ui::form_close();
		echo '</details>';
	}

	/** Cosa fare quando la chiave non si apre più. */
	private static function recovery( string $back ): void {
		echo '<h3>' . esc_html( Texts::t( 'Sistemare la chiave' ) ) . '</h3>';
		self::forward_line();
		echo '<p>' . esc_html( Texts::t( 'Se hai scelto la frase di recupero, scrivila qui: la chiave si riapre e i file tornano leggibili, senza cifrarli di nuovo.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_recoverphrase', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Frase di recupero' ) ) . '<br><input type="password" name="recovery_phrase" class="regular-text" autocomplete="off" required></label></p>'
			. '<p><button class="button button-primary">' . esc_html( Texts::t( 'Riapri la chiave con la frase di recupero' ) ) . '</button></p>';
		Ui::form_close();
		echo '<p>' . esc_html( Texts::t( 'Se i codici di sicurezza di wp-config.php o la costante ASEM_ENC_KEY sono stati cambiati, la strada più semplice è rimettere quelli di prima. Se non è possibile, qui sotto si incollano i vecchi valori: la chiave viene avvolta di nuovo con quelli di adesso, senza cifrare di nuovo nessun file.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_recover', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Vecchio valore di ASEM_ENC_KEY (se c\'era)' ) ) . '<br><input type="password" name="old_enc_const" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_KEY (se non c\'era la costante)' ) ) . '<br><input type="password" name="old_auth_key" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_SALT' ) ) . '<br><input type="password" name="old_auth_salt" class="regular-text" autocomplete="off"></label></p>'
			. '<p><button class="button">' . esc_html( Texts::t( 'Riapri la chiave con i vecchi valori' ) ) . '</button></p>';
		Ui::form_close();
		echo '<p>' . esc_html( Texts::t( 'Se anche la frase di recupero è andata persa, i file cifrati si possono ritrovare ripristinando una copia di sicurezza con la sua frase segreta (sezione «Ripristino»). In alternativa si può ricominciare con una chiave nuova: da quel momento i nuovi file si salvano, ma quelli già cifrati restano illeggibili finché la chiave di prima non torna disponibile.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_newkey', $back, false );
		echo '<p><strong>' . esc_html( Texts::t( 'I documenti già cifrati non si potranno più aprire, a meno di ritrovare la chiave di prima.' ) ) . '</strong></p>'
			. '<p><label>' . esc_html( Texts::t( 'Per confermare scrivi la parola RICOMINCIA' ) ) . '<br><input type="text" name="confirm_word" class="regular-text" autocomplete="off" required></label></p>'
			. '<p><button class="button">' . esc_html( Texts::t( 'Ricomincia con una chiave nuova' ) ) . '</button></p>';
		Ui::form_close();
	}
}

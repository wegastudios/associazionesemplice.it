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
 * più. Avvisi chiudibili per libreria mancante e codici di sicurezza deboli.
 */
final class FileCryptPanel {

	/** Avvisi chiudibili, in cima alle pagine Copia di sicurezza e Certificati. */
	public static function alerts(): void {
		echo wp_kses( self::alerts_html(), Html::allowed() );
	}

	/**
	 * Cosa dire della cifratura automatica dei certificati medici già caricati (per gli avvisi e per il riquadro): tipo, titolo, testo, e se è un avviso che si può
	 * chiudere. Null se non c'è nulla da dire (mai partita, finita da più di due settimane, o senza nulla da cifrare).
	 *
	 * @return array{type:string,key:string,days:int,title:string,body:string,link:string}|null link: «stato» o «sodium» o «chiave» (dove rimandare)
	 */
	public static function cert_parts(): ?array {
		$s  = FileMigration::state( FileMigration::CERT_OPT );
		$ex = Texts::t( 'Lo stato si vede in Impostazioni → Copia di sicurezza → «Cifratura dei file riservati».' );
		if ( 'running' === $s['status'] ) {
			if ( $s['done'] + $s['already'] + $s['failed'] + $s['missing'] <= 0 ) {
				return array( 'type' => 'info', 'key' => '', 'days' => 0, 'link' => 'stato', 'body' => Texts::t( 'Si fa da sola, mentre usi il programma: non serve premere nulla.' ) . ' ' . $ex, 'title' => Texts::t( 'La cifratura dei certificati medici è partita.' ) );
			}
			return array( 'type' => 'info', 'key' => '', 'days' => 0, 'link' => 'stato', 'body' => Texts::t( 'Il lavoro prosegue da solo, un lotto al minuto.' ) . ' ' . $ex, 'title' => Texts::t( 'Cifratura dei certificati medici in corso: restano {n}.', array( 'n' => FileMigration::certs_left() ) ) );
		}
		if ( 'done' === $s['status'] && $s['finished'] > 0 ) {
			if ( $s['failed'] > 0 || $s['missing'] > 0 ) {
				return array( 'type' => 'warning', 'key' => 'cert-crypt-done', 'days' => 365, 'link' => 'stato', 'title' => Texts::t( 'La cifratura dei certificati medici è finita, ma {f} non si sono potuti cifrare e {m} non sono stati trovati sul disco.', array( 'f' => $s['failed'], 'm' => $s['missing'] ) ), 'body' => Texts::t( 'I certificati non cifrati sono rimasti dove stavano, in chiaro: l\'elenco è in Impostazioni → Copia di sicurezza → «Cifratura dei file riservati». Apri l\'elenco e premi «Riprova»; se il file non si trova, elimina il certificato dalla pagina Certificati e caricalo di nuovo, oppure ripristina una copia di sicurezza.' ) );
			}
			return array( 'type' => 'success', 'key' => 'cert-crypt-done', 'days' => 365, 'link' => 'stato', 'title' => Texts::t( 'La cifratura dei certificati medici è finita.' ), 'body' => Texts::t( 'Tutti i certificati già caricati sono ora cifrati sul disco.' ) . ' ' . $ex );
		}
		if ( 'nosodium' === $s['status'] ) {
			return array( 'type' => 'error', 'key' => 'cert-crypt-nosodium', 'days' => 7, 'link' => 'sodium', 'title' => Texts::t( 'I certificati medici già caricati restano in chiaro: su questo server manca la libreria di cifratura (sodium).' ), 'body' => Texts::t( 'La cifratura parte da sola appena l\'estensione sodium di PHP è attiva. Va chiesto a chi gestisce il server o l\'hosting.' ) );
		}
		if ( 'nokey' === $s['status'] ) {
			return array( 'type' => 'error', 'key' => 'cert-crypt-nokey', 'days' => 3, 'link' => 'chiave', 'title' => Texts::t( 'La cifratura dei certificati medici è ferma: la chiave non si apre su questo sito.' ), 'body' => Texts::t( 'I certificati già caricati restano in chiaro e non viene toccato nulla. Va sistemata la chiave (Impostazioni → Copia di sicurezza → «Cifratura dei file riservati» → «Sistemare la chiave»): poi la cifratura riparte da sola.' ) );
		}
		return null;
	}

	/** Avvisi sulla cifratura automatica dei certificati medici, per gli amministratori (Bacheca, Copia di sicurezza, Certificati). */
	public static function cert_alerts_html(): string {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return '';
		}
		$p = self::cert_parts();
		if ( ! $p ) {
			return '';
		}
		$s = FileMigration::state( FileMigration::CERT_OPT );
		if ( 'done' === $s['status'] && time() > $s['finished'] + FileMigration::CERT_NOTICE * DAY_IN_SECONDS ) {
			return ''; // l'avviso di fine si chiude da solo dopo due settimane (o prima, se chiuso con la X)
		}
		$labels = array(
			'stato'  => Texts::t( 'Apri lo stato' ),
			'sodium' => Texts::t( 'Vedi il messaggio da inoltrare' ),
			'chiave' => Texts::t( 'Vedi cosa fare' ),
		);
		$inner = '<strong>' . esc_html( $p['title'] ) . '</strong> ' . esc_html( $p['body'] ) . ' <a href="' . esc_url( Ui::url( 'asem-backup' ) . '#asem-cifratura' ) . '">' . esc_html( $labels[ $p['link'] ] ) . '</a>';
		if ( '' === $p['key'] ) {
			return '<div class="notice notice-' . esc_attr( $p['type'] ) . ' inline"><p>' . $inner . '</p></div>';
		}
		return Dismiss::html( $p['key'], $p['type'], $inner, $p['days'] );
	}

	/** Avvisi della cifratura dei certificati, da mostrare in cima alla Bacheca. */
	public static function cert_alerts(): void {
		echo wp_kses( self::cert_alerts_html(), Html::allowed() );
	}

	/** Riquadro nella pagina Copia di sicurezza: lo stato della cifratura automatica dei certificati (senza chiusura, senza link). */
	private static function certs_box(): void {
		$p = self::cert_parts();
		if ( ! $p ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $p['type'] ) . ' inline"><p><strong>' . esc_html( $p['title'] ) . '</strong> ' . esc_html( $p['body'] ) . '</p></div>';
	}

	public static function alerts_html(): string {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return '';
		}
		$url = Ui::url( 'asem-backup' ) . '#asem-cifratura';
		$out = self::cert_alerts_html();
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
					. esc_html( Texts::t( 'La chiave principale dei file riservati si ricava da lì, quindi è più debole: chi avesse anche il database potrebbe aprire i file. Conviene aggiungere in wp-config.php la costante ASEM_ENC_KEY, con un testo lungo e casuale.' ) )
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
		echo '<p>' . esc_html( Texts::t( 'Certificati medici, documenti e scansioni, allegati dei movimenti, giustificativi dei rimborsi, foto dell\'inventario e PDF delle ricevute si salvano cifrati sul disco: chi si portasse via la sola cartella dei file, senza il database, non potrebbe leggerli. La chiave la gestisce il plugin da solo.' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Texts::t( 'Questa protezione serve se qualcuno porta via la cartella dei file. Non sostituisce la sicurezza del sito e del suo database.' ) ) . '</strong></p>';
		echo '<p class="description">' . esc_html( Texts::t( 'Protegge se qualcuno porta via la sola cartella dei file. Chi ha anche il database può aprirli quando la frase è quella automatica (la data di creazione della chiave) o il codice di licenza, perché stanno nel database. Con una frase tua, nel plugin senza licenza, servono anche il file wp-config.php o la frase.' ) ) . '</p>';

		if ( ! FileCrypt::available() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Questo server non ha la libreria di cifratura (sodium): la funzione non è attiva. I file si salvano in chiaro (con questo avviso) e i nuovi certificati medici non si possono caricare. Va chiesto a chi gestisce il server o l\'hosting di attivare l\'estensione sodium di PHP.' ) ) . '</p></div>';
			self::certs_box();
			self::forward_line();
			self::mail_box( 'sodium', Texts::t( 'Buongiorno, sul nostro sito serve attivare l\'estensione sodium di PHP (cifratura dei documenti riservati e dei certificati medici). Grazie.' ) );
			return;
		}

		FileKey::mode(); // la chiave dei dati si crea da sola alla prima occasione
		$c = FileKey::check();
		self::certs_box();
		if ( $c['valid'] ) {
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

	/** La frase in uso (automatica o scelta) e il modo di cambiarla: la cifratura è attiva fin dall'inizio, la frase non blocca nulla. */
	private static function recovery_setup( array $c, string $back ): void {
		if ( 'mismatch' === $c['state'] ) {
			return;
		}
		$kind = FileKey::phrase_kind();
		echo '<h3>' . esc_html( Texts::t( 'Frase per riaprire i documenti' ) ) . '</h3>';
		echo '<p>' . esc_html( Texts::t( 'La chiave dei documenti è custodita dal sito ed è protetta anche da una frase. La frase serve a riaprire i documenti se il sito viene spostato o riparato, se i codici di sicurezza cambiano e per ripristinare una copia di sicurezza su un altro sito.' ) ) . '</p>';
		echo '<p><strong>' . esc_html( self::phrase_line() ) . '</strong></p>';
		if ( 'timestamp' === $kind ) {
			echo '<p>' . esc_html( Texts::t( 'Conservala fuori dal sito (su carta o in un gestore di password): se perdi il sito, altrimenti non la ritrovi.' ) ) . ' ';
			echo '<button type="button" class="button" id="asem-frase-stampa" data-text="' . esc_attr( Texts::t( 'Associazione Semplice: frase per riaprire i documenti riservati' ) . "\n\n" . self::timestamp_text( FileKey::activated_at() ) ) . '">' . esc_html( Texts::t( 'Stampa' ) ) . '</button></p>';
			Ui::inline_js( 'document.getElementById("asem-frase-stampa").addEventListener("click",function(){var w=window.open("","_blank","width=520,height=360");if(!w){return;}var p=w.document.createElement("pre");p.style.fontSize="18px";p.textContent=this.getAttribute("data-text");w.document.body.appendChild(p);w.focus();w.print();});' );
		}
		if ( FileKey::phrase_changed_at() > (int) get_user_meta( get_current_user_id(), Backup::META_DOWNLOADED, true ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( self::phrase_changed_text() ) . '</strong> <a href="' . esc_url( Backup::download_link( true ) ) . '">' . esc_html( Texts::t( 'Scarica la copia con gli allegati' ) ) . '</a></p></div>';
		}
		echo '<p class="description">' . esc_html( Texts::t( 'Quanto protegge: chi si porta via la sola cartella dei file non può leggerli. Chi ha anche il database può aprirli quando la frase è quella automatica o il codice di licenza, perché stanno nel database. La frase serve a te, per riaprire i documenti su un altro sito o dopo un cambio dei codici di sicurezza.' ) ) . ' ' . esc_html( Texts::t( 'Se queste parole non ti dicono nulla, chiedi a chi gestisce il sito.' ) ) . '</p>';
		echo '<p class="description"><strong>' . esc_html( Texts::t( 'Per proteggere di più: nel plugin senza licenza, una frase tua e lunga (almeno quattro parole); in ogni caso la costante ASEM_ENC_KEY in wp-config.php, che sta fuori dal database. Con la licenza il codice di licenza apre sempre: la frase tua serve solo a riaprire i documenti.' ) ) . '</strong></p>';
		echo '<details style="margin:1em 0"><summary>' . esc_html( Texts::t( 'Cambia la frase' ) ) . '</summary>';
		echo '<p><strong>' . esc_html( Texts::t( 'Scegli una frase e conservala fuori dal sito (su carta o in un gestore di password): serve a riaprire i documenti se il sito viene spostato o riparato.' ) ) . '</strong></p>';
		echo '<p>' . esc_html( Texts::t( 'Scegli una frase lunga (almeno {n} caratteri, meglio almeno quattro parole). Prima di cambiare i codici di sicurezza di wp-config.php o ASEM_ENC_KEY, controlla di avere la frase: serve per riaprire i documenti.', array( 'n' => FileKey::RECOVERY_MIN ) ) ) . '</p>';
		echo '<p><strong>' . esc_html( self::phrase_changed_text() ) . '</strong></p>';
		$note = self::kdf_note();
		if ( '' !== $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
		Ui::form_open( 'asem_enc_setrecovery', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Nuova frase' ) ) . '<br><input type="password" name="rphrase" class="regular-text" autocomplete="new-password" minlength="' . (int) FileKey::RECOVERY_MIN . '" required></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Ripeti la nuova frase' ) ) . '<br><input type="password" name="rphrase2" class="regular-text" autocomplete="new-password" minlength="' . (int) FileKey::RECOVERY_MIN . '" required></label></p>'
			. '<p><button class="button button-primary">' . esc_html( Texts::t( 'Cambia la frase' ) ) . '</button></p>';
		Ui::form_close();
		if ( 'custom' === $kind ) {
			Ui::form_open( 'asem_enc_autophrase', $back, false, 'asem-inline' );
			echo '<button class="button">' . esc_html( Texts::t( 'Torna alla frase automatica' ) ) . '</button>';
			Ui::form_close();
		}
		echo '</details>';
	}

	/** Quale frase è in uso, in parole semplici (anche per la procedura guidata). */
	public static function phrase_line(): string {
		$kind = FileKey::phrase_kind();
		if ( 'license' === $kind ) {
			return Texts::t( 'La frase è il codice di licenza: lo ritrovi nell\'area clienti di Associazione Semplice.' );
		}
		if ( 'custom' === $kind ) {
			return '' !== FileKey::license_code()
				? Texts::t( 'Hai scelto una frase tua: conservala. Il codice di licenza apre comunque i documenti.' )
				: Texts::t( 'Hai scelto una frase tua: conservala.' );
		}
		return self::timestamp_text( FileKey::activated_at() ) . ' ' . Texts::t( 'Conservala (è stampabile).' );
	}

	/** La frase automatica: che cos'è davvero (il momento in cui è nata la chiave, non la data di installazione). */
	public static function timestamp_text( string $timestamp ): string {
		return Texts::t( 'La frase è la data e l\'ora in cui è stata creata la chiave dei file, in UTC: {timestamp}. Non è la data di installazione del plugin. Va scritta esattamente così, spazi e trattini compresi.', array( 'timestamp' => $timestamp ) );
	}

	/** Dopo il cambio frase: cosa succede alle copie fatte prima. */
	public static function phrase_changed_text(): string {
		return Texts::t( 'Hai cambiato la frase. Le copie fatte prima si aprono ancora con la frase di allora, e con quella si aprono anche i file salvati dopo: la chiave dei file non cambia. Conservale con cura o cancellale; non c\'è ancora un cambio completo della chiave.' );
	}

	/** La copia con gli allegati: come si apre e come tenerla. */
	public static function copy_open_text(): string {
		return Texts::t( 'La copia con gli allegati si apre con la frase (nel gratuito la data e ora in cui è stata creata la chiave; con la licenza il codice di licenza, che conosce anche Associazione Semplice e sta nelle email di acquisto). Conservala fuori dal sito. Chi ha la copia e la frase apre i documenti: tratta la copia come un dato riservato.' );
	}

	/** Cosa protegge la copia di sicurezza, senza promettere più del vero. */
	public static function copy_note(): string {
		$out  = Texts::t( 'Le copie con gli allegati contengono i documenti cifrati e le loro chiavi, avvolte con la frase in uso.' ) . ' ';
		$kind = FileKey::phrase_kind();
		if ( 'custom' === $kind ) {
			return $out . Texts::t( 'Con la frase che hai scelto la copia vale quanto quella frase: più è lunga, meglio è.' );
		}
		if ( 'license' === $kind ) {
			return $out . self::copy_open_text();
		}
		return $out . self::copy_open_text();
	}

	private static function protection(): void {
		if ( 'const' === FileKey::source() ) {
			echo '<p>' . esc_html( Texts::t( 'La chiave principale viene dalla costante ASEM_ENC_KEY di wp-config.php, che sta fuori dal database. Insieme a una frase tua e lunga è la protezione più alta. La costante va conservata con cura: senza di essa (e senza la frase) i file non si aprono più.' ) ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( Texts::t( 'La chiave principale è ricavata dai codici di sicurezza di wp-config.php: chi avesse sia una copia del database sia il file wp-config.php potrebbe aprire i file.' ) ) . ' '
			. esc_html( Texts::t( 'Va bene per la maggior parte dei siti; la costante è per chi vuole il massimo.' ) ) . '</p>';
		if ( FileKey::salts_weak() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Texts::t( 'I codici di sicurezza (AUTH_KEY e AUTH_SALT) mancano o sono quelli di esempio: la chiave è debole.' ) ) . '</p></div>';
		}
		echo '<details style="margin:1em 0"><summary><strong>' . esc_html( Texts::t( 'Come aumentare la protezione' ) ) . '</strong></summary>';
		self::forward_line();
		self::mail_box( 'salts', Texts::t( 'Buongiorno, nel file wp-config.php del nostro sito servono codici di sicurezza (AUTH_KEY, AUTH_SALT) casuali e una costante ASEM_ENC_KEY con un testo lungo e casuale. Potete aggiungerli? Grazie.' ) );
		echo '<p><strong>' . esc_html( Texts::t( 'Prima di cambiare i codici di sicurezza o ASEM_ENC_KEY, controlla di avere la frase (qui sopra): serve per riaprire i documenti.' ) ) . '</strong></p>';
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
				echo '<p>' . esc_html( Texts::t( 'La cifratura non è disponibile: manca la libreria sodium oppure la chiave non si apre su questo sito.' ) ) . '</p>';
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
			self::leftovers( $state['started'] > 0 ? $state : FileMigration::state( FileMigration::CERT_OPT ), $back ); // dopo la cifratura automatica dei certificati l'elenco dei rimasti in chiaro si vede anche senza aver premuto il pulsante
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
			'receipts'      => Texts::t( 'PDF di una ricevuta' ),
			'receipts_void' => Texts::t( 'PDF di una ricevuta annullata' ),
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
		echo '<p>' . esc_html( Texts::t( 'Scrivi qui la frase (o il codice di licenza): la chiave si riapre e i file tornano leggibili, senza cifrarli di nuovo. Se non l\'hai cambiata, la frase è il codice di licenza o, nel plugin gratuito, la data e l\'ora in cui è stata creata la chiave dei file (non la data di installazione del plugin).' ) ) . '</p>';
		$when = 'timestamp' === FileKey::phrase_kind() ? FileKey::activated_at_saved() : '';
		if ( '' !== $when ) {
			echo '<p><strong>' . esc_html( Texts::t( 'Se non l\'hai cambiata, la frase è la data e l\'ora in cui è stata creata la chiave dei file, in UTC: {when}. Non è la data di installazione del plugin. Va scritta esattamente com\'era, spazi e trattini compresi.', array( 'when' => $when ) ) ) . '</strong></p>';
		}
		Ui::form_open( 'asem_enc_recoverphrase', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Frase o codice di licenza' ) ) . '<br><input type="password" name="recovery_phrase" class="regular-text" autocomplete="off" required></label></p>'
			. '<p><button class="button button-primary">' . esc_html( Texts::t( 'Riapri la chiave con la frase' ) ) . '</button></p>';
		Ui::form_close();
		echo '<p>' . esc_html( Texts::t( 'Se i codici di sicurezza di wp-config.php o la costante ASEM_ENC_KEY sono stati cambiati, la strada più semplice è rimettere quelli di prima. Se non è possibile, qui sotto si incollano i vecchi valori: la chiave viene avvolta di nuovo con quelli di adesso, senza cifrare di nuovo nessun file.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_recover', $back, false );
		echo '<p><label>' . esc_html( Texts::t( 'Vecchio valore di ASEM_ENC_KEY (se c\'era)' ) ) . '<br><input type="password" name="old_enc_const" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_KEY (se non c\'era la costante)' ) ) . '<br><input type="password" name="old_auth_key" class="regular-text" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html( Texts::t( 'Vecchio AUTH_SALT' ) ) . '<br><input type="password" name="old_auth_salt" class="regular-text" autocomplete="off"></label></p>'
			. '<p><button class="button">' . esc_html( Texts::t( 'Riapri la chiave con i vecchi valori' ) ) . '</button></p>';
		Ui::form_close();
		echo '<p>' . esc_html( Texts::t( 'Se anche la frase è andata persa, i file cifrati si possono ritrovare ripristinando una copia di sicurezza con la sua frase (sezione «Ripristino»). In alternativa si può ricominciare con una chiave nuova: da quel momento i nuovi file si salvano, ma quelli già cifrati restano illeggibili finché la chiave di prima non torna disponibile.' ) ) . '</p>';
		Ui::form_open( 'asem_enc_newkey', $back, false );
		echo '<p><strong>' . esc_html( Texts::t( 'I documenti già cifrati non si potranno più aprire, a meno di ritrovare la chiave di prima.' ) ) . '</strong></p>'
			. '<p><label>' . esc_html( Texts::t( 'Per confermare scrivi la parola RICOMINCIA' ) ) . '<br><input type="text" name="confirm_word" class="regular-text" autocomplete="off" required></label></p>'
			. '<p><button class="button">' . esc_html( Texts::t( 'Ricomincia con una chiave nuova' ) ) . '</button></p>';
		Ui::form_close();
	}
}

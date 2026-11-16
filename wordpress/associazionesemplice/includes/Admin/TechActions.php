<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Azioni delle schede Tecniche e Contabilità delle impostazioni: solo amministratori. */
final class TechActions {

	const ACTIONS = array(
		'asem_save_roles' => 'save_roles',
		'asem_save_acct'  => 'save_acct',
		'asem_save_places' => 'save_places',
		'asem_mail_test'  => 'mail_test',
		'asem_save_bank'  => 'save_bank',
		'asem_save_limits' => 'save_limits',
		'asem_reset_limits' => 'reset_limits',
		'asem_audit_free'  => 'audit_free',
		'asem_save_entity' => 'save_entity',
		'asem_reset_all'   => 'reset_all',
		'asem_wizard_save' => 'wizard_save',
		'asem_wizard_skip' => 'wizard_skip',
		'asem_private_check' => 'private_check',
		'asem_private_move'  => 'private_move',
		'asem_enc_start'     => 'enc_start',
		'asem_enc_step'      => 'enc_step',
		'asem_enc_recover'   => 'enc_recover',
		'asem_enc_newkey'    => 'enc_newkey',
		'asem_enc_setrecovery'    => 'enc_setrecovery',
		'asem_enc_recoverphrase'  => 'enc_recoverphrase',
		'asem_enc_autophrase'     => 'enc_autophrase',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem' );
					try {
						$res = self::$method( $post );
						Ui::redirect( $res[0], $res[1] );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', 'Errore imprevisto: ' . $e->getMessage() );
					}
				}
			);
		}
	}


	/** «Controlla ora» della cartella dei file riservati: una prova dall'esterno, al massimo una ogni dieci secondi. */
	public static function private_check( array $p ): array {
		$back = Ui::url( 'asem-backup' ) . '#asem-private-folder';
		if ( get_transient( 'asem_private_check_busy' ) ) {
			return array( $back, \AssociazioneSemplice\Texts::t( 'Un controllo è appena stato fatto: attendi qualche secondo e riprova.' ) );
		}
		set_transient( 'asem_private_check_busy', 1, 10 );
		$r = \AssociazioneSemplice\PrivateFolder::check();
		return array( $back, \AssociazioneSemplice\Texts::t( 'Controllo eseguito. Esito:' ) . ' ' . \AssociazioneSemplice\PrivateFolder::outcome_text( $r ) );
	}

	/** «Sposta i file»: un lotto di file dalla cartella predefinita a quella indicata in wp-config.php. */
	public static function private_move( array $p ): array {
		$back = Ui::url( 'asem-backup' ) . '#asem-private-folder';
		$st   = \AssociazioneSemplice\PrivateFolder::status();
		if ( ! $st['custom'] ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Texts::t( 'Nessuna nuova cartella in uso: indica prima il percorso in wp-config.php.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$r = \AssociazioneSemplice\PrivateFolder::move_batch();
		if ( $r['busy'] ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Texts::t( 'Un altro spostamento è già in corso: attendi qualche secondo e riprova.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		Audit::log( 'private_folder.moved', 'folder', 0, array( 'moved' => $r['moved'], 'copied' => $r['copied'], 'failed' => $r['failed'], 'conflict' => $r['conflict'] ) );
		if ( $r['same_dir'] ) {
			return array( $back, \AssociazioneSemplice\Texts::t( 'La cartella indicata in wp-config.php è la stessa cartella predefinita, vista da un altro percorso: non è stato spostato né cancellato nulla. Indica una cartella diversa.' ) );
		}
		$msg = \AssociazioneSemplice\Texts::t( 'Spostati {n} file.', array( 'n' => $r['moved'] ) );
		if ( $r['copied'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( 'Copiati {n} file nella nuova cartella: la vecchia resta in uso finché non sono stati copiati tutti.', array( 'n' => $r['copied'] ) );
		}
		if ( $r['switched'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( 'Tutti i file sono stati copiati: da ora è in uso la nuova cartella.' );
		}
		if ( $r['same'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( '{n} file sono lo stesso file nella nuova cartella (una sottocartella collegata): non sono stati cancellati.', array( 'n' => $r['same'] ) );
		}
		if ( $r['failed'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( '{n} non si sono potuti spostare (controlla i permessi): sono rimasti dove stavano.', array( 'n' => $r['failed'] ) );
		}
		if ( $r['conflict'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( '{n} esistono già nella nuova cartella con un contenuto diverso: sono rimasti dove stavano e vanno controllati a mano.', array( 'n' => $r['conflict'] ) );
		}
		if ( $r['left'] > $r['conflict'] + $r['failed'] + $r['same'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( 'Ne restano ancora {n}: premi di nuovo.', array( 'n' => $r['left'] ) );
		}
		return array( $back, $msg );
	}

	/** Frase con l'esito di un lotto di cifratura dei file già caricati. */
	private static function enc_message( array $r ): string {
		if ( ! empty( $r['moving'] ) ) {
			return \AssociazioneSemplice\Texts::t( 'Ci sono ancora file da spostare nella cartella indicata in wp-config.php: la cifratura riparte da sola quando lo spostamento è finito.' );
		}
		if ( ! empty( $r['busy'] ) ) {
			return \AssociazioneSemplice\Texts::t( 'Un altro lotto è già in corso: attendi qualche secondo e riprova.' );
		}
		$msg = \AssociazioneSemplice\Texts::t( 'Cifrati {n} file in questo lotto.', array( 'n' => (int) $r['done'] + (int) $r['already'] ) );
		if ( $r['failed'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( '{n} non si sono potuti cifrare: sono rimasti in chiaro, dove stavano.', array( 'n' => (int) $r['failed'] ) );
		}
		if ( $r['missing'] ) {
			$msg .= ' ' . \AssociazioneSemplice\Texts::t( '{n} non sono stati trovati sul disco.', array( 'n' => (int) $r['missing'] ) );
		}
		$msg .= ' ' . ( $r['finished'] ? \AssociazioneSemplice\Texts::t( 'Il lavoro è finito.' ) : \AssociazioneSemplice\Texts::t( 'Ne restano altri: il lavoro prosegue da solo, un lotto al minuto.' ) );
		return $msg;
	}

	/** «Cifra i file già caricati»: parte il primo lotto e si programmano gli altri. */
	public static function enc_start( array $p ): array {
		$back = Ui::url( 'asem-backup' ) . '#asem-cifratura';
		if ( 'encrypt' !== \AssociazioneSemplice\FileKey::mode() ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Texts::t( 'La cifratura non è disponibile: manca la libreria sodium oppure la chiave non si apre su questo sito.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$r = \AssociazioneSemplice\FileMigration::start();
		Audit::log( 'file_crypt.started', 'folder', 0, array( 'done' => $r['done'], 'failed' => $r['failed'] ) );
		return array( $back, self::enc_message( $r ) );
	}

	/** «Continua adesso»: un altro lotto, senza aspettare quello programmato. */
	public static function enc_step( array $p ): array {
		$back = Ui::url( 'asem-backup' ) . '#asem-cifratura';
		$r    = \AssociazioneSemplice\FileMigration::run_batch();
		if ( \AssociazioneSemplice\FileMigration::state()['running'] ) {
			\AssociazioneSemplice\FileMigration::schedule();
		}
		return array( $back, self::enc_message( $r ) );
	}

	/** Chiave che non si apre più: con i vecchi codici di sicurezza (o la vecchia costante) si avvolge di nuovo, senza ricifrare i file. */
	public static function enc_recover( array $p ): array {
		$n = \AssociazioneSemplice\FileKey::recover( (string) ( $p['old_enc_const'] ?? '' ), (string) ( $p['old_auth_key'] ?? '' ), (string) ( $p['old_auth_salt'] ?? '' ) );
		Audit::log( 'file_crypt.key_recovered', 'folder', 0, array( 'keys' => $n ) );
		return array( Ui::url( 'asem-backup' ) . '#asem-cifratura', \AssociazioneSemplice\Texts::t( 'Chiave riaperta ({n}): i file cifrati tornano leggibili, senza averli cifrati di nuovo.', array( 'n' => $n ) ) );
	}

	/** Ricomincia con una chiave nuova (i file già cifrati con la vecchia restano illeggibili finché la vecchia non torna disponibile). */
	public static function enc_newkey( array $p ): array {
		if ( 'RICOMINCIA' !== strtoupper( trim( (string) ( $p['confirm_word'] ?? '' ) ) ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Texts::t( 'Per ricominciare scrivi la parola RICOMINCIA nel campo di conferma.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		\AssociazioneSemplice\FileKey::start_new();
		Audit::log( 'file_crypt.new_key', 'folder', 0 );
		return array( Ui::url( 'asem-backup' ) . '#asem-cifratura', \AssociazioneSemplice\Texts::t( 'Creata una chiave nuova: da ora i nuovi file si salvano cifrati con questa chiave.' ) );
	}

	/** «Cambia la frase»: da ora la chiave dei dati si apre con la frase scelta. */
	public static function enc_setrecovery( array $p ): array {
		\AssociazioneSemplice\FileKey::set_custom_phrase( (string) ( $p['rphrase'] ?? '' ), (string) ( $p['rphrase2'] ?? '' ) );
		Audit::log( 'file_crypt.phrase_changed', 'folder', 0 );
		return array( Ui::url( 'asem-backup' ) . '#asem-cifratura', \AssociazioneSemplice\Texts::t( 'Frase cambiata. Conservala fuori dal sito.' ) . ' ' . \AssociazioneSemplice\Admin\FileCryptPanel::phrase_changed_text() );
	}

	/** «Torna alla frase automatica». */
	public static function enc_autophrase( array $p ): array {
		\AssociazioneSemplice\FileKey::use_auto_phrase();
		Audit::log( 'file_crypt.phrase_auto', 'folder', 0 );
		return array( Ui::url( 'asem-backup' ) . '#asem-cifratura', \AssociazioneSemplice\Texts::t( 'Tornata alla frase automatica.' ) . ' ' . \AssociazioneSemplice\Admin\FileCryptPanel::phrase_changed_text() );
	}

	/** Chiave che non si apre più: con la frase di recupero si riapre, senza ricifrare i file. */
	public static function enc_recoverphrase( array $p ): array {
		$n = \AssociazioneSemplice\FileKey::recover_with_phrase( (string) ( $p['recovery_phrase'] ?? '' ) );
		Audit::log( 'file_crypt.key_recovered', 'folder', 0, array( 'keys' => $n ) );
		return array( Ui::url( 'asem-backup' ) . '#asem-cifratura', \AssociazioneSemplice\Texts::t( 'Chiave riaperta ({n}): i file cifrati tornano leggibili, senza averli cifrati di nuovo.', array( 'n' => $n ) ) );
	}

	/** Email di prova all'indirizzo di chi la chiede: dice subito se il server l'ha accettata. */
	public static function mail_test( array $p ): array {
		$to = (string) wp_get_current_user()->user_email;
		if ( ! is_email( $to ) ) {
			throw new \InvalidArgumentException( 'Il tuo utente non ha un indirizzo email valido.' );
		}
		if ( ! \AssociazioneSemplice\Texts::mail( $to, 'Prova di invio', "Questa è un'email di prova inviata da Associazione Semplice.\n\nSe la leggi, la posta del sito funziona." ) ) {
			$last = \AssociazioneSemplice\MailLog::recent( 1 );
			throw new \InvalidArgumentException( esc_html( 'Il server non ha accettato il messaggio' . ( $last && ! empty( $last[0]['error'] ) ? ': ' . $last[0]['error'] : '.' ) . ' Controlla la posta del sito (un plugin SMTP di solito risolve).' ) );
		}
		return array( Ui::url( 'asem-tech' ), 'Email di prova inviata a ' . $to . ': controlla la casella (anche la posta indesiderata). Se non arriva, il server l\'ha accettata ma non la consegna: serve un servizio di posta (SMTP).' );
	}

	public static function save_places( array $p ): array {
		$key = \AssociazioneSemplice\Places::clean_key( (string) ( $p['places_key'] ?? '' ) );
		$on  = ! empty( $p['places_enabled'] );
		if ( '' !== trim( (string) ( $p['places_key'] ?? '' ) ) && '' === $key ) {
			throw new \InvalidArgumentException( 'La chiave non sembra valida: copiala per intero dalla console di Google Cloud (lettere, cifre, trattini).' );
		}
		if ( $on && '' === $key ) {
			throw new \InvalidArgumentException( 'Per accendere la ricerca dei luoghi inserisci la chiave API di Google Maps.' );
		}
		Settings::update( array( 'places_enabled' => $on ? 1 : 0, 'places_key' => $key ) );
		return array( Ui::url( 'asem-tech' ), $on ? 'Ricerca dei luoghi attiva: nei campi «Luogo» compaiono i suggerimenti di Google.' : 'Impostazioni salvate: la ricerca dei luoghi è spenta.' );
	}

	public static function save_roles( array $p ): array {
		$chosen = array_map( 'strval', (array) ( $p['roles'] ?? array() ) );
		foreach ( array_keys( TechPage::roles() ) as $key ) {
			TechPage::set_role_access( (string) $key, in_array( (string) $key, $chosen, true ) );
		}
		return array( Ui::url( 'asem-roles' ), 'Accessi salvati.' );
	}





	/** Coordinate bancarie: IBAN controllati (cifra di controllo) e, se cambiano, avviso agli amministratori. */
	public static function save_bank( array $p ): array {
		$rows = array();
		foreach ( (array) ( $p['bank'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rows[] = $r;
			}
		}
		$accounts = \AssociazioneSemplice\Bank::sanitize_accounts( $rows, true ); // un IBAN sbagliato è un errore, non si scarta in silenzio
		$enabled  = ! empty( $p['bank_enabled'] );
		if ( $enabled && ! $accounts ) {
			throw new \InvalidArgumentException( 'Per attivare il bonifico indica almeno un IBAN.' );
		}
		$old = \AssociazioneSemplice\Bank::accounts();
		Settings::update(
			array(
				'bank_enabled'      => $enabled ? 1 : 0,
				'bank_title'        => sanitize_text_field( (string) ( $p['bank_title'] ?? '' ) ),
				'bank_note'         => sanitize_textarea_field( (string) ( $p['bank_note'] ?? '' ) ),
				'bank_in_reminders' => ! empty( $p['bank_in_reminders'] ) ? 1 : 0,
				'bank_accounts'     => $accounts,
			)
		);
		return array( Ui::url( 'asem-bank' ), 'Coordinate bancarie salvate.' . ( \AssociazioneSemplice\Bank::signature( $old ) !== \AssociazioneSemplice\Bank::signature( $accounts ) ? ' Gli amministratori sono stati avvisati per email del cambio.' : '' ) );
	}

	/** Limiti e soglie: ogni valore resta entro i limiti di sicurezza della sua voce. */
	public static function save_limits( array $p ): array {
		$in = array();
		foreach ( \AssociazioneSemplice\Limits::defs() as $key => $d ) {
			if ( 'flag' === $d['unit'] ) {
				$in[ $key ] = ! empty( $p['lim'][ $key ] ) ? 1 : 0; // le caselle non spuntate non arrivano nel modulo
			} elseif ( isset( $p['lim'][ $key ] ) ) {
				$in[ $key ] = $p['lim'][ $key ];
			}
		}
		Settings::update( array( 'limits' => $in ) );
		Audit::log( 'limits.saved', 'settings' );
		return array( Ui::url( 'asem-limits' ), 'Limiti e soglie salvati.' );
	}

	public static function reset_limits( array $p ): array {
		Settings::update( array( 'limits' => array() ) );
		Audit::log( 'limits.reset', 'settings' );
		return array( Ui::url( 'asem-limits' ), 'Ripristinati i valori predefiniti.' );
	}

	/** «Libera spazio»: cancella dal registro azioni tutto ciò che non è dell'anno in corso. */
	public static function audit_free( array $p ): array {
		$n = Audit::free_space();
		return array( Ui::url( 'asem-audit' ), 0 === $n ? 'Nel registro non c\'era niente degli anni scorsi.' : 'Registro alleggerito: eliminate ' . number_format_i18n( $n ) . ' righe degli anni scorsi.' );
	}

	/** Azzeramento dei dati: spunta, frase scritta e password dell'amministratore che opera, poi copia di sicurezza e cancellazione. */
	public static function reset_all( array $p ): array {
		$user = wp_get_current_user();
		if ( empty( $p['confirm'] ) ) {
			throw new \InvalidArgumentException( 'Spunta la conferma: i dati verranno cancellati per sempre.' );
		}
		if ( trim( (string) ( $p['phrase'] ?? '' ) ) !== \AssociazioneSemplice\Reset::PHRASE ) {
			throw new \InvalidArgumentException( 'La frase scritta non corrisponde: non è stato cancellato nulla.' );
		}
		$keys = \AssociazioneSemplice\AttemptLimit::keys( $user && $user->exists() ? (int) $user->ID : 0, \AssociazioneSemplice\ClientIp::get() ); // stesse chiavi del ripristino della copia
		$lim  = \AssociazioneSemplice\AttemptLimit::standard();
		$busy = \AssociazioneSemplice\Texts::t( 'Troppi tentativi: riprova tra qualche minuto.' );
		if ( $lim->is_locked( $keys ) ) { // durante il blocco la password non viene nemmeno controllata
			throw new \InvalidArgumentException( $busy ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		if ( ! $user || ! $user->exists() || '' === (string) ( $p['password'] ?? '' ) || ! wp_check_password( (string) $p['password'], $user->user_pass, $user->ID ) ) {
			if ( $lim->fail( $keys ) ) {
				Audit::log( 'tech.reset_locked', 'settings', 0, array( 'minutes' => (int) ( \AssociazioneSemplice\AttemptLimit::WINDOW / 60 ) ) );
				throw new \InvalidArgumentException( $busy ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
			}
			throw new \InvalidArgumentException( 'La password non è corretta: non è stato cancellato nulla.' );
		}
		$lim->clear( $keys );
		$mode = 'factory' === (string) ( $p['mode'] ?? '' ) ? \AssociazioneSemplice\Reset::FACTORY : \AssociazioneSemplice\Reset::DATA;
		$r    = \AssociazioneSemplice\Reset::run( $mode, ! empty( $p['users'] ) );
		$msg  = ( \AssociazioneSemplice\Reset::FACTORY === $mode ? 'Ripristino di fabbrica completato.' : 'Dati azzerati.' ) . ' Sul sito non resta nessuna copia dei dati: conserva quella che hai scaricato.'
			. ( $r['users'] ? ' Eliminati ' . (int) $r['users'] . ' accessi di soci.' : '' );
		return array( \AssociazioneSemplice\Reset::FACTORY === $mode ? Ui::url( 'asem-wizard' ) : Ui::url( 'asem' ), $msg );
	}

	/** Dati dell'ente e fiscali. Con la partita IVA attiva il numero deve essere valido. */
	public static function save_entity( array $p ): array {
		$vals = \AssociazioneSemplice\EntityData::values( $p, true );
		Settings::update( $vals );
		Audit::log( 'entity.saved', 'settings', 0, array( 'has_vat' => $vals['has_vat'] ?? 0 ) );
		$warn = \AssociazioneSemplice\EntityRules::mismatch_warning( (string) Settings::get( 'entity_form' ), (string) Settings::get( 'runts_section' ) );
		return array( Ui::url( 'asem-entity' ), 'Dati dell\'ente salvati.' . ( '' !== $warn ? ' ' . $warn : '' ) );
	}

	/** Configurazione guidata: applica le scelte e porta dove serve ancora qualcosa (chiavi dei pagamenti, IBAN). */
	public static function wizard_save( array $p ): array {
		$done = \AssociazioneSemplice\Wizard::apply( $p );
		$next = Ui::url( 'asem' );
		$more = '';
		$step = (array) apply_filters( 'asem_wizard_next', array( '', '' ), $p ); // un'estensione può chiedere un passaggio in più (indirizzo, avviso)
		if ( '' !== (string) ( $step[0] ?? '' ) ) {
			$next = (string) $step[0];
			$more = (string) ( $step[1] ?? '' );
		} elseif ( Settings::get( 'bank_enabled' ) && ! \AssociazioneSemplice\Bank::enabled() ) {
			$next = Ui::url( 'asem-bank' );
			$more = ' Ora aggiungi almeno un IBAN per il bonifico.';
		}
		if ( ! empty( $p['go_import'] ) && \AssociazioneSemplice\Modules::on( 'import' ) && Ui::url( 'asem' ) === $next ) { // l'elenco si carica subito dopo (se non serve prima inserire chiavi o IBAN)
			$next  = Ui::url( 'asem-import' );
			$more .= ' Ora carica l\'elenco dei soci e degli ospiti.';
		} elseif ( ! empty( $p['go_import'] ) && \AssociazioneSemplice\Modules::on( 'import' ) ) {
			$more .= ' Poi importa l\'elenco da Strumenti → Importa da Excel/CSV.';
		}
		return array( $next, 'Configurazione completata. ' . implode( ' ', $done ) . $more );
	}

	public static function wizard_skip( array $p ): array {
		\AssociazioneSemplice\Wizard::mark( \AssociazioneSemplice\Wizard::SKIPPED );
		return array( Ui::url( 'asem' ), 'Configurazione guidata rimandata: la riapri quando vuoi da Strumenti.' );
	}

	public static function save_acct( array $p ): array {
		Settings::update( array( 'reports_enabled' => ! empty( $p['reports_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'asem-acct' ), 'Opzioni contabili salvate.' );
	}
}

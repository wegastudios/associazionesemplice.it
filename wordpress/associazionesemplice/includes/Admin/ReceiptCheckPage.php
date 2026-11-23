<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Audit;
use AssociazioneSemplice\FiscalYears;
use AssociazioneSemplice\ReceiptFiles;
use AssociazioneSemplice\Receipts;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Verifica delle ricevute (solo amministratori): per ogni anno fiscale controlla che i PDF definitivi delle ricevute ci siano e che la loro impronta
 * sha256 coincida con quella scritta all'emissione (a posto, mancante, alterato), fissa quelli ancora da fissare (a lotti) e permette di scaricarli in un ZIP.
 */
final class ReceiptCheckPage {

	/** Frase di uno stato, per chi legge. */
	public static function state_text( string $st ): string {
		switch ( $st ) {
			case 'ok':
				return Texts::t( 'a posto' );
			case 'pending':
				return Texts::t( 'PDF ancora da fissare' );
			case 'nodata':
				return Texts::t( 'emessa prima che si salvassero i dati: non si può fissare' );
			case 'locked':
				return Texts::t( 'file cifrato che la chiave di questo sito non apre' );
			case 'missing':
				return Texts::t( 'file mancante' );
			default:
				return Texts::t( 'file diverso da quando è stato emesso' );
		}
	}

	private static function kind_text( string $kind ): string {
		return ReceiptFiles::VOID === $kind ? Texts::t( 'PDF «annullata»' ) : Texts::t( 'PDF emesso' );
	}

	/** Riquadro nella pagina Copia di sicurezza. */
	public static function backup_card(): void {
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) || ! \AssociazioneSemplice\Edition::has( 'receipts' ) ) {
			return;
		}
		echo '<h2>' . esc_html( Texts::t( 'Ricevute: PDF definitivi' ) ) . '</h2><p>' . esc_html( Texts::t( 'I PDF delle ricevute emesse sono documenti definitivi: si salvano una volta sola nella cartella riservata del sito e la copia di sicurezza li contiene sempre, con l\'indice delle loro impronte. Quando ripristini una copia tornano al loro posto.' ) ) . ' ' . esc_html( Texts::t( 'Nella copia i PDF restano cifrati, come sul sito.' ) )
			. ' <a href="' . esc_url( Ui::url( 'asem-receipt-check' ) ) . '">' . esc_html( Texts::t( 'Verifica delle ricevute' ) ) . '</a></p>';
	}

	/** Riquadro nella pagina Anni fiscali: collegamento alla verifica e avviso se ci sono PDF da fissare. */
	public static function years_card(): void {
		if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) || ! \AssociazioneSemplice\Edition::has( 'receipts' ) ) {
			return;
		}
		$n = ReceiptFiles::pending_count();
		echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'PDF definitivi delle ricevute' ) ) . '</h2><p>'
			. esc_html( $n > 0
				? Texts::n( (int) $n, 'C\'è una ricevuta con il PDF ancora da fissare. Fissarlo non cambia nessun dato: il PDF si crea dai dati già salvati e da quel momento non cambia più.', 'Ci sono {n} ricevute con il PDF ancora da fissare. Fissarlo non cambia nessun dato: il PDF si crea dai dati già salvati e da quel momento non cambia più.' )
				: Texts::t( 'Tutte le ricevute emesse hanno il loro PDF definitivo.' ) )
			. ' <a href="' . esc_url( Ui::url( 'asem-receipt-check' ) ) . '">' . esc_html( Texts::t( 'Verifica delle ricevute' ) ) . '</a></p></div>';
	}

	public static function render(): void {
		Ui::header( Texts::t( 'Verifica delle ricevute' ) );
		$years = ReceiptFiles::years();
		if ( ! $years ) {
			echo '<p>' . esc_html( Texts::t( 'Non c\'è ancora nessuna ricevuta emessa.' ) ) . '</p>';
			Ui::footer();
			return;
		}
		$year = Ui::get_int( 'year', $years[0] );
		if ( ! in_array( $year, $years, true ) ) {
			$year = $years[0];
		}
		$after = max( 0, Ui::get_int( 'after' ) );
		$here  = Ui::url( 'asem-receipt-check', array( 'year' => $year ) );

		echo '<p>' . esc_html( Texts::t( 'Ogni ricevuta emessa ha il suo PDF definitivo: si crea una volta sola e si salva nella cartella riservata del sito, insieme alla sua impronta. Qui controlli che i file ci siano e che non siano cambiati. I PDF fanno parte della copia di sicurezza.' ) ) . ' ' . esc_html( Texts::t( 'I PDF si salvano cifrati: per controllarli il sito li apre e confronta l\'impronta del contenuto con quella scritta all\'emissione.' ) ) . '</p>';
		echo '<p class="description">' . esc_html( Texts::t( 'I dati della ricevuta (nome, codice fiscale, importi, dati dell\'ente, piè di pagina, righe) sono sempre quelli salvati all\'emissione. Le ricevute più vecchie, emesse prima che i PDF si salvassero, vengono fissate in automatico: il loro PDF usa i testi e la grafica del giorno in cui viene fissato (testi personalizzati, tipo di ente e termini scelti). Le ricevute emesse da allora in poi restano identiche per sempre.' ) ) . '</p>';
		echo '<p>' . esc_html( Texts::t( 'Anno fiscale:' ) ) . ' ';
		foreach ( $years as $y ) {
			echo $y === $year
				? '<strong>' . esc_html( FiscalYears::label( $y ) ) . '</strong> '
				: '<a href="' . esc_url( Ui::url( 'asem-receipt-check', array( 'year' => $y ) ) ) . '">' . esc_html( FiscalYears::label( $y ) ) . '</a> ';
		}
		echo '</p>';

		// Conteggi dal database: aprire la pagina non apre nessun PDF.
		$cnt = ReceiptFiles::sql_counts( $year );
		echo '<table class="widefat striped" style="max-width:560px"><tbody>';
		foreach ( array( 'total' => Texts::t( 'Ricevute emesse' ), 'pending' => Texts::t( 'PDF ancora da fissare' ), 'nodata' => Texts::t( 'Emesse prima che si salvassero i dati' ) ) as $k => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right"><strong>' . (int) $cnt[ $k ] . '</strong></td></tr>';
		}
		echo '</tbody></table>';

		// Verifica dei file: parte solo con il pulsante, un lotto per volta.
		$last = ReceiptFiles::verify_last( $year );
		$run  = ReceiptFiles::verify_progress( $year );
		echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'Verifica dei PDF di questo anno fiscale' ) ) . '</h2><p>' . esc_html( Texts::t( 'La verifica apre ogni PDF e ne controlla l\'impronta: con molte ricevute richiede tempo, perciò parte solo quando premi il pulsante e procede a lotti di {n} ricevute. Se non finisce in un giro, premi di nuovo: riprende da dove si era fermata.', array( 'n' => ReceiptFiles::VERIFY_BATCH ) ) ) . '</p>';
		if ( $run ) {
			echo '<p><strong>' . esc_html( Texts::n( (int) $run['summary']['total'], 'Verifica in corso: controllata una ricevuta su {tot}.', 'Verifica in corso: controllate {n} ricevute su {tot}.', array( 'tot' => max( (int) $run['total'], (int) $run['summary']['total'] ) ) ) ) . '</strong></p>';
		}
		Ui::form_open( 'asem_receipt_verify', $here, false, 'asem-inline' );
		echo Ui::hidden( 'year', $year ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::hidden() protegge i valori
		echo '<button class="button button-primary">' . esc_html( $run ? Texts::t( 'Continua la verifica' ) : ( $last ? Texts::t( 'Verifica di nuovo' ) : Texts::t( 'Verifica i PDF di questo anno fiscale' ) ) ) . '</button>';
		Ui::form_close();
		echo '</div>';

		if ( null === $last ) {
			echo '<p>' . esc_html( Texts::t( 'Non hai ancora verificato i PDF di questo anno fiscale.' ) ) . '</p>';
		} else {
			$sum = $last['summary'];
			echo '<h2>' . esc_html( Texts::t( 'Esito dell\'ultima verifica' ) ) . '</h2><p>' . esc_html( Texts::t( 'Verifica del {data}.', array( 'data' => Ui::date( substr( (string) $last['at'], 0, 10 ) ) . ' ' . substr( (string) $last['at'], 11, 5 ) ) ) ) . '</p>';
			echo '<table class="widefat striped" style="max-width:560px"><tbody>';
			foreach ( array( 'total' => Texts::t( 'Ricevute controllate' ), 'ok' => Texts::t( 'A posto' ), 'locked' => Texts::t( 'File cifrati che la chiave non apre' ), 'missing' => Texts::t( 'File mancanti' ), 'altered' => Texts::t( 'File diversi da quando sono stati emessi' ) ) as $k => $label ) {
				echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right"><strong>' . (int) ( $sum[ $k ] ?? 0 ) . '</strong></td></tr>';
			}
			echo '</tbody></table>';
			if ( (int) $sum['total'] < (int) $cnt['total'] ) {
				echo '<p class="description">' . esc_html( Texts::t( 'Dopo questa verifica sono state emesse altre ricevute: verifica di nuovo per controllare anche quelle.' ) ) . '</p>';
			}

			$bad = (int) $sum['missing'] + (int) $sum['altered'];
			if ( 0 === $bad && 0 === (int) $sum['locked'] && 0 === (int) $cnt['pending'] && 0 === (int) $cnt['nodata'] && (int) $sum['total'] >= (int) $cnt['total'] ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( Texts::t( 'Tutti i PDF di questo anno fiscale sono al loro posto e coincidono con la loro impronta.' ) ) . '</p></div>';
			}
			if ( $bad ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Alcuni PDF non coincidono con quelli emessi. Finché non li sistemi, scaricare quelle ricevute dà un errore (non vengono mai rifatte di nascosto). Puoi ripristinare il file da una copia di sicurezza oppure rifarlo dai dati salvati: lo si fa solo se i dati sono intatti e l\'operazione resta scritta nel registro azioni.' ) ) . '</p></div>';
			}
			if ( (int) $sum['locked'] > 0 ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Alcuni PDF sono cifrati e la chiave di questo sito non li apre: i file non sono stati toccati. Sistema la chiave da Strumenti → Copia di sicurezza → «Cifratura dei file riservati»; finché non è sistemata, scaricare quelle ricevute dà un errore.' ) ) . '</p></div>';
			}
		}

		if ( (int) $cnt['nodata'] > 0 ) {
			echo '<p class="description">' . esc_html( Texts::t( 'Queste ricevute non hanno i dati salvati: il loro PDF non è ancora stato fissato e non è nella copia di sicurezza. Basta aprire o scaricare la ricevuta una volta: i suoi dati di quel momento si salvano e il PDF viene fissato. Le ricevute già annullate che sono in questo stato non si possono più aprire.' ) ) . '</p>';
			if ( ReceiptFiles::nodata_count( $year ) > 0 ) {
				echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'Fissa anche queste con i dati di oggi' ) ) . '</h2><p><strong>' . esc_html( Texts::t( 'I dati sono quelli di oggi, non quelli dell\'emissione.' ) ) . '</strong> ' . esc_html( Texts::t( 'Per ogni ricevuta si salvano il nome, il codice fiscale, gli importi e i dati dell\'ente come sono adesso e se ne fissa il PDF cifrato; da quel momento non cambia più. Le ricevute annullate restano come sono. Si procede a lotti di {n} ricevute: se ne restano, premi di nuovo. L\'operazione viene scritta nel registro azioni.', array( 'n' => ReceiptFiles::BATCH ) ) ) . '</p>';
				Ui::form_open( 'asem_receipt_nodata_fix', $here, false, 'asem-inline' );
				echo Ui::hidden( 'year', $year ) . Ui::hidden( 'after', $after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::hidden() protegge i valori
				echo '<button class="button" data-confirm="' . esc_attr( Texts::t( 'Fissare queste ricevute con i dati di oggi, non con quelli dell\'emissione? Dopo, il PDF non cambia più; l\'operazione viene scritta nel registro azioni.' ) ) . '">' . esc_html( Texts::t( 'Fissa anche queste con i dati di oggi' ) ) . '</button>';
				Ui::form_close();
				echo '</div>';
			}
		}

		if ( (int) $cnt['pending'] > 0 ) {
			echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'Fissa i PDF in sospeso' ) ) . '</h2><p>' . esc_html( Texts::t( 'Sono ricevute emesse prima che i PDF si salvassero. Il PDF si crea dai dati già salvati, senza cambiarli, e da quel momento non cambia più. Si procede a lotti di {n} ricevute: se ne restano, premi di nuovo.', array( 'n' => ReceiptFiles::BATCH ) ) ) . '</p>';
			Ui::form_open( 'asem_receipt_pdf_complete', $here, false, 'asem-inline' );
			echo Ui::hidden( 'year', $year ) . Ui::hidden( 'after', $after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::hidden() protegge i valori
			echo '<button class="button button-primary">' . esc_html( Texts::t( 'Fissa i prossimi PDF' ) ) . '</button>';
			Ui::form_close();
			echo '</div>';
		}

		if ( $last && $last['problems'] ) {
			echo '<h2>' . esc_html( Texts::t( 'Ricevute da controllare' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( Texts::t( 'Ricevuta' ) ) . '</th><th>' . esc_html( Texts::t( 'Problema' ) ) . '</th><th></th></tr></thead><tbody>';
			foreach ( array_slice( $last['problems'], 0, ReceiptFiles::VERIFY_KEEP ) as $p ) {
				echo '<tr><td>n. ' . esc_html( $p['label'] ) . '</td><td>';
				$acts = '';
				foreach ( $p['detail'] as $kind => $st ) {
					echo esc_html( self::kind_text( $kind ) . ': ' . self::state_text( $st ) ) . '<br>';
					if ( in_array( $st, array( 'missing', 'altered' ), true ) ) {
						$acts .= Ui::confirm_button( 'asem_receipt_pdf_regen', $here, array( 'key' => $p['key'], 'kind' => $kind, 'year' => $year ), Texts::t( 'Rifai dai dati salvati' ), Texts::t( 'Rifare questo PDF dai dati salvati all\'emissione? Il nuovo file si salva cifrato; quello attuale, se è diverso, viene messo da parte. L\'operazione viene scritta nel registro azioni.' ) ) . ' ';
					}
				}
				echo '</td><td>' . $acts . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::confirm_button() protegge ogni valore
			}
			echo '</tbody></table>';
			if ( (int) $last['summary']['locked'] + (int) $last['summary']['missing'] + (int) $last['summary']['altered'] > count( $last['problems'] ) ) {
				echo '<p class="description">' . esc_html( Texts::t( 'Si vedono le prime {n}: sistemale e verifica di nuovo per vedere le altre.', array( 'n' => ReceiptFiles::VERIFY_KEEP ) ) ) . '</p>';
			}
		}

		echo '<p><a class="button" href="' . esc_url( Receipts::zip_url( $year ) ) . '">' . esc_html( Texts::t( 'Scarica i PDF di questo anno fiscale (ZIP)' ) ) . '</a></p>';
		echo '<p class="description"><strong>' . esc_html( Texts::t( 'I PDF dello ZIP non sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura e cancellalo quando non ti serve più.' ) ) . '</strong></p>';
		echo '<p class="description">' . esc_html( Texts::t( 'Ogni PDF pesa pochi KB e sta nella cartella riservata del sito ({cartella}/receipts), la stessa degli allegati. Se la cartella risultasse raggiungibile da internet, la pagina Copia di sicurezza lo segnala. Conserva sempre anche una copia di sicurezza fuori dal sito.', array( 'cartella' => \AssociazioneSemplice\Attachments::dir() ) ) ) . '</p>';
		Ui::footer();
	}

	/** Azione del pulsante «Verifica i PDF»: un giro della verifica (a lotti, con cursore); un solo giro per volta in tutto il sito. */
	public static function verify( array $p ): array {
		$year = max( 0, (int) ( $p['year'] ?? 0 ) );
		if ( ! in_array( $year, ReceiptFiles::years(), true ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Scegli un anno fiscale che abbia ricevute emesse.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$r    = ReceiptFiles::verify_step( $year );
		$back = Ui::url( 'asem-receipt-check', array( 'year' => $year ) );
		if ( ! $r['finished'] ) {
			return array( $back, Texts::n( (int) $r['checked'], 'Controllata una ricevuta su {tot}: premi di nuovo per continuare.', 'Controllate {n} ricevute su {tot}: premi di nuovo per continuare.', array( 'tot' => $r['total'] ) ) );
		}
		$bad = (int) $r['summary']['locked'] + (int) $r['summary']['missing'] + (int) $r['summary']['altered'];
		return array( $back, 0 === $bad
			? Texts::n( (int) $r['checked'], 'Verifica finita: controllata una ricevuta, nessun problema trovato.', 'Verifica finita: controllate {n} ricevute, nessun problema trovato.' )
			: Texts::n( (int) $r['checked'], 'Verifica finita: controllata una ricevuta, {bad} da sistemare.', 'Verifica finita: controllate {n} ricevute, {bad} da sistemare.', array( 'bad' => $bad ) ) );
	}

	/** Azione del modulo «Fissa i prossimi PDF»: un lotto per volta. */
	public static function complete( array $p ): array {
		$year  = max( 0, (int) ( $p['year'] ?? 0 ) );
		$after = max( 0, (int) ( $p['after'] ?? 0 ) );
		$r     = ReceiptFiles::complete( $year > 0 ? $year : null, $after );
		Audit::log( 'receipt.pdfs_fixed', 'year', $year, array( 'done' => $r['done'], 'failed' => $r['failed'], 'manual' => 1 ) );
		$left = ReceiptFiles::pending_count( $year > 0 ? $year : null );
		$msg  = Texts::n( (int) $r['done'], 'Fissato il PDF di una ricevuta.', 'Fissati i PDF di {n} ricevute.' );
		if ( $r['failed'] ) {
			$msg .= ' ' . Texts::n( (int) $r['failed'], 'Un PDF non si è potuto fissare (controlla i permessi della cartella dei file riservati o i dati della ricevuta).', '{n} non si sono potuti fissare (controlla i permessi della cartella dei file riservati o i dati della ricevuta).' );
		}
		if ( $left > 0 && $r['more'] ) {
			$msg .= ' ' . Texts::n( (int) $left, 'Ne resta ancora una: premi di nuovo.', 'Ne restano ancora {n}: premi di nuovo.' );
		}
		return array( Ui::url( 'asem-receipt-check', array( 'year' => $year, 'after' => $r['more'] ? $r['last_id'] : 0 ) ), $msg );
	}

	/** Azione «Fissa anche queste con i dati di oggi»: un lotto per volta, per le ricevute emesse prima che si salvassero i dati. */
	public static function fix_nodata( array $p ): array {
		$year  = max( 0, (int) ( $p['year'] ?? 0 ) );
		$after = max( 0, (int) ( $p['after'] ?? 0 ) );
		$r     = ReceiptFiles::complete_nodata( $year > 0 ? $year : null, $after );
		Audit::log( 'receipt.nodata_fixed_today', 'year', $year, array( 'done' => $r['done'], 'failed' => $r['failed'], 'data_of_today' => 1 ) );
		$left = ReceiptFiles::nodata_count( $year > 0 ? $year : null );
		$msg  = Texts::n( (int) $r['done'], 'Fissata con i dati di oggi una ricevuta.', 'Fissate con i dati di oggi {n} ricevute.' );
		if ( $r['failed'] ) {
			$msg .= ' ' . Texts::n( (int) $r['failed'], 'Una ricevuta non si è potuta fissare (ha perso le righe di incasso o la cartella dei file riservati non è scrivibile).', '{n} non si sono potute fissare (hanno perso le righe di incasso o la cartella dei file riservati non è scrivibile).' );
		}
		if ( $left > 0 && $r['more'] ) {
			$msg .= ' ' . Texts::n( (int) $left, 'Ne resta ancora una: premi di nuovo.', 'Ne restano ancora {n}: premi di nuovo.' );
		} elseif ( 0 === $left ) {
			$voided = ReceiptFiles::nodata_voided_count( $year > 0 ? $year : null );
			if ( $voided > 0 ) {
				$msg .= ' ' . Texts::n( (int) $voided, 'La ricevuta rimasta è annullata: resta com\'è.', 'Le {n} rimaste sono ricevute annullate: restano come sono.' );
			}
		}
		return array( Ui::url( 'asem-receipt-check', array( 'year' => $year, 'after' => $r['more'] ? $r['last_id'] : 0 ) ), $msg );
	}

	/** Azione «Rifai dai dati salvati» di una ricevuta con il file mancante o alterato. */
	public static function regenerate( array $p ): array {
		if ( \AssociazioneSemplice\PrivateFolder::move_incomplete() ) { // file da spostare in un'altra cartella: i PDF non si rifanno finché non sono stati spostati tutti
			throw new \InvalidArgumentException( Texts::t( 'Ci sono ancora file da spostare nella cartella indicata in wp-config.php: rifai le ricevute quando lo spostamento è finito.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		Receipts::regenerate_pdf( (string) ( $p['key'] ?? '' ), (string) ( $p['kind'] ?? '' ) );
		ReceiptFiles::verify_recheck( (int) ( $p['year'] ?? 0 ), (string) ( $p['key'] ?? '' ) );
		return array( Ui::url( 'asem-receipt-check', array( 'year' => (int) ( $p['year'] ?? 0 ) ) ), Texts::t( 'PDF rifatto dai dati salvati all\'emissione. L\'operazione è scritta nel registro azioni.' ) );
	}
}

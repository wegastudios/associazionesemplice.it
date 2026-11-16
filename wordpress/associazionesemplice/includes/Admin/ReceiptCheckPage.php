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

	public static function register(): void {
		add_action( 'asem_backup_page_after', array( __CLASS__, 'backup_card' ), 20 );
		add_action( 'asem_years_page_after', array( __CLASS__, 'years_card' ), 25 );
	}

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
				? Texts::t( 'Ci sono {n} ricevute con il PDF ancora da fissare. Fissarlo non cambia nessun dato: il PDF si disegna dai dati già salvati e da quel momento non cambia più.', array( 'n' => $n ) )
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

		echo '<p>' . esc_html( Texts::t( 'Ogni ricevuta emessa ha il suo PDF definitivo: si disegna una volta sola e si salva nella cartella riservata del sito, insieme alla sua impronta. Qui controlli che i file ci siano e che non siano cambiati. I PDF fanno parte della copia di sicurezza.' ) ) . ' ' . esc_html( Texts::t( 'I PDF si salvano cifrati: per controllarli il sito li apre e confronta l\'impronta del contenuto con quella scritta all\'emissione.' ) ) . '</p>';
		echo '<p class="description">' . esc_html( Texts::t( 'I dati della ricevuta (nome, codice fiscale, importi, dati dell\'ente, piè di pagina, righe) sono sempre quelli salvati all\'emissione. Le ricevute emesse prima di questo aggiornamento si fissano in automatico dopo l\'aggiornamento: per il loro PDF valgono però i testi di oggi, cioè i tuoi testi personalizzati, le parole scelte per il tipo di ente (socio/iscritto, associazione/comitato) e la grafica del plugin di quel momento. Le ricevute emesse da ora in poi restano identiche per sempre.' ) ) . '</p>';
		echo '<p>' . esc_html( Texts::t( 'Anno fiscale:' ) ) . ' ';
		foreach ( $years as $y ) {
			echo $y === $year
				? '<strong>' . esc_html( FiscalYears::label( $y ) ) . '</strong> '
				: '<a href="' . esc_url( Ui::url( 'asem-receipt-check', array( 'year' => $y ) ) ) . '">' . esc_html( FiscalYears::label( $y ) ) . '</a> ';
		}
		echo '</p>';

		$v   = ReceiptFiles::verify( $year );
		$sum = $v['summary'];
		echo '<table class="widefat striped" style="max-width:560px"><tbody>';
		foreach ( array( 'total' => Texts::t( 'Ricevute emesse' ), 'ok' => Texts::t( 'A posto' ), 'pending' => Texts::t( 'PDF ancora da fissare' ), 'nodata' => Texts::t( 'Emesse prima che si salvassero i dati' ), 'locked' => Texts::t( 'File cifrati che la chiave non apre' ), 'missing' => Texts::t( 'File mancanti' ), 'altered' => Texts::t( 'File diversi da quando sono stati emessi' ) ) as $k => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right"><strong>' . (int) $sum[ $k ] . '</strong></td></tr>';
		}
		echo '</tbody></table>';
		if ( $v['truncated'] ) {
			echo '<p class="description">' . esc_html( Texts::t( 'Sono state controllate le prime 20000 ricevute dell\'anno.' ) ) . '</p>';
		}

		$bad = (int) $sum['missing'] + (int) $sum['altered'];
		if ( 0 === $bad && 0 === (int) $sum['locked'] && 0 === (int) $sum['pending'] && 0 === (int) $sum['nodata'] ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( Texts::t( 'Tutti i PDF di questo anno fiscale sono al loro posto e coincidono con la loro impronta.' ) ) . '</p></div>';
		}
		if ( $bad ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Alcuni PDF non coincidono con quelli emessi. Finché non li sistemi, scaricare quelle ricevute dà un errore (non vengono mai rifatte di nascosto). Puoi ripristinare il file da una copia di sicurezza oppure rifarlo dai dati salvati: lo si fa solo se i dati sono intatti e l\'operazione resta scritta nel registro azioni.' ) ) . '</p></div>';
		}

		if ( (int) $sum['nodata'] > 0 ) {
			echo '<p class="description">' . esc_html( Texts::t( 'Queste ricevute non hanno i dati salvati: il loro PDF non è ancora stato fissato e non è nella copia di sicurezza. Aprire o scaricare la ricevuta una volta salva i suoi dati di quel momento e ne fissa il PDF; le ricevute già annullate in questo stato non si possono più aprire.' ) ) . '</p>';
			if ( ReceiptFiles::nodata_count( $year ) > 0 ) {
				echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'Fissa anche queste con i dati di oggi' ) ) . '</h2><p><strong>' . esc_html( Texts::t( 'I dati sono quelli di oggi, non quelli dell\'emissione.' ) ) . '</strong> ' . esc_html( Texts::t( 'Per ogni ricevuta si salvano il nome, il codice fiscale, gli importi e i dati dell\'ente come sono adesso e se ne fissa il PDF cifrato; da quel momento non cambia più. Le ricevute annullate restano come sono. Si procede a lotti di {n} ricevute: se ne restano, premi di nuovo. L\'operazione viene scritta nel registro azioni.', array( 'n' => ReceiptFiles::BATCH ) ) ) . '</p>';
				Ui::form_open( 'asem_receipt_nodata_fix', $here, false, 'asem-inline' );
				echo Ui::hidden( 'year', $year ) . Ui::hidden( 'after', $after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::hidden() protegge i valori
				echo '<button class="button" data-confirm="' . esc_attr( Texts::t( 'Fissare queste ricevute con i dati di oggi? I dati sono quelli di oggi, non quelli dell\'emissione. Dopo il PDF non cambia più e l\'operazione viene scritta nel registro azioni.' ) ) . '">' . esc_html( Texts::t( 'Fissa anche queste con i dati di oggi' ) ) . '</button>';
				Ui::form_close();
				echo '</div>';
			}
		}

		if ( (int) $sum['locked'] > 0 ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Texts::t( 'Alcuni PDF sono cifrati e la chiave di questo sito non li apre: i file non sono stati toccati. Sistema la chiave da Impostazioni → Copia di sicurezza → «Cifratura dei file riservati»; finché non è sistemata, scaricare quelle ricevute dà un errore.' ) ) . '</p></div>';
		}

		if ( (int) $sum['pending'] > 0 ) {
			echo '<div class="asem-card"><h2>' . esc_html( Texts::t( 'Fissa i PDF ancora da fissare' ) ) . '</h2><p>' . esc_html( Texts::t( 'Sono ricevute emesse prima che i PDF si salvassero. Il PDF si disegna dai dati già salvati, senza cambiarli, e da quel momento non cambia più. Si procede a lotti di {n} ricevute: se ne restano, premi di nuovo.', array( 'n' => ReceiptFiles::BATCH ) ) ) . '</p>';
			Ui::form_open( 'asem_receipt_pdf_complete', $here, false, 'asem-inline' );
			echo Ui::hidden( 'year', $year ) . Ui::hidden( 'after', $after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::hidden() protegge i valori
			echo '<button class="button button-primary">' . esc_html( Texts::t( 'Fissa i prossimi PDF' ) ) . '</button>';
			Ui::form_close();
			echo '</div>';
		}

		if ( $v['problems'] ) {
			echo '<h2>' . esc_html( Texts::t( 'Ricevute da controllare' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( Texts::t( 'Ricevuta' ) ) . '</th><th>' . esc_html( Texts::t( 'Problema' ) ) . '</th><th></th></tr></thead><tbody>';
			foreach ( array_slice( $v['problems'], 0, 200 ) as $p ) {
				echo '<tr><td>n. ' . esc_html( $p['label'] ) . '</td><td>';
				$acts = '';
				foreach ( $p['detail'] as $kind => $st ) {
					echo esc_html( self::kind_text( $kind ) . ': ' . self::state_text( $st ) ) . '<br>';
					if ( in_array( $st, array( 'missing', 'altered' ), true ) ) {
						$acts .= Ui::confirm_button( 'asem_receipt_pdf_regen', $here, array( 'key' => $p['key'], 'kind' => $kind, 'year' => $year ), Texts::t( 'Rifai dai dati salvati' ), Texts::t( 'Rifare questo PDF dai dati salvati all\'emissione? Il nuovo file si salva cifrato; un file diverso resta messo da parte e l\'operazione viene scritta nel registro azioni.' ) ) . ' ';
					}
				}
				echo '</td><td>' . $acts . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::confirm_button() protegge ogni valore
			}
			echo '</tbody></table>';
			if ( count( $v['problems'] ) > 200 ) {
				echo '<p class="description">' . esc_html( Texts::t( 'Si vedono le prime 200: sistemale e ricarica la pagina per vedere le altre.' ) ) . '</p>';
			}
		}

		echo '<p><a class="button" href="' . esc_url( Receipts::zip_url( $year ) ) . '">' . esc_html( Texts::t( 'Scarica i PDF di questo anno fiscale (ZIP)' ) ) . '</a></p>';
		echo '<p class="description"><strong>' . esc_html( Texts::t( 'I PDF dello ZIP non sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura e cancellalo quando non ti serve più.' ) ) . '</strong></p>';
		echo '<p class="description">' . esc_html( Texts::t( 'Ogni PDF pesa pochi KB e sta nella cartella riservata del sito ({cartella}/receipts), la stessa degli allegati. Se la cartella risultasse raggiungibile da internet, la pagina Copia di sicurezza lo segnala. Conserva sempre anche una copia di sicurezza fuori dal sito.', array( 'cartella' => \AssociazioneSemplice\Attachments::dir() ) ) ) . '</p>';
		Ui::footer();
	}

	/** Azione del modulo «Fissa i prossimi PDF»: un lotto per volta. */
	public static function complete( array $p ): array {
		$year  = max( 0, (int) ( $p['year'] ?? 0 ) );
		$after = max( 0, (int) ( $p['after'] ?? 0 ) );
		$r     = ReceiptFiles::complete( $year > 0 ? $year : null, $after );
		Audit::log( 'receipt.pdfs_fixed', 'year', $year, array( 'done' => $r['done'], 'failed' => $r['failed'], 'manual' => 1 ) );
		$left = ReceiptFiles::pending_count( $year > 0 ? $year : null );
		$msg  = Texts::t( 'Fissati i PDF di {n} ricevute.', array( 'n' => $r['done'] ) );
		if ( $r['failed'] ) {
			$msg .= ' ' . Texts::t( '{n} non si sono potuti fissare (controlla i permessi della cartella dei file riservati o i dati della ricevuta).', array( 'n' => $r['failed'] ) );
		}
		if ( $left > 0 && $r['more'] ) {
			$msg .= ' ' . Texts::t( 'Ne restano ancora {n}: premi di nuovo.', array( 'n' => $left ) );
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
		$msg  = Texts::t( 'Fissate con i dati di oggi {n} ricevute.', array( 'n' => $r['done'] ) );
		if ( $r['failed'] ) {
			$msg .= ' ' . Texts::t( '{n} non si sono potute fissare (hanno perso le righe di incasso o la cartella dei file riservati non è scrivibile).', array( 'n' => $r['failed'] ) );
		}
		if ( $left > 0 && $r['more'] ) {
			$msg .= ' ' . Texts::t( 'Ne restano ancora {n}: premi di nuovo.', array( 'n' => $left ) );
		} elseif ( 0 === $left ) {
			$voided = ReceiptFiles::nodata_voided_count( $year > 0 ? $year : null );
			if ( $voided > 0 ) {
				$msg .= ' ' . Texts::t( 'Le {n} rimaste sono ricevute annullate: restano come sono.', array( 'n' => $voided ) );
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
		return array( Ui::url( 'asem-receipt-check', array( 'year' => (int) ( $p['year'] ?? 0 ) ) ), Texts::t( 'PDF rifatto dai dati salvati all\'emissione. L\'operazione è scritta nel registro azioni.' ) );
	}
}

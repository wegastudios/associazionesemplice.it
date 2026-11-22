<?php
use AssociazioneSemplice\EmployeeRules;
use PHPUnit\Framework\TestCase;

/**
 * Ogni azione «asem_*» registrata (admin-post e admin-ajax, lette dal sorgente) o ha una regola in EmployeeRules,
 * o sta nell'elenco delle eccezioni qui sotto con il motivo. Un'azione nuova senza l'una né l'altra fa fallire il test.
 */
final class EmployeeActionsCoverageTest extends TestCase {

	/** Azioni decise dai dati della richiesta (vedi Employees::action_need()). */
	const DYNAMIC = array( 'asem_export', 'asem_doc', 'asem_quick_cash', 'asem_long_job' );

	/** File dove le chiavi «asem_*» sono campi di dati o parametri di indirizzo, non azioni. */
	const NOT_ACTION_FILES = array( 'WpAllImport.php', 'PaymentService.php', 'WooBridge.php', 'SatispayRules.php', 'StripeApi.php', 'PublicEvents.php', 'RowClean.php' );

	/**
	 * Motivo => azioni. Qui entra solo ciò che non dipende da nessuna delle sedici aree (tutte hanno le loro porte).
	 *
	 * @return array<string,string[]>
	 */
	private static function exception_groups(): array {
		$g = function ( string $names ): array {
			return preg_split( '/\s+/', trim( $names ) );
		};
		return array(
			'Solo amministratore (permesso asem_manage): il dipendente non lo ha mai (compreso il listino dei pacchetti di crediti)' => $g( 'asem_credit_packs asem_add_text asem_audit_free asem_backup asem_backup_restore asem_backup_saved asem_close_year asem_create_pages asem_create_privacy_page asem_create_year asem_delete_campaign asem_delete_language asem_enc_autophrase asem_enc_newkey asem_enc_recover asem_enc_recoverphrase asem_enc_setrecovery asem_enc_start asem_enc_step asem_export_texts asem_import_language asem_import_texts asem_import_woo_payments asem_mail_test asem_newsletter_lists asem_newsletter_sync asem_privacy_anonymize asem_private_check asem_private_move asem_push_reset asem_push_test asem_receipt_nodata_fix asem_receipt_pdf_complete asem_receipt_pdf_regen asem_receipt_verify asem_receipt_zip asem_regen_ical asem_regen_qr asem_release_wp_user asem_reopen_year asem_reset_all asem_reset_limits asem_reset_texts asem_satispay_connect asem_save_acct asem_save_aff_settings asem_save_app asem_save_bank asem_save_campaign asem_save_card asem_save_cert_settings asem_save_comms asem_save_donate asem_save_entity asem_save_ical asem_save_language asem_save_levels asem_save_limits asem_save_look asem_save_newsletter asem_save_payment_settings asem_save_places asem_save_refund_settings asem_save_settings asem_save_sms asem_save_terms asem_save_texts asem_save_wallet_apple asem_save_wallet_google asem_save_woo asem_save_wpai asem_set_board_role asem_set_secretary asem_set_treasurer asem_snapshot_past_years asem_sports asem_test_gateway asem_test_newsletter asem_test_sms asem_wallet_clear asem_wizard_save asem_wizard_skip asem_wpai_clear asem_wpai_process asem_wpai_retry' ),
			'Interfaccia dell\'utente stesso (avvisi chiusi, guida, notifiche del proprio dispositivo)' => $g( 'asem_dismiss_notice asem_guide_dismiss asem_push_subscribe asem_push_unsubscribe' ),
			'Area soci: scarico del socio con il proprio controllo (ricevuta, rendiconto personale, dati personali, tessera Wallet)' => $g( 'asem_privacy_export asem_receipt asem_statement asem_wallet_apple' ),
			'Controlla da sé l\'area (corsi o eventi) di ogni pagina che riceve: chi ha una sola area pubblica solo quelle' => $g( 'asem_publish_pages' ),
				'Aggiornamento dei plugin: lo decide il permesso update_plugins di WordPress (amministratore), non i permessi per area' => $g( 'asem_check_updates' ),
		);
	}

	/** @return array<string,string> azione => motivo */
	private static function exceptions(): array {
		$out = array();
		foreach ( self::exception_groups() as $why => $names ) {
			foreach ( $names as $a ) {
				$out[ $a ] = $why;
			}
		}
		return $out;
	}

	/** @return string[] */
	private static function registered_actions(): array {
		$root = dirname( __DIR__, 2 ) . '/includes';
		$out  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$path = str_replace( '\\', '/', (string) $f );
			if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/includes/Frontend/' ) ) { // l'area soci ha le sue regole (nonce e socio che agisce), non quelle del dipendente
				continue;
			}
			$src = (string) file_get_contents( $path );
			if ( preg_match_all( '/(?:admin_post_|wp_ajax_)(?:nopriv_)?(asem_[a-z0-9_]+)/', $src, $m ) ) {
				$out = array_merge( $out, $m[1] );
			}
			if ( in_array( basename( $path ), self::NOT_ACTION_FILES, true ) ) {
				continue;
			}
			// chiavi di tabelle di azioni; si escludono i lavori programmati: 'asem_x' => array( 'hourly', 900, ... )
			if ( preg_match_all( "/'(asem_[a-z0-9_]+)'\\s*=>(?!\\s*array\\(\\s*'[a-z]*'\\s*,\\s*\\d)/", $src, $m ) ) {
				$out = array_merge( $out, $m[1] );
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	public function test_every_registered_action_is_classified_or_explained(): void {
		$actions = self::registered_actions();
		$this->assertGreaterThan( 100, count( $actions ), 'la scansione dei sorgenti non ha trovato le azioni' );
		$ex      = self::exceptions();
		$missing = array();
		foreach ( $actions as $a ) {
			if ( null !== EmployeeRules::action_need( $a ) || in_array( $a, EmployeeRules::activity_actions(), true ) || in_array( $a, self::DYNAMIC, true ) || isset( $ex[ $a ] ) ) {
				continue;
			}
			$missing[] = $a;
		}
		$this->assertSame( array(), $missing, "Azioni senza regola in EmployeeRules e senza eccezione motivata:\n" . implode( "\n", $missing ) );
	}

	public function test_exceptions_are_explained_and_not_classified(): void {
		foreach ( self::exceptions() as $a => $why ) {
			$this->assertNotSame( '', trim( $why ), $a );
			$this->assertNull( EmployeeRules::action_need( $a ), "$a ha già una regola: togli l'eccezione" );
			$this->assertNotContains( $a, EmployeeRules::activity_actions(), "$a ha già una regola: togli l'eccezione" );
		}
	}
}

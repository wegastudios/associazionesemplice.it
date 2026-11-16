<?php
use AssociazioneSemplice\EmployeeRules;
use PHPUnit\Framework\TestCase;

/**
 * Ogni azione «asem_*» registrata (admin-post e admin-ajax, lette dal sorgente) o ha una regola in EmployeeRules,
 * o sta nell'elenco delle eccezioni qui sotto con il motivo. Un'azione nuova senza l'una né l'altra fa fallire il test.
 */
final class EmployeeActionsCoverageTest extends TestCase {

	/** Azioni decise dai dati della richiesta (vedi Employees::action_need()). */
	const DYNAMIC = array( 'asem_export', 'asem_doc', 'asem_quick_cash' );

	/** File dove le chiavi «asem_*» sono campi di dati o parametri di indirizzo, non azioni. */
	const NOT_ACTION_FILES = array( 'WpAllImport.php', 'PaymentService.php', 'WooBridge.php', 'SatispayRules.php', 'StripeApi.php', 'PublicEvents.php' );

	/**
	 * Motivo => azioni. Qui entra solo ciò che non dipende da nessuno dei sei permessi rimovibili.
	 *
	 * @return array<string,string[]>
	 */
	private static function exception_groups(): array {
		$g = function ( string $names ): array {
			return preg_split( '/\s+/', trim( $names ) );
		};
		return array(
			'Solo amministratore (permesso asem_manage): il dipendente non lo ha mai' => $g( 'asem_add_text asem_audit_free asem_backup asem_backup_restore asem_backup_saved asem_close_year asem_create_pages asem_create_privacy_page asem_create_year asem_delete_campaign asem_delete_language asem_enc_autophrase asem_enc_newkey asem_enc_recover asem_enc_recoverphrase asem_enc_setrecovery asem_enc_start asem_enc_step asem_export_texts asem_import_language asem_import_texts asem_mail_test asem_newsletter_lists asem_newsletter_sync asem_privacy_anonymize asem_private_check asem_private_move asem_push_reset asem_push_test asem_receipt_nodata_fix asem_receipt_pdf_complete asem_receipt_pdf_regen asem_receipt_zip asem_regen_ical asem_regen_qr asem_release_wp_user asem_reopen_year asem_reset_all asem_reset_limits asem_reset_texts asem_satispay_connect asem_save_acct asem_save_aff_settings asem_save_app asem_save_bank asem_save_campaign asem_save_card asem_save_cert_settings asem_save_comms asem_save_donate asem_save_entity asem_save_ical asem_save_language asem_save_levels asem_save_limits asem_save_look asem_save_newsletter asem_save_payment_settings asem_save_places asem_save_refund_settings asem_save_roles asem_save_settings asem_save_sms asem_save_terms asem_save_texts asem_save_wallet_apple asem_save_wallet_google asem_save_woo asem_save_wpai asem_set_board_role asem_set_secretary asem_set_treasurer asem_snapshot_past_years asem_sports asem_test_gateway asem_test_newsletter asem_test_sms asem_wallet_clear asem_wizard_save asem_wizard_skip asem_wpai_clear asem_wpai_process asem_wpai_retry' ),
			'Soci e persone (schede, adesioni, consensi, accessi): fissi per la segreteria e il dipendente' => $g( 'asem_access_approve asem_access_done asem_adult_invite asem_application_approve asem_application_reject asem_consent_set asem_delete_person asem_detach_email asem_detach_phone asem_member_left asem_privacy_consent asem_promote_guest asem_reactivate_member asem_save_person asem_set_membership asem_set_staff asem_suspend_expired asem_suspend_member asem_rules_record' ),
			'Assemblee, verbali e votazioni: vita associativa, non denaro' => $g( 'asem_assembly_attendance asem_assembly_cancel asem_assembly_convene asem_assembly_csv asem_assembly_delete asem_assembly_draft asem_assembly_hold asem_assembly_minutes asem_assembly_proxy_add asem_assembly_proxy_revoke asem_assembly_save asem_minute_delete asem_minute_save asem_poll_announce asem_poll_delete asem_poll_save' ),
			'Certificati medici, affiliazioni, tessere e polizze: anagrafica dei soci, non denaro' => $g( 'asem_aff_csv asem_aff_delete asem_aff_save asem_card_add asem_card_bulk asem_card_delete asem_card_pdf asem_card_update asem_cert_add asem_cert_csv asem_cert_delete asem_cert_file asem_insurance_add asem_insurance_delete asem_policy_add asem_policy_delete' ),
			'Convenzioni con enti e negozi: nessun movimento di denaro' => $g( 'asem_convention_announce asem_convention_archive asem_convention_categories asem_convention_delete asem_convention_save asem_conventions_csv' ),
			'Inventario e prestiti di oggetti: nessun movimento di denaro' => $g( 'asem_item_delete asem_item_file asem_item_save asem_loan_return asem_loan_save' ),
			'Risorse prenotabili (sale, attrezzi): la prenotazione è fissa; l\'incasso è asem_res_pay, che ha la sua regola' => $g( 'asem_res_assign asem_res_block asem_res_book asem_res_cancel asem_res_wait_remove asem_resource_delete asem_resource_save' ),
			'Comunicazioni ai soci e promemoria: nessun permesso rimovibile' => $g( 'asem_broadcast_retry asem_broadcast_send asem_broadcast_test asem_sms_retry asem_reminders_run' ),
			'Documenti dell\'associazione (statuto, moduli): non sono dati economici' => $g( 'asem_document asem_document_delete asem_document_save' ),
			'Interfaccia dell\'utente stesso (avvisi chiusi, guida, notifiche del proprio dispositivo)' => $g( 'asem_dismiss_notice asem_guide_dismiss asem_push_subscribe asem_push_unsubscribe' ),
			'Area soci: scarico del socio con il proprio controllo (ricevuta, rendiconto personale, dati personali, tessera Wallet)' => $g( 'asem_privacy_export asem_receipt asem_statement asem_wallet_apple' ),
			'DA DECIDERE: importazione guidata di soci e, se presente, prima nota' => $g( 'asem_import_apply asem_import_preview asem_import_undo' ),
			'DA DECIDERE: preventivo (previsioni di entrate e uscite, non movimenti)' => $g( 'asem_budget_approve asem_budget_copy asem_budget_csv asem_budget_delete asem_budget_lines asem_budget_reopen asem_budget_save' ),
			'DA DECIDERE: controllo e verifica dei pagamenti online già arrivati' => $g( 'asem_check_payments asem_payment_reviewed' ),
			'DA DECIDERE: listino dei pacchetti di crediti (configurazione, nessun incasso)' => $g( 'asem_credit_packs' ),
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

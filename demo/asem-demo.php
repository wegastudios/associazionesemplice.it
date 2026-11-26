<?php
/**
 * Plugin Name: Demo di Associazione Semplice
 * Description: Protezioni della demo pubblica: nessuna email in uscita, profili e password degli utenti e del socio di prova bloccati, nessun caricamento di file, avviso in ogni pagina.
 */
defined( 'ABSPATH' ) || exit;

// Nessuna email esce da qui (promemoria, link di accesso, comunicazioni): la demo non scrive mai a nessuno.
add_filter( 'pre_wp_mail', '__return_true' );

// Le credenziali sono pubbliche: nessuno deve poterle cambiare.
add_filter( 'allow_password_reset', '__return_false' );
add_action( 'user_profile_update_errors', function ( $errors ) {
	$errors->add( 'asem_demo', 'Nella demo il profilo e la password non si possono cambiare.' );
} );
add_filter( 'send_password_change_email', '__return_false' );
add_filter( 'send_email_change_email', '__return_false' );
add_action( 'password_reset', function () {
	wp_die( 'Nella demo la password non si può cambiare.', '', array( 'response' => 403 ) );
} );
// Soci (accesso proprio, senza utente WordPress): la password e il recupero sono bloccati, come per gli utenti WordPress. Nessuna email esce comunque.
add_action( 'template_redirect', function () {
	if ( isset( $_GET['asem_member_login'], $_POST['asem_ml_do'] ) && in_array( sanitize_key( wp_unslash( $_POST['asem_ml_do'] ) ), array( 'password', 'recover' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_die( 'Nella demo la password non si può cambiare né recuperare.', '', array( 'response' => 403, 'back_link' => true ) );
	}
}, 0 );
add_action( 'init', function () {
	if ( ! is_user_logged_in() || empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( isset( $_POST['pass1'] ) || isset( $_POST['pass2'] ) || isset( $_POST['new_password'] ) || isset( $_POST['password'] ) && isset( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_die( 'Nella demo la password non si può cambiare.', '', array( 'response' => 403, 'back_link' => true ) );
	}
} );

// La licenza della demo è nostra: chi ha tutti i permessi del plugin non la vede né la cambia (la chiave si nasconde da ogni pagina del pannello).
add_action( 'admin_post_asem_save_license', function () {
	wp_die( 'Nella demo la licenza non si cambia.', '', array( 'response' => 403, 'back_link' => true ) );
}, 0 );
// Nessuno scarico della copia di sicurezza dalla demo (né la copia nuova né quelle già salvate): decisione del titolare del 16/11/2026.
foreach ( array( 'asem_backup', 'asem_backup_saved' ) as $asem_demo_blocked ) {
	add_action( 'admin_post_' . $asem_demo_blocked, function () {
		wp_die( 'Nella demo questa operazione è bloccata.', '', array( 'response' => 403, 'back_link' => true ) );
	}, 0 );
}
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['page'] ) || ! class_exists( '\AssociazioneSemplice\License' ) ) { // solo le pagine del pannello: mai gli scarichi (come la copia di sicurezza)
		return;
	}
	$k = (string) \AssociazioneSemplice\License::key();
	if ( strlen( $k ) < 8 ) {
		return;
	}
	ob_start( function ( $html ) use ( $k ) {
		return str_replace( array( $k, esc_attr( $k ), rawurlencode( $k ) ), '••••••••••••', (string) $html );
	} );
}, 0 );
// Nessuna chiave vera in una demo pubblica: avviso nelle schermate dei pagamenti e delle impostazioni.
add_action( 'admin_notices', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( in_array( $page, array( 'asem-settings', 'asem-payments', 'asem-tech', 'asem-wpai' ), true ) ) {
		echo '<div class="notice notice-warning"><p><strong>Non inserire chiavi vere</strong> (Stripe, PayPal, Brevo, Google…): la demo è pubblica e le chiavi resterebbero salvate fino all\'azzeramento di notte.</p></div>';
	}
} );

// La segreteria entra direttamente nella Bacheca del plugin (il profilo non serve a niente qui).
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( $user instanceof WP_User && array_intersect( array( 'asem_secretary', 'asem_demo_admin' ), (array) $user->roles ) && ( '' === (string) $requested || false !== strpos( (string) $requested, 'wp-admin/' ) && false === strpos( (string) $requested, 'page=' ) ) ) {
		return admin_url( 'admin.php?page=asem' );
	}
	return $to;
}, 20, 3 );

// Nessun file caricato dai visitatori (ricevute, allegati, immagini).
add_filter( 'wp_check_filetype_and_ext', function ( $data ) {
	return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
}, 99 );

// Nell'archivio documenti e in ogni punto che passa da Documents::prepare_upload (inventario, certificati, rimborsi) non si accetta alcun file: decisione del titolare.
add_filter( 'asem_documents_uploads_enabled', '__return_false', 99 );

// Protezioni più strette: nella demo (accessi pubblici) non si salvano chiavi, conti o contatti propri, non si tocca la chiave dei file né si ripristinano o azzerano i dati,
// e nessun file arriva sul server. Valgono solo qui: il plugin dei clienti non cambia. Le azioni si fermano per nome, prima del gestore del plugin (priorità 0).
$asem_demo_blocked = array(
	'Nella demo questa impostazione è bloccata.' => array(
		'asem_save_payment_settings', 'asem_satispay_connect', 'asem_save_bank', 'asem_save_donate', 'asem_save_campaign', 'asem_save_woo',
		'asem_save_sms', 'asem_save_newsletter', 'asem_save_places', 'asem_save_wallet_apple', 'asem_save_wallet_google', 'asem_save_app', 'asem_save_wpai', 'asem_push_test',
		'asem_save_limits', 'asem_audit_free', 'asem_save_license', 'asem_set_staff', 'asem_set_secretary', 'asem_set_treasurer', 'asem_reset_limits',
	),
	'Nella demo questa operazione è bloccata.' => array(
		'asem_enc_start', 'asem_enc_step', 'asem_enc_recover', 'asem_enc_recoverphrase', 'asem_enc_newkey', 'asem_enc_setrecovery', 'asem_enc_autophrase',
		'asem_private_move', 'asem_receipt_pdf_regen', 'asem_receipt_nodata_fix', 'asem_receipt_pdf_complete', 'asem_receipt_zip', 'asem_payment_refund',
		'asem_test_gateway', 'asem_test_sms', 'asem_test_newsletter', 'asem_mail_test', 'asem_send_notice', 'asem_send_reminders', 'asem_sms_retry', 'asem_wpai_process', 'asem_wpai_retry', 'asem_wpai_clear',
		'asem_reset_private_files', 'asem_reset_texts', 'asem_update_account', 'asem_update_api', 'asem_update_check', 'asem_update_hash', 'asem_update_session',
	),
	'Nella demo il ripristino di una copia è bloccato.' => array( 'asem_backup_restore' ),
	'Nella demo l\'importazione da file è bloccata: i dati che vedi sono di esempio.' => array( 'asem_import_preview', 'asem_import_apply', 'asem_import_person', 'asem_import_tx', 'asem_import_undo', 'asem_import_continue', 'asem_import_stop', 'asem_import_language', 'asem_import_texts', 'asem_tec_import' ),
	'Nella demo i dati non si azzerano da qui: si azzerano da soli ogni notte.' => array( 'asem_reset_all' ),
);
// Elenco delle azioni PERMESSE (non solo di quelle vietate): ogni azione con prefisso asem_ che arriva da admin-post.php o admin-ajax.php e non è qui sotto si ferma, quindi un'azione
// nuova di una versione futura del plugin resta chiusa finché non viene riesaminata e aggiunta. Le azioni vietate hanno ciascuna il proprio messaggio.
$asem_demo_allowed = array_flip( array(
		'asem_access', 'asem_access_activities', 'asem_access_approve', 'asem_access_done', 'asem_access_nonce', 'asem_access_requests', 'asem_access_save',
		'asem_account', 'asem_act_', 'asem_activate', 'asem_activate_', 'asem_activate_go', 'asem_activated_at', 'asem_activity', 'asem_activity_created',
		'asem_activity_delete_blockers', 'asem_activity_deleted', 'asem_activity_errors', 'asem_activity_form_rows', 'asem_activity_saved',
		'asem_activity_updated', 'asem_add_account', 'asem_add_attachment', 'asem_add_dates', 'asem_add_expense', 'asem_add_guest', 'asem_add_session',
		'asem_add_text', 'asem_admin_groups', 'asem_admin_hidden_pages', 'asem_admin_init', 'asem_admin_menu_pages', 'asem_admin_only', 'asem_admin_parents',
		'asem_admin_post_classes', 'asem_admin_sections', 'asem_adult_invite', 'asem_adult_invited', 'asem_aff_counts', 'asem_aff_csv', 'asem_aff_delete',
		'asem_aff_reminders', 'asem_aff_save', 'asem_after_card', 'asem_amount', 'asem_ap_', 'asem_ap_pay_', 'asem_application_approve',
		'asem_application_reject', 'asem_apply_go', 'asem_apply_pay', 'asem_area_section', 'asem_areas', 'asem_assembly_', 'asem_assembly_attendance',
		'asem_assembly_cancel', 'asem_assembly_convene', 'asem_assembly_csv', 'asem_assembly_delete', 'asem_assembly_draft', 'asem_assembly_hold',
		'asem_assembly_minutes', 'asem_assembly_proxy_add', 'asem_assembly_proxy_revoke', 'asem_assembly_save', 'asem_attachment', 'asem_attachment_',
		'asem_attachments_added', 'asem_attendance_matrix_save', 'asem_attendance_save', 'asem_audit_log', 'asem_backup', 'asem_backup_documents',
		'asem_backup_downloaded', 'asem_backup_last', 'asem_backup_page_after', 'asem_backup_restore_documents', 'asem_backup_saved', 'asem_bad_payload',
		'asem_bad_signature', 'asem_balance_snaps', 'asem_bank_mail_', 'asem_boh', 'asem_book', 'asem_book_', 'asem_book_for', 'asem_bookdesk_date',
		'asem_bookdesk_item', 'asem_bookdesk_items', 'asem_booking_errors', 'asem_broadcast_batch', 'asem_broadcast_retry', 'asem_broadcast_send',
		'asem_broadcast_test', 'asem_broadcasts', 'asem_budget_approve', 'asem_budget_copy', 'asem_budget_csv', 'asem_budget_delete', 'asem_budget_lines',
		'asem_budget_reopen', 'asem_budget_save', 'asem_budget_year_', 'asem_cancel_booking', 'asem_cancel_enrollment', 'asem_cancel_session', 'asem_card',
		'asem_card_add', 'asem_card_bulk', 'asem_card_delete', 'asem_card_extra', 'asem_card_number', 'asem_card_page_end', 'asem_card_page_title',
		'asem_card_pdf', 'asem_card_pdf_', 'asem_card_salt', 'asem_card_settings', 'asem_card_switches', 'asem_card_update', 'asem_cash_count', 'asem_cat',
		'asem_category', 'asem_cents', 'asem_cert_add', 'asem_cert_counts', 'asem_cert_csv', 'asem_cert_delete', 'asem_cert_file', 'asem_cert_file_',
		'asem_cert_reminders', 'asem_charge', 'asem_check_payments', 'asem_check_pending_payments', 'asem_checkin', 'asem_checkin_extra',
		'asem_close_account', 'asem_close_year', 'asem_collect', 'asem_comms_page_title', 'asem_comms_sections', 'asem_comms_settings',
		'asem_consent_changed', 'asem_consent_set', 'asem_convention_announce', 'asem_convention_archive', 'asem_convention_categories',
		'asem_convention_delete', 'asem_convention_save', 'asem_conventions_csv', 'asem_create_pages', 'asem_create_privacy_page', 'asem_create_year',
		'asem_credit', 'asem_credit_adjust', 'asem_credit_packs', 'asem_credit_sell', 'asem_credit_status', 'asem_credits_', 'asem_dashboard_alerts',
		'asem_dashboard_cards', 'asem_date', 'asem_day', 'asem_db_version', 'asem_delete_activities', 'asem_delete_activity', 'asem_delete_booking',
		'asem_delete_campaign', 'asem_delete_language', 'asem_delete_person', 'asem_description', 'asem_detach_email', 'asem_detach_phone',
		'asem_direct_gateways', 'asem_discrepancy_approve', 'asem_discrepancy_revoke', 'asem_dismiss', 'asem_dismiss_notice', 'asem_dismissed_notices',
		'asem_doc', 'asem_document', 'asem_document_delete', 'asem_document_save', 'asem_donate_campaigns', 'asem_donate_next_id', 'asem_door_cash',
		'asem_duplicate_activity', 'asem_edit_own_profile', 'asem_email', 'asem_emp_denied', 'asem_emp_from', 'asem_emp_to', 'asem_employee',
		'asem_employee_create', 'asem_employee_invite', 'asem_employee_save', 'asem_enroll', 'asem_enroll_variant', 'asem_entity_page_end',
		'asem_entity_page_title', 'asem_entity_rows', 'asem_entity_sections', 'asem_entity_values', 'asem_ev_approval', 'asem_ev_email', 'asem_ev_guests',
		'asem_ev_hide_cancelled', 'asem_ev_max_seats', 'asem_ev_phone', 'asem_event_booking', 'asem_event_contacts', 'asem_event_custom_layout',
		'asem_event_data', 'asem_event_options', 'asem_event_request_approve', 'asem_event_request_approve_group', 'asem_event_request_reject', 'asem_event_requests', 'asem_event_staff_add',
		'asem_event_staff_cash', 'asem_event_staff_remove', 'asem_eventi', 'asem_evento', 'asem_evento_cat', 'asem_evq_cf_', 'asem_evq_em_', 'asem_evq_ip_',
		'asem_evreq', 'asem_evreq_go', 'asem_expense', 'asem_expense_default_person', 'asem_expense_form_rows', 'asem_expense_fund_attach',
		'asem_expense_fund_undo', 'asem_expense_funds', 'asem_expense_funds_', 'asem_expense_people', 'asem_expense_person', 'asem_expense_recorded',
		'asem_expense_saved_note', 'asem_export', 'asem_export_texts', 'asem_fa_', 'asem_fa_m_', 'asem_feature_available', 'asem_feature_switches',
		'asem_feature_unavailable_html', 'asem_feature_unavailable_message', 'asem_features_suspended', 'asem_fee_gateways', 'asem_file_crypt_certs_check',
		'asem_file_crypt_migrate', 'asem_file_crypt_migrate_certs', 'asem_file_keys', 'asem_file_license_code', 'asem_file_migration',
		'asem_file_migration_certs', 'asem_file_phrase_changed', 'asem_file_sources', 'asem_first_access', 'asem_first_go', 'asem_first_name',
		'asem_fiscal_start_month', 'asem_fiscal_year_closures', 'asem_fivepm_add', 'asem_fivepm_delete', 'asem_fivepm_report', 'asem_fivepm_settings',
		'asem_front_accept_for', 'asem_front_accept_rules', 'asem_front_add_guest', 'asem_front_bank_email', 'asem_front_book', 'asem_front_book_group', 'asem_front_cancel_booking',
		'asem_front_checkin', 'asem_front_checkin_scan', 'asem_front_collect', 'asem_front_confirm_booking', 'asem_front_consent', 'asem_front_course_coins', 'asem_front_course_leave', 'asem_front_course_request', 'asem_front_credit_cancel',
		'asem_front_credit_charge', 'asem_front_credit_pay', 'asem_front_door', 'asem_front_door_group', 'asem_front_expense', 'asem_front_expense_docs',
		'asem_front_decline_booking', 'asem_front_expense_fields', 'asem_front_group', 'asem_front_init', 'asem_front_new_member', 'asem_front_notice', 'asem_front_pay',
		'asem_front_profile', 'asem_front_proxy', 'asem_front_proxy_revoke', 'asem_front_refund', 'asem_front_refund_cancel', 'asem_front_regen_qr', 'asem_front_request',
		'asem_front_res_book', 'asem_front_res_cancel', 'asem_front_res_gate', 'asem_front_res_unwait', 'asem_front_res_wait', 'asem_front_transfer_booking',
		'asem_front_vote', 'asem_front_waitlist_join', 'asem_front_waitlist_leave', 'asem_fund_create', 'asem_fund_deposit', 'asem_fund_release',
		'asem_fund_settle', 'asem_fund_settle_', 'asem_fy_auto', 'asem_gate_html', 'asem_generate_sessions', 'asem_guide_dismiss', 'asem_guide_dismissed',
		'asem_guide_options', 'asem_host', 'asem_ical', 'asem_ical_salt', 'asem_ics', 'asem_import_', 'asem_import_1_', 'asem_import_apply',
		'asem_import_language', 'asem_import_undo', 'asem_income',
		'asem_incomplete_card_items', 'asem_install_id', 'asem_install_url', 'asem_insurance_add', 'asem_insurance_delete', 'asem_integrations_list',
		'asem_integrations_top', 'asem_item_', 'asem_item_delete', 'asem_item_file', 'asem_item_file_', 'asem_item_save', 'asem_key', 'asem_label',
		'asem_lang_packs', 'asem_last_name', 'asem_ledger_row_actions', 'asem_lesson_exception', 'asem_lesson_restore', 'asem_levels_saved',
		'asem_license_bad', 'asem_license_check', 'asem_license_first_seen', 'asem_license_free_url', 'asem_license_key', 'asem_license_portal_url',
		'asem_license_resync', 'asem_license_state', 'asem_license_unavailable', 'asem_limits_defs', 'asem_loan_return', 'asem_loan_save', 'asem_mail_log',
		'asem_mail_test', 'asem_manage', 'asem_manage_event', 'asem_matricola', 'asem_matricola_last', 'asem_member', 'asem_member_access_forgotten',
		'asem_member_book_snapshot', 'asem_member_book_snapshot_fields', 'asem_member_challenges', 'asem_member_left', 'asem_member_login',
		'asem_member_sessions', 'asem_member_type', 'asem_mese', 'asem_message', 'asem_method', 'asem_minute_delete', 'asem_minute_save',
		'asem_minutes_number', 'asem_missing_items', 'asem_ml', 'asem_ml_bye_', 'asem_ml_cf_', 'asem_ml_do', 'asem_ml_dummy', 'asem_ml_ip_', 'asem_ml_m_',
		'asem_ml_reset', 'asem_month', 'asem_my_sections', 'asem_newsletter_last', 'asem_newsletter_lists', 'asem_newsletter_providers',
		'asem_newsletter_sync', 'asem_no_person', 'asem_not_found', 'asem_notify_activity', 'asem_notify_email', 'asem_ok', 'asem_online_account_names',
		'asem_online_dues', 'asem_online_methods', 'asem_online_payment_pending', 'asem_operate', 'asem_page_areas', 'asem_page_defs', 'asem_pages',
		'asem_pay', 'asem_payment', 'asem_payment_default_labels', 'asem_payment_methods', 'asem_payment_note', 'asem_payment_refunded',
		'asem_payment_reviewed', 'asem_payment_validate', 'asem_payments_service', 'asem_pe_lock_', 'asem_people', 'asem_person', 'asem_person_anonymized',
		'asem_person_context', 'asem_person_receipts', 'asem_phone', 'asem_policy_add', 'asem_policy_delete', 'asem_poll_', 'asem_poll_announce',
		'asem_poll_delete', 'asem_poll_save', 'asem_portal_account_url', 'asem_portal_domain', 'asem_portal_is_local', 'asem_portal_license_key',
		'asem_portal_support_url', 'asem_portal_update_api', 'asem_portal_updates', 'asem_privacy_anonymize', 'asem_privacy_consent', 'asem_privacy_cookies',
		'asem_privacy_export', 'asem_privacy_export_', 'asem_privacy_processors', 'asem_privacy_transfer_note', 'asem_privacy_uses', 'asem_private_check',
		'asem_private_check_busy', 'asem_private_folder_check', 'asem_pro_db_version', 'asem_pro_url', 'asem_probe', 'asem_promote_guest',
		'asem_public_event_slug', 'asem_public_events_backfill', 'asem_public_events_rewrite', 'asem_purge_audit', 'asem_purge_backups',
		'asem_purge_enrollments', 'asem_purge_member_sessions', 'asem_push', 'asem_push_hosts', 'asem_push_reset', 'asem_push_subscribe',
		'asem_push_unsubscribe', 'asem_quick_cash', 'asem_quick_enroll', 'asem_quick_person', 'asem_reactivate_member', 'asem_receipt', 'asem_receipt_',
		'asem_receipt_email', 'asem_receipt_k1', 'asem_receipt_k2', 'asem_receipt_number', 'asem_receipt_pdfs_backfill', 'asem_receipt_pdfs_batch',
		'asem_receipt_replace', 'asem_receipt_zip_', 'asem_ref', 'asem_refund_', 'asem_refund_approve', 'asem_refund_cancel', 'asem_refund_file',
		'asem_refund_file_', 'asem_refund_pay', 'asem_refund_reject', 'asem_refund_request', 'asem_refunds_csv', 'asem_regen_ical', 'asem_regen_qr',
		'asem_register_member', 'asem_registration_deleted', 'asem_release_holds', 'asem_release_resources', 'asem_release_wp_user', 'asem_reminders_last',
		'asem_reminders_run', 'asem_remove_attachment', 'asem_render_view', 'asem_reopen_account', 'asem_reopen_year', 'asem_res', 'asem_res_',
		'asem_res_assign', 'asem_res_block', 'asem_res_book', 'asem_res_cancel', 'asem_res_pay', 'asem_res_wait_remove', 'asem_reservation_active',
		'asem_reservation_receipt', 'asem_reset_limits', 'asem_reset_private_files', 'asem_reset_texts', 'asem_resource_delete', 'asem_resource_reminders',
		'asem_resource_save', 'asem_rest_routes', 'asem_restricted', 'asem_result', 'asem_ret', 'asem_rules_record', 'asem_runts_workers_count', 'asem_s',
		'asem_save_acct', 'asem_save_activity', 'asem_save_aff_settings', 'asem_save_card', 'asem_save_cert_settings', 'asem_save_comms', 'asem_save_entity',
		'asem_save_expense', 'asem_save_group_cash', 'asem_save_ical', 'asem_save_income', 'asem_save_language', 'asem_save_levels', 'asem_save_license',
		'asem_save_look', 'asem_save_person', 'asem_save_refund_settings', 'asem_save_settings', 'asem_save_terms', 'asem_save_texts', 'asem_save_transfer',
		'asem_scheduled_jobs', 'asem_schema_tables', 'asem_secret_keys', 'asem_secretary', 'asem_send_notice', 'asem_send_reminders', 'asem_session',
		'asem_session_errors', 'asem_set_board_role', 'asem_set_membership', 'asem_set_secretary', 'asem_set_staff', 'asem_set_treasurer', 'asem_settings',
		'asem_settings_clean', 'asem_settings_defaults', 'asem_settings_page_end', 'asem_shortcode_views', 'asem_site_id', 'asem_sms_credits',
		'asem_sms_hourly', 'asem_sms_providers', 'asem_sms_queue', 'asem_sms_retry', 'asem_snapshot_past_years', 'asem_sport_list', 'asem_sports',
		'asem_staff', 'asem_statement', 'asem_statement_', 'asem_statement_backfill', 'asem_statement_notes', 'asem_statement_notes_',
		'asem_statement_snapshot', 'asem_stop_renewal', 'asem_suspend_expired', 'asem_suspend_member', 'asem_suspended', 'asem_tabella_finta',
		'asem_tax_code', 'asem_tec_import', 'asem_test_gateway', 'asem_test_lock', 'asem_test_newsletter', 'asem_test_sms', 'asem_text_html',
		'asem_text_plain', 'asem_text_template', 'asem_texts', 'asem_texts_admin_buffer', 'asem_texts_cat_', 'asem_texts_flush', 'asem_ticket', 'asem_todo',
		'asem_transactions', 'asem_transfer_booking', 'asem_treasurer', 'asem_treasurer_sections', 'asem_tx_void_result', 'asem_tx_voided', 'asem_type',
		'asem_update_account', 'asem_update_api', 'asem_update_check', 'asem_update_hash', 'asem_update_latest', 'asem_update_not_newer',
		'asem_update_session', 'asem_update_unavailable', 'asem_update_unsigned', 'asem_vapid', 'asem_vat_input', 'asem_vat_row', 'asem_view_activity',
		'asem_view_available', 'asem_view_participants', 'asem_view_payments', 'asem_view_person', 'asem_vista', 'asem_void_tx', 'asem_voting_right_level',
		'asem_voting_right_sql', 'asem_waitlist', 'asem_waitlist_remove', 'asem_walk_in', 'asem_wallet_apple', 'asem_wallet_apple_', 'asem_wallet_clear',
		'asem_warnings', 'asem_watch_csv', 'asem_watch_month_end', 'asem_watch_snapshot', 'asem_watch_yearend_mail', 'asem_week', 'asem_when',
		'asem_wizard_done', 'asem_wizard_entity_intro', 'asem_wizard_entity_rows', 'asem_wizard_feature_conditions', 'asem_wizard_levels_rows',
		'asem_wizard_module_extra', 'asem_wizard_more_rows', 'asem_wizard_next', 'asem_wizard_own_step', 'asem_wizard_page_if',
		'asem_wizard_payment_choices', 'asem_wizard_redirect', 'asem_wizard_save', 'asem_wizard_skip', 'asem_wizard_status', 'asem_wizard_steps_entity',
		'asem_wizard_steps_payments', 'asem_wizard_values', 'asem_wpai_clear', 'asem_wpai_process', 'asem_wpai_retry', 'asem_x', 'asem_y',
		'asem_year_close_blockers', 'asem_year_close_confirm', 'asem_year_close_form', 'asem_year_closed', 'asem_year_closing', 'asem_year_snapshot_lines',
		'asem_yearend_alert', 'asem_years_page_after', 'asem_zz_action'

) );
add_action( 'admin_init', function () use ( $asem_demo_blocked, $asem_demo_allowed ) {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
	if ( ! in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		return;
	}
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 0 !== strpos( $action, 'asem_' ) ) {
		return;
	}
	foreach ( $asem_demo_blocked as $msg => $names ) {
		if ( in_array( $action, $names, true ) ) {
			wp_die( esc_html( $msg ), '', array( 'response' => 403, 'back_link' => true ) );
		}
	}
	if ( ! isset( $asem_demo_allowed[ $action ] ) ) {
		wp_die( 'Nella demo questa azione non è disponibile.', '', array( 'response' => 403, 'back_link' => true ) );
	}
}, 0 );
// Posta in uscita già spenta (pre_wp_mail in cima). Seconda barriera: dal web nessuna richiesta esce verso altri siti (SMS, pagamenti, notifiche), salvo questo sito e il portale. I comandi da terminale (aggiornamenti) passano.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || false !== $pre ) {
		return $pre;
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( '' === $host || $host === $own || 'associazionesemplice.it' === $host || 'localhost' === $host || '127.0.0.1' === $host ) {
		return $pre;
	}
	return new WP_Error( 'asem_demo_http', 'Nella demo le richieste verso altri siti sono bloccate.' );
}, 1, 3 );
// Contatti e dati di pagamento che il pubblico vede sul sito: dalle impostazioni generali (via web) restano quelli della demo. I comandi da terminale (provisioning) passano.
add_filter( 'pre_update_option_asem_settings', function ( $new, $old ) {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_array( $new ) || ! is_array( $old ) ) {
		return $new;
	}
	foreach ( array( 'secretary_phone', 'secretary_email', 'whatsapp_button', 'privacy_url', 'privacy_email', 'rules_url', 'apply_email', 'donate_enabled', 'donate_paypal', 'donate_amounts', 'donate_purpose', 'places_key', 'bank_enabled', 'bank_title', 'bank_note', 'bank_in_reminders', 'bank_accounts' ) as $k ) {
		if ( array_key_exists( $k, $old ) ) {
			$new[ $k ] = $old[ $k ];
		} else {
			unset( $new[ $k ] );
		}
	}
	return $new;
}, 10, 2 );
// Nessun file caricato: ogni invio con un file si ferma presto, prima dei gestori (anche quelli che leggono il file senza passare da WordPress); anche l'importazione da file è bloccata.
add_action( 'init', function () {
	if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_FILES ) || ! is_array( $_FILES ) ) {
		return;
	}
	$any = false;
	foreach ( $_FILES as $f ) {
		if ( is_array( $f ) && isset( $f['name'] ) ) {
			if ( is_array( $f['name'] ) ) {
				array_walk_recursive( $f['name'], function ( $v ) use ( &$any ) { // un campo può essere un elenco di file
					$any = $any || '' !== (string) $v;
				} );
			} elseif ( '' !== (string) $f['name'] ) {
				$any = true;
			}
		}
	}
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $any && 0 === strpos( $action, 'asem_import_' ) ) {
		wp_die( 'Nella demo l\'importazione da file è bloccata: i dati che vedi sono di esempio.', '', array( 'response' => 403, 'back_link' => true ) );
	}
	if ( $any ) {
		wp_die( 'Nella demo non si possono caricare file.', '', array( 'response' => 403, 'back_link' => true ) );
	}
}, 0 );
// Seconda barriera: nessun caricamento passa dalle funzioni di WordPress.
add_filter( 'wp_handle_upload_prefilter', function ( $file ) {
	$file['error'] = 'Nella demo non si possono caricare file.';
	return $file;
} );
add_filter( 'wp_handle_sideload_prefilter', function ( $file ) {
	$file['error'] = 'Nella demo non si possono caricare file.';
	return $file;
} );
// La procedura guidata ha una seconda strada per cambiare la frase dei file: i suoi campi vengono ignorati.
add_action( 'init', function () {
	if ( isset( $_POST['action'] ) && 'asem_wizard_save' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		unset( $_POST['file_phrase'], $_POST['file_phrase2'], $_REQUEST['file_phrase'], $_REQUEST['file_phrase2'] );
	}
}, 0 );
// Il server della demo è lo stesso del portale: nelle pagine del plugin i percorsi assoluti del server si nascondono.
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'asem' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	ob_start( function ( $html ) {
		return preg_replace( '#/(?:home|home2|var/www|srv|opt)/[A-Za-z0-9_.-]+#', '/…', (string) $html );
	} );
}, 1 );

// La demo resta fuori dai motori di ricerca: WordPress emette noindex e un robots.txt che vieta tutto, e ogni risposta porta l'intestazione.
add_filter( 'pre_option_blog_public', '__return_zero' );
add_action( 'send_headers', function () {
	header( 'X-Robots-Tag: noindex, nofollow' );
}, 0 );
add_action( 'login_init', function () {
	if ( ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
} );
add_action( 'admin_init', function () {
	if ( ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
} );
// Niente indicizzazione e un avviso ben visibile.
add_filter( 'wp_robots', function ( $robots ) {
	return array( 'noindex' => true, 'nofollow' => true );
} );
$asem_demo_note = 'Questa è una <strong>demo pubblica</strong> di Associazione Semplice: i dati sono inventati, non vengono inviate email e tutto si azzera ogni notte alle 4.';
// Uscire dalla demo: un collegamento sempre in vista (per cambiare accesso, da segreteria a socio e viceversa) che riporta alla pagina iniziale.
$asem_demo_cta = ' · <a href="https://associazionesemplice.it/free" style="color:inherit;font-weight:700">Ottieni la licenza gratuita</a>';
$asem_demo_kses = array( 'strong' => array(), 'a' => array( 'href' => array(), 'style' => array() ), 'form' => array( 'method' => array(), 'action' => array(), 'style' => array() ), 'input' => array( 'type' => array(), 'name' => array(), 'value' => array() ), 'button' => array( 'type' => array(), 'style' => array() ) );
$asem_demo_exit = function () use ( $asem_demo_cta ): string {
	if ( ! is_user_logged_in() && class_exists( '\AssociazioneSemplice\MemberSession' ) && null !== \AssociazioneSemplice\MemberSession::session_person() ) { // il socio entra con l'accesso proprio, non è un utente WordPress
		$f = '<form method="post" action="' . esc_url( \AssociazioneSemplice\Frontend\MemberLoginPage::url() ) . '" style="display:inline;margin:0"><input type="hidden" name="asem_ml_do" value="logout"><input type="hidden" name="_csrf" value="' . esc_attr( \AssociazioneSemplice\MemberSession::csrf( 'account' ) ) . '"><button type="submit" style="background:none;border:0;padding:0;color:inherit;font:inherit;font-weight:700;text-decoration:underline;cursor:pointer">Esci</button></form>'; // il socio esce con un invio vero: l'uscita dell'area soci vuole il suo segnale di sicurezza
		return ' Sei entrato come <strong>socio</strong> · ' . $f . $asem_demo_cta;
	}
	if ( ! is_user_logged_in() ) {
		return ' <a href="' . esc_url( wp_login_url( home_url( '/' ) ) ) . '" style="color:inherit;font-weight:700">Entra</a>' . $asem_demo_cta;
	}
	$u = wp_get_current_user();
	return ' Sei entrato come <strong>' . esc_html( $u->user_login ) . '</strong> · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '" style="color:inherit;font-weight:700">Esci</a>' . $asem_demo_cta;
};
// Nel pannello l'avviso è una riga sola e si può chiudere (da telefono ne occupava cinque in cima a ogni pagina); la chiusura vale finché la scheda del browser resta aperta.
add_action( 'admin_notices', function () use ( $asem_demo_cta, $asem_demo_exit, $asem_demo_kses ) {
	$exit = str_replace( $asem_demo_cta, '', $asem_demo_exit() ); // l'invito alla licenza gratuita sta già nella barra del sito
	echo '<div id="asem-demo-note" class="notice notice-info is-dismissible"><p><strong>Demo pubblica</strong>: dati inventati, azzerati ogni notte.' . wp_kses( $exit, $asem_demo_kses ) . '</p></div>';
	echo '<script>(function(){var n=document.getElementById("asem-demo-note");try{if(sessionStorage.getItem("asemDemoNote")){n.style.display="none";}}catch(e){}document.addEventListener("click",function(e){if(e.target.closest&&e.target.closest("#asem-demo-note .notice-dismiss")){try{sessionStorage.setItem("asemDemoNote","1");}catch(x){}}});})();</script>';
} );
add_action( 'wp_footer', function () use ( $asem_demo_note, $asem_demo_exit, $asem_demo_kses ) {
	echo '<style>body{padding-bottom:48px}.asem-wa-float{bottom:64px!important}</style><div style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#1f218c;color:#fff;padding:8px 16px;font:14px/1.4 system-ui,sans-serif;text-align:center">' . wp_kses( $asem_demo_note, array( 'strong' => array() ) ) . wp_kses( $asem_demo_exit(), $asem_demo_kses ) . '</div>';
} );
// Anche nella barra in alto un «Esci» diretto, senza aprire il menu del profilo.
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( is_user_logged_in() ) {
		$bar->add_node( array( 'id' => 'asem-demo-exit', 'title' => 'Esci dalla demo', 'href' => wp_logout_url( home_url( '/' ) ), 'parent' => 'top-secondary' ) );
	}
}, 5 );
// Il logout non chiede conferma: nella demo si passa spesso da un ruolo all'altro.
add_action( 'login_form_logout', function () {
	if ( isset( $_REQUEST['_wpnonce'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	wp_logout();
	wp_safe_redirect( home_url( '/' ) );
	exit;
}, 1 );

// Confronto tra le versioni: un pulsante che apre il popup a schede. Lo script e il contenuto li dà il portale (stessa fonte di /confronto), quindi sono sempre aggiornati.
add_shortcode( 'asem_demo_confronto', function () {
	return '<p class="has-text-align-center"><a class="wp-block-button__link wp-element-button" href="https://associazionesemplice.it/confronto" data-confronto>Confronta le versioni del plugin</a></p>'
		. '<script src="https://associazionesemplice.it/assets/confronto.js" defer></script>';
} );

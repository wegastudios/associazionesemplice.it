<?php
use AssociazioneSemplice\EmployeeRules;
use PHPUnit\Framework\TestCase;

final class EmployeeRulesTest extends TestCase {

	public function test_the_removable_permissions_are_exactly_these_sixteen_areas(): void {
		$this->assertSame(
			array( 'people', 'sensitive', 'health', 'courses', 'events', 'resources', 'collect', 'expenses', 'refunds', 'funds', 'comms', 'documents', 'assemblies', 'inventory', 'conventions', 'tools' ),
			EmployeeRules::keys()
		);
		$this->assertCount( 16, EmployeeRules::labels() );
		foreach ( EmployeeRules::keys() as $k ) {
			$this->assertTrue( EmployeeRules::is_removable( $k ) );
			$this->assertNotSame( '', EmployeeRules::label( $k ) );
		}
		foreach ( array( 'asem_manage', 'asem_operate', 'members', 'settings', 'roles', '' ) as $other ) {
			$this->assertFalse( EmployeeRules::is_removable( $other ), $other );
		}
	}

	public function test_every_area_has_its_own_label_and_none_is_repeated(): void {
		$keys   = EmployeeRules::keys();
		$labels = array_values( EmployeeRules::labels() );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'chiavi doppie' );
		$this->assertSame( count( $labels ), count( array_unique( $labels ) ), 'etichette doppie' );
		foreach ( $labels as $l ) {
			$this->assertSame( trim( $l ), $l );
			$this->assertNotSame( '', $l );
		}
	}

	public function test_enforced_and_pending_areas_split_the_list_without_overlap(): void {
		$enforced = EmployeeRules::enforced_keys();
		$pending  = EmployeeRules::pending_keys();
		$this->assertSame( array( 'people', 'sensitive', 'health', 'courses', 'events', 'resources', 'collect', 'expenses', 'refunds', 'funds', 'comms', 'documents', 'assemblies', 'inventory', 'conventions', 'tools' ), $enforced );
		$this->assertSame( array(), $pending, 'tutte le sedici aree hanno le loro porte: l\'avviso in Bacheca si accende' );
		$this->assertSame( array(), array_intersect( $enforced, $pending ) );
		$this->assertEqualsCanonicalizing( EmployeeRules::keys(), array_merge( $enforced, $pending ) );
	}

	public function test_the_last_seven_areas_close_their_own_pages_and_nothing_else(): void {
		$pages = array(
			'resources'   => array( 'asem-resources', 'asem-resource', 'asem-res-report' ),
			'comms'       => array( 'asem-messages' ),
			'documents'   => array( 'asem-documents' ),
			'assemblies'  => array( 'asem-assemblies', 'asem-minutes', 'asem-polls' ),
			'inventory'   => array( 'asem-inventory' ),
			'conventions' => array( 'asem-conventions' ),
			'tools'       => array( 'asem-exports' ),
		);
		foreach ( $pages as $key => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertSame( $key, EmployeeRules::blocked_by( array( $key ), EmployeeRules::page_need( $slug ) ), "$key / $slug" );
				foreach ( array_diff( EmployeeRules::keys(), array( $key, EmployeeRules::SENSITIVE ) ) as $other ) { // «Vedere dati riservati» si somma a ogni esportazione
					$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::page_need( $slug ) ), "$other / $slug" );
				}
			}
		}
	}

	public function test_the_last_seven_areas_close_their_actions(): void {
		$actions = array(
			'resources'   => array( 'asem_res_assign', 'asem_res_block', 'asem_res_book', 'asem_res_cancel', 'asem_res_wait_remove', 'asem_resource_delete', 'asem_resource_save' ),
			'comms'       => array( 'asem_broadcast_retry', 'asem_broadcast_send', 'asem_broadcast_test', 'asem_sms_retry', 'asem_reminders_run' ),
			'documents'   => array( 'asem_document', 'asem_document_delete', 'asem_document_save' ),
			'assemblies'  => array( 'asem_assembly_attendance', 'asem_assembly_cancel', 'asem_assembly_csv', 'asem_assembly_delete', 'asem_assembly_draft', 'asem_assembly_hold', 'asem_assembly_minutes', 'asem_assembly_proxy_add', 'asem_assembly_proxy_revoke', 'asem_assembly_save', 'asem_minute_delete', 'asem_minute_save', 'asem_poll_delete', 'asem_poll_save' ),
			'inventory'   => array( 'asem_item_delete', 'asem_item_file', 'asem_item_save', 'asem_loan_return', 'asem_loan_save' ),
			'conventions' => array( 'asem_convention_archive', 'asem_convention_categories', 'asem_convention_delete', 'asem_convention_save', 'asem_conventions_csv' ),
		);
		foreach ( $actions as $key => $list ) {
			foreach ( $list as $action ) {
				$this->assertSame( $key, EmployeeRules::blocked_by( array( $key ), EmployeeRules::action_need( $action ) ), $action );
				foreach ( array_diff( EmployeeRules::keys(), array( $key, EmployeeRules::SENSITIVE ) ) as $other ) { // «Vedere dati riservati» si somma a ogni esportazione
					$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::action_need( $action ) ), "$other / $action" );
				}
			}
		}
		$this->assertNull( EmployeeRules::blocked_by( array( 'resources' ), EmployeeRules::action_need( 'asem_res_pay' ) ), 'l\'incasso di una prenotazione resta «Incassi»' );
	}

	public function test_actions_that_send_a_notice_to_members_also_need_communications(): void {
		foreach ( array( 'asem_assembly_convene' => 'assemblies', 'asem_poll_announce' => 'assemblies', 'asem_convention_announce' => 'conventions' ) as $action => $area ) {
			$need = EmployeeRules::action_need( $action );
			$this->assertSame( $area, EmployeeRules::blocked_by( array( $area ), $need ), $action );
			$this->assertSame( 'comms', EmployeeRules::blocked_by( array( 'comms' ), $need ), $action );
			$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'tools', 'inventory' ), $need ), $action );
		}
	}

	public function test_import_and_export_need_tools_plus_the_area_of_the_data(): void {
		$import = EmployeeRules::action_need( 'asem_import_apply' );
		$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools' ), $import ) );
		$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), $import ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), $import ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses', 'comms' ), $import ) );
		$this->assertSame( EmployeeRules::action_need( 'asem_import_apply' ), EmployeeRules::page_need( 'asem-import' ) );
		foreach ( array( 'asem_import_continue', 'asem_import_stop' ) as $step ) { // riprendere o fermare un'importazione a metà chiede gli stessi permessi dell'importazione
			$this->assertSame( $import, EmployeeRules::action_need( $step ), $step );
		}
		$tec = EmployeeRules::action_need( 'asem_tec_import' );
		$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools' ), $tec ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses' ), $tec ) );
		$this->assertSame( 'events', EmployeeRules::blocked_by( array( 'events' ), $tec ) );
		$this->assertNotContains( 'asem_tec_import', EmployeeRules::activity_actions() );
		$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools' ), EmployeeRules::export_need( 'people' ) ) );
		$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::export_need( 'people' ) ) );
		foreach ( array( 'ledger', 'period', 'social' ) as $what ) {
			$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools' ), EmployeeRules::export_need( $what ) ), $what );
			$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( $what ) ), $what );
		}
		$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools' ), EmployeeRules::export_need( 'qualcosa' ) ), 'qualunque altra esportazione' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'funds' ), EmployeeRules::export_need( 'qualcosa' ) ) );
	}

	public function test_every_export_needs_the_sensitive_permission_too(): void {
		foreach ( array( 'people', 'ledger', 'period', 'social', 'qualcosa' ) as $what ) {
			$need = EmployeeRules::export_need( $what );
			$this->assertSame( 'sensitive', EmployeeRules::blocked_by( array( 'sensitive' ), $need ), $what );
		}
		foreach ( array( 'asem_aff_csv', 'asem_cert_csv', 'asem_refunds_csv', 'asem_assembly_csv', 'asem_conventions_csv', 'asem_watch_csv', 'asem_budget_csv' ) as $csv ) {
			$this->assertSame( 'sensitive', EmployeeRules::blocked_by( array( 'sensitive' ), EmployeeRules::action_need( $csv ) ), $csv );
		}
		$this->assertSame( 'sensitive', EmployeeRules::blocked_by( array( 'sensitive' ), EmployeeRules::doc_need( 'book' ) ), 'il libro soci porta il codice fiscale' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'sensitive' ), EmployeeRules::doc_need( 'cards' ) ), 'le tessere non portano dati riservati' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'sensitive' ), EmployeeRules::page_need( 'asem-people' ) ), 'la lista dei soci resta aperta: nasconde solo i dati' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses', 'people' ), EmployeeRules::export_need( 'qualcosa' ) ), 'chi non ha tolto i dati riservati esporta come prima' );
	}

	public function test_documents_follow_the_area_of_their_content(): void {
		foreach ( array( 'minute' => 'assemblies', 'poll' => 'assemblies', 'items' => 'inventory', 'loans' => 'inventory' ) as $doc => $area ) {
			$this->assertSame( $area, EmployeeRules::blocked_by( array( $area ), EmployeeRules::doc_need( $doc ) ), $doc );
			$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'tools', 'funds' ), EmployeeRules::doc_need( $doc ) ), $doc );
		}
		$att = EmployeeRules::doc_need( 'attendance' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses' ), $att ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses', 'events' ), $att ) );
	}

	public function test_registers_and_tools_pages_open_with_any_of_their_areas(): void {
		$book = EmployeeRules::page_need( 'asem-book' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'people' ), $book ), 'senza anagrafica restano gli altri registri' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'assemblies', 'inventory', 'documents' ), $book ) );
		$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people', 'assemblies', 'inventory', 'documents', 'conventions' ), $book ) );
		$tools = EmployeeRules::page_need( 'asem-tools' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'tools' ), $tools ), 'resta il calendario' );
		$this->assertSame( 'tools', EmployeeRules::blocked_by( array( 'tools', 'courses', 'events' ), $tools ) );
	}

	public function test_a_new_employee_has_every_area_and_existing_removals_stay_the_same(): void {
		foreach ( EmployeeRules::keys() as $k ) {
			$this->assertTrue( EmployeeRules::has( array(), $k ), $k );
		}
		$stored = array( 'funds', 'courses' ); // come l'ha lasciato la versione precedente: nessuna area nuova è tolta
		$denied = EmployeeRules::sanitize_denied( $stored );
		$this->assertSame( array( 'courses', 'funds' ), $denied );
		foreach ( array_diff( EmployeeRules::keys(), array( 'courses', 'funds' ) ) as $k ) {
			$this->assertTrue( EmployeeRules::has( $denied, $k ), $k );
		}
	}

	public function test_a_removed_new_area_is_remembered_and_reported_as_removed(): void {
		$denied = EmployeeRules::sanitize_denied( array( 'tools', 'people', 'asem_manage', 'people' ) );
		$this->assertSame( array( 'people', 'tools' ), $denied );
		$this->assertFalse( EmployeeRules::has( $denied, 'people' ) );
		$this->assertFalse( EmployeeRules::has( $denied, 'tools' ) );
		$this->assertTrue( EmployeeRules::has( $denied, 'health' ) );
		$this->assertStringContainsString( 'Importa ed esporta dati', EmployeeRules::denied_message( 'tools' ) );
		$this->assertSame( 'people', EmployeeRules::blocked_by( $denied, array( 'all' => array( 'people' ) ) ) );
		$this->assertNull( EmployeeRules::blocked_by( $denied, array( 'any' => array( 'people', 'health' ) ) ) );
	}

	public function test_sanitize_keeps_only_removable_permissions_without_duplicates(): void {
		$this->assertSame( array( 'courses', 'funds' ), EmployeeRules::sanitize_denied( array( 'funds', 'asem_manage', 'courses', 'courses', 'members' ) ) );
		$this->assertSame( array(), EmployeeRules::sanitize_denied( 'courses' ) );
		$this->assertSame( array(), EmployeeRules::sanitize_denied( null ) );
	}

	public function test_without_removals_everything_is_allowed_like_the_secretary(): void {
		foreach ( EmployeeRules::keys() as $k ) {
			$this->assertTrue( EmployeeRules::has( array(), $k ), $k );
		}
		foreach ( array( 'asem-activities', 'asem-income', 'asem-expense', 'asem-refunds', 'asem-money', 'asem-ledger' ) as $page ) {
			$this->assertNull( EmployeeRules::blocked_by( array(), EmployeeRules::page_need( $page ) ), $page );
		}
	}

	public function test_removing_one_permission_closes_only_its_own_pages(): void {
		$pages = array(
			'collect'  => array( 'asem-income', 'asem-group' ),
			'expenses' => array( 'asem-expense' ),
			'refunds'  => array( 'asem-refunds' ),
			'funds'    => array( 'asem-money', 'asem-ledger', 'asem-accounts', 'asem-transfer', 'asem-watch', 'asem-accounting', 'asem-reports', 'asem-statement' ),
			'events'   => array( 'asem-bookdesk' ),
		);
		foreach ( $pages as $key => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertSame( $key, EmployeeRules::blocked_by( array( $key ), EmployeeRules::page_need( $slug ) ), "$key / $slug" );
				foreach ( array_diff( EmployeeRules::keys(), array( $key, EmployeeRules::SENSITIVE ) ) as $other ) { // «Vedere dati riservati» si somma a ogni esportazione
					$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::page_need( $slug ) ), "$other / $slug" );
				}
			}
		}
	}

	public function test_activity_pages_need_at_least_one_of_courses_or_events(): void {
		$need = EmployeeRules::page_need( 'asem-activities' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses' ), $need ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'events' ), $need ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses', 'events' ), $need ) );
	}

	public function test_pages_of_fixed_permissions_are_never_blocked(): void {
		$all = EmployeeRules::keys();
		foreach ( array( 'asem', 'asem-entity', 'asem-backup' ) as $page ) {
			$this->assertNull( EmployeeRules::page_need( $page ), $page );
			$this->assertNull( EmployeeRules::blocked_by( $all, EmployeeRules::page_need( $page ) ), $page );
		}
	}

	public function test_actions_follow_the_same_permissions(): void {
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::action_need( 'asem_save_income' ) ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'expenses', 'funds' ), EmployeeRules::action_need( 'asem_save_income' ) ) );
		$this->assertSame( 'expenses', EmployeeRules::blocked_by( array( 'expenses' ), EmployeeRules::action_need( 'asem_save_expense' ) ) );
		$this->assertSame( 'refunds', EmployeeRules::blocked_by( array( 'refunds' ), EmployeeRules::action_need( 'asem_refund_pay' ) ) );
		$this->assertSame( 'refunds', EmployeeRules::blocked_by( array( 'refunds' ), EmployeeRules::action_need( 'asem_refunds_csv' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::action_need( 'asem_cash_count' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( 'ledger' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( 'period' ) ) );
		$this->assertSame( array( 'all' => array( 'tools', EmployeeRules::SENSITIVE ) ), EmployeeRules::export_need( 'minutes' ), 'le altre esportazioni dipendono da «Importa ed esporta dati»' );
		$this->assertNull( EmployeeRules::action_need( 'asem_save_activity' ) );
		$this->assertContains( 'asem_save_activity', EmployeeRules::activity_actions() );
	}

	public function test_alternative_routes_to_the_same_data_follow_the_same_permission(): void {
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( 'social' ) ), 'report per attività' );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::doc_need( 'statement' ) ), 'rendiconto' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'collect', 'expenses', 'refunds' ), EmployeeRules::doc_need( 'statement' ) ) );
		$this->assertNull( EmployeeRules::doc_need( 'privacy' ), 'l\'informativa privacy resta fissa' );
		$by = array(
			'collect' => array( 'asem_res_pay', 'asem_check_payments', 'asem_payment_reviewed' ),
			'refunds' => array( 'asem_payment_refund', 'asem_payment_refunded' ),
			'funds'   => array( 'asem_void_tx', 'asem_credit_adjust', 'asem_fivepm_add', 'asem_fivepm_settings', 'asem_fivepm_report', 'asem_fivepm_delete', 'asem_statement_notes', 'asem_statement_backfill', 'asem_budget_save', 'asem_budget_lines', 'asem_budget_copy', 'asem_budget_approve', 'asem_budget_reopen', 'asem_budget_delete', 'asem_budget_csv' ),
		);
		foreach ( $by as $key => $actions ) {
			foreach ( $actions as $action ) {
				$this->assertSame( $key, EmployeeRules::blocked_by( array( $key ), EmployeeRules::action_need( $action ) ), $action );
				foreach ( array_diff( EmployeeRules::keys(), array( $key, EmployeeRules::SENSITIVE ) ) as $other ) { // «Vedere dati riservati» si somma a ogni esportazione
					$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::action_need( $action ) ), "$other / $action" );
				}
			}
		}
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::page_need( 'asem-fivepm' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::page_need( 'asem-budget' ) ) );
	}

	public function test_attachments_need_any_money_permission(): void {
		$need = EmployeeRules::action_need( 'asem_add_attachment' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'collect', 'expenses' ), $need ) );
		$this->assertNotNull( EmployeeRules::blocked_by( array( 'collect', 'expenses', 'refunds' ), $need ) );
	}

	public function test_abilities_map_to_permissions_by_activity_kind(): void {
		$this->assertSame( 'expenses', EmployeeRules::blocked_by( array( 'expenses' ), EmployeeRules::ability_need( 'asem_add_expense', null ) ) );
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::ability_need( 'asem_collect', null ) ) );
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::ability_need( 'asem_door_cash', 'event' ) ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses' ), EmployeeRules::ability_need( 'asem_view_participants', 'course' ) ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses' ), EmployeeRules::ability_need( 'asem_view_participants', 'event' ) ) );
		$this->assertSame( 'events', EmployeeRules::blocked_by( array( 'events' ), EmployeeRules::ability_need( 'asem_manage_event', 'recurring' ) ) );
		$this->assertNull( EmployeeRules::ability_need( 'asem_view_payments', null ), 'i pagamenti della persona restano fissi' );
	}

	public function test_people_area_closes_the_registry_pages_actions_exports_and_abilities(): void {
		foreach ( array( 'asem-people', 'asem-person', 'asem-runts', 'asem-affiliations', 'asem-volunteers' ) as $page ) {
			$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::page_need( $page ) ), $page );
			foreach ( array_diff( EmployeeRules::keys(), array( 'people' ) ) as $other ) {
				$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::page_need( $page ) ), "$other / $page" );
			}
		}
		foreach ( array( 'asem_save_person', 'asem_delete_person', 'asem_application_approve', 'asem_aff_save', 'asem_aff_csv', 'asem_card_add', 'asem_card_pdf', 'asem_policy_add', 'asem_insurance_delete', 'asem_rules_record' ) as $action ) {
			$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::action_need( $action ) ), $action );
			$this->assertNull( EmployeeRules::blocked_by( array( 'health', 'courses', 'events' ), EmployeeRules::action_need( $action ) ), $action );
		}
		$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::export_need( 'people' ) ) );
		foreach ( array( 'book', 'cards', 'volunteers' ) as $doc ) {
			$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::doc_need( $doc ) ), $doc );
		}
		$this->assertNull( EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::doc_need( 'minute' ) ), 'i verbali non sono anagrafica' );
		foreach ( array( 'asem_view_person', 'asem_register_member' ) as $ability ) {
			$this->assertSame( 'people', EmployeeRules::blocked_by( array( 'people' ), EmployeeRules::ability_need( $ability, null ) ), $ability );
		}
	}

	public function test_health_area_closes_writing_but_the_list_and_the_file_stay_open_for_enrolled_people(): void {
		foreach ( array( 'asem_cert_add', 'asem_cert_delete', 'asem_cert_csv' ) as $action ) {
			$this->assertSame( 'health', EmployeeRules::blocked_by( array( 'health' ), EmployeeRules::action_need( $action ) ), $action );
			$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'courses', 'events' ), EmployeeRules::action_need( $action ) ), $action );
		}
		// senza «Certificati medici» la pagina e il file restano aperti a chi ha corsi o eventi (poi si guardano solo i propri iscritti)
		foreach ( array( EmployeeRules::page_need( 'asem-medical' ), EmployeeRules::action_need( 'asem_cert_file' ) ) as $need ) {
			$this->assertNull( EmployeeRules::blocked_by( array( 'health' ), $need ) );
			$this->assertNull( EmployeeRules::blocked_by( array( 'health', 'courses' ), $need ) );
			$this->assertNull( EmployeeRules::blocked_by( array( 'health', 'events' ), $need ) );
			$this->assertSame( 'health', EmployeeRules::blocked_by( array( 'health', 'courses', 'events' ), $need ) );
			$this->assertNull( EmployeeRules::blocked_by( array(), $need ) );
		}
	}

	public function test_minimal_person_search_is_open_while_any_area_that_picks_people_is_open(): void {
		$need = EmployeeRules::action_need( 'asem_person_search' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'people' ), $need ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'people', 'collect', 'courses', 'events', 'expenses' ), $need ) );
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'people', 'collect', 'courses', 'events', 'expenses', 'refunds' ), $need ) );
	}

	public function test_limited_certificate_view_follows_the_instructor_rule(): void {
		$this->assertTrue( EmployeeRules::instructor_in_scope( 0, 0 ), 'senza istruttore: si vede' );
		$this->assertTrue( EmployeeRules::instructor_in_scope( 0, 7 ), 'senza istruttore: si vede' );
		$this->assertTrue( EmployeeRules::instructor_in_scope( 7, 7 ), 'istruttore: io' );
		$this->assertFalse( EmployeeRules::instructor_in_scope( 8, 7 ), 'un altro istruttore: non si vede' );
		$this->assertFalse( EmployeeRules::instructor_in_scope( 8, 0 ), 'nessuna persona collegata: solo le attività senza istruttore' );
	}

	public function test_dates_are_validated(): void {
		$this->assertSame( '2026-02-28', EmployeeRules::clean_date( '2026-02-28' ) );
		$this->assertNull( EmployeeRules::clean_date( '2026-02-30' ) );
		$this->assertNull( EmployeeRules::clean_date( '28/02/2026' ) );
		$this->assertNull( EmployeeRules::clean_date( '' ) );
		$this->assertNull( EmployeeRules::clean_date( null ) );
	}

	public function test_without_dates_the_employee_is_always_in_service(): void {
		$this->assertTrue( EmployeeRules::is_active( null, null, '2001-01-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '', '', '2099-12-31' ) );
	}

	public function test_start_and_end_days_are_included(): void {
		$this->assertFalse( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-02-28' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-03-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-06-30' ) );
		$this->assertFalse( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-07-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', null, '2030-01-01' ) );
		$this->assertFalse( EmployeeRules::is_active( null, '2026-06-30', '2026-07-01' ) );
	}

	public function test_workers_count_at_a_date(): void {
		$rows = array(
			array( 'from' => null, 'to' => null ),
			array( 'from' => '2026-03-01', 'to' => '2026-06-30' ),
			array( 'from' => '2027-01-01', 'to' => null ),
			array( 'from' => null, 'to' => '2025-12-31' ),
		);
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2025-12-31' ) ); // il primo e l'ultimo
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2026-03-01' ) );
		$this->assertSame( 1, EmployeeRules::count_active( $rows, '2026-07-01' ) );
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2027-01-01' ) );
		$this->assertSame( 0, EmployeeRules::count_active( array(), '2026-12-31' ) );
	}

	public function test_denied_message_names_the_permission_and_who_can_restore_it(): void {
		$m = EmployeeRules::denied_message( 'collect' );
		$this->assertStringContainsString( 'Incassi', $m );
		$this->assertStringContainsString( 'presidente', $m );
		$this->assertStringContainsString( 'amministratore', $m );
	}
}

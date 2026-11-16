<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole del tipo di utente «Dipendente» (parte pura, senza WordPress: si prova con i test unitari).
 *
 * Il dipendente parte con gli stessi permessi della segreteria. Chi ha titolo (amministratori, presidente, vicepresidente)
 * può togliergli, area per area, i permessi di questo elenco chiuso (15 aree); tutto il resto resta solo amministratore.
 * Si memorizza l'elenco dei permessi TOLTI: chi non ha niente di tolto ha tutto, come la segreteria.
 *
 * Ogni pagina, azione o esportazione che dipende da uno di quei permessi è scritta qui, in una tabella sola:
 * il controllo è unico ({@see Employees::gate()}), così un permesso tolto vale ovunque.
 * Tutte e quindici le aree hanno i loro controlli (vedi {@see EmployeeRules::enforced_keys()}).
 */
final class EmployeeRules {

	const COURSES  = 'courses';
	const EVENTS   = 'events';
	const COLLECT  = 'collect';
	const EXPENSES = 'expenses';
	const REFUNDS  = 'refunds';
	const FUNDS    = 'funds';

	const PEOPLE      = 'people';
	const HEALTH      = 'health';
	const RESOURCES   = 'resources';
	const COMMS       = 'comms';
	const DOCUMENTS   = 'documents';
	const ASSEMBLIES  = 'assemblies';
	const INVENTORY   = 'inventory';
	const CONVENTIONS = 'conventions';
	const TOOLS       = 'tools';

	/** @return array<string,string> i permessi che si possono togliere (le 15 aree), con il nome che si legge, nell'ordine della pagina */
	public static function labels(): array {
		return array(
			self::PEOPLE      => 'Anagrafica soci (schede, rubrica, libro soci, RUNTS, tessere, affiliazioni, polizze)',
			self::HEALTH      => 'Certificati medici',
			self::COURSES     => 'Corsi',
			self::EVENTS      => 'Eventi',
			self::RESOURCES   => 'Risorse prenotabili',
			self::COLLECT     => 'Incassi',
			self::EXPENSES    => 'Registrazione delle spese',
			self::REFUNDS     => 'Emissione dei rimborsi',
			self::FUNDS       => 'Visualizzazione di fondi, casse e conti',
			self::COMMS       => 'Comunicazioni (avvisi, newsletter, SMS, promemoria)',
			self::DOCUMENTS   => 'Documenti dell\'associazione',
			self::ASSEMBLIES  => 'Assemblee, verbali e votazioni',
			self::INVENTORY   => 'Inventario e prestiti',
			self::CONVENTIONS => 'Convenzioni',
			self::TOOLS       => 'Importa ed esporta dati',
		);
	}

	/** Le aree che hanno già i loro controlli su pagine e azioni: ora sono tutte e quindici. @return string[] */
	public static function enforced_keys(): array {
		return array(
			self::PEOPLE, self::HEALTH, self::COURSES, self::EVENTS, self::RESOURCES, self::COLLECT, self::EXPENSES, self::REFUNDS, self::FUNDS,
			self::COMMS, self::DOCUMENTS, self::ASSEMBLIES, self::INVENTORY, self::CONVENTIONS, self::TOOLS,
		);
	}

	/** Le aree senza controlli ancora: oggi nessuna (l'elenco resta per le aree che si aggiungessero in futuro prima delle loro porte). @return string[] */
	public static function pending_keys(): array {
		return array_values( array_diff( array_keys( self::labels() ), self::enforced_keys() ) );
	}

	/** @return string[] */
	public static function keys(): array {
		return array_keys( self::labels() );
	}

	public static function is_removable( string $key ): bool {
		return isset( self::labels()[ $key ] );
	}

	public static function label( string $key ): string {
		return self::labels()[ $key ] ?? '';
	}

	/** Dall'elenco ricevuto (da un modulo o dal database) tiene solo i permessi rimovibili, senza doppioni e nell'ordine fisso. @return string[] */
	public static function sanitize_denied( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array_values( array_filter( self::keys(), function ( $k ) use ( $raw ) {
			return in_array( $k, $raw, true );
		} ) );
	}

	/** Il permesso è ancora dato? Un permesso che non è nell'elenco dei rimovibili è sempre dato (resta fisso). */
	public static function has( array $denied, string $key ): bool {
		return ! self::is_removable( $key ) || ! in_array( $key, $denied, true );
	}

	/**
	 * Cosa serve per aprire una pagina di amministrazione (null = nessun permesso rimovibile in gioco).
	 * Forma: ['all' => [...]] servono tutti; ['any' => [...]] basta uno.
	 *
	 * @return array{all?:string[],any?:string[]}|null
	 */
	public static function page_need( string $slug ): ?array {
		$map = array(
			// anagrafica soci: rubrica, scheda, libro soci, RUNTS, affiliazioni e tessere degli enti, polizze e registro dei volontari
			'asem-people'       => array( 'all' => array( self::PEOPLE ) ),
			'asem-person'       => array( 'all' => array( self::PEOPLE ) ),
			// «Registri»: la pagina si apre a chi ha almeno un registro (libro soci = anagrafica; gli altri registri sono assemblee, inventario, documenti, convenzioni); il libro soci in sé lo mostra solo a chi ha l'anagrafica
			'asem-book'         => array( 'any' => array( self::PEOPLE, self::ASSEMBLIES, self::INVENTORY, self::DOCUMENTS, self::CONVENTIONS ) ),
			'asem-runts'        => array( 'all' => array( self::PEOPLE ) ),
			'asem-affiliations' => array( 'all' => array( self::PEOPLE ) ),
			'asem-volunteers'   => array( 'all' => array( self::PEOPLE ) ),
			// certificati medici: elenco completo con «Certificati medici»; senza, resta lo stato dei soli iscritti alle attività che il dipendente può gestire
			'asem-medical'      => array( 'any' => array( self::HEALTH, self::COURSES, self::EVENTS ) ),
			'asem-activities' => array( 'any' => array( self::COURSES, self::EVENTS ) ),
			'asem-calendar'   => array( 'any' => array( self::COURSES, self::EVENTS ) ),
			'asem-bookdesk'   => array( 'all' => array( self::EVENTS ) ),
			'asem-income'     => array( 'all' => array( self::COLLECT ) ),
			'asem-group'      => array( 'all' => array( self::COLLECT ) ),
			'asem-expense'    => array( 'all' => array( self::EXPENSES ) ),
			'asem-refunds'    => array( 'all' => array( self::REFUNDS ) ),
			'asem-money'      => array( 'all' => array( self::FUNDS ) ),
			'asem-ledger'     => array( 'all' => array( self::FUNDS ) ),
			'asem-receipt-replace' => array( 'all' => array( self::FUNDS ) ),
			'asem-accounts'   => array( 'all' => array( self::FUNDS ) ),
			'asem-transfer'   => array( 'all' => array( self::FUNDS ) ),
			'asem-watch'      => array( 'all' => array( self::FUNDS ) ),
			'asem-accounting' => array( 'all' => array( self::FUNDS ) ),
			'asem-reports'    => array( 'all' => array( self::FUNDS ) ),
			'asem-statement'  => array( 'all' => array( self::FUNDS ) ),
			'asem-fivepm'     => array( 'all' => array( self::FUNDS ) ),
			'asem-budget'     => array( 'all' => array( self::FUNDS ) ),
			// risorse prenotabili: elenco, scheda e occupazione
			'asem-resources'  => array( 'all' => array( self::RESOURCES ) ),
			'asem-resource'   => array( 'all' => array( self::RESOURCES ) ),
			'asem-res-report' => array( 'all' => array( self::RESOURCES ) ),
			'asem-messages'   => array( 'all' => array( self::COMMS ) ),
			'asem-documents'  => array( 'all' => array( self::DOCUMENTS ) ),
			'asem-assemblies' => array( 'all' => array( self::ASSEMBLIES ) ),
			'asem-minutes'    => array( 'all' => array( self::ASSEMBLIES ) ),
			'asem-polls'      => array( 'all' => array( self::ASSEMBLIES ) ),
			'asem-inventory'  => array( 'all' => array( self::INVENTORY ) ),
			'asem-conventions' => array( 'all' => array( self::CONVENTIONS ) ),
			// importa ed esporta dati: l'importazione scrive soci e prima nota, la TEC scrive corsi ed eventi: servono anche le aree di quei dati
			'asem-tools'      => array( 'any' => array( self::TOOLS, self::COURSES, self::EVENTS ) ), // la panoramica mostra solo ciò che si può aprire (c'è anche il calendario)
			'asem-exports'    => array( 'all' => array( self::TOOLS ) ),
			'asem-import'     => array( 'all' => array( self::TOOLS, self::PEOPLE, self::FUNDS ) ),
			'asem-tec'        => array( 'all' => array( self::TOOLS, self::COURSES, self::EVENTS ) ),
		);
		return $map[ $slug ] ?? null;
	}

	/** Azioni di admin-post o di admin-ajax che modificano o mostrano attività, tutte da controllare in base al tipo (corso o evento). @return string[] */
	public static function activity_actions(): array {
		return array(
			'asem_save_activity', 'asem_duplicate_activity', 'asem_lesson_exception', 'asem_lesson_restore', 'asem_enroll', 'asem_enroll_variant', 'asem_cancel_enrollment',
			'asem_stop_renewal', 'asem_add_session', 'asem_update_session', 'asem_generate_sessions', 'asem_add_dates', 'asem_cancel_session', 'asem_book',
			'asem_event_staff_add', 'asem_event_staff_remove', 'asem_event_staff_cash', 'asem_checkin', 'asem_send_notice', 'asem_transfer_booking',
			'asem_cancel_booking', 'asem_waitlist_remove', 'asem_quick_enroll', 'asem_delete_activity', 'asem_delete_activities', 'asem_delete_booking',
			'asem_purge_enrollments', 'asem_event_request_approve', 'asem_event_request_reject', 'asem_attendance_save', 'asem_attendance_matrix_save',
		);
	}

	/**
	 * Cosa serve per un'azione di admin-post o di admin-ajax (null = nessun permesso rimovibile in gioco, o da decidere dai dati: vedi activity_actions()
	 * e «asem_quick_cash», «asem_export»).
	 *
	 * @return array{all?:string[],any?:string[]}|null
	 */
	public static function action_need( string $action ): ?array {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			$add = function ( array $actions, array $need ) use ( &$map ) {
				foreach ( $actions as $a ) {
					$map[ $a ] = $need;
				}
			};
			$add( array( 'asem_save_income', 'asem_walk_in', 'asem_save_group_cash', 'asem_credit_sell', 'asem_check_payments', 'asem_payment_reviewed', 'asem_res_pay', 'asem_receipt_email' ), array( 'all' => array( self::COLLECT ) ) );
			$add(
				array(
					'asem_access_approve', 'asem_access_done', 'asem_adult_invite', 'asem_application_approve', 'asem_application_reject', 'asem_consent_set', 'asem_delete_person',
					'asem_detach_email', 'asem_detach_phone', 'asem_member_left', 'asem_privacy_consent', 'asem_promote_guest', 'asem_reactivate_member', 'asem_save_person',
					'asem_set_membership', 'asem_set_staff', 'asem_suspend_expired', 'asem_suspend_member', 'asem_rules_record', 'asem_guardian_consent_resend',
					'asem_aff_csv', 'asem_aff_delete', 'asem_aff_save', 'asem_card_add', 'asem_card_bulk', 'asem_card_delete', 'asem_card_pdf', 'asem_card_update',
					'asem_insurance_add', 'asem_insurance_delete', 'asem_policy_add', 'asem_policy_delete',
				),
				array( 'all' => array( self::PEOPLE ) )
			);
			$add( array( 'asem_cert_add', 'asem_cert_delete', 'asem_cert_csv' ), array( 'all' => array( self::HEALTH ) ) );
			$add( array( 'asem_cert_file' ), array( 'any' => array( self::HEALTH, self::COURSES, self::EVENTS ) ) ); // senza «Certificati medici» solo il file di un iscritto alle proprie attività (lo decide il modulo dei certificati)
			$add( array( 'asem_person_context', 'asem_quick_person' ), array( 'any' => array( self::COLLECT, self::COURSES, self::EVENTS ) ) );
			$add( array( 'asem_person_search' ), array( 'any' => array( self::COLLECT, self::COURSES, self::EVENTS, self::EXPENSES, self::REFUNDS ) ) ); // la ricerca minima (3 lettere, 10 risultati) per scegliere chi paga o chi iscrivere
			$add( array( 'asem_save_expense', 'asem_expense_fund_attach', 'asem_expense_fund_undo' ), array( 'all' => array( self::EXPENSES ) ) );
			$add( array( 'asem_refund_request', 'asem_refund_approve', 'asem_refund_reject', 'asem_refund_cancel', 'asem_refund_pay', 'asem_refunds_csv', 'asem_refund_file', 'asem_payment_refund', 'asem_payment_refunded' ), array( 'all' => array( self::REFUNDS ) ) );
			$add(
				array(
					'asem_save_transfer', 'asem_add_account', 'asem_update_account', 'asem_close_account', 'asem_reopen_account', 'asem_fund_create', 'asem_fund_deposit',
					'asem_fund_release', 'asem_fund_settle', 'asem_receipt_replace', 'asem_watch_snapshot', 'asem_discrepancy_approve', 'asem_discrepancy_revoke', 'asem_watch_csv', 'asem_cash_count',
					'asem_void_tx', 'asem_credit_adjust', 'asem_fivepm_add', 'asem_fivepm_settings', 'asem_fivepm_report', 'asem_fivepm_delete', 'asem_statement_notes', 'asem_statement_backfill',
					'asem_budget_save', 'asem_budget_lines', 'asem_budget_copy', 'asem_budget_approve', 'asem_budget_reopen', 'asem_budget_delete', 'asem_budget_csv',
				),
				array( 'all' => array( self::FUNDS ) )
			);
			$add( array( 'asem_add_attachment', 'asem_remove_attachment', 'asem_attachment' ), array( 'any' => array( self::COLLECT, self::EXPENSES, self::REFUNDS ) ) );
			$add( array( 'asem_res_assign', 'asem_res_block', 'asem_res_book', 'asem_res_cancel', 'asem_res_wait_remove', 'asem_resource_delete', 'asem_resource_save' ), array( 'all' => array( self::RESOURCES ) ) ); // l'incasso di una prenotazione (asem_res_pay) resta «Incassi»
			$add( array( 'asem_broadcast_retry', 'asem_broadcast_send', 'asem_broadcast_test', 'asem_sms_retry', 'asem_reminders_run' ), array( 'all' => array( self::COMMS ) ) );
			$add( array( 'asem_document', 'asem_document_delete', 'asem_document_save' ), array( 'all' => array( self::DOCUMENTS ) ) );
			$add(
				array(
					'asem_assembly_attendance', 'asem_assembly_cancel', 'asem_assembly_csv', 'asem_assembly_delete', 'asem_assembly_draft', 'asem_assembly_hold', 'asem_assembly_minutes',
					'asem_assembly_proxy_add', 'asem_assembly_proxy_revoke', 'asem_assembly_save', 'asem_minute_delete', 'asem_minute_save', 'asem_poll_delete', 'asem_poll_save',
				),
				array( 'all' => array( self::ASSEMBLIES ) )
			);
			$add( array( 'asem_assembly_convene', 'asem_poll_announce' ), array( 'all' => array( self::ASSEMBLIES, self::COMMS ) ) ); // mandano un avviso ai soci: serve anche «Comunicazioni»
			$add( array( 'asem_item_delete', 'asem_item_file', 'asem_item_save', 'asem_loan_return', 'asem_loan_save' ), array( 'all' => array( self::INVENTORY ) ) );
			$add( array( 'asem_convention_archive', 'asem_convention_categories', 'asem_convention_delete', 'asem_convention_save', 'asem_conventions_csv' ), array( 'all' => array( self::CONVENTIONS ) ) );
			$add( array( 'asem_convention_announce' ), array( 'all' => array( self::CONVENTIONS, self::COMMS ) ) );
			$add( array( 'asem_import_apply', 'asem_import_preview', 'asem_import_undo' ), array( 'all' => array( self::TOOLS, self::PEOPLE, self::FUNDS ) ) );
			$add( array( 'asem_tec_import' ), array( 'all' => array( self::TOOLS, self::COURSES, self::EVENTS ) ) );
		}
		return $map[ $action ] ?? null;
	}

	/** Cosa serve per un'esportazione dell'azione «asem_export» (parametro «what»). @return array{all?:string[],any?:string[]}|null */
	public static function export_need( string $what ): ?array {
		if ( 'people' === $what ) { // l'elenco dei soci: «Importa ed esporta dati» e l'anagrafica
			return array( 'all' => array( self::TOOLS, self::PEOPLE ) );
		}
		if ( in_array( $what, array( 'ledger', 'period', 'social' ), true ) ) { // prima nota, rendiconto e report: «Importa ed esporta dati» e i fondi
			return array( 'all' => array( self::TOOLS, self::FUNDS ) );
		}
		return array( 'all' => array( self::TOOLS ) ); // qualunque altra esportazione è un'esportazione
	}

	/**
	 * Cosa serve per un documento dell'azione «asem_doc» (parametro «what»): il rendiconto mostra incassi e costi, come fondi, casse e conti;
	 * libro soci, tessere e registro dei volontari sono anagrafica; verbali e votazioni sono assemblee; elenco beni e prestiti sono inventario;
	 * il registro presenze è di corsi ed eventi.
	 *
	 * @return array{all?:string[],any?:string[]}|null
	 */
	public static function doc_need( string $what ): ?array {
		if ( in_array( $what, array( 'book', 'cards', 'volunteers' ), true ) ) {
			return array( 'all' => array( self::PEOPLE ) );
		}
		if ( in_array( $what, array( 'minute', 'poll' ), true ) ) {
			return array( 'all' => array( self::ASSEMBLIES ) );
		}
		if ( in_array( $what, array( 'items', 'loans' ), true ) ) {
			return array( 'all' => array( self::INVENTORY ) );
		}
		if ( 'attendance' === $what ) {
			return array( 'any' => array( self::COURSES, self::EVENTS ) );
		}
		return 'statement' === $what ? array( 'all' => array( self::FUNDS ) ) : null;
	}

	/**
	 * Vista limitata dei certificati medici: l'attività è tra quelle di cui il dipendente è istruttore, oppure non ha nessun istruttore.
	 * Un'attività con un altro istruttore non si vede.
	 *
	 * @param int $instructor_person_id istruttore dell'attività (0 = nessuno)
	 * @param int $my_person_id         la persona collegata all'utente (0 = nessuna)
	 */
	public static function instructor_in_scope( int $instructor_person_id, int $my_person_id ): bool {
		return $instructor_person_id <= 0 || ( $my_person_id > 0 && $instructor_person_id === $my_person_id );
	}

	/** Un'attività di questo tipo (course, event, recurring) dipende da quale permesso? */
	public static function activity_key( string $kind ): string {
		return ActivityKind::COURSE === $kind ? self::COURSES : self::EVENTS;
	}

	/**
	 * Il primo permesso tolto che impedisce di fare ciò che serve; null se si può fare.
	 *
	 * @param string[]                          $denied permessi tolti al dipendente
	 * @param array{all?:string[],any?:string[]}|null $need  cosa serve (da page_need(), action_need()…)
	 */
	public static function blocked_by( array $denied, ?array $need ): ?string {
		if ( ! $need || ! $denied ) {
			return null;
		}
		foreach ( (array) ( $need['all'] ?? array() ) as $k ) {
			if ( ! self::has( $denied, $k ) ) {
				return $k;
			}
		}
		$any = (array) ( $need['any'] ?? array() );
		if ( $any ) {
			foreach ( $any as $k ) {
				if ( self::has( $denied, $k ) ) {
					return null;
				}
			}
			return (string) reset( $any );
		}
		return null;
	}

	/** Permesso richiesto da una capability «asem_*» (null = nessuno in gioco). $kind è il tipo dell'attività se la capability riguarda un'attività. */
	public static function ability_need( string $ability, ?string $kind ): ?array {
		switch ( $ability ) {
			case 'asem_view_person':
			case 'asem_register_member':
				return array( 'all' => array( self::PEOPLE ) );
			case 'asem_add_expense':
				return array( 'all' => array( self::EXPENSES ) );
			case 'asem_collect':
			case 'asem_door_cash':
				return array( 'all' => array( self::COLLECT ) );
			case 'asem_manage_event':
			case 'asem_view_activity':
			case 'asem_view_participants':
			case 'asem_notify_activity':
				return null === $kind ? null : array( 'all' => array( self::activity_key( $kind ) ) );
		}
		return null;
	}

	/** Valida una data scritta come Y-m-d (altrimenti null). */
	public static function clean_date( $v ): ?string {
		$v = is_string( $v ) ? trim( $v ) : '';
		if ( '' === $v || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	/** Alla data (Y-m-d) il dipendente è in servizio? Senza date è in servizio; l'inizio e la fine sono compresi. */
	public static function is_active( ?string $from, ?string $to, string $date ): bool {
		$from = self::clean_date( $from );
		$to   = self::clean_date( $to );
		return ( null === $from || $from <= $date ) && ( null === $to || $to >= $date );
	}

	/** Quanti dipendenti sono in servizio alla data. @param array<int,array{from:?string,to:?string}> $employees */
	public static function count_active( array $employees, string $date ): int {
		$n = 0;
		foreach ( $employees as $e ) {
			if ( self::is_active( $e['from'] ?? null, $e['to'] ?? null, $date ) ) {
				++$n;
			}
		}
		return $n;
	}

	/** Il messaggio per chi trova chiusa una porta perché gli è stato tolto un permesso. */
	public static function denied_message( string $key ): string {
		$name = self::label( $key );
		return 'Non puoi farlo con il tuo accesso' . ( '' !== $name ? ': ti è stato tolto il permesso «' . $name . '»' : '' ) . '. Se ti serve, chiedi al presidente, al vicepresidente o a un amministratore del sito di ridartelo.';
	}
}

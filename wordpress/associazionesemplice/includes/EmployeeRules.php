<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole del tipo di utente «Dipendente» (parte pura, senza WordPress: si prova con i test unitari).
 *
 * Il dipendente parte con gli stessi permessi della segreteria. Chi ha titolo (amministratori, presidente, vicepresidente)
 * può togliergli, uno per uno, SOLO i sei permessi di questo elenco chiuso; tutti gli altri restano fissi.
 *
 * Ogni pagina, azione o esportazione che dipende da uno di quei permessi è scritta qui, in una tabella sola:
 * il controllo è unico ({@see Employees::gate()}), così un permesso tolto vale ovunque.
 */
final class EmployeeRules {

	const COURSES  = 'courses';
	const EVENTS   = 'events';
	const COLLECT  = 'collect';
	const EXPENSES = 'expenses';
	const REFUNDS  = 'refunds';
	const FUNDS    = 'funds';

	/** @return array<string,string> i soli permessi che si possono togliere, con il nome che si legge */
	public static function labels(): array {
		return array(
			self::COURSES  => 'Corsi',
			self::EVENTS   => 'Eventi',
			self::COLLECT  => 'Incassi',
			self::EXPENSES => 'Registrazione delle spese',
			self::REFUNDS  => 'Emissione dei rimborsi',
			self::FUNDS    => 'Visualizzazione di fondi, casse e conti',
		);
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
			'asem-activities' => array( 'any' => array( self::COURSES, self::EVENTS ) ),
			'asem-calendar'   => array( 'any' => array( self::COURSES, self::EVENTS ) ),
			'asem-bookdesk'   => array( 'all' => array( self::EVENTS ) ),
			'asem-income'     => array( 'all' => array( self::COLLECT ) ),
			'asem-group'      => array( 'all' => array( self::COLLECT ) ),
			'asem-expense'    => array( 'all' => array( self::EXPENSES ) ),
			'asem-refunds'    => array( 'all' => array( self::REFUNDS ) ),
			'asem-money'      => array( 'all' => array( self::FUNDS ) ),
			'asem-ledger'     => array( 'all' => array( self::FUNDS ) ),
			'asem-accounts'   => array( 'all' => array( self::FUNDS ) ),
			'asem-transfer'   => array( 'all' => array( self::FUNDS ) ),
			'asem-watch'      => array( 'all' => array( self::FUNDS ) ),
			'asem-accounting' => array( 'all' => array( self::FUNDS ) ),
			'asem-reports'    => array( 'all' => array( self::FUNDS ) ),
			'asem-statement'  => array( 'all' => array( self::FUNDS ) ),
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
			'asem_purge_enrollments', 'asem_event_request_approve', 'asem_event_request_reject', 'asem_attendance_save', 'asem_attendance_matrix_save', 'asem_tec_import',
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
			$add( array( 'asem_save_income', 'asem_walk_in', 'asem_save_group_cash', 'asem_credit_sell', 'asem_receipt_email' ), array( 'all' => array( self::COLLECT ) ) );
			$add( array( 'asem_person_context', 'asem_quick_person' ), array( 'any' => array( self::COLLECT, self::COURSES, self::EVENTS ) ) );
			$add( array( 'asem_save_expense', 'asem_expense_fund_attach', 'asem_expense_fund_undo' ), array( 'all' => array( self::EXPENSES ) ) );
			$add( array( 'asem_refund_request', 'asem_refund_approve', 'asem_refund_reject', 'asem_refund_cancel', 'asem_refund_pay', 'asem_refunds_csv', 'asem_refund_file' ), array( 'all' => array( self::REFUNDS ) ) );
			$add(
				array(
					'asem_save_transfer', 'asem_add_account', 'asem_update_account', 'asem_close_account', 'asem_reopen_account', 'asem_fund_create', 'asem_fund_deposit',
					'asem_fund_release', 'asem_fund_settle', 'asem_watch_snapshot', 'asem_discrepancy_approve', 'asem_discrepancy_revoke', 'asem_watch_csv', 'asem_cash_count',
				),
				array( 'all' => array( self::FUNDS ) )
			);
			$add( array( 'asem_add_attachment', 'asem_remove_attachment', 'asem_attachment' ), array( 'any' => array( self::COLLECT, self::EXPENSES, self::REFUNDS ) ) );
		}
		return $map[ $action ] ?? null;
	}

	/** Cosa serve per un'esportazione dell'azione «asem_export» (parametro «what»). @return array{all?:string[],any?:string[]}|null */
	public static function export_need( string $what ): ?array {
		return in_array( $what, array( 'ledger', 'period' ), true ) ? array( 'all' => array( self::FUNDS ) ) : null;
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

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure su cosa si può scrivere mentre lo schema del database è ancora a metà (dopo un aggiornamento del plugin che ha aggiunto colonne o tabelle,
 * finché i passaggi non sono finiti). Il codice nuovo scrive colonne che potrebbero non esserci ancora: una scrittura a metà è peggio di un'attesa.
 * Le letture non si toccano (al più mostrano meno); le scritture rispondono con {@see SchemaGuard::MESSAGE} e si possono ripetere dopo un minuto.
 */
final class SchemaGuard {

	/** Risposta alle scritture rifiutate (da passare a {@see Texts::t()}). */
	const MESSAGE = 'Aggiornamento in corso, riprova tra un minuto.';

	/** Azioni di admin-post e admin-ajax che leggono soltanto (scaricano, cercano, mostrano): restano disponibili durante l'aggiornamento. Quelle che finiscono con «_csv» pure. */
	const READ_ONLY = array(
		'asem_export', 'asem_doc', 'asem_document', 'asem_backup', 'asem_backup_saved', 'asem_privacy_export', 'asem_attachment', 'asem_cert_file', 'asem_item_file',
		'asem_refund_file', 'asem_card_pdf', 'asem_receipt', 'asem_statement', 'asem_wallet_apple', 'asem_ics', 'asem_qr', 'asem_ticket',
		'asem_person_search', 'asem_person_context', 'asem_event_slot', 'asem_credit_status', 'asem_dismiss_notice', 'asem_check_updates',
	);

	/** Il lavoro che porta avanti l'aggiornamento: l'unico che gira mentre lo schema non è pronto. */
	const UPGRADE_JOB = 'asem_schema_upgrade';

	/** Un'azione di admin-post o admin-ajax va rifiutata? Solo le azioni del plugin che scrivono, e solo con lo schema non pronto. */
	public static function action_blocked( string $action, bool $ready ): bool {
		if ( $ready || 0 !== strpos( $action, 'asem_' ) ) {
			return false;
		}
		return ! in_array( $action, self::READ_ONLY, true ) && '_csv' !== substr( $action, -4 );
	}

	/** Una richiesta REST del plugin va rifiutata? Tutto ciò che non è una lettura (anche i segnali dei gestori di pagamento: chi li manda riprova). */
	public static function rest_blocked( string $route, string $method, bool $ready ): bool {
		if ( $ready || 0 !== strpos( ltrim( $route, '/' ), 'associazionesemplice/' ) ) {
			return false;
		}
		return ! in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
	}

	/** Un lavoro periodico può girare? Con lo schema a metà solo quello che lo porta avanti. */
	public static function job_allowed( string $hook, bool $ready ): bool {
		return $ready || self::UPGRADE_JOB === $hook;
	}
}

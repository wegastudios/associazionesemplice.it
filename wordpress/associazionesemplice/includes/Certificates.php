<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Certificato medico sportivo dei soci: più certificati per persona (tipo, rilascio, scadenza, medico, file allegato), stato (valido, in scadenza,
 * scaduto, mancante), attività che lo richiedono, promemoria e dati per la privacy.
 *
 * È un dato sanitario: lo vede e lo gestisce solo lo staff (stessa regola di {@see Access::is_admin_user()}); il file sta nella cartella privata
 * degli allegati e si scarica solo da qui; il registro delle azioni ricorda che cosa è successo, mai i contenuti.
 */
final class Certificates {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'admin_post_asem_cert_file', array( __CLASS__, 'handle_file' ) );
	}

	/** Solo lo staff vede e gestisce i certificati. */
	public static function can_manage(): bool {
		return Access::is_admin_user( get_current_user_id() );
	}

	public static function warn_days(): int {
		return ExpiryRules::warn_days( Settings::get( 'cert_warn_days' ) );
	}

	// ---------- File ----------

	/** Indirizzo per aprire il file di un certificato (solo staff). */
	public static function file_url( int $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_cert_file', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'asem_cert_file_' . $id );
	}

	public static function handle_file(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::can_manage() || ! Edition::has( 'medical' ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_cert_file_' . $id );
		$c    = self::get( $id );
		$path = $c && ! empty( $c['file_stored'] ) ? Attachments::dir() . '/' . AttachmentRules::stored_name( (string) $c['file_stored'] ) : '';
		if ( '' === $path || '' === basename( $path ) || ! is_readable( $path ) ) {
			wp_die( 'File non trovato.', 404 );
		}
		Audit::log( 'medical.file_opened', 'medical_cert', $id ); // solo l'azione: niente nome, tipo o date
		$ext = AttachmentRules::extension( (string) $c['file_name'] );
		Attachments::stream( $path, $ext, 'certificato-' . (int) $id . ( '' !== $ext ? '.' . $ext : '' ) ); // il nome originale può contenere il nome della persona: non lo si rimanda
	}

	// ---------- Certificati ----------

	/** @throws \InvalidArgumentException */
	public static function add( int $person_id, array $in, ?array $file = null ): int {
		if ( ! MemberQuery::find( $person_id ) ) {
			throw new \InvalidArgumentException( 'Il certificato si registra per un socio attivo.' );
		}
		$d = CertificateRules::clean( $in );
		if ( $file ) {
			$d['file_name']   = $file['name'];
			$d['file_stored'] = Attachments::save_upload( $file );
			$d['file_size']   = $file['size'];
		}
		if ( ! self::db()->insert( Db::t( 'medical_certs' ), $d + array( 'person_id' => $person_id, 'created_at' => Db::now() ) ) ) {
			Attachments::delete_stored( (string) ( $d['file_stored'] ?? '' ) ); // niente file orfano nella cartella privata
			throw new \InvalidArgumentException( 'Impossibile salvare il certificato (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		self::flush_counts();
		Audit::log( 'medical.added', 'medical_cert', $id ); // solo l'azione
		return $id;
	}

	/** @throws \InvalidArgumentException */
	public static function delete( int $id ): void {
		$c = self::get( $id );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'Certificato non trovato.' );
		}
		self::db()->delete( Db::t( 'medical_certs' ), array( 'id' => $id ) ); // dato sanitario: si cancella davvero, non resta nulla nel database
		Attachments::delete_stored( (string) $c['file_stored'] ); // il file non resta sul disco (né nelle copie di sicurezza)
		self::flush_counts();
		Audit::log( 'medical.deleted', 'medical_cert', $id );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'medical_certs' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Certificati di una persona, dal più recente. @return array[] */
	public static function for_person( int $person_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'medical_certs' ) . ' WHERE person_id = %d AND deleted_at IS NULL ORDER BY expires_on DESC, id DESC', $person_id ), ARRAY_A ) ?: array();
	}

	/** Certificati di più persone. @return array<int,array[]> person_id => certificati */
	private static function for_people( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$db  = self::db();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		foreach ( $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'medical_certs' ) . " WHERE deleted_at IS NULL AND person_id IN ($in) ORDER BY expires_on DESC, id DESC", $ids ), ARRAY_A ) ?: array() as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- i segnaposto sono %d, uno per id
			$out[ (int) $c['person_id'] ][] = $c;
		}
		return $out;
	}

	/** Stato di una persona. @return array{status:string,cert:?array,days_left:?int} */
	public static function status_of( int $person_id, ?string $today = null ): array {
		$today = $today ?: Db::today();
		return self::summarize( self::for_person( $person_id ), $today );
	}

	/** @return array{status:string,cert:?array,days_left:?int} */
	private static function summarize( array $certs, string $today ): array {
		$cur = CertificateRules::current( $certs, $today );
		return array(
			'status'    => CertificateRules::status( $certs, $today, self::warn_days() ),
			'cert'      => $cur,
			'days_left' => $cur ? ExpiryRules::days_left( (string) $cur['expires_on'], $today ) : null,
		);
	}

	// ---------- Attività che lo richiedono ----------

	public static function is_required( int $activity_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'cert_activities' ) . ' WHERE activity_id = %d', $activity_id ) );
	}

	public static function set_required( int $activity_id, bool $on ): void {
		if ( $on ) {
			self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'cert_activities' ) . ' (activity_id) VALUES (%d)', $activity_id ) );
		} else {
			self::db()->delete( Db::t( 'cert_activities' ), array( 'activity_id' => $activity_id ) );
		}
		self::flush_counts();
	}

	/** @return int[] */
	public static function required_ids(): array {
		return array_map( 'intval', self::db()->get_col( 'SELECT c.activity_id FROM ' . Db::t( 'cert_activities' ) . ' c JOIN ' . Db::t( 'activities' ) . ' a ON a.id = c.activity_id WHERE a.deleted_at IS NULL' ) );
	}

	// ---------- Elenco ----------

	/**
	 * I soci con il loro certificato. Predefinito: gli iscritti alle attività che richiedono il certificato; con un'attività, i suoi iscritti;
	 * con `all`, tutti i soci attivi.
	 *
	 * @return array[] ogni riga: person, cert, status, days_left, activities (nomi delle attività che lo richiedono)
	 */
	public static function rows( string $filter = '', int $activity_id = 0, bool $all = false, ?string $today = null ): array {
		$today  = $today ?: Db::today();
		$people = array();
		$why    = array();
		if ( $all ) {
			foreach ( MemberQuery::active() as $p ) {
				$people[ (int) $p['id'] ] = $p;
			}
		} else {
			$ids = $activity_id > 0 ? array( $activity_id ) : self::required_ids();
			foreach ( $ids as $aid ) {
				$a = Plugin::activities()->get( $aid );
				foreach ( MemberQuery::enrolled_in( $aid ) as $p ) {
					$people[ (int) $p['id'] ] = $p;
					$why[ (int) $p['id'] ][]  = $a ? (string) $a['name'] : '';
				}
			}
			uasort(
				$people,
				function ( $x, $y ) {
					return strcasecmp( MemberQuery::name( $x ), MemberQuery::name( $y ) );
				}
			);
		}
		$certs = self::for_people( array_keys( $people ) );
		$out   = array();
		foreach ( $people as $id => $p ) {
			$s     = self::summarize( $certs[ $id ] ?? array(), $today );
			$out[] = array( 'person' => $p, 'cert' => $s['cert'], 'status' => $s['status'], 'days_left' => $s['days_left'], 'activities' => array_values( array_unique( array_filter( $why[ $id ] ?? array() ) ) ) );
		}
		return CertificateRules::filter_by_status( $out, $filter );
	}

	const COUNTS_KEY = 'asem_cert_counts';

	/** Conteggi per la Bacheca; si ricalcolano al massimo ogni 5 minuti o appena cambia un certificato. @return array<string,int> */
	public static function counts( ?string $today = null ): array {
		if ( null === $today ) {
			$cached = get_transient( self::COUNTS_KEY );
			if ( is_array( $cached ) && ( $cached['day'] ?? '' ) === Db::today() ) {
				return $cached['counts'];
			}
		}
		$c = array( ExpiryRules::VALID => 0, ExpiryRules::EXPIRING => 0, ExpiryRules::EXPIRED => 0, ExpiryRules::NONE => 0 );
		foreach ( self::rows( '', 0, false, $today ) as $r ) {
			$c[ $r['status'] ]++;
		}
		if ( null === $today ) {
			set_transient( self::COUNTS_KEY, array( 'day' => Db::today(), 'counts' => $c ), 5 * MINUTE_IN_SECONDS );
		}
		return $c;
	}

	/** Un certificato o un'attività sono cambiati: i conteggi della Bacheca si rifanno. */
	public static function flush_counts(): void {
		delete_transient( self::COUNTS_KEY );
	}

	// ---------- Esportazione ----------

	/** Righe del file CSV (intestazione compresa): quello che serve alla segreteria, senza note né nome del file. @return array[] */
	public static function csv_rows( string $filter = '', int $activity_id = 0, bool $all = false ): array {
		$out = array( array( 'Cognome', 'Nome', 'Stato', 'Tipo', 'Rilasciato il', 'Scade il', 'Medico o struttura', 'Attività che lo richiedono' ) );
		foreach ( self::rows( $filter, $activity_id, $all ) as $r ) {
			$c     = $r['cert'];
			$out[] = array(
				(string) $r['person']['last_name'],
				(string) $r['person']['first_name'],
				ExpiryRules::labels()[ $r['status'] ],
				$c ? CertificateRules::kinds()[ $c['kind'] ] ?? (string) $c['kind'] : '',
				$c ? ExpiryRules::it_date( (string) $c['issued_on'] ) : '',
				$c ? ExpiryRules::it_date( (string) $c['expires_on'] ) : '',
				$c ? (string) $c['doctor'] : '',
				implode( ' · ', $r['activities'] ),
			);
		}
		return $out;
	}

	// ---------- Privacy ----------

	/** Esportazione dei dati della persona (diritto di accesso). */
	public static function privacy_export( array $data, int $person_id ): array {
		$rows = array();
		foreach ( array_reverse( self::for_person( $person_id ) ) as $c ) {
			$rows[] = array(
				'tipo'      => CertificateRules::kinds()[ $c['kind'] ] ?? (string) $c['kind'],
				'rilasciato' => $c['issued_on'],
				'scadenza'  => $c['expires_on'],
				'medico'    => (string) $c['doctor'],
				'file'      => ! empty( $c['file_stored'] ) ? 'allegato presente' : '',
			);
		}
		$data['certificati_medici'] = $rows;
		return $data;
	}

	/** Anonimizzazione: i certificati e i loro file si cancellano del tutto (dato sanitario). */
	public static function anonymize( int $person_id ): void {
		$db = self::db();
		foreach ( $db->get_results( $db->prepare( 'SELECT id, file_stored FROM ' . Db::t( 'medical_certs' ) . ' WHERE person_id = %d', $person_id ), ARRAY_A ) ?: array() as $c ) {
			Attachments::delete_stored( (string) $c['file_stored'] );
		}
		$db->delete( Db::t( 'medical_certs' ), array( 'person_id' => $person_id ) );
		self::flush_counts();
	}

	// ---------- Promemoria ----------

	public static function reminders_enabled(): bool {
		return ! empty( Settings::get( 'cert_reminders' ) );
	}

	/**
	 * Promemoria ai soci con il certificato in scadenza o scaduto da poco e, in un solo messaggio, allo staff. Una sola volta per tipo e certificato.
	 *
	 * @return array{members:int,staff:int} promemoria mandati (o, senza invio, da mandare)
	 */
	public static function run_reminders( ?string $today = null, bool $send = true ): array {
		$out = array( 'members' => 0, 'staff' => 0 );
		if ( ! Edition::has( 'medical' ) || ( $send && ! self::reminders_enabled() ) ) {
			return $out;
		}
		$today = $today ?: Db::today();
		$lines = array();
		$keys  = array();
		foreach ( self::rows( '', 0, false, $today ) as $r ) {
			$c = $r['cert'];
			if ( ! $c ) {
				continue;
			}
			$kind = CertificateRules::reminder_kind( $r['status'], (int) $r['days_left'] );
			if ( '' === $kind ) {
				continue;
			}
			$p    = $r['person'];
			$ref  = CertificateRules::reminder_ref( (int) $c['id'], (string) $c['expires_on'] );
			$when = ExpiryRules::it_date( (string) $c['expires_on'] );
			$to    = Reminders::recipient( $p );
			$about = $to ? (string) $to['about'] : ''; // per un minore (o un ospite) il messaggio va a un adulto e parla di un'altra persona
			$whose = '' !== $about ? 'di ' . $about : 'tuo';
			if ( ExpiryRules::EXPIRING === $r['status'] ) {
				$subject = '' !== $about ? 'Il certificato medico di ' . $about . ' sta per scadere' : 'Il tuo certificato medico sta per scadere';
				$body    = ( '' !== $about ? 'il certificato medico sportivo ' . $whose : 'il ' . $whose . ' certificato medico sportivo' ) . ' scade il ' . $when . '. Per continuare a partecipare alle attività che lo richiedono portane uno nuovo alla segreteria prima di quella data.';
			} else {
				$subject = '' !== $about ? 'Il certificato medico di ' . $about . ' è scaduto' : 'Il tuo certificato medico è scaduto';
				$body    = ( '' !== $about ? 'il certificato medico sportivo ' . $whose : 'il ' . $whose . ' certificato medico sportivo' ) . ' è scaduto il ' . $when . '. Per continuare a partecipare alle attività che lo richiedono portane uno nuovo alla segreteria.';
			}
			if ( $send ? Reminders::notify( $p, $kind, $ref, $subject, $body ) : Reminders::pending( $p, $kind, $ref ) ) {
				$out['members']++;
			}
			if ( StaffReminders::fresh( (int) $p['id'], 'cert_staff', $ref, $send ) ) {
				$lines[] = MemberQuery::name( $p ) . ' — ' . ( ExpiryRules::EXPIRING === $r['status'] ? 'scade il ' : 'scaduto il ' ) . $when;
				$keys[]  = array( (int) $p['id'], 'cert_staff', $ref );
			}
		}
		$out['staff'] = StaffReminders::digest( 'Certificati medici in scadenza o scaduti', 'Questi certificati medici richiedono attenzione:', "L'elenco completo è in Associazione Semplice → Registri → Certificati medici.", $lines, $keys, $send );
		return $out;
	}

	/** Lavoro periodico (una volta al giorno). */
	public static function cron(): void {
		self::run_reminders();
	}
}

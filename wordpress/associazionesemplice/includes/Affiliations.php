<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Affiliazioni agli enti di promozione sportiva (CONI/RAS, UISP, CSEN, ACSI, AICS, altri): l'affiliazione dell'associazione (codice, stagione,
 * scadenza, costo) e il tesseramento dei soci (numero di tessera, stagione, scadenza, costo), singolo o in blocco. Elenco con filtri, file CSV per
 * i portali degli enti e promemoria di scadenza. Il tutore e l'ospite non sono soci: non si tesserano.
 */
final class Affiliations {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function warn_days(): int {
		return ExpiryRules::warn_days( Settings::get( 'aff_warn_days' ) );
	}

	/** Stato di una scadenza facoltativa: senza data non scade mai, quindi è in regola. */
	private static function state( ?string $expires_on, string $today ): string {
		return null === $expires_on || '' === $expires_on ? ExpiryRules::VALID : ExpiryRules::status( $expires_on, $today, self::warn_days() );
	}

	public static function body_name( array $row ): string {
		return AffiliationRules::body_label( (string) $row['body'], (string) $row['body_label'] );
	}

	// ---------- Affiliazioni dell'associazione ----------

	/** @throws \InvalidArgumentException */
	public static function save_affiliation( int $id, array $in ): int {
		$d  = AffiliationRules::clean_affiliation( $in );
		$db = self::db();
		if ( $id ) {
			if ( ! self::affiliation( $id ) ) {
				throw new \InvalidArgumentException( 'Affiliazione non trovata.' );
			}
			$db->update( Db::t( 'affiliations' ), $d, array( 'id' => $id ) );
			self::flush_counts();
			Audit::log( 'affiliation.updated', 'affiliation', $id );
			return $id;
		}
		$dup = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'affiliations' ) . ' WHERE body = %s AND body_label = %s AND season = %s AND deleted_at IS NULL', $d['body'], $d['body_label'], $d['season'] ) );
		if ( $dup ) {
			throw new \InvalidArgumentException( 'C\'è già un\'affiliazione a questo ente per la stessa stagione: modificala invece di aggiungerne un\'altra.' );
		}
		if ( ! $db->insert( Db::t( 'affiliations' ), $d + array( 'created_at' => Db::now() ) ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare l\'affiliazione (errore del database).' );
		}
		$id = (int) $db->insert_id;
		self::flush_counts();
		Audit::log( 'affiliation.added', 'affiliation', $id, array( 'body' => $d['body'], 'season' => $d['season'] ) );
		return $id;
	}

	/** @throws \InvalidArgumentException */
	public static function delete_affiliation( int $id ): void {
		if ( ! self::affiliation( $id ) ) {
			throw new \InvalidArgumentException( 'Affiliazione non trovata.' );
		}
		self::db()->update( Db::t( 'affiliations' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		self::flush_counts();
		Audit::log( 'affiliation.deleted', 'affiliation', $id );
	}

	public static function affiliation( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'affiliations' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Affiliazioni, dalla stagione più recente. Ogni riga ha `status`. @return array[] */
	public static function affiliations( string $body = '', string $status = '', ?string $today = null ): array {
		$today = $today ?: Db::today();
		$db    = self::db();
		$sql   = 'SELECT * FROM ' . Db::t( 'affiliations' ) . ' WHERE deleted_at IS NULL';
		$args  = array();
		if ( '' !== $body && AffiliationRules::is_body( $body ) ) {
			$sql   .= ' AND body = %s';
			$args[] = $body;
		}
		$sql .= ' ORDER BY season DESC, body, id DESC';
		$out  = array();
		foreach ( $db->get_results( $args ? $db->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- senza filtri la query non ha dati esterni
			$r['status'] = self::state( $r['expires_on'] ? (string) $r['expires_on'] : null, $today );
			if ( '' === $status || $r['status'] === $status ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	// ---------- Tessere dei soci ----------

	/**
	 * @return int id della tessera
	 * @throws \InvalidArgumentException
	 */
	public static function add_card( int $person_id, array $in ): int {
		$p = MemberQuery::find( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Si tessera un socio attivo.' );
		}
		return self::insert_card( $person_id, AffiliationRules::clean_card( $in ) );
	}

	/** @throws \InvalidArgumentException */
	private static function insert_card( int $person_id, array $d ): int {
		$db  = self::db();
		$dup = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'body_cards' ) . ' WHERE person_id = %d AND body = %s AND body_label = %s AND season = %s AND deleted_at IS NULL', $person_id, $d['body'], $d['body_label'], $d['season'] ) );
		if ( $dup ) {
			throw new \InvalidArgumentException( 'Questo socio ha già una tessera di questo ente per la stessa stagione.' );
		}
		if ( ! $db->insert( Db::t( 'body_cards' ), $d + array( 'person_id' => $person_id, 'created_at' => Db::now() ) ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la tessera (errore del database).' );
		}
		$id = (int) $db->insert_id;
		self::flush_counts();
		Audit::log( 'body_card.added', 'person', $person_id, array( 'card' => $id, 'body' => $d['body'], 'season' => $d['season'] ) );
		return $id;
	}

	/**
	 * Tessera tutti i soci attivi, o gli iscritti a un'attività. Il numero di tessera resta vuoto (si compila dopo, dal portale dell'ente).
	 *
	 * @return array{created:int,skipped:int} tessere create e soci che l'avevano già
	 * @throws \InvalidArgumentException
	 */
	public static function bulk_add( array $in, int $activity_id = 0 ): array {
		$d = AffiliationRules::clean_card( array_merge( $in, array( 'card_number' => '' ) ) );
		if ( $activity_id > 0 ) {
			$people = MemberQuery::enrolled_in( $activity_id );
			if ( ! $people && ! Plugin::activities()->get( $activity_id ) ) {
				throw new \InvalidArgumentException( 'Attività non trovata.' );
			}
		} else {
			$people = MemberQuery::active();
		}
		$db      = self::db();
		$already = array_map( 'intval', $db->get_col( $db->prepare( 'SELECT person_id FROM ' . Db::t( 'body_cards' ) . ' WHERE body = %s AND body_label = %s AND season = %s AND deleted_at IS NULL', $d['body'], $d['body_label'], $d['season'] ) ) );
		$ids     = AffiliationRules::new_card_targets( array_column( $people, 'id' ), $already );
		$created = 0;
		foreach ( $ids as $pid ) {
			try {
				self::insert_card( $pid, $d );
				$created++;
			} catch ( \InvalidArgumentException $e ) {
				continue; // tessera comparsa nel frattempo: si salta
			}
		}
		Audit::log( 'body_card.bulk', 'settings', 0, array( 'created' => $created, 'body' => $d['body'], 'season' => $d['season'] ) );
		return array( 'created' => $created, 'skipped' => count( $people ) - $created );
	}

	/**
	 * Cambia numero, tipo, data e scadenza di una tessera (ente, stagione e costo restano).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function update_card( int $id, array $changes ): void {
		$c = self::card( $id );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'Tessera non trovata.' );
		}
		$in = array(
			'body'        => $c['body'],
			'body_label'  => $c['body_label'],
			'season'      => $c['season'],
			'cost'        => null === $c['cost_cents'] ? '' : Money::plain( (int) $c['cost_cents'] ),
			'card_number' => $changes['card_number'] ?? $c['card_number'],
			'card_type'   => $changes['card_type'] ?? $c['card_type'],
			'issued_on'   => $changes['issued_on'] ?? $c['issued_on'],
			'expires_on'  => $changes['expires_on'] ?? $c['expires_on'],
		);
		self::db()->update( Db::t( 'body_cards' ), AffiliationRules::clean_card( $in ), array( 'id' => $id ) );
		self::flush_counts();
		Audit::log( 'body_card.updated', 'person', (int) $c['person_id'], array( 'card' => $id ) );
	}

	/** @throws \InvalidArgumentException */
	public static function delete_card( int $id ): void {
		$c = self::card( $id );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'Tessera non trovata.' );
		}
		self::db()->update( Db::t( 'body_cards' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		self::flush_counts();
		Audit::log( 'body_card.deleted', 'person', (int) $c['person_id'], array( 'card' => $id ) );
	}

	public static function card( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'body_cards' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Tessere di una persona, dalla più recente. @return array[] */
	public static function cards_for_person( int $person_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'body_cards' ) . ' WHERE person_id = %d AND deleted_at IS NULL ORDER BY season DESC, id DESC', $person_id ), ARRAY_A ) ?: array();
	}

	/**
	 * Elenco delle tessere con i dati della persona e `status`. Filtri: ente, stagione, stato, attività (iscritti).
	 *
	 * @param array{body?:string,season?:string,status?:string,activity?:int} $f
	 * @return array[]
	 */
	public static function cards( array $f = array(), ?string $today = null ): array {
		$today = $today ?: Db::today();
		$db    = self::db();
		$sql   = 'SELECT c.*, p.first_name, p.last_name, p.tax_code, p.birth_date, p.address, p.zip, p.city, p.province, p.email, p.phone, p.affiliation_role FROM ' . Db::t( 'body_cards' ) . ' c JOIN ' . Db::t( 'people' ) . ' p ON p.id = c.person_id WHERE c.deleted_at IS NULL AND p.deleted_at IS NULL AND p.anonymized_at IS NULL';
		$args  = array();
		if ( ! empty( $f['body'] ) && AffiliationRules::is_body( (string) $f['body'] ) ) {
			$sql   .= ' AND c.body = %s';
			$args[] = (string) $f['body'];
		}
		if ( ! empty( $f['season'] ) ) {
			$sql   .= ' AND c.season = %s';
			$args[] = (string) $f['season'];
		}
		$keep = null;
		if ( ! empty( $f['activity'] ) ) {
			$keep = array_flip( array_map( 'intval', array_column( MemberQuery::enrolled_in( (int) $f['activity'] ), 'id' ) ) );
		}
		$sql .= ' ORDER BY p.last_name, p.first_name, c.season DESC, c.id';
		$out  = array();
		foreach ( $db->get_results( $args ? $db->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- senza filtri la query non ha dati esterni
			if ( null !== $keep && ! isset( $keep[ (int) $r['person_id'] ] ) ) {
				continue;
			}
			$r['status'] = self::state( $r['expires_on'] ? (string) $r['expires_on'] : null, $today );
			if ( empty( $f['status'] ) || $r['status'] === $f['status'] ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/** Stagioni presenti nelle tessere e nelle affiliazioni, per i filtri. @return string[] */
	public static function seasons(): array {
		$db  = self::db();
		$all = array_merge(
			$db->get_col( 'SELECT DISTINCT season FROM ' . Db::t( 'body_cards' ) . ' WHERE deleted_at IS NULL' ),
			$db->get_col( 'SELECT DISTINCT season FROM ' . Db::t( 'affiliations' ) . ' WHERE deleted_at IS NULL' )
		);
		$all = array_values( array_unique( array_map( 'strval', $all ) ) );
		rsort( $all );
		return $all;
	}

	// ---------- Esportazione ----------

	/** Righe del file per i portali degli enti (intestazione compresa), una per tessera. @return array[] */
	public static function portal_rows( array $f = array() ): array {
		$out   = array( AffiliationRules::CSV_HEADER );
		$cards = self::cards( $f );
		$sport = self::sports_by_person( array_map( 'intval', array_column( $cards, 'person_id' ) ) );
		foreach ( $cards as $c ) {
			$type = '' !== trim( (string) $c['card_type'] ) ? (string) $c['card_type'] : MembershipRoles::label( $c['affiliation_role'] ?? null ); // senza un tipo scritto sulla tessera vale il tipo di tesseramento della scheda
			$out[] = AffiliationRules::csv_row( $c, $type, $sport[ (int) $c['person_id'] ] ?? array() );
		}
		return $out;
	}

	/**
	 * Sport e discipline delle attività a cui ogni persona partecipa (iscrizioni ai corsi in corso e prenotazioni degli eventi), per le tessere degli enti.
	 *
	 * @param int[] $ids
	 * @return array<int,array{sport:string,discipline:string}>
	 */
	public static function sports_by_person( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$db   = self::db();
		$in   = implode( ',', $ids );
		$rows = array_merge(
			$db->get_results( 'SELECT e.person_id, a.sport, a.discipline FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . " a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.end_month IS NULL AND e.person_id IN ($in) AND (a.sport IS NOT NULL OR a.discipline IS NOT NULL)", ARRAY_A ) ?: array(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id numerici
			$db->get_results( 'SELECT b.person_id, a.sport, a.discipline FROM ' . Db::t( 'bookings' ) . " b JOIN " . Db::t( 'sessions' ) . ' s ON s.id = b.session_id JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.status = 'booked' AND b.person_id IN ($in) AND (a.sport IS NOT NULL OR a.discipline IS NOT NULL)", ARRAY_A ) ?: array() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id numerici
		);
		$tmp = array();
		foreach ( $rows as $r ) {
			$pid = (int) $r['person_id'];
			foreach ( array( 'sport', 'discipline' ) as $k ) {
				if ( '' !== trim( (string) $r[ $k ] ) ) {
					$tmp[ $pid ][ $k ][ (string) $r[ $k ] ] = true;
				}
			}
		}
		$out = array();
		foreach ( $tmp as $pid => $x ) {
			$out[ $pid ] = array( 'sport' => implode( '; ', array_keys( $x['sport'] ?? array() ) ), 'discipline' => implode( '; ', array_keys( $x['discipline'] ?? array() ) ) );
		}
		return $out;
	}

	/** Righe del file dell'elenco: tessere con numero, ente, stagione, scadenza e costo. @return array[] */
	public static function list_rows( array $f = array() ): array {
		$out = array( array( 'Cognome', 'Nome', 'Codice fiscale', 'Ente', 'Numero tessera', 'Tipo tessera', 'Stagione', 'Data', 'Scade il', 'Costo', 'Stato' ) );
		foreach ( self::cards( $f ) as $c ) {
			$out[] = array(
				(string) $c['last_name'],
				(string) $c['first_name'],
				strtoupper( (string) $c['tax_code'] ),
				self::body_name( $c ),
				(string) $c['card_number'],
				(string) $c['card_type'],
				(string) $c['season'],
				$c['issued_on'] ? ExpiryRules::it_date( (string) $c['issued_on'] ) : '',
				$c['expires_on'] ? ExpiryRules::it_date( (string) $c['expires_on'] ) : '',
				null === $c['cost_cents'] ? '' : Money::plain( (int) $c['cost_cents'] ),
				ExpiryRules::labels()[ $c['status'] ],
			);
		}
		return $out;
	}

	// ---------- Privacy ----------

	public static function privacy_export( array $data, int $person_id ): array {
		$rows = array();
		foreach ( array_reverse( self::cards_for_person( $person_id ) ) as $c ) {
			$rows[] = array(
				'ente'     => self::body_name( $c ),
				'numero'   => (string) $c['card_number'],
				'tipo'     => (string) $c['card_type'],
				'stagione' => $c['season'],
				'data'     => $c['issued_on'],
				'scadenza' => $c['expires_on'],
				'costo'    => null === $c['cost_cents'] ? '' : Money::plain( (int) $c['cost_cents'] ),
			);
		}
		$data['tessere_enti'] = $rows;
		return $data;
	}

	/** Anonimizzazione: le tessere della persona si cancellano. */
	public static function anonymize( int $person_id ): void {
		self::db()->delete( Db::t( 'body_cards' ), array( 'person_id' => $person_id ) );
		self::flush_counts();
	}

	// ---------- Promemoria ----------

	public static function reminders_enabled(): bool {
		return ! empty( Settings::get( 'aff_reminders' ) );
	}

	/**
	 * Promemoria di scadenza: ai soci per la loro tessera (una sola volta per tipo e tessera) e alla segreteria, in un solo messaggio, per le
	 * affiliazioni e le tessere.
	 *
	 * @return array{members:int,staff:int} promemoria mandati (o, senza invio, da mandare)
	 */
	public static function run_reminders( ?string $today = null, bool $send = true ): array {
		$out = array( 'members' => 0, 'staff' => 0 );
		if ( ! Edition::has( 'affiliations' ) || ( $send && ! self::reminders_enabled() ) ) {
			return $out;
		}
		$today = $today ?: Db::today();
		$lines = array();
		$keys  = array();
		foreach ( self::affiliations( '', '', $today ) as $a ) {
			if ( ! $a['expires_on'] ) {
				continue;
			}
			$kind = AffiliationRules::reminder_kind( 'aff', $a['status'], ExpiryRules::days_left( (string) $a['expires_on'], $today ) );
			$ref  = $a['id'] . ':' . $a['expires_on'];
			if ( '' !== $kind && StaffReminders::fresh( 0, $kind, $ref, $send ) ) {
				$lines[] = 'Affiliazione ' . self::body_name( $a ) . ' ' . $a['season'] . ' (codice ' . $a['code'] . ') — ' . ( ExpiryRules::EXPIRING === $a['status'] ? 'scade il ' : 'scaduta il ' ) . ExpiryRules::it_date( (string) $a['expires_on'] );
				$keys[]  = array( 0, $kind, $ref );
			}
		}
		foreach ( self::cards( array(), $today ) as $c ) {
			if ( ! $c['expires_on'] ) {
				continue;
			}
			$kind = AffiliationRules::reminder_kind( 'card', $c['status'], ExpiryRules::days_left( (string) $c['expires_on'], $today ) );
			if ( '' === $kind ) {
				continue;
			}
			$pid  = (int) $c['person_id'];
			$ref  = $c['id'] . ':' . $c['expires_on'];
			$when = ExpiryRules::it_date( (string) $c['expires_on'] );
			$p    = Plugin::people()->get( $pid );
			if ( $p ) {
				$soon    = ExpiryRules::EXPIRING === $c['status'];
				$to      = Reminders::recipient( $p );
				$about   = $to ? (string) $to['about'] : ''; // per un minore il messaggio va a un adulto e parla di un'altra persona
				$card    = 'tessera ' . self::body_name( $c );
				$subject = '' !== $about ? ( $soon ? 'La ' . $card . ' di ' . $about . ' sta per scadere' : 'La ' . $card . ' di ' . $about . ' è scaduta' ) : ( $soon ? 'La ' . $card . ' sta per scadere' : 'La ' . $card . ' è scaduta' );
				$body    = ( '' !== $about ? 'la ' . $card . ' di ' . $about : 'la tua ' . $card ) . ' (stagione ' . $c['season'] . ') ' . ( $soon ? 'scade il ' : 'è scaduta il ' ) . $when . '. Per rinnovarla rivolgiti alla segreteria.';
				if ( $send ? Reminders::notify( $p, $kind, $ref, $subject, $body ) : Reminders::pending( $p, $kind, $ref ) ) {
					$out['members']++;
				}
			}
			if ( StaffReminders::fresh( $pid, $kind . '_s', $ref, $send ) ) { // il tipo con «_s» distingue la riga della segreteria da quella del socio
				$lines[] = 'Tessera ' . self::body_name( $c ) . ' di ' . trim( $c['last_name'] . ' ' . $c['first_name'] ) . ' — ' . ( ExpiryRules::EXPIRING === $c['status'] ? 'scade il ' : 'scaduta il ' ) . $when;
				$keys[]  = array( $pid, $kind . '_s', $ref );
			}
		}
		$out['staff'] = StaffReminders::digest( 'Affiliazioni e tessere in scadenza', 'Queste affiliazioni e tessere richiedono attenzione:', "L'elenco completo è in Associazione Semplice → Registri → Affiliazioni.", $lines, $keys, $send );
		return $out;
	}

	/** Lavoro periodico (una volta al giorno). */
	public static function cron(): void {
		self::run_reminders();
	}

	const COUNTS_KEY = 'asem_aff_counts';

	/** Un'affiliazione o una tessera sono cambiate: i conteggi della Bacheca si rifanno. */
	public static function flush_counts(): void {
		delete_transient( self::COUNTS_KEY );
	}

	/** Conteggi per la Bacheca: affiliazioni e tessere scadute o in scadenza. @return array{aff_expired:int,aff_expiring:int,card_expired:int,card_expiring:int} */
	public static function counts( ?string $today = null ): array {
		$cache = null === $today;
		if ( $cache ) { // si ricalcolano al massimo ogni 5 minuti o appena qualcosa cambia
			$cached = get_transient( self::COUNTS_KEY );
			if ( is_array( $cached ) && ( $cached['day'] ?? '' ) === Db::today() ) {
				return $cached['counts'];
			}
		}
		$n = array( 'aff_expired' => 0, 'aff_expiring' => 0, 'card_expired' => 0, 'card_expiring' => 0 );
		$today = $today ?: Db::today();
		$recent = function ( array $r ) use ( $today ): bool { // una scadenza vecchia non deve restare per sempre tra gli avvisi
			return ExpiryRules::days_left( (string) $r['expires_on'], $today ) >= -AffiliationRules::EXPIRED_WINDOW;
		};
		foreach ( self::affiliations( '', '', $today ) as $a ) {
			if ( ExpiryRules::EXPIRED === $a['status'] ) {
				if ( ! $recent( $a ) ) {
					continue;
				}
				$n['aff_expired']++;
			} elseif ( ExpiryRules::EXPIRING === $a['status'] ) {
				$n['aff_expiring']++;
			}
		}
		foreach ( self::cards( array(), $today ) as $c ) {
			if ( ExpiryRules::EXPIRED === $c['status'] ) {
				if ( ! $recent( $c ) ) {
					continue;
				}
				$n['card_expired']++;
			} elseif ( ExpiryRules::EXPIRING === $c['status'] ) {
				$n['card_expiring']++;
			}
		}
		if ( $cache ) {
			set_transient( self::COUNTS_KEY, array( 'day' => Db::today(), 'counts' => $n ), 5 * MINUTE_IN_SECONDS );
		}
		return $n;
	}
}

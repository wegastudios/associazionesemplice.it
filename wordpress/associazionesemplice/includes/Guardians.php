<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Minori e tutori. Un minore può non avere né email né accesso: il suo tutore (un genitore, uno solo) lo vede nella propria area riservata,
 * paga per lui e riceve le sue email. Il tutore è una persona già registrata con una email. A 18 anni i permessi del tutore si spengono da soli
 * (anche se il ragazzo non ha ancora un accesso suo: maggiorenne è maggiorenne); il collegamento resta registrato finché non lo toglie
 * l'amministratore e la dashboard avvisa che il ragazzo va invitato a creare il suo accesso.
 */
final class Guardians {

	const MAX = 1;

	/** Ragazzi diventati maggiorenni che hanno già ricevuto l'invito a creare il loro accesso (id persona). */
	const INVITED_OPTION = 'asem_adult_invited';

	private static function db(): \wpdb {
		return Db::db();
	}

	/**
	 * Regole pure del collegamento (nessun database).
	 *
	 * @param array $minor    la persona da collegare (con birth_date)
	 * @param array $guardian il tutore (con type, email, birth_date)
	 * @return string[] errori (vuoto = collegamento possibile)
	 */
	public static function validate( array $minor, array $guardian, int $already_linked, string $today ): array {
		$errors = array();
		if ( (int) $minor['id'] === (int) $guardian['id'] ) {
			$errors[] = 'Una persona non può essere tutore di se stessa.';
		}
		if ( empty( $minor['birth_date'] ) ) {
			$errors[] = 'Per collegare un tutore serve la data di nascita del minore.';
		}
		if ( ! MemberType::can_login( (string) ( $guardian['type'] ?? '' ) ) ) {
			$errors[] = 'Il tutore deve poter entrare nell\'area riservata: un socio o una persona registrata come genitore o tutore (un ospite non può).';
		}
		$email = trim( (string) ( $guardian['email'] ?? '' ) );
		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = 'Il tutore deve avere un\'email.';
		}
		if ( Age::is_minor( $guardian['birth_date'] ?? null, $today ) ) {
			$errors[] = 'Il tutore deve essere maggiorenne.';
		}
		if ( $already_linked >= self::MAX ) {
			$errors[] = 'Un minore ha un solo tutore: toglilo prima di collegarne un altro.';
		}
		return $errors;
	}

	// ---------- Lettura ----------

	/** I tutori di una persona. @return array[] persone */
	public static function of( int $person_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT p.* FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.guardian_person_id WHERE g.person_id = %d AND p.deleted_at IS NULL ORDER BY g.id',
				$person_id
			),
			ARRAY_A
		) ?: array();
	}

	/** Nata dopo questa data = ancora minorenne (a 18 anni il tutore non ha più alcun potere). */
	private static function minor_after(): string {
		if ( ! Limits::flag( 'guardian_until_adult' ) ) {
			return '1000-01-01'; // limite spento: il tutore resta attivo anche dopo i 18 anni (comportamento di prima)
		}
		return ( new \DateTimeImmutable( Db::today() ) )->modify( '-' . Age::ADULT_AT . ' years' )->format( 'Y-m-d' );
	}

	/** Le persone di cui uno è tutore: solo finché sono minorenni (senza data di nascita il collegamento vale come prima). @return array[] persone */
	public static function dependents_of( int $guardian_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT p.* FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.person_id WHERE g.guardian_person_id = %d AND p.deleted_at IS NULL AND p.application_status IS NULL AND ( p.birth_date IS NULL OR p.birth_date > %s ) ORDER BY p.last_name, p.first_name',
				$guardian_id,
				self::minor_after()
			),
			ARRAY_A
		) ?: array();
	}

	/** Uno è tutore di quella persona? Solo finché la persona è minorenne: ai 18 anni i permessi del tutore si spengono da soli. */
	public static function is_guardian_of( int $guardian_id, int $person_id ): bool {
		if ( $guardian_id <= 0 || $person_id <= 0 ) {
			return false;
		}
		return (bool) self::db()->get_var(
			self::db()->prepare(
				'SELECT COUNT(*) FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.person_id WHERE g.guardian_person_id = %d AND g.person_id = %d AND ( p.birth_date IS NULL OR p.birth_date > %s )',
				$guardian_id,
				$person_id,
				self::minor_after()
			)
		);
	}

	/** Persona con almeno un tutore collegato? */
	public static function has_guardian( int $person_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'guardians' ) . ' WHERE person_id = %d', $person_id ) );
	}

	/** Per l'elenco soci/libro soci: id persona => nomi dei tutori. @return array<int,string> */
	public static function names_by_person(): array {
		$rows = self::db()->get_results(
			'SELECT g.person_id, CONCAT(p.first_name, " ", p.last_name) AS name FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.guardian_person_id WHERE p.deleted_at IS NULL ORDER BY g.id',
			ARRAY_A
		) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$k         = (int) $r['person_id'];
			$out[ $k ] = isset( $out[ $k ] ) ? $out[ $k ] . ', ' . $r['name'] : $r['name'];
		}
		return $out;
	}

	/**
	 * I tutori di molte persone con una sola interrogazione (per gli avvisi ai partecipanti).
	 *
	 * @param int[] $person_ids
	 * @return array<int,array[]> id persona => tutori
	 */
	public static function map_for( array $person_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $person_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = self::db()->get_results(
			'SELECT g.person_id AS ward_id, p.* FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.guardian_person_id WHERE p.deleted_at IS NULL AND g.person_id IN (' . implode( ',', $ids ) . ') ORDER BY g.id',
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$ward = (int) $r['ward_id'];
			unset( $r['ward_id'] );
			$out[ $ward ][] = $r;
		}
		return $out;
	}

	// ---------- Scrittura ----------

	/** @throws \InvalidArgumentException */
	public static function link( int $person_id, int $guardian_id ): void {
		$people   = Plugin::people();
		$minor    = $people->get( $person_id );
		$guardian = $people->get( $guardian_id );
		if ( ! $minor || ! $guardian ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( self::is_guardian_of( $guardian_id, $person_id ) ) {
			return;
		}
		$errors = self::validate( $minor, $guardian, count( self::of( $person_id ) ), Db::today() );
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::db()->insert( Db::t( 'guardians' ), array( 'person_id' => $person_id, 'guardian_person_id' => $guardian_id, 'created_at' => Db::now() ) );
		Audit::log( 'guardian.linked', 'person', $person_id, array( 'guardian' => $guardian_id ) );
	}

	public static function unlink( int $person_id, int $guardian_id ): void {
		$n = self::db()->delete( Db::t( 'guardians' ), array( 'person_id' => $person_id, 'guardian_person_id' => $guardian_id ) );
		if ( $n ) {
			Audit::log( 'guardian.unlinked', 'person', $person_id, array( 'guardian' => $guardian_id ) );
		}
	}

	/** Alla cancellazione di una persona si tolgono i suoi collegamenti (come minore e come tutore). */
	public static function forget( int $person_id ): void {
		self::db()->delete( Db::t( 'guardians' ), array( 'person_id' => $person_id ) );
		self::db()->delete( Db::t( 'guardians' ), array( 'guardian_person_id' => $person_id ) );
	}

	// ---------- Chi riceve le email ----------

	/**
	 * A chi scrivere per una persona: la sua email e, se è minorenne o non ne ha una, quella dei tutori.
	 *
	 * @return array[] ['email', 'name' (a chi ci si rivolge), 'about' (il nome del minore, se il messaggio va al tutore)]
	 */
	public static function recipients( array $p, ?array $guardians = null ): array {
		$out   = array();
		$name  = trim( $p['first_name'] . ' ' . $p['last_name'] );
		$own   = trim( (string) ( $p['email'] ?? '' ) );
		$minor = Age::is_minor( $p['birth_date'] ?? null, Db::today() );
		if ( '' !== $own && is_email( $own ) ) {
			$out[ Text::lower( $own ) ] = array( 'email' => $own, 'name' => $p['first_name'], 'about' => '' );
		}
		$known_adult = Limits::flag( 'guardian_until_adult' ) && ! $minor && null !== Age::years( $p['birth_date'] ?? null, Db::today() ); // maggiorenne: le email non vanno più al tutore, nemmeno se non ha una email sua
		if ( $minor || ( ! $known_adult && array() === $out ) ) {
			foreach ( null === $guardians ? self::of( (int) $p['id'] ) : $guardians as $g ) {
				$mail = trim( (string) $g['email'] );
				if ( '' !== $mail && is_email( $mail ) && ! isset( $out[ Text::lower( $mail ) ] ) ) {
					$out[ Text::lower( $mail ) ] = array( 'email' => $mail, 'name' => $g['first_name'], 'about' => $name );
				}
			}
		}
		return array_values( $out );
	}

	// ---------- Diventare maggiorenni ----------

	/** Ragazzi ancora collegati a un tutore che hanno compiuto 18 anni e non hanno ancora un accesso loro. @return array[] persone con 'adult_on' */
	public static function adults_to_invite(): array {
		$today = Db::today();
		$limit = ( new \DateTimeImmutable( $today ) )->modify( '-' . Age::ADULT_AT . ' years' )->format( 'Y-m-d' );
		$rows  = self::db()->get_results(
			self::db()->prepare(
				'SELECT DISTINCT p.* FROM ' . Db::t( 'guardians' ) . ' g JOIN ' . Db::t( 'people' ) . ' p ON p.id = g.person_id '
				. 'WHERE p.deleted_at IS NULL AND p.anonymized_at IS NULL AND p.application_status IS NULL AND p.wp_user_id IS NULL AND p.type <> %s AND p.birth_date IS NOT NULL AND p.birth_date <= %s ORDER BY p.last_name, p.first_name',
				MemberType::GUEST,
				$limit
			),
			ARRAY_A
		) ?: array();
		$invited = array_map( 'intval', (array) get_option( self::INVITED_OPTION, array() ) );
		$rows    = array_values( array_filter( $rows, function ( $r ) use ( $invited ) {
			return ! in_array( (int) $r['id'], $invited, true );
		} ) );
		foreach ( $rows as &$r ) {
			$r['adult_on'] = Age::adult_on( (string) $r['birth_date'] );
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Invita un ragazzo maggiorenne a creare il suo accesso: gli arriva il link per scegliere la password. Serve la sua email.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function invite_adult( int $person_id ): void {
		$people = Plugin::people();
		$p      = $people->get( $person_id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( Age::is_minor( $p['birth_date'] ?? null, Db::today() ) ) {
			throw new \InvalidArgumentException( 'È ancora minorenne: l\'accesso lo ha il suo tutore.' );
		}
		if ( '' === trim( (string) $p['email'] ) ) {
			throw new \InvalidArgumentException( 'Prima inserisci la sua email nella scheda: l\'invito parte da lì.' );
		}
		if ( empty( $p['wp_user_id'] ) ) {
			$people->update( $person_id, array() ); // crea l'utente WordPress con la sua email
		}
		if ( ! Frontend\FirstAccess::request( (string) $p['email'] ) ) {
			throw new \InvalidArgumentException( 'L\'invito non è partito (troppe email recenti allo stesso indirizzo, o la posta del sito non risponde): riprova più tardi.' );
		}
		$done   = array_map( 'intval', (array) get_option( self::INVITED_OPTION, array() ) );
		$done[] = $person_id;
		update_option( self::INVITED_OPTION, array_values( array_unique( $done ) ), false );
		Audit::log( 'guardian.adult_invited', 'person', $person_id );
	}

	// ---------- Riferimenti per l'importazione ----------

	/** Trova un tutore da un testo libero: numero di tessera, email oppure «nome cognome». @return array|null null se non c'è o se il nome è ambiguo */
	public static function find_by_ref( string $ref ): ?array {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}
		$people = Plugin::people();
		if ( false !== strpos( $ref, '@' ) ) {
			return $people->find_by_email( strtolower( $ref ) );
		}
		$by_card = $people->find_by_card( $ref );
		if ( $by_card ) {
			return $by_card;
		}
		$key   = Text::normalize( $ref );
		$found = array();
		foreach ( $people->search( array( 'q' => $ref ) ) as $p ) {
			if ( Text::normalize( $p['first_name'] . $p['last_name'] ) === $key || Text::normalize( $p['last_name'] . $p['first_name'] ) === $key ) {
				$found[] = $p;
			}
		}
		if ( ! $found ) { // la ricerca libera non trova «Anna Rossi» scritto per intero: si prova per cognome
			foreach ( $people->search() as $p ) {
				if ( Text::normalize( $p['first_name'] . $p['last_name'] ) === $key || Text::normalize( $p['last_name'] . $p['first_name'] ) === $key ) {
					$found[] = $p;
				}
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}
}

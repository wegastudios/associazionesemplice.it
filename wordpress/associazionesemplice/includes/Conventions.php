<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Convenzioni per i soci: sconti e vantaggi di esercenti ed enti partner. La segreteria le gestisce in «Convenzioni»;
 * i soci in regola vedono quelle valide oggi nell'area riservata; quelle segnate «pubbliche» compaiono anche sul sito (senza codice né condizioni).
 * Le regole pure (validità, chi ha diritto) stanno in {@see ConventionRules}.
 */
final class Conventions {

	const OPT_CATEGORIES = 'asem_convention_categories';
	const NOTIFY_KIND    = 'convention_new';

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Registro ----------

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in ): int {
		$name = mb_substr( trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ), 0, 190 );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Scrivi il nome dell\'esercente o dell\'ente.' );
		}
		$benefit = mb_substr( trim( sanitize_text_field( (string) ( $in['benefit'] ?? '' ) ) ), 0, 500 );
		if ( '' === $benefit ) {
			throw new \InvalidArgumentException( 'Descrivi il vantaggio (per esempio «sconto del 10%»).' );
		}
		$from = trim( (string) ( $in['valid_from'] ?? '' ) );
		$to   = trim( (string) ( $in['valid_to'] ?? '' ) );
		$err  = ConventionRules::check_period( $from, $to );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$audience = (string) ( $in['audience'] ?? ConventionRules::AUDIENCE_ALL );
		if ( ! ConventionRules::is_audience( $audience ) ) {
			throw new \InvalidArgumentException( 'Scegli a chi spetta la convenzione.' );
		}
		$types = ConventionRules::clean_types( (array) ( $in['types'] ?? array() ) );
		if ( ConventionRules::AUDIENCE_TYPES === $audience && ! $types ) {
			throw new \InvalidArgumentException( 'Scegli almeno un tipo di socio, oppure «Tutti i soci in regola».' );
		}
		$email = trim( sanitize_text_field( (string) ( $in['email'] ?? '' ) ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'L\'indirizzo email non è valido.' );
		}
		$site = trim( (string) ( $in['website'] ?? '' ) );
		$url  = ConventionRules::normalize_website( $site );
		if ( '' !== $site && '' === $url ) {
			throw new \InvalidArgumentException( 'L\'indirizzo del sito non è valido (deve essere http o https).' );
		}
		$logo = (int) ( $in['logo_id'] ?? 0 );
		if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {
			$logo = 0;
		}
		$d = array(
			'name'       => $name,
			'category'   => mb_substr( trim( sanitize_text_field( (string) ( $in['category'] ?? '' ) ) ), 0, 80 ),
			'benefit'    => $benefit,
			'terms'      => mb_substr( trim( sanitize_textarea_field( (string) ( $in['terms'] ?? '' ) ) ), 0, 3000 ),
			'valid_from' => '' === $from ? null : $from,
			'valid_to'   => '' === $to ? null : $to,
			'address'    => mb_substr( trim( sanitize_text_field( (string) ( $in['address'] ?? '' ) ) ), 0, 190 ),
			'phone'      => mb_substr( trim( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) ), 0, 40 ),
			'email'      => mb_substr( $email, 0, 190 ),
			'website'    => $url,
			'logo_id'    => $logo > 0 ? $logo : null,
			'audience'   => $audience,
			'types'      => ConventionRules::types_to_string( $types ),
			'is_public'  => empty( $in['is_public'] ) ? 0 : 1,
			'code'       => mb_substr( trim( sanitize_text_field( (string) ( $in['code'] ?? '' ) ) ), 0, 60 ),
		);
		$db = self::db();
		if ( $id ) {
			if ( ! self::get( $id ) ) {
				throw new \InvalidArgumentException( 'Convenzione non trovata.' );
			}
			$db->update( Db::t( 'conventions' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'convention.updated', 'convention', $id );
			return $id;
		}
		if ( ! $db->insert( Db::t( 'conventions' ), $d + array( 'created_at' => Db::now(), 'updated_at' => Db::now() ) ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare la convenzione (errore del database).' );
		}
		$id = (int) $db->insert_id;
		Audit::log( 'convention.created', 'convention', $id );
		return $id;
	}

	/** Archivia (esce dall'area soci, resta in amministrazione) o riporta in elenco. */
	public static function set_archived( int $id, bool $archived ): void {
		if ( ! self::get( $id ) ) {
			throw new \InvalidArgumentException( 'Convenzione non trovata.' );
		}
		self::db()->update( Db::t( 'conventions' ), array( 'archived_at' => $archived ? Db::now() : null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( $archived ? 'convention.archived' : 'convention.restored', 'convention', $id );
	}

	public static function delete( int $id ): void {
		if ( ! self::get( $id ) ) {
			throw new \InvalidArgumentException( 'Convenzione non trovata.' );
		}
		self::db()->update( Db::t( 'conventions' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'convention.deleted', 'convention', $id );
	}

	private static function decorate( array $r ): array {
		$r['types_list'] = ConventionRules::types_from_string( (string) $r['types'] );
		$r['status']     = ConventionRules::status( $r['valid_from'] ?: null, $r['valid_to'] ?: null, ! empty( $r['archived_at'] ), Db::today() );
		return $r;
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'conventions' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ? self::decorate( $r ) : null;
	}

	/** Tutte le convenzioni (anche scadute e archiviate), per categoria e nome. @return array[] */
	public static function all( string $category = '' ): array {
		$sql  = 'SELECT * FROM ' . Db::t( 'conventions' ) . ' WHERE deleted_at IS NULL' . ( '' !== $category ? ' AND category = %s' : '' ) . ' ORDER BY category, name, id';
		$rows = self::db()->get_results( '' !== $category ? self::db()->prepare( $sql, $category ) : $sql, ARRAY_A ) ?: array();
		return array_map( array( __CLASS__, 'decorate' ), $rows );
	}

	/** Quelle valide oggi (non scadute, non archiviate), eventualmente di una categoria. @return array[] */
	public static function valid( string $category = '' ): array {
		return array_values( array_filter( self::all( $category ), function ( $c ) {
			return ConventionRules::STATUS_ACTIVE === $c['status'];
		} ) );
	}

	/** Convenzioni che scadono entro 30 giorni, la più vicina per prima. @return array[] */
	public static function expiring( int $days = ConventionRules::EXPIRING_DAYS ): array {
		$out = array_filter( self::all(), function ( $c ) use ( $days ) {
			return ConventionRules::is_expiring( $c['valid_from'] ?: null, $c['valid_to'] ?: null, ! empty( $c['archived_at'] ), Db::today(), $days );
		} );
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) $a['valid_to'], (string) $b['valid_to'] );
		} );
		return array_values( $out );
	}

	// ---------- Categorie ----------

	/** Le categorie scelte dalla segreteria, più quelle in uso e quelle proposte. @return string[] */
	public static function categories(): array {
		$saved = (array) get_option( self::OPT_CATEGORIES, array() );
		$used  = self::db()->get_col( 'SELECT DISTINCT category FROM ' . Db::t( 'conventions' ) . " WHERE deleted_at IS NULL AND category <> ''" ) ?: array();
		return ConventionRules::merge_categories( $saved ? $saved : ConventionRules::default_categories(), array_map( 'strval', $used ) );
	}

	/** Salva l'elenco delle categorie proposte (una per riga). */
	public static function save_categories( string $text ): void {
		update_option( self::OPT_CATEGORIES, ConventionRules::parse_categories( $text ), false );
		Audit::log( 'convention.categories', 'convention', 0 );
	}

	/** Categorie delle sole convenzioni indicate (per il filtro dei soci). @return string[] */
	public static function categories_of( array $conventions ): array {
		$out = array();
		foreach ( $conventions as $c ) {
			if ( '' !== (string) $c['category'] ) {
				$out[ (string) $c['category'] ] = (string) $c['category'];
			}
		}
		natcasesort( $out );
		return array_values( $out );
	}

	// ---------- A chi spetta ----------

	/** La persona ha diritto a questa convenzione oggi (socio in regola del tipo previsto)? */
	public static function is_entitled( array $conv, array $person ): bool {
		if ( ! empty( $person['anonymized_at'] ) || ! empty( $person['deleted_at'] ) || ! empty( $person['application_status'] ) ) {
			return false;
		}
		if ( ! empty( $person['left_on'] ) && (string) $person['left_on'] <= Db::today() ) {
			return false; // socio uscito
		}
		$active = MemberType::is_member( (string) $person['type'] ) && Plugin::people()->is_active_member( (int) $person['id'] );
		return ConventionRules::is_entitled( (string) $conv['audience'], (array) $conv['types_list'], (string) $person['type'], $active );
	}

	/**
	 * Le convenzioni valide oggi che spettano alla persona. Un tutore che non è socio non ne vede.
	 *
	 * @return array[]
	 */
	public static function for_person( array $person, string $category = '' ): array {
		if ( ! MemberType::is_member( (string) $person['type'] ) ) {
			return array();
		}
		return array_values( array_filter( self::valid( $category ), function ( $c ) use ( $person ) {
			return self::is_entitled( $c, $person );
		} ) );
	}

	/** Le convenzioni valide oggi segnate pubbliche. @return array[] */
	public static function public_list( string $category = '' ): array {
		return array_values( array_filter( self::valid( $category ), function ( $c ) {
			return ! empty( $c['is_public'] );
		} ) );
	}

	/** I soci a cui spetta (con la tessera valida oggi e del tipo previsto). @return array[] */
	public static function recipients( array $conv ): array {
		$out = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( (string) $p['type'] ) && self::is_entitled( $conv, $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	// ---------- Logo ----------

	public static function logo_url( array $conv, string $size = 'medium' ): string {
		$id = (int) ( $conv['logo_id'] ?? 0 );
		$u  = $id > 0 ? wp_get_attachment_image_url( $id, $size ) : false;
		return $u ? (string) $u : '';
	}

	// ---------- Avviso ai soci ----------

	/**
	 * Manda l'email di nuova convenzione ai soci a cui spetta, una sola volta per convenzione (e per persona).
	 *
	 * @param bool $send false = solo conteggio dei destinatari che riceverebbero l'email
	 * @return int email partite (o da mandare, senza invio)
	 * @throws \InvalidArgumentException
	 */
	public static function announce( int $id, bool $send = true ): int {
		$c = self::get( $id );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'Convenzione non trovata.' );
		}
		if ( ConventionRules::STATUS_ACTIVE !== $c['status'] ) {
			throw new \InvalidArgumentException( 'L\'avviso si manda per una convenzione in corso (non scaduta, non archiviata, già iniziata).' );
		}
		if ( $send && ! empty( $c['announced_at'] ) ) {
			throw new \InvalidArgumentException( 'I soci sono già stati avvisati di questa convenzione.' );
		}
		$subject = 'Nuova convenzione: ' . $c['name'];
		$body    = 'abbiamo una nuova convenzione per i soci: ' . $c['name'] . ".\nVantaggio: " . $c['benefit']
			. ( $c['valid_to'] ? "\nValida fino al " . ( new \DateTimeImmutable( (string) $c['valid_to'] ) )->format( 'd/m/Y' ) . '.' : '' )
			. "\nCodice, condizioni e istruzioni per farti riconoscere sono nell'area riservata, sezione «Convenzioni»: " . Gatekeeper::area_url();
		$ref = (string) $id;
		$n   = 0;
		foreach ( self::recipients( $c ) as $p ) {
			if ( $send ? Reminders::notify( $p, self::NOTIFY_KIND, $ref, $subject, $body ) : Reminders::pending( $p, self::NOTIFY_KIND, $ref ) ) {
				$n++;
			}
		}
		if ( $send ) {
			self::db()->update( Db::t( 'conventions' ), array( 'announced_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'convention.announced', 'convention', $id, array( 'sent' => $n ) );
		}
		return $n;
	}

	// ---------- CSV ----------

	/** Elenco delle convenzioni in CSV (separatore «;», si apre in Excel italiano). */
	public static function csv(): string {
		$out    = Admin\Exports::line( array( 'Nome', 'Categoria', 'Vantaggio', 'Condizioni', 'Valida dal', 'Valida al', 'Stato', 'Chi ne ha diritto', 'Pubblica', 'Codice', 'Indirizzo', 'Telefono', 'Email', 'Sito' ) );
		$d      = function ( $ymd ) {
			return $ymd ? ( new \DateTimeImmutable( (string) $ymd ) )->format( 'd/m/Y' ) : '';
		};
		$status = ConventionRules::status_labels();
		foreach ( self::all() as $c ) {
			if ( ConventionRules::AUDIENCE_TYPES === $c['audience'] ) {
				$who = implode( ', ', array_map( array( MemberType::class, 'label' ), $c['types_list'] ) );
			} else {
				$who = ConventionRules::audiences()[ ConventionRules::AUDIENCE_ALL ];
			}
			$out .= Admin\Exports::line( array( $c['name'], $c['category'], $c['benefit'], $c['terms'], $d( $c['valid_from'] ), $d( $c['valid_to'] ), $status[ $c['status'] ] ?? '', $who, $c['is_public'] ? 'sì' : 'no', $c['code'], $c['address'], $c['phone'], $c['email'], $c['website'] ) );
		}
		return $out;
	}
}

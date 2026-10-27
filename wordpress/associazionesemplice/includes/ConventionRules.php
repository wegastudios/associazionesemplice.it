<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) delle convenzioni per i soci: quando sono valide, a chi spettano, categorie e indirizzi. Il resto sta in Conventions. */
final class ConventionRules {

	const AUDIENCE_ALL   = 'all';
	const AUDIENCE_TYPES = 'types';

	const STATUS_ACTIVE   = 'active';
	const STATUS_UPCOMING = 'upcoming';
	const STATUS_EXPIRED  = 'expired';
	const STATUS_ARCHIVED = 'archived';

	/** Giorni di preavviso per «convenzioni in scadenza». */
	const EXPIRING_DAYS = 30;

	public static function audiences(): array {
		return array( self::AUDIENCE_ALL => 'Tutti i soci in regola', self::AUDIENCE_TYPES => 'Solo alcuni tipi di socio' );
	}

	public static function is_audience( string $v ): bool {
		return isset( self::audiences()[ $v ] );
	}

	public static function status_labels(): array {
		return array(
			self::STATUS_ACTIVE   => 'In corso',
			self::STATUS_UPCOMING => 'Non ancora valida',
			self::STATUS_EXPIRED  => 'Scaduta',
			self::STATUS_ARCHIVED => 'Archiviata',
		);
	}

	/** Le categorie proposte (se ne possono aggiungere altre). */
	public static function default_categories(): array {
		return array( 'Sport e benessere', 'Cultura e tempo libero', 'Salute', 'Negozi e servizi', 'Ristorazione', 'Altro' );
	}

	/**
	 * Stato di una convenzione a una data. Date vuote = nessun limite.
	 *
	 * @param string|null $from     primo giorno di validità (Y-m-d)
	 * @param string|null $to       ultimo giorno di validità, compreso (Y-m-d)
	 * @param bool        $archived archiviata a mano
	 */
	public static function status( ?string $from, ?string $to, bool $archived, string $today ): string {
		if ( $archived ) {
			return self::STATUS_ARCHIVED;
		}
		if ( null !== $from && '' !== $from && $from > $today ) {
			return self::STATUS_UPCOMING;
		}
		if ( null !== $to && '' !== $to && $to < $today ) {
			return self::STATUS_EXPIRED;
		}
		return self::STATUS_ACTIVE;
	}

	/** Vale oggi (cioè si mostra ai soci)? */
	public static function is_valid_on( ?string $from, ?string $to, bool $archived, string $today ): bool {
		return self::STATUS_ACTIVE === self::status( $from, $to, $archived, $today );
	}

	/** Controllo delle date inserite: il «dal» non può venire dopo il «al». @return string|null messaggio di errore */
	public static function check_period( ?string $from, ?string $to ): ?string {
		foreach ( array( 'dal' => $from, 'al' => $to ) as $label => $d ) {
			if ( null !== $d && '' !== $d && ! self::is_date( $d ) ) {
				return 'La data «' . $label . '» non è valida.';
			}
		}
		if ( $from && $to && $from > $to ) {
			return 'La convenzione non può finire prima di iniziare.';
		}
		return null;
	}

	public static function is_date( string $d ): bool {
		$x = \DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		return false !== $x && $x->format( 'Y-m-d' ) === $d;
	}

	/** Scade entro $days giorni (oggi compreso)? Solo se è valida oggi e ha una data di fine. */
	public static function is_expiring( ?string $from, ?string $to, bool $archived, string $today, int $days = self::EXPIRING_DAYS ): bool {
		if ( null === $to || '' === $to || ! self::is_valid_on( $from, $to, $archived, $today ) ) {
			return false;
		}
		$limit = ( new \DateTimeImmutable( $today ) )->modify( '+' . max( 0, $days ) . ' days' )->format( 'Y-m-d' );
		return $to <= $limit;
	}

	/** Tipi di socio scelti, ripuliti: solo tipi che sono soci veri, senza doppi. @return string[] */
	public static function clean_types( array $types ): array {
		return array_values( array_intersect( MemberType::member_types(), array_map( 'strval', $types ) ) );
	}

	/**
	 * Una persona ha diritto alla convenzione? Serve essere un socio vero (non ospite né tutore) con la tessera valida oggi.
	 * Con «solo alcuni tipi» serve anche che il suo tipo sia tra quelli scelti.
	 *
	 * @param string[] $types tipi scelti (usati solo con AUDIENCE_TYPES)
	 */
	public static function is_entitled( string $audience, array $types, string $person_type, bool $active_member ): bool {
		if ( ! $active_member || ! MemberType::is_member( $person_type ) ) {
			return false;
		}
		if ( self::AUDIENCE_TYPES === $audience ) {
			return in_array( $person_type, self::clean_types( $types ), true );
		}
		return true;
	}

	/** «tipi» salvati come testo («founder,ordinary») ⇄ elenco. @return string[] */
	public static function types_from_string( string $csv ): array {
		return self::clean_types( '' === trim( $csv ) ? array() : explode( ',', $csv ) );
	}

	public static function types_to_string( array $types ): string {
		return implode( ',', self::clean_types( $types ) );
	}

	/** Sito web: se manca lo schema si aggiunge https://; si accettano solo http e https, altrimenti vuoto. */
	public static function normalize_website( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		if ( ! preg_match( '#^https?://[^\s/$.?\#][^\s]*$#i', $url ) ) {
			return '';
		}
		return mb_substr( $url, 0, 255 );
	}

	/** Elenco di categorie scritto una per riga (o separato da virgole o punti e virgola): pulito e senza doppi. @return string[] */
	public static function parse_categories( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n;,]+/', $text ) ?: array() as $c ) {
			$c = mb_substr( trim( preg_replace( '/\s+/', ' ', $c ) ), 0, 80 );
			if ( '' !== $c && ! in_array( mb_strtolower( $c ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** Categorie salvate, poi quelle in uso, senza doppi (a parità di maiuscole). @return string[] */
	public static function merge_categories( array $saved, array $used ): array {
		$out = array();
		foreach ( array_merge( $saved, $used ) as $c ) {
			$c = trim( (string) $c );
			if ( '' !== $c && ! in_array( mb_strtolower( $c ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole delle affiliazioni agli enti (CONI/RAS, UISP, CSEN, ACSI, AICS, altri): enti, stagione, controllo dei dati, tesseramenti in blocco
 * e righe del file per i portali degli enti. Logica pura, senza WordPress.
 */
final class AffiliationRules {

	const OTHER = 'other';

	/** Giorni in più dopo la scadenza in cui si manda ancora il promemoria «scaduta». */
	const EXPIRED_WINDOW = 30;

	/** Intestazione del file per i portali degli enti. */
	const CSV_HEADER = array( 'Nome', 'Cognome', 'Codice fiscale', 'Data di nascita', 'Luogo di nascita (codice catastale)', 'Sesso', 'Indirizzo', 'Email', 'Telefono', 'Tipo tessera', 'Sport', 'Disciplina' );

	/** CONI / RAS e i 16 enti di promozione sportiva riconosciuti dal CONI (14 nazionali e 2 su base regionale), più «Altro ente». */
	public static function bodies(): array {
		return array(
			'coni'      => 'CONI / RAS',
			'acsi'      => 'ACSI',
			'aics'      => 'AICS',
			'asc'       => 'ASC',
			'asi'       => 'ASI',
			'libertas'  => 'CNS Libertas',
			'csain'     => 'CSAIN',
			'csen'      => 'CSEN',
			'csi'       => 'CSI',
			'endas'     => 'ENDAS',
			'msp'       => 'MSP Italia',
			'opes'      => 'OPES',
			'pgs'       => 'PGS',
			'uisp'      => 'UISP',
			'usacli'    => 'US ACLI',
			'ussa'      => 'USSA (Alto Adige)',
			'vss'       => 'VSS (Alto Adige)',
			self::OTHER => 'Altro ente',
		);
	}

	public static function is_body( string $b ): bool {
		return isset( self::bodies()[ $b ] );
	}

	/** Nome da mostrare: quello dell'elenco o, per «Altro ente», il nome scritto. */
	public static function body_label( string $body, string $custom ): string {
		if ( self::OTHER === $body ) {
			return '' !== trim( $custom ) ? trim( $custom ) : self::bodies()[ self::OTHER ];
		}
		return self::bodies()[ $body ] ?? $body;
	}

	/**
	 * Stagione sportiva: «2026» o «2025/2026» (anche «2025-2026» e «2025/26»).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function season( string $s ): string {
		$s = trim( $s );
		if ( preg_match( '/^(\d{4})$/', $s, $m ) && (int) $m[1] >= 1990 && (int) $m[1] <= 2100 ) {
			return $m[1];
		}
		if ( preg_match( '/^(\d{4})\s*[\/\-]\s*(\d{4}|\d{2})$/', $s, $m ) ) {
			$a = (int) $m[1];
			$b = 2 === strlen( $m[2] ) ? (int) ( substr( $m[1], 0, 2 ) . $m[2] ) : (int) $m[2];
			if ( $a >= 1990 && $a <= 2100 && $b === $a + 1 ) {
				return $a . '/' . $b;
			}
		}
		throw new \InvalidArgumentException( 'Scrivi la stagione come 2026 oppure 2025/2026.' );
	}

	/** Stagione proposta: da settembre in poi quella che inizia quest'anno («2025/2026» dal 1° settembre 2025), altrimenti quella in corso. */
	public static function default_season( string $today ): string {
		$y = (int) substr( $today, 0, 4 );
		$m = (int) substr( $today, 5, 2 );
		return $m >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;
	}

	private static function body_fields( array $in ): array {
		$body = (string) ( $in['body'] ?? '' );
		if ( ! self::is_body( $body ) ) {
			throw new \InvalidArgumentException( 'Scegli l\'ente.' );
		}
		$custom = mb_substr( trim( strip_tags( (string) ( $in['body_label'] ?? '' ) ) ), 0, 80 );
		if ( self::OTHER === $body && '' === $custom ) {
			throw new \InvalidArgumentException( 'Scrivi il nome dell\'ente.' );
		}
		return array( 'body' => $body, 'body_label' => self::OTHER === $body ? $custom : '' );
	}

	private static function cost( $v ): ?int {
		$raw = trim( (string) $v );
		if ( '' === $raw ) {
			return null;
		}
		$c = Money::parse( $raw );
		if ( null === $c || $c < 0 ) {
			throw new \InvalidArgumentException( 'Il costo non è valido: scrivi un importo come 25,00.' );
		}
		return $c;
	}

	private static function expiry( array $in ): ?string {
		$e = trim( (string) ( $in['expires_on'] ?? '' ) );
		if ( '' === $e ) {
			return null;
		}
		if ( ! ExpiryRules::is_date( $e ) ) {
			throw new \InvalidArgumentException( 'La data di scadenza non è valida.' );
		}
		return $e;
	}

	/**
	 * Controlla i dati dell'affiliazione dell'associazione all'ente.
	 *
	 * @return array{body:string,body_label:string,code:string,season:string,expires_on:?string,cost_cents:?int}
	 * @throws \InvalidArgumentException
	 */
	public static function clean_affiliation( array $in ): array {
		$d         = self::body_fields( $in );
		$d['code'] = mb_substr( trim( strip_tags( (string) ( $in['code'] ?? '' ) ) ), 0, 60 );
		if ( '' === $d['code'] ) {
			throw new \InvalidArgumentException( 'Scrivi il codice di affiliazione.' );
		}
		$d['disciplines'] = mb_substr( trim( strip_tags( (string) ( $in['disciplines'] ?? '' ) ) ), 0, 190 );
		$d['season']     = self::season( (string) ( $in['season'] ?? '' ) );
		$d['expires_on'] = self::expiry( $in );
		$d['cost_cents'] = self::cost( $in['cost'] ?? '' );
		return $d;
	}

	/**
	 * Controlla i dati del tesseramento di un socio all'ente.
	 *
	 * @return array{body:string,body_label:string,card_number:string,card_type:string,season:string,issued_on:?string,expires_on:?string,cost_cents:?int}
	 * @throws \InvalidArgumentException
	 */
	public static function clean_card( array $in ): array {
		$d                = self::body_fields( $in );
		$d['card_number'] = mb_substr( trim( strip_tags( (string) ( $in['card_number'] ?? '' ) ) ), 0, 60 );
		$d['card_type']   = mb_substr( trim( strip_tags( (string) ( $in['card_type'] ?? '' ) ) ), 0, 60 );
		$d['season']      = self::season( (string) ( $in['season'] ?? '' ) );
		$issued           = trim( (string) ( $in['issued_on'] ?? '' ) );
		if ( '' !== $issued && ! ExpiryRules::is_date( $issued ) ) {
			throw new \InvalidArgumentException( 'La data della tessera non è valida.' );
		}
		$d['issued_on']  = '' === $issued ? null : $issued;
		$d['expires_on'] = self::expiry( $in );
		if ( $d['issued_on'] && $d['expires_on'] && $d['expires_on'] < $d['issued_on'] ) {
			throw new \InvalidArgumentException( 'La tessera non può scadere prima della sua data.' );
		}
		$d['cost_cents'] = self::cost( $in['cost'] ?? '' );
		return $d;
	}

	/**
	 * Tesseramento in blocco: tra i candidati, chi non ha già una tessera dello stesso ente e stagione.
	 *
	 * @param int[] $candidates
	 * @param int[] $already
	 * @return int[]
	 */
	public static function new_card_targets( array $candidates, array $already ): array {
		$have = array_flip( array_map( 'intval', $already ) );
		$out  = array();
		foreach ( $candidates as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $have[ $id ] ) ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/** Promemoria di scadenza: tipo (colonna `kind`) oppure vuoto. $prefix è «aff» (affiliazione) o «card» (tessera). */
	public static function reminder_kind( string $prefix, string $status, int $days_left ): string {
		if ( ExpiryRules::EXPIRING === $status ) {
			return $prefix . '_soon';
		}
		if ( ExpiryRules::EXPIRED === $status && -$days_left <= self::EXPIRED_WINDOW ) {
			return $prefix . '_expired';
		}
		return '';
	}

	// ---------- File per i portali degli enti ----------

	/** Il codice fiscale con le lettere dell'omocodia riportate alle cifre (solo nelle posizioni numeriche). */
	private static function plain_tax_code( string $cf ): string {
		$cf = strtoupper( (string) preg_replace( '/\s+/', '', $cf ) );
		if ( 16 !== strlen( $cf ) ) {
			return '';
		}
		$map = array_flip( str_split( 'LMNPQRSTUV' ) );
		foreach ( array( 6, 7, 9, 10, 12, 13, 14 ) as $i ) {
			if ( isset( $map[ $cf[ $i ] ] ) ) {
				$cf[ $i ] = (string) $map[ $cf[ $i ] ];
			}
		}
		return $cf;
	}

	/** Il sesso scritto nel codice fiscale: «M», «F» oppure vuoto se il codice non è leggibile. */
	public static function sex_from_tax_code( string $cf ): string {
		$p = self::plain_tax_code( $cf );
		if ( '' === $p || ! ctype_digit( substr( $p, 9, 2 ) ) ) {
			return '';
		}
		$day = (int) substr( $p, 9, 2 );
		if ( $day >= 41 && $day <= 71 ) {
			return 'F';
		}
		return $day >= 1 && $day <= 31 ? 'M' : '';
	}

	/** Il codice catastale del luogo di nascita scritto nel codice fiscale (ad esempio «H501»); vuoto se non leggibile. */
	public static function birth_place_code( string $cf ): string {
		$p = self::plain_tax_code( $cf );
		return '' !== $p && preg_match( '/^[A-Z][0-9]{3}$/', substr( $p, 11, 4 ) ) ? substr( $p, 11, 4 ) : '';
	}

	/** Indirizzo su una riga: via, CAP, città e provincia. */
	public static function address_line( array $p ): string {
		$town = trim( trim( (string) ( $p['zip'] ?? '' ) ) . ' ' . trim( (string) ( $p['city'] ?? '' ) ) );
		$prov = trim( (string) ( $p['province'] ?? '' ) );
		if ( '' !== $prov && '' !== $town ) {
			$town .= ' (' . strtoupper( $prov ) . ')';
		}
		return trim( implode( ', ', array_filter( array( trim( (string) ( $p['address'] ?? '' ) ), $town ) ) ) );
	}

	/**
	 * Una riga del file per i portali degli enti (stesse colonne di CSV_HEADER).
	 *
	 * @param array $p persona (first_name, last_name, tax_code, birth_date, address, zip, city, province, email, phone)
	 * @return string[]
	 */
	public static function csv_row( array $p, string $card_type, array $extra = array() ): array {
		$cf    = strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $p['tax_code'] ?? '' ) ) );
		$birth = (string) ( $p['birth_date'] ?? '' );
		return array(
			(string) $p['first_name'],
			(string) $p['last_name'],
			$cf,
			ExpiryRules::is_date( $birth ) ? ExpiryRules::it_date( $birth ) : '',
			self::birth_place_code( $cf ),
			self::sex_from_tax_code( $cf ),
			self::address_line( $p ),
			(string) ( $p['email'] ?? '' ),
			(string) ( $p['phone'] ?? '' ),
			$card_type,
			(string) ( $extra['sport'] ?? '' ),
			(string) ( $extra['discipline'] ?? '' ),
		);
	}
}

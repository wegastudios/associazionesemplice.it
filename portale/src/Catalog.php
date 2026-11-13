<?php
namespace Portale;

/**
 * Catalogo dei prodotti (piani, prezzi, promozioni), letto dal portale e mai scritto: la fonte è l'amministrazione di ciascun prodotto
 * (docs/ARCHITETTURA_DOMINI.md §4, saas/docs/CONTRATTO_PORTALE.md §2).
 *
 * - Si scarica da `saas_catalog_url` (default: `{saas_api_url}/api/catalogo/v1`) con `If-None-Match`; la risposta è valida solo se la firma
 *   EdDSA dell'intestazione `X-Catalog-Signature` torna con una chiave fissata in `saas_public_keys` (kid => x) e l'impronta sha256 coincide con i byte ricevuti.
 * - Anti-rollback: una `rev` minore dell'ultima salvata si rifiuta; con la stessa `rev` il corpo deve essere identico.
 * - L'ultima copia valida resta salvata (tabella `catalog`) e serve se il prodotto non risponde.
 * - Il portale ricarica ogni 10 minuti e quando riceve l'avviso firmato `catalog_changed` (/api/saas/v1/catalog-changed).
 */
final class Catalog {

	const SAAS = 'saas';
	/** Secondi dopo i quali la copia salvata si ricontrolla. */
	const TTL = 600;

	/** @return array<string,string> kid => chiave pubblica (base64url) del SaaS */
	public static function keys(): array {
		$out = array();
		foreach ( (array) Config::get( 'saas_public_keys', array() ) as $kid => $x ) {
			if ( is_string( $kid ) && is_string( $x ) && '' !== $kid && '' !== $x ) {
				$out[ $kid ] = $x;
			}
		}
		return $out;
	}

	public static function enabled(): bool {
		return function_exists( 'sodium_crypto_sign_verify_detached' ) && array() !== self::keys();
	}

	public static function url(): string {
		$u = trim( (string) Config::get( 'saas_catalog_url', '' ) );
		return '' !== $u ? $u : SaasClient::baseUrl() . '/api/catalogo/v1';
	}

	/** Indirizzo con cui il SaaS firma (claim `iss`). */
	public static function issuer(): string {
		return SaasClient::baseUrl();
	}

	/**
	 * Verifica un JWT del SaaS: firma, kid, emittente, destinatario (il portale), tipo e date (vita massima 600 s, tolleranza 60 s).
	 * @return array|null i claim, o null se qualcosa non torna
	 */
	public static function verifyToken( string $jwt, string $typ, ?int $now = null ): ?array {
		$now = $now ?? time();
		$p   = explode( '.', $jwt );
		if ( 3 !== count( $p ) || strlen( $jwt ) > 4096 || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$h = json_decode( self::unb64( $p[0] ), true );
		$c = json_decode( self::unb64( $p[1] ), true );
		if ( ! is_array( $h ) || ! is_array( $c ) || 'EdDSA' !== ( $h['alg'] ?? '' ) || ! is_string( $h['kid'] ?? null ) ) {
			return null;
		}
		$keys = self::keys();
		if ( ! isset( $keys[ $h['kid'] ] ) ) {
			return null;
		}
		$pub = self::unb64( $keys[ $h['kid'] ] );
		$sig = self::unb64( $p[2] );
		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $p[0] . '.' . $p[1], $pub ) ) {
			return null;
		}
		$aud = rtrim( (string) Config::get( 'base_url', '' ), '/' );
		if ( ( $c['iss'] ?? '' ) !== self::issuer() || ( $c['aud'] ?? '' ) !== $aud || ( $c['typ'] ?? '' ) !== $typ ) {
			return null;
		}
		$iat = $c['iat'] ?? null;
		$exp = $c['exp'] ?? null;
		if ( ! is_int( $iat ) || ! is_int( $exp ) || $exp - $iat > 600 || $iat > $now + 60 || $exp < $now - 60 || ! is_string( $c['jti'] ?? null ) || '' === $c['jti'] ) {
			return null;
		}
		return $c;
	}

	/**
	 * Controlla e salva una risposta del catalogo. @return string '' se salvata, altrimenti il motivo del rifiuto
	 */
	public static function accept( Db $db, string $body, string $signature, string $etag, ?int $now = null ): string {
		$now = $now ?? time();
		$c   = self::verifyToken( $signature, 'catalog', $now );
		if ( ! $c ) {
			return 'firma non valida';
		}
		$hash = hash( 'sha256', $body );
		if ( ! is_string( $c['sha256'] ?? null ) || ! hash_equals( $hash, strtolower( $c['sha256'] ) ) ) {
			return 'impronta diversa dal corpo';
		}
		$doc = json_decode( $body, true );
		if ( ! is_array( $doc ) || 'catalogo/v1' !== ( $doc['schema'] ?? '' ) || self::SAAS !== ( $doc['product'] ?? '' ) || ! is_int( $doc['rev'] ?? null ) || ! is_array( $doc['plans'] ?? null ) || ! is_array( $doc['features'] ?? null ) ) {
			return 'documento non valido';
		}
		if ( ( $c['rev'] ?? null ) !== $doc['rev'] ) {
			return 'rev diversa dalla firma';
		}
		$old = $db->one( 'SELECT rev, body FROM catalog WHERE product = ?', array( self::SAAS ) );
		if ( $old && $doc['rev'] < (int) $old['rev'] ) {
			return 'rev più vecchia di quella salvata';
		}
		if ( $old && $doc['rev'] === (int) $old['rev'] && hash( 'sha256', (string) $old['body'] ) !== $hash ) {
			return 'stessa rev con contenuto diverso';
		}
		$at = gmdate( 'Y-m-d H:i:s', $now );
		if ( $old ) {
			$db->run( 'UPDATE catalog SET rev = ?, etag = ?, body = ?, fetched_at = ?, checked_at = ? WHERE product = ?', array( $doc['rev'], $etag, $body, $at, $at, self::SAAS ) );
		} else {
			$db->run( 'INSERT INTO catalog (product, rev, etag, body, fetched_at, checked_at) VALUES (?,?,?,?,?,?)', array( self::SAAS, $doc['rev'], $etag, $body, $at, $at ) );
		}
		return '';
	}

	/** Scarica il catalogo se serve. @return string updated | unchanged | error: motivo | off */
	public static function refresh( Db $db, ?int $now = null ): string {
		if ( ! self::enabled() ) {
			return 'off';
		}
		$now = $now ?? time();
		$row = $db->one( 'SELECT etag FROM catalog WHERE product = ?', array( self::SAAS ) );
		$h   = array( 'Accept: application/json' );
		if ( $row && '' !== (string) $row['etag'] ) {
			$h[] = 'If-None-Match: ' . $row['etag'];
		}
		$res = Http::send( 'GET', self::url(), $h );
		if ( 304 === $res[0] && $row ) {
			$db->run( 'UPDATE catalog SET checked_at = ? WHERE product = ?', array( gmdate( 'Y-m-d H:i:s', $now ), self::SAAS ) );
			return 'unchanged';
		}
		if ( 200 !== $res[0] ) {
			return 'error: il SaaS ha risposto ' . $res[0];
		}
		$hd  = Http::$lastHeaders;
		$why = self::accept( $db, $res[1], (string) ( $hd['x-catalog-signature'] ?? '' ), trim( (string) ( $hd['etag'] ?? '' ) ), $now );
		if ( '' !== $why ) {
			error_log( '[portale] catalogo rifiutato: ' . $why );
			return 'error: ' . $why;
		}
		return 'updated';
	}

	/** Catalogo salvato: documento, rev, quando è stato scaricato e se va ricontrollato. @return array|null */
	public static function stored( Db $db, ?int $now = null ): ?array {
		$row = $db->one( 'SELECT * FROM catalog WHERE product = ?', array( self::SAAS ) );
		$doc = $row ? json_decode( (string) $row['body'], true ) : null;
		if ( ! is_array( $doc ) ) {
			return null;
		}
		return array(
			'doc'        => $doc,
			'rev'        => (int) $row['rev'],
			'fetched_at' => (string) $row['fetched_at'],
			'checked_at' => (string) $row['checked_at'],
			'stale'      => strtotime( (string) $row['checked_at'] . ' UTC' ) < ( $now ?? time() ) - self::TTL,
		);
	}

	/** Vero se conviene ricontrollare (nessuna copia, oppure più vecchia di 10 minuti). */
	public static function needsRefresh( Db $db, ?int $now = null ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$s = self::stored( $db, $now );
		return ! $s || $s['stale'];
	}

	/**
	 * Avviso firmato `catalog_changed`: vale se la firma torna e il `jti` è nuovo. @return bool
	 */
	public static function notify( Db $db, string $payload, ?int $now = null ): bool {
		$now = $now ?? time();
		$b   = json_decode( $payload, true );
		$c   = is_array( $b ) && is_string( $b['token'] ?? null ) ? self::verifyToken( $b['token'], 'catalog_changed', $now ) : null;
		if ( ! $c ) {
			return false;
		}
		$db->run( 'DELETE FROM seen_jti WHERE exp < ?', array( $now - 3600 ) );
		try {
			$db->run( 'INSERT INTO seen_jti (jti, exp) VALUES (?,?)', array( substr( $c['jti'], 0, 64 ), (int) $c['exp'] ) );
		} catch ( \PDOException $e ) {
			return false; // già usato
		}
		return true;
	}

	/** Piani visibili, in ordine. */
	public static function plans( array $doc ): array {
		$plans = array_values( array_filter( (array) ( $doc['plans'] ?? array() ), function ( $p ) {
			return is_array( $p ) && false !== ( $p['visible'] ?? true );
		} ) );
		usort( $plans, function ( $a, $b ) {
			return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
		} );
		return $plans;
	}

	/**
	 * Perché un componente (addon) non si compra sul piano scelto, oppure null se si può. Nessuna regola scritta qui: vale il catalogo
	 * (contratto §2): un pacchetto già compreso nel piano (`limits.packages`) non si vende due volte; se l'addon ha l'elenco `plans`
	 * (assente o null = tutti i piani) il piano scelto deve esserci.
	 */
	public static function addonBlock( array $doc, string $plan, array $addon ): ?string {
		$chosen = null;
		$names  = array();
		foreach ( (array) ( $doc['plans'] ?? array() ) as $p ) {
			if ( is_array( $p ) && is_string( $p['code'] ?? null ) ) {
				$names[ $p['code'] ] = (string) ( $p['name'] ?? $p['code'] );
				if ( $p['code'] === $plan ) {
					$chosen = $p;
				}
			}
		}
		if ( ! $chosen ) {
			return null; // piano sconosciuto: ci pensa Quote::build
		}
		$pack     = (string) ( $addon['grants']['package'] ?? '' );
		$included = (array) ( $chosen['limits']['packages'] ?? array() );
		if ( 'package' === ( $addon['kind'] ?? '' ) && '' !== $pack && array_key_exists( $pack, $included ) ) {
			return 'Compreso nel piano ' . $names[ $plan ];
		}
		$allowed = $addon['plans'] ?? null;
		if ( is_array( $allowed ) && ! in_array( $plan, $allowed, true ) ) {
			$list = array();
			foreach ( $allowed as $c ) {
				if ( is_string( $c ) && isset( $names[ $c ] ) ) {
					$list[] = $names[ $c ];
				}
			}
			return $list ? 'Disponibile solo con ' . ( count( $list ) > 1 ? 'i piani ' : 'il piano ' ) . implode( ', ', $list ) : 'Non disponibile con questo piano';
		}
		return null;
	}

	/**
	 * Condizioni di vendita del catalogo (`terms`, contratto §2.2), con i valori di partenza dove mancano: IVA standard (prezzi IVA esclusa),
	 * commissioni 0% (Stripe/PayPal propri) e 3% (incassiamo noi), prova senza esportazioni né commercialista.
	 * Le commissioni NON si scrivono nelle pagine di vendita finché la titolare non lo decide: qui si leggono soltanto.
	 * @return array{vat_mode:string,vat_note:string,own_gateway_percent:int,collected_by_us_percent:int,trial_exports:bool,trial_accountant_access:bool}
	 */
	public static function terms( array $doc ): array {
		$t    = is_array( $doc['terms'] ?? null ) ? $doc['terms'] : array();
		$vat  = is_array( $t['vat'] ?? null ) ? $t['vat'] : array();
		$pay  = is_array( $t['payments'] ?? null ) ? $t['payments'] : array();
		$try  = is_array( $t['trial'] ?? null ) ? $t['trial'] : array();
		$note = is_string( $vat['note'] ?? null ) ? trim( $vat['note'] ) : '';
		// Senza la dicitura obbligatoria il regime «esente» non si applica: meglio l'IVA esposta che una fattura senza la dicitura di legge.
		$exempt = 'exempt' === ( $vat['mode'] ?? '' ) && '' !== $note;
		$pct    = function ( $v, int $default ): int {
			return is_int( $v ) && $v >= 0 && $v <= 30 ? $v : $default;
		};
		return array(
			'vat_mode' => $exempt ? 'exempt' : 'standard', 'vat_note' => $exempt ? $note : '',
			'own_gateway_percent' => $pct( $pay['own_gateway_percent'] ?? null, 0 ), 'collected_by_us_percent' => $pct( $pay['collected_by_us_percent'] ?? null, 3 ),
			'trial_exports' => true === ( $try['exports'] ?? false ), 'trial_accountant_access' => true === ( $try['accountant_access'] ?? false ),
			// Fine prova senza piano: sconto proposto nelle email (0 = niente offerta) e giorni dopo i quali i dati della prova si cancellano.
			'trial_end_discount_percent' => is_int( $try['end_discount_percent'] ?? null ) && $try['end_discount_percent'] >= 0 && $try['end_discount_percent'] <= 100 ? $try['end_discount_percent'] : 0,
			'trial_purge_days' => is_int( $try['purge_days'] ?? null ) && $try['purge_days'] >= 1 && $try['purge_days'] <= 90 ? $try['purge_days'] : 7,
		);
	}

	/** Frase per le pagine di vendita sulla prova (solo i limiti decisi): vuota se la prova non toglie nulla. */
	public static function trialLimits( array $doc ): string {
		$t    = self::terms( $doc );
		$gone = array();
		if ( ! $t['trial_exports'] ) {
			$gone[] = 'le esportazioni';
		}
		if ( ! $t['trial_accountant_access'] ) {
			$gone[] = 'l\'accesso del commercialista';
		}
		return $gone ? 'tutto tranne ' . implode( ' e ', $gone ) : 'tutto compreso';
	}

	/** Prezzo di un piano per periodo (month|year): il primo con quel periodo. @return array|null */
	public static function price( array $plan, string $period ): ?array {
		foreach ( (array) ( $plan['prices'] ?? array() ) as $p ) {
			if ( is_array( $p ) && ( $p['period'] ?? '' ) === $period ) {
				return $p;
			}
		}
		return null;
	}

	/** Importo da mostrare «da …» (imponibile) per il periodo, alla quantità minima. @return array|null [net, vat_percent] */
	public static function from( array $plan, string $period ): ?array {
		$price = self::price( $plan, $period );
		if ( ! $price ) {
			return null;
		}
		$q   = 'members_tiers' === ( $price['kind'] ?? '' ) ? (int) ( $price['base_members'] ?? 1 ) : 1;
		$net = PriceCalculator::net( $price, max( 1, $q ) );
		return null === $net ? null : array( $net, (int) ( $price['vat_percent'] ?? 0 ) );
	}

	/** Righe della tabella di confronto: gruppo → [funzione → per piano state/text]. */
	public static function comparison( array $doc ): array {
		$groups = array();
		foreach ( (array) $doc['features'] as $f ) {
			if ( is_array( $f ) && is_string( $f['id'] ?? null ) ) {
				$groups[ (string) ( $f['group'] ?? '' ) ][] = $f;
			}
		}
		return $groups;
	}

	private static function unb64( string $s ): string {
		$r = base64_decode( strtr( $s, '-_', '+/' ), true );
		return false === $r ? '' : $r;
	}
}

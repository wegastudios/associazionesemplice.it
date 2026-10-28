<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Punto UNICO in cui il plugin decide se una funzione avanzata è disponibile: `License::allows( 'funzione' )`.
 *
 * La licenza è una chiave legata a UN dominio, emessa dal portale (gratuita o a pagamento) e verificata ogni giorno con il server delle licenze
 * ({@see LicenseClient}). Il plugin gratuito funziona anche senza chiave: la licenza abilita i servizi collegati (prova Pro, avvisi di
 * aggiornamento) e, con il plugin Pro, le funzioni del piano acquistato. Mai nessuna funzione di base dipende dalla licenza.
 *
 * Finché la verifica non è attiva (sito locale) lo stato è `standby` e tutto è consentito.
 * Vedi docs/LICENZE.md.
 */
final class License {

	const OPT_INSTALL_ID  = 'asem_install_id';
	const OPT_INSTALL_URL = 'asem_install_url';
	const OPT_STATE       = 'asem_license_state';

	/** Funzioni avanzate che si bloccano se la licenza a pagamento non è in regola (solo con il plugin Pro installato). */
	const FEATURES = array(
		'export',            // esportazione dei dati (CSV prima nota, rendiconto, soci…)
		'member_area',       // accesso di soci e soci volontari
		'online_payments',   // pagamenti online (WooCommerce)
		'official_notices',  // avvisi ufficiali / notifiche push agli iscritti
		'pwa',               // app installabile
	);

	/**
	 * Livelli di licenza. Il portale vende Free, Pro Lite (senza contabilità) e Pro Full (tutto); «contabile» è il vecchio livello intermedio
	 * (conti, report e ricevute ma non IVA, 5 per mille e anni solari), ancora riconosciuto.
	 */
	const PLAN_FREE       = 'free';
	const PLAN_LITE       = 'lite';
	const PLAN_ACCOUNTING = 'accounting';
	const PLAN_FISCAL     = 'fiscal'; // = Pro Full

	const PLANS = array( self::PLAN_FREE, self::PLAN_LITE, self::PLAN_ACCOUNTING, self::PLAN_FISCAL );

	/** Livello della licenza. Senza verifica attiva (standby) valgono tutte le funzioni; senza chiave, con la verifica attiva, vale il gratuito. */
	public static function plan(): string {
		$s = self::state();
		if ( LicensePolicy::STATUS_NOKEY === $s['status'] ) {
			return self::PLAN_FREE;
		}
		if ( LicenseClient::enabled() ) {
			if ( LicensePolicy::STATUS_ACTIVE === $s['status'] ) { // il livello lo dice la prova firmata, non il campo salvato (modificabile a mano nel database)
				$portal = self::portal_plan( $s ); // prima il piano firmato dal portale: è lui a decidere il livello
				if ( null !== $portal ) {
					return $portal;
				}
				if ( LicenseClient::portal_decides_plan() && '' !== $s['portal_attestation'] ) {
					return self::PLAN_FREE; // la prova del portale c'è ma non regge (scaduta, altro dominio): il livello non si ricava dal nome del piano su Wega
				}
				// nessuna prova del portale ancora salvata (appena aggiornato il plugin): vale per poco la parte firmata da Wega, finché il controllo immediato non porta quella del portale
				$claims = self::attestation_claims( $s );
				if ( $claims && ! empty( $claims['plan'] ) ) {
					return LicenseClient::plan_from_name( (string) $claims['plan'] );
				}
			}
			if ( ! in_array( (string) $s['plan'], self::PLANS, true ) ) {
				return self::PLAN_FREE; // un valore inventato non regala il livello massimo
			}
		}
		return in_array( (string) $s['plan'], self::PLANS, true ) ? (string) $s['plan'] : self::PLAN_FISCAL;
	}

	public static function plan_label( string $plan ): string {
		$map = array( self::PLAN_FREE => 'Gratuito', self::PLAN_LITE => 'Pro Lite', self::PLAN_ACCOUNTING => 'Pro (contabile)', self::PLAN_FISCAL => 'Pro Full' );
		return $map[ $plan ] ?? $plan;
	}

	public static function key(): string {
		return (string) Settings::get( 'license_key' );
	}

	/** La verifica con il server è attiva su questo sito? */
	public static function enabled(): bool {
		return LicenseClient::enabled();
	}

	/** @return array stato salvato: status, since, checked_at, url, plan, reason, expires_at, next_check_at, error */
	public static function state(): array {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'status'        => (string) ( $s['status'] ?? LicensePolicy::STATUS_STANDBY ),
			'since'         => $s['since'] ?? null,
			'checked_at'    => $s['checked_at'] ?? null,
			'url'           => $s['url'] ?? null,
			'plan'          => $s['plan'] ?? null,
			'reason'        => (string) ( $s['reason'] ?? '' ),
			'expires_at'    => $s['expires_at'] ?? null,
			'next_check_at' => $s['next_check_at'] ?? null,
			'in_grace'      => ! empty( $s['in_grace'] ),
			'error'         => (string) ( $s['error'] ?? '' ),
			'attestation'   => (string) ( $s['attestation'] ?? '' ),
			'portal_attestation' => (string) ( $s['portal_attestation'] ?? '' ),
			'product'       => (string) ( $s['product'] ?? '' ),
		);
	}

	/** Lo scrive la verifica con il server (e i test). */
	public static function set_state( string $status, ?string $since = null, ?string $payment_url = null, ?string $plan = null, array $extra = array() ): void {
		update_option(
			self::OPT_STATE,
			array( 'status' => $status, 'since' => $since, 'checked_at' => Db::now(), 'url' => $payment_url, 'plan' => $plan ) + $extra
		);
	}

	/** Indirizzo per regolarizzare il pagamento: il portale delle licenze (modificabile con il filtro `asem_license_portal_url`). */
	public static function payment_url(): string {
		$stored = (string) ( self::state()['url'] ?? '' );
		return '' !== $stored ? $stored : (string) apply_filters( 'asem_license_portal_url', 'https://associazionesemplice.it/accedi' );
	}

	/** Dove si ottiene la chiave gratuita: il portale, con il dominio di questo sito già compilato. */
	public static function free_url(): string {
		$base   = (string) apply_filters( 'asem_license_free_url', 'https://associazionesemplice.it/free' );
		$domain = LicenseClient::normalize_domain( home_url() );
		return '' === $domain ? $base : add_query_arg( 'domain', rawurlencode( $domain ), $base );
	}

	public static function policy(): array {
		$s      = self::state();
		$status = self::effective_status( $s );
		$since  = LicensePolicy::STATUS_UNVERIFIED === $status ? ( $s['checked_at'] ? substr( (string) $s['checked_at'], 0, 10 ) : null ) : $s['since'];
		return LicensePolicy::evaluate( $status, $since, Db::today() );
	}

	/**
	 * Stato in uso: quello salvato, tranne due casi in cui non ci si fida più di «va tutto bene»:
	 *  - «attiva» ma la prova firmata da Wega manca, non torna (stato modificato a mano, altro dominio) o è scaduta da più della tolleranza
	 *    (Limiti: `license_offline_days`): chi blocca i controlli non tiene il piano per sempre;
	 *  - «in attesa» (nessun controllo riuscito) da più di `license_first_check_days`: non basta fermare il controllo periodico per avere tutto.
	 * Sui siti locali non cambia niente.
	 */
	public static function effective_status( array $s ): string {
		if ( ! LicenseClient::enabled() ) {
			return (string) $s['status'];
		}
		$known = array( LicensePolicy::STATUS_STANDBY, LicensePolicy::STATUS_ACTIVE, LicensePolicy::STATUS_UNPAID, LicensePolicy::STATUS_UNLICENSED, LicensePolicy::STATUS_NOKEY, LicensePolicy::STATUS_UNVERIFIED );
		if ( ! in_array( (string) $s['status'], $known, true ) ) {
			return LicensePolicy::STATUS_UNVERIFIED; // uno stato inventato non toglie le restrizioni
		}
		if ( LicensePolicy::STATUS_ACTIVE === $s['status'] ) {
			return self::attestation_holds( $s ) ? LicensePolicy::STATUS_ACTIVE : LicensePolicy::STATUS_UNVERIFIED;
		}
		if ( LicensePolicy::STATUS_STANDBY === $s['status'] && time() > self::first_seen() + Limits::get( 'license_first_check_days' ) * DAY_IN_SECONDS ) {
			return LicensePolicy::STATUS_UNVERIFIED;
		}
		return (string) $s['status'];
	}

	/** Il livello della licenza dalla prova firmata dal portale (salvata nello stato), se regge; altrimenti null. (Si calcola una volta per richiesta.) */
	private static function portal_plan( array $s ): ?string {
		static $memo = array();
		if ( '' === $s['portal_attestation'] ) {
			return null;
		}
		$domain = LicenseClient::normalize_domain( home_url() );
		$leeway = Limits::get( 'license_offline_days' ) * DAY_IN_SECONDS;
		$key    = md5( $s['portal_attestation'] . '|' . $s['product'] . '|' . $domain . '|' . $leeway );
		if ( ! array_key_exists( $key, $memo ) ) {
			$memo[ $key ] = LicenseClient::portal_plan( $s['portal_attestation'], $s['product'], $domain, null, $leeway );
		}
		return $memo[ $key ];
	}

	/** L'attestazione salvata è firmata da Wega, è per questo dominio e non è scaduta oltre la tolleranza? (si calcola una volta per richiesta) */
	private static function attestation_holds( array $s ): bool {
		return null !== self::attestation_claims( $s );
	}

	/** Le affermazioni (livello, dominio…) della prova firmata salvata, se regge; altrimenti null. */
	private static function attestation_claims( array $s ): ?array {
		static $memo = array();
		$domain = LicenseClient::normalize_domain( home_url() );
		$leeway = Limits::get( 'license_offline_days' ) * DAY_IN_SECONDS;
		$key    = md5( $s['attestation'] . '|' . $s['product'] . '|' . $domain . '|' . $leeway );
		if ( ! array_key_exists( $key, $memo ) ) {
			$memo[ $key ] = '' === $s['attestation'] ? null : LicenseClient::verify_attestation( $s['attestation'], LicenseClient::jwks(), LicenseClient::ISSUER, $s['product'], $domain, null, $leeway );
		}
		return $memo[ $key ];
	}

	/** Quando questo sito ha cominciato a usare il plugin con la verifica attiva (si scrive la prima volta che serve). */
	private static function first_seen(): int {
		$v = (int) get_option( 'asem_license_first_seen', 0 );
		if ( $v <= 0 ) {
			$v = time();
			update_option( 'asem_license_first_seen', $v, false );
		}
		return min( $v, time() ); // una data nel futuro non allunga all'infinito il periodo di attesa
	}

	public static function allows( string $feature ): bool {
		return ! in_array( $feature, self::policy()['blocked'], true );
	}

	/**
	 * La licenza non è in regola (pagamento mancante o dominio non più associato)? Allora Associazione Semplice Pro torna alle funzioni di base.
	 * Senza il plugin Pro non c'è niente da sospendere: l'edizione gratuita non perde mai nulla.
	 */
	public static function degraded(): bool {
		return Edition::pro_present() && in_array( self::policy()['status'], LicensePolicy::penalized_statuses(), true );
	}

	/**
	 * Verifica la chiave con il server e salva l'esito. Se il server non risponde lo stato precedente resta com'è (una revoca non
	 * si «annulla» per un errore di rete, e un errore di rete non toglie le funzioni).
	 *
	 * @return array ['ok' => bool, 'message' => string]
	 */
	public static function sync(): array {
		if ( ! LicenseClient::enabled() ) {
			return array( 'ok' => true, 'message' => LicenseClient::is_local() ? 'Ambiente di sviluppo locale: non serve la licenza.' : 'La verifica della licenza non è ancora attiva.' );
		}
		$key = self::key();
		if ( '' === $key ) {
			self::set_state( LicensePolicy::STATUS_NOKEY, null, null, self::PLAN_FREE );
			return array( 'ok' => true, 'message' => 'Nessuna chiave inserita: funzionano le funzioni di base.' );
		}
		$domain = LicenseClient::normalize_domain( home_url() );
		if ( '' === $domain ) {
			return self::failed( 'Indirizzo del sito non utilizzabile per la licenza (serve un nome di dominio).' );
		}
		$r = LicenseClient::validate( $key, $domain );
		if ( is_wp_error( $r ) ) {
			return self::failed( $r->get_error_message() );
		}
		$claims = null;
		$att    = '';
		if ( ! empty( $r['valid'] ) ) {
			// Una licenza valida deve portare l'attestazione firmata da Wega (chiavi scritte nel plugin): senza firma, o con una firma che non torna,
			// non ci si fida e si resta allo stato precedente.
			$att    = (string) ( $r['attestation'] ?? '' );
			$claims = '' === $att ? null : LicenseClient::verify_attestation( $att, LicenseClient::jwks(), LicenseClient::ISSUER, (string) ( $r['product_code'] ?? '' ), $domain );
			if ( null === $claims ) {
				return self::failed( 'La risposta del server delle licenze non ha una firma valida: la licenza non è stata aggiornata.' );
			}
		}
		$i = LicenseClient::interpret( $r );
		if ( $claims && ! empty( $claims['plan'] ) ) {
			$i['plan'] = LicenseClient::plan_from_name( (string) $claims['plan'] ); // il livello lo dice la parte firmata, non quella modificabile in transito
		}
		$extra       = array( 'reason' => $i['reason'], 'expires_at' => $i['expires_at'], 'next_check_at' => $i['next_check_at'], 'in_grace' => $i['in_grace'] );
		$portal_att  = $claims ? (string) ( $r['portal_attestation'] ?? '' ) : '';
		$portal_plan = '' === $portal_att ? null : LicenseClient::portal_plan( $portal_att, (string) ( $r['product_code'] ?? '' ), $domain );
		if ( $claims && null === $portal_plan && LicenseClient::portal_decides_plan() ) {
			return self::failed( 'La risposta del portale non porta il piano firmato: la licenza non è stata aggiornata.' ); // il livello lo decide il portale, mai il nome su Wega
		}
		if ( null !== $portal_plan ) { // il piano firmato dal portale vale più del nome salvato su Wega
			$i['plan']                  = $portal_plan;
			$extra['portal_attestation'] = $portal_att;
		}
		if ( $claims ) {
			$extra['attestation'] = $att;
			$extra['product']     = (string) ( $r['product_code'] ?? '' );
		}
		self::set_state( $i['status'], $i['since'], null, $i['plan'], $extra );
		$msg = LicenseClient::reason_label( $i['reason'] );
		if ( LicensePolicy::STATUS_ACTIVE === $i['status'] ) {
			$msg = 'Licenza ' . self::plan_label( $i['plan'] ) . ' valida' . ( $i['expires_at'] ? ' fino al ' . gmdate( 'd/m/Y', strtotime( $i['expires_at'] ) ) : '' ) . '.' . ( $i['in_grace'] ? ' ' . LicenseClient::reason_label( 'IN_GRACE' ) : '' );
		}
		return array( 'ok' => LicensePolicy::STATUS_ACTIVE === $i['status'], 'message' => '' !== $msg ? $msg : 'Licenza non valida.' );
	}

	/** Errore di rete o di risposta: lo si ricorda per mostrarlo, ma lo stato non cambia. */
	private static function failed( string $message ): array {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		update_option( self::OPT_STATE, array_merge( $s, array( 'error' => $message, 'error_at' => Db::now() ) ) );
		return array( 'ok' => false, 'message' => $message );
	}

	/** Stato «attivo» salvato da una versione che non conservava la prova firmata: la si rinnova subito invece di aspettare il giro del giorno dopo. */
	public static function maybe_resync(): void {
		$s = self::state();
		$no_proof = '' === $s['attestation'] || ( LicenseClient::portal_decides_plan() && '' === $s['portal_attestation'] ); // anche la prova del piano firmata dal portale
		if ( LicenseClient::enabled() && LicensePolicy::STATUS_ACTIVE === $s['status'] && $no_proof && ! wp_next_scheduled( 'asem_license_resync' ) ) {
			wp_schedule_single_event( time() + 30, 'asem_license_resync' );
		}
	}

	/** Lavoro giornaliero di WP-Cron. */
	public static function cron(): void {
		self::sync();
	}

	/**
	 * Identità di QUESTA installazione: un id casuale che resta finché il sito non cambia indirizzo.
	 * Se il sito viene copiato altrove (es. produzione -> staging) la copia porta con sé il database, quindi lo
	 * stesso id: lo si riconosce perché l'indirizzo non coincide più, e si genera un id nuovo.
	 *
	 * @return array ['id'=>string, 'url'=>string, 'domain'=>string, 'moved'=>bool]
	 */
	public static function installation(): array {
		$url   = home_url();
		$host  = LicenseRules::host( $url );
		$id    = (string) get_option( self::OPT_INSTALL_ID, '' );
		$known = (string) get_option( self::OPT_INSTALL_URL, '' );
		$moved = false;
		if ( '' === $id || ( '' !== $known && LicenseRules::host( $known ) !== $host ) ) {
			$moved = '' !== $id;
			$id    = wp_generate_uuid4();
			update_option( self::OPT_INSTALL_ID, $id );
		}
		if ( $known !== $url ) {
			update_option( self::OPT_INSTALL_URL, $url );
		}
		return array( 'id' => $id, 'url' => $url, 'domain' => LicenseClient::normalize_domain( $url ) ?: self::domain( $url ), 'moved' => $moved );
	}

	/** Stato mostrabile nelle impostazioni. */
	public static function status(): array {
		$key    = self::key();
		$inst   = self::installation();
		$policy = self::policy();
		$state  = self::state();
		$enabled = self::enabled();
		$note   = LicensePolicy::STATUS_STANDBY === $policy['status'] ? 'Verifica della licenza non ancora attiva: tutte le funzioni sono disponibili.' : LicensePolicy::message( $policy['status'] );
		if ( LicensePolicy::STATUS_ACTIVE === $policy['status'] ) {
			$note = 'Licenza ' . self::plan_label( self::plan() ) . ' valida' . ( $state['expires_at'] ? ' fino al ' . gmdate( 'd/m/Y', strtotime( (string) $state['expires_at'] ) ) : '' ) . '.';
		}
		return array(
			'state'      => '' === $key ? 'none' : 'unchecked',
			'status'     => $policy['status'],
			'domain'     => $inst['domain'],
			'install_id' => $inst['id'],
			'local'      => LicenseClient::is_local(),
			'enabled'    => $enabled,
			'plan'       => self::plan(),
			'plan_label' => self::plan_label( self::plan() ),
			'checked_at' => $state['checked_at'],
			'reason'     => LicenseClient::reason_label( $state['reason'] ),
			'error'      => $state['error'],
			'note'       => $note,
		);
	}

	/** Dominio senza schema, percorso e "www." (per mostrarlo): "https://www.Esempio.it/x" => "esempio.it". */
	public static function domain( string $url ): string {
		$host = LicenseRules::host( $url );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}

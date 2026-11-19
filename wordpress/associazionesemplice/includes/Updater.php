<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Aggiornamento diretto da WordPress (Bacheca → Aggiornamenti) di Associazione Semplice e di Associazione Semplice Pro dal portale, finché il gratuito non sta su WordPress.org.
 *
 * Questo file sta nel pacchetto del portale ma NON in quello per WordPress.org (`build.sh` con `ASEM_WPORG=1` lo toglie): lì gli aggiornamenti li dà WordPress.org.
 * Ogni 12 ore o più (limite `update_check_hours`) chiede al portale l'ultima versione di ciò che è installato (`POST /api/plugin/update`) e
 * la mette nell'elenco degli aggiornamenti di WordPress. Il pacchetto firmato dal portale vale pochi minuti: per questo si chiede di nuovo
 * proprio quando si preme «Aggiorna», non si conserva. Il gratuito manda solo prodotto e versione; il Pro anche la chiave di licenza e il dominio.
 * Un errore del portale non cambia niente: restano le ultime versioni note.
 */
final class Updater {

	const API    = 'https://associazionesemplice.it/api/plugin/update';
	const OPT    = 'asem_portal_updates';
	const PREFIX = 'asem-portal://';
	/** Versioni più alte e soglie minime già viste con firma v2 valida, per prodotto (opzione senza autoload). */
	const SEEN_OPT = 'asem_portal_seen';
	/** Prefisso del messaggio firmato v2. */
	const V2_TAG = 'asem-pkg-v2';
	/** Una firma datata oltre questo margine nel futuro non vale (secondi). */
	const V2_FUTURE_SLACK = 86400;

	/**
	 * Chiavi pubbliche con cui si firmano i pacchetti (kid => Ed25519, base64url). La chiave privata non sta sul server del portale: chi lo
	 * compromette non può far installare codice sui siti. Un kid sconosciuto o una firma sbagliata bloccano l'installazione.
	 * Può contenere più chiavi: ogni firma si verifica con la chiave del proprio kid.
	 */
	const PACKAGE_KEYS = array(
		'pkg-2026-10-85dce7' => 'ld_4NilmHT-TtaH8vUw_YGq7eEt4a0e4L4YAxSzP6oI',
	);

	const REFRESH_HOOK = 'asem_updater_refresh'; // evento singolo: chiede al portale lo stato degli aggiornamenti fuori dalla pagina

	/** Istante dell'ultimo controllo forzato (link «Controlla aggiornamenti», «Controlla di nuovo» di WordPress, cambio del gratuito): al più uno al minuto. */
	const FORCE_OPT = 'asem_updater_forced';
	/** Un nuovo controllo è stato chiesto (il gratuito è cambiato): lo fa il lavoro in background, senza aspettare le 12 ore. */
	const RECHECK_OPT = 'asem_updater_recheck';
	/** Versione del gratuito vista l'ultima volta: se cambia (anche da WordPress.org) il Pro ricontrolla il portale. */
	const FREE_VERSION_OPT = 'asem_updater_free_version';
	const FORCE_GAP        = 60;

	private static $registered = false;
	/** Perché l'ultima richiesta al portale non ha dato niente: nokey, license, busy, net. */
	private static $last_error = '';

	public static function register(): void {
		if ( self::$registered ) { // il gratuito del portale e il Pro la chiamano entrambi: una volta sola
			return;
		}
		self::$registered = true;
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_asem_check_updates', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'print_result' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'watch_free_version' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_in_background' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	/**
	 * Testo suggerito per Strumenti → Privacy: dice all'amministratore cosa contatta il programma. Esiste solo in questo pacchetto
	 * (quello del portale), perché solo questo contatta il portale: nel pacchetto di WordPress.org la classe non c'è e il testo non compare.
	 * Ogni paragrafo è una frase intera, così la traduzione (languages/en.json) la trova uguale.
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p   = array();
		$p[] = '<h2>Aggiornamenti dal sito associazionesemplice.it</h2>';
		$p[] = '<p>Questa copia di Associazione Semplice è stata scaricata dal sito associazionesemplice.it, non da WordPress.org. Per offrirti gli aggiornamenti in Bacheca → Aggiornamenti, il programma contatta quel sito (indirizzo https://associazionesemplice.it/api/plugin/update) e gli invia solo il nome del prodotto («free») e la versione installata. Lo fa al massimo ogni 12 ore o più (di solito una volta al giorno), anche senza chiave di licenza, e mai su un sito di prova locale.</p>';
		$p[] = '<p>Non invia la chiave di licenza, il dominio né dati dei soci o dell\'associazione. Come ogni richiesta su internet, il sito riceve l\'indirizzo IP del server e l\'User-Agent di WordPress, che contiene l\'indirizzo di questo sito.</p>';
		$p[] = '<p>Si spegne da Impostazioni → Limiti e soglie, togliendo il segno da «Aggiornamenti diretti di Associazione Semplice e del Pro»: se è già spento, non parte nessuna richiesta, e gli aggiornamenti vanno scaricati dall\'area clienti e caricati a mano.</p>';
		if ( isset( self::products()['pro'] ) ) {
			$p[] = '<p>Con Associazione Semplice Pro attivo, la richiesta di aggiornamento porta con sé anche la chiave di licenza e il dominio del sito, e una volta al giorno il Pro verifica la licenza inviando gli stessi due dati. Nemmeno in questo caso partono dati dei soci.</p>';
		}
		wp_add_privacy_policy_content( 'Associazione Semplice', wp_kses_post( implode( '', $p ) ) );
	}

	/** Scelta dell'amministratore (Limiti e soglie) e sito non locale. */
	public static function enabled(): bool {
		return 1 === Limits::get( 'portal_updates' ) && ! self::is_local();
	}

	/** Sito di sviluppo (localhost, indirizzi IP, *.local, *.test…): niente richieste al portale. Il Pro può rispondere al posto del gratuito. */
	private static function is_local(): bool {
		$v = apply_filters( 'asem_portal_is_local', null );
		if ( null !== $v ) {
			return (bool) $v;
		}
		$h = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $h || false === strpos( $h, '.' ) || filter_var( $h, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test', '.invalid', '.example' ) as $suffix ) {
			if ( substr( $h, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/** Versione in forma «1.2» o «1.2.3(.4)». */
	private static function valid_version( string $v ): bool {
		return (bool) preg_match( '/^\d{1,3}(\.\d{1,4}){1,3}$/', $v );
	}

	private static function is_newer( string $installed, string $latest ): bool {
		return self::valid_version( $installed ) && self::valid_version( $latest ) && version_compare( $latest, $installed, '>' );
	}

	public static function api(): string {
		return (string) apply_filters( 'asem_portal_update_api', self::API );
	}

	/** I plugin installati che si aggiornano da qui. @return array<string,array{basename:string,slug:string,name:string,version:string}> */
	public static function products(): array {
		$out = array();
		if ( is_readable( ASEM_DIR . 'includes/Updater.php' ) ) { // il gratuito scaricato da WordPress.org non ha questo file e si aggiorna da WordPress.org: qui solo il Pro
			$out['free'] = array( 'basename' => plugin_basename( ASEM_FILE ), 'slug' => 'associazionesemplice', 'name' => 'Associazione Semplice', 'version' => (string) ASEM_VERSION );
		}
		if ( defined( 'ASEM_PRO_VERSION' ) && defined( 'ASEM_PRO_DIR' ) ) {
			$out['pro'] = array( 'basename' => plugin_basename( ASEM_PRO_DIR . 'associazionesemplice-pro.php' ), 'slug' => 'associazionesemplice-pro', 'name' => 'Associazione Semplice Pro', 'version' => (string) ASEM_PRO_VERSION );
		}
		return $out;
	}

	/**
	 * Risposta del portale → dati validi o null. La versione deve essere numerica; gli indirizzi devono essere https.
	 *
	 * @param mixed $body testo JSON
	 * @return array{version:string,update:bool,package:string,url:string}|null
	 */
	public static function parse( $body ): ?array {
		$j = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $j ) || ! isset( $j['version'] ) || ! is_string( $j['version'] ) || ! self::valid_version( trim( $j['version'] ) ) ) {
			return null;
		}
		$https = function ( $v ) {
			return is_string( $v ) && 0 === strpos( trim( $v ), 'https://' ) ? trim( $v ) : '';
		};
		return array(
			'version' => trim( $j['version'] ),
			'update'  => ! empty( $j['update'] ),
			'package' => $https( $j['package'] ?? '' ),
			'url'     => $https( $j['url'] ?? '' ),
			'sha256'  => isset( $j['sha256'] ) && is_string( $j['sha256'] ) ? strtolower( trim( $j['sha256'] ) ) : '',
			'signature' => isset( $j['signature'] ) && is_string( $j['signature'] ) ? trim( $j['signature'] ) : '',
			'kid'     => isset( $j['kid'] ) && is_string( $j['kid'] ) ? trim( $j['kid'] ) : '',
			// firma v2: se il portale dichiara anche solo uno di questi campi, la v2 è obbligatoria (un campo di tipo sbagliato resta vuoto e fa rifiutare il pacchetto)
			'v2'      => self::declares_v2( $j ),
			'signature_v2' => isset( $j['signature_v2'] ) && is_string( $j['signature_v2'] ) ? trim( $j['signature_v2'] ) : '',
			'kid_v2'  => isset( $j['kid_v2'] ) && is_string( $j['kid_v2'] ) ? trim( $j['kid_v2'] ) : '',
			'signed_at' => isset( $j['signed_at'] ) && is_int( $j['signed_at'] ) ? $j['signed_at'] : 0,
			'min_version' => isset( $j['min_version'] ) && is_string( $j['min_version'] ) ? trim( $j['min_version'] ) : '',
			'changelog' => isset( $j['changelog'] ) && is_string( $j['changelog'] ) ? mb_substr( $j['changelog'], 0, 20000 ) : '',
		);
	}

	/** La risposta dichiara almeno un campo della firma v2 (presente e non nullo)? */
	private static function declares_v2( array $j ): bool {
		foreach ( array( 'signature_v2', 'kid_v2', 'signed_at', 'min_version' ) as $k ) {
			if ( array_key_exists( $k, $j ) && null !== $j[ $k ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Il log delle novità del portale (stesso formato di CHANGELOG.md: «## 1.1.18» e righe «- …») → HTML per la scheda dei dettagli.
	 * Tutto il testo viene protetto: vale solo ciò che segue questo formato.
	 */
	public static function changelog_html( string $md ): string {
		$html   = '';
		$open   = false; // un elenco è aperto
		$inside = false; // dentro una versione
		foreach ( preg_split( '/\R/', $md ) ?: array() as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^##\s+(\d+(?:\.\d+){1,2})(?:\s+–\s+(\d{4}-\d{2}-\d{2}))?$/u', $line, $m ) ) {
				$title  = $m[1] . ( isset( $m[2] ) ? ' – ' . $m[2] : '' );
				$html  .= ( $open ? '</ul>' : '' ) . '<h4>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</h4><ul>';
				$open   = true;
				$inside = true;
			} elseif ( $inside && "Da fare dopo l'aggiornamento:" === $line ) {
				$html .= ( $open ? '</ul>' : '' ) . '<p><strong>' . htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' ) . '</strong></p><ul>';
				$open  = true;
			} elseif ( $inside && preg_match( '#^Changelog di tutte le versioni: (https://associazionesemplice\.it/[A-Za-z0-9/_\-.]*)$#', $line, $m ) ) {
				$html .= ( $open ? '</ul>' : '' ) . '<p><a href="' . htmlspecialchars( $m[1], ENT_QUOTES, 'UTF-8' ) . '">Changelog di tutte le versioni</a></p>';
				$open  = false;
			} elseif ( $open && preg_match( '/^-\s+(.+)$/', $line, $m ) ) {
				$text = htmlspecialchars( $m[1], ENT_QUOTES, 'UTF-8' );
				// Etichetta di tipo in grassetto: «Nuovo:», «Ottimizzazione:», «Sicurezza:», «Altro:».
				$text  = preg_replace( '/^(Nuovo|Ottimizzazione|Sicurezza|Altro):/u', '<strong>$1:</strong>', $text ) ?? $text;
				$html .= '<li>' . $text . '</li>';
			}
		}
		return $inside ? $html . ( $open ? '</ul>' : '' ) : '';
	}

	/**
	 * Le ultime novità dal CHANGELOG.md che sta dentro il plugin installato (al massimo le ultime $versions versioni, con un solo link finale).
	 * Serve quando il portale non manda il testo, per esempio perché il sito è già aggiornato e non c'è una versione più nuova.
	 */
	public static function local_changelog( string $product, int $versions = 3 ): string {
		$dir  = 'pro' === $product ? ( defined( 'ASEM_PRO_DIR' ) ? (string) ASEM_PRO_DIR : '' ) : ( defined( 'ASEM_DIR' ) ? (string) ASEM_DIR : '' );
		$file = '' !== $dir ? rtrim( $dir, '/\\' ) . '/CHANGELOG.md' : '';
		$raw  = '' !== $file && is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file del plugin, non un indirizzo
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		$out   = array();
		$link  = '';
		$count = 0;
		foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( 0 === strpos( $line, '## ' ) ) {
				if ( ++$count > $versions ) {
					break;
				}
				$out[] = $line;
			} elseif ( $count > 0 && 0 === strpos( $line, 'Changelog di tutte le versioni:' ) ) {
				$link = $line;
			} elseif ( $count > 0 && ( 0 === strpos( $line, '- ' ) || "Da fare dopo l'aggiornamento:" === $line ) ) {
				$out[] = $line;
			}
		}
		return $out ? implode( "\n", $out ) . ( '' !== $link ? "\n" . $link : '' ) : '';
	}

	/** base64url senza padding → byte ('' se non valido). */
	private static function b64d( string $s ): string {
		$r = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $r ? '' : $r;
	}

	/**
	 * La firma del portale per «prodotto|versione|sha256» torna con la chiave del suo kid?
	 *
	 * @param array<string,string> $keys kid => chiave pubblica (base64url)
	 */
	public static function verify_signature( string $product, string $version, string $sha256, string $kid, string $signature, array $keys ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! isset( $keys[ $kid ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return false;
		}
		$pub = self::b64d( $keys[ $kid ] );
		$sig = self::b64d( $signature );
		return 32 === strlen( $pub ) && 64 === strlen( $sig ) && sodium_crypto_sign_verify_detached( $sig, $product . '|' . $version . '|' . $sha256, $pub );
	}

	/** Soglia minima in forma «1.2» o «1.2.3». */
	private static function valid_min_version( string $v ): bool {
		return (bool) preg_match( '/^\d{1,3}(\.\d{1,4}){1,2}$/', $v );
	}

	/**
	 * La firma v2 del portale per «asem-pkg-v2|prodotto|versione|sha256|signed_at|min_version» torna con la chiave del suo kid?
	 * Controlla anche la forma dei campi; il confronto con la soglia, la data e le versioni già viste sta in v2_verdict().
	 *
	 * @param array<string,string> $keys kid => chiave pubblica (base64url)
	 */
	public static function verify_signature_v2( string $product, string $version, string $sha256, int $signed_at, string $min_version, string $kid, string $signature, array $keys ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! isset( $keys[ $kid ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return false;
		}
		if ( $signed_at <= 0 || ! self::valid_version( $version ) || ! self::valid_min_version( $min_version ) ) {
			return false;
		}
		$pub = self::b64d( $keys[ $kid ] );
		$sig = self::b64d( $signature );
		$msg = self::V2_TAG . '|' . $product . '|' . $version . '|' . $sha256 . '|' . $signed_at . '|' . $min_version;
		return 32 === strlen( $pub ) && 64 === strlen( $sig ) && sodium_crypto_sign_verify_detached( $sig, $msg, $pub );
	}

	/**
	 * Regole sul pacchetto offerto, prima di proporlo o di scaricarlo. Senza campi v2 non ce ne sono (resta la firma v1, controllata allo scarico).
	 * Con campi v2 la v2 è obbligatoria e valida: niente ripiego sulla v1.
	 *
	 * @param array                                      $r    risposta di parse()
	 * @param array<string,string>                       $keys kid => chiave pubblica
	 * @param array{version?:string,min_version?:string} $seen cosa si è già visto per questo prodotto
	 * @return string '' se va bene, altrimenti il motivo: invalid (firma v2 non valida), min (sotto la soglia), rollback (sotto la versione più alta già vista), future (firma datata nel futuro), missing_v2 (pacchetto senza v2 dopo che ne è già stato visto uno)
	 */
	public static function v2_verdict( string $product, array $r, array $keys, array $seen, int $now ): string {
		$seen_min = (string) ( $seen['min_version'] ?? '' );
		$seen_v   = (string) ( $seen['version'] ?? '' );
		if ( empty( $r['v2'] ) ) {
			if ( ! empty( $seen['v2'] ) ) { // già visto un pacchetto v2 per questo prodotto: da qui in poi la v2 è obbligatoria
				return 'missing_v2';
			}
			$v1 = (string) ( $r['version'] ?? '' ); // la v1 è ancora ammessa, ma senza passi indietro rispetto a quanto già visto
			if ( '' !== $seen_min && self::valid_min_version( $seen_min ) && version_compare( $v1, $seen_min, '<' ) ) {
				return 'min';
			}
			if ( '' !== $seen_v && self::valid_version( $seen_v ) && version_compare( $v1, $seen_v, '<' ) ) { // la stessa versione resta ammessa
				return 'rollback';
			}
			return '';
		}
		$version = (string) ( $r['version'] ?? '' );
		$min     = (string) ( $r['min_version'] ?? '' );
		$at      = (int) ( $r['signed_at'] ?? 0 );
		if ( ! self::verify_signature_v2( $product, $version, (string) ( $r['sha256'] ?? '' ), $at, $min, (string) ( $r['kid_v2'] ?? '' ), (string) ( $r['signature_v2'] ?? '' ), $keys ) ) {
			return 'invalid';
		}
		if ( version_compare( $version, $min, '<' ) ) {
			return 'min';
		}
		if ( '' !== $seen_min && self::valid_min_version( $seen_min ) && version_compare( $version, $seen_min, '<' ) ) {
			return 'min';
		}
		if ( '' !== $seen_v && self::valid_version( $seen_v ) && version_compare( $version, $seen_v, '<' ) ) { // la stessa versione ripubblicata resta ammessa
			return 'rollback';
		}
		if ( $at > $now + self::V2_FUTURE_SLACK ) {
			return 'future';
		}
		return '';
	}

	/** Versione e soglia più alte tra quelle già viste e quelle del pacchetto verificato: non scendono mai. */
	public static function seen_after( array $seen, string $version, string $min_version, bool $v2 = true ): array {
		$v = (string) ( $seen['version'] ?? '' );
		$m = (string) ( $seen['min_version'] ?? '' );
		if ( '' === $m || ! self::valid_min_version( $m ) ) {
			$m = $min_version; // un pacchetto v1 non ha soglia: resta quella già vista (se c'è)
		} elseif ( '' !== $min_version && version_compare( $min_version, $m, '>' ) ) {
			$m = $min_version;
		}
		return array(
			'version'     => '' === $v || ! self::valid_version( $v ) || version_compare( $version, $v, '>' ) ? $version : $v,
			'min_version' => $m,
			'v2'          => $v2 || ! empty( $seen['v2'] ), // dal primo pacchetto v2 in poi, senza v2 non si accetta più niente
		);
	}

	/** @return array{version?:string,min_version?:string,v2?:bool} */
	private static function seen( string $product ): array {
		$all = get_option( self::SEEN_OPT, array() );
		return is_array( $all ) && isset( $all[ $product ] ) && is_array( $all[ $product ] ) ? $all[ $product ] : array();
	}

	private static function remember( string $product, array $r, bool $v2 = true ): void {
		$all             = get_option( self::SEEN_OPT, array() );
		$all             = is_array( $all ) ? $all : array();
		$all[ $product ] = self::seen_after( self::seen( $product ), (string) $r['version'], $v2 ? (string) $r['min_version'] : '', $v2 );
		update_option( self::SEEN_OPT, $all, false );
	}

	/** Motivo del rifiuto o '' se il pacchetto va bene; se è un v2 valido ricorda versione e soglia più alte. */
	private static function package_verdict( string $product, array $r ): string {
		$v = self::v2_verdict( $product, $r, self::PACKAGE_KEYS, self::seen( $product ), time() );
		if ( '' === $v && ! empty( $r['v2'] ) ) {
			self::remember( $product, $r );
		}
		return $v;
	}

	/** Il pacchetto viene davvero dal portale (stesso sito dell'indirizzo di verifica)? */
	public static function package_is_ours( string $package ): bool {
		$a = wp_parse_url( self::api() );
		$p = wp_parse_url( $package );
		return ! empty( $a['host'] ) && ! empty( $p['host'] ) && 'https' === ( $p['scheme'] ?? '' ) && strtolower( $a['host'] ) === strtolower( $p['host'] );
	}

	/**
	 * Chiede al portale lo stato di un prodotto. Il Pro senza chiave non chiede niente.
	 *
	 * @return array|null dati validi (vedi parse), null se non si può o il portale non risponde bene
	 */
	public static function ask( string $product ): ?array {
		$all              = self::products();
		self::$last_error = '';
		if ( ! isset( $all[ $product ] ) ) {
			return null;
		}
		$body = array( 'product' => $product, 'version' => $all[ $product ]['version'] ); // il gratuito non manda niente del sito
		if ( 'pro' === $product ) {
			$body['domain'] = (string) apply_filters( 'asem_portal_domain', '' ); // lo dà il Pro
			$key            = (string) apply_filters( 'asem_portal_license_key', '' ); // lo dà il Pro
			if ( '' === $key || '' === $body['domain'] ) {
				self::$last_error = 'nokey';
				return null;
			}
			$body['license_key'] = $key;
		}
		$res = wp_remote_post(
			self::api(),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$code             = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			self::$last_error = 403 === $code ? 'license' : ( 429 === $code ? 'busy' : 'net' );
			return null; // 403 (licenza), 429 (troppe richieste), rete: si tiene l'ultimo stato
		}
		$r = self::parse( wp_remote_retrieve_body( $res ) );
		if ( ! $r ) {
			self::$last_error = 'net';
		}
		return $r;
	}

	/** Ultimo stato salvato. @return array{checked_at:?string,free?:array,pro?:array} */
	public static function state(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s + array( 'checked_at' => null ) : array( 'checked_at' => null );
	}

	private static function due(): bool {
		$last = self::state()['checked_at'];
		return ! $last || strtotime( (string) $last . ' UTC' ) + max( 12, Limits::get( 'update_check_hours' ) ) * HOUR_IN_SECONDS <= time();
	}

	/** Aggiorna lo stato chiedendo al portale (un prodotto che non risponde conserva l'ultimo dato). */
	public static function refresh( array $only = array() ): void {
		$state = self::state();
		foreach ( array_keys( self::products() ) as $p ) {
			if ( $only && ! in_array( $p, $only, true ) ) {
				continue;
			}
			$r = self::ask( $p );
			if ( $r ) {
				$state[ $p ] = $r;
			}
		}
		$state['checked_at'] = Db::now();
		update_option( self::OPT, $state, false );
	}

	/** Lavoro in background: aggiorna lo stato e rimette l'elenco degli aggiornamenti di WordPress al passo, senza attendere la prossima scadenza. */
	public static function refresh_in_background(): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( get_option( self::RECHECK_OPT ) ) { // controllo chiesto dal cambio del gratuito: subito, ma al più uno al minuto
			delete_option( self::RECHECK_OPT );
			if ( ! self::force_allowed() ) {
				return;
			}
		} elseif ( ! self::due() ) {
			return;
		}
		self::refresh();
		delete_site_transient( 'update_plugins' ); // alla prossima visita WordPress lo ricostruisce con lo stato nuovo
	}

	/** Un controllo forzato è permesso? Al più uno al minuto per sito, per non martellare il portale. */
	private static function force_allowed(): bool {
		$last = (int) get_option( self::FORCE_OPT, 0 );
		if ( $last && time() - $last < self::FORCE_GAP ) {
			return false;
		}
		update_option( self::FORCE_OPT, time(), false );
		return true;
	}

	/** Chiede un nuovo controllo al lavoro in background (il gratuito è cambiato). */
	private static function queue_recheck(): void {
		update_option( self::RECHECK_OPT, time(), false );
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REFRESH_HOOK );
		}
	}

	/** La versione del Pro che il portale offre, se è più nuova di quella installata. */
	public static function pro_update_available(): ?string {
		$p = self::state()['pro'] ?? array();
		if ( empty( $p['update'] ) || empty( $p['version'] ) || ! defined( 'ASEM_PRO_VERSION' ) || ! self::is_newer( (string) ASEM_PRO_VERSION, (string) $p['version'] ) ) {
			return null;
		}
		return (string) $p['version'];
	}

	/** Indirizzo del controllo manuale (con il controllo di sicurezza di WordPress). */
	public static function check_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=asem_check_updates' ), 'asem_check_updates' );
	}

	/**
	 * Controllo subito, a richiesta: chiede il Pro al portale e sostituisce ciò che si sapeva.
	 *
	 * @return string off | nopro | wait | nokey | license | err | new | none
	 */
	public static function check_now(): string {
		if ( ! self::enabled() ) {
			return 'off';
		}
		if ( ! isset( self::products()['pro'] ) ) {
			return 'nopro';
		}
		if ( ! self::force_allowed() ) {
			return 'wait';
		}
		$r = self::ask( 'pro' );
		if ( ! $r ) {
			return in_array( self::$last_error, array( 'nokey', 'license' ), true ) ? self::$last_error : ( 'busy' === self::$last_error ? 'wait' : 'err' );
		}
		$state               = self::state();
		$state['pro']        = $r;
		$state['checked_at'] = Db::now();
		update_option( self::OPT, $state, false );
		delete_site_transient( 'update_plugins' ); // alla prossima visita WordPress lo ricostruisce con lo stato nuovo
		return null !== self::pro_update_available() ? 'new' : 'none';
	}

	/** Nella riga del Pro in Plugin, accanto al rimando al sito: «Controlla aggiornamenti». */
	public static function row_meta( $meta, $file ) {
		$all = self::products();
		if ( is_array( $meta ) && isset( $all['pro'] ) && $file === $all['pro']['basename'] && current_user_can( 'update_plugins' ) && self::enabled() ) {
			$meta[] = '<a href="' . esc_url( self::check_url() ) . '">' . esc_html( 'Controlla aggiornamenti' ) . '</a>';
		}
		return $meta;
	}

	public static function handle_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html( 'Non hai il permesso di controllare gli aggiornamenti.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'asem_check_updates' );
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( 'asem_upd', $back ) : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'asem_upd', self::check_now(), $back ) );
		exit;
	}

	/** Esito del controllo manuale, in cima alla pagina da cui si è partiti. */
	public static function print_result(): void {
		if ( empty( $_GET['asem_upd'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura dell'esito
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['asem_upd'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ver  = defined( 'ASEM_PRO_VERSION' ) ? (string) ASEM_PRO_VERSION : '';
		$new  = (string) ( self::state()['pro']['version'] ?? '' );
		$msg  = array(
			'none'    => array( 'success', 'Nessun aggiornamento: Associazione Semplice Pro è già alla versione più recente (' . $ver . ').' ),
			'new'     => array( 'success', 'Aggiornamento disponibile: Associazione Semplice Pro ' . $new . '. Installalo da Bacheca → Aggiornamenti.' ),
			'err'     => array( 'warning', 'Il portale non ha risposto. Riprova tra qualche minuto: intanto non cambia niente.' ),
			'wait'    => array( 'info', 'Hai già controllato da poco: riprova tra un minuto.' ),
			'nokey'   => array( 'warning', 'Per controllare gli aggiornamenti del Pro serve la chiave di licenza: inseriscila in Associazione Semplice → Licenza.' ),
			'license' => array( 'warning', 'Il portale non riconosce la licenza del Pro per questo sito: controllala in Associazione Semplice → Licenza.' ),
			'off'     => array( 'warning', 'Gli aggiornamenti diretti sono spenti: scarica il Pro dall\'area clienti, oppure riaccendili da Impostazioni → Limiti e soglie.' ),
		);
		if ( ! isset( $msg[ $code ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $msg[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $msg[ $code ][1] );
		if ( 'new' === $code ) {
			echo ' <a class="button button-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html( 'Vai agli aggiornamenti' ) . '</a>';
		}
		echo '</p></div>';
	}

	/** Un plugin è stato aggiornato: se è il gratuito (anche da WordPress.org) il Pro ricontrolla subito il portale. */
	public static function after_upgrade( $upgrader, $options ): void {
		if ( ! is_array( $options ) || 'plugin' !== ( $options['type'] ?? '' ) || 'update' !== ( $options['action'] ?? '' ) || ! defined( 'ASEM_FILE' ) ) {
			return;
		}
		$files = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : ( isset( $options['plugin'] ) ? array( $options['plugin'] ) : array() );
		if ( in_array( plugin_basename( ASEM_FILE ), $files, true ) && self::enabled() ) {
			self::queue_recheck();
		}
	}

	/** La versione del gratuito installato è diversa dall'ultima vista (aggiornamento da WordPress.org, carico a mano…): il Pro ricontrolla il portale. */
	public static function watch_free_version(): void {
		if ( ! defined( 'ASEM_VERSION' ) ) {
			return;
		}
		$saved = get_option( self::FREE_VERSION_OPT, null );
		if ( (string) ASEM_VERSION === $saved ) {
			return;
		}
		update_option( self::FREE_VERSION_OPT, (string) ASEM_VERSION, false );
		if ( null !== $saved && self::enabled() ) {
			self::queue_recheck();
		}
	}

	/** Icone del plugin nell'elenco degli aggiornamenti (immagini pubblicate dal portale). */
	private static function icons(): array {
		$base = 'https://associazionesemplice.it/assets/plugin/';
		return array( '1x' => $base . 'icon-128x128.png', '2x' => $base . 'icon-256x256.png', 'default' => $base . 'icon-256x256.png' );
	}

	/** Banner nella scheda dei dettagli. */
	private static function banners(): array {
		$base = 'https://associazionesemplice.it/assets/plugin/';
		return array( 'low' => $base . 'banner-772x250.png', 'high' => $base . 'banner-1544x500.png' );
	}

	/** Filtro di WordPress: mette gli aggiornamenti nell'elenco (Bacheca → Aggiornamenti e pagina Plugin). */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! self::enabled() ) {
			return $transient;
		}
		if ( is_admin() && isset( $_GET['force-check'] ) && self::force_allowed() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura del parametro con cui WordPress chiede «Controlla di nuovo»
			self::refresh(); // richiesta esplicita: si chiede subito al portale, senza aspettare le 12 ore (al più uno al minuto)
		} elseif ( self::due() ) {
			if ( wp_doing_cron() ) {
				self::refresh(); // lavoro in background: la chiamata al portale non rallenta una pagina qualunque
			} elseif ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
				wp_schedule_single_event( time(), self::REFRESH_HOOK ); // si mostra l'ultimo stato salvato; quello nuovo arriva dal lavoro in background
			}
		}
		$state = self::state();
		foreach ( self::products() as $p => $info ) {
			if ( empty( $state[ $p ]['version'] ) || ! self::is_newer( $info['version'], (string) $state[ $p ]['version'] ) || empty( $state[ $p ]['update'] ) ) {
				continue;
			}
			if ( '' !== self::package_verdict( $p, (array) $state[ $p ] ) ) { // non si propone ciò che lo scarico rifiuterebbe
				continue;
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $info['basename'] ] = (object) array(
				'id'          => 'associazionesemplice.it/' . $info['slug'],
				'slug'        => $info['slug'],
				'plugin'      => $info['basename'],
				'new_version' => (string) $state[ $p ]['version'],
				'url'         => '' !== (string) ( $state[ $p ]['url'] ?? '' ) ? (string) $state[ $p ]['url'] : 'https://associazionesemplice.it',
				'package'     => self::PREFIX . $p, // l'indirizzo vero si chiede al momento dello scarico: vale pochi minuti
				'icons'       => self::icons(),
				'banners'     => self::banners(),
			);
		}
		return $transient;
	}

	/** Scheda «Visualizza i dettagli» dell'aggiornamento. */
	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || ! self::enabled() ) {
			return $res;
		}
		foreach ( self::products() as $p => $info ) {
			if ( $args->slug === $info['slug'] && ! empty( self::state()[ $p ]['version'] ) ) {
				$st       = self::state()[ $p ];
				$sections = array( 'description' => '<p>' . esc_html( $info['name'] ) . ': gestione di soci, attività e prima nota per associazioni. L\'aggiornamento si scarica dal portale di Associazione Semplice.</p>' );
				$news     = self::changelog_html( (string) ( $st['changelog'] ?? '' ) );
				if ( '' === $news ) { // il portale non l'ha mandato (sito già aggiornato, risposta vecchia): si usano le novità del plugin installato
					$news = self::changelog_html( self::local_changelog( $p ) );
				}
				if ( '' !== $news ) {
					$sections['changelog'] = $news; // «Cosa cambia» nella scheda dei dettagli
				}
				return (object) array(
					'name'          => $info['name'],
					'slug'          => $info['slug'],
					'version'       => (string) $st['version'],
					'author'        => '<a href="https://associazionesemplice.it">Associazione Semplice</a>',
					'homepage'      => 'https://associazionesemplice.it',
					'requires'      => '6.0',
					'requires_php'  => '7.4',
					'sections'      => $sections,
					'download_link' => self::PREFIX . $p,
					'icons'         => self::icons(),
					'banners'       => self::banners(),
				);
			}
		}
		return $res;
	}

	/** Scarico del pacchetto: chiede al portale un indirizzo fresco e scarica lo zip. */
	public static function download( $reply, $package, $upgrader = null ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, self::PREFIX ) ) {
			return $reply;
		}
		$p = substr( $package, strlen( self::PREFIX ) );
		$r = isset( self::products()[ $p ] ) ? self::ask( $p ) : null;
		if ( ! $r || empty( $r['update'] ) || '' === $r['package'] || ! self::package_is_ours( $r['package'] ) ) {
			return new \WP_Error( 'asem_update_unavailable', 'L\'aggiornamento non è disponibile adesso dal portale di Associazione Semplice (licenza non in regola, troppe richieste o portale non raggiungibile). Riprova più tardi o scaricalo dall\'area clienti.' );
		}
		$installed = self::products()[ $p ]['version'] ?? '';
		if ( '' === $installed || ! self::is_newer( $installed, $r['version'] ) ) { // niente passi indietro: una versione vecchia firmata non si reinstalla
			return new \WP_Error( 'asem_update_not_newer', 'La versione offerta dal portale non è più recente di quella installata.' );
		}
		$why = self::package_verdict( $p, $r );
		if ( '' !== $why ) {
			$msg = array(
				'invalid'  => 'Il pacchetto offerto dal portale non ha una firma valida: non è stato installato. Scaricalo dall\'area clienti e scrivici.',
				'min'      => 'La versione offerta dal portale è sotto la soglia minima ammessa: non è stata installata.',
				'rollback' => 'La versione offerta dal portale è più vecchia di una già vista: non è stata installata.',
				'missing_v2' => 'Il pacchetto offerto dal portale non ha la firma del nuovo formato, richiesta dopo il primo pacchetto così firmato: non è stato installato. Scaricalo dall\'area clienti e scrivici.',
				'future'   => 'La firma del pacchetto ha una data nel futuro: non è stato installato. Controlla data e ora del server.',
			);
			return new \WP_Error( 'asem_update_unsigned', Texts::t( $msg[ $why ] ?? $msg['invalid'] ) );
		}
		if ( empty( $r['v2'] ) && ! self::verify_signature( $p, $r['version'], $r['sha256'], $r['kid'], $r['signature'], self::PACKAGE_KEYS ) ) {
			return new \WP_Error( 'asem_update_unsigned', 'Il pacchetto offerto dal portale non ha una firma valida: non è stato installato. Scaricalo dall\'area clienti e scrivici.' );
		}
		if ( empty( $r['v2'] ) ) {
			self::remember( $p, $r, false ); // firma v1 verificata: si ricorda la versione, così non si torna indietro
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $r['package'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $r['sha256'], (string) hash_file( 'sha256', $file ) ) ) { // il file scaricato è proprio quello firmato
			wp_delete_file( $file );
			return new \WP_Error( 'asem_update_hash', 'Il file scaricato non corrisponde alla firma del portale: non è stato installato.' );
		}
		return $file;
	}
}

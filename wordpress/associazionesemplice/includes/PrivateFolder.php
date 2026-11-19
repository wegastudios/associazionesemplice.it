<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * La cartella dei file riservati (allegati, documenti, scansioni, certificati, copie di sicurezza e quanto altro il plugin tiene su disco).
 *
 * - Dove sta: di norma `wp-content/uploads/associazionesemplice-private`; con la costante opzionale `ASEM_PRIVATE_DIR` in wp-config.php
 *   (percorso assoluto, meglio fuori dalla parte pubblica del sito) sta lì. Se il percorso non è utilizzabile si continua con quello predefinito
 *   e l'avviso lo dice, senza fermare il sito.
 * - Come si protegge: `.htaccess` (solo Apache) e un `index.php` vuoto in ogni cartella, anche sottocartelle create dopo (contro l'elenco dei file).
 * - Come si controlla: tre file di prova, innocui e senza dati, con la forma dei file veri (senza estensione, .pdf, .zip), che si provano a scaricare dall'indirizzo
 *   web della cartella, preceduti da un file di riferimento in una cartella pubblica: se nemmeno quello si scarica l'esito è «non verificabile». Non si fa a ogni pagina:
 *   il lavoro periodico lo ripete circa ogni sette giorni e un pulsante nella pagina Copia di sicurezza lo fa a richiesta. L'esito resta salvato.
 *   Se la prova non si può fare (il sito non riesce a chiamarsi, è dietro una password del server, risponde con un errore) l'esito è «non verificabile»
 *   e non scatta nessun allarme.
 * - Come si sposta: il pulsante «Sposta i file» porta a lotti i file della cartella predefinita in quella indicata, verificando l'impronta di ciascuno.
 *   La cartella indicata entra in uso solo quando tutti i file sono stati copiati: fino ad allora il sito legge e scrive nella vecchia, che è completa.
 *   Non si cancella mai un file che è lo stesso file della copia (stessa cartella vista da percorsi diversi). Un solo spostamento alla volta.
 */
final class PrivateFolder {

	const CONSTANT = 'ASEM_PRIVATE_DIR';
	const OPT      = 'asem_private_check';
	const HOOK     = 'asem_private_folder_check';
	const RECHECK  = 7 * DAY_IN_SECONDS;
	const RETRY    = DAY_IN_SECONDS; // un esito «non verificabile» si ritenta dopo un giorno
	const BATCH    = 100;
	const MAX_SECS = 20;

	const OPT_SITE   = 'asem_private_site';   // identificativo di questo sito, scritto nel segnaposto della cartella indicata in wp-config.php
	const OPT_ACTIVE = 'asem_private_active'; // impronta del percorso di wp-config.php che è entrato in uso a spostamento finito

	/** @var array|null percorso risolto (una volta per richiesta) */
	private static $resolved = null;

	/** @var bool|null la cartella di wp-config.php è già in uso (una volta per richiesta) */
	private static $active = null;

	// ---------- Dove sta ----------

	/** Cartella predefinita, dentro la cartella dei caricamenti di WordPress. */
	public static function default_dir(): string {
		$u = wp_upload_dir( null, false );
		return rtrim( (string) $u['basedir'], '/\\' ) . '/' . Attachments::DIR_NAME;
	}

	/** Il percorso scritto in wp-config.php (stringa vuota se la costante non c'è). */
	public static function configured(): string {
		return defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) ? trim( (string) constant( self::CONSTANT ) ) : '';
	}

	/**
	 * Valuta un percorso indicato in wp-config.php: se la cartella esiste (o si riesce a crearla) e ci si può scrivere.
	 *
	 * @return array{path:string,error:string} path vuoto se non utilizzabile; error: relative, dotdot, root, overlaps, missing, not_writable
	 */
	public static function resolve( string $raw, string $default ): array {
		$err = PrivateFolderRules::path_error( $raw );
		if ( '' !== $err ) {
			return array( 'path' => '', 'error' => $err );
		}
		$path = PrivateFolderRules::normalize( $raw );
		// Dentro la cartella predefinita, o attorno ad essa, i file si mescolerebbero: non si accetta.
		if ( PrivateFolderRules::is_inside( $path, $default ) || PrivateFolderRules::is_inside( $default, $path ) ) {
			return array( 'path' => '', 'error' => 'overlaps' );
		}
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return array( 'path' => '', 'error' => 'missing' );
		}
		if ( ! wp_is_writable( $path ) ) {
			return array( 'path' => '', 'error' => 'not_writable' );
		}
		// Lo stesso posto visto da un altro percorso (collegamento simbolico, alias dell'hosting, maiuscole diverse): il confronto sul testo non basta.
		$real_path = realpath( $path );
		$real_def  = realpath( $default );
		if ( false !== $real_path && false !== $real_def && ( PrivateFolderRules::is_inside( $real_path, $real_def ) || PrivateFolderRules::is_inside( $real_def, $real_path ) || self::same_file( $path, $default ) ) ) {
			return array( 'path' => '', 'error' => 'overlaps' );
		}
		$claim = self::claim( $path ); // una cartella usata anche per altro, o da un altro sito, non si accetta: azzeramento e disinstallazione toglierebbero file non nostri
		if ( '' !== $claim ) {
			return array( 'path' => '', 'error' => $claim );
		}
		return array( 'path' => $path, 'error' => '' );
	}

	/** Identificativo di questo sito per il segnaposto delle cartelle (si crea una volta sola, con un blocco). Stringa vuota se non si riesce a leggerlo o crearlo. */
	public static function site_id(): string {
		$id = (string) get_option( self::OPT_SITE, '' );
		if ( 1 === preg_match( '/^[0-9a-f-]{32,36}$/', $id ) ) {
			return $id;
		}
		try {
			Db::with_lock(
				'asem_private_site',
				function () use ( &$id ): void {
					wp_cache_delete( self::OPT_SITE, 'options' );
					wp_cache_delete( 'alloptions', 'options' );
					wp_cache_delete( 'notoptions', 'options' );
					$id = (string) get_option( self::OPT_SITE, '' ); // riletto dopo aver preso il blocco: se un'altra richiesta l'ha creato, vale il suo
					if ( 1 !== preg_match( '/^[0-9a-f-]{32,36}$/', $id ) ) {
						$id = wp_generate_uuid4();
						update_option( self::OPT_SITE, $id );
					}
				},
				3
			);
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		return 1 === preg_match( '/^[0-9a-f-]{32,36}$/', $id ) ? $id : '';
	}

	/**
	 * La cartella indicata è dedicata a questo sito? Vuota (o con soli file del plugin) la si segna con un file segnaposto che porta l'identificativo del
	 * sito; già segnata da questo sito va bene; segnata da un altro sito, o con altri file dentro, no.
	 *
	 * @return string stringa vuota se va bene, altrimenti not_empty o foreign_site
	 */
	private static function claim( string $path ): string {
		$mine = self::site_id();
		if ( '' === $mine ) {
			return ''; // non si riesce a decidere: non si ferma il sito per questo
		}
		$ph    = $path . '/' . PrivateFolderRules::PLACEHOLDER;
		$found = is_file( $ph ) ? PrivateFolderRules::placeholder_id( (string) file_get_contents( $ph ) ) : '';
		if ( $found === $mine ) {
			return '';
		}
		$other = false;
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name && ! PrivateFolderRules::is_plugin_entry( (string) $name ) ) {
				$other = true;
				break;
			}
		}
		$verdict = PrivateFolderRules::placeholder_verdict( $other, $found, $mine );
		if ( '' === $verdict ) {
			file_put_contents( $ph, PrivateFolderRules::placeholder_text( $mine ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- segnaposto nella cartella riservata
		}
		return $verdict;
	}

	/**
	 * Due percorsi sono lo stesso file o la stessa cartella? Si guarda il percorso reale (risolti collegamenti e maiuscole) e, in subordine,
	 * il numero identificativo sul disco (un file con due nomi, una cartella montata due volte).
	 */
	public static function same_file( string $a, string $b ): bool {
		clearstatcache();
		if ( ! file_exists( $a ) || ! file_exists( $b ) ) {
			return false;
		}
		$ra = realpath( $a );
		$rb = realpath( $b );
		if ( false !== $ra && false !== $rb && PrivateFolderRules::normalize( $ra ) === PrivateFolderRules::normalize( $rb ) ) {
			return true;
		}
		$sa = stat( $a );
		$sb = stat( $b );
		return is_array( $sa ) && is_array( $sb ) && (int) $sa['ino'] > 0 && $sa['ino'] === $sb['ino'] && $sa['dev'] === $sb['dev'];
	}

	/** @return array{path:string,error:string} */
	private static function custom(): array {
		if ( null === self::$resolved ) {
			$raw = self::configured();
			if ( '' !== $raw && is_multisite() ) { // ogni sito di una rete ha la sua sottocartella: i file di un sito non si mescolano con quelli di un altro
				$raw = rtrim( $raw, '/\\' ) . '/sito-' . get_current_blog_id();
			}
			self::$resolved = '' === $raw ? array( 'path' => '', 'error' => '' ) : self::resolve( $raw, self::default_dir() );
		}
		return self::$resolved;
	}

	/** Dimentica il percorso già valutato (dopo un cambio della costante, nei test). */
	public static function reset(): void {
		self::$resolved = null;
		self::$active   = null;
	}

	/**
	 * La cartella indicata in wp-config.php è già in uso? Solo quando nella cartella predefinita non resta nessun file da spostare (e quindi tutto
	 * ciò che il sito legge, scrive e cancella sta in un posto solo): finché ce ne sono, i file vecchi restano raggiungibili dove sono. La decisione
	 * si ricorda (opzione legata al percorso): da lì in poi non si guarda più la vecchia cartella.
	 */
	private static function in_use(): bool {
		$c = self::custom();
		if ( '' === $c['path'] ) {
			return false;
		}
		if ( null === self::$active ) {
			$key = md5( PrivateFolderRules::normalize( $c['path'] ) );
			if ( get_option( self::OPT_ACTIVE ) === $key ) {
				self::$active = true;
			} elseif ( array() === self::list_default( 1 ) ) { // niente da spostare (installazione nuova o spostamento finito): si comincia subito
				update_option( self::OPT_ACTIVE, $key );
				self::$active = true;
			} else {
				self::$active = false;
			}
		}
		return self::$active;
	}

	/** La cartella in uso: quella di wp-config.php se utilizzabile e già pronta (nessun file da spostare), altrimenti quella predefinita. */
	public static function dir(): string {
		$c = self::custom();
		return '' !== $c['path'] && self::in_use() ? $c['path'] : self::default_dir();
	}

	/**
	 * Situazione della cartella.
	 *
	 * @return array{custom:bool,active:bool,target:string,dir:string,default:string,configured:string,error:string,in_web:bool}
	 *   custom: il percorso di wp-config.php è valido; active: è già in uso (nessun file da spostare); target: il percorso valido, in uso o no
	 */
	public static function status(): array {
		$c   = self::custom();
		$dir = self::dir();
		return array(
			'custom'     => '' !== $c['path'],
			'active'     => '' !== $c['path'] && self::in_use(),
			'target'     => $c['path'],
			'dir'        => $dir,
			'default'    => self::default_dir(),
			'configured' => self::configured(),
			'error'      => $c['error'],
			'in_web'     => '' !== self::web_url( $dir ),
		);
	}

	/** Frase che spiega perché il percorso di wp-config.php non va. */
	public static function error_text( string $code ): string {
		switch ( $code ) {
			case 'relative':
				return Texts::t( 'Il percorso non è assoluto: deve partire dalla radice del server (per esempio /home/utente/asem-riservata).' );
			case 'dotdot':
				return Texts::t( 'Il percorso contiene «..»: va scritto per intero.' );
			case 'root':
				return Texts::t( 'Il percorso è la radice del disco: serve una cartella dedicata.' );
			case 'overlaps':
				return Texts::t( 'Il percorso coincide con la cartella predefinita, o ne fa parte, o la contiene: serve una cartella diversa e separata.' );
			case 'missing':
				return Texts::t( 'La cartella non esiste e non si riesce a crearla.' );
			case 'not_writable':
				return Texts::t( 'La cartella esiste ma il sito non può scriverci: servono i permessi di scrittura per l\'utente del server web.' );
			case 'not_empty':
				return Texts::t( 'La cartella contiene già altri file e non è stata preparata da questo sito: serve una cartella vuota e dedicata, perché l\'azzeramento dei dati e la disinstallazione tolgono i file del plugin da quella cartella.' );
			case 'foreign_site':
				return Texts::t( 'La cartella è già usata da un altro sito (o da un\'altra installazione dello stesso sito, ad esempio quella di prova): serve una cartella diversa per ognuno.' );
		}
		return '';
	}

	/** Parti pubbliche del sito con il loro indirizzo, dalla più specifica. @return array<int,array{0:string,1:string}> */
	private static function web_pairs(): array {
		$u     = wp_upload_dir( null, false );
		$pairs = array( array( (string) $u['basedir'], (string) $u['baseurl'] ) );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$pairs[] = array( (string) WP_CONTENT_DIR, (string) content_url() );
		}
		$pairs[] = array( (string) ABSPATH, (string) site_url( '/' ) );
		$docroot = self::document_root(); // radice del server: WordPress può stare in una sottocartella e la cartella riservata sopra di essa, ma ancora dentro la radice
		$root    = 1 === preg_match( '#^(https?://[^/]+)#i', (string) home_url(), $m ) ? $m[1] . '/' : '';
		if ( '' !== $docroot && '' !== $root ) {
			$pairs[] = array( $docroot, $root );
		}
		return $pairs;
	}

	/** Radice dei documenti del server web (DOCUMENT_ROOT), stringa vuota se manca o non è un percorso assoluto utilizzabile. */
	private static function document_root(): string {
		if ( empty( $_SERVER['DOCUMENT_ROOT'] ) || ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return '';
		}
		$d = PrivateFolderRules::normalize( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
		return '' === PrivateFolderRules::path_error( $d ) ? $d : '';
	}

	/** Percorso suggerito per la cartella riservata: sopra la radice web più alta fra quelle conosciute, non sopra la sola cartella di WordPress. */
	public static function suggested_path(): string {
		$u     = wp_upload_dir( null, false );
		$roots = array( (string) ABSPATH, (string) $u['basedir'] );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = (string) WP_CONTENT_DIR;
		}
		$docroot = self::document_root();
		if ( '' !== $docroot && PrivateFolderRules::is_inside( (string) ABSPATH, $docroot ) ) {
			$roots[] = $docroot;
		}
		$s = PrivateFolderRules::suggest_path( $roots, 'asem-riservata' );
		return '' !== $s ? $s : PrivateFolderRules::normalize( dirname( untrailingslashit( str_replace( '\\', '/', ABSPATH ) ) ) ) . '/asem-riservata';
	}

	/** Indirizzo web di un percorso se sta nella parte pubblica del sito (anche se è un collegamento a una cartella altrove), altrimenti stringa vuota. */
	public static function web_url( string $path ): string {
		$pairs = self::web_pairs();
		$url   = PrivateFolderRules::map_url( $path, $pairs );
		if ( '' === $url ) { // un collegamento simbolico (o un percorso scritto in altro modo) che punta dentro la parte pubblica
			$real = realpath( $path );
			if ( false !== $real ) {
				foreach ( $pairs as $i => $pair ) {
					$rb = realpath( (string) $pair[0] );
					if ( false !== $rb ) {
						$pairs[ $i ][0] = $rb;
					}
				}
				$url = PrivateFolderRules::map_url( (string) $real, $pairs );
			}
		}
		return $url;
	}

	// ---------- Protezione ----------

	/** Scrive un `index.php` vuoto nella cartella e in ogni cartella sopra di essa, fino alla radice riservata (contro l'elenco dei file). */
	public static function protect( string $dir, string $root = '' ): void {
		$root = PrivateFolderRules::normalize( '' !== $root ? $root : self::dir() );
		$d    = PrivateFolderRules::normalize( $dir );
		for ( $i = 0; $i < 12 && PrivateFolderRules::is_inside( $d, $root ) && is_dir( $d ); $i++ ) {
			if ( ! file_exists( $d . '/index.php' ) && false === @file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				error_log( 'Associazione Semplice: impossibile creare index.php in una cartella privata (permessi).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return;
			}
			if ( $d === $root ) {
				return;
			}
			$d = PrivateFolderRules::normalize( dirname( $d ) );
		}
	}

	/** Mette l'`index.php` in tutte le sottocartelle già esistenti (cartelle create da versioni precedenti). */
	public static function protect_all(): void {
		$root = self::dir();
		if ( ! is_dir( $root ) ) {
			return;
		}
		self::protect( $root );
		try {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			$it->setMaxDepth( 4 );
			foreach ( $it as $f ) {
				if ( $f->isDir() && ! $f->isLink() ) {
					self::protect( (string) $f->getPathname() );
				}
			}
		} catch ( \Throwable $e ) {
			return; // una cartella illeggibile non ferma il lavoro
		}
	}

	// ---------- Controllo dall'esterno ----------

	/** Chiave della situazione controllata: se la cartella o l'indirizzo del sito cambiano, l'esito salvato non vale più. */
	private static function key(): string {
		return md5( PrivateFolderRules::normalize( self::dir() ) . '|' . home_url() );
	}

	/** Ultimo esito salvato (null se manca o se riguarda un'altra situazione). @return array{state:string,reason:string,code:int,at:int,key:string}|null */
	public static function last(): ?array {
		$r = get_option( self::OPT );
		if ( ! is_array( $r ) || empty( $r['state'] ) || ( $r['key'] ?? '' ) !== self::key() ) {
			return null;
		}
		return array(
			'state'  => (string) $r['state'],
			'reason' => (string) ( $r['reason'] ?? '' ),
			'code'   => (int) ( $r['code'] ?? 0 ),
			'at'     => (int) ( $r['at'] ?? 0 ),
			'key'    => (string) $r['key'],
		);
	}

	/**
	 * Fa il controllo. Prima un file di riferimento in una cartella pubblica (la cartella dei caricamenti): se nemmeno quello si scarica dalla richiesta
	 * del sito a sé stesso, l'esito è «non verificabile» e non «chiusa» (un firewall, una rete di distribuzione o un nome interno possono respingerla).
	 * Poi tre file di prova nella cartella riservata, con la forma dei file veri (senza estensione, .pdf sotto receipts/<anno>/, .zip sotto backups/),
	 * che si provano a scaricare dall'indirizzo web; se uno qualsiasi si scarica la cartella è raggiungibile. I file non contengono dati e si cancellano sempre.
	 * Non lancia mai eccezioni e non blocca: se qualcosa non si può fare l'esito è «non verificabile».
	 *
	 * @return array{state:string,reason:string,code:int,at:int,key:string}
	 */
	public static function check(): array {
		$res   = array( 'state' => PrivateFolderRules::UNKNOWN, 'reason' => 'write', 'code' => 0, 'at' => time(), 'key' => self::key() );
		$files = array();
		try {
			$dir = self::dir();
			$url = self::web_url( $dir );
			if ( '' === $url ) { // fuori dalla parte pubblica: nessun indirizzo, niente da provare
				$res['state']  = PrivateFolderRules::OUTSIDE;
				$res['reason'] = '';
			} else {
				Attachments::prepare_dir();
				foreach ( glob( $dir . '/' . PrivateFolderRules::CHECK_DIR . '/prova-*.txt' ) ?: array() as $old ) { // resti del controllo delle versioni precedenti
					if ( (int) filemtime( $old ) < time() - HOUR_IN_SECONDS ) {
						wp_delete_file( $old );
					}
				}
				$up     = wp_upload_dir( null, false );
				$ref    = self::probe( (string) $up['basedir'], (string) $up['baseurl'], 'asem-prova-ref-' . bin2hex( random_bytes( 8 ) ) . '.txt', $files );
				$probes = array();
				foreach ( PrivateFolderRules::probe_shapes( gmdate( 'Y' ) ) as $shape ) {
					$where    = '' === $shape['rel'] ? '' : '/' . $shape['rel'];
					$probes[] = self::probe( $dir . $where, rtrim( $url, '/' ) . $where, 'asem-prova-' . bin2hex( random_bytes( 8 ) ) . $shape['ext'], $files, $dir );
				}
				if ( null !== $ref && ! in_array( null, $probes, true ) ) {
					$got         = self::fetch( $ref['url'] );
					$ok          = PrivateFolderRules::reference_ok( $got[0], $got[1], $ref['token'] );
					$res['code'] = $got[0];
					if ( ! $ok[0] ) { // il riferimento non si scarica: la prova non può dire nulla
						$res['state']  = PrivateFolderRules::UNKNOWN;
						$res['reason'] = $ok[1];
					} else {
						$res['state']  = PrivateFolderRules::CLOSED;
						$res['reason'] = '';
						foreach ( $probes as $p ) {
							$got         = self::fetch( $p['url'] );
							$class       = PrivateFolderRules::classify( $got[0], $got[1], $p['token'] );
							$res['code'] = $got[0];
							if ( PrivateFolderRules::CLOSED !== $class[0] ) { // si scarica (allarme) o non si può dire (nessun allarme): ci si ferma
								$res['state']  = $class[0];
								$res['reason'] = $class[1];
								break;
							}
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			$res['state']  = PrivateFolderRules::UNKNOWN;
			$res['reason'] = 'write';
		}
		foreach ( $files as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
		update_option( self::OPT, $res, false );
		return $res;
	}

	/**
	 * Mette un file di prova (solo un testo casuale, nessun dato) in una cartella e ne ricorda il percorso per cancellarlo.
	 *
	 * @param string[] $files elenco dei file creati, da cancellare a fine controllo
	 * @param string   $root  radice riservata (per proteggere le sottocartelle create); vuota per una cartella pubblica
	 * @return array{url:string,token:string}|null null se non si riesce a scriverlo
	 */
	private static function probe( string $dir, string $dir_url, string $name, array &$files, string $root = '' ): ?array {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		if ( '' !== $root ) {
			self::protect( $dir, $root );
		}
		foreach ( glob( $dir . '/asem-prova-*' ) ?: array() as $old ) { // resti di un controllo interrotto
			if ( is_file( $old ) && (int) filemtime( $old ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		$token = 'asem-controllo-' . bin2hex( random_bytes( 16 ) );
		$path  = $dir . '/' . $name;
		if ( false === @file_put_contents( $path, $token . "\n" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		$files[] = $path;
		return array( 'url' => rtrim( $dir_url, '/' ) . '/' . $name, 'token' => $token );
	}

	/**
	 * Scarica un indirizzo del sito stesso: breve, senza seguire rimandi, senza cookie né password dell'utente.
	 *
	 * @return array{0:int,1:string} [codice (0 se la richiesta non è riuscita), inizio del corpo]
	 */
	private static function fetch( string $url ): array {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WordPress per le chiamate del sito a sé stesso
				'user-agent'  => 'AssociazioneSemplice-controllo',
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		return array( is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ), is_wp_error( $resp ) ? '' : substr( (string) wp_remote_retrieve_body( $resp ), 0, 4096 ) );
	}

	// ---------- File temporanei ----------

	/** Sottocartella (dentro la cartella riservata) dei file temporanei che contengono dati in chiaro: PDF decifrati per un allegato o per l'archivio ZIP. */
	const TEMP_DIR = 'tmp';

	/** Dopo quanti secondi un temporaneo rimasto sul disco si considera un resto da togliere. */
	const TEMP_MAX_AGE = 3600;

	/** La cartella dei temporanei, creata e protetta; stringa vuota se non si può (mai la cartella temporanea del server, che può stare nella parte pubblica). */
	public static function temp_dir(): string {
		$dir = self::dir() . '/' . self::TEMP_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::protect( $dir );
		return is_dir( $dir ) ? $dir : '';
	}

	/** Crea un file temporaneo vuoto, leggibile solo dal server, nella cartella dei temporanei. Stringa vuota se non si può. */
	public static function temp_file( string $prefix ): string {
		$dir = self::temp_dir();
		if ( '' === $dir ) {
			return '';
		}
		$path = $dir . '/' . preg_replace( '/[^a-z0-9-]/i', '', $prefix ) . '-' . bin2hex( random_bytes( 8 ) );
		if ( false === @file_put_contents( $path, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- file temporaneo riservato
			return '';
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return $path;
	}

	/** Toglie i temporanei (file o cartelle) più vecchi di $age secondi: resti di una richiesta interrotta. @return int quanti ne ha tolti */
	public static function temp_clean( int $age = self::TEMP_MAX_AGE ): int {
		$dir = self::dir() . '/' . self::TEMP_DIR;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name || 'index.php' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( time() - (int) filemtime( $path ) <= $age ) {
				continue;
			}
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				foreach ( (array) scandir( $path ) as $in ) {
					if ( '.' !== $in && '..' !== $in && is_file( $path . '/' . $in ) ) {
						wp_delete_file( $path . '/' . $in );
					}
				}
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $path );
			}
			if ( ! file_exists( $path ) ) {
				++$n;
			}
		}
		return $n;
	}

	/** Lavoro periodico (una volta al giorno): protegge le sottocartelle e rifà il controllo se l'ultimo è vecchio. */
	public static function cron(): void {
		self::temp_clean();
		self::protect_all();
		$r = self::last();
		if ( null === $r || time() - $r['at'] >= ( PrivateFolderRules::UNKNOWN === $r['state'] ? self::RETRY : self::RECHECK ) ) {
			self::check();
		}
	}

	/** Frase dell'esito di un controllo (senza la data). */
	public static function outcome_text( array $r ): string {
		switch ( (string) $r['state'] ) {
			case PrivateFolderRules::EXPOSED:
				return Texts::t( 'la cartella è raggiungibile da internet.' );
			case PrivateFolderRules::CLOSED:
				return Texts::t( 'tre file di prova, fatti come i file veri, messi nella cartella non si scaricano dall\'indirizzo web del sito. Il controllo parte dal sito stesso: per la certezza chiedi conferma a chi gestisce il server.' );
			case PrivateFolderRules::OUTSIDE:
				return Texts::t( 'la cartella non risulta dentro la parte pubblica del sito, quindi il plugin non ha un indirizzo web da provare. Se il server la rende comunque raggiungibile (ad esempio perché sta dentro la cartella pubblica di un altro dominio dello stesso hosting), chi lo gestisce può controllare a mano che l\'indirizzo di un file della cartella non si apra.' );
		}
		switch ( (string) ( $r['reason'] ?? '' ) ) {
			case 'reference':
				return Texts::t( 'non verificabile: nemmeno un file messo in una cartella pubblica del sito si scarica dalla richiesta del sito a sé stesso (può dipendere da un firewall, da una rete di distribuzione dei contenuti o da un indirizzo interno). Nessun allarme: chi gestisce il server può controllare a mano che l\'indirizzo di un file della cartella non si apra.' );
			case 'auth':
				return Texts::t( 'non verificabile: il sito è protetto da una password del server e la prova non può passare. Nessun allarme.' );
			case 'redirect':
				return Texts::t( 'non verificabile: la prova è stata mandata a un\'altra pagina (manutenzione, accesso o spostamento del sito). Nessun allarme.' );
			case 'server':
				return Texts::t( 'non verificabile: il server ha risposto con un errore. Nessun allarme: il controllo si ripete da solo.' );
			case 'write':
				return Texts::t( 'non verificabile: nella cartella non si riesce a creare il file di prova. Nessun allarme.' );
		}
		return Texts::t( 'non verificabile: il sito non riesce a collegarsi a sé stesso per fare la prova. Nessun allarme: chi gestisce il server può controllare a mano che l\'indirizzo di un file della cartella non si apra.' );
	}

	// ---------- Avviso all'amministratore ----------

	/**
	 * Avvisi chiudibili per chi amministra: percorso di wp-config.php non utilizzabile, cartella raggiungibile da internet.
	 * Legge solo l'esito già salvato: nessuna richiesta di rete a ogni pagina.
	 */
	public static function alerts_html(): string {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return '';
		}
		$url = Admin\Ui::url( 'asem-backup' ) . '#asem-private-folder';
		$st  = self::status();
		$out = '';
		if ( '' !== $st['error'] ) {
			$out .= Admin\Dismiss::html(
				'private-folder-config-' . $st['error'],
				'error',
				'<strong>' . esc_html( Texts::t( 'La cartella dei file riservati indicata in wp-config.php non è utilizzabile.' ) ) . '</strong> '
					. esc_html( self::error_text( $st['error'] ) ) . ' '
					. esc_html( Texts::t( 'Finché non viene sistemata, i nuovi file vengono salvati nella cartella predefinita dentro wp-content/uploads.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi cosa fare' ) ) . '</a>.',
				7
			);
		}
		if ( $st['custom'] ) { // avviso fisso (non si chiude) finché nella vecchia cartella restano file da spostare
			$left = count( self::pending( 5000 ) );
			if ( $left > 0 ) {
				$out .= '<div class="notice notice-warning inline asem-private-move"><p><strong>' . esc_html( Texts::n( (int) $left, 'C\'è ancora un file da spostare nella cartella indicata in wp-config.php.', 'Ci sono ancora {n} file da spostare nella cartella indicata in wp-config.php.', array( 'n' => $left >= 5000 ? '5000+' : $left ) ) ) . '</strong> '
					. esc_html( Texts::t( 'Finché non sono stati spostati la nuova cartella non entra in uso, e la cifratura automatica dei certificati e il rifacimento delle ricevute restano fermi.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Sposta i file' ) ) . '</a>.</p></div>';
			}
		}
		$last = self::last();
		if ( null !== $last && PrivateFolderRules::EXPOSED === $last['state'] ) {
			$out .= Admin\Dismiss::html(
				'private-folder-exposed',
				'error',
				'<strong>' . esc_html( Texts::t( 'La cartella dei file riservati è raggiungibile da internet.' ) ) . '</strong> '
					. esc_html( Texts::t( 'Ricevute, allegati, scansioni, certificati medici e copie di sicurezza stanno in una cartella che dal controllo risulta aperta: chi conosce l\'indirizzo esatto di un file può leggerlo senza accedere al sito. I nomi dei file non si indovinano, ma un indirizzo finito per sbaglio in una email, in una cronologia o in un registro del server basterebbe per aprire il documento. Va chiesto a chi gestisce il server o l\'hosting del sito di bloccare l\'accesso diretto a quella cartella oppure di spostarla fuori dalla parte pubblica del sito.' ) )
					. ' <a href="' . esc_url( $url ) . '">' . esc_html( Texts::t( 'Vedi cosa fare' ) ) . '</a>.',
				7
			);
		}
		return $out;
	}

	// ---------- Spostamento dei file ----------

	/**
	 * File che stanno nella cartella predefinita (percorsi relativi). Non conta i file di protezione, quelli del controllo e i resti di copie
	 * interrotte (nome con «.part-»).
	 *
	 * @return string[]
	 */
	private static function list_default( int $limit ): array {
		$base = PrivateFolderRules::normalize( self::default_dir() );
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$out = array();
		try {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->isLink() ) {
					continue;
				}
				$rel = ltrim( substr( PrivateFolderRules::normalize( (string) $f->getPathname() ), strlen( $base ) ), '/' );
				if ( '.htaccess' === basename( $rel ) || 'index.php' === basename( $rel ) || false !== strpos( basename( $rel ), '.part-' ) || 0 === strpos( basename( $rel ), 'asem-prova-' ) || 0 === strpos( $rel, PrivateFolderRules::CHECK_DIR . '/' ) ) {
					continue;
				}
				$out[] = $rel;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * File ancora nella cartella predefinita che vanno portati in quella di wp-config.php (percorsi relativi). Vuoto se la cartella indicata
	 * non è utilizzabile. Non conta i file di protezione né quelli del controllo.
	 *
	 * @return string[]
	 */
	public static function pending( int $limit = 5000 ): array {
		return '' === self::custom()['path'] ? array() : self::list_default( $limit );
	}

	/**
	 * File degli allegati (nome casuale al primo livello) rimasti nella cartella predefinita quando quella nuova è già in uso: la copia di sicurezza
	 * li prende da qui, perché non vadano persi. Prima che la nuova sia in uso la predefinita è la cartella in uso e non serve.
	 *
	 * @return string[] percorsi assoluti
	 */
	public static function left_behind(): array {
		if ( '' === self::custom()['path'] || ! self::in_use() ) {
			return array();
		}
		$out = array();
		foreach ( glob( self::default_dir() . '/*' ) ?: array() as $f ) {
			if ( is_file( $f ) && preg_match( '/^[0-9a-f]{32}$/', basename( $f ) ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/** C'è una cartella indicata in wp-config.php e nella predefinita resta almeno un file da spostare? (Si ferma al primo.) */
	public static function move_incomplete(): bool {
		return '' !== self::custom()['path'] && array() !== self::list_default( 1 );
	}

	/**
	 * Porta un lotto di file dalla cartella predefinita a quella indicata in wp-config.php, in due tempi:
	 * 1. finché la nuova cartella non è in uso, ogni file si COPIA (su un file temporaneo con nome «….part-…», controllo dell'impronta SHA-256 e solo poi
	 *    rinomina) e l'originale resta dov'è, così il sito continua a leggere e scrivere nella vecchia cartella, che è completa;
	 * 2. quando sono stati copiati tutti (nessun errore, nessun conflitto) la nuova cartella entra in uso e i file vecchi si cancellano uno alla volta,
	 *    solo se la copia è identica.
	 * Non si cancella mai un file che è lo stesso file della copia (collegamento simbolico, cartella montata due volte) e un file già presente nella
	 * nuova cartella con contenuto diverso resta dov'è, segnalato come conflitto. Un solo spostamento alla volta.
	 *
	 * @return array{moved:int,copied:int,failed:int,conflict:int,same:int,same_dir:bool,switched:bool,busy:bool,left:int}
	 */
	public static function move_batch( int $max = self::BATCH ): array {
		$res = array( 'moved' => 0, 'copied' => 0, 'failed' => 0, 'conflict' => 0, 'same' => 0, 'same_dir' => false, 'switched' => false, 'busy' => false, 'left' => 0 );
		$c   = self::custom();
		if ( '' === $c['path'] ) {
			return $res;
		}
		try {
			Db::with_lock(
				'asem_private_move',
				function () use ( &$res, $max, $c ): void {
					self::move_locked( $res, $max, $c['path'] );
				},
				0
			);
		} catch ( \InvalidArgumentException $e ) {
			$res['busy'] = true; // un altro spostamento è in corso
		}
		return $res;
	}

	/** @param array<string,mixed> $res risultato da riempire */
	private static function move_locked( array &$res, int $max, string $target ): void {
		$src = PrivateFolderRules::normalize( self::default_dir() );
		$dst = PrivateFolderRules::normalize( $target );
		if ( is_dir( $src ) && self::same_file( $src, $dst ) ) { // la stessa cartella vista da un altro percorso: nulla da spostare, nulla da cancellare
			$res['same']     = 1;
			$res['same_dir'] = true;
			$res['left']     = count( self::list_default( 5000 ) );
			return;
		}
		try {
			Attachments::prepare_dir_at( $dst );
		} catch ( \Throwable $e ) {
			$res['failed']++;
			$res['left'] = count( self::list_default( 5000 ) );
			return;
		}
		$start  = time();
		$active = self::in_use();
		$clean  = self::move_pass( $res, $src, $dst, $max, $start, $active );
		if ( ! $active && $clean && 0 === $res['failed'] && 0 === $res['conflict'] ) { // tutto copiato e controllato: la nuova cartella entra in uso
			update_option( self::OPT_ACTIVE, md5( $dst ) );
			self::$active    = true;
			$res['switched'] = true;
			Audit::log( 'private_folder.activated', 'folder', 0, array( 'copied' => $res['copied'] ) );
			self::move_pass( $res, $src, $dst, $max, $start, true );
		}
		$res['left'] = count( self::list_default( 5000 ) );
	}

	/**
	 * Un giro sui file della cartella predefinita: copia quelli che mancano nella nuova e, a nuova cartella in uso, cancella gli originali la cui copia è identica.
	 *
	 * @param array<string,mixed> $res
	 * @return bool true se si sono esaminati tutti i file (nessun taglio per numero, tempo o lunghezza dell'elenco)
	 */
	private static function move_pass( array &$res, string $src, string $dst, int $max, int $start, bool $active ): bool {
		clearstatcache();
		$list     = self::list_default( 5000 );
		$complete = count( $list ) < 5000;
		$done     = 0; // i file in conflitto e quelli già coincidenti non contano nel lotto
		foreach ( $list as $rel ) {
			if ( $done >= $max || time() - $start > self::MAX_SECS ) {
				return false;
			}
			$from = $src . '/' . $rel;
			$to   = $dst . '/' . $rel;
			if ( ! is_file( $from ) ) {
				continue; // sparito nel frattempo
			}
			if ( file_exists( $to ) ) {
				if ( self::same_file( $from, $to ) ) { // è lo stesso file visto da due percorsi: cancellare l'originale cancellerebbe anche la copia
					$res['same']++;
					continue;
				}
				$hash = (string) hash_file( 'sha256', $from );
				if ( '' !== $hash && hash_equals( $hash, (string) hash_file( 'sha256', $to ) ) ) {
					$done++;
					if ( $active ) {
						wp_delete_file( $from );
						$res['moved']++;
					}
					continue;
				}
				// Con la nuova cartella in uso un file diverso è un conflitto. Prima, la vecchia cartella è quella buona: se l'originale è cambiato dopo la copia, si ricopia.
				if ( $active || '' === $hash || (int) filemtime( $from ) <= (int) filemtime( $to ) ) {
					$res['conflict']++;
					$complete = false;
					continue;
				}
			}
			$done++;
			$hash = (string) hash_file( 'sha256', $from );
			if ( '' === $hash || ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) ) {
				$res['failed']++;
				$complete = false;
				continue;
			}
			self::protect( dirname( $to ), $dst );
			if ( ! self::copy_checked( $from, $to, $hash ) ) {
				$res['failed']++;
				$complete = false;
				continue;
			}
			if ( $active ) {
				wp_delete_file( $from );
				$res['moved']++;
			} else {
				$res['copied']++;
			}
		}
		return $complete;
	}

	/** Copia su un file temporaneo accanto alla destinazione, ne controlla l'impronta e solo allora lo rinomina: la destinazione non è mai a metà. */
	private static function copy_checked( string $from, string $to, string $hash ): bool {
		$tmp = $to . '.part-' . bin2hex( random_bytes( 4 ) );
		if ( ! copy( $from, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- spostamento di file privati tra due cartelle del sito
			wp_delete_file( $tmp );
			return false;
		}
		if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $tmp ) ) ) { // la copia non coincide: si butta e l'originale resta
			wp_delete_file( $tmp );
			return false;
		}
		chmod( $tmp, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		if ( ! rename( $tmp, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- spostamento di file privati tra due cartelle del sito
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}
}

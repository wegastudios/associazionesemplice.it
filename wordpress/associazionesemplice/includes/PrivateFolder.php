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
 * - Come si controlla: un file di prova, innocuo e senza dati, che si prova a scaricare dall'indirizzo web della cartella. Non si fa a ogni pagina:
 *   il lavoro periodico lo ripete circa ogni sette giorni e un pulsante nella pagina Copia di sicurezza lo fa a richiesta. L'esito resta salvato.
 *   Se la prova non si può fare (il sito non riesce a chiamarsi, è dietro una password del server, risponde con un errore) l'esito è «non verificabile»
 *   e non scatta nessun allarme.
 * - Come si sposta: il pulsante «Sposta i file» porta a lotti i file della cartella predefinita in quella indicata, verificando l'impronta di ciascuno.
 */
final class PrivateFolder {

	const CONSTANT = 'ASEM_PRIVATE_DIR';
	const OPT      = 'asem_private_check';
	const HOOK     = 'asem_private_folder_check';
	const RECHECK  = 7 * DAY_IN_SECONDS;
	const RETRY    = DAY_IN_SECONDS; // un esito «non verificabile» si ritenta dopo un giorno
	const BATCH    = 100;
	const MAX_SECS = 20;

	/** @var array|null percorso risolto (una volta per richiesta) */
	private static $resolved = null;

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
		if ( ! is_writable( $path ) ) {
			return array( 'path' => '', 'error' => 'not_writable' );
		}
		return array( 'path' => $path, 'error' => '' );
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
	}

	/** La cartella in uso: quella di wp-config.php se utilizzabile, altrimenti quella predefinita. */
	public static function dir(): string {
		$c = self::custom();
		return '' !== $c['path'] ? $c['path'] : self::default_dir();
	}

	/**
	 * Situazione della cartella.
	 *
	 * @return array{custom:bool,dir:string,default:string,configured:string,error:string,in_web:bool}
	 */
	public static function status(): array {
		$c   = self::custom();
		$dir = self::dir();
		return array(
			'custom'     => '' !== $c['path'],
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
		return $pairs;
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
	public static function protect( string $dir ): void {
		$root = PrivateFolderRules::normalize( self::dir() );
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
	 * Fa il controllo: crea un file di prova (senza dati) nella cartella riservata, prova a scaricarlo dall'indirizzo web e lo cancella.
	 * Non lancia mai eccezioni e non blocca: se qualcosa non si può fare l'esito è «non verificabile».
	 *
	 * @return array{state:string,reason:string,code:int,at:int,key:string}
	 */
	public static function check(): array {
		$res   = array( 'state' => PrivateFolderRules::UNKNOWN, 'reason' => 'write', 'code' => 0, 'at' => time(), 'key' => self::key() );
		$probe = '';
		try {
			$dir = self::dir();
			$url = self::web_url( $dir );
			if ( '' === $url ) { // fuori dalla parte pubblica: nessun indirizzo, niente da provare
				$res['state']  = PrivateFolderRules::OUTSIDE;
				$res['reason'] = '';
			} else {
				Attachments::prepare_dir();
				$sub = $dir . '/' . PrivateFolderRules::CHECK_DIR;
				if ( is_dir( $sub ) || wp_mkdir_p( $sub ) ) {
					self::protect( $sub );
					foreach ( glob( $sub . '/prova-*.txt' ) ?: array() as $old ) { // resti di un controllo interrotto
						if ( (int) filemtime( $old ) < time() - HOUR_IN_SECONDS ) {
							wp_delete_file( $old );
						}
					}
					$token = 'asem-controllo-' . bin2hex( random_bytes( 16 ) );
					$name  = 'prova-' . bin2hex( random_bytes( 8 ) ) . '.txt';
					$probe = $sub . '/' . $name;
					if ( false !== @file_put_contents( $probe, $token . "\n" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						$resp = wp_remote_get(
							trailingslashit( $url ) . PrivateFolderRules::CHECK_DIR . '/' . $name,
							array(
								'timeout'     => 5,
								'redirection' => 0,
								'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // come le chiamate del sito a sé stesso
								'user-agent'  => 'AssociazioneSemplice-controllo',
								'headers'     => array( 'Cache-Control' => 'no-cache' ),
							)
						);
						$code  = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
						$body  = is_wp_error( $resp ) ? '' : substr( (string) wp_remote_retrieve_body( $resp ), 0, 4096 );
						$class = PrivateFolderRules::classify( $code, $body, $token );
						$res['state']  = $class[0];
						$res['reason'] = $class[1];
						$res['code']   = $code;
					}
				}
			}
		} catch ( \Throwable $e ) {
			$res['state']  = PrivateFolderRules::UNKNOWN;
			$res['reason'] = 'write';
		}
		if ( '' !== $probe && file_exists( $probe ) ) {
			wp_delete_file( $probe );
		}
		update_option( self::OPT, $res, false );
		return $res;
	}

	/** Lavoro periodico (una volta al giorno): protegge le sottocartelle e rifà il controllo se l'ultimo è vecchio. */
	public static function cron(): void {
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
				return Texts::t( 'un file di prova messo nella cartella non si scarica dall\'esterno: a posto.' );
			case PrivateFolderRules::OUTSIDE:
				return Texts::t( 'la cartella sta fuori dalla parte pubblica del sito e non ha un indirizzo web: non serve altro.' );
		}
		switch ( (string) ( $r['reason'] ?? '' ) ) {
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
	 * File ancora nella cartella predefinita che andrebbero portati in quella di wp-config.php (percorsi relativi). Vuoto se la cartella
	 * indicata non è in uso. Non conta i file di protezione né quelli del controllo.
	 *
	 * @return string[]
	 */
	public static function pending( int $limit = 5000 ): array {
		$st = self::status();
		if ( ! $st['custom'] || ! is_dir( $st['default'] ) ) {
			return array();
		}
		$base = PrivateFolderRules::normalize( $st['default'] );
		$out  = array();
		try {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->isLink() ) {
					continue;
				}
				$rel = ltrim( substr( PrivateFolderRules::normalize( (string) $f->getPathname() ), strlen( $base ) ), '/' );
				if ( '.htaccess' === basename( $rel ) || 'index.php' === basename( $rel ) || 0 === strpos( $rel, PrivateFolderRules::CHECK_DIR . '/' ) ) {
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
	 * Porta un lotto di file dalla cartella predefinita a quella indicata: copia, controlla l'impronta SHA-256 e solo dopo cancella l'originale.
	 * Un file già presente nella nuova cartella con lo stesso contenuto si considera spostato; con contenuto diverso resta dov'è.
	 *
	 * @return array{moved:int,failed:int,conflict:int,left:int}
	 */
	public static function move_batch( int $max = self::BATCH ): array {
		$res = array( 'moved' => 0, 'failed' => 0, 'conflict' => 0, 'left' => 0 );
		$st  = self::status();
		if ( ! $st['custom'] ) {
			return $res;
		}
		$src   = PrivateFolderRules::normalize( $st['default'] );
		$dst   = PrivateFolderRules::normalize( $st['dir'] );
		$start = time();
		$done  = 0; // i file in conflitto restano dove sono e non contano nel lotto
		foreach ( self::pending( 5000 ) as $rel ) {
			if ( $done >= $max || time() - $start > self::MAX_SECS ) {
				break;
			}
			$from = $src . '/' . $rel;
			$to   = $dst . '/' . $rel;
			$hash = (string) hash_file( 'sha256', $from );
			if ( file_exists( $to ) ) {
				if ( '' !== $hash && hash_equals( $hash, (string) hash_file( 'sha256', $to ) ) ) {
					wp_delete_file( $from );
					$res['moved']++;
					$done++;
				} else {
					$res['conflict']++;
				}
				continue;
			}
			$done++;
			if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
				$res['failed']++;
				continue;
			}
			self::protect( dirname( $to ) );
			if ( '' === $hash || ! copy( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- spostamento di file privati tra due cartelle del sito
				$res['failed']++;
				continue;
			}
			if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $to ) ) ) { // la copia non coincide: si butta e l'originale resta
				wp_delete_file( $to );
				$res['failed']++;
				continue;
			}
			chmod( $to, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
			wp_delete_file( $from );
			$res['moved']++;
		}
		$res['left'] = count( self::pending( 5000 ) );
		return $res;
	}
}

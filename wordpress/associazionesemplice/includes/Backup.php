<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Copia di sicurezza: un file ZIP con tutti i dati del plugin (una tabella per file, una riga per record in formato JSON), le impostazioni
 * (senza le chiavi segrete dei pagamenti, che restano cifrate sul sito) e, se vuoi, gli allegati. Si scarica con un clic e si può
 * ripristinare sullo stesso sito: i dati attuali vengono sostituiti da quelli della copia, dopo averne fatta una di sicurezza.
 */
final class Backup {

	const OPT_LAST   = 'asem_backup_last';
	const OPT_NAMES  = array( 'asem_texts', 'asem_access_requests', 'asem_convention_categories' );
	const KEEP       = 3;
	const CHUNK      = 1000;
	const MAX_ENTRY  = 1073741824; // 1 GB per file dentro lo zip

	/** Quanti campi di testo dell'ultima copia sono usciti in chiaro perché sul sito non si potevano cifrare (si mostra nella pagina «Copia di sicurezza»). */
	const OPT_PLAIN = 'asem_backup_plain';

	/** Le frasi da scrivere per ripristinare: una per le copie di questo sito, una per quelle fatte altrove. */
	const RESTORE_PHRASE       = 'RIPRISTINA';
	const RESTORE_PHRASE_OTHER = 'RIPRISTINA DA ALTRO SITO';

	/** Tabelle che il ripristino non riscrive: il registro azioni è la memoria di ciò che è successo (anche del ripristino stesso); sessioni, codici di accesso e notifiche push dei soci non tornano (le password sì, vedi {@see self::MEMBER_ACCESS}). */
	const NOT_RESTORED = array( 'asem_audit_log', 'asem_push_subs', 'asem_member_sessions', 'asem_member_challenges' );

	/** Tabelle con le sessioni, i codici di accesso e le notifiche push dei soci: dopo un ripristino si svuotano (le password invece tornano, e ogni socio ne sceglie una nuova al primo accesso). */
	const MEMBER_ACCESS = array( 'asem_member_sessions', 'asem_member_challenges', 'asem_push_subs' );

	/** Solo per le prove: tetti più bassi di quelli predefiniti (vedi {@see BackupSeal::limits()}). @var array<string,int> */
	public static $test_limits = array();

	public static function register(): void {
		add_action( 'admin_post_asem_backup', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_asem_backup_saved', array( __CLASS__, 'handle_saved' ) );
	}

	// ---------- Cartella delle copie di sicurezza ----------

	public static function dir(): string {
		return rtrim( Attachments::dir(), '/\\' ) . '/backups';
	}

	private static function ensure_dir(): string {
		Attachments::prepare_dir();
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Impossibile creare la cartella delle copie di sicurezza: controlla i permessi di wp-content/uploads.' );
		}
		PrivateFolder::protect( $dir ); // anche la sottocartella delle copie: niente elenco dei file
		return $dir;
	}

	/** Cancella le copie salvate sul sito da più di `backup_hours` ore (contengono dati personali); quella fatta prima di riallineare le tessere resta 7 giorni. @return int copie cancellate */
	public static function purge_old(): int {
		$limit = time() - max( 1, (int) Limits::get( 'backup_hours' ) ) * HOUR_IN_SECONDS;
		$long  = time() - 7 * DAY_IN_SECONDS;
		$n     = 0;
		$sets  = array(
			array( glob( self::dir() . '/prima-del-ripristino-*.zip' ) ?: array(), $limit ),
			array( glob( self::dir() . '/prima-del-riallineamento-delle-tessere-*.zip' ) ?: array(), $long ),
		);
		foreach ( $sets as $set ) {
			foreach ( $set[0] as $f ) {
				if ( (int) filemtime( $f ) < $set[1] ) {
					wp_delete_file( $f );
					$n++;
				}
			}
		}
		return $n;
	}

	/** Copie salvate sul sito (quelle fatte prima di un ripristino), dalla più recente. @return array[] name, size, time */
	public static function saved(): array {
		self::purge_old();
		$out = array();
		foreach ( glob( self::dir() . '/*.zip' ) ?: array() as $f ) {
			if ( preg_match( '/^prima-del-ripristino-(\d{8}-\d{6})\.zip$/', basename( $f ), $m ) ) { // copie fatte da versioni precedenti, con il nome prevedibile
				$new = dirname( $f ) . '/prima-del-ripristino-' . $m[1] . '-' . bin2hex( random_bytes( 8 ) ) . '.zip';
				if ( ! @rename( $f, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- lettura/scrittura in streaming di file grandi
					wp_delete_file( $f ); // un nome prevedibile non deve restare nella cartella: se non si può rinominare, la copia si cancella
					continue;
				}
				$f = $new;
			}
			$out[] = array( 'name' => basename( $f ), 'size' => (int) filesize( $f ), 'time' => (int) filemtime( $f ) );
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		return $out;
	}

	public static function last(): ?int {
		$v = (int) get_option( self::OPT_LAST, 0 );
		return $v > 0 ? $v : null;
	}

	// ---------- Contenuto ----------

	/** Nomi delle tabelle del plugin (senza il prefisso del database). @return string[] */
	public static function tables(): array {
		$db     = Db::db();
		$prefix = $db->prefix . 'asem_';
		$out    = array();
		foreach ( $db->get_col( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $prefix ) . '%' ) ) ?: array() as $t ) {
			$name = substr( (string) $t, strlen( $db->prefix ) );
			if ( in_array( $name, array( 'asem_member_sessions', 'asem_member_challenges' ), true ) ) {
				continue; // sessioni e codici di accesso non vanno nelle copie: un ripristino non deve riaprire accessi già chiusi
			}
			$out[] = $name;
		}
		sort( $out );
		return $out;
	}

	private static function settings_payload(): array {
		$s = Settings::all();
		foreach ( Settings::secret_keys() as $k ) {
			unset( $s[ $k ] ); // le chiavi dei gateway restano sul sito
		}
		unset( $s['license_key'] ); // la chiave di licenza è di questo sito: una copia vecchia non la sovrascrive
		$s = Secrets::strip_encrypted( $s ); // anche con il Pro spento (le sue chiavi non sono più elencate) nella copia non finisce nessun valore cifrato
		$opts = array();
		foreach ( self::OPT_NAMES as $n ) {
			$opts[ $n ] = get_option( $n, null );
		}
		return array( 'settings' => $s, 'options' => $opts );
	}

	/**
	 * Crea la copia e ne restituisce il percorso (file temporaneo o, con $to, il file indicato).
	 * I file riservati restano cifrati nella copia. Le loro chiavi vanno in `chiavi.json`, avvolte con la chiave principale di questo sito, con la frase
	 * in uso e con il codice di licenza (se c'è): così la copia si ripristina anche su un altro sito.
	 *
	 * @throws \RuntimeException
	 */
	public static function export( bool $with_files = false, ?string $to = null ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'Questo server non può creare file ZIP (manca l\'estensione zip di PHP).' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- lettura/scrittura in streaming di file grandi
		}
		$db    = Db::db();
		$path  = $to ?: wp_tempnam( 'asem-copia' );
		$zip   = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Impossibile creare il file della copia.' );
		}
		$tmp    = array();
		$counts = array();
		$hashes = array(); // impronta SHA-256 di ogni voce dello ZIP (tranne il manifest): finisce nel manifest, che poi si firma
		$plain  = array(); // «tabella.colonna» => quanti campi sono usciti in chiaro perché sul sito non si potevano cifrare
		$fields = array(); // colonne di testo che nel database stanno cifrate: nella copia escono cifrate; se non si possono cifrare escono com'è, e si contano
		foreach ( PrivateFiles::sources() as $src ) {
			foreach ( $src['fields'] as $col ) {
				$fields[ 'asem_' . $src['table'] ][] = (string) $col;
			}
		}
		foreach ( self::tables() as $name ) {
			$tbl = $db->prefix . $name;
			$f   = wp_tempnam( 'asem-t' );
			$tmp[] = $f;
			$h   = fopen( $f, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lettura/scrittura in streaming di file grandi
			$n   = 0;
			for ( $off = 0; ; $off += self::CHUNK ) {
				$rows = $db->get_results( $db->prepare( "SELECT * FROM `$tbl` ORDER BY 1 LIMIT %d OFFSET %d", self::CHUNK, $off ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $r ) {
					foreach ( $fields[ $name ] ?? array() as $col ) { // un campo ancora in chiaro (non ancora cifrato sul sito) si cifra ora
						$v = (string) ( $r[ $col ] ?? '' );
						if ( '' !== $v && ! FileCrypt::is_field( $v ) ) {
							$e = PrivateFiles::field_encrypt( substr( $name, 5 ), $col, (int) $r['id'], $v );
							if ( $e !== $v ) {
								$r[ $col ] = $e;
							} else { // non si può cifrare (senza sodium, o con la chiave che non si apre): il valore NON sparisce dalla copia, esce com'è e si conta
								$plain[ $name . '.' . $col ] = ( $plain[ $name . '.' . $col ] ?? 0 ) + 1;
							}
						}
					}
					fwrite( $h, wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- lettura/scrittura in streaming di file grandi
					$n++;
				}
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
			$counts[ $name ] = $n;
			$hashes[ 'tabelle/' . $name . '.jsonl' ] = (string) hash_file( 'sha256', $f );
			$zip->addFile( $f, 'tabelle/' . $name . '.jsonl' );
		}
		$plain_files = 0; // allegati che entrano nella copia in chiaro perché non erano ancora cifrati (o salvati senza sodium)
		$files = array();
		if ( $with_files ) {
			foreach ( glob( Attachments::dir() . '/*' ) ?: array() as $f ) {
				if ( is_file( $f ) && preg_match( '/^[0-9a-f]{32}$/', basename( $f ) ) ) {
					$hashes[ 'allegati/' . basename( $f ) ] = (string) hash_file( 'sha256', $f );
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[] = basename( $f ); $plain_files += FileCrypt::is_encrypted_file( $f ) ? 0 : 1;
				}
			}
			foreach ( PrivateFolder::left_behind() as $f ) { // file rimasti nella vecchia cartella a spostamento non finito: vanno nella copia anche loro
				if ( ! in_array( basename( $f ), $files, true ) ) {
					$hashes[ 'allegati/' . basename( $f ) ] = (string) hash_file( 'sha256', $f );
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[] = basename( $f ); $plain_files += FileCrypt::is_encrypted_file( $f ) ? 0 : 1;
				}
			}
		}
		$docs = self::documents( $zip, $hashes ); // documenti definitivi di un'estensione (PDF già emessi): vanno sempre nella copia, con l'indice delle impronte
		$bundle = FileKey::bundle();
		$add    = function ( string $name, string $body ) use ( $zip, &$hashes ): void { // una voce scritta da qui: se ne tiene l'impronta
			$hashes[ $name ] = hash( 'sha256', $body );
			$zip->addFromString( $name, $body );
		};
		if ( $bundle ) {
			$add( 'chiavi.json', (string) wp_json_encode( $bundle, JSON_PRETTY_PRINT ) );
		}
		$add( 'impostazioni.json', (string) wp_json_encode( self::settings_payload(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$add( 'LEGGIMI.txt', self::readme( $plain_files ) );
		$key      = BackupSeal::key( wp_salt( 'auth' ) );
		$manifest = array(
			'plugin' => 'associazionesemplice', 'version' => ASEM_VERSION, 'db_version' => (string) get_option( Install::DB_VERSION_OPTION, Install::DB_VERSION ), 'created_at' => Db::now(),
			'site' => home_url(), 'prefix' => $db->prefix, 'tables' => $counts, 'attachments' => count( $files ), 'documents' => $docs,
			'encrypted' => (bool) $bundle,
			'plain_fields' => $plain, // campi di testo che sul sito non si potevano cifrare e sono nelle tabelle in chiaro (per tabella.colonna)
			'entries' => $hashes,     // impronta SHA-256 di ogni voce dello ZIP (il manifest escluso)
			'kfp' => BackupSeal::fingerprint( $key ), // impronta dei codici di sicurezza che hanno firmato (non li rivela)
		);
		$manifest['sig'] = BackupSeal::signature( $manifest, $key ); // HMAC-SHA256 di tutto il manifest
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$ok = $zip->close();
		foreach ( $tmp as $f ) {
			wp_delete_file( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok || ! is_file( $path ) ) {
			throw new \RuntimeException( 'Creazione della copia non riuscita.' );
		}
		update_option( self::OPT_LAST, time(), false );
		update_option( self::OPT_PLAIN, array_sum( $plain ), false );
		Audit::log( 'backup.created', 'settings', 0, array( 'tables' => count( $counts ), 'attachments' => count( $files ), 'documents' => $docs, 'plain_fields' => array_sum( $plain ) ) );
		return $path;
	}

	/** Il LEGGIMI che sta dentro ogni copia. */
	private static function readme( int $plain_files = 0 ): string {
		$plain_line = $plain_files > 0 ? str_replace( '{n}', (string) $plain_files, "{n} file sono inclusi in chiaro perché non erano ancora cifrati.\n" ) : ''; // il LEGGIMI della copia è un testo fisso in italiano, come il resto del file
		return "Copia di sicurezza di Associazione Semplice.\n"
			. "Contiene una tabella per file (tabelle/*.jsonl, una riga JSON per record), le impostazioni (impostazioni.json) e, se scelto, gli allegati. La cartella documenti/ contiene i PDF definitivi già emessi (ad esempio le ricevute) con l'indice delle loro impronte SHA-256 (documenti/indice.json).\n"
			. "Il manifest (manifest.json) elenca l'impronta SHA-256 di ogni file della copia ed è firmato con i codici di sicurezza del sito che l'ha fatta. Sullo stesso sito una copia modificata dopo essere stata fatta viene rifiutata; su un altro sito il ripristino chiede una conferma scritta e la password dell'amministratore, e resta scritto nel registro delle azioni.\n"
			. "Si ripristina da Strumenti → Copia di sicurezza.\n"
			. "I dati in chiaro (soci, prima nota e tutte le altre tabelle) si ripristinano sempre, anche senza la frase e anche su un server senza la libreria sodium.\n"
			. "I documenti riservati (certificati, allegati, scansioni) restano cifrati anche in questa copia. Nel file chiavi.json le chiavi sono a loro volta cifrate: da sole non aprono niente.\n"
			. "Per aprire i documenti dopo il ripristino serve una di queste tre cose:\n"
			. "- il sito di origine, con la sua chiave principale;\n"
			. "- la frase in uso quando hai fatto la copia;\n"
			. "- il codice di licenza, se il Pro era attivo.\n"
			. "Se non hai mai scelto una frase, quella in uso è automatica: nel plugin gratuito è la data e l'ora, in UTC, in cui è stata creata la chiave dei file (non è la data di installazione del plugin); con il Pro è il codice di licenza. Una frase automatica protegge meno di una frase scelta da te. Per una protezione vera scegline una tua in Strumenti → Copia di sicurezza → «Frase per riaprire i documenti» e conservala fuori dal sito.\n"
			. "Se non hai nessuna delle tre, i documenti tornano sul sito ma restano chiusi: il ripristino ti dice quanti sono e puoi aprirli più tardi con «Riapri la chiave con la frase».\n"
			. "Se il sito non è riuscito a cifrare alcuni testi, quei testi restano leggibili anche nella copia; quanti sono lo vedi in Strumenti → Copia di sicurezza, dopo aver fatto la copia.\n"
			. "Il file contiene anche le impronte delle password dei soci (mai le password in chiaro) e le notifiche push. Il ripristino rimette le password: ogni socio, al primo accesso, entra con la password di prima e deve scegliere subito una nuova password (vale anche entrando con il link ricevuto per e-mail). Le sessioni aperte e le notifiche push non vengono ripristinate, e nemmeno il registro delle azioni e gli utenti WordPress.\n"
			. $plain_line . "Le chiavi segrete dei pagamenti online non sono incluse.\n";
	}

	/**
	 * Aggiunge allo ZIP i documenti definitivi che un'estensione dichiara con il filtro `asem_backup_documents` (elenco di name, path, sha256) e
	 * scrive `documenti/indice.json` con i nomi e le impronte SHA-256 (del contenuto in chiaro e, se il file è cifrato, anche del file com'è nello ZIP). Solo nomi sotto `documenti/`, senza `..`, e solo file che esistono.
	 *
	 * @return int documenti aggiunti
	 */
	private static function documents( \ZipArchive $zip, array &$hashes ): int {
		$index = array();
		foreach ( (array) apply_filters( 'asem_backup_documents', array() ) as $d ) {
			if ( ! is_array( $d ) || ! isset( $d['name'], $d['path'] ) ) {
				continue;
			}
			$name = (string) $d['name'];
			if ( 1 !== preg_match( '#^documenti/[A-Za-z0-9/_.-]+$#', $name ) || false !== strpos( $name, '..' ) || 'documenti/indice.json' === $name || ! is_file( (string) $d['path'] ) ) {
				continue;
			}
			$zip->addFile( (string) $d['path'], $name );
			$hashes[ $name ] = (string) hash_file( 'sha256', (string) $d['path'] );
			$entry   = array( 'name' => $name, 'sha256' => (string) ( $d['sha256'] ?? '' ) );
			if ( ! empty( $d['file_sha256'] ) ) {
				$entry['file_sha256'] = (string) $d['file_sha256']; // impronta del file così com'è nello ZIP (cifrato); «sha256» è quella del contenuto in chiaro
			}
			$index[] = $entry;
		}
		if ( $index ) {
			$body = (string) wp_json_encode( array( 'files' => $index ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			$hashes['documenti/indice.json'] = hash( 'sha256', $body );
			$zip->addFromString( 'documenti/indice.json', $body );
		}
		return count( $index );
	}

	// ---------- Ripristino ----------

	/**
	 * Le parole con cui si dice che una copia viene da un altro sito: che cosa sostituirà, che cosa non tocca e che cosa serve per procedere.
	 * Si mostra a chi prova a ripristinarla senza la conferma scritta.
	 */
	public static function other_site_text(): string {
		return Texts::t( 'Questa copia non è stata fatta su questo sito. Ripristinala solo se è tua e sai da dove viene: sostituirà i dati attuali.' )
			. ' ' . Texts::t( 'Verranno sostituiti: tutte le tabelle del plugin (soci, prima nota, attività, ricevute, fondi e le altre), le impostazioni e i testi personalizzati, gli allegati e i PDF delle ricevute citati dai dati della copia, le chiavi dei documenti riservati e l\'identificativo del sito che li lega.' )
			. ' ' . Texts::t( 'Verranno cancellate, senza essere ripristinate dalla copia: le sessioni dei soci e le notifiche push di adesso. Le password dei soci invece tornano come nella copia, e ogni socio dovrà sceglierne una nuova al primo accesso.' )
			. ' ' . Texts::t( 'Non verranno toccati: il registro delle azioni, gli utenti WordPress, le chiavi segrete dei pagamenti e il codice di licenza di questo sito.' )
			. ' ' . Texts::t( 'Per procedere scrivi «{frase}» nel campo di conferma e la tua password; l\'operazione resta scritta nel registro delle azioni.', array( 'frase' => self::RESTORE_PHRASE_OTHER ) );
	}

	/** Una copia rifiutata: lascia una riga nel registro azioni (con il solo motivo) e restituisce l'errore da lanciare. */
	private static function refusal( string $code, string $message ): \InvalidArgumentException {
		Audit::log( 'backup.restore_refused', 'settings', 0, array( 'reason' => $code ) );
		return new \InvalidArgumentException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
	}

	/** Il messaggio per una copia che supera i tetti (vedi {@see BackupSeal::sizes()}). */
	private static function limit_message( string $problem ): string {
		if ( 'attachments' === $problem ) {
			return Texts::t( 'La copia contiene troppi allegati per essere ripristinata da qui. Non è stato cambiato nulla.' );
		}
		if ( 'entries' === $problem ) {
			return Texts::t( 'La copia contiene troppi file per essere ripristinata da qui. Non è stato cambiato nulla.' );
		}
		return Texts::t( 'La copia è troppo grande per essere ripristinata da qui: una sua voce o il totale superano i limiti. Non è stato cambiato nulla.' );
	}

	/**
	 * Controlla il file e ne legge l'indice. Dice anche da dove viene: $origin diventa «same» (fatta da questo sito: firma e impronte coincidono) oppure
	 * «other» (fatta con altri codici di sicurezza, o senza firma: la firma qui non si può verificare e il ripristino vuole una conferma scritta).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function inspect( string $zip_path, ?string &$origin = null ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può leggere file ZIP.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza valida.' );
		}
		try {
			return self::check( $zip, $origin );
		} finally {
			$zip->close();
		}
	}

	/** I controlli di inspect() su uno ZIP già aperto. @throws \InvalidArgumentException */
	private static function check( \ZipArchive $zip, ?string &$origin = null ): array {
		$limits = BackupSeal::limits( self::$test_limits );
		$st     = $zip->statName( 'manifest.json' );
		if ( $st && (int) $st['size'] > $limits['small'] ) {
			throw self::refusal( 'size', self::limit_message( 'entry' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		$raw = $st ? $zip->getFromName( 'manifest.json' ) : false;
		$m   = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $m ) || 'associazionesemplice' !== ( $m['plugin'] ?? '' ) || empty( $m['tables'] ) || ! is_array( $m['tables'] ) ) {
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza di Associazione Semplice.' );
		}
		if ( (int) ( $m['db_version'] ?? 0 ) > (int) Install::DB_VERSION ) {
			throw new \InvalidArgumentException( 'La copia è stata fatta con una versione più recente del plugin: aggiorna prima il plugin.' );
		}
		$known = self::tables();
		foreach ( array_keys( $m['tables'] ) as $t ) {
			if ( ! preg_match( '/^asem_[a-z_]+$/', (string) $t ) || ( ! in_array( $t, $known, true ) && ! in_array( $t, self::NOT_RESTORED, true ) ) ) {
				throw new \InvalidArgumentException( 'La copia contiene una tabella sconosciuta (' . esc_html( (string) $t ) . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		$sz = BackupSeal::sizes( $zip, self::$test_limits ); // dimensioni dichiarate: i tetti si controllano prima di leggere una sola voce
		if ( '' !== $sz['problem'] ) {
			throw self::refusal( $sz['problem'], self::limit_message( $sz['problem'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		foreach ( array_keys( $m['tables'] ) as $t ) {
			if ( ! isset( $sz['sizes'][ 'tabelle/' . $t . '.jsonl' ] ) ) {
				throw new \InvalidArgumentException( 'La copia è incompleta: manca la tabella ' . esc_html( (string) $t ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		// Impronte e firma. Con i codici di sicurezza di questo sito la copia deve essere intatta e la firma valida; con altri codici la firma non si può
		// verificare (la copia viene da un altro sito, o non è firmata) e le impronte servono solo a scoprire un file danneggiato o cambiato.
		$mine   = BackupSeal::key( wp_salt( 'auth' ) );
		$listed = isset( $m['entries'] ) && is_array( $m['entries'] ) ? $m['entries'] : null;
		$int    = '';
		if ( null !== $listed ) {
			$actual = BackupSeal::hashes( $zip, $sz['sizes'] );
			$int    = null === $actual ? 'changed' : BackupSeal::compare( $listed, $actual );
		}
		$modified = Texts::t( 'La copia è stata modificata dopo essere stata fatta, oppure è danneggiata: il suo contenuto non coincide con l\'elenco con cui è stata firmata. Non è stato cambiato nulla.' );
		if ( is_string( $m['kfp'] ?? null ) && hash_equals( BackupSeal::fingerprint( $mine ), $m['kfp'] ) ) { // stessi codici di sicurezza: è una copia di questo sito
			if ( null === $listed || '' !== $int ) {
				throw self::refusal( 'modified', $modified ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
			}
			if ( ! is_string( $m['sig'] ?? null ) || ! hash_equals( BackupSeal::signature( $m, $mine ), $m['sig'] ) ) {
				throw self::refusal( 'signature', Texts::t( 'La firma di questa copia non è valida: la copia è stata modificata, oppure non è stata firmata da questo sito. Non è stato cambiato nulla.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
			}
			$origin = 'same';
			return $m;
		}
		if ( null !== $listed && '' !== $int ) {
			throw self::refusal( 'modified', $modified ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		$origin = 'other';
		return $m;
	}

	/** Colonne di una tabella. @return string[] */
	private static function columns( string $tbl ): array {
		return array_map( function ( $c ) {
			return (string) $c['Field'];
		}, Db::db()->get_results( "SHOW COLUMNS FROM `$tbl`", ARRAY_A ) ?: array() );
	}

	private static function insert_rows( string $tbl, array $cols, array $rows ): void {
		$db     = Db::db();
		$values = array();
		$short = substr( $tbl, strlen( $db->prefix ) );
		foreach ( $rows as $r ) {
			$r    = RowClean::row( $short, $r ); // i testi semplici rientrano senza tag, come da modulo
			$vals = array();
			foreach ( $cols as $c ) {
				$v      = $r[ $c ] ?? null;
				$vals[] = null === $v ? 'NULL' : $db->prepare( '%s', (string) $v );
			}
			$values[] = '(' . implode( ',', $vals ) . ')';
		}
		if ( false === $db->query( "INSERT INTO `$tbl` (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ',', $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			throw new \RuntimeException( 'Errore del database durante il ripristino: ' . $db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Una voce JSON piccola dello ZIP, letta e controllata PRIMA di toccare i dati. @return array|null null se la voce non c'è */
	private static function read_json( \ZipArchive $zip, string $name, string $what ): ?array {
		$st = $zip->statName( $name );
		if ( ! $st ) {
			return null;
		}
		if ( (int) $st['size'] > BackupSeal::limits( self::$test_limits )['small'] ) {
			throw self::refusal( 'size', self::limit_message( 'entry' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		$raw = $zip->getFromName( $name );
		$v   = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $v ) ) {
			$msg = 'keys' === $what ? Texts::t( 'Le chiavi contenute nella copia non sono valide.' ) : ( 'index' === $what ? Texts::t( 'La copia è danneggiata: l\'indice dei documenti non si legge. Non è stato cambiato nulla.' ) : Texts::t( 'La copia è danneggiata: le impostazioni non si leggono. Non è stato cambiato nulla.' ) );
			throw self::refusal( 'unreadable_' . $what, $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
		}
		return $v;
	}

	/**
	 * Sostituisce i dati del plugin con quelli della copia. Prima salva una copia di sicurezza sul sito; il ripristino è tutto o niente per i dati.
	 *
	 * Un solo ripristino alla volta (blocco del database con nome per sito). Le copie fatte da questo sito si ripristinano se firma e impronte sono
	 * valide; quelle fatte altrove (o senza firma) solo con $other_site_ok. Non si riscrive il registro azioni; sessioni, codici di accesso e notifiche
	 * push dei soci si svuotano. Le impronte delle password dei soci tornano dalla copia, con il segno «cambio obbligatorio» (must_reset = 2): ogni socio ne sceglie una nuova al primo accesso. Le chiavi dei documenti cifrati e l'identificativo del
	 * sito si adottano DOPO il COMMIT, con una riga nel registro azioni.
	 *
	 * @param string $phrase        la frase della copia (il codice di licenza o la data di attivazione di allora, o la frase scelta): serve solo per aprire le chiavi che questo sito non conosce; senza, quelle chiavi si adottano chiuse
	 * @param bool   $other_site_ok chi ripristina ha confermato per iscritto una copia fatta su un altro sito
	 * @return array tables, rows, files, bad (file non rimessi perché l'impronta non coincide), skipped (file che nessuna riga cita), closed (documenti rimessi ma chiusi), keys_closed, members_reset (righe di sessioni, codici e notifiche dei soci cancellate), passwords (password dei soci rimesse con il cambio obbligatorio), origin, safety, documents
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function restore( string $zip_path, string $phrase = '', bool $other_site_ok = false ): array {
		return Db::with_lock(
			'asem_backup_restore',
			function () use ( $zip_path, $phrase, $other_site_ok ): array {
				return self::restore_locked( $zip_path, $phrase, $other_site_ok );
			},
			0,
			true
		);
	}

	private static function restore_locked( string $zip_path, string $phrase, bool $other_site_ok ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può leggere file ZIP.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza valida.' );
		}
		try {
			$origin = '';
			$m      = self::check( $zip, $origin );
			if ( 'other' === $origin && ! $other_site_ok ) {
				throw self::refusal( 'other_site', self::other_site_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
			}
			return self::restore_open( $zip, $m, $origin, $phrase );
		} finally {
			$zip->close();
		}
	}

	private static function restore_open( \ZipArchive $zip, array $m, string $origin, string $phrase ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- lettura/scrittura in streaming di file grandi
		}
		// Prima di toccare qualsiasi dato: si leggono e si controllano le voci piccole (chiavi, impostazioni, indice dei PDF) e si prepara l'adozione delle
		// chiavi, così un file rovinato o una frase sbagliata fermano tutto senza cambiare nulla. Le chiavi si scrivono dopo il COMMIT.
		$bundle   = self::read_json( $zip, 'chiavi.json', 'keys' );
		$settings = self::read_json( $zip, 'impostazioni.json', 'settings' );
		$index    = self::read_json( $zip, 'documenti/indice.json', 'index' );
		if ( null !== $index ) {
			$list = $index['files'] ?? array();
			if ( ! is_array( $list ) || count( $list ) > BackupSeal::limits( self::$test_limits )['attachments'] ) {
				throw self::refusal( 'unreadable_index', Texts::t( 'La copia è danneggiata: l\'indice dei documenti non si legge. Non è stato cambiato nulla.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
			}
			foreach ( $list as $f ) {
				if ( ! is_array( $f ) ) {
					throw self::refusal( 'unreadable_index', Texts::t( 'La copia è danneggiata: l\'indice dei documenti non si legge. Non è stato cambiato nulla.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso del plugin, mostrato solo dopo esc_html
				}
			}
		}
		$plan = null !== $bundle ? FileKey::plan_adopt( $bundle, $phrase ) : null; // lancia InvalidArgumentException con il motivo, senza scrivere nulla

		$dir    = self::ensure_dir();
		$safety = $dir . '/prima-del-ripristino-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.zip'; // nome non indovinabile: la cartella potrebbe essere raggiungibile da web (nginx ignora .htaccess)
		self::export( true, $safety );
		$old = self::saved();
		foreach ( array_slice( $old, Limits::get( 'backup_keep' ) ) as $o ) {
			wp_delete_file( $dir . '/' . $o['name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$db         = Db::db();
		$names      = array_values( array_diff( array_keys( $m['tables'] ), self::NOT_RESTORED ) );
		$wipe       = array();
		$tables_wipe = self::MEMBER_ACCESS;
		if ( ! isset( $m['tables']['asem_member_auth'] ) ) {
			$tables_wipe[] = 'asem_member_auth'; // una copia senza le password: quelle di adesso erano legate alle persone di prima, si svuotano
		}
		foreach ( $tables_wipe as $t ) {
			if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $db->prefix . $t ) ) ) ) {
				$wipe[] = $t;
			}
		}
		$rows_total    = 0;
		$members_reset = 0;
		$passwords     = 0;
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( $names as $name ) {
				$tbl  = $db->prefix . $name;
				$cols = self::columns( $tbl );
				$db->query( "DELETE FROM `$tbl`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				$h = $zip->getStream( 'tabelle/' . $name . '.jsonl' );
				if ( ! $h ) {
					throw new \RuntimeException( 'Impossibile leggere la tabella ' . $name . '.' );
				}
				$batch = array();
				$use   = null;
				while ( false !== ( $line = fgets( $h ) ) ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$r = json_decode( $line, true );
					if ( ! is_array( $r ) ) {
						throw new \RuntimeException( 'Riga non valida nella tabella ' . $name . '.' );
					}
					if ( null === $use ) {
						$use = array_values( array_intersect( array_keys( $r ), $cols ) ); // solo le colonne che esistono ancora
					}
					$batch[] = $r;
					$rows_total++;
					if ( count( $batch ) >= 100 ) {
						self::insert_rows( $tbl, $use, $batch );
						$batch = array();
					}
				}
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
				if ( $batch ) {
					self::insert_rows( $tbl, $use, $batch );
				}
			}
			if ( isset( $m['tables']['asem_member_auth'] ) ) { // le password tornano, ma ogni socio ne sceglie una nuova al primo accesso (il segno si toglie solo cambiandola)
				$auth = $db->prefix . 'asem_member_auth';
				$n    = $db->query( $db->prepare( "UPDATE `$auth` SET must_reset = %d WHERE pass_hash IS NOT NULL AND pass_hash <> ''", MemberPassword::RESTORED ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( false === $n ) {
					throw new \RuntimeException( 'Errore del database durante il ripristino: ' . $db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$passwords = (int) $n;
			}
			foreach ( $wipe as $t ) { // sessioni, codici e notifiche dei soci erano legati a ciò che c'era prima: dopo il ripristino i soci dovranno entrare di nuovo
				$tbl = $db->prefix . $t;
				$n   = $db->query( "DELETE FROM `$tbl`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( false === $n ) {
					throw new \RuntimeException( 'Errore del database durante il ripristino: ' . $db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$members_reset += (int) $n;
			}
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
				wp_delete_file( $safety ); // nulla è cambiato: la copia con le tabelle in chiaro non serve a nessuno
			throw new \RuntimeException( $e->getMessage() . ' Nessun dato è stato cambiato.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		// Chiavi dei documenti e identificativo del sito: solo ora che i dati sono al loro posto (una riga nel registro azioni dice che cosa si è adottato).
		$keys = array( 'open' => 0, 'closed' => 0, 'site_changed' => false, 'failed' => false );
		if ( $plan ) {
			try {
				$keys = array_merge( $keys, FileKey::commit_adopt( $plan ) );
			} catch ( \InvalidArgumentException $e ) { // un'altra operazione sulle chiavi tiene il blocco: i dati sono a posto, le chiavi no
				$keys['failed'] = true;
				Audit::log( 'backup.keys_adopt_failed', 'folder', 0 );
			}
		}
		// impostazioni (le chiavi segrete restano quelle del sito) e opzioni collegate
		$p = $settings;
		if ( is_array( $p ) ) {
			$new = is_array( $p['settings'] ?? null ) ? $p['settings'] : array();
			foreach ( Settings::secret_keys() as $k ) {
				unset( $new[ $k ] ); // le chiavi segrete restano quelle del sito (e Settings::update le lascia com'è)
			}
			unset( $new['license_key'] ); // la chiave di licenza resta quella del sito
			$new = Secrets::strip_encrypted( $new ); // una copia fatta da una versione precedente può contenere chiavi cifrate: non si rimettono
			$fiscal_month = max( 1, min( 12, (int) ( $new['fiscal_start_month'] ?? 1 ) ) ); // il mese dell'anno fiscale torna quello della copia, insieme agli anni fiscali: non passa dal blocco «anni fiscali chiusi»
			unset( $new['fiscal_start_month'] );
			Settings::update( $new ); // passa dai controlli delle impostazioni: una copia modificata non può inserire valori fuori regola
			$saved                       = (array) get_option( Settings::OPTION, array() );
			$saved['fiscal_start_month'] = $fiscal_month;
			update_option( Settings::OPTION, $saved );
			Settings::forget(); // la copia in memoria di questa richiesta si rilegge
			foreach ( self::OPT_NAMES as $n ) {
				if ( isset( $p['options'][ $n ] ) && null !== $p['options'][ $n ] ) {
					update_option( $n, $p['options'][ $n ], false );
				}
			}
		}
		// allegati: solo quelli che una riga dei dati ripristinati cita (con la loro impronta), a pezzi su un temporaneo
		$files   = 0;
		$bad     = 0;
		$skipped = 0;
		$closed  = 0;
		if ( ! empty( $m['attachments'] ) ) {
			Attachments::prepare_dir();
			$map = PrivateFiles::lookup_map(); // impronta e cifratura registrate nel database appena ripristinato
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( preg_match( '#^allegati/([0-9a-f]{32})$#', $name, $mm ) ) {
					if ( ! isset( $map[ $mm[1] ] ) ) {
						$skipped++; // nessuna riga lo cita: non si rimette sul disco
						continue;
					}
					$res = self::restore_file( $zip, $name, $mm[1], $map[ $mm[1] ] );
					if ( 'bad' === $res ) {
						$bad++;
					} else {
						$files++;
						if ( 'closed' === $res ) {
							$closed++;
						}
					}
				}
			}
		}
		// documenti definitivi (PDF già emessi): un'estensione li rimette sul disco, controllando le impronte registrate nel database ripristinato
		$documents = array_merge( array( 'restored' => 0, 'present' => 0, 'missing' => 0, 'bad' => 0, 'closed' => 0 ), (array) apply_filters( 'asem_backup_restore_documents', array(), $zip, $m, $index ) );
		Install::migrate_year_closures(); // una copia fatta prima della traccia delle chiusure: gli anni chiusi ne ricevono la riga (idempotente)
		Texts::flush();
		$closed_all = $closed + (int) $documents['closed'];
		Audit::log(
			'backup.restored',
			'settings',
			0,
			array(
				'origin'            => $origin,
				'rows'              => $rows_total,
				'attachments'       => $files,
				'attachments_bad'   => $bad,
				'attachments_skipped' => $skipped,
				'documents_closed'  => $closed_all,
				'keys_closed'       => (int) $keys['closed'],
				'members_reset'     => $members_reset,
				'passwords'         => $passwords,
				'documents'         => $documents,
			)
		);
		wp_delete_file( $safety ); // ripristino riuscito: la copia con le tabelle in chiaro e le chiavi non resta sul disco (quelle che restassero per un'interruzione le toglie la pulizia oraria)
		return array(
			'tables'        => count( $names ),
			'rows'          => $rows_total,
			'files'         => $files,
			'bad'           => $bad,
			'skipped'       => $skipped,
			'closed'        => $closed_all,
			'keys_closed'   => (int) $keys['closed'],
			'keys_failed'   => (bool) $keys['failed'],
			'members_reset' => $members_reset,
			'passwords'     => $passwords,
			'origin'        => $origin,
			'safety'        => basename( $safety ),
			'documents'     => $documents,
		);
	}

	/**
	 * Rimette sul disco un file della copia, a pezzi (mai intero in memoria), in un file temporaneo; ne controlla l'impronta (per i file cifrati si apre
	 * e si confronta il contenuto) e solo se coincide lo rinomina al suo posto. Un file cifrato la cui chiave su questo sito non c'è (copia fatta altrove,
	 * rimessa senza frase, o server senza sodium) non si può aprire: si rimette lo stesso, chiuso (l'impronta del file com'è nello ZIP è già stata
	 * controllata con quella del manifest).
	 *
	 * @param array $info [versione cifratura, impronta] registrati nel database
	 * @return string ok, closed (rimesso ma chiuso) o bad (non letto o impronta diversa: il file non viene rimesso)
	 */
	private static function restore_file( \ZipArchive $zip, string $entry, string $stored, array $info ): string {
		$in = $zip->getStream( $entry );
		if ( ! $in ) {
			return 'bad';
		}
		$final = Attachments::dir() . '/' . $stored;
		$tmp   = $final . '.part-' . bin2hex( random_bytes( 4 ) );
		$out   = fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lettura/scrittura in streaming di file grandi
		if ( ! $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
			return 'bad';
		}
		$ok     = true;
		$closed = false;
		while ( ! feof( $in ) ) {
			$d = fread( $in, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- lettura/scrittura in streaming di file grandi
			if ( false === $d ) {
				$ok = false;
				break;
			}
			if ( '' !== $d && false === fwrite( $out, $d ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- lettura/scrittura in streaming di file grandi
				$ok = false;
				break;
			}
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
		if ( $ok ) {
			$sha = strtolower( (string) $info[1] );
			try {
				if ( PrivateFiles::is_enc( $tmp, $stored, $info[0] ) ) {
					PrivateFiles::verify( $tmp, $stored, $sha );
				} elseif ( '' !== $sha && ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
					$ok = false;
				}
			} catch ( FileCryptError $e ) {
				if ( in_array( $e->reason, array( FileCryptError::NO_KEY, FileCryptError::NO_SODIUM ), true ) ) {
					$closed = true; // la chiave non c'è ancora: il file si rimette chiuso
				} else {
					$ok = false;
				}
			}
		}
		if ( ! $ok ) {
			wp_delete_file( $tmp );
			return 'bad';
		}
		chmod( $tmp, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		if ( ! rename( $tmp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- lettura/scrittura in streaming di file grandi
			wp_delete_file( $tmp );
			return 'bad';
		}
		return $closed ? 'closed' : 'ok';
	}

	// ---------- Download ----------

	/** Indirizzo dell'azione di scarico: senza password non scarica nulla, lo scarico parte solo dal modulo della pagina. */
	public static function download_url( bool $with_files ): string {
		return add_query_arg( array( 'action' => 'asem_backup', 'files' => $with_files ? 1 : 0 ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * Dove mandare chi vuole una copia: la pagina Copia di sicurezza, dove si scrive la password prima dello scarico.
	 */
	public static function download_link( bool $with_files ): string {
		return Admin\Ui::url( 'asem-backup' );
	}

	/** Indirizzo dell'azione di scarico di una copia salvata: lo scarico parte solo dal modulo della pagina (POST con password). */
	public static function saved_url( string $name ): string {
		return add_query_arg( array( 'action' => 'asem_backup_saved', 'name' => $name ), admin_url( 'admin-post.php' ) );
	}

	private static function send_zip( string $path, string $filename, bool $delete ): void {
		if ( $delete ) { // anche se il browser interrompe lo scarico la copia temporanea, con tutti i dati dei soci, non resta sul disco
			ignore_user_abort( true );
			register_shutdown_function(
				function () use ( $path ) {
					if ( is_file( $path ) ) {
						wp_delete_file( $path );
					}
				}
			);
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- lettura/scrittura in streaming di file grandi
		if ( $delete ) {
			wp_delete_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		exit;
	}

	public static function handle_download(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$back = Admin\Ui::url( 'asem-backup' );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // un vecchio indirizzo diretto porta alla pagina, dove si scrive la password
			Admin\Ui::redirect( $back );
		}
		check_admin_referer( 'asem_backup' );
		self::require_password( $back );
		$with = isset( $_POST['files'] ) && 1 === absint( $_POST['files'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce controllato sopra
		try {
			$path = self::export( $with );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}
		self::mark_downloaded();
		Audit::log( 'export.downloaded', 'export', 0, array( 'what' => $with ? 'backup_with_files' : 'backup' ) );
		self::send_zip( $path, 'copia-associazionesemplice-' . gmdate( 'Y-m-d-His' ) . '.zip', true );
	}

	/**
	 * Password dell'utente e blocco dei tentativi, uguali per ogni scarico di una copia (nuova o già salvata). Se non va, torna alla pagina con il messaggio.
	 * Da chiamare solo dopo permesso e nonce.
	 */
	private static function require_password( string $back ): void {
		$user = wp_get_current_user();
		$keys = \AssociazioneSemplice\AttemptLimit::keys( $user && $user->exists() ? (int) $user->ID : 0, \AssociazioneSemplice\ClientIp::get() ); // stesse chiavi del ripristino e dell'azzeramento
		$lim  = AttemptLimit::standard();
		$busy = Texts::t( 'Troppi tentativi: riprova tra qualche minuto.' );
		if ( $lim->is_locked( $keys ) ) { // durante il blocco la password non viene nemmeno controllata
			Admin\Ui::redirect( $back, '', $busy );
		}
		$pass = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce controllato dal chiamante; la password non va modificata
		if ( ! $user || ! $user->exists() || '' === $pass || ! wp_check_password( $pass, $user->user_pass, $user->ID ) ) {
			if ( $lim->fail( $keys ) ) {
				Audit::log( 'backup.download_locked', 'settings', 0, array( 'minutes' => (int) ( AttemptLimit::WINDOW / 60 ) ) );
				Admin\Ui::redirect( $back, '', $busy );
			}
			Admin\Ui::redirect( $back, '', Texts::t( 'La password non è corretta: non è stato scaricato nulla.' ) );
		}
		$lim->clear( $keys );
	}

	const META_DOWNLOADED = 'asem_backup_downloaded';

	/** L'utente ha appena scaricato una copia completa (serve prima di azzerare i dati). */
	public static function mark_downloaded(): void {
		update_user_meta( get_current_user_id(), self::META_DOWNLOADED, time() );
	}

	/** Ha scaricato una copia nell'ultima ora? */
	public static function downloaded_recently( int $within = HOUR_IN_SECONDS ): bool {
		$t = (int) get_user_meta( get_current_user_id(), self::META_DOWNLOADED, true );
		return $t > 0 && time() - $t <= $within;
	}

	/** Cancella le copie salvate sul sito (contengono dati personali). @return int copie cancellate */
	public static function delete_saved(): int {
		$n = 0;
		foreach ( self::saved() as $o ) {
			wp_delete_file( self::dir() . '/' . $o['name'] );
			$n++;
		}
		return $n;
	}

	public static function handle_saved(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$back = Admin\Ui::url( 'asem-backup' );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // un vecchio indirizzo diretto porta alla pagina, dove si scrive la password
			Admin\Ui::redirect( $back );
		}
		check_admin_referer( 'asem_backup_saved' );
		self::require_password( $back );
		$name = isset( $_POST['name'] ) ? basename( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce controllato sopra
		$path = self::dir() . '/' . $name;
		if ( ! preg_match( '/^prima-del-ripristino-\d{8}-\d{6}(-[0-9a-f]{16})?\.zip$/', $name ) || ! is_file( $path ) ) {
			wp_die( 'Copia non trovata.', 404 );
		}
		Audit::log( 'export.downloaded', 'export', 0, array( 'what' => 'backup_saved' ) );
		self::send_zip( $path, $name, false );
	}
}

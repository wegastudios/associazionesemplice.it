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

	/** Cancella le copie salvate sul sito da più di `backup_hours` ore (contengono dati personali). @return int copie cancellate */
	public static function purge_old(): int {
		$limit = time() - max( 1, (int) Limits::get( 'backup_hours' ) ) * HOUR_IN_SECONDS;
		$n     = 0;
		foreach ( glob( self::dir() . '/prima-del-ripristino-*.zip' ) ?: array() as $f ) {
			if ( (int) filemtime( $f ) < $limit ) {
				wp_delete_file( $f );
				$n++;
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
		$fields = array(); // colonne di testo che nel database stanno cifrate: nella copia non escono mai in chiaro
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
					foreach ( $fields[ $name ] ?? array() as $col ) { // un campo ancora in chiaro (non ancora cifrato sul sito) si cifra ora; se non si può, non esce
						$v = (string) ( $r[ $col ] ?? '' );
						if ( '' !== $v && ! FileCrypt::is_field( $v ) ) {
							$e         = PrivateFiles::field_encrypt( substr( $name, 5 ), $col, (int) $r['id'], $v );
							$r[ $col ] = $e !== $v ? $e : null;
						}
					}
					fwrite( $h, wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- lettura/scrittura in streaming di file grandi
					$n++;
				}
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
			$counts[ $name ] = $n;
			$zip->addFile( $f, 'tabelle/' . $name . '.jsonl' );
		}
		$files = array();
		if ( $with_files ) {
			foreach ( glob( Attachments::dir() . '/*' ) ?: array() as $f ) {
				if ( is_file( $f ) && preg_match( '/^[0-9a-f]{32}$/', basename( $f ) ) ) {
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[] = basename( $f );
				}
			}
		}
		$docs = self::documents( $zip ); // documenti definitivi di un'estensione (PDF già emessi): vanno sempre nella copia, con l'indice delle impronte
		$bundle = FileKey::bundle();
		if ( $bundle ) {
			$zip->addFromString( 'chiavi.json', (string) wp_json_encode( $bundle, JSON_PRETTY_PRINT ) );
		}
		$zip->addFromString( 'impostazioni.json', (string) wp_json_encode( self::settings_payload(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$zip->addFromString(
			'manifest.json',
			(string) wp_json_encode(
				array(
					'plugin' => 'associazionesemplice', 'version' => ASEM_VERSION, 'db_version' => (string) get_option( Install::DB_VERSION_OPTION, Install::DB_VERSION ), 'created_at' => Db::now(),
					'site' => home_url(), 'prefix' => $db->prefix, 'tables' => $counts, 'attachments' => count( $files ), 'documents' => $docs,
					'encrypted' => (bool) $bundle,
				),
				JSON_PRETTY_PRINT
			)
		);
		$zip->addFromString( 'LEGGIMI.txt', "Copia di sicurezza di Associazione Semplice.\nContiene una tabella per file (tabelle/*.jsonl, una riga JSON per record), le impostazioni (impostazioni.json) e, se scelto, gli allegati. La cartella documenti/ contiene i PDF definitivi già emessi (ad esempio le ricevute) con l'indice delle loro impronte SHA-256 (documenti/indice.json).\nSi ripristina da Strumenti → Copia di sicurezza.\nI documenti riservati (certificati, allegati, scansioni) sono cifrati. Le loro chiavi sono in chiavi.json, avvolte con la frase in uso sul sito (il codice di licenza, la data e ora di attivazione o la frase scelta) e con il codice di licenza: senza una di queste frasi (o senza la chiave del sito di origine) i documenti di questa copia non si possono più aprire.\nLe chiavi segrete dei pagamenti online non sono incluse.\n" );
		$ok = $zip->close();
		foreach ( $tmp as $f ) {
			wp_delete_file( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok || ! is_file( $path ) ) {
			throw new \RuntimeException( 'Creazione della copia non riuscita.' );
		}
		update_option( self::OPT_LAST, time(), false );
		Audit::log( 'backup.created', 'settings', 0, array( 'tables' => count( $counts ), 'attachments' => count( $files ), 'documents' => $docs ) );
		return $path;
	}

	/**
	 * Aggiunge allo ZIP i documenti definitivi che un'estensione dichiara con il filtro `asem_backup_documents` (elenco di name, path, sha256) e
	 * scrive `documenti/indice.json` con i nomi e le impronte SHA-256 (del contenuto in chiaro e, se il file è cifrato, anche del file com'è nello ZIP). Solo nomi sotto `documenti/`, senza `..`, e solo file che esistono.
	 *
	 * @return int documenti aggiunti
	 */
	private static function documents( \ZipArchive $zip ): int {
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
			$entry   = array( 'name' => $name, 'sha256' => (string) ( $d['sha256'] ?? '' ) );
			if ( ! empty( $d['file_sha256'] ) ) {
				$entry['file_sha256'] = (string) $d['file_sha256']; // impronta del file così com'è nello ZIP (cifrato); «sha256» è quella del contenuto in chiaro
			}
			$index[] = $entry;
		}
		if ( $index ) {
			$zip->addFromString( 'documenti/indice.json', (string) wp_json_encode( array( 'files' => $index ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		}
		return count( $index );
	}

	// ---------- Ripristino ----------

	/** Controlla il file e ne legge l'indice. @throws \InvalidArgumentException */
	public static function inspect( string $zip_path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può leggere file ZIP.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza valida.' );
		}
		$raw = $zip->getFromName( 'manifest.json' );
		$m   = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $m ) || 'associazionesemplice' !== ( $m['plugin'] ?? '' ) || empty( $m['tables'] ) ) {
			$zip->close();
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza di Associazione Semplice.' );
		}
		if ( (int) ( $m['db_version'] ?? 0 ) > (int) Install::DB_VERSION ) {
			$zip->close();
			throw new \InvalidArgumentException( 'La copia è stata fatta con una versione più recente del plugin: aggiorna prima il plugin.' );
		}
		$known = self::tables();
		foreach ( array_keys( $m['tables'] ) as $t ) {
			if ( ! preg_match( '/^asem_[a-z_]+$/', (string) $t ) || ! in_array( $t, $known, true ) ) {
				$zip->close();
				throw new \InvalidArgumentException( 'La copia contiene una tabella sconosciuta (' . esc_html( (string) $t ) . ').' );
			}
			$st = $zip->statName( 'tabelle/' . $t . '.jsonl' );
			if ( ! $st || (int) $st['size'] > self::MAX_ENTRY ) {
				$zip->close();
				throw new \InvalidArgumentException( 'La copia è incompleta: manca la tabella ' . $t . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		$zip->close();
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
		foreach ( $rows as $r ) {
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

	/**
	 * Sostituisce i dati del plugin con quelli della copia. Prima salva una copia di sicurezza sul sito; il ripristino è tutto o niente.
	 *
	 * @param string $phrase la frase della copia (il codice di licenza o la data di attivazione di allora, o la frase scelta): serve solo se le chiavi della copia non sono già note a questo sito
	 * @return array tables, rows, files, bad (file non rimessi perché l'impronta non coincide), safety (nome del file di sicurezza)
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function restore( string $zip_path, string $phrase = '' ): array {
		$m = self::inspect( $zip_path );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- lettura/scrittura in streaming di file grandi
		}
		// Le chiavi dei file cifrati: prima di toccare qualsiasi dato, così una frase sbagliata ferma tutto senza cambiare nulla.
		$bundle = array();
		$zk     = new \ZipArchive();
		if ( true === $zk->open( $zip_path ) ) {
			$raw    = $zk->getFromName( 'chiavi.json' );
			$bundle = $raw ? (array) json_decode( $raw, true ) : array();
			$zk->close();
		}
		if ( $bundle ) {
			FileKey::adopt_keys( $bundle, $phrase ); // lancia InvalidArgumentException con il motivo
		}
		$dir    = self::ensure_dir();
		$safety = $dir . '/prima-del-ripristino-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.zip'; // nome non indovinabile: la cartella potrebbe essere raggiungibile da web (nginx ignora .htaccess)
		self::export( true, $safety );
		$old = self::saved();
		foreach ( array_slice( $old, Limits::get( 'backup_keep' ) ) as $o ) {
			wp_delete_file( $dir . '/' . $o['name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$db  = Db::db();
		$zip = new \ZipArchive();
		$zip->open( $zip_path );
		$rows_total = 0;
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( array_keys( $m['tables'] ) as $name ) {
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
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			$zip->close();
			throw new \RuntimeException( $e->getMessage() . ' Nessun dato è stato cambiato.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( $bundle ) {
			FileKey::adopt_site( (string) ( $bundle['site'] ?? '' ) ); // i file della copia sono legati all'identificativo del sito di origine
		}
		// impostazioni (le chiavi segrete restano quelle del sito) e opzioni collegate
		$raw = $zip->getFromName( 'impostazioni.json' );
		$p   = $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $p ) ) {
			$new = is_array( $p['settings'] ?? null ) ? $p['settings'] : array();
			foreach ( Settings::secret_keys() as $k ) {
				unset( $new[ $k ] ); // le chiavi segrete restano quelle del sito (e Settings::update le lascia com'è)
			}
			unset( $new['license_key'] ); // la chiave di licenza resta quella del sito
			$fiscal_month = max( 1, min( 12, (int) ( $new['fiscal_start_month'] ?? 1 ) ) ); // il mese dell'anno fiscale torna quello della copia, insieme agli anni fiscali: non passa dal blocco «anni fiscali chiusi»
			unset( $new['fiscal_start_month'] );
			Settings::update( $new ); // passa dai controlli delle impostazioni: una copia modificata non può inserire valori fuori regola
			$saved                       = (array) get_option( Settings::OPTION, array() );
			$saved['fiscal_start_month'] = $fiscal_month;
			update_option( Settings::OPTION, $saved );
			foreach ( self::OPT_NAMES as $n ) {
				if ( isset( $p['options'][ $n ] ) && null !== $p['options'][ $n ] ) {
					update_option( $n, $p['options'][ $n ], false );
				}
			}
		}
		// allegati
		$files = 0;
		$bad   = 0;
		if ( ! empty( $m['attachments'] ) ) {
			Attachments::prepare_dir();
			$map = PrivateFiles::lookup_map(); // impronta e cifratura registrate nel database appena ripristinato
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( preg_match( '#^allegati/([0-9a-f]{32})$#', $name, $mm ) ) {
					if ( self::restore_file( $zip, $name, $mm[1], $map[ $mm[1] ] ?? null ) ) {
						$files++;
					} else {
						$bad++;
					}
				}
			}
		}
		// documenti definitivi (PDF già emessi): un'estensione li rimette sul disco, controllando le impronte registrate nel database ripristinato
		$documents = array_merge( array( 'restored' => 0, 'present' => 0, 'missing' => 0, 'bad' => 0 ), (array) apply_filters( 'asem_backup_restore_documents', array(), $zip, $m ) );
		$zip->close();
		Install::migrate_year_closures(); // una copia fatta prima della traccia delle chiusure: gli anni chiusi ne ricevono la riga (idempotente)
		Texts::flush();
		Audit::log( 'backup.restored', 'settings', 0, array( 'rows' => $rows_total, 'attachments' => $files, 'attachments_bad' => $bad, 'documents' => $documents ) );
		return array( 'tables' => count( $m['tables'] ), 'rows' => $rows_total, 'files' => $files, 'bad' => $bad, 'safety' => basename( $safety ), 'documents' => $documents );
	}

	/**
	 * Rimette sul disco un file della copia, a pezzi (mai intero in memoria), in un file temporaneo; ne controlla l'impronta (per i file cifrati si apre
	 * e si confronta il contenuto) e solo se coincide lo rinomina al suo posto.
	 *
	 * @param array|null $info [versione cifratura, impronta] registrati nel database, null se nessuna riga lo riguarda
	 * @return bool false se non si è potuto leggere o l'impronta non coincide (il file non viene rimesso)
	 */
	private static function restore_file( \ZipArchive $zip, string $entry, string $stored, ?array $info ): bool {
		$in = $zip->getStream( $entry );
		if ( ! $in ) {
			return false;
		}
		$final = Attachments::dir() . '/' . $stored;
		$tmp   = $final . '.part-' . bin2hex( random_bytes( 4 ) );
		$out   = fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lettura/scrittura in streaming di file grandi
		if ( ! $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lettura/scrittura in streaming di file grandi
			return false;
		}
		$ok = true;
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
		if ( $ok && null !== $info ) {
			$sha = strtolower( (string) $info[1] );
			try {
				if ( PrivateFiles::is_enc( $tmp, $stored, $info[0] ) ) {
					PrivateFiles::verify( $tmp, $stored, $sha );
				} elseif ( '' !== $sha && ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
					$ok = false;
				}
			} catch ( FileCryptError $e ) {
				$ok = false;
			}
		}
		if ( ! $ok ) {
			wp_delete_file( $tmp );
			return false;
		}
		chmod( $tmp, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		if ( ! rename( $tmp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- lettura/scrittura in streaming di file grandi
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}

	// ---------- Download ----------

	public static function download_url( bool $with_files ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_backup', 'files' => $with_files ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'asem_backup' );
	}

	/**
	 * Dove mandare chi vuole una copia: il download diretto.
	 */
	public static function download_link( bool $with_files ): string {
		return self::download_url( $with_files );
	}

	public static function saved_url( string $name ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_backup_saved', 'name' => $name ), admin_url( 'admin-post.php' ) ), 'asem_backup_saved' );
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
		check_admin_referer( 'asem_backup' );
		$with   = isset( $_REQUEST['files'] ) && 1 === absint( $_REQUEST['files'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce controllato sopra con check_admin_referer()
		try {
			$path = self::export( $with );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}
		self::mark_downloaded();
		self::send_zip( $path, 'copia-associazionesemplice-' . gmdate( 'Y-m-d-His' ) . '.zip', true );
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
		check_admin_referer( 'asem_backup_saved' );
		$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$path = self::dir() . '/' . $name;
		if ( ! preg_match( '/^prima-del-ripristino-\d{8}-\d{6}(-[0-9a-f]{16})?\.zip$/', $name ) || ! is_file( $path ) ) {
			wp_die( 'Copia non trovata.', 404 );
		}
		self::send_zip( $path, $name, false );
	}
}

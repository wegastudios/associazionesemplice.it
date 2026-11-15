<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * PDF definitivi delle ricevute (Pro). Una ricevuta emessa è un documento: il suo PDF si disegna UNA volta, si salva come file nella cartella
 * privata del plugin (la stessa degli allegati e delle copie di sicurezza, mai servita da web) e da quel momento si serve sempre quel file.
 * Nel database restano il percorso (relativo alla cartella privata) e l'impronta SHA-256 del file: prima di servirlo si controlla che sia ancora
 * identico. Non si riscrive mai un PDF già fissato; solo un amministratore, con un'azione esplicita e annotata, può rifarne uno mancante
 * o alterato, e solo dai dati fotografati all'emissione (se la loro impronta coincide).
 *
 * Ogni ricevuta ha fino a due file: quello EMESSO (colonne pdf_*) e, se poi viene annullata, quello ANNULLATA (colonne void_pdf_*), che porta
 * lo stesso contenuto con la scritta, la data e il motivo dell'annullo. Il primo non si sostituisce mai con il secondo.
 *
 * Posizione: `<cartella privata>/receipts/AAAA/<numero>[a]-<codice>.pdf`. Il codice è un HMAC (con il sale del sito) di id, anno, numero e tipo,
 * quindi il nome non si indovina; il percorso però è scritto nel database e ogni percorso passa da {@see valid_path()} prima di toccare il disco.
 */
final class ReceiptFiles {

	const SUBDIR   = 'receipts';
	const BATCH    = 100;
	const CRON     = 'asem_receipt_pdfs_batch';
	const MAX_SIZE = 10485760; // 10 MB: una ricevuta pesa pochi KB, oltre non è un nostro PDF

	const ISSUED = 'issued';
	const VOID   = 'void';

	/** Condizione SQL: ricevute con i dati fotografati ma con un PDF ancora da fissare (emesso o annullato). */
	const PENDING_SQL = "data IS NOT NULL AND data <> '' AND ( pdf_path IS NULL OR ( voided_at IS NOT NULL AND void_pdf_path IS NULL ) )";

	public static function register(): void {
		add_action( 'asem_year_closed', array( __CLASS__, 'on_year_closed' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_batch' ), 10, 2 );
		add_filter( 'asem_backup_documents', array( __CLASS__, 'backup_documents' ) );
		add_filter( 'asem_backup_restore_documents', array( __CLASS__, 'restore_documents' ), 10, 3 );
		add_action( 'asem_reset_private_files', array( __CLASS__, 'delete_all' ) );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Percorsi (regole pure) ----------

	/** Colonne del database di un tipo di PDF: percorso, impronta, data. @return string[] */
	public static function columns( string $kind ): array {
		return self::VOID === $kind ? array( 'void_pdf_path', 'void_pdf_sha256', 'void_pdf_at' ) : array( 'pdf_path', 'pdf_sha256', 'pdf_at' );
	}

	/** @return string[] */
	public static function kinds(): array {
		return array( self::ISSUED, self::VOID );
	}

	/** Un percorso relativo è uno dei nostri? Solo `receipts/AAAA/<numero>[a]-<codice>.pdf`, mai altro (niente `..`, niente barre in più). */
	public static function valid_path( string $p ): bool {
		return 1 === preg_match( '#^receipts/\d{4}/\d{1,9}a?-[0-9a-f]{20}(?:[0-9a-f]{6})?\.pdf\z#', $p );
	}

	/** Percorso relativo di un PDF (`$extra`: 6 cifre esadecimali aggiunte se il nome è già occupato da un file rimasto da un tentativo interrotto). */
	public static function rel_path( int $year, int $number, string $kind, string $mac, string $extra = '' ): string {
		return sprintf( '%s/%04d/%d%s-%s%s.pdf', self::SUBDIR, $year, $number, self::VOID === $kind ? 'a' : '', $mac, $extra );
	}

	/** Il codice non indovinabile nel nome del file. */
	public static function mac( array $rec, string $kind ): string {
		return substr( hash_hmac( 'sha256', 'receipt-pdf|' . (int) $rec['id'] . '|' . (int) $rec['year'] . '|' . (int) $rec['number'] . '|' . $kind, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** Percorso assoluto sul disco di un percorso relativo valido (stringa vuota se non lo è). */
	public static function abs_path( string $rel ): string {
		return self::valid_path( $rel ) ? rtrim( Attachments::dir(), '/\\' ) . '/' . $rel : '';
	}

	/** Il PDF di questo tipo è già fissato (c'è il percorso nel database)? */
	public static function has( array $rec, string $kind ): bool {
		$c = self::columns( $kind );
		return '' !== (string) ( $rec[ $c[0] ] ?? '' );
	}

	/**
	 * Stato di un PDF fissato: none (non ancora fissato), ok, missing (il file non c'è), altered (il contenuto non coincide con l'impronta o il percorso
	 * non è valido).
	 */
	public static function state( array $rec, string $kind ): string {
		$c    = self::columns( $kind );
		$path = (string) ( $rec[ $c[0] ] ?? '' );
		if ( '' === $path ) {
			return 'none';
		}
		$abs = self::abs_path( $path );
		if ( '' === $abs ) {
			return 'altered';
		}
		if ( ! is_file( $abs ) ) {
			return 'missing';
		}
		if ( (int) filesize( $abs ) > self::MAX_SIZE ) {
			return 'altered';
		}
		return hash_equals( (string) ( $rec[ $c[1] ] ?? '' ), (string) hash_file( 'sha256', $abs ) ) ? 'ok' : 'altered';
	}

	// ---------- Lettura e scrittura ----------

	/**
	 * Il PDF fissato, dopo aver controllato l'impronta. Se il file manca o è cambiato NON si rifà in silenzio: errore chiaro.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function read( array $rec, string $kind ): string {
		$c     = self::columns( $kind );
		$label = (int) $rec['number'] . '/' . (int) $rec['year'];
		$abs   = self::abs_path( (string) ( $rec[ $c[0] ] ?? '' ) );
		if ( '' === $abs ) {
			throw new \InvalidArgumentException( Texts::t( 'Il PDF della ricevuta n. {n} ha un percorso non valido: per sicurezza non lo apro. Avvisa un amministratore.', array( 'n' => $label ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! is_file( $abs ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Il PDF della ricevuta n. {n} non si trova più sul sito (la cartella dei file riservati è stata svuotata o spostata). Non lo rifaccio in silenzio: un amministratore può ripristinarlo da una copia di sicurezza oppure, da «Verifica delle ricevute», rigenerarlo dai dati salvati.', array( 'n' => $label ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$bytes = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file privato nella cartella del plugin
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_SIZE || ! hash_equals( (string) ( $rec[ $c[1] ] ?? '' ), hash( 'sha256', $bytes ) ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Il PDF della ricevuta n. {n} risulta diverso da quando è stato emesso: per sicurezza non lo scarico. Un amministratore può controllarlo da «Verifica delle ricevute».', array( 'n' => $label ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $bytes;
	}

	/** Crea un file nuovo, mai su uno esistente. */
	private static function write_new( string $abs, string $bytes ): bool {
		$h = @fopen( $abs, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- file privato: «x» rifiuta di toccare un file che c'è già
		if ( ! $h ) {
			return false;
		}
		$ok = strlen( $bytes ) === fwrite( $h, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- file privato
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- file privato
		if ( ! $ok ) {
			wp_delete_file( $abs );
			return false;
		}
		@chmod( $abs, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		return true;
	}

	/** Crea (se mancano) la cartella e il file `index.php` che impedisce l'elenco, sulla strada del PDF. */
	private static function prepare_dir( string $abs ): bool {
		Attachments::prepare_dir(); // cartella privata con .htaccess e index.php
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		foreach ( array( $dir, dirname( $dir ) ) as $d ) {
			if ( ! file_exists( $d . '/index.php' ) ) {
				@file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return true;
	}

	/**
	 * Fissa un PDF: scrive il file e poi il percorso con l'impronta nel database, UNA volta sola. Se il PDF di quel tipo è già fissato non fa nulla.
	 *
	 * @return bool true se l'ha fissato questa chiamata
	 */
	public static function store( array $rec, string $kind, string $bytes ): bool {
		if ( self::has( $rec, $kind ) || 0 !== strpos( $bytes, '%PDF-' ) || strlen( $bytes ) > self::MAX_SIZE ) {
			return false;
		}
		$c   = self::columns( $kind );
		$mac = self::mac( $rec, $kind );
		$rel = self::rel_path( (int) $rec['year'], (int) $rec['number'], $kind, $mac );
		$abs = self::abs_path( $rel );
		if ( '' === $abs ) {
			return false;
		}
		try {
			if ( ! self::prepare_dir( $abs ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			error_log( 'Associazione Semplice: cartella dei PDF delle ricevute non disponibile: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		if ( file_exists( $abs ) ) { // rimasto da un tentativo interrotto: non si tocca, si sceglie un altro nome
			$rel = self::rel_path( (int) $rec['year'], (int) $rec['number'], $kind, $mac, bin2hex( random_bytes( 3 ) ) );
			$abs = self::abs_path( $rel );
		}
		if ( '' === $abs || ! self::write_new( $abs, $bytes ) ) {
			return false;
		}
		$db = self::db();
		$n  = $db->query( $db->prepare( 'UPDATE ' . Db::t( 'receipts' ) . " SET {$c[0]} = %s, {$c[1]} = %s, {$c[2]} = %s WHERE id = %d AND {$c[0]} IS NULL", $rel, hash( 'sha256', $bytes ), Db::now(), (int) $rec['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- i nomi delle colonne sono costanti interne
		if ( 1 !== (int) $n ) { // un'altra richiesta l'ha fissato prima: vale il suo, il nostro si toglie
			wp_delete_file( $abs );
			return false;
		}
		return true;
	}

	/**
	 * Rifà il file di un PDF già fissato ma MANCANTE o ALTERATO (azione esplicita di un amministratore, annotata nel registro): il file alterato non
	 * si cancella, resta accanto con il suffisso «.alterato-…» (non fa parte delle copie di sicurezza), e l'impronta nel database diventa quella del nuovo.
	 *
	 * @return string impronta precedente
	 * @throws \InvalidArgumentException
	 */
	public static function replace( array $rec, string $kind, string $bytes ): string {
		$c   = self::columns( $kind );
		$abs = self::abs_path( (string) ( $rec[ $c[0] ] ?? '' ) );
		if ( '' === $abs || 0 !== strpos( $bytes, '%PDF-' ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Impossibile rifare il PDF: percorso o contenuto non validi.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( is_file( $abs ) && ! @rename( $abs, $abs . '.alterato-' . gmdate( 'YmdHis' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- il file alterato si conserva
			throw new \InvalidArgumentException( Texts::t( 'Non riesco a mettere da parte il file alterato: controlla i permessi della cartella dei file riservati.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! self::write_new( $abs, $bytes ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Non riesco a scrivere il nuovo PDF: controlla i permessi della cartella dei file riservati.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$db = self::db();
		$db->query( $db->prepare( 'UPDATE ' . Db::t( 'receipts' ) . " SET {$c[1]} = %s, {$c[2]} = %s WHERE id = %d", hash( 'sha256', $bytes ), Db::now(), (int) $rec['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- i nomi delle colonne sono costanti interne
		return (string) ( $rec[ $c[1] ] ?? '' );
	}

	// ---------- Completamento a lotti ----------

	/** Quante ricevute hanno ancora un PDF da fissare (di un anno, o di tutti). */
	public static function pending_count( ?int $year = null ): int {
		$db  = self::db();
		$sql = 'SELECT COUNT(*) FROM ' . Db::t( 'receipts' ) . ' WHERE ' . self::PENDING_SQL;
		return (int) ( null === $year ? $db->get_var( $sql ) : $db->get_var( $db->prepare( $sql . ' AND year = %d', $year ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condizione costante interna
	}

	/**
	 * Fissa i PDF mancanti dei prossimi `$limit` ricevute (a partire dall'id seguente a `$after_id`), dai dati già fotografati, senza cambiarli.
	 *
	 * @return array{done:int,failed:int,last_id:int,more:bool}
	 */
	public static function complete( ?int $year = null, int $after_id = 0, int $limit = self::BATCH ): array {
		$db     = self::db();
		$limit  = max( 1, min( 500, $limit ) );
		$sql    = 'SELECT * FROM ' . Db::t( 'receipts' ) . ' WHERE ' . self::PENDING_SQL . ' AND id > %d' . ( null === $year ? '' : ' AND year = %d' ) . ' ORDER BY id LIMIT %d';
		$args   = null === $year ? array( $after_id, $limit ) : array( $after_id, $year, $limit );
		$rows   = $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condizione costante interna
		$done   = 0;
		$failed = 0;
		$last   = $after_id;
		foreach ( $rows as $r ) {
			$last = (int) $r['id'];
			try {
				if ( Receipts::fix_pdfs( $r ) ) {
					$done++;
				} else {
					$failed++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
			}
		}
		return array( 'done' => $done, 'failed' => $failed, 'last_id' => $last, 'more' => count( $rows ) >= $limit );
	}

	/** A chiusura avvenuta di un anno fiscale: si fissa il primo lotto subito, il resto con un lavoro programmato (mai tutto in una richiesta). */
	public static function on_year_closed( $year ): void {
		if ( ! Edition::has( 'receipts' ) ) {
			return;
		}
		try {
			self::run_batch( (int) $year, 0 );
		} catch ( \Throwable $e ) {
			error_log( 'Associazione Semplice: PDF delle ricevute non fissati alla chiusura dell\'anno: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Lavoro programmato: il lotto seguente. */
	public static function cron_batch( $year = 0, $after = 0 ): void {
		if ( ! Edition::has( 'receipts' ) || (int) $year <= 0 ) {
			return;
		}
		try {
			self::run_batch( (int) $year, (int) $after );
		} catch ( \Throwable $e ) {
			error_log( 'Associazione Semplice: lotto dei PDF delle ricevute non riuscito: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private static function run_batch( int $year, int $after ): void {
		$r = self::complete( $year, $after );
		if ( $r['done'] || $r['failed'] ) {
			Audit::log( 'receipt.pdfs_fixed', 'year', $year, array( 'done' => $r['done'], 'failed' => $r['failed'] ) );
		}
		if ( $r['more'] && ! wp_next_scheduled( self::CRON, array( $year, $r['last_id'] ) ) ) {
			wp_schedule_single_event( time() + 20, self::CRON, array( $year, $r['last_id'] ) );
		}
	}

	// ---------- Verifica ----------

	/** Anni (fiscali) che hanno ricevute emesse, dal più recente. @return int[] */
	public static function years(): array {
		return array_map( 'intval', self::db()->get_col( 'SELECT DISTINCT year FROM ' . Db::t( 'receipts' ) . ' ORDER BY year DESC' ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno, nessun valore esterno
	}

	/**
	 * Controlla le impronte dei PDF fissati di un anno fiscale.
	 *
	 * @return array{summary:array<string,int>,problems:array[],truncated:bool} stati: ok, pending (da fissare), nodata (emessa prima della fotografia dei dati),
	 *         missing (file mancante), altered (impronta diversa); per ogni ricevuta con problemi: key, label, state, detail (tipo => stato)
	 */
	public static function verify( int $year, int $cap = 20000 ): array {
		$db   = self::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT id, receipt_key, year, number, voided_at, pdf_path, pdf_sha256, void_pdf_path, void_pdf_sha256, ( data IS NOT NULL AND data <> \'\' ) AS has_data FROM ' . Db::t( 'receipts' ) . ' WHERE year = %d ORDER BY number LIMIT %d',
				$year,
				$cap
			),
			ARRAY_A
		) ?: array();
		$rank = array( 'ok' => 0, 'pending' => 1, 'nodata' => 2, 'missing' => 3, 'altered' => 4 );
		$sum  = array( 'total' => 0, 'ok' => 0, 'pending' => 0, 'nodata' => 0, 'missing' => 0, 'altered' => 0 );
		$bad  = array();
		foreach ( $rows as $r ) {
			$sum['total']++;
			$worst  = 'ok';
			$detail = array();
			foreach ( null !== $r['voided_at'] ? self::kinds() : array( self::ISSUED ) as $kind ) {
				$st = self::state( $r, $kind );
				if ( 'none' === $st ) {
					$st = (int) $r['has_data'] ? 'pending' : 'nodata';
				}
				if ( 'ok' !== $st ) {
					$detail[ $kind ] = $st;
				}
				if ( $rank[ $st ] > $rank[ $worst ] ) {
					$worst = $st;
				}
			}
			$sum[ $worst ]++;
			if ( 'ok' !== $worst ) {
				$bad[] = array( 'key' => (string) $r['receipt_key'], 'label' => (int) $r['number'] . '/' . (int) $r['year'], 'state' => $worst, 'detail' => $detail );
			}
		}
		return array( 'summary' => $sum, 'problems' => $bad, 'truncated' => count( $rows ) >= $cap );
	}

	/**
	 * Un file ZIP con i PDF fissati (e integri) di un anno fiscale, per archiviarli fuori dal sito.
	 *
	 * @return array{path:string,count:int} percorso del file temporaneo (da cancellare dopo l'uso)
	 * @throws \RuntimeException
	 */
	public static function zip_year( int $year ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( Texts::t( 'Questo server non può creare file ZIP (manca l\'estensione zip di PHP).' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'receipts' ) . ' WHERE year = %d AND ( pdf_path IS NOT NULL OR void_pdf_path IS NOT NULL ) ORDER BY number', $year ), ARRAY_A ) ?: array();
		$path = wp_tempnam( 'asem-ricevute' );
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( Texts::t( 'Impossibile creare il file ZIP.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$n = 0;
		foreach ( $rows as $r ) {
			foreach ( self::kinds() as $kind ) {
				if ( 'ok' !== self::state( $r, $kind ) ) {
					continue; // un file mancante o alterato non entra nell'archivio: lo segnala la verifica
				}
				$c = self::columns( $kind );
				$zip->addFile( self::abs_path( (string) $r[ $c[0] ] ), sprintf( 'ricevute-%d/ricevuta-%d-%d%s.pdf', $year, (int) $r['number'], (int) $r['year'], self::VOID === $kind ? '-annullata' : '' ) );
				$n++;
			}
		}
		$zip->addFromString( 'LEGGIMI.txt', "PDF definitivi delle ricevute dell'anno fiscale $year, così come sono stati emessi. Le impronte SHA-256 sono nel database del sito (e nelle copie di sicurezza).\n" );
		$ok = $zip->close();
		if ( ! $ok || ! is_file( $path ) ) {
			throw new \RuntimeException( Texts::t( 'Creazione del file ZIP non riuscita.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( 'path' => $path, 'count' => $n );
	}

	// ---------- Copia di sicurezza e ripristino ----------

	/**
	 * Filtro `asem_backup_documents`: i PDF fissati e integri, da mettere nella copia di sicurezza.
	 *
	 * @param array $docs
	 * @return array[] name (percorso nello ZIP), path (sul disco), sha256
	 */
	public static function backup_documents( $docs ): array {
		$docs = (array) $docs;
		$db   = self::db();
		$last = 0;
		do {
			$rows = $db->get_results( $db->prepare( 'SELECT id, year, number, pdf_path, pdf_sha256, void_pdf_path, void_pdf_sha256 FROM ' . Db::t( 'receipts' ) . ' WHERE id > %d AND ( pdf_path IS NOT NULL OR void_pdf_path IS NOT NULL ) ORDER BY id LIMIT 500', $last ), ARRAY_A ) ?: array();
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				foreach ( self::kinds() as $kind ) {
					if ( 'ok' !== self::state( $r, $kind ) ) { // un file alterato non va in una copia: il ripristino lo rifiuterebbe comunque
						continue;
					}
					$c      = self::columns( $kind );
					$docs[] = array( 'name' => 'documenti/' . $r[ $c[0] ], 'path' => self::abs_path( (string) $r[ $c[0] ] ), 'sha256' => (string) $r[ $c[1] ] );
				}
			}
		} while ( count( $rows ) >= 500 );
		return $docs;
	}

	/**
	 * Filtro `asem_backup_restore_documents`: dopo il ripristino delle tabelle, rimette sul disco i PDF fissati. I percorsi si leggono dal DATABASE
	 * appena ripristinato (mai dai nomi dentro lo ZIP) e un file si accetta solo se la sua impronta coincide con quella registrata.
	 *
	 * @param array $res counters: restored, present, missing, bad
	 * @return array
	 */
	public static function restore_documents( $res, $zip, $manifest = array() ): array {
		$res = array_merge( array( 'restored' => 0, 'present' => 0, 'missing' => 0, 'bad' => 0 ), (array) $res );
		if ( ! $zip instanceof \ZipArchive ) {
			return $res;
		}
		$db   = self::db();
		$last = 0;
		do {
			$rows = $db->get_results( $db->prepare( 'SELECT id, year, number, pdf_path, pdf_sha256, void_pdf_path, void_pdf_sha256 FROM ' . Db::t( 'receipts' ) . ' WHERE id > %d AND ( pdf_path IS NOT NULL OR void_pdf_path IS NOT NULL ) ORDER BY id LIMIT 500', $last ), ARRAY_A ) ?: array();
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				foreach ( self::kinds() as $kind ) {
					if ( ! self::has( $r, $kind ) ) {
						continue;
					}
					$c   = self::columns( $kind );
					$rel = (string) $r[ $c[0] ];
					$abs = self::abs_path( $rel );
					if ( '' === $abs ) {
						$res['bad']++;
						continue;
					}
					if ( 'ok' === self::state( $r, $kind ) ) { // già al suo posto e identico
						$res['present']++;
						continue;
					}
					$idx = $zip->locateName( 'documenti/' . $rel );
					if ( false === $idx ) {
						$res['missing']++;
						continue;
					}
					$st = $zip->statIndex( $idx );
					if ( ! $st || (int) $st['size'] > self::MAX_SIZE ) {
						$res['bad']++;
						continue;
					}
					$bytes = $zip->getFromIndex( $idx );
					if ( false === $bytes || ! hash_equals( (string) $r[ $c[1] ], hash( 'sha256', $bytes ) ) ) {
						$res['bad']++;
						continue;
					}
					try {
						if ( ! self::prepare_dir( $abs ) ) {
							$res['bad']++;
							continue;
						}
					} catch ( \Throwable $e ) {
						$res['bad']++;
						continue;
					}
					if ( is_file( $abs ) && ! @rename( $abs, $abs . '.alterato-' . gmdate( 'YmdHis' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- il file diverso si conserva
						$res['bad']++;
						continue;
					}
					if ( self::write_new( $abs, $bytes ) ) {
						$res['restored']++;
					} else {
						$res['bad']++;
					}
				}
			}
		} while ( count( $rows ) >= 500 );
		return $res;
	}

	/** Azzeramento dei dati: i PDF delle ricevute (dati personali) vanno via con le ricevute. @return int file cancellati */
	public static function delete_all(): int {
		$root = rtrim( Attachments::dir(), '/\\' ) . '/' . self::SUBDIR;
		if ( ! is_dir( $root ) ) {
			return 0;
		}
		$n = 0;
		foreach ( glob( $root . '/*' ) ?: array() as $d ) {
			if ( is_dir( $d ) ) {
				foreach ( glob( $d . '/*' ) ?: array() as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
						$n++;
					}
				}
				@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir -- cartella privata del plugin
			} elseif ( is_file( $d ) ) {
				wp_delete_file( $d );
				$n++;
			}
		}
		@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir -- cartella privata del plugin
		return $n;
	}
}

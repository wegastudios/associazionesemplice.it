<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- lavoro su file privati

/**
 * Cifratura dei file già caricati (prima che la cifratura esistesse). A lotti, nell'ordine delle fonti (prima i certificati medici, poi gli altri):
 * per ogni file si scrive la versione cifrata in un file temporaneo, la si riapre per controllare che l'impronta coincida, e solo allora si rinomina al
 * posto dell'originale e si segna `enc_ver` nel database. Si può interrompere in ogni momento senza perdere nulla e ripetere: un file che ha già
 * l'intestazione si salta (dopo averne controllato il contenuto).
 */
final class FileMigration {

	const OPT      = 'asem_file_migration';
	const HOOK     = 'asem_file_crypt_migrate';
	const BATCH    = 100;
	const MAX_SECS = 15;
	const EVERY    = 60; // secondi fra un lotto programmato e il successivo

	// ---------- Stato ----------

	/** @return array{running:bool,started:int,last:int,done:int,already:int,failed:int,missing:int,cursor:array} */
	public static function state(): array {
		$s = get_option( self::OPT );
		$s = is_array( $s ) ? $s : array();
		return array(
			'running' => ! empty( $s['running'] ),
			'started' => (int) ( $s['started'] ?? 0 ),
			'last'    => (int) ( $s['last'] ?? 0 ),
			'done'    => (int) ( $s['done'] ?? 0 ),
			'already' => (int) ( $s['already'] ?? 0 ),
			'failed'  => (int) ( $s['failed'] ?? 0 ),
			'missing' => (int) ( $s['missing'] ?? 0 ),
			'cursor'  => isset( $s['cursor'] ) && is_array( $s['cursor'] ) ? $s['cursor'] : array(),
		);
	}

	private static function save( array $s ): void {
		update_option( self::OPT, $s, false );
	}

	/** Conteggi per la pagina: file ancora in chiaro e campi ancora in chiaro; file già cifrati. @return array{pending:int,fields:int,encrypted:int} */
	public static function counts(): array {
		$db  = Db::db();
		$out = array( 'pending' => 0, 'fields' => 0, 'encrypted' => 0 );
		foreach ( PrivateFiles::sources() as $s ) {
			$t     = '`' . Db::t( $s['table'] ) . '`';
			$st    = '`' . $s['stored'] . '`';
			$en    = '`' . $s['enc'] . '`';
			$out['pending']   += (int) $db->get_var( "SELECT COUNT(*) FROM $t WHERE $en IS NULL AND $st IS NOT NULL AND $st <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nomi di tabella e colonna fissi del plugin
			$out['encrypted'] += (int) $db->get_var( "SELECT COUNT(*) FROM $t WHERE $en IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- come sopra
			foreach ( $s['fields'] as $col ) {
				$out['fields'] += (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $t WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE %s", $db->esc_like( FileCrypt::FIELD_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
			}
		}
		return $out;
	}

	/**
	 * I file che risultano ancora in chiaro (o non trovati sul disco), per l'elenco della pagina.
	 *
	 * @return array<int,array{key:string,id:int,stored:string,exists:bool}>
	 */
	public static function unencrypted( int $limit = 50 ): array {
		$db  = Db::db();
		$out = array();
		$dir = Attachments::dir();
		foreach ( PrivateFiles::sources() as $s ) {
			$left = $limit - count( $out );
			if ( $left <= 0 ) {
				break;
			}
			$t    = '`' . Db::t( $s['table'] ) . '`';
			$st   = '`' . $s['stored'] . '`';
			$en   = '`' . $s['enc'] . '`';
			$rows = $db->get_results( $db->prepare( "SELECT id, $st AS st FROM $t WHERE $en IS NULL AND $st IS NOT NULL AND $st <> '' ORDER BY id LIMIT %d", $left ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
			foreach ( $rows as $r ) {
				$out[] = array( 'key' => (string) $s['key'], 'id' => (int) $r['id'], 'stored' => (string) $r['st'], 'exists' => is_file( $dir . '/' . basename( (string) $r['st'] ) ) );
			}
		}
		return $out;
	}

	// ---------- Avvio e lotti ----------

	/** Comincia: azzera il conteggio, fa il primo lotto e programma i successivi. */
	public static function start(): array {
		self::save( array( 'running' => true, 'started' => time(), 'last' => 0, 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'cursor' => array() ) );
		$r = self::run_batch();
		if ( self::state()['running'] ) {
			self::schedule();
		}
		return $r;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::EVERY, self::HOOK );
		}
	}

	/** Lavoro programmato: un lotto, poi se resta altro se ne programma un altro. */
	public static function cron(): void {
		if ( ! self::state()['running'] ) {
			return;
		}
		self::run_batch();
		if ( self::state()['running'] ) {
			self::schedule();
		}
	}

	/**
	 * Un lotto di lavoro (al massimo BATCH righe e MAX_SECS secondi). Non lancia errori: chi non si può cifrare resta in chiaro e si conta.
	 *
	 * @return array{done:int,already:int,failed:int,missing:int,finished:bool,busy:bool}
	 */
	public static function run_batch( int $max = self::BATCH ): array {
		$res = array( 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'finished' => false, 'busy' => false );
		if ( 'encrypt' !== FileKey::mode() ) {
			return $res; // senza libreria o con la chiave che non coincide non si tocca nulla
		}
		try {
			Db::with_lock(
				'asem_file_migration',
				function () use ( &$res, $max ): void {
					$res = self::batch( $max );
				},
				0
			);
		} catch ( \InvalidArgumentException $e ) {
			$res['busy'] = true; // un altro lotto è in corso
		}
		return $res;
	}

	private static function batch( int $max ): array {
		$res = array( 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'finished' => false, 'busy' => false );
		$s   = self::state();
		self::clean_partials();
		$db    = Db::db();
		$start = time();
		$left  = $max;
		foreach ( PrivateFiles::sources() as $src ) {
			$t   = '`' . Db::t( $src['table'] ) . '`';
			$key = (string) $src['key'];
			// 1) campi di testo ancora in chiaro
			foreach ( $src['fields'] as $col ) {
				if ( $left <= 0 || time() - $start > self::MAX_SECS ) {
					break 2;
				}
				$rows = $db->get_results( $db->prepare( "SELECT id, `$col` AS v FROM $t WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE %s ORDER BY id LIMIT %d", $db->esc_like( FileCrypt::FIELD_PREFIX ) . '%', $left ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
				foreach ( $rows as $r ) {
					$enc = PrivateFiles::field_encrypt( (string) $src['table'], (string) $col, (int) $r['id'], (string) $r['v'] );
					if ( $enc !== (string) $r['v'] ) {
						$db->update( Db::t( $src['table'] ), array( $col => $enc ), array( 'id' => (int) $r['id'] ) );
						$res['done']++;
					} else {
						$res['failed']++;
					}
					$left--;
				}
			}
			// 2) file ancora in chiaro, dopo l'ultimo già visto
			$cursor = (int) ( $s['cursor'][ $key ] ?? 0 );
			while ( $left > 0 && time() - $start <= self::MAX_SECS ) {
				$rows = $db->get_results( $db->prepare( "SELECT id, `{$src['stored']}` AS st, `{$src['sha']}` AS sh FROM $t WHERE `{$src['enc']}` IS NULL AND `{$src['stored']}` IS NOT NULL AND `{$src['stored']}` <> '' AND id > %d ORDER BY id LIMIT %d", $cursor, min( $left, 10 ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $r ) {
					$cursor = (int) $r['id'];
					$left--;
					$o = self::encrypt_one( (string) $r['st'], (string) $r['sh'] );
					if ( 'ok' === $o['status'] || 'already' === $o['status'] ) {
						$upd = array( $src['enc'] => PrivateFiles::ENC_VER );
						if ( '' === (string) $r['sh'] ) {
							$upd[ $src['sha'] ] = $o['sha256'];
						}
						$db->update( Db::t( $src['table'] ), $upd, array( 'id' => (int) $r['id'] ) );
						$res[ 'ok' === $o['status'] ? 'done' : 'already' ]++;
					} elseif ( 'missing' === $o['status'] ) {
						$res['missing']++;
					} else {
						$res['failed']++;
					}
				}
				$s['cursor'][ $key ] = $cursor;
			}
		}
		// finito quando in nessuna fonte restano righe dopo il cursore e nessun campo in chiaro
		$more = false;
		foreach ( PrivateFiles::sources() as $src ) {
			$t = '`' . Db::t( $src['table'] ) . '`';
			if ( (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $t WHERE `{$src['enc']}` IS NULL AND `{$src['stored']}` IS NOT NULL AND `{$src['stored']}` <> '' AND id > %d", (int) ( $s['cursor'][ (string) $src['key'] ] ?? 0 ) ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
				$more = true;
			}
			foreach ( $src['fields'] as $col ) {
				if ( (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $t WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE %s", $db->esc_like( FileCrypt::FIELD_PREFIX ) . '%' ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella e colonna fissi del plugin
					$more = true;
				}
			}
		}
		$s['done']    += $res['done'];
		$s['already'] += $res['already'];
		$s['failed']  += $res['failed'];
		$s['missing'] += $res['missing'];
		$s['last']     = time();
		$s['running']  = $more;
		self::save( $s );
		$res['finished'] = ! $more;
		return $res;
	}

	/** Cancella i file temporanei lasciati da un lotto interrotto (più vecchi di un'ora). */
	private static function clean_partials(): void {
		foreach ( glob( Attachments::dir() . '/*.part-*' ) ?: array() as $f ) {
			if ( is_file( $f ) && (int) filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/**
	 * Cifra un file già sul disco, al suo posto.
	 *
	 * @param string $stored nome del file nella cartella riservata
	 * @param string $sha    impronta registrata del contenuto in chiaro (vuota se non c'è)
	 * @return array{status:string,sha256:string} status: ok, already, missing, badhash, error
	 */
	public static function encrypt_one( string $stored, string $sha ): array {
		$name = AttachmentRules::stored_name( $stored );
		if ( '' === $name ) {
			return array( 'status' => 'missing', 'sha256' => '' );
		}
		$path = Attachments::dir() . '/' . $name;
		if ( ! is_file( $path ) ) {
			return array( 'status' => 'missing', 'sha256' => '' );
		}
		if ( FileCrypt::is_encrypted_file( $path ) ) { // già cifrato (lotto interrotto prima di segnarlo nel database): si controlla e basta
			try {
				$v = PrivateFiles::verify( $path, $name, $sha );
				return array( 'status' => 'already', 'sha256' => $v['sha256'] );
			} catch ( FileCryptError $e ) {
				if ( FileCryptError::FORMAT !== $e->reason ) {
					return array( 'status' => 'error', 'sha256' => '' ); // non si apre: non si tocca e non si «ricifra»
				}
				// intestazione non valida: è un file in chiaro che comincia per caso con quel byte, si cifra
			}
		}
		$plain = (string) hash_file( 'sha256', $path );
		if ( '' === $plain ) {
			return array( 'status' => 'error', 'sha256' => '' );
		}
		if ( '' !== $sha && ! hash_equals( strtolower( $sha ), $plain ) ) {
			return array( 'status' => 'badhash', 'sha256' => $plain ); // il file non è più quello registrato: non si cifra in silenzio
		}
		$key = FileKey::current();
		if ( null === $key ) {
			return array( 'status' => 'error', 'sha256' => '' );
		}
		$tmp = $path . '.part-' . bin2hex( random_bytes( 4 ) );
		try {
			$r = FileCrypt::encrypt_file( $path, $tmp, $key['key'], $key['id'], PrivateFiles::ad( $name ) );
			if ( $r['sha256'] !== $plain ) {
				throw new FileCryptError( FileCryptError::HASH );
			}
			$check = FileCrypt::decrypt_file( $tmp, null, PrivateFiles::key_for(), PrivateFiles::ad( $name ) ); // si riapre la versione cifrata prima di sostituire l'originale
			if ( $check['sha256'] !== $plain ) {
				throw new FileCryptError( FileCryptError::HASH );
			}
		} catch ( \Throwable $e ) {
			wp_delete_file( $tmp );
			return array( 'status' => 'error', 'sha256' => '' );
		}
		chmod( $tmp, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file privato dell'ente: lettura solo per il server
		if ( ! rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
			return array( 'status' => 'error', 'sha256' => '' );
		}
		return array( 'status' => 'ok', 'sha256' => $plain );
	}
}

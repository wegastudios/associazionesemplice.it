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

	// Certificati medici (dati sulla salute): dopo l'aggiornamento si cifrano da soli, una volta, con lo stesso meccanismo a lotti ma con uno stato a parte.
	const CERT_OPT    = 'asem_file_migration_certs';
	const CERT_HOOK   = 'asem_file_crypt_migrate_certs';
	const CERT_CHECK  = 'asem_file_crypt_certs_check'; // controllo orario: se qualcosa si è fermato o è andato perso, riparte
	const CERT_KEY    = 'medical_certs';                // chiave della fonte (la voce la aggiunge chi gestisce i certificati)
	const CERT_NOTICE = 14;                             // giorni in cui resta l'avviso di fine

	// ---------- Stato ----------

	/** @return array{running:bool,started:int,last:int,done:int,already:int,failed:int,missing:int,cursor:array} */
	public static function state( string $opt = self::OPT ): array {
		$s = get_option( $opt );
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
			'status'  => (string) ( $s['status'] ?? '' ), // solo per i certificati: '', running, done, nosodium, nokey
			'finished' => (int) ( $s['finished'] ?? 0 ),
			'logged'  => (string) ( $s['logged'] ?? '' ), // ultimo blocco scritto nel registro (per non ripeterlo)
		);
	}

	private static function save( array $s, string $opt = self::OPT ): void {
		update_option( $opt, $s, self::CERT_OPT === $opt ); // quello dei certificati si legge a ogni richiesta: resta caricato
	}

	/** Conteggi per la pagina: file ancora in chiaro e campi ancora in chiaro; file già cifrati. @return array{pending:int,fields:int,encrypted:int} */
	public static function counts( ?string $only = null ): array {
		$db  = Db::db();
		$out = array( 'pending' => 0, 'fields' => 0, 'encrypted' => 0 );
		foreach ( PrivateFiles::sources() as $s ) {
			if ( null !== $only && (string) $s['key'] !== $only ) {
				continue;
			}
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
				$rel   = PrivateFiles::rel_of( (string) $r['st'], $s );
				$out[] = array( 'key' => (string) $s['key'], 'id' => (int) $r['id'], 'stored' => (string) $r['st'], 'exists' => '' !== $rel && is_file( $dir . '/' . $rel ) );
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

	// ---------- Certificati medici: partenza automatica ----------

	/** Certificati ancora da cifrare (file in chiaro; se non ce ne sono, righe con campi in chiaro). */
	public static function certs_left(): int {
		$n = self::counts( self::CERT_KEY );
		return $n['pending'] > 0 ? $n['pending'] : $n['fields'];
	}

	/**
	 * Controllo per la cifratura automatica dei certificati medici già caricati (dati sulla salute): una volta sola, dopo l'aggiornamento, senza premere
	 * nulla. Si chiama dalle pagine di amministrazione e da un controllo orario; a lavoro finito (o senza nulla da cifrare) costa la lettura di
	 * un'opzione. Senza libreria o con la chiave che non si apre non tocca nulla e lo ricorda per l'avviso; appena il blocco cade, parte.
	 */
	public static function auto_certs(): void {
		$s = self::state( self::CERT_OPT );
		if ( 'done' === $s['status'] ) {
			return;
		}
		if ( 'running' === $s['status'] ) {
			if ( ! wp_next_scheduled( self::CERT_HOOK ) ) { // il lavoro programmato è andato perso: si riprende da dove era
				self::schedule_certs();
			}
			return;
		}
		if ( ( 'nosodium' === $s['status'] && ! FileCrypt::available() ) || ( 'nokey' === $s['status'] && 'encrypt' !== FileKey::mode() ) ) {
			return; // ancora bloccato: niente da fare finché non viene sistemato (l'avviso resta)
		}
		if ( '' === $s['status'] && (int) $s['last'] > time() ) {
			return; // controllato da poco, nessuna fonte dei certificati: si riprova più tardi
		}
		$has = false;
		foreach ( PrivateFiles::sources() as $src ) {
			if ( self::CERT_KEY === (string) $src['key'] ) {
				$has = true;
			}
		}
		if ( ! $has ) { // i certificati non ci sono in questa edizione (o la loro parte è spenta): si riguarda fra un giorno
			$s['last'] = time() + DAY_IN_SECONDS;
			self::save( $s, self::CERT_OPT );
			return;
		}
		if ( 0 === self::certs_left() ) {
			$s['status']   = 'done'; // niente da cifrare: non si ricontrolla più (i nuovi certificati si cifrano già al caricamento)
			$s['finished'] = 0;
			self::save( $s, self::CERT_OPT );
			return;
		}
		if ( ! FileCrypt::available() ) {
			self::block_certs( $s, 'nosodium' );
			return;
		}
		if ( 'encrypt' !== FileKey::mode() ) {
			self::block_certs( $s, 'nokey' );
			return;
		}
		self::save( array( 'running' => true, 'started' => time(), 'last' => 0, 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'cursor' => array(), 'status' => 'running', 'finished' => 0, 'logged' => '' ), self::CERT_OPT );
		Audit::log( 'file_crypt.certs_auto_started', 'folder', 0, array( 'left' => self::certs_left() ) );
		self::schedule_certs( 30 );
	}

	/** Ricorda che la cifratura dei certificati è ferma (libreria assente o chiave che non si apre); lo scrive nel registro una volta sola. */
	private static function block_certs( array $s, string $why ): void {
		$s['status']  = $why;
		$s['running'] = false;
		if ( $s['logged'] !== $why ) {
			Audit::log( 'file_crypt.certs_auto_blocked', 'folder', 0, array( 'reason' => $why ) );
			$s['logged'] = $why;
		}
		self::save( $s, self::CERT_OPT );
	}

	public static function schedule_certs( int $in = self::EVERY ): void {
		if ( ! wp_next_scheduled( self::CERT_HOOK ) ) {
			wp_schedule_single_event( time() + $in, self::CERT_HOOK );
		}
	}

	/** Lavoro programmato dei certificati: un lotto dei soli certificati, poi o se ne programma un altro o si chiude. */
	public static function certs_cron(): void {
		$s = self::state( self::CERT_OPT );
		if ( 'running' !== $s['status'] ) {
			return;
		}
		if ( 'encrypt' !== FileKey::mode() ) { // la chiave non si apre più o la libreria è sparita: si ferma, senza toccare nulla
			self::block_certs( $s, FileCrypt::available() ? 'nokey' : 'nosodium' );
			return;
		}
		$r = self::run_batch( self::BATCH, self::CERT_KEY );
		$s = self::state( self::CERT_OPT );
		if ( $r['busy'] || $s['running'] ) {
			self::schedule_certs();
			return;
		}
		$s['status']   = 'done';
		$s['finished'] = time();
		self::save( $s, self::CERT_OPT );
		Audit::log( 'file_crypt.certs_auto_finished', 'folder', 0, array( 'done' => $s['done'] + $s['already'], 'failed' => $s['failed'], 'missing' => $s['missing'] ) );
	}

	/**
	 * Un lotto di lavoro (al massimo BATCH righe e MAX_SECS secondi). Non lancia errori: chi non si può cifrare resta in chiaro e si conta.
	 *
	 * @return array{done:int,already:int,failed:int,missing:int,finished:bool,busy:bool}
	 */
	public static function run_batch( int $max = self::BATCH, ?string $only = null ): array {
		$res = array( 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'finished' => false, 'busy' => false );
		if ( 'encrypt' !== FileKey::mode() ) {
			return $res; // senza libreria o con la chiave che non coincide non si tocca nulla
		}
		try {
			Db::with_lock(
				'asem_file_migration',
				function () use ( &$res, $max, $only ): void {
					$res = self::batch( $max, $only );
				},
				0
			);
		} catch ( \InvalidArgumentException $e ) {
			$res['busy'] = true; // un altro lotto è in corso
		}
		return $res;
	}

	private static function batch( int $max, ?string $only = null ): array {
		$res = array( 'done' => 0, 'already' => 0, 'failed' => 0, 'missing' => 0, 'finished' => false, 'busy' => false );
		$opt = null === $only ? self::OPT : self::CERT_OPT; // il lavoro dei soli certificati ha il suo stato
		$s   = self::state( $opt );
		self::clean_partials();
		$db    = Db::db();
		$start = time();
		$left  = $max;
		foreach ( PrivateFiles::sources() as $src ) {
			if ( null !== $only && (string) $src['key'] !== $only ) {
				continue;
			}
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
					$o = self::encrypt_one( (string) $r['st'], (string) $r['sh'], $src );
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
			if ( null !== $only && (string) $src['key'] !== $only ) {
				continue;
			}
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
		self::save( $s, $opt );
		$res['finished'] = ! $more;
		return $res;
	}

	/** Cancella i file temporanei lasciati da un lotto interrotto (più vecchi di un'ora). */
	private static function clean_partials(): void {
		foreach ( array_merge( glob( Attachments::dir() . '/*.part-*' ) ?: array(), glob( Attachments::dir() . '/receipts/*/*.part-*' ) ?: array() ) as $f ) {
			if ( is_file( $f ) && (int) filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/**
	 * Cifra un file già sul disco, al suo posto.
	 *
	 * @param string     $stored nome del file nella cartella riservata (o percorso relativo, per le fonti con sottocartelle)
	 * @param array|null $src    voce della fonte (serve a sapere come è fatto il percorso)
	 * @param string $sha    impronta registrata del contenuto in chiaro (vuota se non c'è)
	 * @return array{status:string,sha256:string} status: ok, already, missing, badhash, error
	 */
	public static function encrypt_one( string $stored, string $sha, ?array $src = null ): array {
		$name = PrivateFiles::rel_of( $stored, $src );
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

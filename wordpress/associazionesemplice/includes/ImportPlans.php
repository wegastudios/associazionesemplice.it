<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dove sta l'anteprima di un'importazione e, una volta avviata, il suo piano.
 *
 * Un file con migliaia di righe dà un'anteprima di 5-10 MB: troppo per una riga della tabella delle opzioni (il pacchetto massimo
 * predefinito di MySQL 5.7 è 4 MB e la scrittura fallirebbe in silenzio) e per essere riletta a ogni richiesta. Per questo l'anteprima
 * e il piano stanno in un file nella cartella riservata ({@see PrivateFolder}, sottocartella `import`), mai raggiungibile dal web.
 * Se la cartella non è scrivibile si ripiega sul modo di prima (un transiente per l'anteprima, un'opzione per il piano), che regge i file piccoli.
 *
 * - L'anteprima è per utente e per codice, vale un'ora.
 * - Il piano è uno solo (un'importazione per volta) e resta finché l'importazione non finisce o non viene fermata.
 * - I file dimenticati (importazione mai finita, pagina chiusa) si tolgono da soli: l'anteprima dopo un giorno, il piano dopo otto.
 */
final class ImportPlans {

	const DIR_NAME = 'import';
	const PREVIEW_TTL = HOUR_IN_SECONDS;

	/** Il nome del transiente di un'anteprima, com'era prima (resta per il ripiego e per chi lo scrive a mano nelle prove). */
	public static function transient_name( int $user_id, string $token ): string {
		return 'asem_import_' . $user_id . '_' . sanitize_key( $token );
	}

	// ---------- Anteprima ----------

	/** Salva l'anteprima di un file appena letto. */
	public static function save_preview( int $user_id, string $token, array $preview ): void {
		$token = sanitize_key( $token );
		if ( '' !== $token && self::write( 'p-' . $user_id . '-' . $token, $preview ) ) {
			return;
		}
		set_transient( self::transient_name( $user_id, $token ), $preview, self::PREVIEW_TTL );
	}

	/** L'anteprima, se c'è ancora (e non è più vecchia di un'ora). @return array|null */
	public static function load_preview( int $user_id, string $token ): ?array {
		$token = sanitize_key( $token );
		if ( '' === $token ) {
			return null;
		}
		$p = self::read( 'p-' . $user_id . '-' . $token, self::PREVIEW_TTL );
		if ( null !== $p ) {
			return $p;
		}
		$t = get_transient( self::transient_name( $user_id, $token ) );
		return is_array( $t ) ? $t : null;
	}

	public static function drop_preview( int $user_id, string $token ): void {
		$token = sanitize_key( $token );
		if ( '' !== $token ) {
			self::remove( 'p-' . $user_id . '-' . $token );
		}
		delete_transient( self::transient_name( $user_id, $token ) );
	}

	// ---------- Piano dell'importazione in corso ----------

	/** @param array $plan l'anteprima su cui si lavora; scritta una volta all'avvio */
	public static function save_plan( array $plan ): void {
		if ( self::write( 'plan', $plan ) ) {
			delete_option( ImportJob::OPT_PLAN );
			return;
		}
		update_option( ImportJob::OPT_PLAN, $plan, false );
	}

	/** @return array|null null se non c'è più */
	public static function load_plan(): ?array {
		$p = self::read( 'plan', 0 );
		if ( null !== $p ) {
			return $p;
		}
		$o = get_option( ImportJob::OPT_PLAN, null );
		return is_array( $o ) ? $o : null;
	}

	public static function drop_plan(): void {
		self::remove( 'plan' );
		delete_option( ImportJob::OPT_PLAN );
	}

	// ---------- File ----------

	/** La cartella dei file di importazione, creata e protetta; stringa vuota se non si può. */
	private static function dir(): string {
		$dir = PrivateFolder::dir() . '/' . self::DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		PrivateFolder::protect( $dir );
		return is_dir( $dir ) ? $dir : '';
	}

	private static function path( string $name ): string {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . preg_replace( '/[^a-z0-9-]/', '', strtolower( $name ) ) . '.dat';
	}

	/** Scrive il file (prima su un nome provvisorio, poi lo rinomina: chi legge non vede mai un file a metà). */
	private static function write( string $name, array $data ): bool {
		$path = self::path( $name );
		if ( '' === $path ) {
			return false;
		}
		self::clean();
		$tmp = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$ok  = false !== @file_put_contents( $tmp, serialize( $data ), LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- file riservato, solo dati del plugin
		if ( $ok ) {
			@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			$ok = @rename( $tmp, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		}
		if ( ! $ok ) {
			wp_delete_file( $tmp );
		}
		return $ok;
	}

	/** @param int $ttl 0 = senza scadenza */
	private static function read( string $name, int $ttl ): ?array {
		$path = self::path( $name );
		if ( '' === $path || ! is_file( $path ) ) {
			return null;
		}
		if ( $ttl > 0 && time() - (int) filemtime( $path ) > $ttl ) {
			wp_delete_file( $path );
			return null;
		}
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file riservato
		$d   = is_string( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- nostro file, nessuna classe ammessa
		return is_array( $d ) ? $d : null;
	}

	private static function remove( string $name ): void {
		$path = self::path( $name );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** Toglie i file dimenticati: le anteprime dopo un giorno, il piano dopo otto. */
	private static function clean(): void {
		$dir = PrivateFolder::dir() . '/' . self::DIR_NAME;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name || 'index.php' === $name || ! is_file( $dir . '/' . $name ) ) {
				continue;
			}
			$age = time() - (int) filemtime( $dir . '/' . $name );
			if ( ( 0 === strpos( $name, 'plan' ) ? 8 * DAY_IN_SECONDS : DAY_IN_SECONDS ) < $age ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
	}
}

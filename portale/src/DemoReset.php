<?php
namespace Portale;

/**
 * Tasti «Reset demo» della gestione (amministratori e supporto). Il portale e le demo stanno sullo stesso server e sullo stesso account: il portale
 * lancia direttamente lo script, senza chiavi né SSH. Il comando non si sceglie da pagina: sta in config.php (`demo_reset`), come lista di argomenti
 * (percorso assoluto dello script e opzioni), mai una riga libera. Il reset gira in background: un solo reset alla volta per demo, l'esito si legge qui.
 *
 * config.php:  'demo_reset' => array( 'plugin' => array( 'cmd' => array( '/bin/bash', '/home/…/demo-provision/reset.sh', '--forza' ), 'note' => '…' ) )
 */
final class DemoReset {

	/** Demo che si possono azzerare e come si chiamano a schermo. */
	const TARGETS = array(
		'plugin' => 'Demo del plugin',
		'saas'   => 'Demo del gestionale SaaS',
	);

	/** Oltre questo tempo un blocco rimasto si considera orfano (il reset non dura così). */
	const STALE = 7200;

	/** Le demo configurate: chiave => array( 'label', 'cmd' (lista di argomenti), 'note' ). */
	public static function targets(): array {
		$out = array();
		foreach ( (array) Config::get( 'demo_reset', array() ) as $key => $t ) {
			$cmd = is_array( $t ) ? ( $t['cmd'] ?? array() ) : array();
			if ( ! isset( self::TARGETS[ $key ] ) || ! self::validCmd( $cmd ) ) {
				continue;
			}
			$req         = (string) ( $t['requires'] ?? '' );
			$out[ $key ] = array(
				'label'     => self::TARGETS[ $key ],
				'cmd'       => array_values( $cmd ),
				'note'      => (string) ( $t['note'] ?? '' ),
				'requires'  => $req,
				'installed' => '' === $req || file_exists( $req ), // la demo c'è già sul server? (es. la cartella «current» del gestionale demo)
			);
		}
		return $out;
	}

	/** Lista di argomenti non vuoti, il primo è un percorso assoluto. */
	private static function validCmd( $cmd ): bool {
		if ( ! is_array( $cmd ) || count( $cmd ) < 2 || '/' !== substr( (string) reset( $cmd ), 0, 1 ) ) {
			return false;
		}
		foreach ( $cmd as $a ) {
			if ( ! is_string( $a ) || '' === $a || false !== strpos( $a, "\0" ) ) {
				return false;
			}
		}
		return true;
	}

	/** Il server permette di lanciare un comando? (alcuni hosting disattivano proc_open) */
	public static function canRun(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$off = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'proc_open', $off, true );
	}

	public static function dir(): string {
		return rtrim( (string) Config::get( 'demo_reset_dir', dirname( __DIR__ ) . '/data/demo-reset' ), '/' );
	}

	private static function f( string $key, string $ext ): string {
		return self::dir() . '/' . $key . '.' . $ext;
	}

	/** @return bool c'è un reset in corso (blocco recente e processo ancora vivo, se il suo numero è noto) */
	private static function running( string $key ): bool {
		$lock = self::f( $key, 'lock' );
		if ( ! is_file( $lock ) ) {
			return false;
		}
		$age = time() - (int) @filemtime( $lock );
		if ( $age > self::STALE ) {
			@unlink( $lock );
			return false;
		}
		$pid = (int) trim( (string) @file_get_contents( self::f( $key, 'pid' ) ) );
		if ( $pid > 0 && is_dir( '/proc' ) && ! is_dir( '/proc/' . $pid ) ) { // il processo è finito senza ripulire (riavvio del server, kill)
			@unlink( $lock );
			return false;
		}
		return true;
	}

	/**
	 * Stato di una demo: running, ultimo esito (chi, quando, codice di uscita) e le ultime righe di ciò che ha scritto lo script.
	 * Alla prima lettura dopo la fine registra l'esito nel registro delle azioni.
	 *
	 * @return array{running:bool,last:?array,log:string}
	 */
	public static function status( ?Db $db, string $key ): array {
		$running = self::running( $key );
		$exit    = self::f( $key, 'exit' );
		if ( ! $running && is_file( $exit ) ) {
			$meta  = json_decode( (string) @file_get_contents( self::f( $key, 'meta' ) ), true );
			$meta  = is_array( $meta ) ? $meta : array();
			$parts = preg_split( '/\s+/', trim( (string) @file_get_contents( $exit ) ) );
			$rc    = isset( $parts[0] ) && is_numeric( $parts[0] ) ? (int) $parts[0] : -1;
			$last  = array( 'actor' => (string) ( $meta['actor'] ?? '' ), 'started' => (int) ( $meta['started'] ?? 0 ), 'finished' => (int) ( $parts[1] ?? time() ), 'exit' => $rc );
			@file_put_contents( self::f( $key, 'last' ), json_encode( $last ), LOCK_EX );
			@unlink( $exit );
			if ( $db ) {
				Audit::log( $db, 'sistema', 'demo_reset_fine', ( self::TARGETS[ $key ] ?? $key ) . ' · ' . ( 0 === $rc ? 'finito bene' : 'finito con errore (' . $rc . ')' ) . ' · avviato da ' . $last['actor'] );
			}
		}
		$last = json_decode( (string) @file_get_contents( self::f( $key, 'last' ) ), true );
		$log  = '';
		if ( is_file( self::f( $key, 'log' ) ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', trim( (string) @file_get_contents( self::f( $key, 'log' ) ) ) );
			$log   = implode( "\n", array_slice( (array) $lines, -20 ) );
		}
		return array( 'running' => $running, 'last' => is_array( $last ) ? $last : null, 'log' => $log );
	}

	/** Avvia il reset in background. Un solo reset alla volta: un secondo clic trova il blocco e viene rifiutato. */
	public static function start( Db $db, string $key, string $actor ): void {
		$t = self::targets();
		if ( ! isset( $t[ $key ] ) ) {
			throw new UserError( 'Questa demo non è collegata al portale.' );
		}
		if ( ! $t[ $key ]['installed'] ) {
			throw new UserError( $t[ $key ]['label'] . ' non ancora installata: non c\'è niente da azzerare.' );
		}
		if ( ! self::canRun() ) {
			throw new UserError( 'Il server non permette al portale di lanciare comandi: il reset non parte.' );
		}
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new UserError( 'Non riesco a preparare la cartella di lavoro del reset.' );
		}
		self::running( $key ); // toglie un eventuale blocco orfano
		$h = @fopen( self::f( $key, 'lock' ), 'x' ); // creazione esclusiva: chi arriva secondo non passa
		if ( false === $h ) {
			throw new UserError( 'C\'è già un reset della ' . strtolower( $t[ $key ]['label'] ) . ' in corso: aspetta che finisca.' );
		}
		fwrite( $h, (string) time() );
		fclose( $h );
		foreach ( array( 'exit', 'pid', 'log' ) as $ext ) {
			@unlink( self::f( $key, $ext ) );
		}
		file_put_contents( self::f( $key, 'meta' ), json_encode( array( 'actor' => $actor, 'started' => time() ) ), LOCK_EX );

		$cmd   = implode( ' ', array_map( 'escapeshellarg', $t[ $key ]['cmd'] ) );
		$inner = 'echo $$ > ' . escapeshellarg( self::f( $key, 'pid' ) ) . '; ' . $cmd . ' > ' . escapeshellarg( self::f( $key, 'log' ) ) . ' 2>&1 < /dev/null; '
			. 'rc=$?; printf "%s %s" "$rc" "$(date +%s)" > ' . escapeshellarg( self::f( $key, 'exit' ) ) . '; rm -f ' . escapeshellarg( self::f( $key, 'pid' ) ) . ' ' . escapeshellarg( self::f( $key, 'lock' ) );
		$spec  = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) );
		$p     = @proc_open( 'nohup sh -c ' . escapeshellarg( $inner ) . ' >/dev/null 2>&1 &', $spec, $pipes );
		if ( ! is_resource( $p ) ) {
			@unlink( self::f( $key, 'lock' ) );
			throw new UserError( 'Il reset non è partito: il server non ha lanciato il comando.' );
		}
		proc_close( $p );
		Audit::log( $db, $actor, 'demo_reset', 'Avviato il reset: ' . $t[ $key ]['label'] );
	}
}

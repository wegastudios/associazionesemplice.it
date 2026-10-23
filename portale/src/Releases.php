<?php
namespace Portale;

/**
 * Pacchetti del plugin (gratuito e Pro) ospitati dal portale, ultime N versioni per tornare indietro.
 * I file stanno fuori dalla cartella pubblica (`data/releases/`) e si scaricano solo da {@see Releases::stream}
 * (`/scarica/{prodotto}/{versione}`), con accesso eseguito e licenza in regola: non esiste nessun indirizzo pubblico da condividere.
 */
final class Releases {

	/** prodotto → [cartella nel pacchetto, nome mostrato] */
	const PRODUCTS = array(
		'free' => array( 'associazionesemplice', 'Associazione Semplice (gratuito)' ),
		'pro'  => array( 'associazionesemplice-pro', 'Associazione Semplice Pro' ),
	);

	public static function dir( string $product = '' ): string {
		$base = (string) Config::get( 'releases_dir', dirname( __DIR__ ) . '/data/releases' );
		return $base . ( '' !== $product ? '/' . $product : '' );
	}

	/**
	 * Aggiunge una versione leggendo numero e cartella dal pacchetto. Una versione già presente non si sovrascrive.
	 * @return array riga inserita
	 */
	public static function add( Db $db, string $product, string $tmpFile, string $note, string $actor, ?int $now = null, ?string $signature = null, ?string $kid = null ): array {
		if ( ! isset( self::PRODUCTS[ $product ] ) ) {
			throw new UserError( 'Prodotto non valido.' );
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new UserError( 'Sul server manca l\'estensione zip di PHP.' );
		}
		$slug = self::PRODUCTS[ $product ][0];
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $tmpFile ) ) {
			throw new UserError( 'Il file non è uno zip valido.' );
		}
		$main = (string) $zip->getFromName( $slug . '/' . $slug . '.php' );
		$zip->close();
		if ( '' === $main ) {
			throw new UserError( 'Il pacchetto non contiene ' . $slug . '/' . $slug . '.php: controlla di aver scelto il file giusto.' );
		}
		$version = self::headerVersion( $main );
		if ( '' === $version ) {
			throw new UserError( 'Non trovo la versione nell\'intestazione del plugin.' );
		}
		if ( $db->one( 'SELECT 1 AS x FROM releases WHERE product = ? AND version = ?', array( $product, $version ) ) ) {
			throw new UserError( 'La versione ' . $version . ' è già presente: aumenta il numero di versione per pubblicarne una nuova.' );
		}
		$signature = null !== $signature ? trim( $signature ) : '';
		if ( '' !== $signature && ! self::verify( $product, $version, (string) hash_file( 'sha256', $tmpFile ), $signature, (string) $kid ) ) {
			throw new UserError( 'La firma non corrisponde a questo pacchetto (prodotto, versione e impronta) o alla chiave dei pacchetti: ricalcolala con release.sh.' );
		}
		$dir = self::dir( $product );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) ) {
			throw new UserError( 'Non riesco a creare la cartella dei pacchetti.' );
		}
		$dest = $dir . '/' . $version . '.zip';
		if ( ! copy( $tmpFile, $dest ) ) {
			throw new UserError( 'Non riesco a salvare il pacchetto.' );
		}
		@chmod( $dest, 0640 );
		$row = array(
			'product' => $product, 'version' => $version, 'size' => (int) filesize( $dest ), 'sha256' => hash_file( 'sha256', $dest ),
			'note' => mb_substr( trim( $note ), 0, 500 ), 'created_at' => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
		);
		$db->run( 'INSERT INTO releases (product, version, size, sha256, note, created_at) VALUES (?,?,?,?,?,?)', array_values( $row ) );
		if ( '' !== $signature ) {
			$db->run( 'UPDATE releases SET signature = ?, kid = ? WHERE product = ? AND version = ?', array( $signature, (string) $kid, $product, $version ) );
			$row['signature'] = $signature;
			$row['kid']       = (string) $kid;
		}
		Audit::log( $db, $actor, 'release.add', $product . ' ' . $version . ( '' !== $signature ? ' (firmata)' : ' (NON firmata)' ) );
		self::prune( $db, $product, $actor );
		return $row;
	}

	/**
	 * Chiavi pubbliche (Ed25519, 32 byte in base64url) con cui il plugin verifica i pacchetti prima di installarli. La chiave privata NON sta
	 * sul server web: firma chi pubblica, dal proprio computer (`release.sh`), così un portale compromesso non può far installare codice sui siti.
	 */
	const SIGNING_KEYS = array(
		'pkg-2026-10-85dce7' => 'ld_4NilmHT-TtaH8vUw_YGq7eEt4a0e4L4YAxSzP6oI',
	);

	public static function keys(): array {
		return (array) Config::get( 'package_keys', self::SIGNING_KEYS );
	}

	/** Messaggio firmato: «prodotto|versione|impronta sha256 dello zip». */
	public static function signedMessage( string $product, string $version, string $sha256 ): string {
		return $product . '|' . $version . '|' . strtolower( $sha256 );
	}

	private static function b64d( string $s ): string {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	}

	/** Verifica la firma Ed25519 (base64url) del messaggio con la chiave pubblica `kid`. */
	public static function verify( string $product, string $version, string $sha256, string $signature, string $kid ): bool {
		$keys = self::keys();
		if ( ! isset( $keys[ $kid ] ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$sig = self::b64d( $signature );
		$pub = self::b64d( (string) $keys[ $kid ] );
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, self::signedMessage( $product, $version, $sha256 ), $pub );
	}

	/** Aggiunge la firma a una versione già pubblicata (verificata contro l'impronta registrata). @throws UserError */
	public static function sign( Db $db, string $product, string $version, string $signature, string $kid, string $actor ): void {
		$rel = self::get( $db, $product, $version );
		if ( ! $rel ) {
			throw new UserError( 'Versione non trovata.' );
		}
		if ( ! self::verify( $product, $version, (string) $rel['sha256'], trim( $signature ), $kid ) ) {
			throw new UserError( 'La firma non corrisponde a questa versione.' );
		}
		$db->run( 'UPDATE releases SET signature = ?, kid = ? WHERE product = ? AND version = ?', array( trim( $signature ), $kid, $product, $version ) );
		Audit::log( $db, $actor, 'release.sign', $product . ' ' . $version );
	}

	/** Numero di versione ammesso: x.y.z oppure x.y (es. 1.1.14, 1.2). */
	public static function validVersion( string $v ): bool {
		return (bool) preg_match( '/^\d+\.\d+(\.\d+)?$/', $v );
	}

	/** Numero di versione dall'intestazione del file principale del plugin («Version: 1.2.3»). */
	public static function headerVersion( string $source ): string {
		return preg_match( '/^[ \t\/*#@]*Version:\s*(\d+\.\d+(?:\.\d+)?)\s*$/mi', substr( $source, 0, 8192 ), $m ) ? $m[1] : '';
	}

	/** Versioni del prodotto, dalla più recente. */
	public static function all( Db $db, string $product ): array {
		$rows = $db->all( 'SELECT * FROM releases WHERE product = ?', array( $product ) );
		usort( $rows, function ( $a, $b ) { return version_compare( $b['version'], $a['version'] ); } );
		return $rows;
	}

	/**
	 * Novità dal CHANGELOG.md dentro il pacchetto pubblicato: solo le voci «## x.y.z» più recenti della versione installata
	 * (senza versione installata, la più recente) e solo le righe «## versione» e «- voce». Massimo 20000 caratteri.
	 */
	public static function changelog( string $product, string $latest, string $installed = '' ): string {
		if ( ! isset( self::PRODUCTS[ $product ] ) || ! self::validVersion( $latest ) || ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$file = self::dir( $product ) . '/' . $latest . '.zip';
		$zip  = new \ZipArchive();
		if ( ! is_file( $file ) || true !== $zip->open( $file ) ) {
			return '';
		}
		$raw = $zip->getFromName( self::PRODUCTS[ $product ][0] . '/CHANGELOG.md' );
		$zip->close();
		return false === $raw ? '' : self::filterChangelog( (string) $raw, $installed );
	}

	public static function filterChangelog( string $md, string $installed = '' ): string {
		$out  = array();
		$keep = false;
		$n    = 0;
		foreach ( preg_split( '/\r?\n/', $md ) as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^## (\d+\.\d+(?:\.\d+)?)$/', $line, $m ) ) {
				++$n;
				$keep = '' === $installed ? 1 === $n : version_compare( $m[1], $installed, '>' );
				if ( $keep ) {
					$out[] = $line;
				}
			} elseif ( $keep && 0 === strpos( $line, '- ' ) ) {
				$out[] = $line;
			}
		}
		return substr( implode( "\n", $out ), 0, 20000 );
	}

	public static function get( Db $db, string $product, string $version ): ?array {
		return $db->one( 'SELECT * FROM releases WHERE product = ? AND version = ?', array( $product, $version ) );
	}

	/** Tiene le ultime N versioni (limite «releases_keep»); le altre si cancellano. */
	public static function prune( Db $db, string $product, string $actor ): void {
		foreach ( array_slice( self::all( $db, $product ), Settings::limit( 'releases_keep' ) ) as $old ) {
			self::remove( $db, $product, $old['version'], $actor );
		}
	}

	public static function remove( Db $db, string $product, string $version, string $actor ): void {
		if ( ! self::validVersion( $version ) || ! isset( self::PRODUCTS[ $product ] ) ) {
			return;
		}
		$db->run( 'DELETE FROM releases WHERE product = ? AND version = ?', array( $product, $version ) );
		@unlink( self::dir( $product ) . '/' . $version . '.zip' );
		Audit::log( $db, $actor, 'release.remove', $product . ' ' . $version );
	}

	/**
	 * Prodotti che il cliente può scaricare: il gratuito con qualunque licenza in regola, il Pro con un piano a pagamento
	 * (o in prova) in regola. Una licenza scaduta o con pagamento non riuscito non dà accesso.
	 * @param array $subs licenze del cliente
	 * @return string[]
	 */
	public static function entitled( array $subs ): array {
		$out = array();
		foreach ( $subs as $s ) {
			if ( 'Attiva' !== sub_status( $s )[0] ) {
				continue;
			}
			$out['free'] = 'free';
			if ( 'free' !== $s['plan_code'] ) {
				$out['pro'] = 'pro';
			}
		}
		return array_values( $out );
	}

	/** Scarichi del cliente nelle ultime 24 ore. */
	public static function recentDownloads( Db $db, string $customerId, ?int $now = null ): int {
		$r = $db->one( 'SELECT COUNT(*) AS n FROM downloads WHERE customer_id = ? AND at >= ?', array( $customerId, gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - 86400 ) ) );
		return $r ? (int) $r['n'] : 0;
	}

	/** Controlla accesso e limite, registra lo scarico e restituisce il percorso del file. @throws UserError */
	public static function authorize( Db $db, string $customerId, bool $isAdmin, array $subs, string $product, string $version, ?int $now = null ): string {
		$rel = self::get( $db, $product, $version );
		if ( ! $rel ) {
			throw new UserError( 'Questa versione non è disponibile.' );
		}
		if ( ! $isAdmin ) {
			if ( ! in_array( $product, self::entitled( $subs ), true ) ) {
				throw new UserError( 'Per scaricare questo pacchetto serve una licenza attiva' . ( 'pro' === $product ? ' di un piano Pro.' : '.' ) );
			}
			if ( self::recentDownloads( $db, $customerId, $now ) >= Settings::limit( 'download_day' ) ) {
				throw new UserError( 'Hai raggiunto il limite di scarichi di oggi: riprova domani.' );
			}
		}
		$file = self::dir( $product ) . '/' . $rel['version'] . '.zip';
		if ( ! is_file( $file ) ) {
			throw new UserError( 'Il file di questa versione non è più sul server.' );
		}
		$db->run( 'INSERT INTO downloads (id, customer_id, at, product, version) VALUES (?,?,?,?,?)', array( Db::id(), $customerId, gmdate( 'Y-m-d H:i:s', $now ?? time() ), $product, $rel['version'] ) );
		return $file;
	}

	/** Invia il file al browser. */
	public static function stream( string $file, string $product, string $version ): void {
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . self::PRODUCTS[ $product ][0] . '-' . $version . '.zip"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
	}
}

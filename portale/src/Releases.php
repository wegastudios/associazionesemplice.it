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
	public static function add( Db $db, string $product, string $tmpFile, string $note, string $actor, ?int $now = null, ?string $signature = null, ?string $kid = null, ?array $v2 = null ): array {
		$version = self::packageVersion( $product, $tmpFile );
		if ( $db->one( 'SELECT 1 AS x FROM releases WHERE product = ? AND version = ?', array( $product, $version ) ) ) {
			throw new UserError( 'La versione ' . $version . ' è già presente: aumenta il numero di versione per pubblicarne una nuova.' );
		}
		$signature = null !== $signature ? trim( $signature ) : '';
		if ( '' !== $signature && ! self::verify( $product, $version, (string) hash_file( 'sha256', $tmpFile ), $signature, (string) $kid ) ) {
			throw new UserError( 'La firma non corrisponde a questo pacchetto (prodotto, versione e impronta) o alla chiave dei pacchetti: ricalcolala con release.sh.' );
		}
		if ( null !== $v2 && '' === $signature ) {
			throw new UserError( 'La firma v2 si pubblica insieme alla firma v1.' );
		}
		$v2 = null !== $v2 ? self::checkV2( $product, $version, (string) hash_file( 'sha256', $tmpFile ), $v2, $now ) : null;
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
		if ( null !== $v2 ) {
			self::storeV2( $db, $product, $version, $v2 );
			$row = array_merge( $row, $v2 );
		}
		Audit::log( $db, $actor, 'release.add', $product . ' ' . $version . ( '' !== $signature ? ' (firmata' . ( null !== $v2 ? ' v1+v2' : '' ) . ')' : ' (NON firmata)' ) );
		self::prune( $db, $product, $actor );
		return $row;
	}

	/** Numero di versione scritto nell'intestazione del plugin dentro lo zip; controlla che sia lo zip del prodotto giusto. @throws UserError */
	private static function packageVersion( string $product, string $tmpFile ): string {
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
		return $version;
	}

	/**
	 * Sostituisce zip, impronta e firma di una versione GIÀ pubblicata, con lo stesso numero (caso eccezionale, autorizzato dalla titolare: la firma è
	 * obbligatoria). Il vecchio zip resta sul server accanto al nuovo come «VERSIONE.zip.IMPRONTA8.replaced» (non scaricabile) e l'audit registra le due impronte.
	 * @return array riga aggiornata
	 * @throws UserError
	 */
	public static function replace( Db $db, string $product, string $tmpFile, string $note, string $actor, string $signature, string $kid, ?array $v2 = null ): array {
		$version = self::packageVersion( $product, $tmpFile );
		$old     = self::get( $db, $product, $version );
		if ( ! $old ) {
			throw new UserError( 'La versione ' . $version . ' non è ancora pubblicata: si pubblica con il comando normale.' );
		}
		$sha = (string) hash_file( 'sha256', $tmpFile );
		if ( strtolower( (string) $old['sha256'] ) === $sha ) {
			throw new UserError( 'Il pacchetto è identico a quello già pubblicato: niente da sostituire.' );
		}
		if ( '' === trim( $signature ) || ! self::verify( $product, $version, $sha, trim( $signature ), $kid ) ) {
			throw new UserError( 'La firma non corrisponde a questo pacchetto (prodotto, versione e impronta) o alla chiave dei pacchetti: ricalcolala con release.sh.' );
		}
		$v2 = null !== $v2 ? self::checkV2( $product, $version, $sha, $v2 ) : null;
		$dest = self::dir( $product ) . '/' . $version . '.zip';
		if ( is_file( $dest ) && ! copy( $dest, $dest . '.' . substr( (string) $old['sha256'], 0, 8 ) . '.replaced' ) ) {
			throw new UserError( 'Non riesco a conservare il vecchio pacchetto: sostituzione annullata.' );
		}
		if ( ! copy( $tmpFile, $dest ) ) {
			throw new UserError( 'Non riesco a salvare il pacchetto.' );
		}
		@chmod( $dest, 0640 );
		$row = array(
			'size' => (int) filesize( $dest ), 'sha256' => (string) hash_file( 'sha256', $dest ), 'note' => '' !== trim( $note ) ? mb_substr( trim( $note ), 0, 500 ) : (string) $old['note'],
			'signature' => trim( $signature ), 'kid' => $kid,
		);
		$db->run( 'UPDATE releases SET size = ?, sha256 = ?, note = ?, signature = ?, kid = ? WHERE product = ? AND version = ?', array( $row['size'], $row['sha256'], $row['note'], $row['signature'], $row['kid'], $product, $version ) );
		// La firma v2 vecchia riguarda l'impronta vecchia: o si rifirma (nuovo signed_at) o si toglie.
		self::storeV2( $db, $product, $version, $v2 ?? array( 'signature_v2' => null, 'kid_v2' => null, 'signed_at' => null, 'min_version' => null ) );
		Audit::log( $db, $actor, 'release.replace', $product . ' ' . $version . ' ' . substr( (string) $old['sha256'], 0, 12 ) . ' → ' . substr( $row['sha256'], 0, 12 ) . ( null !== $v2 ? ' (v1+v2)' : '' ) );
		return array_merge( $old, $row, $v2 ?? array() );
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
		return self::verifyMessage( self::signedMessage( $product, $version, $sha256 ), $signature, $kid );
	}

	private static function verifyMessage( string $message, string $signature, string $kid ): bool {
		$keys = self::keys();
		if ( ! isset( $keys[ $kid ] ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$sig = self::b64d( $signature );
		$pub = self::b64d( (string) $keys[ $kid ] );
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $message, $pub );
	}

	/** Aggiunge la firma a una versione già pubblicata (verificata contro l'impronta registrata). @throws UserError */
	public static function sign( Db $db, string $product, string $version, string $signature, string $kid, string $actor, ?array $v2 = null ): void {
		$rel = self::get( $db, $product, $version );
		if ( ! $rel ) {
			throw new UserError( 'Versione non trovata.' );
		}
		if ( ! self::verify( $product, $version, (string) $rel['sha256'], trim( $signature ), $kid ) ) {
			throw new UserError( 'La firma non corrisponde a questa versione.' );
		}
		$v2 = null !== $v2 ? self::checkV2( $product, $version, (string) $rel['sha256'], $v2 ) : null;
		$db->run( 'UPDATE releases SET signature = ?, kid = ? WHERE product = ? AND version = ?', array( trim( $signature ), $kid, $product, $version ) );
		if ( null !== $v2 ) {
			self::storeV2( $db, $product, $version, $v2 );
		}
		Audit::log( $db, $actor, 'release.sign', $product . ' ' . $version . ( null !== $v2 ? ' (v1+v2)' : '' ) );
	}

	/**
	 * Messaggio firmato v2: «asem-pkg-v2|prodotto|versione|sha256|signed_at|min_version». Il prefisso fisso separa le due firme:
	 * una firma v1 non vale come v2 e viceversa. signed_at (secondi UNIX UTC) è il momento della firma, non della pubblicazione.
	 */
	public static function signedMessageV2( string $product, string $version, string $sha256, int $signedAt, string $minVersion ): string {
		return 'asem-pkg-v2|' . $product . '|' . $version . '|' . strtolower( $sha256 ) . '|' . $signedAt . '|' . $minVersion;
	}

	/** Verifica la firma v2 con la chiave pubblica `kid`. */
	public static function verifyV2( string $product, string $version, string $sha256, int $signedAt, string $minVersion, string $signature, string $kid ): bool {
		return self::verifyMessage( self::signedMessageV2( $product, $version, $sha256, $signedAt, $minVersion ), $signature, $kid );
	}

	/**
	 * Controlla i campi v2 (signature_v2, kid_v2, signed_at, min_version): tutti presenti, min_version in formato versione e non oltre la versione,
	 * signed_at plausibile (non nel futuro oltre un giorno), firma valida con la chiave kid_v2. @return array campi normalizzati @throws UserError
	 */
	public static function checkV2( string $product, string $version, string $sha256, array $v2, ?int $now = null ): array {
		$sig = trim( (string) ( $v2['signature_v2'] ?? '' ) );
		$kid = trim( (string) ( $v2['kid_v2'] ?? '' ) );
		$at  = $v2['signed_at'] ?? null;
		$min = trim( (string) ( $v2['min_version'] ?? '' ) );
		if ( '' === $sig || '' === $kid || '' === $min || ! ( is_int( $at ) || ( is_string( $at ) && ctype_digit( $at ) ) ) || (int) $at <= 0 ) {
			throw new UserError( 'La firma v2 vuole firma, chiave, data della firma e versione minima, tutte insieme.' );
		}
		$at = (int) $at;
		if ( ! self::validVersion( $min ) || version_compare( $min, $version, '>' ) ) {
			throw new UserError( 'La versione minima deve essere nel formato 1.2 o 1.2.3 e non superiore alla versione del pacchetto.' );
		}
		if ( $at > ( $now ?? time() ) + 86400 ) {
			throw new UserError( 'La data della firma è nel futuro: controlla l\'orologio del computer che firma.' );
		}
		if ( ! self::verifyV2( $product, $version, $sha256, $at, $min, $sig, $kid ) ) {
			throw new UserError( 'La firma v2 non corrisponde a questo pacchetto (prodotto, versione, impronta, data, versione minima) o alla chiave dei pacchetti: ricalcolala con release.sh.' );
		}
		return array( 'signature_v2' => $sig, 'kid_v2' => $kid, 'signed_at' => $at, 'min_version' => $min );
	}

	private static function storeV2( Db $db, string $product, string $version, array $v2 ): void {
		$db->run(
			'UPDATE releases SET signature_v2 = ?, kid_v2 = ?, signed_at = ?, min_version = ? WHERE product = ? AND version = ?',
			array( $v2['signature_v2'], $v2['kid_v2'], $v2['signed_at'], $v2['min_version'], $product, $version )
		);
	}

	/** Versione minima dichiarata dall'ultima release del prodotto che ne ha una (per il valore predefinito di release.sh); stringa vuota se nessuna. */
	public static function latestMinVersion( Db $db, string $product ): string {
		foreach ( self::all( $db, $product ) as $r ) {
			if ( ! empty( $r['min_version'] ) ) {
				return (string) $r['min_version'];
			}
		}
		return '';
	}

	/** Numero di versione ammesso: x.y.z oppure x.y (es. 1.1.14, 1.2). */
	public static function validVersion( string $v ): bool {
		return (bool) preg_match( '/^\d+\.\d+(\.\d+)?$/D', $v );
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
	public static function changelog( string $product, string $latest, string $installed = '', ?array $available = null, array $custom = array() ): string {
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
		$raw = false === $raw ? '' : (string) $raw;
		return self::filterChangelog( $custom ? self::mergeChangelog( $raw, $custom ) : $raw, $installed, $available );
	}

	/** Testi del changelog scritti a parte (colonna «changelog», non coperta dalla firma) per versione, dalle righe di Releases::all(). */
	public static function customChangelogs( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( isset( $r['version'], $r['changelog'] ) && '' !== trim( (string) $r['changelog'] ) ) {
				$out[ (string) $r['version'] ] = (string) $r['changelog'];
			}
		}
		return $out;
	}

	/** Unisce il CHANGELOG.md del pacchetto con i testi scritti a parte: per una versione che ne ha uno, questo prende il posto del blocco del pacchetto; le versioni restano dalla più nuova. */
	public static function mergeChangelog( string $md, array $custom ): string {
		$blocks = array();
		$cur    = '';
		foreach ( preg_split( '/\r?\n/', $md ) as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^## (\d+\.\d+(?:\.\d+)?)(?: [–-] \d{4}-\d{2}-\d{2})?$/u', $line, $m ) ) {
				$cur            = $m[1];
				$blocks[ $cur ] = $line;
			} elseif ( '' !== $cur ) {
				$blocks[ $cur ] .= "\n" . $line;
			}
		}
		foreach ( $custom as $v => $text ) {
			$blocks[ (string) $v ] = str_replace( "\r", '', trim( (string) $text ) );
		}
		uksort( $blocks, function ( $a, $b ) { return version_compare( (string) $b, (string) $a ); } );
		return implode( "\n\n", $blocks );
	}

	/** Scrive (o toglie, se vuoto) il testo del changelog di una release già pubblicata; non tocca zip, impronta né firme. @throws UserError */
	public static function setChangelog( Db $db, string $product, string $version, string $text, string $actor ): void {
		if ( ! self::get( $db, $product, $version ) ) {
			throw new UserError( 'Versione non trovata.' );
		}
		$text = str_replace( "\r", '', trim( $text ) );
		if ( '' !== $text && ! preg_match( '/^## ' . preg_quote( $version, '/' ) . '(?: [–-] \d{4}-\d{2}-\d{2})?\n/u', $text . "\n" ) ) {
			throw new UserError( 'Il testo deve cominciare con «## ' . $version . '» (con eventuale « – AAAA-MM-GG»).' );
		}
		if ( strlen( $text ) > 20000 ) {
			throw new UserError( 'Testo troppo lungo (massimo 20000 caratteri).' );
		}
		$db->run( 'UPDATE releases SET changelog = ? WHERE product = ? AND version = ?', array( '' === $text ? null : $text, $product, $version ) );
		Audit::log( $db, $actor, 'release.changelog', $product . ' ' . $version . ( '' === $text ? ' (tolto)' : '' ) );
	}

	/**
	 * Righe del CHANGELOG.md che il portale mostra: «## x.y.z» (con eventuale « – AAAA-MM-GG»), le voci «- …», la riga esatta
	 * «Da fare dopo l'aggiornamento:» e, una sola volta in fondo, il «Changelog di tutte le versioni: https://associazionesemplice.it/…».
	 * Con $available (elenco delle versioni ancora scaricabili) restano solo quelle: il changelog non cresce con le versioni tolte.
	 */
	public static function filterChangelog( string $md, string $installed = '', ?array $available = null ): string {
		$out  = array();
		$link = '';
		$keep = false;
		$n    = 0;
		foreach ( preg_split( '/\r?\n/', $md ) as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^## (\d+\.\d+(?:\.\d+)?)(?: [–-] \d{4}-\d{2}-\d{2})?$/u', $line, $m ) ) {
				if ( null !== $available && ! in_array( $m[1], $available, true ) ) {
					$keep = false;
					continue;
				}
				++$n;
				$keep = '' === $installed ? 1 === $n : version_compare( $m[1], $installed, '>' );
				if ( $keep ) {
					$out[] = $line;
				}
			} elseif ( $keep && ( 0 === strpos( $line, '- ' ) || "Da fare dopo l'aggiornamento:" === $line ) ) {
				$out[] = $line;
			} elseif ( $keep && '' === $link && 0 === strpos( $line, 'Changelog di tutte le versioni: https://associazionesemplice.it/' ) ) {
				$link = $line;
			}
		}
		if ( $out && '' !== $link ) {
			$out[] = $link;
		}
		return substr( implode( "\n", $out ), 0, 20000 );
	}

	/** Il testo filtrato di filterChangelog() in HTML: titoli di versione, etichette in grassetto, «Da fare dopo l'aggiornamento» e link finale (solo associazionesemplice.it). */
	public static function changelogHtml( string $md ): string {
		$out  = '';
		$open = false;
		foreach ( preg_split( '/\n/', $md ) as $line ) {
			if ( 0 === strpos( $line, '- ' ) ) {
				$out .= $open ? '' : '<ul>';
				$open = true;
				$item = htmlspecialchars( substr( $line, 2 ), ENT_QUOTES, 'UTF-8' );
				$out .= '<li>' . preg_replace( '/^(Nuovo|Ottimizzazione|Sicurezza|Altro):/', '<strong>$1:</strong>', $item ) . '</li>';
				continue;
			}
			$out .= $open ? '</ul>' : '';
			$open = false;
			if ( 0 === strpos( $line, '## ' ) ) {
				$out .= '<h3>' . htmlspecialchars( substr( $line, 3 ), ENT_QUOTES, 'UTF-8' ) . '</h3>';
			} elseif ( "Da fare dopo l'aggiornamento:" === $line ) {
				$out .= '<p><strong>' . htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' ) . '</strong></p>';
			} elseif ( preg_match( '#^Changelog di tutte le versioni: (https://associazionesemplice\.it/[^\s"\'<>]*)$#', $line, $m ) ) {
				$out .= '<p><a href="' . htmlspecialchars( $m[1], ENT_QUOTES, 'UTF-8' ) . '">Changelog di tutte le versioni</a></p>';
			}
		}
		return $out . ( $open ? '</ul>' : '' );
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

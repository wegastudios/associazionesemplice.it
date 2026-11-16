<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- lettura a flussi di file grandi (memoria costante)

/**
 * Firma e controlli di una copia di sicurezza. Classe «pura» (nessuna dipendenza da WordPress): si può provare da sola.
 *
 * Il manifest della copia porta l'impronta SHA-256 di ogni voce dello ZIP e una firma HMAC-SHA256 di tutto il manifest, con una chiave ricavata dai
 * codici di sicurezza del sito. Chi riceve la copia sullo stesso sito (stessi codici) controlla firma e impronte; una copia fatta altrove ha una firma
 * che qui non si può verificare e vuole una conferma esplicita.
 */
final class BackupSeal {

	/** Tetti predefiniti: una voce (tabella, allegato, PDF), un file piccolo (chiavi, impostazioni, indice, manifest), il totale decompresso, allegati, voci. */
	const MAX_ENTRY       = 1073741824;
	const MAX_SMALL       = 5242880;
	const MAX_TOTAL       = 10737418240;
	const MAX_ATTACHMENTS = 20000;
	const MAX_ENTRIES     = 40000;

	/** @return array<string,int> */
	public static function limits( array $override = array() ): array {
		$l = array( 'entry' => self::MAX_ENTRY, 'small' => self::MAX_SMALL, 'total' => self::MAX_TOTAL, 'attachments' => self::MAX_ATTACHMENTS, 'entries' => self::MAX_ENTRIES );
		foreach ( $override as $k => $v ) {
			if ( isset( $l[ $k ] ) ) {
				$l[ $k ] = (int) $v;
			}
		}
		return $l;
	}

	/** Chiave di firma (32 byte) dai codici di sicurezza del sito. */
	public static function key( string $salt ): string {
		return hash_hmac( 'sha256', 'asem-backup-seal-v1', $salt, true );
	}

	/** Impronta (non segreta) della chiave: dice se due siti hanno gli stessi codici, senza rivelarli. */
	public static function fingerprint( string $key ): string {
		return substr( hash( 'sha256', 'asem-backup-seal-fp-v1|' . $key ), 0, 16 );
	}

	/** JSON dello stesso valore sempre uguale: chiavi in ordine, senza spazi. */
	public static function canonical( $v ): string {
		return (string) json_encode( self::sorted( $v ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sorted( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
		foreach ( $v as $k => $x ) {
			$v[ $k ] = self::sorted( $x );
		}
		if ( ! $is_list ) {
			ksort( $v, SORT_STRING );
		}
		return $v;
	}

	/** Firma (esadecimale) del manifest: il campo «sig» non ne fa parte. */
	public static function signature( array $manifest, string $key ): string {
		unset( $manifest['sig'] );
		return hash_hmac( 'sha256', self::canonical( $manifest ), $key );
	}

	/** Un file piccolo: si legge intero in memoria, con un tetto basso. */
	public static function is_small( string $name ): bool {
		return in_array( $name, array( 'manifest.json', 'chiavi.json', 'impostazioni.json', 'LEGGIMI.txt', 'documenti/indice.json' ), true );
	}

	/**
	 * Dimensioni dichiarate delle voci dello ZIP (senza leggerne il contenuto) e controllo dei tetti.
	 *
	 * @return array{sizes:array<string,int>,problem:string} problem: vuoto, oppure entries (troppe voci), entry (voce troppo grande), attachments, total
	 */
	public static function sizes( \ZipArchive $zip, array $limits = array() ): array {
		$l       = self::limits( $limits );
		$sizes   = array();
		$total   = 0;
		$att     = 0;
		$problem = '';
		if ( $zip->numFiles > $l['entries'] ) {
			return array( 'sizes' => array(), 'problem' => 'entries' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( ! $st || '/' === substr( (string) $st['name'], -1 ) ) {
				continue; // le cartelle non hanno contenuto
			}
			$name  = (string) $st['name'];
			$size  = (int) $st['size'];
			$cap   = self::is_small( $name ) ? $l['small'] : $l['entry'];
			if ( $size > $cap && '' === $problem ) {
				$problem = 'entry';
			}
			if ( 0 === strpos( $name, 'allegati/' ) ) {
				++$att;
			}
			$total        += $size;
			$sizes[ $name ] = $size;
		}
		if ( '' === $problem && $att > $l['attachments'] ) {
			$problem = 'attachments';
		}
		if ( '' === $problem && $total > $l['total'] ) {
			$problem = 'total';
		}
		return array( 'sizes' => $sizes, 'problem' => $problem );
	}

	/**
	 * Impronta SHA-256 di ogni voce (tranne il manifest), letta a flussi. Una voce che dà più byte di quelli dichiarati, o che non si legge, ferma tutto.
	 *
	 * @param array<string,int> $sizes dimensioni dichiarate (vedi sizes())
	 * @return array<string,string>|null nome => impronta; null se qualcosa non torna
	 */
	public static function hashes( \ZipArchive $zip, array $sizes ): ?array {
		$out = array();
		foreach ( $sizes as $name => $size ) {
			if ( 'manifest.json' === $name ) {
				continue;
			}
			$h = $zip->getStream( (string) $name );
			if ( ! $h ) {
				return null;
			}
			$ctx  = hash_init( 'sha256' );
			$read = 0;
			while ( ! feof( $h ) ) {
				$d = fread( $h, 1048576 );
				if ( false === $d ) {
					fclose( $h );
					return null;
				}
				$read += strlen( $d );
				if ( $read > $size ) {
					fclose( $h );
					return null; // più byte di quelli dichiarati
				}
				hash_update( $ctx, $d );
			}
			fclose( $h );
			if ( $read !== (int) $size ) {
				return null;
			}
			$out[ (string) $name ] = hash_final( $ctx );
		}
		return $out;
	}

	/**
	 * Confronta l'elenco delle impronte del manifest con quelle delle voci presenti.
	 *
	 * @param array $listed nome => impronta, dal manifest
	 * @param array $actual nome => impronta, dallo ZIP
	 * @return string vuoto se coincidono, altrimenti unlisted (voce non elencata), missing (elencata ma assente) o changed (impronta diversa)
	 */
	public static function compare( array $listed, array $actual ): string {
		foreach ( $actual as $name => $hash ) {
			if ( ! array_key_exists( $name, $listed ) ) {
				return 'unlisted';
			}
		}
		foreach ( $listed as $name => $hash ) {
			if ( ! array_key_exists( $name, $actual ) ) {
				return 'missing';
			}
		}
		foreach ( $listed as $name => $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $hash ) || ! hash_equals( strtolower( $hash ), (string) $actual[ $name ] ) ) {
				return 'changed';
			}
		}
		return '';
	}
}

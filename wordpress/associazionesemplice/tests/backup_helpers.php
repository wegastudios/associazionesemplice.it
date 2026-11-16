<?php
/**
 * Aiuti per le prove della copia di sicurezza (caricati da smoke.php e da smoke_file_crypt.php, dentro un WordPress reale).
 */

use AssociazioneSemplice\BackupSeal;

if ( ! function_exists( 'asem_bk_reseal' ) ) {

	/**
	 * Cambia le voci di una copia e la firma di nuovo con i codici di QUESTO sito: serve a provare i controlli che vengono dopo la firma
	 * (un file manomesso, un indice rovinato) senza che la copia venga fermata prima.
	 */
	function asem_bk_reseal( string $path, callable $mutate ): void {
		$z = new ZipArchive();
		$z->open( $path );
		$mutate( $z );
		$z->close();
		$z = new ZipArchive();
		$z->open( $path );
		$m            = json_decode( (string) $z->getFromName( 'manifest.json' ), true );
		$s            = BackupSeal::sizes( $z );
		$m['entries'] = BackupSeal::hashes( $z, $s['sizes'] );
		$key          = BackupSeal::key( wp_salt( 'auth' ) );
		$m['kfp']     = BackupSeal::fingerprint( $key );
		unset( $m['sig'] );
		$m['sig'] = BackupSeal::signature( $m, $key );
		$z->addFromString( 'manifest.json', wp_json_encode( $m, JSON_PRETTY_PRINT ) );
		$z->close();
	}

	/** Una copia «all'antica»: senza impronte delle voci, senza firma e senza impronta dei codici (come quelle fatte a mano o da una versione senza firma). */
	function asem_bk_legacy( string $path, ?callable $mutate = null ): void {
		$z = new ZipArchive();
		$z->open( $path );
		if ( $mutate ) {
			$mutate( $z );
		}
		$m = json_decode( (string) $z->getFromName( 'manifest.json' ), true );
		unset( $m['entries'], $m['sig'], $m['kfp'] );
		$z->addFromString( 'manifest.json', wp_json_encode( $m, JSON_PRETTY_PRINT ) );
		$z->close();
	}

	/** Cambia il manifest di una copia senza ricalcolare né la firma né le impronte. */
	function asem_bk_edit_manifest( string $path, callable $edit ): void {
		$z = new ZipArchive();
		$z->open( $path );
		$m = json_decode( (string) $z->getFromName( 'manifest.json' ), true );
		$m = $edit( $m );
		$z->addFromString( 'manifest.json', wp_json_encode( $m, JSON_PRETTY_PRINT ) );
		$z->close();
	}

	/** Filtro dei codici di sicurezza: finge di essere su un altro sito (stessi dati, codici diversi). */
	function asem_bk_salt_filter( $salt, $scheme = '' ) {
		return 'auth' === $scheme ? 'codici-di-un-altro-sito-' . $salt : $salt;
	}

	/** Accende o spegne la finzione «un altro sito» (le chiavi lette si dimenticano). */
	function asem_bk_other_site( bool $on ): void {
		remove_filter( 'salt', 'asem_bk_salt_filter', 10 );
		if ( $on ) {
			add_filter( 'salt', 'asem_bk_salt_filter', 10, 2 );
		}
		\AssociazioneSemplice\FileKey::reset();
	}
}

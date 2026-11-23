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

	/** Un metodo privato di Backup (per la copia «com'era»). */
	function asem_bk_private( string $method, array $args = array() ) {
		$m = new \ReflectionMethod( \AssociazioneSemplice\Backup::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	/**
	 * La copia di sicurezza come la faceva il plugin prima del lavoro a tappe (L10): tutto in una richiesta, tabelle lette a pagine con OFFSET.
	 * Serve solo a confrontare: la copia nuova deve avere le stesse voci, con lo stesso contenuto.
	 */
	function asem_bk_export_legacy( bool $with_files = false ): string {
		$db     = \AssociazioneSemplice\Db::db();
		$path   = wp_tempnam( 'asem-copia-prima' );
		$zip    = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$tmp    = array();
		$counts = array();
		$hashes = array();
		$plain  = array();
		$fields = array();
		foreach ( \AssociazioneSemplice\PrivateFiles::sources() as $src ) {
			foreach ( $src['fields'] as $col ) {
				$fields[ 'asem_' . $src['table'] ][] = (string) $col;
			}
		}
		foreach ( \AssociazioneSemplice\Backup::tables() as $name ) {
			$tbl   = $db->prefix . $name;
			$f     = wp_tempnam( 'asem-t' );
			$tmp[] = $f;
			$h     = fopen( $f, 'wb' );
			$n     = 0;
			for ( $off = 0; ; $off += \AssociazioneSemplice\Backup::CHUNK ) {
				$rows = $db->get_results( $db->prepare( "SELECT * FROM `$tbl` ORDER BY 1 LIMIT %d OFFSET %d", \AssociazioneSemplice\Backup::CHUNK, $off ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $r ) {
					foreach ( $fields[ $name ] ?? array() as $col ) {
						$v = (string) ( $r[ $col ] ?? '' );
						if ( '' !== $v && ! \AssociazioneSemplice\FileCrypt::is_field( $v ) ) {
							$e = \AssociazioneSemplice\PrivateFiles::field_encrypt( substr( $name, 5 ), $col, (int) $r['id'], $v );
							if ( $e !== $v ) {
								$r[ $col ] = $e;
							} else {
								$plain[ $name . '.' . $col ] = ( $plain[ $name . '.' . $col ] ?? 0 ) + 1;
							}
						}
					}
					fwrite( $h, wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
					$n++;
				}
			}
			fclose( $h );
			$counts[ $name ] = $n;
			$hashes[ 'tabelle/' . $name . '.jsonl' ] = (string) hash_file( 'sha256', $f );
			$zip->addFile( $f, 'tabelle/' . $name . '.jsonl' );
		}
		$plain_files = 0;
		$files       = array();
		if ( $with_files ) {
			$all = glob( \AssociazioneSemplice\Attachments::dir() . '/*' ) ?: array();
			foreach ( $all as $f ) {
				if ( is_file( $f ) && preg_match( '/^[0-9a-f]{32}$/', basename( $f ) ) ) {
					$hashes[ 'allegati/' . basename( $f ) ] = (string) hash_file( 'sha256', $f );
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[]      = basename( $f );
					$plain_files += \AssociazioneSemplice\FileCrypt::is_encrypted_file( $f ) ? 0 : 1;
				}
			}
			foreach ( \AssociazioneSemplice\PrivateFolder::left_behind() as $f ) {
				if ( ! in_array( basename( $f ), $files, true ) ) {
					$hashes[ 'allegati/' . basename( $f ) ] = (string) hash_file( 'sha256', $f );
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[]      = basename( $f );
					$plain_files += \AssociazioneSemplice\FileCrypt::is_encrypted_file( $f ) ? 0 : 1;
				}
			}
		}
		$index = array();
		foreach ( (array) apply_filters( 'asem_backup_documents', array() ) as $d ) {
			if ( ! is_array( $d ) || ! isset( $d['name'], $d['path'] ) ) {
				continue;
			}
			$dn = (string) $d['name'];
			if ( 1 !== preg_match( '#^documenti/[A-Za-z0-9/_.-]+$#', $dn ) || false !== strpos( $dn, '..' ) || 'documenti/indice.json' === $dn || ! is_file( (string) $d['path'] ) ) {
				continue;
			}
			$zip->addFile( (string) $d['path'], $dn );
			$hashes[ $dn ] = (string) hash_file( 'sha256', (string) $d['path'] );
			$entry = array( 'name' => $dn, 'sha256' => (string) ( $d['sha256'] ?? '' ) );
			if ( ! empty( $d['file_sha256'] ) ) {
				$entry['file_sha256'] = (string) $d['file_sha256'];
			}
			$index[] = $entry;
		}
		if ( $index ) {
			$body = (string) wp_json_encode( array( 'files' => $index ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			$hashes['documenti/indice.json'] = hash( 'sha256', $body );
			$zip->addFromString( 'documenti/indice.json', $body );
		}
		$bundle = \AssociazioneSemplice\FileKey::bundle();
		$add    = function ( string $name, string $body ) use ( $zip, &$hashes ): void {
			$hashes[ $name ] = hash( 'sha256', $body );
			$zip->addFromString( $name, $body );
		};
		if ( $bundle ) {
			$add( 'chiavi.json', (string) wp_json_encode( $bundle, JSON_PRETTY_PRINT ) );
		}
		$add( 'impostazioni.json', (string) wp_json_encode( asem_bk_private( 'settings_payload' ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$add( 'LEGGIMI.txt', (string) asem_bk_private( 'readme', array( $plain_files ) ) );
		$key      = \AssociazioneSemplice\BackupSeal::key( wp_salt( 'auth' ) );
		$manifest = array(
			'plugin' => 'associazionesemplice', 'version' => ASEM_VERSION, 'db_version' => (string) get_option( \AssociazioneSemplice\Install::DB_VERSION_OPTION, \AssociazioneSemplice\Install::DB_VERSION ), 'created_at' => \AssociazioneSemplice\Db::now(),
			'site' => home_url(), 'prefix' => $db->prefix, 'tables' => $counts, 'attachments' => count( $files ), 'documents' => count( $index ),
			'encrypted' => (bool) $bundle,
			'plain_fields' => $plain,
			'entries' => $hashes,
			'kfp' => \AssociazioneSemplice\BackupSeal::fingerprint( $key ),
		);
		$manifest['sig'] = \AssociazioneSemplice\BackupSeal::signature( $manifest, $key );
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->close();
		foreach ( $tmp as $f ) {
			wp_delete_file( $f );
		}
		return $path;
	}

	/**
	 * Confronta due copie: stesse voci nello stesso ordine e con lo stesso contenuto. Differenze ammesse: data di creazione e firma del manifest, e i campi
	 * riservati cifrati al momento della copia (un campo cifrato due volte non è mai uguale: si confronta solo che sia cifrato in tutte e due).
	 *
	 * @return string[] le differenze trovate (vuoto = uguali)
	 */
	function asem_bk_compare( string $a, string $b ): array {
		$za = new ZipArchive();
		$zb = new ZipArchive();
		if ( true !== $za->open( $a ) || true !== $zb->open( $b ) ) {
			return array( 'una delle due copie non si apre' );
		}
		$names = function ( ZipArchive $z ): array {
			$out = array();
			for ( $i = 0; $i < $z->numFiles; $i++ ) {
				$out[] = (string) $z->getNameIndex( $i );
			}
			return $out;
		};
		$diff = array();
		$na   = $names( $za );
		$nb   = $names( $zb );
		if ( $na !== $nb ) {
			$diff[] = 'voci diverse: ' . implode( ',', array_diff( $na, $nb ) ) . ' | ' . implode( ',', array_diff( $nb, $na ) ) . ( array_diff( $na, $nb ) || array_diff( $nb, $na ) ? '' : ' (ordine)' );
		}
		$same = array(); // voci uguali byte per byte: la loro impronta nel manifest deve coincidere
		foreach ( array_intersect( $na, $nb ) as $n ) {
			if ( 'manifest.json' === $n ) {
				continue;
			}
			$ca = (string) $za->getFromName( $n );
			$cb = (string) $zb->getFromName( $n );
			if ( $ca === $cb ) {
				$same[ $n ] = true;
				continue;
			}
			if ( 0 !== strpos( $n, 'tabelle/' ) ) {
				$diff[] = 'contenuto diverso: ' . $n;
				continue;
			}
			$la = explode( "\n", $ca );
			$lb = explode( "\n", $cb );
			if ( count( $la ) !== count( $lb ) ) {
				$diff[] = 'righe diverse in ' . $n . ' (' . count( $la ) . ' e ' . count( $lb ) . ')';
				continue;
			}
			foreach ( $la as $i => $line ) {
				if ( $line === $lb[ $i ] ) {
					continue;
				}
				$ra = json_decode( $line, true );
				$rb = json_decode( $lb[ $i ], true );
				if ( ! is_array( $ra ) || ! is_array( $rb ) || array_keys( $ra ) !== array_keys( $rb ) ) {
					$diff[] = 'riga ' . $i . ' diversa in ' . $n;
					break;
				}
				foreach ( $ra as $k => $v ) {
					if ( $v !== $rb[ $k ] && ! ( \AssociazioneSemplice\FileCrypt::is_field( (string) $v ) && \AssociazioneSemplice\FileCrypt::is_field( (string) $rb[ $k ] ) ) ) {
						$diff[] = 'campo ' . $k . ' diverso alla riga ' . $i . ' di ' . $n;
						break 2;
					}
				}
			}
		}
		$ma = json_decode( (string) $za->getFromName( 'manifest.json' ), true );
		$mb = json_decode( (string) $zb->getFromName( 'manifest.json' ), true );
		$za->close();
		$zb->close();
		if ( ! is_array( $ma ) || ! is_array( $mb ) ) {
			return array_merge( $diff, array( 'manifest non leggibile' ) );
		}
		$ea = (array) ( $ma['entries'] ?? array() );
		$eb = (array) ( $mb['entries'] ?? array() );
		if ( array_keys( $ea ) !== array_keys( $eb ) ) {
			$diff[] = 'impronte del manifest per voci diverse';
		}
		foreach ( $same as $n => $unused ) {
			if ( ( $ea[ $n ] ?? 'a' ) !== ( $eb[ $n ] ?? 'b' ) ) {
				$diff[] = 'impronta diversa nel manifest: ' . $n;
			}
		}
		foreach ( array( 'created_at', 'sig', 'entries', 'a_tappe' ) as $k ) { // «a_tappe»: ore di inizio e fine della copia a tappe (la prova lo controlla a parte)
			unset( $ma[ $k ], $mb[ $k ] );
		}
		foreach ( array_unique( array_merge( array_keys( $ma ), array_keys( $mb ) ) ) as $k ) {
			if ( ( $ma[ $k ] ?? null ) !== ( $mb[ $k ] ?? null ) ) {
				$diff[] = 'manifest: «' . $k . '» diverso (' . wp_json_encode( $ma[ $k ] ?? null ) . ' e ' . wp_json_encode( $mb[ $k ] ?? null ) . ')';
			}
		}
		return $diff;
	}
}

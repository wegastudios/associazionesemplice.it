<?php
/**
 * Dopo l'aggiornamento (free + Pro correnti installati sopra i vecchi, senza disinstallare): dati, schema, documento cifrato e chiave dei file.
 *   wp eval-file wp-content/prove/prova-pro-verifica.php
 */

use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\FileCrypt;
use AssociazioneSemplice\FileKey;
use AssociazioneSemplice\Install;
use AssociazioneSemplice\PrivateFiles;
use AssociazioneSemplice\ProSchema;

require_once __DIR__ . '/prova-pro-comune.php';

$prima = get_option( 'asem_prova_pro' );
dati_ok( is_array( $prima ) && ! empty( $prima['impronta'] ), 'dati salvati prima dell\'aggiornamento trovati (free ' . ( $prima['free'] ?? '?' ) . ', Pro ' . ( $prima['pro'] ?? '?' ) . ', DB ' . ( $prima['db'] ?? '?' ) . ', schema Pro ' . ( $prima['pro_db'] ?? '?' ) . ')' );
dati_ok( defined( 'ASEM_PRO_VERSION' ), 'Pro ancora attivo dopo l\'aggiornamento (versione ' . ASEM_PRO_VERSION . ', gratuito ' . ASEM_VERSION . ')' );

// Conteggi e importi
$ora = pro_impronta();
dati_ok( $ora === $prima['impronta'], 'conteggi e importi identici a prima: ' . wp_json_encode( $ora ) );
dati_ok( $prima['saldo'] === Credits::balance( (int) $prima['socio'] ), 'il saldo dei crediti è quello di prima (' . $prima['saldo'] . ')' );

// Schema
dati_ok( (string) get_option( 'asem_db_version', '' ) === Install::DB_VERSION && version_compare( $prima['db'], Install::DB_VERSION, '<=' ), 'schema del gratuito: DB ' . $prima['db'] . ' → ' . get_option( 'asem_db_version' ) . ' (livello della build: ' . Install::DB_VERSION . ')' );
dati_ok( (string) get_option( ProSchema::VERSION_OPTION, '' ) === ProSchema::VERSION && version_compare( $prima['pro_db'], ProSchema::VERSION, '<=' ), 'schema del Pro: ' . $prima['pro_db'] . ' → ' . get_option( ProSchema::VERSION_OPTION ) . ' (livello della build: ' . ProSchema::VERSION . ')' );

// Documento cifrato e chiave dei file
$chiav = pro_chiave();
dati_ok( $chiav === $prima['chiave'], 'chiave dei file intatta: stesso stato, stesso sito, stessa chiave in uso' );
$check = FileKey::check();
dati_ok( ! empty( $check['valid'] ), 'controllo «chiave valida»: tutte le chiavi si aprono' );
$row = Documents::get( (int) $prima['doc'] );
dati_ok( is_array( $row ) && 1 === (int) $row['enc_ver'] && $row['file_sha256'] === $prima['sha'], 'il documento è ancora registrato come cifrato, con la stessa impronta' );
$path = \AssociazioneSemplice\Attachments::dir() . '/' . $row['file_stored'];
dati_ok( FileCrypt::is_encrypted_file( $path ), 'sul disco il file è cifrato' );
$read = PrivateFiles::prepare_read( $path, $row['file_stored'], $row['enc_ver'], $row['file_sha256'], 'doc' );
$h    = fopen( $path, 'rb' );
$buf  = '';
FileCrypt::decrypt_stream(
	$h,
	function ( string $d ) use ( &$buf ): void {
		$buf .= $d;
	},
	PrivateFiles::key_for(),
	PrivateFiles::ad( $row['file_stored'] )
);
fclose( $h );
dati_ok( hash( 'sha256', $buf ) === $prima['sha'] && false !== strpos( $buf, $prima['marker'] ) && ! empty( $read['enc'] ), 'il documento cifrato si apre ancora e il contenuto è quello di partenza' );
WP_CLI::success( 'Free ' . $prima['free'] . ' + Pro ' . $prima['pro'] . ' → build corrente (free ' . ASEM_VERSION . ', Pro ' . ASEM_PRO_VERSION . '): dati, schema e chiave dei file conservati.' );

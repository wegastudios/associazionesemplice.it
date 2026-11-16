<?php
/**
 * Prove della cifratura a riposo dei file riservati dentro un WordPress reale: caricamento, lettura, controllo dell'impronta, chiave che non coincide,
 * migrazione dei file già caricati (a lotti, ripetibile, interrotta), copia di sicurezza cifrata con frase segreta e ripristino anche con una chiave
 * diversa, libreria sodium assente. Si carica alla fine di smoke.php e di smoke_free.php, prima della prova della cartella riservata.
 */

use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\Backup;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\FileCrypt;
use AssociazioneSemplice\FileCryptError;
use AssociazioneSemplice\FileKey;
use AssociazioneSemplice\FileMigration;
use AssociazioneSemplice\Inventory;
use AssociazioneSemplice\PrivateFiles;

$fc_ok = function_exists( 'free_ok' ) ? 'free_ok' : 'asem_ok';
global $wpdb;

if ( ! FileCrypt::available() ) {
	$fc_ok( true, 'cifratura: la libreria sodium manca in questo ambiente, prove saltate' );
	return;
}

wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$fc_dir = Attachments::prepare_dir();

// La chiave chiusa si riapre da sola con la frase automatica o il codice di licenza: le prove che vogliono la chiave chiusa spengono questa riapertura
// (la accende di nuovo il blocco «Chiave: richieste insieme…» e alla fine del file).
$fk_auto = function ( bool $on ): void {
	FileKey::$auto_reopen = $on;
};
$fk_auto( false );

$fc_marker = 'MARCATORE-SEGRETO-' . bin2hex( random_bytes( 4 ) );
$fc_tmp    = function ( string $content ) {
	$f = wp_tempnam( 'asem-fc' );
	file_put_contents( $f, $content );
	return $f;
};
$fc_file   = function ( string $name, string $path, string $mime = 'application/pdf' ): array {
	return array( 'name' => $name, 'tmp_name' => $path, 'size' => (int) filesize( $path ), 'mime' => $mime );
};
$fc_plain  = function ( string $path, string $rel ): string {
	$h   = fopen( $path, 'rb' );
	$buf = '';
	FileCrypt::decrypt_stream(
		$h,
		function ( string $d ) use ( &$buf ): void {
			$buf .= $d;
		},
		PrivateFiles::key_for(),
		PrivateFiles::ad( $rel )
	);
	fclose( $h );
	return $buf;
};
$fc_reason = function ( callable $f ): string {
	try {
		$f();
	} catch ( FileCryptError $e ) {
		return $e->reason;
	}
	return '';
};
$fc_leak   = function () use ( $fc_dir, $fc_marker ): array {
	$out = array();
	foreach ( glob( $fc_dir . '/*' ) ?: array() as $f ) {
		if ( is_file( $f ) && false !== strpos( (string) file_get_contents( $f ), $fc_marker ) ) {
			$out[] = $f;
		}
	}
	return $out;
};
$fc_parts  = function () use ( $fc_dir ): array {
	return glob( $fc_dir . '/*.part-*' ) ?: array();
};

// ---------- Caricamento: documenti, foto dell'inventario, allegati ----------
$fc_text = "%PDF-1.4\n" . $fc_marker . "\n" . str_repeat( 'x', 70000 ); // più di un blocco da 64 KiB
$fc_src  = $fc_tmp( $fc_text );
$fc_doc  = Documents::save( 0, array( 'title' => 'Prova cifratura', 'visibility' => 'staff' ), $fc_file( 'prova.pdf', $fc_src ), 1 );
$fc_row  = Documents::get( $fc_doc );
$fc_path = $fc_dir . '/' . $fc_row['file_stored'];
$fc_ok( 1 === (int) $fc_row['enc_ver'] && hash( 'sha256', $fc_text ) === $fc_row['file_sha256'] && (int) $fc_row['file_size'] === strlen( $fc_text ), 'cifratura: il documento caricato ha la versione di cifratura e l\'impronta del contenuto in chiaro' );
$fc_ok( FileCrypt::is_encrypted_file( $fc_path ) && "\x01" === substr( (string) file_get_contents( $fc_path ), 0, 1 ) && ! $fc_leak() && ! $fc_parts(), 'cifratura: sul disco il file è cifrato, il contenuto in chiaro non resta da nessuna parte e non restano file temporanei' );
$fc_ok( $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'cifratura: il file cifrato si riapre e dà il contenuto di partenza' );
$fc_read = PrivateFiles::prepare_read( $fc_path, $fc_row['file_stored'], $fc_row['enc_ver'], $fc_row['file_sha256'], 'doc' );
$fc_ok( $fc_read['enc'] && strlen( $fc_text ) === $fc_read['length'], 'cifratura: in lettura si controlla il file e la lunghezza dichiarata è quella del contenuto in chiaro, non del file cifrato' );

$fc_png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
$fc_item = Inventory::save_item( 0, array( 'name' => 'Bene con foto cifrata', 'quantity' => 1, 'state' => 'good' ), $fc_file( 'foto.png', $fc_tmp( $fc_png ), 'image/png' ) );
$fc_irow = Inventory::item( $fc_item );
$fc_ok( 1 === (int) $fc_irow['enc_ver'] && hash( 'sha256', $fc_png ) === $fc_irow['file_sha256'] && $fc_png === $fc_plain( $fc_dir . '/' . $fc_irow['file_stored'], $fc_irow['file_stored'] ), 'cifratura: anche la foto di un bene si salva cifrata' );

$fc_att = Attachments::save_file( $fc_file( 'scontrino.pdf', $fc_tmp( $fc_text ) ) );
$wpdb->insert( Db::t( 'attachments' ), array( 'transaction_id' => 987654, 'original_name' => 'scontrino.pdf', 'stored_name' => $fc_att['stored'], 'mime' => 'application/pdf', 'size_bytes' => strlen( $fc_text ), 'sha256' => $fc_att['sha256'], 'enc_ver' => $fc_att['enc_ver'], 'created_at' => Db::now() ) );
$fc_ok( 1 === $fc_att['enc_ver'] && FileCrypt::is_encrypted_file( $fc_dir . '/' . $fc_att['stored'] ) && ! $fc_leak(), 'cifratura: un allegato salvato con save_file() è cifrato' );

// ---------- Controlli in lettura ----------
$fc_ok( 'hash' === $fc_reason( function () use ( $fc_path, $fc_row ) { PrivateFiles::verify( $fc_path, $fc_row['file_stored'], str_repeat( '0', 64 ) ); } ), 'cifratura: se l\'impronta registrata non coincide si ferma con un errore chiaro, senza rifare nulla' );
$fc_move = $fc_dir . '/' . bin2hex( random_bytes( 16 ) );
copy( $fc_path, $fc_move );
$fc_ok( 'auth' === $fc_reason( function () use ( $fc_move ) { PrivateFiles::verify( $fc_move, basename( $fc_move ) ); } ), 'cifratura: un file copiato con un altro nome (spostato altrove) non si apre' );
unlink( $fc_move );
$fc_doc2  = Documents::save( 0, array( 'title' => 'Da manomettere', 'visibility' => 'staff' ), $fc_file( 'b.pdf', $fc_tmp( $fc_text ) ), 1 );
$fc_row2  = Documents::get( $fc_doc2 );
$fc_path2 = $fc_dir . '/' . $fc_row2['file_stored'];
$fc_bytes = (string) file_get_contents( $fc_path2 );
$fc_bytes[ 100 ] = chr( ord( $fc_bytes[100] ) ^ 1 );
file_put_contents( $fc_path2, $fc_bytes );
$fc_ok( 'auth' === $fc_reason( function () use ( $fc_path2, $fc_row2 ) { PrivateFiles::verify( $fc_path2, $fc_row2['file_stored'], $fc_row2['file_sha256'] ); } ), 'cifratura: un file manomesso non si apre' );
$fc_msg = PrivateFiles::message( 'doc', 'auth' );
$fc_ok( false !== strpos( $fc_msg, 'non leggibile su questo sito' ) && false !== strpos( PrivateFiles::message( 'cert', 'nokey' ), 'Certificato non leggibile su questo sito' ) && false !== strpos( PrivateFiles::message( 'att', 'hash' ), 'Allegato non leggibile' ) && false !== strpos( PrivateFiles::message( 'photo', 'truncated' ), 'Foto non leggibile' ) && false !== strpos( PrivateFiles::message( 'refund', 'format' ), 'Giustificativo non leggibile' ), 'cifratura: il messaggio «non leggibile su questo sito» c\'è per ogni tipo di file' );
Documents::delete( $fc_doc2 );
$fc_ok( ! is_file( $fc_path2 ), 'cifratura: cancellare un documento cancella anche il file cifrato' );

// ---------- Chiave valida e chiave che non coincide ----------
$fc_check = FileKey::check();
$fc_ok( $fc_check['valid'] && 'ok' === $fc_check['state'] && 'encrypt' === FileKey::mode(), 'chiave: valida (blocco di prova) e i nuovi file si cifrano' );
$fc_ok( false !== strpos( \AssociazioneSemplice\Admin\FileCryptPanel::status_line(), 'valida' ), 'chiave: lo stato «valida» si vede nelle pagine' );

// ---------- La frase: automatica, nessun blocco ----------
$fc_ts   = FileKey::activated_at();
$fc_lic0 = FileKey::license_code();
$fc_auto = '' !== $fc_lic0 ? $fc_lic0 : $fc_ts;
$fc_ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fc_ts ), 'frase: la data e ora di attivazione è leggibile (anno-mese-giorno ore:minuti:secondi, UTC)' );
$fc_ok( ( '' !== $fc_lic0 ? 'license' : 'timestamp' ) === FileKey::phrase_kind() && 'encrypt' === FileKey::mode(), 'frase: nessun blocco, la cifratura è attiva da subito con la frase automatica' );
$fc_ok( false !== strpos( html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::phrase_line(), ENT_QUOTES, 'UTF-8' ), '' !== $fc_lic0 ? 'codice di licenza' : $fc_ts ), 'frase: la schermata dice quale frase è in uso' );
$fc_ok( in_array( FileCrypt::kdf_level(), array( 'strong', 'light', 'basic' ), true ), 'frase: il server dichiara il metodo con cui protegge la frase' );
FileCrypt::$kdf_force = 'pbkdf2';
$fc_ok( 'basic' === FileCrypt::kdf_level() && false !== strpos( \AssociazioneSemplice\Admin\FileCryptPanel::kdf_note(), 'almeno quattro parole' ), 'frase: con poca memoria la schermata spiega il metodo alternativo e chiede almeno quattro parole' );
FileCrypt::$kdf_force = '';
$fc_rphrase = $fc_auto;

$fc_orig = wp_salt( 'auth' );
$fc_salt = function ( $s, $scheme ) {
	return 'auth' === $scheme ? 'altri-codici-' . $s : $s;
};
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_new = wp_salt( 'auth' );
$fc_ok( 'mismatch' === FileKey::state() && 'blocked' === FileKey::mode() && ! FileKey::check()['valid'], 'chiave: se i codici di sicurezza cambiano la chiave non si apre e lo stato lo dice' );
$fc_ok( 'nokey' === $fc_reason( function () use ( $fc_path, $fc_row ) { PrivateFiles::verify( $fc_path, $fc_row['file_stored'] ); } ), 'chiave: con la chiave che non coincide il file non si apre e l\'errore è «chiave non disponibile»' );
$fc_throw = (string) asem_or_free_throws( function () use ( $fc_file, $fc_tmp, $fc_text ) { Attachments::save_file( $fc_file( 'x.pdf', $fc_tmp( $fc_text ) ) ); } );
$fc_ok( false !== strpos( $fc_throw, 'non coincide' ) && 'blocked' === FileKey::mode() && ! $fc_leak(), 'chiave: con la chiave che non coincide i nuovi file non si salvano (né cifrati né in chiaro)' );
$fc_alert = \AssociazioneSemplice\Admin\FileCryptPanel::alerts_html();
$fc_ok( false !== strpos( $fc_alert, 'non si apre su questo sito' ) && false !== strpos( \AssociazioneSemplice\Admin\FileCryptPanel::status_line(), 'non valida' ), 'chiave: avviso all\'amministratore e riga di stato quando la chiave non si apre' );
$fc_ok( null !== asem_or_free_throws( function () { FileKey::recover( '', 'valori-sbagliati', '' ); } ), 'chiave: con valori sbagliati la chiave non si riapre' );
$fc_n = FileKey::recover( '', $fc_orig, '' ); // i vecchi codici: la chiave dei dati si avvolge di nuovo con quelli di adesso
$fc_ok( $fc_n >= 1 && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ) && FileKey::check()['valid'], 'chiave: con i vecchi codici la chiave si riapre e si avvolge di nuovo, senza cifrare i file un\'altra volta' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state(), 'chiave: tolto il cambio, la chiave avvolta con i codici nuovi non coincide più (come previsto)' );
FileKey::recover( '', $fc_new, '' );
$fc_ok( 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'chiave: rimessi i codici di prima tutto torna leggibile' );

// codici di sicurezza cambiati e vecchi valori persi: si riapre tutto con la frase di recupero, senza ricifrare
$fc_h0 = hash_file( 'sha256', $fc_path );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state(), 'recupero: con i codici cambiati la chiave non si apre' );
$fc_ok( false !== strpos( (string) asem_or_free_throws( function () { FileKey::recover_with_phrase( 'una frase sbagliata ma lunga' ); } ), 'Con questa frase la chiave non si apre' ), 'recupero: con una frase sbagliata la chiave non si riapre' );
$fc_ok( FileKey::recover_with_phrase( $fc_rphrase ) >= 1 && 'ok' === FileKey::state() && FileKey::check()['valid'] && hash_file( 'sha256', $fc_path ) === $fc_h0 && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'recupero: con la frase di recupero la chiave si riapre e i file si leggono, senza cifrarli di nuovo' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
$fc_ok( 'ok' === FileKey::state() && FileKey::check()['valid'], 'recupero: tornati ai codici di prima la chiave è di nuovo valida' );

// la licenza compare: la frase diventa il codice di licenza e la chiave si avvolge anche con quello, senza toccare i file
$fc_code   = 'CODICE-DI-LICENZA-PROVA-0001';
$fc_lic_cb = function ( $c ) use ( $fc_code ) {
	return $fc_code;
};
add_filter( 'asem_file_license_code', $fc_lic_cb, 99 );
FileKey::reset();
FileKey::mode();
$fc_b1 = FileKey::bundle();
$fc_e1k = reset( $fc_b1['keys'] );
$fc_ok( 'license' === FileKey::phrase_kind() && hash_file( 'sha256', $fc_path ) === $fc_h0 && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ) && ! empty( $fc_e1k['l']['w'] ) && ! empty( $fc_e1k['r']['w'] ), 'licenza: la frase diventa il codice di licenza e la chiave si avvolge anche con quello, senza ricifrare i file' );
$fc_ok( false !== strpos( html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::copy_note(), ENT_QUOTES, 'UTF-8' ), 'segreto del codice' ), 'licenza: la schermata dice che la copia vale quanto il segreto del codice' );
// codici di sicurezza cambiati e frase persa: il codice di licenza riapre
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state() && FileKey::recover_with_phrase( $fc_code ) >= 1 && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'licenza: con i codici cambiati il codice di licenza riapre la chiave' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
// una frase scelta: il codice di licenza apre comunque
$fc_custom = 'una frase mia, lunga e facile da ricordare';
$fc_ok( false !== strpos( (string) asem_or_free_throws( function () { FileKey::set_custom_phrase( 'corta', 'corta' ); } ), 'almeno 12' ) && false !== strpos( (string) asem_or_free_throws( function () use ( $fc_custom ) { FileKey::set_custom_phrase( $fc_custom, 'un\'altra frase lunga e diversa' ); } ), 'non coincidono' ), 'frase scelta: almeno 12 caratteri e da ripetere uguale' );
FileKey::set_custom_phrase( $fc_custom, $fc_custom );
$fc_ok( 'custom' === FileKey::phrase_kind() && 'encrypt' === FileKey::mode() && FileKey::check()['valid'] && hash_file( 'sha256', $fc_path ) === $fc_h0, 'frase scelta: cambiata in qualunque momento, senza toccare i file' );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state() && null !== asem_or_free_throws( function () { FileKey::recover_with_phrase( 'una frase sbagliata ma lunga' ); } ) && FileKey::recover_with_phrase( $fc_code ) >= 1 && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'frase scelta: dimenticata la frase, il codice di licenza apre comunque i documenti' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state() && FileKey::recover_with_phrase( $fc_custom ) >= 1 && 'ok' === FileKey::state(), 'frase scelta: la frase scelta apre la chiave' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
// la licenza sparisce: la frase torna quella di prima (senza licenza) e il codice non apre più
remove_filter( 'asem_file_license_code', $fc_lic_cb, 99 );
FileKey::reset();
FileKey::use_auto_phrase();
$fc_b2  = FileKey::bundle();
$fc_e2k = reset( $fc_b2['keys'] );
$fc_ok( ( '' !== $fc_lic0 ? 'license' : 'timestamp' ) === FileKey::phrase_kind() && ( '' === $fc_lic0 ) === empty( $fc_e2k['l']['w'] ) && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'licenza: se la licenza sparisce la frase torna quella automatica e il codice non avvolge più la chiave' );

// ---------- Chiave: richieste insieme, frasi con spazi, riapertura da sola ----------
$fk_names = array( FileKey::OPT_KEYS, FileKey::OPT_SITE, FileKey::OPT_TS, FileKey::OPT_CHANGED );
$fk_row   = function ( string $name ) use ( $wpdb ) { // il valore nel database, senza passare dalla cache di questa richiesta
	return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s', $name ) );
};
$fk_flush = function () use ( $fk_names ): void { // dimentica ciò che questa richiesta ha letto
	foreach ( $fk_names as $n ) {
		wp_cache_delete( $n, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	FileKey::reset();
};
$fk_raw   = function ( string $name, $raw ) use ( $wpdb, $fk_flush ): void { // come scriverebbe un'altra richiesta: nel database, la cache di questa non lo sa
	if ( null === $raw ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	} else {
		$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'no' ) );
	}
};
$fk_keep  = array();
foreach ( $fk_names as $fk_n ) {
	$fk_keep[ $fk_n ] = $fk_row( $fk_n );
}
$fk_back  = function () use ( $fk_names, $fk_keep, $fk_raw, $fk_flush ): void { // rimette com'erano chiave, identificativo, data e segno del cambio frase
	foreach ( $fk_names as $n ) {
		$fk_raw( $n, $fk_keep[ $n ] );
	}
	$fk_flush();
};
$fk_wipe  = function () use ( $fk_names, $fk_raw, $fk_flush ): void { // sito senza chiave, senza identificativo e senza data
	foreach ( $fk_names as $n ) {
		$fk_raw( $n, null );
	}
	$fk_flush();
};
$fk_audit = function ( string $action ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s', $action ) );
};

// Creazione della chiave: la prima richiesta crea chiave, identificativo e data, e lo scrive nel registro azioni
$fk_c0 = $fk_audit( 'file_crypt.key_created' );
$fk_wipe();
$fk_b_mode = FileKey::mode(); // la richiesta «B»
$fk_b_keys = (string) $fk_row( FileKey::OPT_KEYS );
$fk_b_site = (string) $fk_row( FileKey::OPT_SITE );
$fk_b_ts   = (string) $fk_row( FileKey::OPT_TS );
$fk_bo     = maybe_unserialize( $fk_b_keys );
$fk_bid    = is_array( $fk_bo ) && ! empty( $fk_bo['keys'] ) ? (int) array_key_first( $fk_bo['keys'] ) : 0;
$fc_ok( 'encrypt' === $fk_b_mode && 1 === preg_match( '/^[0-9a-f]{16}$/', $fk_b_site ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fk_b_ts ) && $fk_bid > 0, 'chiave nuova: si crea con chiave, identificativo del sito e data di attivazione' );
$fc_ok( $fk_c0 + 1 === $fk_audit( 'file_crypt.key_created' ) && is_array( $fk_bo ) && ( $fk_bo['site'] ?? '' ) === $fk_b_site, 'chiave nuova: la creazione entra nel registro azioni e l\'identificativo del sito è scritto accanto alla chiave' );

// Due richieste insieme: la «A» ha letto «nessuna chiave», nel frattempo la «B» ha creato la sua. Quella della «B» deve restare
$fk_wipe();
FileKey::state();                      // A: nessuna chiave (ricorda l'assenza)
get_option( FileKey::OPT_SITE, '' );   // A: nessun identificativo
get_option( FileKey::OPT_TS, '' );     // A: nessuna data
$fk_raw( FileKey::OPT_KEYS, $fk_b_keys ); // B scrive la sua chiave, il suo identificativo e la sua data
$fk_raw( FileKey::OPT_SITE, $fk_b_site );
$fk_raw( FileKey::OPT_TS, $fk_b_ts );
$fk_a_mode = FileKey::mode();          // A procede: deve trovare e usare quelle di B
$fk_ao     = maybe_unserialize( (string) $fk_row( FileKey::OPT_KEYS ) );
$fc_ok( 'encrypt' === $fk_a_mode && is_array( $fk_ao ) && 1 === count( $fk_ao['keys'] ) && array_key_first( $fk_ao['keys'] ) === $fk_bid && ( $fk_ao['keys'][ $fk_bid ]['w'] ?? 'a' ) === ( $fk_bo['keys'][ $fk_bid ]['w'] ?? 'b' ), 'due richieste insieme: la chiave creata per prima resta, la seconda non la sovrascrive' );
$fc_ok( $fk_b_site === (string) $fk_row( FileKey::OPT_SITE ) && $fk_b_ts === (string) $fk_row( FileKey::OPT_TS ) && $fk_b_site === FileKey::site_id() && null !== FileKey::current() && $fk_bid === FileKey::current()['id'], 'due richieste insieme: identificativo e data restano quelli della prima e la seconda li usa' );
$fc_ok( $fk_c0 + 1 === $fk_audit( 'file_crypt.key_created' ), 'due richieste insieme: la seconda non crea nulla e non scrive nel registro' );

// Identificativo e data letti come assenti mentre un'altra richiesta li crea
$fk_back();
$fk_raw( FileKey::OPT_SITE, null );
$fk_flush();
get_option( FileKey::OPT_SITE, '' );
$fk_raw( FileKey::OPT_SITE, 'abcdef0123456789' );
$fc_ok( 'abcdef0123456789' === FileKey::site_id() && 'abcdef0123456789' === (string) $fk_row( FileKey::OPT_SITE ), 'due richieste insieme: l\'identificativo creato da un\'altra richiesta non viene sostituito' );
$fk_back();
$fk_raw( FileKey::OPT_TS, null );
$fk_flush();
get_option( FileKey::OPT_TS, '' );
$fk_raw( FileKey::OPT_TS, '2020-01-02 03:04:05' );
$fc_ok( '2020-01-02 03:04:05' === FileKey::activated_at() && '2020-01-02 03:04:05' === (string) $fk_row( FileKey::OPT_TS ), 'due richieste insieme: la data di attivazione creata da un\'altra richiesta non viene sostituita' );

// Chiavi già salvate e identificativo perso: non se ne inventa uno nuovo; se è scritto accanto alle chiavi si ritrova
$fk_back();
$fk_ko = maybe_unserialize( (string) $fk_keep[ FileKey::OPT_KEYS ] );
unset( $fk_ko['site'] );
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_ko ) );
$fk_raw( FileKey::OPT_SITE, null );
$fk_flush();
$fc_ok( '' === FileKey::site_id() && null === $fk_row( FileKey::OPT_SITE ), 'identificativo: se esistono già chiavi e l\'identificativo manca non se ne inventa uno nuovo' );
$fk_ko['site'] = 'fedcba9876543210';
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_ko ) );
$fk_flush();
$fc_ok( 'fedcba9876543210' === FileKey::site_id() && 'fedcba9876543210' === (string) $fk_row( FileKey::OPT_SITE ), 'identificativo: perso quello del sito, si ritrova quello scritto accanto alle chiavi' );
$fk_back();
// chiavi di una versione che non scriveva l'identificativo accanto, mai usate per cifrare: al primo uso si dà l'identificativo, una volta sola
unset( $fk_ko['site'] );
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_ko ) );
$fk_raw( FileKey::OPT_SITE, null );
$fk_flush();
$fk_m  = FileKey::mode();
$fk_s1 = (string) $fk_row( FileKey::OPT_SITE );
$fk_ko2 = maybe_unserialize( (string) $fk_row( FileKey::OPT_KEYS ) );
$fc_ok( 'encrypt' === $fk_m && 1 === preg_match( '/^[0-9a-f]{16}$/', $fk_s1 ) && ( $fk_ko2['site'] ?? '' ) === $fk_s1 && $fk_s1 === FileKey::site_id(), 'identificativo: chiavi di prima senza identificativo, al primo uso se ne dà uno e si scrive anche accanto alle chiavi' );
$fk_back();

// Errore del server sul blocco (GET_LOCK dà NULL): per le chiavi vale «blocco non preso», per gli altri usi si procede come sempre
Db::$lock_fake = 'null';
$fk_s = asem_or_free_throws( function () { Db::with_lock( 'asem_prova_blocco', function () {}, 1, true ); } );
$fk_l = 'no';
Db::with_lock( 'asem_prova_blocco', function () use ( &$fk_l ) { $fk_l = 'si'; } );
Db::$lock_fake = 'x';
$fc_ok( null !== $fk_s && 'si' === $fk_l, 'blocco: un errore del server vale «blocco non preso» per le chiavi e gli altri usi procedono come sempre' );

// Nome del blocco: unico per sito (database e prefisso), sempre entro i 64 caratteri di MySQL
$fk_long = str_repeat( 'operazione-molto-lunga-', 6 );
$fk_n1   = Db::lock_name( 'asem_file_key', 'db1|wp_' );
$fk_n2   = Db::lock_name( 'asem_file_key', 'db1|wp2_' );
$fc_ok( $fk_n1 !== $fk_n2 && Db::lock_name( 'asem_file_key', 'db2|wp_' ) !== $fk_n1, 'blocco: due siti con prefisso o database diversi non si bloccano a vicenda' );
$fc_ok( Db::lock_name( 'asem_file_key', 'db1|wp_' ) === $fk_n1 && Db::lock_name( 'asem_file_key' ) === Db::lock_name( 'asem_file_key' ) && Db::lock_name( 'asem_file_key' ) !== Db::lock_name( 'asem_private_move' ), 'blocco: lo stesso sito e la stessa operazione hanno sempre lo stesso nome, operazioni diverse nomi diversi' );
$fc_ok( strlen( $fk_n1 ) <= 64 && strlen( Db::lock_name( $fk_long ) ) <= 64 && Db::lock_name( $fk_long ) !== Db::lock_name( $fk_long . 'x' ), 'blocco: il nome sta nei 64 caratteri di MySQL anche con un nome di operazione lunghissimo, e resta distinto' );

// Blocco occupato al primo avvio: nessun file né campo si cifra con un identificativo vuoto, e dopo lo sblocco tutto funziona
$fk_cnt = function () use ( $fc_dir ): int {
	return count( glob( $fc_dir . '/*' ) ?: array() );
};
$fk_save = function () use ( $fc_file, $fc_tmp, $fc_text ): array {
	return Attachments::save_file( $fc_file( 'blocco.pdf', $fc_tmp( $fc_text ) ) );
};
$fk_wipe();
$fk_n0 = $fk_cnt();
Db::$lock_fake = '0';
$fk_m1 = FileKey::mode();
$fk_t1 = asem_or_free_throws( function () use ( $fk_save ) { $fk_save(); } );
$fk_f1 = PrivateFiles::field_encrypt( 'prova', 'col', 1, 'testo da cifrare' );
Db::$lock_fake = 'x';
$fc_ok( 'blocked' === $fk_m1 && null !== $fk_t1 && 'testo da cifrare' === $fk_f1 && null === $fk_row( FileKey::OPT_KEYS ) && null === $fk_row( FileKey::OPT_SITE ) && $fk_n0 === $fk_cnt(), 'blocco occupato al primo avvio: nessuna chiave, nessun identificativo e nessun file o campo cifrato' );
FileKey::reset();
$fk_a1 = $fk_save();
$fk_e1 = PrivateFiles::field_encrypt( 'prova', 'col', 1, 'testo da cifrare' );
$fc_ok( 'encrypt' === FileKey::mode() && 1 === (int) $fk_a1['enc_ver'] && FileCrypt::is_field( $fk_e1 ) && 'testo da cifrare' === PrivateFiles::field_decrypt( 'prova', 'col', 1, $fk_e1 ), 'blocco occupato al primo avvio: sbloccato, file e campi si cifrano e si riaprono' );
wp_delete_file( $fc_dir . '/' . $fk_a1['stored'] );
$fk_back();
// chiavi già salvate ma identificativo non ritrovabile, con il blocco occupato: stesso rifiuto, nessun uso dell'identificativo vuoto
$fk_ko = maybe_unserialize( (string) $fk_keep[ FileKey::OPT_KEYS ] );
unset( $fk_ko['site'] );
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_ko ) );
$fk_raw( FileKey::OPT_SITE, null );
$fk_flush();
$fk_n0 = $fk_cnt();
Db::$lock_fake = '0';
$fk_m2 = FileKey::mode();
$fk_c2 = FileKey::current();
$fk_k2 = FileKey::key_for( (int) array_key_first( $fk_ko['keys'] ) );
$fk_t2 = asem_or_free_throws( function () use ( $fk_save ) { $fk_save(); } );
Db::$lock_fake = 'x';
$fc_ok( 'blocked' === $fk_m2 && null === $fk_c2 && null === $fk_k2 && null !== $fk_t2 && null === $fk_row( FileKey::OPT_SITE ) && $fk_n0 === $fk_cnt(), 'blocco occupato con chiavi senza identificativo: niente cifratura né decifratura con un identificativo vuoto' );
FileKey::reset();
$fk_a2 = $fk_save();
$fc_ok( 'encrypt' === FileKey::mode() && 1 === (int) $fk_a2['enc_ver'] && 1 === preg_match( '/^[0-9a-f]{16}$/', (string) $fk_row( FileKey::OPT_SITE ) ), 'blocco occupato con chiavi senza identificativo: sbloccato, tutto torna a funzionare' );
wp_delete_file( $fc_dir . '/' . $fk_a2['stored'] );
$wpdb->delete( Db::t( 'attachments' ), array( 'stored_name' => $fk_a2['stored'] ) );
$fk_back();

// Ogni modifica delle chiavi parte da quanto c'è nel database in quel momento: ciò che un'altra richiesta ha aggiunto non va perso
FileKey::state();
$fk_ko = maybe_unserialize( (string) $fk_keep[ FileKey::OPT_KEYS ] );
$fk_ko['keys'][999999] = array( 'w' => 'x', 'by' => 'salt', 'at' => time() ); // un'altra richiesta aggiunge una chiave
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_ko ) );
FileKey::use_auto_phrase();
$fk_ao = maybe_unserialize( (string) $fk_row( FileKey::OPT_KEYS ) );
$fc_ok( is_array( $fk_ao ) && isset( $fk_ao['keys'][999999] ) && count( $fk_ao['keys'] ) === count( $fk_ko['keys'] ), 'due richieste insieme: cambiare la frase non cancella una chiave aggiunta nel frattempo da un\'altra richiesta' );
$fk_back();

// Frase con spazi ai bordi: si salva ripulita e si riapre scritta in tutti e due i modi
$fc_ok( '' !== FileKey::recovery_error( '    abcdef    ', '    abcdef    ' ), 'frase scelta: gli spazi ai bordi non contano per la lunghezza minima' );
$fk_auto( false );
$fk_ph = '   una frase con spazi ai bordi, lunga abbastanza   ';
FileKey::set_custom_phrase( $fk_ph, $fk_ph );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'mismatch' === FileKey::state(), 'frase con spazi: con i codici cambiati la chiave si chiude' );
$fk_e = asem_or_free_throws( function () use ( $fk_ph ) { FileKey::recover_with_phrase( trim( $fk_ph ) ); } );
$fc_ok( null === $fk_e && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'frase con spazi ai bordi: scritta senza spazi riapre la chiave' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fk_e = asem_or_free_throws( function () use ( $fk_ph ) { FileKey::recover_with_phrase( $fk_ph ); } );
$fc_ok( null === $fk_e && 'ok' === FileKey::state(), 'frase con spazi ai bordi: scritta con gli spazi, come incollata, riapre la chiave' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
// una frase già salvata con gli spazi (come faceva la versione di prima) si riapre ancora, scritta com'è
$fk_cur = FileKey::current();
$fk_rec = FileCrypt::kdf_make( $fk_ph );
$fk_lo  = maybe_unserialize( (string) $fk_row( FileKey::OPT_KEYS ) );
$fk_lo['keys'][ $fk_cur['id'] ]['r'] = array( 'w' => FileCrypt::wrap_key( $fk_cur['key'], $fk_rec['key'] ), 'k' => $fk_rec['kdf'] );
$fk_lo['pm'] = 'custom';
$fk_raw( FileKey::OPT_KEYS, maybe_serialize( $fk_lo ) );
$fk_flush();
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fk_e = asem_or_free_throws( function () use ( $fk_ph ) { FileKey::recover_with_phrase( $fk_ph ); } );
$fc_ok( 'mismatch' !== FileKey::state() && null === $fk_e, 'frase con spazi ai bordi: una frase già salvata con gli spazi si riapre scrivendola com\'è' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
$fk_back();

// Vecchi codici di sicurezza con uno spazio in fondo: la chiave è nata da quelli com'erano, non si ripuliscono
$fk_sp = function ( $s, $scheme ) {
	return 'auth' === $scheme ? $s . ' ' : $s;
};
add_filter( 'salt', $fk_sp, 10, 2 );
FileKey::reset();
FileKey::recover( '', $fc_orig, '' ); // la chiave si avvolge con la chiave principale ricavata dai codici con lo spazio
remove_filter( 'salt', $fk_sp, 10 );
FileKey::reset();
$fk_e = asem_or_free_throws( function () use ( $fc_orig ) { FileKey::recover( '', $fc_orig . ' ', '' ); } );
$fc_ok( null === $fk_e && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'vecchi codici: con lo spazio in fondo, scritti com\'erano, la chiave si riapre' );
$fk_back();

// Codici di sicurezza cambiati: la chiave che ha la frase automatica (o il codice di licenza) si riapre da sola e lo scrive nel registro
$fk_auto( true );
$fk_r0 = $fk_audit( 'file_crypt.key_reopened_auto' );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'ok' === FileKey::state() && FileKey::check()['valid'] && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'codici cambiati: con la frase automatica la chiave si riapre da sola, senza che nessuno scriva nulla' );
$fc_ok( $fk_r0 + 1 === $fk_audit( 'file_crypt.key_reopened_auto' ), 'codici cambiati: la riapertura da sola entra nel registro azioni' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
$fc_ok( 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'codici cambiati: rimessi quelli di prima la chiave si apre ancora' );
$fk_back();
if ( '' === $fc_lic0 ) {
	// frase scelta e nessuna licenza: la chiave resta chiusa e la frase la sa solo l'amministratore
	FileKey::set_custom_phrase( $fc_custom, $fc_custom );
	add_filter( 'salt', $fc_salt, 10, 2 );
	FileKey::reset();
	$fc_ok( 'mismatch' === FileKey::state(), 'codici cambiati: con una frase scelta e senza licenza la chiave resta chiusa (la frase la sa solo l\'amministratore)' );
	remove_filter( 'salt', $fc_salt, 10 );
	FileKey::reset();
	$fk_back();
	// frase automatica ma chiave che non si riapre da sola: la schermata mostra la data da scrivere
	$fk_auto( false );
	add_filter( 'salt', $fc_salt, 10, 2 );
	FileKey::reset();
	ob_start();
	\AssociazioneSemplice\Admin\FileCryptPanel::render();
	$fk_pn2 = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( 'mismatch' === FileKey::state() && false !== strpos( $fk_pn2, FileKey::activated_at() ), 'chiave chiusa: la schermata mostra la data e ora di attivazione, che è la frase da scrivere nel plugin gratuito' );
	remove_filter( 'salt', $fc_salt, 10 );
	$fk_auto( true );
	$fk_back();
}
// frase scelta ma con il codice di licenza: il codice apre da solo
$fk_lc = function ( $c ) {
	return 'CODICE-LICENZA-PROVA-0002';
};
add_filter( 'asem_file_license_code', $fk_lc, 99 );
FileKey::reset();
FileKey::mode();
FileKey::set_custom_phrase( $fc_custom, $fc_custom );
add_filter( 'salt', $fc_salt, 10, 2 );
FileKey::reset();
$fc_ok( 'custom' === FileKey::phrase_kind() && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'codici cambiati: con una frase scelta ma con il codice di licenza la chiave si riapre da sola' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
remove_filter( 'asem_file_license_code', $fk_lc, 99 );
$fk_back();
$fk_auto( false );

// ---------- Migrazione dei file già caricati ----------
delete_option( FileMigration::OPT );
$fc_legacy = function ( string $content, ?string $sha, bool $missing = false ) use ( $wpdb, $fc_dir ): array {
	$stored = bin2hex( random_bytes( 16 ) );
	if ( ! $missing ) {
		file_put_contents( $fc_dir . '/' . $stored, $content );
	}
	$wpdb->insert( Db::t( 'documents' ), array( 'title' => 'Vecchio ' . $stored, 'category' => '', 'visibility' => 'staff', 'file_name' => 'vecchio.pdf', 'file_stored' => $stored, 'file_sha256' => $sha, 'file_size' => strlen( $content ), 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
	return array( 'id' => (int) $wpdb->insert_id, 'stored' => $stored, 'content' => $content );
};
$fc_l1 = $fc_legacy( "%PDF-old1\n" . $fc_marker, hash( 'sha256', "%PDF-old1\n" . $fc_marker ) );
$fc_l2 = $fc_legacy( "%PDF-old2\n" . $fc_marker, null ); // senza impronta registrata
$fc_l3 = $fc_legacy( "%PDF-old3\n" . $fc_marker, hash( 'sha256', 'un altro contenuto' ) ); // il file non è più quello registrato
$fc_l4 = $fc_legacy( "%PDF-old4\n" . $fc_marker, hash( 'sha256', "%PDF-old4\n" . $fc_marker ) ); // sarà cifrato ma la riga non verrà aggiornata (interruzione)
$fc_l5 = $fc_legacy( '', null, true ); // il file non c'è più
$fc_ok( 5 <= FileMigration::counts()['pending'] && count( $fc_leak() ) >= 4, 'migrazione: i file vecchi risultano da cifrare' );
$fc_e4 = FileMigration::encrypt_one( $fc_l4['stored'], hash( 'sha256', $fc_l4['content'] ) );
$fc_ok( 'ok' === $fc_e4['status'] && FileCrypt::is_encrypted_file( $fc_dir . '/' . $fc_l4['stored'] ) && null === $wpdb->get_var( 'SELECT enc_ver FROM ' . Db::t( 'documents' ) . ' WHERE id = ' . $fc_l4['id'] ), 'migrazione: file cifrato ma riga non ancora aggiornata (come dopo un\'interruzione)' );
$fc_ok( PrivateFiles::is_enc( $fc_dir . '/' . $fc_l4['stored'], $fc_l4['stored'], null ), 'migrazione: in quel momento il file si legge già come cifrato' );
$fc_b1 = FileMigration::run_batch( 1 ); // un lotto piccolo: il lavoro si ferma a metà e riparte
$fc_ok( $fc_b1['done'] + $fc_b1['already'] + $fc_b1['failed'] + $fc_b1['missing'] >= 1 && ! $fc_b1['busy'], 'migrazione: un lotto di una riga fa solo una riga' );
$fc_s = FileMigration::start();
$fc_state = FileMigration::state();
$fc_get   = function ( array $l ) use ( $wpdb ): array {
	return (array) $wpdb->get_row( 'SELECT enc_ver, file_sha256 FROM ' . Db::t( 'documents' ) . ' WHERE id = ' . $l['id'], ARRAY_A );
};
$fc_ok( ! $fc_state['running'] && 1 === (int) $fc_get( $fc_l1 )['enc_ver'] && 1 === (int) $fc_get( $fc_l2 )['enc_ver'] && 1 === (int) $fc_get( $fc_l4 )['enc_ver'], 'migrazione: a lavoro finito i file vecchi hanno la versione di cifratura' );
$fc_ok( hash( 'sha256', $fc_l2['content'] ) === $fc_get( $fc_l2 )['file_sha256'], 'migrazione: dove l\'impronta mancava viene registrata' );
$fc_ok( $fc_l1['content'] === $fc_plain( $fc_dir . '/' . $fc_l1['stored'], $fc_l1['stored'] ) && $fc_l2['content'] === $fc_plain( $fc_dir . '/' . $fc_l2['stored'], $fc_l2['stored'] ) && $fc_l4['content'] === $fc_plain( $fc_dir . '/' . $fc_l4['stored'], $fc_l4['stored'] ), 'migrazione: nessuna perdita, ogni file si riapre uguale all\'originale' );
$fc_ok( null === $fc_get( $fc_l3 )['enc_ver'] && $fc_l3['content'] === file_get_contents( $fc_dir . '/' . $fc_l3['stored'] ) && FileMigration::state()['failed'] >= 1, 'migrazione: un file che non coincide con l\'impronta registrata resta in chiaro, si conta come non riuscito e non viene cifrato in silenzio' );
$fc_ok( FileMigration::state()['missing'] >= 1 && null === $fc_get( $fc_l5 )['enc_ver'], 'migrazione: un file che non c\'è sul disco si conta come mancante' );
$fc_ok( ! $fc_parts(), 'migrazione: non restano file temporanei' );
$fc_again = FileMigration::start();
$fc_ok( 0 === $fc_again['done'] && 0 === $fc_again['already'], 'migrazione: ripetuta non rifà niente (i file già cifrati si saltano)' );
$fc_ok( $fc_l1['content'] === $fc_plain( $fc_dir . '/' . $fc_l1['stored'], $fc_l1['stored'] ), 'migrazione: dopo la ripetizione i file sono ancora quelli di prima' );
$fc_panel = \AssociazioneSemplice\Admin\BackupPage::class;
ob_start();
$fc_panel::render();
$fc_html = ob_get_clean();
$fc_ok( false !== strpos( $fc_html, 'Cifratura dei file riservati' ) && false !== strpos( $fc_html, 'Chiave valida' ) && false !== strpos( $fc_html, 'asem_enc_start' ), 'pagina Copia di sicurezza: riquadro della cifratura con «Chiave valida» e il pulsante per i file già caricati' );
$fc_htd = html_entity_decode( $fc_html, ENT_QUOTES, 'UTF-8' );
$fc_ok( false !== strpos( $fc_htd, 'Frase per riaprire i documenti' ) && false !== strpos( $fc_htd, 'Cambia la frase' ) && false !== strpos( $fc_html, 'name="rphrase2"' ) && false === strpos( $fc_htd, 'Attiva la cifratura e scegli la frase' ), 'pagina Copia di sicurezza: la frase in uso, «Cambia la frase» con ripetizione e nessun blocco da sbloccare' );
// la riga dei legacy rimasti non deve disturbare le prove seguenti
foreach ( array( $fc_l3, $fc_l5 ) as $fc_x ) {
	$wpdb->delete( Db::t( 'documents' ), array( 'id' => $fc_x['id'] ) );
	wp_delete_file( $fc_dir . '/' . $fc_x['stored'] );
}
$fc_leak_left = $fc_leak();
$fc_ok( ! $fc_leak_left, 'migrazione: nessun contenuto in chiaro resta nella cartella riservata' );

// ---------- Certificati medici già caricati: si cifrano da soli dopo l'aggiornamento (solo con il Pro) ----------
$fc_audit_n = function ( string $action ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s', $action ) );
};
if ( class_exists( '\\AssociazioneSemplice\\Certificates' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::t( 'medical_certs' ) ) ) ) {
	delete_option( FileMigration::CERT_OPT );
	wp_clear_scheduled_hook( FileMigration::CERT_HOOK );
	$fc_cert = function ( string $content, string $sha, string $doctor ) use ( $wpdb, $fc_dir ): array {
		$stored = bin2hex( random_bytes( 16 ) );
		file_put_contents( $fc_dir . '/' . $stored, $content );
		$wpdb->insert( Db::t( 'medical_certs' ), array( 'person_id' => 1, 'kind' => 'other', 'issued_on' => '2026-01-01', 'expires_on' => '2027-01-01', 'doctor' => $doctor, 'file_name' => 'cert.pdf', 'file_stored' => $stored, 'file_sha256' => $sha, 'file_size' => strlen( $content ), 'created_at' => Db::now() ) );
		return array( 'id' => (int) $wpdb->insert_id, 'stored' => $stored, 'content' => $content );
	};
	$fc_cget = function ( array $c ) use ( $wpdb ): array {
		return (array) $wpdb->get_row( 'SELECT enc_ver, doctor, file_name FROM ' . Db::t( 'medical_certs' ) . ' WHERE id = ' . (int) $c['id'], ARRAY_A );
	};
	$fc_c1 = $fc_cert( "%PDF-cert1\n" . $fc_marker, hash( 'sha256', "%PDF-cert1\n" . $fc_marker ), 'Dott. Uno' );
	$fc_c2 = $fc_cert( "%PDF-cert2\n" . $fc_marker, hash( 'sha256', "%PDF-cert2\n" . $fc_marker ), 'Dott. Due' ); // sarà cifrato ma la riga non verrà aggiornata (interruzione)
	$fc_c3 = $fc_cert( "%PDF-cert3\n" . $fc_marker, hash( 'sha256', 'un altro contenuto' ), 'Dott. Tre' ); // il file non è più quello registrato
	$fc_oth = $fc_legacy( "%PDF-altro\n" . $fc_marker, hash( 'sha256', "%PDF-altro\n" . $fc_marker ) ); // un documento comune già caricato: solo il pulsante lo cifra
	$fc_plain_h = array();
	foreach ( array( $fc_c1, $fc_c2, $fc_c3, $fc_oth ) as $fc_x ) {
		$fc_plain_h[ $fc_x['stored'] ] = hash_file( 'sha256', $fc_dir . '/' . $fc_x['stored'] );
	}

	// senza sodium: i certificati restano in chiaro, avviso con il messaggio da inoltrare, nessun errore, nulla di programmato
	$fc_nb = $fc_audit_n( 'file_crypt.certs_auto_blocked' );
	FileCrypt::$disabled = true;
	FileKey::reset();
	FileMigration::auto_certs();
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_al = html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( 'nosodium' === $fc_cs['status'] && ! $fc_cs['running'] && ! wp_next_scheduled( FileMigration::CERT_HOOK ), 'certificati automatici: senza sodium non parte nulla e lo stato lo ricorda' );
	$fc_ok( false !== strpos( $fc_al, 'I certificati medici già caricati restano in chiaro' ) && false !== strpos( $fc_al, 'sodium' ), 'certificati automatici: senza sodium l\'amministratore vede l\'avviso (certificati in chiaro, serve sodium)' );
	ob_start();
	\AssociazioneSemplice\Admin\FileCryptPanel::render();
	$fc_pnl = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( false !== strpos( $fc_pnl, 'asem-sodium-msg' ) && false !== strpos( $fc_pnl, 'certificati medici). Grazie.' ) && false !== strpos( $fc_pnl, 'restano in chiaro' ), 'certificati automatici: senza sodium il riquadro dà il messaggio da copiare e inoltrare a chi gestisce il sito' );
	FileMigration::auto_certs();
	$fc_ok( $fc_nb + 1 === $fc_audit_n( 'file_crypt.certs_auto_blocked' ), 'certificati automatici: il blocco entra nel registro azioni una volta sola' );
	$fc_still = true;
	foreach ( array( $fc_c1, $fc_c2, $fc_c3 ) as $fc_x ) {
		$fc_still = $fc_still && hash_file( 'sha256', $fc_dir . '/' . $fc_x['stored'] ) === $fc_plain_h[ $fc_x['stored'] ] && null === $fc_cget( $fc_x )['enc_ver'];
	}
	$fc_ok( $fc_still, 'certificati automatici: senza sodium i file dei certificati sono rimasti in chiaro, identici' );
	FileCrypt::$disabled = false;
	FileKey::reset();

	// chiave che non si apre: non si tocca nulla, l'avviso rimanda a «Sistemare la chiave»
	add_filter( 'salt', $fc_salt, 10, 2 );
	FileKey::reset();
	FileMigration::auto_certs();
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_al = html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), ENT_QUOTES, 'UTF-8' );
	$fc_still = true;
	foreach ( array( $fc_c1, $fc_c2, $fc_c3 ) as $fc_x ) {
		$fc_still = $fc_still && hash_file( 'sha256', $fc_dir . '/' . $fc_x['stored'] ) === $fc_plain_h[ $fc_x['stored'] ] && null === $fc_cget( $fc_x )['enc_ver'] && 'Dott.' === substr( (string) $fc_cget( $fc_x )['doctor'], 0, 5 );
	}
	$fc_ok( 'nokey' === $fc_cs['status'] && ! wp_next_scheduled( FileMigration::CERT_HOOK ) && $fc_still, 'certificati automatici: con la chiave che non si apre non parte nulla e non si tocca nessun file né campo' );
	$fc_ok( false !== strpos( $fc_al, 'la chiave non si apre su questo sito' ) && false !== strpos( $fc_al, 'Sistemare la chiave' ), 'certificati automatici: con la chiave che non si apre l\'avviso rimanda a «Sistemare la chiave»' );
	FileMigration::certs_cron();
	$fc_ok( 'nokey' === FileMigration::state( FileMigration::CERT_OPT )['status'], 'certificati automatici: un lavoro programmato con la chiave che non si apre non cambia nulla' );
	remove_filter( 'salt', $fc_salt, 10 );
	FileKey::reset();
	$fc_ok( 'ok' === FileKey::state(), 'certificati automatici: la chiave torna valida (prova)' );

	// chiave a posto: parte da sola, solo i certificati, a lotti
	$fc_ns = $fc_audit_n( 'file_crypt.certs_auto_started' );
	FileMigration::auto_certs();
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_al = html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( 'running' === $fc_cs['status'] && $fc_cs['running'] && false !== wp_next_scheduled( FileMigration::CERT_HOOK ) && $fc_ns + 1 === $fc_audit_n( 'file_crypt.certs_auto_started' ), 'certificati automatici: appena la chiave è valida parte da sola, programma il primo lotto e lo scrive nel registro azioni' );
	$fc_ok( false !== strpos( $fc_al, 'La cifratura dei certificati medici è partita.' ) && false !== strpos( $fc_al, 'Cifratura dei file riservati' ), 'certificati automatici: l\'avviso dice che è partita (mentre usi il programma) e dove vedere lo stato' );
	$fc_ok( null === $fc_cget( $fc_c1 )['enc_ver'] && hash_file( 'sha256', $fc_dir . '/' . $fc_c1['stored'] ) === $fc_plain_h[ $fc_c1['stored'] ], 'certificati automatici: la partenza non cifra nulla da sola, il lavoro lo fanno i lotti programmati' );

	// interruzione: un lotto minuscolo, il lavoro programmato che va perso, un file cifrato ma con la riga non aggiornata
	FileMigration::run_batch( 1, FileMigration::CERT_KEY );
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_al = html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( 'running' === $fc_cs['status'] && $fc_cs['running'] && 1 === preg_match( '/in corso: restano \d+/', $fc_al ), 'certificati automatici: a lavoro iniziato l\'avviso dice «in corso: restano n»' );
	wp_clear_scheduled_hook( FileMigration::CERT_HOOK );
	FileMigration::auto_certs();
	$fc_ok( false !== wp_next_scheduled( FileMigration::CERT_HOOK ), 'certificati automatici: se il lavoro programmato va perso, riparte da solo dal punto in cui era' );
	$fc_e2 = FileMigration::encrypt_one( $fc_c2['stored'], hash( 'sha256', $fc_c2['content'] ) );
	$fc_ok( 'ok' === $fc_e2['status'] && null === $fc_cget( $fc_c2 )['enc_ver'], 'certificati automatici: file cifrato ma riga non ancora aggiornata (come dopo un\'interruzione)' );
	$fc_loops2 = 0;
	while ( 'running' === FileMigration::state( FileMigration::CERT_OPT )['status'] && $fc_loops2 < 10 ) {
		FileMigration::certs_cron();
		$fc_loops2++;
	}
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_ok( 'done' === $fc_cs['status'] && $fc_cs['finished'] > 0 && ! $fc_cs['running'], 'certificati automatici: a lotti finisce da solo' );
	$fc_ok( 1 === (int) $fc_cget( $fc_c1 )['enc_ver'] && 1 === (int) $fc_cget( $fc_c2 )['enc_ver'] && $fc_c1['content'] === $fc_plain( $fc_dir . '/' . $fc_c1['stored'], $fc_c1['stored'] ) && $fc_c2['content'] === $fc_plain( $fc_dir . '/' . $fc_c2['stored'], $fc_c2['stored'] ), 'certificati automatici: nessuna perdita, i certificati si riaprono uguali (anche quello interrotto a metà)' );
	$fc_ok( FileCrypt::is_field( (string) $fc_cget( $fc_c1 )['doctor'] ) && FileCrypt::is_field( (string) $fc_cget( $fc_c1 )['file_name'] ), 'certificati automatici: anche medico e nome del file sono cifrati' );
	$fc_ok( null === $fc_cget( $fc_c3 )['enc_ver'] && hash_file( 'sha256', $fc_dir . '/' . $fc_c3['stored'] ) === $fc_plain_h[ $fc_c3['stored'] ] && $fc_cs['failed'] >= 1, 'certificati automatici: un certificato che non coincide con l\'impronta registrata resta in chiaro, intatto, e si conta come non riuscito' );
	$fc_ok( null === $fc_get( $fc_oth )['enc_ver'] && hash_file( 'sha256', $fc_dir . '/' . $fc_oth['stored'] ) === $fc_plain_h[ $fc_oth['stored'] ], 'certificati automatici: gli altri file già caricati non vengono toccati finché non si preme il pulsante' );
	$fc_al = html_entity_decode( \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), ENT_QUOTES, 'UTF-8' );
	$fc_ok( false !== strpos( $fc_al, 'La cifratura dei certificati medici è finita, ma' ) && false !== strpos( $fc_al, 'non si sono potuti cifrare' ) && false !== strpos( $fc_al, 'Apri l\'elenco e premi «Riprova»; se il file non si trova, elimina il certificato dalla pagina Certificati e caricalo di nuovo, oppure ripristina una copia di sicurezza.' ), 'certificati automatici: l\'avviso di fine dice quanti non si sono potuti cifrare e la strada per sistemarli' );
	$fc_ok( $fc_ns + 1 === $fc_audit_n( 'file_crypt.certs_auto_started' ) && $fc_audit_n( 'file_crypt.certs_auto_finished' ) >= 1, 'certificati automatici: partenza e fine sono nel registro azioni' );
	$fc_ok( ! $fc_parts(), 'certificati automatici: non restano file temporanei' );

	// finito: non riparte, non si ricontrolla
	wp_clear_scheduled_hook( FileMigration::CERT_HOOK );
	FileMigration::auto_certs();
	FileMigration::certs_cron();
	$fc_ok( 'done' === FileMigration::state( FileMigration::CERT_OPT )['status'] && ! wp_next_scheduled( FileMigration::CERT_HOOK ) && $fc_ns + 1 === $fc_audit_n( 'file_crypt.certs_auto_started' ), 'certificati automatici: a lavoro finito non riparte e non programma più nulla' );
	$fc_s = FileMigration::state( FileMigration::CERT_OPT );
	$fc_s['finished'] = time() - ( FileMigration::CERT_NOTICE + 1 ) * DAY_IN_SECONDS;
	update_option( FileMigration::CERT_OPT, $fc_s, true );
	$fc_ok( '' === \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html(), 'certificati automatici: l\'avviso di fine si chiude da solo dopo due settimane' );

	// il pulsante resta quello di prima: cifra gli altri file
	FileMigration::start();
	$fc_ok( 1 === (int) $fc_get( $fc_oth )['enc_ver'] && $fc_oth['content'] === $fc_plain( $fc_dir . '/' . $fc_oth['stored'], $fc_oth['stored'] ), 'certificati automatici: premendo «Cifra i file già caricati» si cifrano anche gli altri file' );

	// senza nulla da cifrare: si chiude subito in silenzio e non riparte
	foreach ( array( $fc_c1, $fc_c2, $fc_c3 ) as $fc_x ) {
		$wpdb->delete( Db::t( 'medical_certs' ), array( 'id' => $fc_x['id'] ) );
		wp_delete_file( $fc_dir . '/' . $fc_x['stored'] );
	}
	$wpdb->delete( Db::t( 'documents' ), array( 'id' => $fc_oth['id'] ) );
	wp_delete_file( $fc_dir . '/' . $fc_oth['stored'] );
	delete_option( FileMigration::CERT_OPT );
	$fc_ns2 = $fc_audit_n( 'file_crypt.certs_auto_started' );
	FileMigration::auto_certs();
	$fc_cs = FileMigration::state( FileMigration::CERT_OPT );
	$fc_ok( ( 'done' === $fc_cs['status'] || 'running' === $fc_cs['status'] ) && ( 'done' !== $fc_cs['status'] || ( 0 === $fc_cs['finished'] && $fc_ns2 === $fc_audit_n( 'file_crypt.certs_auto_started' ) && '' === \AssociazioneSemplice\Admin\FileCryptPanel::cert_alerts_html() ) ), 'certificati automatici: senza certificati da cifrare si chiude subito, in silenzio, senza programmare nulla' );
	wp_clear_scheduled_hook( FileMigration::CERT_HOOK );
	delete_option( FileMigration::CERT_OPT );
} else {
	delete_option( FileMigration::CERT_OPT );
	FileMigration::auto_certs();
	$fc_ok( '' === FileMigration::state( FileMigration::CERT_OPT )['status'] && ! wp_next_scheduled( FileMigration::CERT_HOOK ), 'certificati automatici: senza i certificati (edizione gratuita) non parte nulla' );
	delete_option( FileMigration::CERT_OPT );
}

// ---------- Campi dei certificati nel database (solo con il Pro) ----------
if ( class_exists( '\\AssociazioneSemplice\\Certificates' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::t( 'medical_certs' ) ) ) ) {
	$wpdb->insert( Db::t( 'medical_certs' ), array( 'person_id' => 1, 'kind' => 'other', 'issued_on' => '2026-01-01', 'expires_on' => '2027-01-01', 'doctor' => 'Dott. Esportazione', 'created_at' => Db::now() ) );
	$fc_cid = (int) $wpdb->insert_id;
}

// ---------- Copia di sicurezza cifrata, con frase segreta ----------
$fc_phrase = $fc_auto; // la frase in uso: quella automatica
$fc_zip    = Backup::export( true );
$fc_za     = new ZipArchive();
$fc_za->open( $fc_zip );
$fc_keys = json_decode( (string) $fc_za->getFromName( 'chiavi.json' ), true );
$fc_man  = json_decode( (string) $fc_za->getFromName( 'manifest.json' ), true );
$fc_blob = (string) $fc_za->getFromName( 'allegati/' . $fc_row['file_stored'] );
$fc_one = reset( $fc_keys['keys'] );
$fc_ok( ! empty( $fc_keys['keys'] ) && ! empty( $fc_one['r']['k']['salt'] ) && in_array( $fc_one['r']['k']['alg'], array( 'argon2id13', 'pbkdf2-sha256' ), true ) && $fc_man['encrypted'], 'copia: contiene le chiavi avvolte con la chiave del sito e con la frase in uso (con il suo sale e il metodo)' );
$fc_ok( ! empty( $fc_one['m'] ) && ! empty( $fc_one['r']['w'] ) && false === strpos( (string) $fc_za->getFromName( 'chiavi.json' ), $fc_phrase ), 'copia: ogni chiave ha la versione per il sito e quella per la frase, e la frase non compare' );
$fc_ok( "\x01" === substr( $fc_blob, 0, 1 ) && false === strpos( $fc_blob, $fc_marker ) && $fc_blob === file_get_contents( $fc_path ), 'copia: i file restano cifrati nello ZIP, identici a quelli sul disco' );
if ( isset( $fc_cid ) ) {
	$fc_jl = (string) $fc_za->getFromName( 'tabelle/asem_medical_certs.jsonl' );
	$fc_ok( false === strpos( $fc_jl, 'Esportazione' ) && false !== strpos( $fc_jl, FileCrypt::FIELD_PREFIX ), 'copia: medico e nome del file dei certificati non escono mai in chiaro' );
	$wpdb->delete( Db::t( 'medical_certs' ), array( 'id' => $fc_cid ) );
}
$fc_za->close();
$fc_ok( 'La frase deve avere almeno 12 caratteri.' === FileKey::recovery_error( 'corta', 'corta' ) && 'Le due frasi non coincidono.' === FileKey::recovery_error( 'una frase abbastanza lunga', 'un\'altra frase lunga' ) && '' === FileKey::recovery_error( 'una frase abbastanza lunga', 'una frase abbastanza lunga' ), 'frase: lunghezza minima e ripetizione uguale' );

// ripristino sullo stesso sito: la chiave del sito apre la copia, senza frase
$fc_site = FileKey::site_id();
$fc_r1   = Backup::restore( $fc_zip );
$fc_ok( 0 === $fc_r1['bad'] && $fc_r1['files'] >= 1 && FileKey::site_id() === $fc_site && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'ripristino: sullo stesso sito, con la stessa chiave, i file tornano e si aprono senza chiedere la frase' );
$fc_ok( 1 === (int) Documents::get( $fc_doc )['enc_ver'], 'ripristino: la versione di cifratura registrata nel database torna com\'era' );

// ripristino con un file della copia manomesso: non viene rimesso
$fc_tz = wp_tempnam( 'asem-fc-zip' );
copy( $fc_zip, $fc_tz );
$fc_zt = new ZipArchive();
$fc_zt->open( $fc_tz );
$fc_bad = $fc_blob;
$fc_bad[ 100 ] = chr( ord( $fc_bad[100] ) ^ 1 );
$fc_zt->addFromString( 'allegati/' . $fc_row['file_stored'], $fc_bad );
$fc_zt->close();
$fc_r2 = Backup::restore( $fc_tz );
$fc_ok( $fc_r2['bad'] >= 1 && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'ripristino: un file della copia che non coincide con la sua impronta non viene rimesso e si segnala; quello sul sito resta intatto' );
wp_delete_file( $fc_tz );

// le copie «prima-del-ripristino» restano utilizzabili
$fc_saved = Backup::saved();
$fc_ok( ! empty( $fc_saved ), 'ripristino: sul sito ci sono le copie fatte prima dei ripristini' );
$fc_r3 = Backup::restore( Backup::dir() . '/' . $fc_saved[0]['name'] );
$fc_ok( 0 === $fc_r3['bad'] && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'ripristino: una copia «prima-del-ripristino» si ripristina con la chiave del sito, senza frase' );

// ripristino su un altro sito: chiave principale diversa, nessuna chiave salvata
add_filter( 'salt', $fc_salt, 10, 2 );
delete_option( FileKey::OPT_KEYS );
delete_option( FileKey::OPT_SITE );
FileKey::reset();
$fc_ok( 'none' === FileKey::state() && FileKey::site_id() !== $fc_site, 'altro sito: nessuna chiave salvata e un altro identificativo' );
$fc_e1 = (string) asem_or_free_throws( function () use ( $fc_zip ) { Backup::restore( $fc_zip ); } );
$fc_ok( false !== strpos( $fc_e1, 'Scrivi la frase' ) && false !== strpos( $fc_e1, 'codice di licenza' ), 'altro sito: senza la frase il ripristino dice quale frase scrivere e non cambia nulla' );
$fc_e2 = (string) asem_or_free_throws( function () use ( $fc_zip ) { Backup::restore( $fc_zip, 'frase sbagliata ma lunga' ); } );
$fc_ok( false !== strpos( $fc_e2, 'non è quella giusta' ), 'altro sito: con la frase sbagliata il ripristino si ferma con un errore chiaro' );
$fc_r4 = Backup::restore( $fc_zip, $fc_phrase );
$fc_ok( 0 === $fc_r4['bad'] && FileKey::site_id() === $fc_site && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ) && 'ok' === FileKey::state(), 'altro sito: con la frase giusta il ripristino riesce, il sito prende l\'identificativo della copia e i file si aprono' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' ); // si torna ai codici di prima
$fc_ok( 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'altro sito: rimessi i codici di prima tutto resta leggibile' );
wp_delete_file( $fc_zip );

// copia fatta su un server con poca memoria: la frase funziona lo stesso, il metodo è scritto nella copia e il ripristino lo legge da lì
FileCrypt::$kdf_force = 'pbkdf2';
FileKey::use_auto_phrase(); // la frase in uso si riavvolge con il metodo alternativo
$fc_zip2 = Backup::export( true );
FileCrypt::$kdf_force = '';
$fc_za2 = new ZipArchive();
$fc_za2->open( $fc_zip2 );
$fc_keys2 = json_decode( (string) $fc_za2->getFromName( 'chiavi.json' ), true );
$fc_za2->close();
$fc_k2 = reset( $fc_keys2['keys'] );
$fc_ok( 'pbkdf2-sha256' === $fc_k2['r']['k']['alg'] && (int) $fc_k2['r']['k']['iter'] >= 100000 && ! empty( $fc_k2['r']['k']['salt'] ), 'copia con poca memoria: il metodo alternativo e i suoi parametri sono scritti nel file delle chiavi' );
add_filter( 'salt', $fc_salt, 10, 2 );
delete_option( FileKey::OPT_KEYS );
delete_option( FileKey::OPT_SITE );
FileKey::reset();
$fc_ok( 'none' === FileKey::state(), 'altro sito: senza chiavi salvate si parte da zero' );
$fc_e3 = (string) asem_or_free_throws( function () use ( $fc_zip2 ) { Backup::restore( $fc_zip2, 'frase sbagliata ma lunga' ); } );
$fc_r5 = Backup::restore( $fc_zip2, $fc_phrase );
$fc_ok( false !== strpos( $fc_e3, 'non è quella giusta' ) && 0 === $fc_r5['bad'] && 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'copia con poca memoria: su un altro sito il ripristino con la frase riesce (e con quella sbagliata si ferma)' );
$fc_ok( 'encrypt' === FileKey::mode() && FileKey::check()['valid'], 'ripristino: dopo il ripristino la cifratura resta attiva e la chiave è valida' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' );
wp_delete_file( $fc_zip2 );

// ---------- Costante ASEM_ENC_KEY: la chiave si avvolge di nuovo senza ricifrare i file ----------
$fc_before = hash_file( 'sha256', $fc_path );
if ( ! defined( 'ASEM_ENC_KEY' ) ) {
	define( 'ASEM_ENC_KEY', 'una-chiave-lunga-di-prova-0123456789' );
}
FileKey::reset();
$fc_ok( 'const' === FileKey::source() && 'ok' === FileKey::state() && hash_file( 'sha256', $fc_path ) === $fc_before && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'costante: con ASEM_ENC_KEY la chiave dei dati si avvolge di nuovo e i file non vengono toccati' );
$fc_bk = FileKey::bundle();
$fc_ok( 'const' === reset( $fc_bk['keys'] )['mb'] && ! FileKey::salts_weak(), 'costante: la chiave è avvolta con la costante e l\'avviso dei codici deboli non compare' );

// ---------- Misura dei tempi: 100 file da 1 MB (si legge nei log), con il sito che resta usabile ----------
$fc_blob1 = str_repeat( 'abcdefghijklmnopqrstuvwxyz012345', 32768 ); // 1 MiB
$fc_bulk  = array();
for ( $fc_i = 0; $fc_i < 100; $fc_i++ ) {
	$fc_bulk[] = $fc_legacy( $fc_blob1 . $fc_i, hash( 'sha256', $fc_blob1 . $fc_i ) );
}
$fc_mid = PrivateFiles::prepare_read( $fc_dir . '/' . $fc_bulk[0]['stored'], $fc_bulk[0]['stored'], null, hash( 'sha256', $fc_bulk[0]['content'] ), 'doc' );
$fc_ok( false === $fc_mid['enc'] && strlen( $fc_bulk[0]['content'] ) === $fc_mid['length'], 'tempi: finché un file non è stato cifrato si legge com\'è (il vecchio file vale fino alla sostituzione)' );
$fc_t0 = microtime( true );
FileMigration::start();
$fc_loops = 1;
while ( FileMigration::state()['running'] && $fc_loops < 50 ) {
	FileMigration::run_batch();
	$fc_loops++;
}
$fc_secs = microtime( true ) - $fc_t0;
echo sprintf( "misura cifratura: 100 file da 1 MB in %.2f s (%.1f file/s), %d lotti da %d\n", $fc_secs, 100 / max( 0.001, $fc_secs ), $fc_loops, FileMigration::BATCH ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- messaggio di prova
$fc_okall = true;
foreach ( $fc_bulk as $fc_x ) {
	$fc_okall = $fc_okall && 1 === (int) $fc_get( $fc_x )['enc_ver'] && FileCrypt::is_encrypted_file( $fc_dir . '/' . $fc_x['stored'] );
	$wpdb->delete( Db::t( 'documents' ), array( 'id' => $fc_x['id'] ) );
	wp_delete_file( $fc_dir . '/' . $fc_x['stored'] );
}
$fc_ok( $fc_okall && ! $fc_parts(), 'tempi: tutti i 100 file da 1 MB risultano cifrati, senza file temporanei' );
$fc_ok( 100 === FileMigration::BATCH && 15 === FileMigration::MAX_SECS, 'tempi: il lotto è di 100 file con al massimo 15 secondi' );

// ---------- Libreria sodium assente ----------
FileCrypt::$disabled = true;
FileKey::reset();
$fc_leaked = count( glob( $fc_dir . '/*' ) ?: array() );
$fc_ok( 'plain' === FileKey::mode() && 'nosodium' === FileKey::state(), 'senza sodium: la funzione non si attiva' );
$fc_pl = Attachments::save_file( $fc_file( 'chiaro.pdf', $fc_tmp( $fc_text ) ) );
$fc_ok( null === $fc_pl['enc_ver'] && $fc_text === file_get_contents( $fc_dir . '/' . $fc_pl['stored'] ), 'senza sodium: i file comuni si salvano in chiaro (l\'avviso lo dice)' );
wp_delete_file( $fc_dir . '/' . $fc_pl['stored'] );
$fc_strict = (string) asem_or_free_throws( function () use ( $fc_file, $fc_tmp, $fc_text ) { Attachments::save_file( $fc_file( 'cert.pdf', $fc_tmp( $fc_text ) ), true ); } );
$fc_ok( false !== strpos( $fc_strict, 'sodium' ) && $fc_leaked === count( glob( $fc_dir . '/*' ) ?: array() ), 'senza sodium: un file sanitario si rifiuta e non resta nulla sul disco' );
$fc_ok( false !== strpos( \AssociazioneSemplice\Admin\FileCryptPanel::alerts_html(), 'manca la libreria di cifratura (sodium)' ) && false !== strpos( \AssociazioneSemplice\Admin\FileCryptPanel::status_line(), 'sodium' ), 'senza sodium: avviso chiaro all\'amministratore' );
$fc_ok( 0 === FileMigration::run_batch()['done'], 'senza sodium: la migrazione non fa nulla' );
$fc_ok( false !== strpos( (string) asem_or_free_throws( function () { FileKey::set_custom_phrase( 'una frase lunga abbastanza', 'una frase lunga abbastanza' ); } ), 'sodium' ), 'senza sodium: la frase scelta non si può impostare' );
FileCrypt::$disabled = false;
FileKey::reset();
$fc_ok( 'encrypt' === FileKey::mode() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'sodium di nuovo disponibile: tutto torna a funzionare' );

// pulizia delle prove
$fk_auto( true );
Documents::delete( $fc_doc );
Inventory::delete_item( $fc_item );
foreach ( array( $fc_l1, $fc_l2, $fc_l4 ) as $fc_x ) {
	$wpdb->delete( Db::t( 'documents' ), array( 'id' => $fc_x['id'] ) );
	wp_delete_file( $fc_dir . '/' . $fc_x['stored'] );
}
$wpdb->delete( Db::t( 'attachments' ), array( 'transaction_id' => 987654 ) );
wp_delete_file( $fc_dir . '/' . $fc_att['stored'] );
delete_option( FileMigration::OPT );

/** Come asem_throws(), anche nella prova dell'edizione gratuita. */
function asem_or_free_throws( callable $f ): ?string {
	try {
		$f();
	} catch ( \InvalidArgumentException $e ) {
		return $e->getMessage();
	}
	return null;
}

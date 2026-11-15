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
$fc_ok( false !== strpos( $fc_html, 'Senza questa frase i documenti di questa copia non si possono più aprire: scrivila e conservala.' ) && false !== strpos( $fc_html, 'name="phrase2"' ), 'pagina Copia di sicurezza: la frase segreta con l\'avvertenza e la ripetizione' );
// la riga dei legacy rimasti non deve disturbare le prove seguenti
foreach ( array( $fc_l3, $fc_l5 ) as $fc_x ) {
	$wpdb->delete( Db::t( 'documents' ), array( 'id' => $fc_x['id'] ) );
	wp_delete_file( $fc_dir . '/' . $fc_x['stored'] );
}
$fc_leak_left = $fc_leak();
$fc_ok( ! $fc_leak_left, 'migrazione: nessun contenuto in chiaro resta nella cartella riservata' );

// ---------- Campi dei certificati nel database (solo con il Pro) ----------
if ( class_exists( '\\AssociazioneSemplice\\Certificates' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::t( 'medical_certs' ) ) ) ) {
	$wpdb->insert( Db::t( 'medical_certs' ), array( 'person_id' => 1, 'kind' => 'other', 'issued_on' => '2026-01-01', 'expires_on' => '2027-01-01', 'doctor' => 'Dott. Esportazione', 'created_at' => Db::now() ) );
	$fc_cid = (int) $wpdb->insert_id;
}

// ---------- Copia di sicurezza cifrata, con frase segreta ----------
$fc_phrase = 'una frase segreta di prova';
$fc_zip    = Backup::export( true, null, $fc_phrase );
$fc_za     = new ZipArchive();
$fc_za->open( $fc_zip );
$fc_keys = json_decode( (string) $fc_za->getFromName( 'chiavi.json' ), true );
$fc_man  = json_decode( (string) $fc_za->getFromName( 'manifest.json' ), true );
$fc_blob = (string) $fc_za->getFromName( 'allegati/' . $fc_row['file_stored'] );
$fc_ok( ! empty( $fc_keys['keys'] ) && ! empty( $fc_keys['kdf']['salt'] ) && 'argon2id13' === $fc_keys['kdf']['alg'] && $fc_man['encrypted'] && $fc_man['phrase'], 'copia: contiene le chiavi avvolte con la chiave del sito e con la frase segreta (Argon2id, con il suo sale)' );
$fc_one = reset( $fc_keys['keys'] );
$fc_ok( ! empty( $fc_one['m'] ) && ! empty( $fc_one['p'] ) && false === strpos( (string) $fc_za->getFromName( 'chiavi.json' ), $fc_phrase ), 'copia: ogni chiave ha la versione per il sito e quella per la frase, e la frase non compare' );
$fc_ok( "\x01" === substr( $fc_blob, 0, 1 ) && false === strpos( $fc_blob, $fc_marker ) && $fc_blob === file_get_contents( $fc_path ), 'copia: i file restano cifrati nello ZIP, identici a quelli sul disco' );
if ( isset( $fc_cid ) ) {
	$fc_jl = (string) $fc_za->getFromName( 'tabelle/asem_medical_certs.jsonl' );
	$fc_ok( false === strpos( $fc_jl, 'Esportazione' ) && false !== strpos( $fc_jl, FileCrypt::FIELD_PREFIX ), 'copia: medico e nome del file dei certificati non escono mai in chiaro' );
	$wpdb->delete( Db::t( 'medical_certs' ), array( 'id' => $fc_cid ) );
}
$fc_za->close();
$fc_nophrase = Backup::export( false );
$fc_zn       = new ZipArchive();
$fc_zn->open( $fc_nophrase );
$fc_kn = json_decode( (string) $fc_zn->getFromName( 'chiavi.json' ), true );
$fc_ok( ! empty( $fc_kn['keys'] ) && empty( reset( $fc_kn['keys'] )['p'] ) && empty( $fc_kn['kdf'] ), 'copia: senza frase le chiavi sono avvolte solo con quella del sito (copia per questo sito)' );
$fc_zn->close();
wp_delete_file( $fc_nophrase );
$fc_ok( 'La frase segreta deve avere almeno 10 caratteri.' === FileKey::phrase_error( 'corta', 'corta' ) && 'Le due frasi segrete non coincidono.' === FileKey::phrase_error( 'una frase abbastanza lunga', 'un\'altra frase lunga' ) && '' === FileKey::phrase_error( 'una frase abbastanza lunga', 'una frase abbastanza lunga' ), 'copia: la frase segreta ha una lunghezza minima e va ripetuta uguale' );

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
$fc_ok( false !== strpos( $fc_e1, 'protetta da una frase segreta' ), 'altro sito: senza la frase il ripristino chiede la frase e non cambia nulla' );
$fc_e2 = (string) asem_or_free_throws( function () use ( $fc_zip ) { Backup::restore( $fc_zip, 'frase sbagliata ma lunga' ); } );
$fc_ok( false !== strpos( $fc_e2, 'non è quella giusta' ), 'altro sito: con la frase sbagliata il ripristino si ferma con un errore chiaro' );
$fc_r4 = Backup::restore( $fc_zip, $fc_phrase );
$fc_ok( 0 === $fc_r4['bad'] && FileKey::site_id() === $fc_site && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ) && 'ok' === FileKey::state(), 'altro sito: con la frase giusta il ripristino riesce, il sito prende l\'identificativo della copia e i file si aprono' );
remove_filter( 'salt', $fc_salt, 10 );
FileKey::reset();
FileKey::recover( '', $fc_new, '' ); // si torna ai codici di prima
$fc_ok( 'ok' === FileKey::state() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'altro sito: rimessi i codici di prima tutto resta leggibile' );
wp_delete_file( $fc_zip );

// ---------- Costante ASEM_ENC_KEY: la chiave si avvolge di nuovo senza ricifrare i file ----------
$fc_before = hash_file( 'sha256', $fc_path );
if ( ! defined( 'ASEM_ENC_KEY' ) ) {
	define( 'ASEM_ENC_KEY', 'una-chiave-lunga-di-prova-0123456789' );
}
FileKey::reset();
$fc_ok( 'const' === FileKey::source() && 'ok' === FileKey::state() && hash_file( 'sha256', $fc_path ) === $fc_before && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'costante: con ASEM_ENC_KEY la chiave dei dati si avvolge di nuovo e i file non vengono toccati' );
$fc_bk = FileKey::bundle( null );
$fc_ok( 'const' === reset( $fc_bk['keys'] )['mb'] && ! FileKey::salts_weak(), 'costante: la chiave è avvolta con la costante e l\'avviso dei codici deboli non compare' );

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
$fc_ok( null !== asem_or_free_throws( function () use ( $fc_phrase ) { try { Backup::export( false, null, $fc_phrase ); } catch ( \RuntimeException $e ) { throw new \InvalidArgumentException( $e->getMessage() ); } } ), 'senza sodium: la copia con frase segreta non si crea' );
FileCrypt::$disabled = false;
FileKey::reset();
$fc_ok( 'encrypt' === FileKey::mode() && $fc_text === $fc_plain( $fc_path, $fc_row['file_stored'] ), 'sodium di nuovo disponibile: tutto torna a funzionare' );

// pulizia delle prove
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

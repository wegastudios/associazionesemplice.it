<?php
/**
 * Prove della cartella dei file riservati dentro un WordPress reale: protezione contro l'elenco dei file, controllo dall'esterno (con le risposte
 * simulate), avvisi, percorso in wp-config.php e spostamento dei file. Si carica alla fine di smoke.php e di smoke_free.php, perché definisce una
 * costante (ASEM_PRIVATE_DIR) e dopo non si torna indietro.
 */

use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\PrivateFolder;
use AssociazioneSemplice\PrivateFolderRules;

$pf_ok = function_exists( 'free_ok' ) ? 'free_ok' : 'asem_ok';

$pf_rm = function ( string $d ) use ( &$pf_rm ): void {
	if ( ! is_dir( $d ) ) {
		return;
	}
	foreach ( scandir( $d ) ?: array() as $n ) {
		if ( '.' === $n || '..' === $n ) {
			continue;
		}
		if ( is_dir( "$d/$n" ) && ! is_link( "$d/$n" ) ) {
			$pf_rm( "$d/$n" );
		} else {
			unlink( "$d/$n" );
		}
	}
	rmdir( $d );
};

$pf_user  = get_current_user_id();
$pf_admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $pf_admin );
delete_user_meta( $pf_admin, \AssociazioneSemplice\Admin\Dismiss::META );
delete_option( PrivateFolder::OPT );

// ---------- Cartella predefinita e protezione contro l'elenco dei file ----------
$pf_def  = PrivateFolder::default_dir();
$pf_root = Attachments::prepare_dir();
$pf_ok( ! PrivateFolder::status()['custom'] && '' === PrivateFolder::status()['error'] && Attachments::dir() === $pf_def && $pf_root === $pf_def && PrivateFolder::status()['in_web'], 'cartella riservata: senza la costante in wp-config.php vale quella predefinita dentro uploads' );
wp_mkdir_p( $pf_root . '/prova-a/2026/x' );
wp_mkdir_p( $pf_root . '/prova-b/y' );
PrivateFolder::protect( $pf_root . '/prova-a/2026/x' );
$pf_ok( is_file( $pf_root . '/index.php' ) && is_file( $pf_root . '/prova-a/index.php' ) && is_file( $pf_root . '/prova-a/2026/index.php' ) && is_file( $pf_root . '/prova-a/2026/x/index.php' ) && ! is_file( $pf_root . '/prova-b/index.php' ), 'cartella riservata: l\'index.php vuoto sta nella radice e in ogni cartella sulla strada di una sottocartella nuova' );
PrivateFolder::protect_all();
$pf_ok( is_file( $pf_root . '/prova-b/index.php' ) && is_file( $pf_root . '/prova-b/y/index.php' ) && "<?php\n// Silence is golden.\n" === file_get_contents( $pf_root . '/prova-b/y/index.php' ), 'cartella riservata: il lavoro periodico mette l\'index.php anche nelle sottocartelle già esistenti' );
$pf_rm( $pf_root . '/prova-a' );
$pf_rm( $pf_root . '/prova-b' );

// ---------- Controllo dall'esterno, con le risposte simulate ----------
$pf_calls = 0;
$pf_url   = '';
$pf_args  = array();
$pf_mock  = function ( string $mode ) use ( &$pf_calls, &$pf_url, &$pf_args ) {
	return function ( $pre, $args, $url ) use ( $mode, &$pf_calls, &$pf_url, &$pf_args ) {
		$pf_calls++;
		$pf_url  = (string) $url;
		$pf_args = (array) $args;
		if ( 'error' === $mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: connessione rifiutata' );
		}
		$files = glob( PrivateFolder::dir() . '/controllo/prova-*.txt' ) ?: array();
		$codes = array( 'exposed' => 200, 'closed' => 404, 'auth' => 401, 'redirect' => 302, 'fail' => 503 );
		return array( 'headers' => array(), 'body' => 'exposed' === $mode && $files ? (string) file_get_contents( $files[0] ) : 'Non trovato', 'response' => array( 'code' => $codes[ $mode ], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	};
};
$pf_run = function ( string $mode ) use ( $pf_mock ): array {
	remove_all_filters( 'pre_http_request' );
	add_filter( 'pre_http_request', $pf_mock( $mode ), 10, 3 );
	return PrivateFolder::check();
};
$pf_left = function () {
	return glob( PrivateFolder::dir() . '/controllo/prova-*.txt' ) ?: array();
};

$r = $pf_run( 'exposed' );
$pf_ok( PrivateFolderRules::EXPOSED === $r['state'] && 1 === $pf_calls && 0 === strpos( $pf_url, (string) wp_upload_dir( null, false )['baseurl'] . '/associazionesemplice-private/controllo/prova-' ) && ! $pf_left(), 'controllo cartella: un file di prova che si scarica da fuori è un allarme, e il file di prova viene cancellato' );
$pf_ok( ( $pf_args['timeout'] ?? 99 ) <= 5 && 0 === ( $pf_args['redirection'] ?? 1 ) && empty( $pf_args['headers']['Authorization'] ) && empty( $pf_args['cookies'] ), 'controllo cartella: richiesta breve, senza seguire rimandi e senza password né cookie dell\'utente' );
$pf_alert = PrivateFolder::alerts_html();
$pf_ok( false !== strpos( $pf_alert, 'private-folder-exposed' ) && false !== strpos( $pf_alert, 'raggiungibile da internet' ) && false !== strpos( $pf_alert, 'is-dismissible' ) && false !== strpos( $pf_alert, 'chi gestisce il server' ), 'controllo cartella: l\'avviso all\'amministratore è chiudibile e dice a chi rivolgersi' );
\AssociazioneSemplice\Admin\Dismiss::dismiss( 'private-folder-exposed', 7 );
$pf_ok( '' === PrivateFolder::alerts_html(), 'controllo cartella: chiuso l\'avviso non ricompare subito' );
delete_user_meta( $pf_admin, \AssociazioneSemplice\Admin\Dismiss::META );
wp_set_current_user( 0 );
$pf_ok( '' === PrivateFolder::alerts_html(), 'controllo cartella: chi non amministra non vede l\'avviso' );
wp_set_current_user( $pf_admin );

$pf_panel = free_render_pf( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ) );
$pf_ok( false !== strpos( $pf_panel, 'Cartella dei file riservati' ) && false !== strpos( $pf_panel, 'Controlla ora' ) && false !== strpos( $pf_panel, 'location ^~ ' ) && false !== strpos( $pf_panel, 'ASEM_PRIVATE_DIR' ) && false !== strpos( $pf_panel, 'private-folder-exposed' ), 'pagina Copia di sicurezza: riquadro con esito, pulsante, regola nginx e riga per wp-config.php' );

$pf_p1 = strpos( $pf_panel, 'Se queste parole non ti dicono nulla' );
$pf_p2 = strpos( $pf_panel, 'Buongiorno, sul nostro sito la cartella ' . $pf_root );
$pf_p3 = strpos( $pf_panel, 'location ^~ ' );
$pf_p4 = strpos( $pf_panel, '.htaccess' );
$pf_ok( false !== $pf_p1 && false !== $pf_p2 && false !== $pf_p3 && $pf_p1 < $pf_p4 && $pf_p2 < $pf_p3 && false !== strpos( $pf_panel, 'id="asem-private-copy"' ) && false !== strpos( $pf_panel, '<textarea' ), 'riquadro: la riga «se queste parole non ti dicono nulla» sta prima dei termini tecnici e il messaggio da inoltrare (con il percorso reale) prima delle istruzioni' );

foreach ( array( 'closed', 'auth', 'redirect', 'fail', 'error' ) as $mode ) {
	$r   = $pf_run( $mode );
	$exp = 'closed' === $mode ? PrivateFolderRules::CLOSED : PrivateFolderRules::UNKNOWN;
	$pf_ok( $exp === $r['state'] && '' === PrivateFolder::alerts_html() && ! $pf_left(), 'controllo cartella: risposta «' . $mode . '» = ' . $exp . ', nessun allarme' );
}
$r = $pf_run( 'error' );
$pf_ok( 'loopback' === $r['reason'] && false !== strpos( PrivateFolder::outcome_text( $r ), 'Nessun allarme' ), 'controllo cartella: se il sito non riesce a chiamarsi è «non verificabile», senza allarme' );
$r = $pf_run( 'auth' );
$pf_ok( 'auth' === $r['reason'] && false !== strpos( PrivateFolder::outcome_text( $r ), 'password del server' ), 'controllo cartella: sito dietro password del server = «non verificabile», senza falso allarme' );

$pf_run( 'closed' );
$n0 = $pf_calls;
PrivateFolder::cron();
$pf_ok( $n0 === $pf_calls, 'controllo cartella: il lavoro periodico non rifà il controllo se l\'ultimo è recente' );
$o       = get_option( PrivateFolder::OPT );
$o['at'] = time() - 8 * DAY_IN_SECONDS;
update_option( PrivateFolder::OPT, $o );
PrivateFolder::cron();
$pf_ok( $n0 + 1 === $pf_calls && PrivateFolder::last()['at'] > time() - 60, 'controllo cartella: dopo sette giorni il lavoro periodico lo ripete' );
$o        = get_option( PrivateFolder::OPT );
$o['key'] = 'altra-situazione';
update_option( PrivateFolder::OPT, $o );
$pf_ok( null === PrivateFolder::last(), 'controllo cartella: un esito di un\'altra cartella o di un altro indirizzo non vale più' );

delete_transient( 'asem_private_check_busy' );
$res = \AssociazioneSemplice\Admin\TechActions::private_check( array() );
$res2 = \AssociazioneSemplice\Admin\TechActions::private_check( array() );
$pf_ok( false !== strpos( $res[1], 'Controllo eseguito' ) && false !== strpos( $res2[1], 'appena stato fatto' ) && isset( \AssociazioneSemplice\Admin\TechActions::ACTIONS['asem_private_check'], \AssociazioneSemplice\Admin\TechActions::ACTIONS['asem_private_move'] ), 'controllo cartella: il pulsante «Controlla ora» esegue il controllo, e non più di uno ogni pochi secondi' );
delete_transient( 'asem_private_check_busy' );

// ---------- Percorso in wp-config.php ----------
$pf_ok( 'relative' === PrivateFolder::resolve( 'relativo/x', $pf_def )['error'] && 'dotdot' === PrivateFolder::resolve( '/a/../b', $pf_def )['error'] && 'overlaps' === PrivateFolder::resolve( $pf_def . '/dentro', $pf_def )['error'] && 'overlaps' === PrivateFolder::resolve( dirname( $pf_def ), $pf_def )['error'], 'cartella in wp-config.php: percorsi relativi, con «..» o dentro/attorno alla cartella predefinita vengono rifiutati' );
$pf_tmpfile = tempnam( sys_get_temp_dir(), 'asem' );
$pf_ok( 'missing' === PrivateFolder::resolve( $pf_tmpfile . '/sotto', $pf_def )['error'], 'cartella in wp-config.php: una cartella che non esiste e non si può creare dà un errore chiaro' );
unlink( $pf_tmpfile );
foreach ( array( 'relative', 'dotdot', 'root', 'overlaps', 'missing', 'not_writable' ) as $code ) {
	$pf_ok( '' !== PrivateFolder::error_text( $code ), 'cartella in wp-config.php: frase per l\'errore «' . $code . '»' );
}

// errore mostrato nell'avviso e nel riquadro (si simula il percorso non utilizzabile)
$pf_prop = new ReflectionProperty( PrivateFolder::class, 'resolved' );
$pf_prop->setAccessible( true );
$pf_prop->setValue( null, array( 'path' => '', 'error' => 'not_writable' ) );
$pf_alert = PrivateFolder::alerts_html();
$pf_ok( false !== strpos( $pf_alert, 'non è utilizzabile' ) && false !== strpos( $pf_alert, 'permessi di scrittura' ) && false !== strpos( $pf_alert, 'is-dismissible' ) && Attachments::dir() === $pf_def, 'cartella in wp-config.php: se non è scrivibile l\'avviso lo dice e il sito continua con la cartella predefinita' );
PrivateFolder::reset();

// spostamento: prima di indicare la nuova cartella non c'è nulla da spostare
$pf_threw = false;
try {
	\AssociazioneSemplice\Admin\TechActions::private_move( array() );
} catch ( \InvalidArgumentException $e ) {
	$pf_threw = true;
}
$pf_ok( $pf_threw && array() === PrivateFolder::pending(), 'spostamento: senza una cartella indicata in wp-config.php non si sposta nulla' );

$pf_a = str_repeat( 'a', 32 );
$pf_c = str_repeat( 'c', 32 );
wp_mkdir_p( $pf_root . '/backups' );
file_put_contents( $pf_root . '/' . $pf_a, 'contenuto uno' );
file_put_contents( $pf_root . '/' . $pf_c, 'dal vecchio posto' );
file_put_contents( $pf_root . '/backups/prova-spostamento.zip', 'zip finto' );

$pf_custom = trailingslashit( sys_get_temp_dir() ) . 'asem-privata-' . bin2hex( random_bytes( 4 ) );
define( 'ASEM_PRIVATE_DIR', $pf_custom );
PrivateFolder::reset();
$pf_dir = PrivateFolderRules::normalize( $pf_custom ) . ( is_multisite() ? '/sito-' . get_current_blog_id() : '' );
$pf_st  = PrivateFolder::status();

// i file vecchi restano raggiungibili: la nuova cartella entra in uso solo a spostamento finito
$pf_ok( $pf_st['custom'] && '' === $pf_st['error'] && ! $pf_st['active'] && $pf_dir === $pf_st['target'] && PrivateFolder::dir() === $pf_def && Attachments::dir() === $pf_def && 'contenuto uno' === file_get_contents( Attachments::dir() . '/' . $pf_a ), 'spostamento: indicata una nuova cartella, finché ci sono file da spostare resta in uso la vecchia e i file restano raggiungibili' );
$pf_alert = PrivateFolder::alerts_html();
$pf_ok( false !== strpos( $pf_alert, 'notice-warning inline asem-private-move' ) && 1 === preg_match( '/ancora \d+ file da spostare/', $pf_alert ), 'spostamento: in Bacheca c\'è un avviso fisso con il numero dei file da spostare' );
$pf_ok( PrivateFolder::move_incomplete(), 'spostamento: si sa che ci sono ancora file da spostare' );
if ( class_exists( '\\AssociazioneSemplice\\FileMigration' ) ) {
	$pf_r = \AssociazioneSemplice\FileMigration::run_batch();
	$pf_ok( ! empty( $pf_r['busy'] ) && ! empty( $pf_r['moving'] ), 'spostamento: la cifratura dei file già caricati resta ferma finché ci sono file da spostare' );
	delete_option( \AssociazioneSemplice\FileMigration::CERT_OPT );
	\AssociazioneSemplice\FileMigration::auto_certs();
	$pf_cs = \AssociazioneSemplice\FileMigration::state( \AssociazioneSemplice\FileMigration::CERT_OPT );
	$pf_ok( 'running' !== $pf_cs['status'] && 'done' !== $pf_cs['status'] && ! wp_next_scheduled( \AssociazioneSemplice\FileMigration::CERT_HOOK ), 'spostamento: la cifratura automatica dei certificati non parte e non si chiude come finita finché ci sono file da spostare' );
	delete_option( \AssociazioneSemplice\FileMigration::CERT_OPT );
}
$pf_zip = \AssociazioneSemplice\Backup::export( true );
$pf_za  = new ZipArchive();
$pf_has = true === $pf_za->open( $pf_zip ) && false !== $pf_za->locateName( 'allegati/' . $pf_a );
$pf_za->close();
unlink( $pf_zip );
$pf_ok( $pf_has, 'spostamento: la copia di sicurezza con gli allegati contiene anche i file ancora da spostare' );

// i file della cartella predefinita aspettano di essere spostati
file_put_contents( $pf_root . '/' . str_repeat( 'b', 32 ) . '.part-abcd1234', 'resto di una copia interrotta' );
$pf_pend = PrivateFolder::pending();
$pf_ok( in_array( $pf_a, $pf_pend, true ) && in_array( 'backups/prova-spostamento.zip', $pf_pend, true ) && ! in_array( 'index.php', $pf_pend, true ) && ! in_array( '.htaccess', $pf_pend, true ) && ! in_array( str_repeat( 'b', 32 ) . '.part-abcd1234', $pf_pend, true ), 'spostamento: l\'elenco dei file da portare non include i file di protezione né i resti di copie interrotte' );
unlink( $pf_root . '/' . str_repeat( 'b', 32 ) . '.part-abcd1234' );
wp_mkdir_p( $pf_dir );
file_put_contents( $pf_dir . '/' . $pf_c, 'contenuto diverso nella nuova cartella' ); // stesso nome, contenuto diverso: non si tocca nulla
touch( $pf_root . '/' . $pf_c, time() - 3600 );
$pf_panel = free_render_pf( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ) );
$pf_ok( false !== strpos( $pf_panel, 'Prima di spostare i file fai una copia di sicurezza.' ) && strpos( $pf_panel, 'Prima di spostare i file' ) < strpos( $pf_panel, 'name="action" value="asem_private_move"' ), 'spostamento: prima del pulsante c\'è l\'invito a fare una copia di sicurezza, con il link' );
$pf_ok( false !== strpos( PrivateFolder::outcome_text( array( 'state' => PrivateFolderRules::CLOSED ) ), 'dall\'indirizzo web del sito. Il controllo parte dal sito stesso' ), 'controllo cartella: l\'esito positivo non promette certezze' );
$pf_ok( false !== strpos( $pf_panel, 'Sposta i file' ), 'spostamento: il riquadro mostra il pulsante «Sposta i file»' );

// prima si copia tutto lasciando gli originali; con un file in conflitto la nuova cartella non entra in uso e non si cancella nulla
for ( $pf_i = 0; $pf_i < 12; $pf_i++ ) {
	PrivateFolder::move_batch();
}
$pf_msg = \AssociazioneSemplice\Admin\TechActions::private_move( array() );
$pf_ok( ! PrivateFolder::status()['active'] && PrivateFolder::dir() === $pf_def && 'contenuto uno' === file_get_contents( $pf_dir . '/' . $pf_a ) && 'contenuto uno' === file_get_contents( $pf_root . '/' . $pf_a ) && 'dal vecchio posto' === file_get_contents( $pf_root . '/' . $pf_c ) && 'contenuto diverso nella nuova cartella' === file_get_contents( $pf_dir . '/' . $pf_c ) && false !== strpos( $pf_msg[1], 'esistono già nella nuova cartella' ), 'spostamento: con un file in conflitto la nuova cartella non entra in uso, gli originali restano e il conflitto è segnalato' );
unlink( $pf_dir . '/' . $pf_c ); // il conflitto è risolto a mano
for ( $pf_i = 0; $pf_i < 80 && PrivateFolder::pending(); $pf_i++ ) { // altri file di prova già nella cartella predefinita: si portano tutti, a lotti
	PrivateFolder::move_batch();
}
$pf_ok( PrivateFolder::status()['active'] && Attachments::dir() === $pf_dir && 'contenuto uno' === file_get_contents( $pf_dir . '/' . $pf_a ) && hash_file( 'sha256', $pf_dir . '/' . $pf_a ) === hash( 'sha256', 'contenuto uno' ) && ! file_exists( $pf_root . '/' . $pf_a ) && 'dal vecchio posto' === file_get_contents( $pf_dir . '/' . $pf_c ) && ! file_exists( $pf_root . '/' . $pf_c ) && 'zip finto' === file_get_contents( $pf_dir . '/backups/prova-spostamento.zip' ) && ! file_exists( $pf_root . '/backups/prova-spostamento.zip' ) && is_file( $pf_dir . '/backups/index.php' ) && array() === PrivateFolder::pending() && ! PrivateFolder::move_incomplete(), 'spostamento: copiati tutti i file entra in uso la nuova cartella, i file arrivano identici (impronta verificata), spariscono dalla vecchia posizione e la sottocartella è protetta' );
$pf_ok( Attachments::prepare_dir() === $pf_dir && is_file( $pf_dir . '/index.php' ) && is_file( $pf_dir . '/.htaccess' ) && 0 === strpos( \AssociazioneSemplice\Backup::dir(), $pf_dir ), 'cartella in wp-config.php: protezione e cartella delle copie di sicurezza stanno nel nuovo percorso' );
$pf_ok( ! PrivateFolder::status()['in_web'] && $pf_dir !== $pf_def, 'cartella in wp-config.php: spostamento finito, la cartella in uso è quella nuova, fuori dalla parte pubblica' );
if ( class_exists( '\\AssociazioneSemplice\\ReceiptFiles' ) ) {
	$pf_ok( 0 === strpos( \AssociazioneSemplice\ReceiptFiles::abs_path( 'receipts/2026/12-' . str_repeat( 'a', 20 ) . '.pdf' ), $pf_dir . '/receipts/2026/' ), 'cartella in wp-config.php: anche i PDF delle ricevute usano il nuovo percorso' );
}
$n0 = $pf_calls;
$r  = PrivateFolder::check();
$pf_ok( PrivateFolderRules::OUTSIDE === $r['state'] && $n0 === $pf_calls && '' === PrivateFolder::alerts_html() && false !== strpos( PrivateFolder::outcome_text( $r ), 'fuori dalla parte pubblica' ), 'controllo cartella: una cartella fuori dalla parte pubblica non ha indirizzo web, niente richiesta e niente allarme' );
$pf_panel = free_render_pf( array( \AssociazioneSemplice\Admin\BackupPage::class, 'render' ) );
$pf_ok( false !== strpos( $pf_panel, 'fuori dalla parte pubblica del sito e non ha un indirizzo web' ), 'cartella in wp-config.php: a spostamento finito il riquadro dice che la cartella è fuori dalla parte pubblica' );

// dopo l'entrata in uso: un file rimasto nella vecchia cartella si porta nella nuova; uno che esiste già con contenuto diverso non si tocca
$pf_e = str_repeat( 'e', 32 );
$pf_f = str_repeat( 'f', 32 );
file_put_contents( $pf_root . '/' . $pf_e, 'vecchio' );
file_put_contents( $pf_dir . '/' . $pf_e, 'diverso' );
file_put_contents( $pf_root . '/' . $pf_f, 'rimasto indietro' );
$pf_msg = \AssociazioneSemplice\Admin\TechActions::private_move( array() );
$pf_ok( 'vecchio' === file_get_contents( $pf_root . '/' . $pf_e ) && 'diverso' === file_get_contents( $pf_dir . '/' . $pf_e ) && false !== strpos( $pf_msg[1], 'esistono già nella nuova cartella' ) && array( $pf_e ) === array_values( PrivateFolder::pending() ) && 'rimasto indietro' === file_get_contents( $pf_dir . '/' . $pf_f ) && ! file_exists( $pf_root . '/' . $pf_f ), 'spostamento: un file che esiste già nella nuova cartella con contenuto diverso non viene toccato e viene segnalato' );
unlink( $pf_root . '/' . $pf_e );
unlink( $pf_dir . '/' . $pf_e );
$pf_ok( in_array( 'private_folder.moved', array_column( \AssociazioneSemplice\Audit::recent( 8 ), 'action' ), true ), 'spostamento: l\'operazione resta scritta nel registro azioni' );

// «Sposta i file» non cancella mai quando origine e destinazione sono lo stesso posto visto da percorsi diversi
$pf_d    = str_repeat( 'd', 32 );
$pf_link = trailingslashit( sys_get_temp_dir() ) . 'asem-link-' . bin2hex( random_bytes( 4 ) );
if ( function_exists( 'symlink' ) && @symlink( $pf_root, $pf_link ) ) { // phpcs:ignore
	$pf_ok( 'overlaps' === PrivateFolder::resolve( $pf_link, $pf_def )['error'] && 'overlaps' === PrivateFolder::resolve( $pf_link . '/', $pf_def )['error'], 'cartella in wp-config.php: un collegamento simbolico alla cartella predefinita è la stessa cartella, rifiutato' );
	file_put_contents( $pf_root . '/' . $pf_d, 'dato che non deve sparire' );
	PrivateFolder::reset();
	$pf_prop->setValue( null, array( 'path' => PrivateFolderRules::normalize( $pf_link ), 'error' => '' ) ); // come se la verifica del percorso l'avesse lasciato passare
	$pf_r = PrivateFolder::move_batch();
	$pf_ok( 'dato che non deve sparire' === file_get_contents( $pf_root . '/' . $pf_d ) && 0 === $pf_r['moved'] && ! empty( $pf_r['same'] ), 'spostamento: se la cartella nuova è la stessa della vecchia, vista da un altro percorso, non si cancella nulla' );
	unlink( $pf_link );
	unlink( $pf_root . '/' . $pf_d );
}
$pf_c2 = trailingslashit( sys_get_temp_dir() ) . 'asem-privata2-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $pf_c2 );
wp_mkdir_p( $pf_root . '/backups' );
if ( function_exists( 'symlink' ) && @symlink( $pf_root . '/backups', $pf_c2 . '/backups' ) ) { // phpcs:ignore
	file_put_contents( $pf_root . '/backups/solo-qui.zip', 'zip che non deve sparire' );
	PrivateFolder::reset();
	$pf_prop->setValue( null, array( 'path' => PrivateFolderRules::normalize( $pf_c2 ), 'error' => '' ) );
	$pf_r = PrivateFolder::move_batch();
	$pf_ok( 'zip che non deve sparire' === file_get_contents( $pf_root . '/backups/solo-qui.zip' ) && ! empty( $pf_r['same'] ), 'spostamento: un file che nella cartella nuova è lo stesso file (sottocartella collegata) non viene cancellato' );
	unlink( $pf_root . '/backups/solo-qui.zip' );
}
$pf_rm( $pf_c2 );
PrivateFolder::reset();

// pulizia
$pf_rm( $pf_custom );
foreach ( array( $pf_a, $pf_c ) as $pf_f ) {
	if ( is_file( $pf_root . '/' . $pf_f ) ) {
		unlink( $pf_root . '/' . $pf_f );
	}
}
remove_all_filters( 'pre_http_request' );
delete_option( PrivateFolder::OPT );
delete_option( PrivateFolder::OPT_ACTIVE );
delete_user_meta( $pf_admin, \AssociazioneSemplice\Admin\Dismiss::META );
wp_set_current_user( $pf_user );

/** Pagina resa come testo (le due prove hanno ciascuna il suo aiuto). */
function free_render_pf( callable $page ): string {
	ob_start();
	$page();
	return (string) ob_get_clean();
}

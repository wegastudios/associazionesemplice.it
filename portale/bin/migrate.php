<?php
// Aggiorna la struttura del database (tabelle e colonne) con l'utente PROPRIETARIO, non con quello di tutti i giorni del portale.
// Lo lancia deploy.sh a ogni pubblicazione: php ~/portale/bin/migrate.php
// Credenziali del proprietario: file NON tracciato sul server, ~/portale/data/pg-migrate.php, stesso formato di quello del portale, per esempio
//   <?php return array( 'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=NOME', 'user' => 'UTENTE_PROPRIETARIO', 'pass_file' => '/percorso/fuori/dal/web/password', 'schema' => 'portal' );
// (la password sta in un file a parte con permessi 600, mai scritta qui né in chat). Senza quel file usa l'utente di tutti i giorni: va bene finché ha il permesso.
// Le tabelle che appartengono ancora all'utente di tutti i giorni (il proprietario non può modificarle): le ALTER rifiutate con «must be owner»
// (SQLSTATE 42501) si riprovano con quell'utente, che come proprietario può cambiarle anche senza il permesso CREATE sullo schema.
// Se qualche istruzione non riesce con nessuno dei due utenti, la elenca e termina con codice 1 (deploy.sh lo segnala e termina con errore).
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$file = __DIR__ . '/../data/pg-migrate.php';
$cfg  = is_readable( $file ) ? (array) require $file : (array) Portale\Config::get( 'db' );
$own  = is_readable( $file );
try {
	$db     = Portale\Db::connect( $cfg );
	$failed = $db->migrateReport();
	$retry  = array_values( array_filter( $failed, static function ( $f ) {
		return $f['owner'];
	} ) );
	$final  = array_values( array_filter( $failed, static function ( $f ) {
		return ! $f['owner'];
	} ) );
	if ( $own && $retry ) {
		try {
			$daily = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
			$left  = $daily->retry( $retry );
			echo date( 'c' ), ' ', count( $retry ) - count( $left ), ' modifiche su tabelle del portale riuscite con l\'utente di tutti i giorni', "\n";
			$final = array_merge( $final, $left );
		} catch ( \Throwable $e ) {
			$final = array_merge( $final, $retry );
		}
	} else {
		$final = array_merge( $final, $retry );
	}
	if ( $final ) {
		fwrite( STDERR, date( 'c' ) . ' migrazione INCOMPLETA: ' . count( $final ) . " istruzioni non riuscite con nessun utente:\n" );
		foreach ( $final as $f ) {
			fwrite( STDERR, '  - ' . preg_replace( '/\s+/', ' ', $f['sql'] ) . ' → ' . substr( preg_replace( '/\s+/', ' ', $f['error'] ), 0, 160 ) . "\n" );
		}
		exit( 1 );
	}
	echo date( 'c' ), ' migrazione eseguita', $own ? ' con l\'utente proprietario' : ' con l\'utente di tutti i giorni (data/pg-migrate.php non c\'è)', "\n";
} catch ( \Throwable $e ) {
	fwrite( STDERR, date( 'c' ) . ' migrazione NON riuscita: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

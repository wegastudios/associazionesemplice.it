<?php
// Aggiorna la struttura del database (tabelle e colonne) con l'utente PROPRIETARIO, non con quello di tutti i giorni del portale.
// Lo lancia deploy.sh a ogni pubblicazione: php ~/portale/bin/migrate.php
// Credenziali del proprietario: file NON tracciato sul server, ~/portale/data/pg-migrate.php, stesso formato di quello del portale, per esempio
//   <?php return array( 'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=NOME', 'user' => 'UTENTE_PROPRIETARIO', 'pass_file' => '/percorso/fuori/dal/web/password', 'schema' => 'portal' );
// (la password sta in un file a parte con permessi 600, mai scritta qui né in chat). Senza quel file usa l'utente di tutti i giorni: va bene finché ha il permesso.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$file = __DIR__ . '/../data/pg-migrate.php';
$cfg  = is_readable( $file ) ? (array) require $file : (array) Portale\Config::get( 'db' );
$own  = is_readable( $file );
try {
	$db = Portale\Db::connect( $cfg );
	$db->migrate();
	echo date( 'c' ), ' migrazione eseguita', $own ? ' con l\'utente proprietario' : ' con l\'utente di tutti i giorni (data/pg-migrate.php non c\'è)', "\n";
} catch ( \Throwable $e ) {
	fwrite( STDERR, date( 'c' ) . ' migrazione NON riuscita: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

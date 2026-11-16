<?php
// Aggiunge o firma una versione del plugin ai pacchetti scaricabili (di solito lanciato da release.sh, che firma sul computer di chi pubblica):
//   php bin/release.php free|pro file.zip ["nota"] [firma kid [firma_v2 kid_v2 signed_at min_version]]   pubblica una versione (la firma Ed25519 base64url è facoltativa ma senza non si aggiorna da WordPress)
//   php bin/release.php sign free|pro VERSIONE firma kid [firma_v2 kid_v2 signed_at min_version]          aggiunge la firma a una versione già pubblicata
//   php bin/release.php replace free|pro file.zip nota firma kid [firma_v2 kid_v2 signed_at min_version]   sostituisce zip, impronta e firma di una versione già pubblicata (stesso numero; firma obbligatoria; il vecchio zip resta conservato)
//   php bin/release.php minver free|pro                                                                    stampa la versione minima dell'ultima release che ne ha una (vuota se nessuna)
// La firma v2 («asem-pkg-v2|prodotto|versione|sha256|signed_at|min_version») si dà sempre insieme alla v1: i plugin già installati leggono solo la v1.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrateOnStart();
Portale\Settings::load( $db );
if ( $argc < 3 ) {
	fwrite( STDERR, "Uso: php bin/release.php free|pro file.zip [nota] [firma kid ...] | php bin/release.php sign free|pro versione firma kid [...] | php bin/release.php minver free|pro\n" );
	exit( 2 );
}
/** Campi v2 dagli argomenti a partire da $from (4 argomenti) oppure null se non ci sono. */
$v2from = function ( int $from ) use ( $argv, $argc ): ?array {
	if ( $argc <= $from ) {
		return null;
	}
	if ( $argc < $from + 4 ) {
		fwrite( STDERR, "La firma v2 vuole quattro valori: firma_v2 kid_v2 signed_at min_version\n" );
		exit( 2 );
	}
	return array( 'signature_v2' => $argv[ $from ], 'kid_v2' => $argv[ $from + 1 ], 'signed_at' => $argv[ $from + 2 ], 'min_version' => $argv[ $from + 3 ] );
};
try {
	if ( 'minver' === $argv[1] ) {
		echo Portale\Releases::latestMinVersion( $db, $argv[2] ), "\n";
		exit( 0 );
	}
	if ( 'checkcolumns' === $argv[1] ) {
		// Sola lettura: le colonne della firma v2 devono esistere nel database prima di pubblicare (release.sh si ferma se mancano).
		$missing = array();
		foreach ( array( 'signature_v2', 'kid_v2', 'signed_at', 'min_version' ) as $col ) {
			try {
				$db->all( 'SELECT ' . $col . ' FROM releases WHERE 1 = 0' );
			} catch ( \Throwable $e ) {
				$missing[] = $col;
			}
		}
		if ( $missing ) {
			fwrite( STDERR, 'Mancano nella tabella releases del database: ' . implode( ', ', $missing ) . ". Vanno create come proprietario (vedi il documento del rimedio) prima di pubblicare.\n" );
			exit( 3 );
		}
		echo "ok\n";
		exit( 0 );
	}
	if ( 'sign' === $argv[1] ) {
		if ( $argc < 6 ) {
			fwrite( STDERR, "Uso: php bin/release.php sign free|pro versione firma kid [firma_v2 kid_v2 signed_at min_version]\n" );
			exit( 2 );
		}
		Portale\Releases::sign( $db, $argv[2], $argv[3], $argv[4], $argv[5], 'cli', $v2from( 6 ) );
		echo 'Firmata ', $argv[2], ' ', $argv[3], "\n";
		exit( 0 );
	}
	if ( 'replace' === $argv[1] ) {
		if ( $argc < 7 ) {
			fwrite( STDERR, "Uso: php bin/release.php replace free|pro file.zip nota firma kid [firma_v2 kid_v2 signed_at min_version]\n" );
			exit( 2 );
		}
		$row = Portale\Releases::replace( $db, $argv[2], $argv[3], $argv[4], 'cli', $argv[5], $argv[6], $v2from( 7 ) );
		echo 'Sostituita ', $argv[2], ' ', $row['version'], ' (', $row['size'], ' byte, sha256 ', $row['sha256'], ", firmata; il vecchio zip è conservato)\n";
		exit( 0 );
	}
	$row = Portale\Releases::add( $db, $argv[1], $argv[2], $argv[3] ?? '', 'cli', null, $argv[4] ?? null, $argv[5] ?? null, $v2from( 6 ) );
	echo 'Pubblicata ', $argv[1], ' ', $row['version'], ' (', $row['size'], ' byte, sha256 ', $row['sha256'], ! empty( $row['signature'] ) ? ', firmata' . ( ! empty( $row['signature_v2'] ) ? ' v1+v2' : ' solo v1' ) : ', NON firmata: niente aggiornamento diretto', ")\n";
} catch ( Portale\UserError $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

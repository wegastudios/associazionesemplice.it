<?php
/**
 * Due connessioni vere su MySQL 8 (workflow «WordPress plugin»): le stesse istruzioni di CourseMonths::fix() e ::due(), fuori da WordPress.
 * La connessione A apre una transazione e fissa la sua istantanea; B scrive il mese e il dovuto; A prova a scriverli (INSERT IGNORE) e poi li legge
 * con una lettura che blocca. Esito giusto: A legge ciò che ha scritto B, oppure il database rifiuta la lettura (allora il codice si ferma e fa riprovare).
 * Esito sbagliato: A legge un valore diverso o crede di aver scritto lei. Uso: php prova-mysql-concorrenza.php host utente password database
 */
mysqli_report( MYSQLI_REPORT_OFF );
list( , $host, $user, $pass, $name ) = $argv;
$a = new mysqli( $host, $user, $pass, $name );
$b = new mysqli( $host, $user, $pass, $name );
if ( $a->connect_errno || $b->connect_errno ) {
	fwrite( STDERR, "connessione non riuscita\n" );
	exit( 2 );
}
echo 'Server: ' . $a->server_info . "\n";
$a->query( 'DROP TABLE IF EXISTS cm' );
$a->query( 'DROP TABLE IF EXISTS cd' );
$a->query( 'CREATE TABLE cm (id bigint unsigned NOT NULL AUTO_INCREMENT, activity_id bigint unsigned NOT NULL, month char(7) NOT NULL, dates text NOT NULL, PRIMARY KEY (id), UNIQUE KEY activity_month (activity_id, month)) ENGINE=InnoDB' );
$a->query( 'CREATE TABLE cd (id bigint unsigned NOT NULL AUTO_INCREMENT, activity_id bigint unsigned NOT NULL, person_id bigint unsigned NOT NULL, month char(7) NOT NULL, due_cents bigint NOT NULL, PRIMARY KEY (id), UNIQUE KEY apm (activity_id, person_id, month)) ENGINE=InnoDB' );
$bad = 0;
foreach ( array( 'REPEATABLE READ', 'READ COMMITTED' ) as $level ) {
	$a->query( 'DELETE FROM cm' );
	$a->query( 'DELETE FROM cd' );
	$a->query( "SET SESSION TRANSACTION ISOLATION LEVEL $level" );
	$a->query( 'START TRANSACTION' );
	$a->query( 'SELECT COUNT(*) FROM cm' )->fetch_row(); // istantanea ferma
	$a->query( 'SELECT COUNT(*) FROM cd' )->fetch_row();
	$b->query( "INSERT INTO cm (activity_id, month, dates) VALUES (1, '2031-06', '2031-06-03,2031-06-10,2031-06-17')" );
	$b->query( "INSERT INTO cd (activity_id, person_id, month, due_cents) VALUES (1, 7, '2031-06', 3000)" );
	foreach ( array( array( 'cm', "INSERT IGNORE INTO cm (activity_id, month, dates) VALUES (1, '2031-06', 'quattro,lezioni,del,calendario')", "SELECT dates FROM cm WHERE activity_id = 1 AND month = '2031-06' FOR UPDATE", '2031-06-03,2031-06-10,2031-06-17' ), array( 'cd', "INSERT IGNORE INTO cd (activity_id, person_id, month, due_cents) VALUES (1, 7, '2031-06', 4000)", "SELECT due_cents FROM cd WHERE activity_id = 1 AND person_id = 7 AND month = '2031-06' FOR UPDATE", '3000' ) ) as list( $t, $ins, $sel, $want ) ) {
		$a->query( $ins );
		$mine = $a->affected_rows;
		$r    = $a->query( $sel );
		$err  = $a->error;
		$got  = $r ? ( $r->fetch_row()[0] ?? null ) : null;
		if ( 1 === $mine ) {
			$out = 'ERRORE: crede di aver scritto lei';
			$bad++;
		} elseif ( null !== $got && (string) $got === $want ) {
			$out = 'ok: legge la riga dell\'altra connessione';
		} elseif ( null === $got && '' !== $err ) {
			$out = 'ok: lettura rifiutata («' . $err . '»): il codice si ferma e fa riprovare';
		} else {
			$out = 'ERRORE: legge ' . var_export( $got, true );
			$bad++;
		}
		echo "- $level, $t: $out\n";
	}
	$a->query( 'ROLLBACK' );
}
exit( $bad ? 1 : 0 );

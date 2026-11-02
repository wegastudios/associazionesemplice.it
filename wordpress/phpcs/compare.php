<?php
// Confronta il rapporto JSON di phpcs con l'elenco dei risultati già verificati (file, regola, quanti). Esce con 1 se ne compaiono di nuovi.
// Uso: php compare.php rapporto.json baseline.txt radice-del-pacchetto [--update]
list( , $report, $baseline, $root ) = $argv + array( '', '', '', '' );
$update = in_array( '--update', $argv, true );
$j      = json_decode( (string) file_get_contents( $report ), true );
$now    = array();
foreach ( (array) ( $j['files'] ?? array() ) as $path => $f ) {
	$rel = ltrim( str_replace( rtrim( $root, '/' ), '', $path ), '/' );
	foreach ( (array) $f['messages'] as $m ) {
		$k         = $rel . "\t" . $m['source'];
		$now[ $k ] = ( $now[ $k ] ?? 0 ) + 1;
	}
}
ksort( $now );
if ( $update ) {
	$out = '';
	foreach ( $now as $k => $n ) {
		$out .= $k . "\t" . $n . "\n";
	}
	file_put_contents( $baseline, $out );
	echo count( $now ) . " voci scritte in $baseline\n";
	exit( 0 );
}
$known = array();
foreach ( is_file( $baseline ) ? file( $baseline, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array() as $line ) {
	$p = explode( "\t", $line );
	if ( 3 === count( $p ) ) {
		$known[ $p[0] . "\t" . $p[1] ] = (int) $p[2];
	}
}
$new = 0;
foreach ( $now as $k => $n ) {
	if ( $n > ( $known[ $k ] ?? 0 ) ) {
		list( $file, $src ) = explode( "\t", $k );
		echo "NUOVO: $file — $src (" . ( $n - ( $known[ $k ] ?? 0 ) ) . " in più)\n";
		$new += $n - ( $known[ $k ] ?? 0 );
	}
}
$gone = 0;
foreach ( $known as $k => $n ) {
	$gone += max( 0, $n - ( $now[ $k ] ?? 0 ) );
}
echo 'Segnalazioni: ' . array_sum( $now ) . ' (già verificate: ' . array_sum( $known ) . ", nuove: $new, sparite: $gone)\n";
exit( $new > 0 ? 1 : 0 );

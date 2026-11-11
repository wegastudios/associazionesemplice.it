<?php
/**
 * Controllo dei link (docs/GRAFICA_UX.md §6.4): apre le pagine pubbliche, segue i link di barra e piede e dice quali non rispondono
 * 200 (o un rinvio che finisce in 200). Uso: php bin/link-check.php [https://associazionesemplice.it]
 * Esce con 1 se almeno un link nostro è rotto. Non usa il database né la configurazione: solo richieste GET.
 */
$base  = rtrim( $argv[1] ?? 'https://associazionesemplice.it', '/' );
$host  = parse_url( $base, PHP_URL_HOST );
$pages = array( '/', '/funzioni', '/prezzi', '/domande', '/confronto', '/saas', '/wordpress', '/free', '/accedi', '/accedi/password-dimenticata', '/prova' );
$seen  = array();
$bad   = 0;
function fetch( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'link-check' ) );
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, $body );
}
foreach ( $pages as $p ) {
	list( $code, $body ) = fetch( $base . $p );
	if ( 200 !== $code ) {
		echo "PAGINA  $code  $p\n";
		$bad++;
		continue;
	}
	preg_match_all( '/<(?:header|footer|nav)\b.*?<\/(?:header|footer|nav)>/is', $body, $blocks );
	preg_match_all( '/href="([^"#]+)"/i', implode( ' ', $blocks[0] ), $m );
	foreach ( array_unique( $m[1] ) as $href ) {
		$href = html_entity_decode( $href );
		if ( 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) || 0 === strpos( $href, '/assets/' ) ) {
			continue;
		}
		$url = 0 === strpos( $href, '/' ) ? $base . $href : $href;
		if ( ! preg_match( '#^https?://#', $url ) || isset( $seen[ $url ] ) ) {
			continue;
		}
		$seen[ $url ] = true;
		$h = (string) parse_url( $url, PHP_URL_HOST );
		if ( $h !== $host && substr( $h, -strlen( $host ) - 1 ) !== '.' . $host ) {
			continue; // solo link nostri
		}
		list( $c ) = fetch( $url );
		if ( 200 !== $c ) {
			echo "LINK    $c  $url  (da $p)\n";
			$bad++;
		}
	}
}
echo $bad ? "\n$bad link da sistemare\n" : "Tutti i link rispondono.\n";
exit( $bad ? 1 : 0 );

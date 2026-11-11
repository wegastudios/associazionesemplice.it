<?php
namespace Portale;

/**
 * Proxy in PHP verso il SaaS: `/saas/admin`, `/saas/staff`, `/saas/api` e `/saas/vai` si servono dal gestionale restando sul dominio principale.
 * La richiesta va a `saas_proxy.upstream` (fisso, mai preso dalla richiesta) con lo stesso percorso, metodo, query e corpo; la risposta torna così com'è
 * (status e redirect non si seguono), con `Location` riscritta sul dominio del portale e `Set-Cookie` senza `Domain`.
 *
 * Sicurezza: solo i prefissi qui sotto; intestazioni inoltrate da un elenco (tutte le `X-Forwarded-*` del client si scartano e le imposta il portale);
 * il cookie di sessione del portale non va mai al SaaS e un cookie del SaaS con lo stesso nome non arriva al browser. Si accende con
 * `saas_proxy.enabled` in config.php; spento, tutto resta come prima.
 */
final class SaasProxy {

	const PREFIXES = array( '/saas/admin', '/saas/staff', '/saas/api', '/saas/vai' );
	const MAX_BODY = 20971520;

	/** @var callable|null function (string $method, string $url, array $headers, string $body): array{0:int,1:array,2:string} [status, righe di intestazione, corpo] */
	public static $transport = null;

	public static function enabled(): bool {
		return true === Config::get( 'saas_proxy.enabled', false );
	}

	public static function handles( string $path ): bool {
		foreach ( self::PREFIXES as $p ) {
			if ( $path === $p || 0 === strpos( $path, $p . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Indirizzo del SaaS (solo https, senza barra finale). */
	public static function upstream(): string {
		$u = rtrim( trim( (string) Config::get( 'saas_proxy.upstream', 'https://app.associazionesemplice.it' ) ), '/' );
		return preg_match( '#^https://[A-Za-z0-9.-]+(:\d+)?$#', $u ) ? $u : 'https://app.associazionesemplice.it';
	}

	/** Cookie del browser senza quello di sessione del portale. */
	public static function cookieHeader( string $raw, string $portalSession ): string {
		$keep = array();
		foreach ( explode( ';', $raw ) as $c ) {
			$c = trim( $c );
			if ( '' === $c || 0 === strpos( $c, $portalSession . '=' ) ) {
				continue;
			}
			$keep[] = $c;
		}
		return implode( '; ', $keep );
	}

	/** Intestazioni da mandare al SaaS: solo quelle dell'elenco, più le X-Forwarded-* decise dal portale. */
	public static function requestHeaders( array $server, string $clientIp, string $portalSession ): array {
		$map = array(
			'CONTENT_TYPE' => 'Content-Type', 'HTTP_ACCEPT' => 'Accept', 'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language', 'HTTP_USER_AGENT' => 'User-Agent',
			'HTTP_AUTHORIZATION' => 'Authorization', 'HTTP_ORIGIN' => 'Origin', 'HTTP_REFERER' => 'Referer', 'HTTP_X_REQUESTED_WITH' => 'X-Requested-With',
			'HTTP_X_CSRF_TOKEN' => 'X-CSRF-TOKEN', 'HTTP_X_XSRF_TOKEN' => 'X-XSRF-TOKEN', 'HTTP_X_INERTIA' => 'X-Inertia', 'HTTP_X_LIVEWIRE' => 'X-Livewire',
		);
		$out = array();
		foreach ( $map as $key => $name ) {
			if ( isset( $server[ $key ] ) && '' !== (string) $server[ $key ] && ! preg_match( '/[\r\n]/', (string) $server[ $key ] ) ) {
				$out[] = $name . ': ' . $server[ $key ];
			}
		}
		$cookie = self::cookieHeader( (string) ( $server['HTTP_COOKIE'] ?? '' ), $portalSession );
		if ( '' !== $cookie && ! preg_match( '/[\r\n]/', $cookie ) ) {
			$out[] = 'Cookie: ' . $cookie;
		}
		$host  = (string) parse_url( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), PHP_URL_HOST );
		$out[] = 'X-Forwarded-Host: ' . $host;
		$out[] = 'X-Forwarded-Proto: https';
		$out[] = 'X-Forwarded-For: ' . $clientIp;
		return $out;
	}

	/** `Location` del SaaS → indirizzo sul dominio del portale (sotto i prefissi proxati; altrimenti sotto /saas). */
	public static function rewriteLocation( string $loc ): string {
		$up = self::upstream();
		if ( 0 === strpos( $loc, $up . '/' ) || $loc === $up ) {
			$loc = (string) substr( $loc, strlen( $up ) );
			$loc = '' === $loc ? '/' : $loc;
		} elseif ( ! isset( $loc[0] ) || '/' !== $loc[0] || 0 === strpos( $loc, '//' ) ) {
			return $loc; // indirizzo esterno o relativo: così com'è
		}
		$path = (string) parse_url( $loc, PHP_URL_PATH );
		return self::handles( $path ) ? $loc : '/saas' . $loc;
	}

	/** `Set-Cookie` del SaaS senza `Domain`; null se tenterebbe di sostituire il cookie di sessione del portale. */
	public static function rewriteSetCookie( string $line, string $portalSession ): ?string {
		if ( 0 === strpos( ltrim( $line ), $portalSession . '=' ) ) {
			return null;
		}
		$parts = array_values( array_filter( array_map( 'trim', explode( ';', $line ) ), function ( $p ) {
			return 0 !== stripos( $p, 'domain=' );
		} ) );
		return implode( '; ', $parts );
	}

	/**
	 * Inoltra la richiesta e prepara la risposta. @return array{0:int,1:array,2:string} status, intestazioni [nome, valore, sostituisci], corpo
	 * @param array $server $_SERVER
	 */
	public static function handle( array $server, string $body, string $clientIp, string $portalSession = 'PHPSESSID' ): array {
		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		$path   = (string) parse_url( (string) ( $server['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$query  = (string) ( $server['QUERY_STRING'] ?? '' );
		$plain  = rawurldecode( $path );
		if ( ! self::handles( $path ) || preg_match( '#(^|/)\.\.?(/|$)#', $plain ) || false !== strpos( $plain, "\0" ) || false !== strpos( $plain, '\\' ) ) {
			return array( 400, array( array( 'Content-Type', 'text/plain; charset=utf-8', true ) ), 'Richiesta non valida.' );
		}
		if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ) {
			return array( 405, array( array( 'Content-Type', 'text/plain; charset=utf-8', true ) ), 'Metodo non consentito.' );
		}
		$url     = self::upstream() . $path . ( '' !== $query ? '?' . $query : '' );
		$headers = self::requestHeaders( $server, $clientIp, $portalSession );
		$send    = null !== self::$transport ? self::$transport : array( __CLASS__, 'curl' );
		list( $status, $lines, $content ) = call_user_func( $send, $method, $url, $headers, $body );
		if ( $status < 100 ) {
			return array( 502, array( array( 'Content-Type', 'text/plain; charset=utf-8', true ), array( 'Cache-Control', 'no-store', true ) ), 'Il gestionale non risponde in questo momento: riprova tra poco.' );
		}
		$pass = array( 'content-type', 'content-disposition', 'content-language', 'cache-control', 'pragma', 'expires', 'retry-after', 'www-authenticate', 'x-robots-tag',
			'content-security-policy', 'referrer-policy', 'x-content-type-options', 'x-frame-options', 'permissions-policy' );
		$out = array();
		foreach ( $lines as $line ) {
			$p = explode( ':', (string) $line, 2 );
			if ( 2 !== count( $p ) ) {
				continue;
			}
			$name = strtolower( trim( $p[0] ) );
			$val  = trim( $p[1] );
			if ( 'location' === $name ) {
				$out[] = array( 'Location', self::rewriteLocation( $val ), true );
			} elseif ( 'set-cookie' === $name ) {
				$c = self::rewriteSetCookie( $val, $portalSession );
				if ( null !== $c ) {
					$out[] = array( 'Set-Cookie', $c, false );
				}
			} elseif ( in_array( $name, $pass, true ) ) {
				$out[] = array( $name, $val, true );
			}
		}
		return array( $status, $out, 'HEAD' === $method ? '' : (string) $content );
	}

	/** Esegue la richiesta e invia la risposta al browser. */
	public static function serve( array $server ): void {
		$body = '';
		if ( ! in_array( strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) ) {
			if ( (int) ( $server['CONTENT_LENGTH'] ?? 0 ) > self::MAX_BODY ) {
				http_response_code( 413 );
				exit( 'Richiesta troppo grande.' );
			}
			$body = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY );
		}
		$ip = ClientIp::from( $server, (bool) Config::get( 'trust_forwarded_for', false ), (int) Config::get( 'forwarded_hops', 1 ) );
		list( $status, $headers, $content ) = self::handle( $server, $body, $ip, session_name() );
		http_response_code( $status );
		foreach ( $headers as $h ) {
			header( $h[0] . ': ' . $h[1], $h[2] );
		}
		echo $content;
	}

	/** Trasporto reale: niente redirect seguiti, tempo breve, nessuna cache; il corpo arriva già decompresso. */
	public static function curl( string $method, string $url, array $headers, string $body ): array {
		$lines = array();
		$ch    = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_ENCODING       => '',
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$lines ) {
				if ( 0 === stripos( $line, 'HTTP/' ) ) {
					$lines = array(); // nuova risposta (dopo un 100-continue, per esempio): si riparte
				} elseif ( '' !== trim( $line ) ) {
					$lines[] = rtrim( $line );
				}
				return strlen( $line );
			},
		) );
		if ( '' !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		}
		$raw    = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return array( false === $raw ? 0 : $status, $lines, false === $raw ? '' : (string) $raw );
	}
}

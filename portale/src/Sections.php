<?php
namespace Portale;

/**
 * Indirizzi delle sezioni /saas e /wordpress (docs/ARCHITETTURA_DOMINI.md §2).
 * /saas e /wordpress sono sezioni di contenuti; /saas/admin appartiene al SaaS e /wordpress/admin al plugin.
 * Qui vivono i redirect dei vecchi indirizzi e l'elenco delle pagine di contenuto: nessun dato, nessuna scrittura.
 */
final class Sections {

	/** Pagine di contenuto: indirizzo → vista. */
	const PAGES = array(
		'/saas'                 => 'saas_section',
		'/wordpress'            => 'wordpress_section',
		'/wordpress/changelog'  => 'wordpress_changelog',
	);

	/** Dove si apre l'amministrazione del SaaS quando il server non la serve già sotto /saas/admin (ARCHITETTURA_DOMINI §3.3). */
	public static function saasAdminUrl(): string {
		$url = trim( (string) Config::get( 'saas_admin_url', 'https://app.associazionesemplice.it/admin' ) );
		return preg_match( '#^https://[^\s]+$#', $url ) ? rtrim( $url, '/' ) : 'https://app.associazionesemplice.it/admin';
	}

	/**
	 * Redirect per i vecchi indirizzi sotto /saas. Restituisce [codice, indirizzo] oppure null se la richiesta non è un vecchio indirizzo.
	 * 301/308 solo dove la destinazione è definitiva; 302/307 per /saas/admin finché non è deciso dove gira (§11, punto 2).
	 *
	 * @return array{0:int,1:string}|null
	 */
	public static function legacy( string $method, string $path, string $query = '' ): ?array {
		$get = 'GET' === $method || 'HEAD' === $method;
		$qs  = '' !== $query ? '?' . $query : '';
		if ( '/saas/accedi' === $path ) {
			// «Gestisci abbonamento» dal gestionale (contratto §3.2): stesso token, nuovo indirizzo.
			return array( $get ? 301 : 308, '/account/da-gestionale' . $qs );
		}
		if ( '/saas/admin' === $path || 0 === strpos( $path, '/saas/admin/' ) ) {
			$rest = substr( $path, strlen( '/saas/admin' ) );
			return array( $get ? 302 : 307, self::saasAdminUrl() . $rest . $qs );
		}
		if ( 0 === strpos( $path, '/saas/utenti/' ) || preg_match( '#^/saas/[A-Za-z0-9-]{8,64}$#', $path ) ) {
			// Vecchia gestione delle associazioni dal portale (contratto v1 §2.3, ritirata): ora è l'amministrazione del SaaS.
			return array( $get ? 301 : 308, '/saas/admin' );
		}
		return null;
	}

	public static function view( string $path ): ?string {
		return self::PAGES[ $path ] ?? null;
	}

	/** Versioni pubblicate per prodotto, dalla più recente, con data e novità dell'ultima. */
	public static function releases( Db $db ): array {
		$out = array();
		foreach ( array( 'free' => 'Plugin gratuito', 'pro' => 'Plugin Pro' ) as $product => $label ) {
			$all = Releases::all( $db, $product );
			$out[ $product ] = array(
				'label'    => $label,
				'versions' => $all,
				'latest'   => $all ? Releases::changelog( $product, (string) $all[0]['version'] ) : '',
			);
		}
		return $out;
	}
}

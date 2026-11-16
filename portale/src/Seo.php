<?php
namespace Portale;

/**
 * Motori di ricerca: elenco delle pagine pubbliche (sitemap.xml), indirizzo canonico, Open Graph, Twitter card, dati strutturati e robots.txt.
 * Quello che resta fuori dall'indice (account, accesso, amministrazione, demo, API) non compare né nella sitemap né nei tag.
 * L'apertura vera ai motori di ricerca resta l'interruttore `indexable` (pannello Impostazioni): senza, il robots.txt chiude tutto e le pagine sono noindex.
 */
final class Seo {

	/** Pagine pubbliche indicizzabili, oltre a quelle di presentazione (Site::MARKETING). */
	const EXTRA = array( '/privacy', '/cookie', '/termini', '/wordpress/changelog' );

	/** Pagine che non vanno mai nell'indice anche se «di presentazione»: accesso e simili. */
	const NEVER = array( '/accedi' );

	/** Immagine di anteprima per i social (1200x630). */
	const IMAGE = '/assets/brand/og-1200x630.png';

	public static function base(): string {
		return rtrim( (string) Config::get( 'base_url', '' ), '/' );
	}

	/** @return string[] percorsi delle pagine pubbliche da indicizzare (senza il gestionale finché non è in vendita) */
	public static function pages(): array {
		$out = array();
		foreach ( array_merge( Site::MARKETING, self::EXTRA ) as $p ) {
			if ( in_array( $p, self::NEVER, true ) || ( '/saas' === $p && ! SaasSale::on() ) ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	public static function indexablePath( string $path ): bool {
		return in_array( $path, self::pages(), true );
	}

	public static function canonical( string $path ): string {
		return self::base() . ( '/' === $path ? '/' : $path );
	}

	public static function sitemap(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::pages() as $p ) {
			$xml .= '<url><loc>' . htmlspecialchars( self::canonical( $p ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</loc></url>' . "\n";
		}
		return $xml . '</urlset>' . "\n";
	}

	public static function robots(): string {
		if ( ! Config::get( 'indexable', false ) ) {
			return "User-agent: *\nDisallow: /\n";
		}
		$out = "User-agent: *\nAllow: /\n";
		foreach ( array( '/account', '/accedi', '/twofactor', '/grazie', '/paypal', '/admin', '/wordpress/admin', '/saas/admin', '/api', '/scarica', '/entra', '/demo/' ) as $d ) {
			$out .= 'Disallow: ' . $d . "\n";
		}
		return $out . 'Sitemap: ' . self::base() . "/sitemap.xml\n";
	}

	/** Codice di verifica di Google Search Console (solo il valore del meta tag), vuoto se non configurato o non valido. */
	public static function googleVerification(): string {
		$v = trim( (string) Config::get( 'seo.google_verification', '' ) );
		return 1 === preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $v ) ? $v : '';
	}

	/** Tag canonical, Open Graph e Twitter card per una pagina (stringa vuota per le pagine fuori dall'indice). */
	public static function tags( string $path, string $title, string $description ): string {
		$meta = '';
		if ( '' !== self::googleVerification() ) {
			$meta .= '<meta name="google-site-verification" content="' . e( self::googleVerification() ) . '">' . "\n";
		}
		if ( ! self::indexablePath( $path ) ) {
			return $meta;
		}
		$url   = self::canonical( $path );
		$image = self::base() . self::IMAGE;
		$t     = '' !== $title ? $title . ' · Associazione Semplice' : 'Associazione Semplice';
		$meta .= '<link rel="canonical" href="' . e( $url ) . '">' . "\n";
		$meta .= '<meta property="og:type" content="website"><meta property="og:site_name" content="Associazione Semplice"><meta property="og:locale" content="it_IT">' . "\n";
		$meta .= '<meta property="og:title" content="' . e( $t ) . '"><meta property="og:description" content="' . e( $description ) . '"><meta property="og:url" content="' . e( $url ) . '">' . "\n";
		$meta .= '<meta property="og:image" content="' . e( $image ) . '"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="Associazione Semplice">' . "\n";
		$meta .= '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . e( $t ) . '"><meta name="twitter:description" content="' . e( $description ) . '"><meta name="twitter:image" content="' . e( $image ) . '">' . "\n";
		return $meta;
	}

	/** Dati strutturati: l'organizzazione in home, il plugin (senza prezzi del gestionale) in /wordpress. */
	public static function jsonLd( string $path ): string {
		$data = null;
		if ( '/' === $path ) {
			$data = array(
				'@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Associazione Semplice',
				'url' => self::base() . '/', 'logo' => self::base() . '/assets/brand/icona-512.png',
			);
		} elseif ( '/wordpress' === $path ) {
			$data = array(
				'@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Associazione Semplice (plugin per WordPress)',
				'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'WordPress', 'inLanguage' => 'it',
				'url' => self::canonical( '/wordpress' ),
				'description' => Site::description( '/wordpress' ),
			);
		}
		if ( ! $data ) {
			return '';
		}
		return '<script type="application/ld+json">' . str_replace( '</', '<\/', (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</script>' . "\n";
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Cache di pagina dei siti che ne hanno una: le pagine pubbliche degli eventi (elenco, pagina dell'evento, prossime date) sono uguali per tutti
 * gli anonimi e si possono conservare; quando prenotazioni, date o attività cambiano la cache si svuota, così i posti liberi non restano vecchi.
 */
final class PageCache {

	public static function register(): void {
		add_action( 'asem_audit_logged', array( __CLASS__, 'on_audit' ) );
		add_filter( 'rocket_cache_reject_cookies', array( __CLASS__, 'reject_session_cookie' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WP Rocket
	}

	/** Chi è entrato con l'accesso proprio non ha il cookie di WordPress: WP Rocket non serve né salva pagine per chi ha quello di sessione. */
	public static function reject_session_cookie( $cookies ): array {
		$cookies   = is_array( $cookies ) ? $cookies : array();
		$cookies[] = MemberSession::COOKIE;
		return array_values( array_unique( $cookies ) );
	}

	/** Ogni azione registrata che cambia ciò che il sito pubblico mostra programma lo svuotamento a fine richiesta (l'aggancio è sempre lo stesso: parte una volta sola). */
	public static function on_audit( $action ): void {
		if ( CachePolicy::changes_public_pages( (string) $action ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/** Svuota la cache di pagina dei plugin più diffusi; per gli altri c'è l'azione «asem_public_pages_changed». */
	public static function flush(): void {
		do_action( 'asem_public_pages_changed' );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di LiteSpeed Cache
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache
		}
		do_action( 'sg_cachepress_purge_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di SiteGround Optimizer
		do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di Cache Enabler
	}
}

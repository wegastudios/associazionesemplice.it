<?php
namespace Portale;

/**
 * Modalità manutenzione (interruttore «Modalità manutenzione» in /account/impostazioni, spento di default).
 * Acceso: i visitatori vedono una pagina semplice con stato 503, Retry-After e noindex; chi è entrato come staff vede il sito normale con una banda.
 * Non si ferma MAI quello che usano i siti dei clienti e i servizi collegati: API delle licenze e degli aggiornamenti, scarico dei pacchetti con collegamento
 * firmato, catalogo, chiavi pubbliche, webhook di Stripe e PayPal, avvisi e API del SaaS (proxy). Il cron gira da riga di comando e non passa di qui.
 * Restano raggiungibili anche l'accesso (con la verifica in due passaggi) e il ritorno da PayPal, per poterla spegnere e non perdere un pagamento.
 */
final class Maintenance {

	/** Secondi suggeriti ai motori di ricerca e ai client prima di riprovare. */
	const RETRY_AFTER = 600;

	/** Indirizzi (esatti o con prefisso «/…/») sempre raggiungibili anche dai visitatori, oltre a quelli dei servizi. */
	const OPEN = array( '/accedi', '/twofactor', '/logout', '/entra', '/supporto/accedi', '/paypal/ritorno', '/robots.txt', '/api/piani' );

	public static function on(): bool {
		return true === Config::get( 'maintenance.enabled', false );
	}

	/** Chi può vedere il sito normale durante la manutenzione: staff del portale (sessione di amministrazione, staff, o accesso unico con un ruolo). */
	public static function bypass(): bool {
		if ( ! empty( $_SESSION['admin'] ) || ! empty( $_SESSION['staff'] ) ) {
			return true;
		}
		if ( Identity::enabled() ) {
			$i = Identity::check();
			return $i && array_filter( (array) ( $i['areas'] ?? array() ) );
		}
		return false;
	}

	/** Indirizzi che non si fermano mai: API per i siti dei clienti, webhook, scarico firmato, catalogo, chiavi, proxy del SaaS. */
	public static function isService( string $path ): bool {
		return 0 === strpos( $path, '/api/' ) || 0 === strpos( $path, '/webhook/' ) || 0 === strpos( $path, '/.well-known/' )
			|| Plugin\CatalogApi::PATH === $path || Plugin\DownloadLink::PATH === $path || SaasProxy::handles( $path );
	}

	/** Vero se questa richiesta va fermata con la pagina di manutenzione. */
	public static function blocks( string $path, bool $staff ): bool {
		if ( ! self::on() || $staff || self::isService( $path ) ) {
			return false;
		}
		if ( 0 === strpos( $path, Plugin\Admin::BASE ) ) {
			return false; // /wordpress/admin: serve lo staff (l'accesso e la sessione sono lì), e senza ruolo non si entra comunque
		}
		foreach ( self::OPEN as $open ) {
			if ( $open === $path || 0 === strpos( $path, $open . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Pagina semplice per i visitatori: 503, Retry-After e noindex. */
	public static function serve(): void {
		http_response_code( 503 );
		header( 'Retry-After: ' . self::RETRY_AFTER );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Cache-Control: no-store' );
		View::security();
		require __DIR__ . '/../views/maintenance.php';
	}
}

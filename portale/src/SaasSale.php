<?php
namespace Portale;

/**
 * Il gestionale online è in vendita sul portale? Decisione del titolare del 16/11/2026: per ora no (prove di uso più approfondite e feedback prima del lancio).
 * Con la vendita spenta il sito non mostra prezzi, piani, prova gratuita, iscrizione né colonna del gestionale nel confronto, e /saas e /saas/acquista
 * rimandano alla home. Non si tocca altro: il SaaS, la sua demo e i clienti già attivi (account, «Gestisci», amministrazione) funzionano come prima.
 * Si riaccende dall'interruttore «Vendita del gestionale online» in /account/impostazioni (o `saas_sale.enabled` in config.php).
 */
final class SaasSale {

	public static function on(): bool {
		return true === Config::get( 'saas_sale.enabled', false );
	}

	/** Dove si manda chi apre una pagina di vendita del gestionale mentre è spenta. */
	public static function fallback(): string {
		return '/';
	}

	/** Vero se il percorso è una pagina di vendita del gestionale (solo contenuto e acquisto, non l'area dei clienti già attivi). */
	public static function isSalesPath( string $path ): bool {
		return '/saas' === $path || '/saas/acquista' === $path;
	}
}

<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Texts;
use AssociazioneSemplice\UpdateCheck;
use AssociazioneSemplice\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso chiudibile «È disponibile una nuova versione» in cima alle pagine del plugin. Una chiusura vale 30 giorni per quella versione:
 * se ne esce una più recente l'avviso ricompare. Non blocca niente.
 */
final class UpdateNotice {

	public static function print_notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'asem' ) || ! current_user_can( Plugin::CAP_OPS ) ) {
			return;
		}
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Markup degli avvisi (uno per prodotto da aggiornare), stringa vuota se non c'è niente da segnalare. */
	public static function html(): string {
		$out = '';
		foreach ( UpdateCheck::pending() as $u ) {
			$head = '<strong>È disponibile la versione ' . esc_html( $u['latest'] ) . ' di ' . esc_html( $u['label'] ) . '</strong> (hai la ' . esc_html( $u['installed'] ) . '). ';
			if ( self::via_core( $u['product'] ) ) {
				// Aggiornamento diretto attivo: si installa da Bacheca → Aggiornamenti, dove il pacchetto è verificato con la firma.
				$body = esc_html( Texts::t( 'Installala da Bacheca → Aggiornamenti: i dati restano.' ) ) . ' '
					. '<a class="button button-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html( Texts::t( 'Vai agli aggiornamenti' ) ) . '</a>';
			} else {
				$body = 'Scaricala dalla tua area riservata e carica il nuovo pacchetto da Plugin → Aggiungi nuovo: i dati restano. '
					. '<a class="button button-primary" href="' . esc_url( UpdateCheck::download_url( $u ) ) . '" target="_blank" rel="noopener">Scarica l\'aggiornamento</a>';
			}
			$out .= Dismiss::html( 'update-' . $u['product'] . '-' . $u['latest'], 'info', $head . $body, 30 );
		}
		return $out;
	}

	/** L'aggiornamento diretto dal portale è attivo e riguarda questo prodotto: allora WordPress lo offre in Bacheca → Aggiornamenti. */
	public static function via_core( string $product ): bool {
		return class_exists( '\AssociazioneSemplice\Updater' ) && Updater::enabled() && isset( Updater::products()[ $product ] );
	}
}

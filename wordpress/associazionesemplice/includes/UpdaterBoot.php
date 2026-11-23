<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Avvio dell'aggiornatore dal portale (solo nel pacchetto del portale, come Updater: in quello per WordPress.org non c'è).
 * In bacheca, nel cron e in WP-CLI Updater si registra subito; sul sito pubblico la sua classe nemmeno si legge, e si carica solo
 * se WordPress lavora davvero sugli aggiornamenti dei plugin (uno degli hook di HOOKS, ad esempio un aggiornamento chiesto da remoto).
 */
final class UpdaterBoot {

	/** Hook di WordPress con cui l'aggiornatore lavora anche fuori dalla bacheca. */
	const HOOKS = array( 'pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_pre_download', 'upgrader_process_complete' );

	public static function register( bool $backend ): void {
		if ( $backend ) {
			Updater::register();
			return;
		}
		$load = static function ( $value = null ) {
			Updater::register(); // una volta sola: le chiamate successive non fanno nulla; i suoi hook si aggiungono mentre WordPress esegue quello in corso
			return $value;
		};
		foreach ( self::HOOKS as $hook ) {
			add_filter( $hook, $load, 1 );
		}
	}
}

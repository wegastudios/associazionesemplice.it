<?php
/**
 * Modulo mu, SOLO per le prove nel browser in wp-env (workflow «Prove in sandbox»): accende le date finte del plugin (ASEM_TESTS) e usa come «oggi»
 * il giorno scritto nell'opzione asem_prova_oggi. Si copia in mu-plugins prima della prova e si toglie subito dopo; non fa parte del plugin.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ASEM_TESTS' ) ) {
	define( 'ASEM_TESTS', true );
}
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( '\AssociazioneSemplice\Db' ) ) {
			\AssociazioneSemplice\Db::$today_fake = (string) get_option( 'asem_prova_oggi', '' );
		}
	},
	0
);

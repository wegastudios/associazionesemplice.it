<?php
/**
 * Azioni di admin-post.php e admin-ajax.php (richiamato da tests/smoke.php, dopo Admin\Admin::init()).
 * Con un'azione chiesta si registra solo il suo gestore (più quelli dei gestori che registrano sempre il loro elenco), e ogni azione resta
 * raggiungibile: per ognuna si simula la richiesta vera (pagina admin-post.php o admin-ajax.php e il suo nome) e si controlla che il gestore ci sia.
 */

defined( 'ABSPATH' ) || exit;
$az_ok = function_exists( 'free_ok' ) ? 'free_ok' : 'asem_ok'; // anche nella prova dell'edizione gratuita

// La decisione: pagine, battito e azioni degli altri plugin non registrano nulla; le azioni del plugin sì; i moduli del sito solo le loro.
$az_ok( ! \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_', 'heartbeat', false ) && ! \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_', '', false ) && ! \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_', 'woocommerce_get_refreshed_fragments', false ), 'azioni: battito, pagine della bacheca e azioni degli altri plugin non registrano i gestori' );
$az_ok( \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_', 'asem_save_person', false ) && \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_front_', 'asem_front_book', false ) && ! \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_front_', 'asem_save_person', false ) && \AssociazioneSemplice\Plugin::action_handlers_needed( 'asem_', 'heartbeat', true ), 'azioni: quelle del plugin sì (i moduli del sito solo asem_front_…); in WP-CLI sempre' );

/** Nomi delle azioni asem_ con un gestore, ora: azione => [tipi di hook]. */
$az_now = static function (): array {
	$out = array();
	foreach ( array_keys( $GLOBALS['wp_filter'] ) as $h ) {
		if ( preg_match( '/^(admin_post_nopriv_|admin_post_|wp_ajax_nopriv_|wp_ajax_)(asem_[a-z0-9_]+)$/', (string) $h, $m ) && has_action( (string) $h ) ) {
			$out[ $m[2] ][] = $m[1];
		}
	}
	return $out;
};
$az_saved   = $GLOBALS['wp_filter'];
$az_page    = $GLOBALS['pagenow'] ?? '';
$az_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification
/**
 * Toglie i gestori delle azioni asem_, simula la richiesta ($script, $action) e rifà le registrazioni delle azioni come all'avvio.
 * @return array{0:array,1:string[]} azioni registrate (come $az_now) e quelle aggiunte dal caricamento su richiesta (Plugin::load_for_action)
 */
$az_boot = static function ( string $script, string $action ) use ( $az_now ): array {
	foreach ( array_keys( $GLOBALS['wp_filter'] ) as $h ) {
		if ( preg_match( '/^(admin_post|admin_post_nopriv|wp_ajax|wp_ajax_nopriv)_asem_/', (string) $h ) ) {
			unset( $GLOBALS['wp_filter'][ $h ] );
		}
	}
	$GLOBALS['pagenow'] = $script;
	unset( $_REQUEST['action'] );
	if ( '' !== $action ) {
		$_REQUEST['action'] = $action;
	}
	\AssociazioneSemplice\Admin\Admin::handlers();
	\AssociazioneSemplice\Frontend\Actions::register();
	$before = $az_now();
	if ( '' !== $action ) {
		\AssociazioneSemplice\Plugin::load_for_action( $action ); // come fa admin_init per i download (copia di sicurezza, documenti…)
	}
	$after = $az_now();
	return array( $after, array_keys( array_diff_key( $after, $before ) ) );
};

$az_all = $az_boot( '', '' )[0]; // nessuna azione chiesta (come in WP-CLI): si registrano tutte
$az_ok( count( $az_all ) > 100 && isset( $az_all['asem_save_person'], $az_all['asem_front_book'], $az_all['asem_person_search'], $az_all['asem_export'] ), 'azioni: senza un\'azione chiesta si registrano tutte (' . count( $az_all ) . ')' );
$az_fixed = $az_boot( 'admin-post.php', 'asem_zz_nessuna' )[0]; // gestori che registrano sempre tutto il loro elenco (piccoli: scarichi, ricerche)
$az_ok( count( $az_fixed ) < count( $az_all ) / 2 && ! isset( $az_fixed['asem_save_person'] ) && ! isset( $az_fixed['asem_front_book'] ), 'azioni: con un\'azione chiesta non si registrano le altre (restano ' . count( $az_fixed ) . ' dei gestori senza elenco)' );

$az_bad = array();
foreach ( $az_all as $az_a => $az_k ) {
	$az_script         = in_array( 'wp_ajax_', $az_k, true ) || in_array( 'wp_ajax_nopriv_', $az_k, true ) ? 'admin-ajax.php' : 'admin-post.php';
	list( $az_got, $az_lazy ) = $az_boot( $az_script, (string) $az_a );
	if ( ! isset( $az_got[ $az_a ] ) || array_diff( $az_k, $az_got[ $az_a ] ) ) {
		$az_bad[] = $az_a . ' (non raggiungibile)';
		continue;
	}
	$az_extra = array_diff( array_keys( $az_got ), array_keys( $az_fixed ), $az_lazy, array( $az_a ) );
	if ( $az_extra ) {
		$az_bad[] = $az_a . ' (registra anche ' . implode( ', ', array_slice( $az_extra, 0, 3 ) ) . ')';
	}
}
$az_ok( ! $az_bad, 'azioni: ognuna delle ' . count( $az_all ) . ' è raggiungibile con la sua richiesta e registra solo sé stessa' . ( $az_bad ? ': ' . implode( '; ', array_slice( $az_bad, 0, 8 ) ) : '' ) );

// Si torna come prima: tutte le azioni registrate (come in WP-CLI) per le prove che seguono.
$GLOBALS['wp_filter'] = $az_saved;
$GLOBALS['pagenow']   = $az_page;
$_REQUEST             = $az_request;
$az_ok( has_action( 'admin_post_asem_save_person' ) && has_action( 'admin_post_asem_front_book' ), 'azioni: ripristinate dopo la prova' );

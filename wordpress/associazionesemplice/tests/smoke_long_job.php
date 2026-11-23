<?php
/**
 * Prove del motore dei lavori lunghi (LongJob) con un lavoro di prova: 25 pezzi, uno per passo. Si carica da smoke.php.
 */

use AssociazioneSemplice\LongJob;

global $wpdb;

$lj_key  = 'asem_test_long_job';
$lj_user = get_current_user_id();
wp_set_current_user( 1 );
$GLOBALS['asem_lj_calls']   = 0;
$GLOBALS['asem_lj_cleaned'] = 0;
add_filter(
	'asem_long_jobs',
	function ( array $jobs ) use ( $lj_key ): array {
		$jobs[ $lj_key ] = array(
			'step'   => function ( array $s, float $deadline ): array {
				$GLOBALS['asem_lj_calls']++;
				$s['total'] = 25;
				if ( ! empty( $s['boom'] ) && 3 === (int) $s['done'] ) {
					throw new \RuntimeException( 'guasto di prova' );
				}
				$s['sum']  = (int) ( $s['sum'] ?? 0 ) + (int) $s['done'] + 1;
				$s['done'] = (int) $s['done'] + 1;
				if ( $s['done'] >= $s['total'] ) {
					$s['finished'] = true;
					$s['result']   = array( 'sum' => $s['sum'], 'deadline' => $deadline > 0 );
				}
				return $s;
			},
			'cancel' => function ( array $s ): void {
				$GLOBALS['asem_lj_cleaned']++;
			},
		);
		return $jobs;
	}
);
$lj_opt   = LongJob::OPT_PREFIX . $lj_key;
$lj_lock  = LongJob::LOCK_PREFIX . $lj_key;
$lj_cron  = function () use ( $lj_key ) {
	return false !== wp_next_scheduled( LongJob::HOOK, array( $lj_key ) );
};
$lj_lockrow = function () use ( $wpdb, $lj_lock ) {
	return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lj_lock ) );
};

// ---------- Avvio ----------
asem_ok( null === LongJob::status( $lj_key ) && '' === LongJob::panel( $lj_key ), 'lavori lunghi: prima dell\'avvio non c\'è stato né pannello' );
asem_ok( null === LongJob::definition( 'asem_nessun_lavoro' ) && null !== asem_throws( function () { LongJob::start( 'asem_nessun_lavoro' ); } ), 'lavori lunghi: un lavoro non dichiarato non parte' );
$lj_st = LongJob::start( $lj_key, array( 'sum' => 0 ) );
asem_ok( 'running' === $lj_st['status'] && 0 === $lj_st['percent'] && 0 === $GLOBALS['asem_lj_calls'], 'lavori lunghi: avviato, ancora nessun passo fatto' );
$lj_auto = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $lj_opt ) );
asem_ok( in_array( $lj_auto, array( 'no', 'off' ), true ), 'lavori lunghi: lo stato sta in un\'opzione che non si carica a ogni richiesta (autoload ' . $lj_auto . ')' );
asem_ok( $lj_cron() && ! $lj_lockrow(), 'lavori lunghi: all\'avvio la rete di sicurezza di WP-Cron è programmata e il blocco è libero' );
asem_ok( false !== strpos( (string) asem_throws( function () use ( $lj_key ) { LongJob::start( $lj_key ); } ), 'già in corso' ), 'lavori lunghi: lo stesso lavoro non parte due volte' );

// ---------- Tappe ----------
$lj_st = LongJob::run( $lj_key, 0.0 );
asem_ok( 'running' === $lj_st['status'] && 1 === $lj_st['done'] && 25 === $lj_st['total'] && 4 === $lj_st['percent'] && 1 === $GLOBALS['asem_lj_calls'] && ! $lj_lockrow(), 'lavori lunghi: una tappa breve fa un passo, salva il punto e libera il blocco' );
asem_ok( false !== strpos( $lj_st['message'], '1 di 25 (4%)' ), 'lavori lunghi: lo stato si legge come frase [' . $lj_st['message'] . ']' );
$lj_panel = LongJob::panel( $lj_key, array( 'title' => 'Prova' ) );
asem_ok( false !== strpos( $lj_panel, 'data-asem-job="' . $lj_key . '"' ) && false !== strpos( $lj_panel, '<progress class="asem-longjob-bar" max="100" value="4">' ) && false !== strpos( $lj_panel, 'asem-longjob-cancel' ) && false !== strpos( $lj_panel, 'data-asem-running="1"' ), 'lavori lunghi: il pannello ha barra, stato e «Annulla»' );

$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lj_lock, (string) time() ) ); // un'altra tappa in corso
$lj_st = LongJob::run( $lj_key, 0.0 );
asem_ok( ! empty( $lj_st['busy'] ) && 1 === $lj_st['done'] && 1 === $GLOBALS['asem_lj_calls'] && $lj_lockrow(), 'lavori lunghi: con un\'altra tappa in corso non si sovrappone (e non le toglie il blocco)' );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) ( time() - 600 ), $lj_lock ) ); // la tappa è morta: il blocco è scaduto
$lj_st = LongJob::run( $lj_key, 0.0 );
asem_ok( empty( $lj_st['busy'] ) && 2 === $lj_st['done'] && ! $lj_lockrow(), 'lavori lunghi: un blocco scaduto non ferma il lavoro, che riprende dal punto salvato' );

add_filter( 'asem_long_job_budget_seconds', '__return_zero' ); // la rete di sicurezza: una tappa da un passo per volta
wp_clear_scheduled_hook( LongJob::HOOK, array( $lj_key ) ); // WP-Cron toglie l'evento prima di eseguirlo
do_action( LongJob::HOOK, $lj_key );
remove_filter( 'asem_long_job_budget_seconds', '__return_zero' );
$lj_st = LongJob::status( $lj_key );
asem_ok( 3 === $lj_st['done'] && $lj_cron(), 'lavori lunghi: senza la pagina, l\'evento di WP-Cron fa una tappa e programma la prossima' );

$lj_st = LongJob::run( $lj_key, INF );
asem_ok( 'finished' === $lj_st['status'] && 100 === $lj_st['percent'] && 25 === $lj_st['done'] && array( 'sum' => 325, 'deadline' => true ) === $lj_st['result'] && 25 === $GLOBALS['asem_lj_calls'], 'lavori lunghi: si arriva in fondo con il risultato, ogni pezzo fatto una volta sola' );
asem_ok( ! $lj_cron() && ! $lj_lockrow() && 0 === $GLOBALS['asem_lj_cleaned'], 'lavori lunghi: a lavoro finito nessuna tappa resta programmata' );
$lj_st = LongJob::run( $lj_key, INF );
asem_ok( 'finished' === $lj_st['status'] && 25 === $GLOBALS['asem_lj_calls'], 'lavori lunghi: un lavoro finito non rifà nulla' );
$lj_panel = LongJob::panel( $lj_key );
asem_ok( false !== strpos( $lj_panel, 'data-asem-running="0"' ) && false === strpos( $lj_panel, 'asem-longjob-cancel' ) && false !== strpos( $lj_panel, 'Operazione completata.' ), 'lavori lunghi: a lavoro finito il pannello non ha più «Annulla»' );

// ---------- Annullamento ----------
LongJob::start( $lj_key, array() ); // un lavoro concluso si può riavviare
LongJob::run( $lj_key, 0.0 );
$lj_st = LongJob::cancel( $lj_key );
asem_ok( 'cancelled' === $lj_st['status'] && 1 === $GLOBALS['asem_lj_cleaned'] && ! $lj_cron() && ! $lj_lockrow(), 'lavori lunghi: «Annulla» ferma subito, pulisce e non lascia tappe programmate' );
$lj_calls = $GLOBALS['asem_lj_calls'];
$lj_st    = LongJob::run( $lj_key, INF );
asem_ok( 'cancelled' === $lj_st['status'] && $lj_calls === $GLOBALS['asem_lj_calls'], 'lavori lunghi: un lavoro annullato non va più avanti' );

LongJob::start( $lj_key, array() );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lj_lock, (string) time() ) ); // una tappa in corso
$lj_st = LongJob::cancel( $lj_key );
asem_ok( 'running' === $lj_st['status'] && $lj_st['cancelling'] && false !== strpos( $lj_st['message'], 'Annullamento richiesto' ), 'lavori lunghi: con una tappa in corso l\'annullamento aspetta la fine del passo' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $lj_lock ) );
$lj_calls = $GLOBALS['asem_lj_calls'];
$lj_st    = LongJob::run( $lj_key, INF );
asem_ok( 'cancelled' === $lj_st['status'] && $lj_calls === $GLOBALS['asem_lj_calls'] && 2 === $GLOBALS['asem_lj_cleaned'], 'lavori lunghi: la tappa successiva vede l\'annullamento e si ferma prima del passo' );

// ---------- Errore ----------
LongJob::start( $lj_key, array( 'boom' => true ) );
$lj_st = LongJob::run( $lj_key, INF );
asem_ok( 'failed' === $lj_st['status'] && 'guasto di prova' === $lj_st['error'] && 3 === $lj_st['done'] && 3 === $GLOBALS['asem_lj_cleaned'] && ! $lj_cron() && ! $lj_lockrow(), 'lavori lunghi: un errore ferma il lavoro con il messaggio, pulisce e libera il blocco' );
asem_ok( false !== strpos( $lj_st['message'], 'guasto di prova' ), 'lavori lunghi: l\'errore si legge nello stato [' . $lj_st['message'] . ']' );

$lj_st = LongJob::start( $lj_key, array( 'total' => 40 ) );
asem_ok( 'running' === $lj_st['status'] && 40 === $lj_st['total'] && 0 === $lj_st['percent'], 'lavori lunghi: il totale noto già all\'avvio si legge subito' );
LongJob::cancel( $lj_key );

// ---------- Richieste della pagina ----------
LongJob::start( $lj_key, array() );
$lj_st = LongJob::handle( $lj_key, 'run' );
asem_ok( 'finished' === $lj_st['status'] && 'finished' === LongJob::handle( $lj_key, 'status' )['status'], 'lavori lunghi: la richiesta della pagina fa una tappa (qui basta una) e legge lo stato' );
wp_set_current_user( 0 );
$lj_denied = null;
try {
	LongJob::handle( $lj_key, 'status' );
} catch ( \RuntimeException $e ) {
	$lj_denied = $e->getMessage();
}
wp_set_current_user( 1 );
asem_ok( null !== $lj_denied && false !== strpos( $lj_denied, 'permesso' ), 'lavori lunghi: senza il permesso del lavoro la pagina non lo avanza né lo legge' );
asem_ok( null === LongJob::need( $lj_key ), 'lavori lunghi: un lavoro senza aree per i dipendenti decide solo con il suo permesso' );

// ---------- Pulizia ----------
asem_ok( LongJob::forget( $lj_key ) && null === LongJob::status( $lj_key ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'asem_job' ) . '%' ) ), 'lavori lunghi: tolto il lavoro concluso non resta nessuna opzione' );
wp_set_current_user( $lj_user );

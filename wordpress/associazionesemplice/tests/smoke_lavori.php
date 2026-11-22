<?php
/**
 * Lavori periodici (richiamato da tests/smoke.php e da tests/smoke_free.php): due soli eventi di WP-Cron, il giro esegue in fila i lavori dovuti
 * con un tempo massimo, salva l'ora dell'ultimo giro, e chi aggiorna passa dai vecchi eventi (uno per lavoro) senza perdere né anticipare nulla.
 * I gestori veri dei lavori si sostituiscono con una sonda per la durata della prova: qui si prova il giro, non i lavori (che hanno le loro prove).
 */

defined( 'ABSPATH' ) || exit;
$lv_ok = function_exists( 'free_ok' ) ? 'free_ok' : 'asem_ok';
$lv_J  = \AssociazioneSemplice\Jobs::class;
$lv_P  = \AssociazioneSemplice\Plugin::class;

$lv_jobs     = $lv_P::jobs();
$lv_periodic = array_filter(
	$lv_jobs,
	static function ( $j ) {
		return '' !== $j[0];
	}
);
$lv_ok( count( $lv_periodic ) >= 9 && isset( $lv_periodic['asem_send_reminders'], $lv_periodic['asem_release_holds'], $lv_periodic['asem_purge_audit'] ), 'lavori: elenco dei lavori periodici con i nomi di sempre (' . count( $lv_periodic ) . ')' );

// Due soli eventi: nessun lavoro periodico ha più un evento suo.
$lv_own = array();
foreach ( $lv_periodic as $lv_h => $lv_j ) {
	$lv_ev = wp_get_scheduled_event( $lv_h );
	if ( $lv_ev && ! empty( $lv_ev->schedule ) ) {
		$lv_own[] = $lv_h;
	}
	$lv_ok( (bool) has_action( $lv_h ), "lavori: $lv_h ha il suo gestore" );
}
$lv_ok( ! $lv_own && 'hourly' === wp_get_schedule( $lv_P::JOBS_HOURLY ) && 'daily' === wp_get_schedule( $lv_P::JOBS_DAILY ) && has_action( $lv_P::JOBS_HOURLY ) && has_action( $lv_P::JOBS_DAILY ), 'lavori: due soli eventi, ogni ora e ogni giorno' . ( $lv_own ? ' (con un evento proprio: ' . implode( ', ', $lv_own ) . ')' : '' ) );
$lv_ok( gmdate( 'Y-m-d' ) === (string) \AssociazioneSemplice\BootState::get( $lv_P::JOBS_CHECKED ) && $lv_J::scheduled( 'asem_purge_audit' ) && $lv_J::scheduled( 'asem_release_holds' ) && ! $lv_J::scheduled( 'asem_file_crypt_migrate' ), 'lavori: la programmazione si è controllata oggi; i lotti che si programmano da soli non sono nel giro' );

// Regole: quando un lavoro è dovuto.
$lv_now = 1800000000;
$lv_ok( $lv_J::due( 'hourly', 0, $lv_now, 'hourly' ) && $lv_J::due( 'hourly', $lv_now - 3600, $lv_now, 'hourly' ) && ! $lv_J::due( 'hourly', $lv_now - 1800, $lv_now, 'hourly' ) && ! $lv_J::due( 'hourly', 0, $lv_now, 'daily' ), 'lavori: un lavoro orario gira una volta l\'ora, solo nel giro orario' );
$lv_ok( $lv_J::due( 'daily', $lv_now - 86400, $lv_now, 'daily' ) && ! $lv_J::due( 'daily', $lv_now - 86400, $lv_now, 'hourly' ) && $lv_J::due( 'daily', $lv_now - 86400 - 3600, $lv_now, 'hourly' ) && ! $lv_J::due( 'daily', $lv_now - 3600, $lv_now, 'daily' ), 'lavori: un lavoro giornaliero gira nel giro giornaliero; se resta indietro di oltre un\'ora lo prende quello orario' );

// Il giro, con una sonda al posto dei gestori veri.
$lv_saved_filter = $GLOBALS['wp_filter'];
$lv_saved_state  = get_option( $lv_J::STATE, null );
$lv_seen         = array();
foreach ( array_keys( $lv_periodic ) as $lv_h ) {
	remove_all_actions( $lv_h );
	add_action(
		$lv_h,
		static function () use ( $lv_h, &$lv_seen ) {
			$lv_seen[] = $lv_h;
		}
	);
}
update_option( $lv_J::STATE, array( 'done' => array() ), false ); // mai eseguiti: tutti dovuti
$lv_ran_d = $lv_J::run( 'daily', microtime( true ) );
$lv_daily = array_keys( array_filter( $lv_periodic, static function ( $j ) { return 'daily' === $j[0]; } ) );
$lv_ok( $lv_ran_d === $lv_daily && $lv_seen === $lv_daily, 'lavori: il giro giornaliero esegue in fila tutti i lavori giornalieri dovuti, e solo quelli (' . count( $lv_ran_d ) . ')' );
$lv_last = $lv_J::last_run();
$lv_ok( $lv_last >= time() - 5 && $lv_last <= time(), 'lavori: l\'ora dell\'ultimo giro è salvata e si legge con Jobs::last_run()' );
$lv_seen  = array();
$lv_ran_h = $lv_J::run( 'hourly', microtime( true ) );
$lv_hour  = array_keys( array_filter( $lv_periodic, static function ( $j ) { return 'hourly' === $j[0]; } ) );
$lv_ok( $lv_ran_h === $lv_hour && $lv_seen === $lv_hour, 'lavori: il giro orario esegue in fila i lavori orari dovuti; i giornalieri appena fatti no (' . count( $lv_ran_h ) . ')' );
$lv_seen = array();
$lv_ok( array() === $lv_J::run( 'hourly', microtime( true ) ) && array() === $lv_J::run( 'daily', microtime( true ) ) && ! $lv_seen, 'lavori: subito dopo nessun lavoro è di nuovo dovuto' );

// Tempo finito: il giro non comincia altri lavori; chi resta lo prende il giro orario dopo.
update_option( $lv_J::STATE, array( 'done' => array() ), false );
$lv_seen = array();
$lv_ok( array() === $lv_J::run( 'daily', microtime( true ) - $lv_J::BUDGET - 1 ) && ! $lv_seen, 'lavori: a tempo finito il giro non comincia nessun lavoro' );
$lv_seen = array();
$lv_J::run( 'hourly', microtime( true ) );
$lv_ok( ! array_diff( array_keys( $lv_periodic ), $lv_seen ), 'lavori: i giornalieri rimasti indietro li esegue il giro orario successivo' );

// Un lavoro che si rompe non ferma gli altri.
$lv_first = array_keys( $lv_periodic )[0];
add_action(
	$lv_first,
	static function () {
		throw new \RuntimeException( 'prova' );
	},
	5
);
update_option( $lv_J::STATE, array( 'done' => array() ), false );
$lv_seen = array();
$lv_J::run( 'hourly', microtime( true ) );
$lv_st = (array) get_option( $lv_J::STATE );
$lv_ok( ! array_diff( array_keys( $lv_periodic ), array_merge( $lv_seen, array( $lv_first ) ) ) && isset( $lv_st['failed'][ $lv_first ], $lv_st['done'][ $lv_first ] ), 'lavori: un lavoro che si rompe è segnato e gli altri girano lo stesso' );

// Passaggio dai vecchi eventi: ognuno lascia la sua prossima scadenza al giro e sparisce; gli eventi singoli e i due del giro restano.
update_option( $lv_J::STATE, array( 'done' => array() ), false );
$lv_t1 = time() + 5000;
$lv_t2 = time() + 700;
wp_schedule_event( $lv_t1, 'daily', 'asem_purge_audit' );
wp_schedule_event( $lv_t2, 'hourly', 'asem_release_holds' );
wp_schedule_single_event( time() + 900, 'asem_send_reminders' ); // continuazione di un giro di promemoria: si lascia
wp_clear_scheduled_hook( $lv_P::JOBS_DAILY );
\AssociazioneSemplice\BootState::forget( $lv_P::JOBS_CHECKED );
$lv_J::ensure_scheduled();
$lv_st = (array) get_option( $lv_J::STATE );
$lv_ok( false === wp_next_scheduled( 'asem_purge_audit' ) && false === wp_next_scheduled( 'asem_release_holds' ), 'lavori: i vecchi eventi periodici dei singoli lavori si tolgono' );
$lv_ok( $lv_t1 - 86400 === (int) ( $lv_st['done']['asem_purge_audit'] ?? 0 ) && $lv_t2 - 3600 === (int) ( $lv_st['done']['asem_release_holds'] ?? 0 ), 'lavori: la loro prossima scadenza passa al giro (non si perdono né si anticipano)' );
$lv_ok( ! $lv_J::due( 'daily', (int) $lv_st['done']['asem_purge_audit'], time(), 'daily' ) && $lv_J::due( 'daily', (int) $lv_st['done']['asem_purge_audit'], $lv_t1, 'daily' ), 'lavori: il lavoro passato al giro è dovuto quando lo sarebbe stato il vecchio evento' );
$lv_ok( false !== wp_next_scheduled( 'asem_send_reminders' ) && false !== wp_next_scheduled( $lv_P::JOBS_DAILY ) && false !== wp_next_scheduled( $lv_P::JOBS_HOURLY ), 'lavori: gli eventi singoli restano; l\'evento del giro mancante si riprogramma' );
wp_clear_scheduled_hook( 'asem_send_reminders' );

// Alla disattivazione si tolgono anche i due eventi del giro, e alla riattivazione si riprogrammano subito.
$lv_ok( in_array( $lv_P::JOBS_HOURLY, \AssociazioneSemplice\Install::JOB_HOOKS, true ) && in_array( $lv_P::JOBS_DAILY, \AssociazioneSemplice\Install::JOB_HOOKS, true ), 'lavori: i due eventi sono tra quelli che la disattivazione toglie' );
wp_unschedule_hook( $lv_P::JOBS_HOURLY ); // come fa Install::clear_jobs() (qui non si chiama: toglierebbe anche i lotti delle prove che seguono)
wp_unschedule_hook( $lv_P::JOBS_DAILY );
\AssociazioneSemplice\BootState::forget( $lv_P::JOBS_CHECKED );
$lv_ok( false === wp_next_scheduled( $lv_P::JOBS_HOURLY ) && '' === (string) \AssociazioneSemplice\BootState::get( $lv_P::JOBS_CHECKED ), 'lavori: senza i due eventi e senza il segno del controllo' );
$lv_J::ensure_scheduled();
$lv_ok( false !== wp_next_scheduled( $lv_P::JOBS_HOURLY ) && false !== wp_next_scheduled( $lv_P::JOBS_DAILY ), 'lavori: riprogrammati' );

// Si torna come prima.
$GLOBALS['wp_filter'] = $lv_saved_filter;
if ( null === $lv_saved_state ) {
	delete_option( $lv_J::STATE );
} else {
	update_option( $lv_J::STATE, $lv_saved_state, false );
}

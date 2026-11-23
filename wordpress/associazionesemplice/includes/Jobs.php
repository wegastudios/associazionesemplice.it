<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Giro dei lavori periodici. Invece di un evento di WP-Cron per ogni lavoro (e di un controllo per ognuno a ogni richiesta del sito) ce ne sono due:
 * Plugin::JOBS_HOURLY, ogni ora, e Plugin::JOBS_DAILY, ogni giorno. Ogni giro esegue in fila i lavori dovuti, uno dopo l'altro, finché c'è tempo
 * (BUDGET secondi dall'inizio della richiesta: gli hosting piccoli fermano a 30). Ogni lavoro resta un'azione con il suo nome (asem_send_reminders,
 * asem_purge_audit…): il giro chiama do_action() e il gestore è quello di sempre. Chi resta indietro (tempo finito) gira al giro orario dopo.
 * L'ora dell'ultimo giro si salva ({@see last_run()}): dice se WP-Cron gira davvero.
 * La classe si carica solo nei giri e nel controllo giornaliero della programmazione.
 */
final class Jobs {

	/** Opzione (non caricata con le altre): ora dell'ultimo giro ('last') e dell'ultima esecuzione di ogni lavoro ('done'). */
	const STATE = 'asem_jobs_state';

	/** Secondi dall'inizio della richiesta dopo i quali non si comincia un altro lavoro. */
	const BUDGET = 20;

	/** Un lavoro è dovuto con qualche minuto di anticipo: WP-Cron non è puntuale (parte con le visite). */
	const EARLY = 300;

	/** Durata dei periodi. */
	const PERIODS = array(
		'hourly' => 3600,
		'daily'  => 86400,
	);

	public static function hourly(): void {
		self::run( 'hourly' );
	}

	public static function daily(): void {
		self::run( 'daily' );
	}

	/**
	 * Il lavoro va eseguito in questo giro? Nel giro orario: i lavori orari dovuti e quelli giornalieri rimasti indietro di oltre un'ora
	 * (giro giornaliero mancato o finito senza tempo); nel giro giornaliero: i lavori giornalieri dovuti. Mai eseguito = dovuto.
	 */
	public static function due( string $freq, int $done, int $now, string $round ): bool {
		if ( ! isset( self::PERIODS[ $freq ] ) ) {
			return false;
		}
		$at = $done + self::PERIODS[ $freq ] - self::EARLY;
		if ( 'daily' === $round ) {
			return 'daily' === $freq && $now >= $at;
		}
		return 'hourly' === $freq ? $now >= $at : $now >= $at + self::PERIODS['hourly'];
	}

	/**
	 * Esegue in fila i lavori dovuti di un giro. @param float|null $start inizio della richiesta (per il tempo massimo; null = quello vero)
	 * @return string[] i lavori eseguiti
	 */
	public static function run( string $round, ?float $start = null ): array {
		$start = $start ?? ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );
		$state = self::state();
		$now   = time();
		$state['last'] = $now;
		self::save( $state );
		$ran   = array();
		$ready = Plugin::schema_ready(); // con lo schema a metà gira solo il lavoro che lo porta avanti: gli altri scriverebbero colonne non ancora create e restano per il giro dopo
		foreach ( Plugin::jobs() as $hook => $j ) {
			if ( ! SchemaGuard::job_allowed( (string) $hook, $ready ) ) {
				continue;
			}
			if ( ! self::due( (string) $j[0], (int) ( $state['done'][ $hook ] ?? 0 ), $now, $round ) ) {
				continue;
			}
			if ( microtime( true ) - $start > self::BUDGET ) {
				break; // tempo finito: chi resta gira al prossimo giro orario
			}
			try {
				do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nomi dei lavori del plugin (asem_…), dall'elenco Plugin::jobs()
			} catch ( \Throwable $e ) {
				$state['failed'][ $hook ] = $now; // un lavoro che si rompe non ferma gli altri; si riprova al suo prossimo turno
			}
			$state['done'][ $hook ] = $now;
			self::save( $state ); // dopo ogni lavoro: se la richiesta si interrompe, quelli fatti restano fatti
			$ran[] = $hook;
		}
		return $ran;
	}

	/** Ora (timestamp) dell'ultimo giro dei lavori: 0 se non è mai partito. Se è vecchia di ore, WP-Cron sul sito non gira. */
	public static function last_run(): int {
		return (int) ( self::state()['last'] ?? 0 );
	}

	/** Il lavoro è programmato? (c'è nell'elenco dei lavori periodici e il suo evento, orario o giornaliero, è in WP-Cron) */
	public static function scheduled( string $hook ): bool {
		$j = Plugin::jobs()[ $hook ] ?? null;
		if ( ! $j || ! isset( self::PERIODS[ $j[0] ] ) ) {
			return false;
		}
		return false !== wp_next_scheduled( Plugin::JOBS_HOURLY ) && ( 'hourly' === $j[0] || false !== wp_next_scheduled( Plugin::JOBS_DAILY ) );
	}

	/**
	 * Una volta al giorno (e alla prima richiesta dopo l'aggiornamento): i due eventi ci sono; i vecchi eventi periodici dei singoli lavori
	 * si tolgono, e la loro prossima scadenza diventa quella del lavoro nel giro (nessun lavoro si perde né si anticipa).
	 * Gli eventi singoli (lotti che si programmano da soli, con o senza argomenti) non si toccano.
	 */
	public static function ensure_scheduled(): void {
		$state   = self::state();
		$changed = false;
		foreach ( Plugin::jobs() as $hook => $j ) {
			if ( ! isset( self::PERIODS[ $j[0] ] ) ) {
				continue;
			}
			for ( $i = 0; $i < 5; $i++ ) { // di solito uno solo; qualche sito può averne due dello stesso lavoro
				$ev = wp_get_scheduled_event( $hook );
				if ( ! $ev || empty( $ev->schedule ) ) {
					break; // nessuno, o un evento singolo: resta com'è
				}
				if ( ! isset( $state['done'][ $hook ] ) ) {
					$state['done'][ $hook ] = (int) $ev->timestamp - self::PERIODS[ $j[0] ]; // dovuto quando lo sarebbe stato il vecchio evento
				}
				wp_unschedule_event( (int) $ev->timestamp, $hook, (array) $ev->args );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save( $state );
		}
		if ( false === wp_next_scheduled( Plugin::JOBS_HOURLY ) ) {
			wp_schedule_event( time() + 600, 'hourly', Plugin::JOBS_HOURLY );
		}
		if ( false === wp_next_scheduled( Plugin::JOBS_DAILY ) ) {
			wp_schedule_event( time() + 1800, 'daily', Plugin::JOBS_DAILY );
		}
		BootState::set( Plugin::JOBS_CHECKED, gmdate( 'Y-m-d' ) );
	}

	/** @return array{last?:int,done?:array<string,int>,failed?:array<string,int>} */
	private static function state(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save( array $state ): void {
		if ( false === get_option( self::STATE, false ) ) {
			add_option( self::STATE, $state, '', false ); // non si carica con le altre opzioni: serve solo ai giri
			return;
		}
		update_option( self::STATE, $state, false );
	}
}

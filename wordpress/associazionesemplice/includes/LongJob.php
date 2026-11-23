<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Un solo motore per i lavori lunghi (copia di sicurezza, importazioni, ZIP delle ricevute, esportazioni grandi).
 *
 * Su un hosting con 30 secondi un lavoro lungo non sta in una richiesta: si fa a tappe. Ogni tappa dura al più {@see LongJob::BUDGET_SECONDS}
 * secondi (la metà del tempo massimo del server, se è poco) e si ferma salvando il punto raggiunto; la tappa dopo riparte da lì.
 * Non servono shell né un cron vero:
 *
 * - le tappe le chiede la pagina aperta (richieste ripetute dal browser con l'interfaccia di {@see LongJob::panel()});
 * - se la pagina si chiude, un evento singolo di WP-Cron (rete di sicurezza) fa una tappa e ne programma un'altra, finché il lavoro finisce;
 *   va bene anche il cron che parte solo con le visite: si va più piano, ma si arriva in fondo;
 * - due tappe insieme (pagina e cron, due schede, due amministratori) non succedono: un blocco con scadenza, una riga della tabella
 *   delle opzioni scritta con un solo comando; se una tappa muore senza liberarlo, dopo {@see LongJob::LOCK_SECONDS} secondi lo prende chi arriva;
 * - lo stato sta in un'opzione che non si carica a ogni richiesta del sito (autoload no), letta e scritta direttamente nel database
 *   (mai una copia vecchia dalla cache della richiesta), in JSON.
 *
 * API
 * ---
 * 1. Dichiarare il lavoro con il filtro `asem_long_jobs` (costa una funzione: questa classe si carica solo quando un lavoro parte o avanza):
 *
 *        add_filter( 'asem_long_jobs', function ( array $jobs ): array {
 *            $jobs['asem_receipts_zip'] = array(
 *                'step'   => array( ReceiptsZip::class, 'step' ),   // obbligatorio: function ( array $state, float $deadline ): array
 *                'cap'    => Plugin::CAP,                           // facoltativo: chi avanza, vede e annulla dalla pagina (predefinito asem_manage)
 *                'need'   => array( 'all' => array( EmployeeRules::RECEIPTS ) ), // facoltativo: aree che servono al dipendente (vedi EmployeeRules)
 *                'cancel' => array( ReceiptsZip::class, 'cancel' ), // facoltativo: function ( array $state ): void, pulizia se si annulla o si guasta
 *            );
 *            return $jobs;
 *        } );
 *
 * 2. Il passo. Riceve l'ultimo stato salvato e fa UN pezzo breve di lavoro (un blocco di righe, un file): pochi secondi, mai più di un minuto.
 *    Il secondo argomento, facoltativo, è il momento (microtime) in cui la tappa deve finire: serve a un passo che vuole regolarsi da sé.
 *    Restituisce il nuovo stato, un array qualsiasi che si salva in JSON (cursore, contatori, nomi di file), con tre chiavi lette dal motore:
 *        'done'     => int   pezzi fatti fin qui            'total' => int pezzi in tutto (0 = non si sa ancora)
 *        'finished' => bool  true quando il lavoro è finito; allora 'result' (array) è il risultato che legge la pagina: niente segreti.
 *    Lo stato si salva dopo ogni passo. Se la richiesta muore a metà di un passo (tempo, memoria) quel passo si rifà dall'ultimo stato
 *    salvato: rifarlo non deve raddoppiare nulla. Un'eccezione ferma il lavoro: stato «failed» con il messaggio, e si chiama 'cancel'.
 *
 * 3. Usarlo:
 *        LongJob::start( $key, $state )   avvia con lo stato iniziale (eccezione se è già in corso) e programma la rete di sicurezza;
 *        LongJob::panel( $key, $opts )    barra di avanzamento e «Annulla» per la pagina (title, done_url); vuoto se il lavoro non c'è;
 *        LongJob::status( $key )          null o array: status (running, finished, failed, cancelled), done, total, percent, message,
 *                                         error, result, started, updated, cancelling (annullamento chiesto, la tappa in corso finisce il passo);
 *        LongJob::run( $key, $budget )    una tappa (la usano la pagina e il cron; nelle prove: 0 = un passo solo, INF = fino alla fine);
 *        LongJob::cancel( $key )          annulla (subito, o alla fine del passo se una tappa è in corso);
 *        LongJob::forget( $key )          toglie un lavoro concluso, quando il risultato è stato consegnato;
 *        LongJob::state( $key )           l'ultimo stato salvato dal passo (per il lavoro stesso, mai per la pagina).
 *    Un lavoro concluso resta leggibile finché non lo si toglie o non lo si riavvia.
 *
 * Le tappe della pagina arrivano all'azione ajax `asem_long_job` (campi what = chiave, op = run | cancel | status, nonce): permesso del lavoro
 * ('cap') e, per i dipendenti, le aree ('need'). Il blocco con scadenza e il tempo di una tappa servono anche fuori dal motore
 * ({@see LongJob::acquire()}, {@see LongJob::server_budget()}): li usa l'importazione di {@see ImportJob}.
 */
final class LongJob {

	const HOOK           = 'asem_long_job_tick'; // evento singolo di WP-Cron, con la chiave del lavoro come argomento
	const NONCE          = 'asem_long_job';
	const OPT_PREFIX     = 'asem_job_';          // stato: asem_job_<chiave>
	const LOCK_PREFIX    = 'asem_joblock_';      // blocco: asem_joblock_<chiave>
	const STOP_PREFIX    = 'asem_jobstop_';      // annullamento chiesto: asem_jobstop_<chiave>
	const LOCK_SECONDS   = 120;                  // un blocco più vecchio di così è di una tappa morta (ogni passo lo rinnova)
	const BUDGET_SECONDS = 20;                   // tempo massimo di una tappa
	const CRON_DELAY     = 30;                   // la rete di sicurezza parte dopo questi secondi (con la pagina aperta di solito trova il lavoro già avanti)

	// ---------- Dichiarazione ----------

	/** La chiave di un lavoro: come sanitize_key(), al più 64 caratteri (il nome dell'opzione deve starci). */
	public static function clean_key( string $key ): string {
		return substr( strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ), 0, 64 );
	}

	/** @return array{step:callable,cap:string,need:?array,cancel:?callable}|null il lavoro dichiarato con questa chiave */
	public static function definition( string $key ): ?array {
		$key = self::clean_key( $key );
		if ( '' === $key ) {
			return null;
		}
		$all = (array) apply_filters( 'asem_long_jobs', array() );
		$d   = $all[ $key ] ?? null;
		if ( ! is_array( $d ) || ! isset( $d['step'] ) || ! is_callable( $d['step'] ) ) {
			return null;
		}
		return array(
			'step'   => $d['step'],
			'cap'    => (string) ( $d['cap'] ?? Plugin::CAP ),
			'need'   => isset( $d['need'] ) && is_array( $d['need'] ) ? $d['need'] : null,
			'cancel' => isset( $d['cancel'] ) && is_callable( $d['cancel'] ) ? $d['cancel'] : null,
		);
	}

	/** Le aree che servono al dipendente per avanzare il lavoro (null = nessuna area: decide solo il permesso del lavoro). */
	public static function need( string $key ): ?array {
		$d = self::definition( $key );
		return $d ? $d['need'] : null;
	}

	/** @throws \InvalidArgumentException */
	private static function require_definition( string $key ): array {
		$d = self::definition( $key );
		if ( null === $d ) {
			throw new \InvalidArgumentException( Texts::t( 'Questa operazione non esiste più: ricarica la pagina.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $d;
	}

	// ---------- Avvio, tappe, annullamento ----------

	/**
	 * Avvia il lavoro dallo stato iniziale. Un lavoro già concluso con la stessa chiave si sostituisce.
	 *
	 * @param array $state stato iniziale (si salva in JSON); il passo lo riceve alla prima tappa
	 * @return array lo stato leggibile (vedi status())
	 * @throws \InvalidArgumentException se il lavoro non esiste o è già in corso
	 */
	public static function start( string $key, array $state = array() ): array {
		$key = self::clean_key( $key );
		self::require_definition( $key );
		$lock = self::LOCK_PREFIX . $key;
		if ( ! self::acquire( $lock, self::LOCK_SECONDS ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Un\'altra operazione è in corso: riprova tra qualche secondo.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		try {
			$old = self::read( self::OPT_PREFIX . $key );
			if ( is_array( $old ) && 'running' === ( $old['status'] ?? '' ) ) {
				throw new \InvalidArgumentException( Texts::t( 'Questa operazione è già in corso: aspetta che finisca o annullala.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			self::delete( self::STOP_PREFIX . $key );
			$state = $state + array( 'done' => 0, 'total' => 0 );
			$state['finished'] = false;
			$rec   = array(
				'key'     => $key,
				'status'  => 'running',
				'state'   => $state,
				'done'    => max( 0, (int) $state['done'] ),
				'total'   => max( 0, (int) $state['total'] ),
				'error'   => '',
				'result'  => null,
				'user'    => get_current_user_id(),
				'started' => time(),
				'updated' => time(),
			);
			self::write( self::OPT_PREFIX . $key, $rec );
			self::schedule( $key );
			return self::view( $rec, false );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Una tappa: passi uno dopo l'altro finché c'è tempo (almeno uno), salvando lo stato dopo ognuno.
	 *
	 * @param float|null $budget secondi a disposizione (null = il solito, filtro `asem_long_job_budget_seconds`; 0 = un passo; INF = fino alla fine)
	 * @return array|null lo stato leggibile; null se il lavoro non c'è. Con un'altra tappa in corso: lo stato com'è, con busy = true
	 * @throws \InvalidArgumentException se il lavoro non è dichiarato
	 */
	public static function run( string $key, ?float $budget = null ): ?array {
		$key  = self::clean_key( $key );
		$def  = self::require_definition( $key );
		$lock = self::LOCK_PREFIX . $key;
		if ( ! self::acquire( $lock, self::LOCK_SECONDS ) ) {
			$st = self::status( $key );
			return null === $st ? null : array( 'busy' => true ) + $st;
		}
		try {
			$rec = self::read( self::OPT_PREFIX . $key );
			if ( null === $rec ) {
				return null;
			}
			if ( 'running' !== ( $rec['status'] ?? '' ) ) {
				return self::view( $rec, false );
			}
			if ( null === $budget ) {
				$budget = (float) apply_filters( 'asem_long_job_budget_seconds', self::server_budget(), $key );
			}
			$deadline = microtime( true ) + $budget;
			do {
				if ( self::stop_requested( $key ) ) {
					return self::view( self::close( $key, $def, $rec, 'cancelled', '' ), false );
				}
				try {
					$state = call_user_func( $def['step'], (array) $rec['state'], $deadline );
					if ( ! is_array( $state ) ) {
						throw new \UnexpectedValueException( 'Il passo del lavoro non ha restituito uno stato.' );
					}
				} catch ( \Throwable $e ) {
					return self::view( self::close( $key, $def, $rec, 'failed', $e->getMessage() ), false );
				}
				$rec = self::advance( $rec, $state );
				self::write( self::OPT_PREFIX . $key, $rec );
				self::beat( $lock );
			} while ( 'running' === $rec['status'] && microtime( true ) < $deadline );
			if ( 'running' === $rec['status'] ) {
				self::schedule( $key );
			} else {
				self::unschedule( $key );
			}
			return self::view( $rec, false );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Annulla il lavoro: subito se nessuna tappa è in corso, altrimenti la tappa in corso si ferma alla fine del passo.
	 *
	 * @return array|null lo stato leggibile (cancelling = true se l'annullamento aspetta la fine del passo); null se il lavoro non c'è
	 * @throws \InvalidArgumentException se il lavoro non è dichiarato
	 */
	public static function cancel( string $key ): ?array {
		$key = self::clean_key( $key );
		$def = self::require_definition( $key );
		$rec = self::read( self::OPT_PREFIX . $key );
		if ( null === $rec || 'running' !== ( $rec['status'] ?? '' ) ) {
			return null === $rec ? null : self::view( $rec, false );
		}
		self::flag( self::STOP_PREFIX . $key );
		$lock = self::LOCK_PREFIX . $key;
		if ( self::acquire( $lock, self::LOCK_SECONDS ) ) {
			try {
				$rec = self::read( self::OPT_PREFIX . $key );
				if ( is_array( $rec ) && 'running' === ( $rec['status'] ?? '' ) ) {
					self::close( $key, $def, $rec, 'cancelled', '' );
				}
			} finally {
				self::release( $lock );
			}
		}
		return self::status( $key );
	}

	/** Toglie un lavoro concluso (finito, guasto o annullato). @return bool false se è ancora in corso */
	public static function forget( string $key ): bool {
		$key = self::clean_key( $key );
		$rec = self::read( self::OPT_PREFIX . $key );
		if ( is_array( $rec ) && 'running' === ( $rec['status'] ?? '' ) ) {
			return false;
		}
		self::delete( self::OPT_PREFIX . $key );
		self::delete( self::STOP_PREFIX . $key );
		self::unschedule( $key );
		return true;
	}

	/** Rete di sicurezza (WP-Cron): una tappa; se resta lavoro, run() programma la prossima. */
	public static function tick( $key = '' ): void {
		$key = self::clean_key( (string) $key );
		if ( null === self::definition( $key ) ) {
			return; // il lavoro non c'è più in questa edizione: non si riprogramma
		}
		try {
			self::run( $key );
		} catch ( \Throwable $e ) {
			try {
				$st = self::status( $key );
				if ( $st && 'running' === $st['status'] ) {
					self::schedule( $key ); // il database non ha risposto: si riprova più tardi
				}
			} catch ( \Throwable $e2 ) {
				return;
			}
		}
	}

	// ---------- Stato leggibile ----------

	/** @return array|null status, done, total, percent, message, error, result, started, updated, cancelling, busy, key; null se il lavoro non c'è */
	public static function status( string $key ): ?array {
		$key = self::clean_key( $key );
		$rec = '' === $key ? null : self::read( self::OPT_PREFIX . $key );
		if ( null === $rec ) {
			return null;
		}
		return self::view( $rec, 'running' === ( $rec['status'] ?? '' ) && self::stop_requested( $key ) );
	}

	/**
	 * L'ultimo stato salvato dal passo (cursore, nomi di file): serve al lavoro stesso, per esempio a consegnare il file finito. Non va mai alla pagina:
	 * la pagina legge solo status().
	 *
	 * @return array|null null se il lavoro non c'è
	 */
	public static function state( string $key ): ?array {
		$key = self::clean_key( $key );
		$rec = '' === $key ? null : self::read( self::OPT_PREFIX . $key );
		return null === $rec ? null : (array) ( $rec['state'] ?? array() );
	}

	/** Percentuale intera da 0 a 100 (100 solo a lavoro finito, o se i pezzi fatti sono già tutti). */
	public static function percent( int $done, int $total, bool $finished ): int {
		if ( $finished ) {
			return 100;
		}
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) max( 0, min( 100, floor( $done * 100 / $total ) ) );
	}

	/** Quanto dura una tappa: 20 secondi, o la metà del tempo massimo del server se è poco (mai meno di 5). Senza limite (0): 20. */
	public static function server_budget( ?int $max_execution_time = null ): float {
		$max    = null === $max_execution_time ? (int) ini_get( 'max_execution_time' ) : $max_execution_time;
		$budget = (float) self::BUDGET_SECONDS;
		return $max > 0 ? min( $budget, max( 5.0, $max * 0.5 ) ) : $budget;
	}

	private static function view( array $rec, bool $cancelling ): array {
		$status = (string) ( $rec['status'] ?? 'running' );
		$done   = max( 0, (int) ( $rec['done'] ?? 0 ) );
		$total  = max( 0, (int) ( $rec['total'] ?? 0 ) );
		$v      = array(
			'key'        => (string) ( $rec['key'] ?? '' ),
			'status'     => $status,
			'done'       => $done,
			'total'      => $total,
			'percent'    => self::percent( $done, $total, 'finished' === $status ),
			'error'      => (string) ( $rec['error'] ?? '' ),
			'result'     => isset( $rec['result'] ) && is_array( $rec['result'] ) ? $rec['result'] : null,
			'started'    => (int) ( $rec['started'] ?? 0 ),
			'updated'    => (int) ( $rec['updated'] ?? 0 ),
			'cancelling' => $cancelling,
			'busy'       => false,
		);
		$v['message'] = self::message( $v );
		return $v;
	}

	/** La frase dello stato, per la pagina. */
	private static function message( array $v ): string {
		switch ( $v['status'] ) {
			case 'finished':
				return Texts::t( 'Operazione completata.' );
			case 'cancelled':
				return Texts::t( 'Operazione annullata.' );
			case 'failed':
				return Texts::t( 'L\'operazione si è fermata per un errore: {error}', array( 'error' => $v['error'] ) );
		}
		if ( $v['cancelling'] ) {
			return Texts::t( 'Annullamento richiesto: l\'operazione si ferma appena finisce il passaggio in corso.' );
		}
		if ( $v['total'] > 0 ) {
			return Texts::t( 'In corso: {done} di {total} ({percent}%).', array( 'done' => $v['done'], 'total' => $v['total'], 'percent' => $v['percent'] ) );
		}
		return Texts::t( 'In corso: preparazione.' );
	}

	// ---------- Interfaccia per la pagina ----------

	/**
	 * Barra di avanzamento con lo stato e il pulsante «Annulla». Con il lavoro in corso la pagina chiede le tappe una dopo l'altra (admin.js);
	 * a lavoro finito, se c'è done_url, ci va.
	 *
	 * @param array $opts title (testo), done_url (indirizzo dopo la fine)
	 * @return string HTML già protetto; vuoto se il lavoro non c'è
	 */
	public static function panel( string $key, array $opts = array() ): string {
		$key = self::clean_key( $key );
		$st  = self::status( $key );
		if ( null === $st ) {
			return '';
		}
		$run = 'running' === $st['status'];
		$h   = '<div class="asem-longjob" data-asem-job="' . esc_attr( $key ) . '" data-asem-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"'
			. ' data-asem-running="' . ( $run ? '1' : '0' ) . '" data-asem-done-url="' . esc_url( (string) ( $opts['done_url'] ?? '' ) ) . '"'
			. ' data-asem-retry="' . esc_attr( Texts::t( 'Connessione interrotta: nuovo tentativo tra qualche secondo.' ) ) . '"'
			. ' data-asem-confirm="' . esc_attr( Texts::t( 'Vuoi annullare questa operazione? Quello che non è ancora stato fatto non si farà.' ) ) . '">';
		if ( '' !== (string) ( $opts['title'] ?? '' ) ) {
			$h .= '<p><strong>' . esc_html( (string) $opts['title'] ) . '</strong></p>';
		}
		$h .= '<progress class="asem-longjob-bar" max="100" value="' . (int) $st['percent'] . '">' . (int) $st['percent'] . '%</progress>';
		$h .= '<p class="asem-longjob-msg" role="status" aria-live="polite">' . esc_html( $st['message'] ) . '</p>';
		if ( $run ) {
			$h .= '<p class="description">' . esc_html( Texts::t( 'Con questa pagina aperta l\'operazione va più veloce; se la chiudi continua da sola, più lentamente.' ) ) . '</p>';
			$h .= '<p><button type="button" class="button asem-longjob-cancel">' . esc_html( Texts::t( 'Annulla' ) ) . '</button></p>';
		}
		return $h . '</div>';
	}

	/**
	 * Una richiesta della pagina: permesso del lavoro, poi una tappa, l'annullamento o lo stato.
	 *
	 * @param string $op run | cancel | status
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function handle( string $key, string $op ): array {
		$key = self::clean_key( $key );
		$def = self::require_definition( $key );
		if ( ! current_user_can( $def['cap'] ) ) {
			throw new \RuntimeException( Texts::t( 'Non hai il permesso per questa operazione: chiedilo a un amministratore.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'cancel' === $op ) {
			$st = self::cancel( $key );
		} elseif ( 'status' === $op ) {
			$st = self::status( $key );
		} else {
			$st = self::run( $key );
		}
		if ( null === $st ) {
			throw new \InvalidArgumentException( Texts::t( 'Nessuna operazione in corso.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $st;
	}

	/** Azione ajax asem_long_job. */
	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$key = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'run';
		try {
			$st = self::handle( $key, $op );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage(), 400 );
			return;
		}
		wp_send_json_success( $st );
	}

	// ---------- Passi ----------

	/** Lo stato dopo un passo. */
	private static function advance( array $rec, array $state ): array {
		$state['done']  = max( 0, (int) ( $state['done'] ?? 0 ) );
		$state['total'] = max( 0, (int) ( $state['total'] ?? 0 ) );
		$finished       = ! empty( $state['finished'] );
		if ( $finished ) {
			$rec['result'] = isset( $state['result'] ) && is_array( $state['result'] ) ? $state['result'] : array();
			unset( $state['result'] );
			$rec['status'] = 'finished';
			$state['done'] = max( $state['done'], $state['total'] );
		}
		$rec['state']   = $state;
		$rec['done']    = $state['done'];
		$rec['total']   = $state['total'];
		$rec['updated'] = time();
		return $rec;
	}

	/** Chiude il lavoro annullato o guasto: pulizia del lavoro, stato definitivo, niente più tappe. */
	private static function close( string $key, array $def, array $rec, string $status, string $error ): array {
		if ( $def['cancel'] ) {
			try {
				call_user_func( $def['cancel'], (array) $rec['state'] );
			} catch ( \Throwable $e ) {
				$error = '' !== $error ? $error : $e->getMessage(); // la pulizia non riesce: il lavoro si chiude lo stesso
			}
		}
		$rec['status']  = $status;
		$rec['error']   = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 500 ) : substr( $error, 0, 500 );
		$rec['updated'] = time();
		self::write( self::OPT_PREFIX . $key, $rec );
		self::delete( self::STOP_PREFIX . $key );
		self::unschedule( $key );
		return $rec;
	}

	private static function stop_requested( string $key ): bool {
		$db = Db::db();
		return null !== $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', self::STOP_PREFIX . $key ) );
	}

	private static function schedule( string $key ): void {
		if ( false === wp_next_scheduled( self::HOOK, array( $key ) ) ) {
			wp_schedule_single_event( time() + self::CRON_DELAY, self::HOOK, array( $key ) );
		}
	}

	private static function unschedule( string $key ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $key ) );
	}

	// ---------- Opzioni lette e scritte nel database ----------

	/** @return array|null il valore JSON dell'opzione (letto dal database, non dalla cache della richiesta) */
	private static function read( string $name ): ?array {
		$db  = Db::db();
		$raw = $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', $name ) );
		$d   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $d ) ? $d : null;
	}

	/** @throws \RuntimeException se lo stato non si può salvare */
	private static function write( string $name, array $data ): void {
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Stato del lavoro non salvato: contiene dati non validi.' );
		}
		$db = Db::db();
		$ok = $db->query( $db->prepare( 'INSERT INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = %s", $name, $json, $json ) );
		wp_cache_delete( $name, 'options' );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Stato del lavoro non salvato: riprova.' );
		}
	}

	private static function flag( string $name ): void {
		$db = Db::db();
		$db->query( $db->prepare( 'INSERT IGNORE INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
		wp_cache_delete( $name, 'options' );
	}

	private static function delete( string $name ): void {
		$db = Db::db();
		$db->query( $db->prepare( 'DELETE FROM ' . $db->options . ' WHERE option_name = %s', $name ) );
		wp_cache_delete( $name, 'options' );
	}

	// ---------- Blocco con scadenza (anche per ImportJob) ----------

	/**
	 * Prende il blocco $name: una riga della tabella delle opzioni scritta con un solo comando (nessuno può prenderla due volte).
	 * Un blocco più vecchio di $ttl secondi è di una tappa morta: lo prende uno solo di quelli che arrivano.
	 */
	public static function acquire( string $name, int $ttl ): bool {
		$db  = Db::db();
		$now = time();
		if ( 1 === (int) $db->query( $db->prepare( 'INSERT IGNORE INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) ) ) {
			return true;
		}
		$old = (string) $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', $name ) );
		if ( '' !== $old && $now - (int) $old <= $ttl ) {
			return false;
		}
		// scaduto (o appena liberato): lo prende uno solo, quello per cui la riga è ancora com'era
		$won = '' === $old
			? (int) $db->query( $db->prepare( 'INSERT IGNORE INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) )
			: (int) $db->query( $db->prepare( 'UPDATE ' . $db->options . ' SET option_value = %s WHERE option_name = %s AND option_value = %s', (string) $now, $name, $old ) );
		return 1 === $won;
	}

	/** La tappa è ancora viva: il blocco si rinnova. */
	public static function beat( string $name ): void {
		$db = Db::db();
		$db->query( $db->prepare( 'UPDATE ' . $db->options . ' SET option_value = %s WHERE option_name = %s', (string) time(), $name ) );
	}

	public static function release( string $name ): void {
		$db = Db::db();
		$db->query( $db->prepare( 'DELETE FROM ' . $db->options . ' WHERE option_name = %s', $name ) );
	}
}

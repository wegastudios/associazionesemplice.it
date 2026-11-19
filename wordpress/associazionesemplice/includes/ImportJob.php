<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Importazione di soci, ospiti e prima nota a giri, con ripresa.
 *
 * Un file grande non si scrive in una sola richiesta: si lavora a blocchi di {@see ImportJob::BLOCK} righe e, quando il tempo di un giro è finito,
 * ci si ferma e si riprende dal punto salvato. Il risultato è identico a quello di un'unica richiesta: stesse righe, stesso ordine
 * (prima i soci, poi gli ospiti, poi i tutori), stessi errori e avvisi, stessa traccia per l'annullo.
 *
 * - Lo stato (punto raggiunto, contatori, righe non riuscite, tutori da collegare) e la traccia per l'annullo stanno insieme nella riga
 *   dell'import: una sola scrittura per blocco, quindi mai l'uno senza l'altra. L'annullo copre sempre tutto quello che è stato importato fino a lì.
 * - Un solo giro per volta in tutto il sito (blocco sul database, che cade da solo se il giro muore).
 * - Se un giro muore a metà di un blocco (tempo, errore, memoria), resta il segno del blocco in corso: alla ripresa quel blocco si rifà
 *   riconoscendo le schede già scritte (non se ne crea una seconda) e usando le schede com'erano prima, lette prima del blocco.
 * - Nulla parte da solo: si avvia con «Importa» e si riprende con «Riprendi l'importazione».
 */
final class ImportJob {

	const OPT_JOB        = 'asem_import_job';      // quale import è in corso e a che punto è (piccolo: lo legge la pagina)
	const OPT_PLAN       = 'asem_import_plan';     // il piano dell'anteprima, scritto una volta all'avvio
	const OPT_FLIGHT     = 'asem_import_inflight'; // il blocco che si sta scrivendo
	const OPT_LOCK       = 'asem_import_lock';     // un solo giro per volta: un blocco con scadenza
	const LOCK_SECONDS   = 120;    // un blocco più vecchio di così è di un giro morto (ogni blocco di righe lo rinnova)
	const BLOCK          = 200;     // righe per blocco
	const BUDGET_SECONDS = 20;      // tempo massimo di un giro
	const STALE_SECONDS  = 604800;  // un import lasciato a metà per una settimana si chiude da solo (quello che c'è resta annullabile)

	// ---------- Avvio e giri ----------

	/**
	 * Apre un import dall'anteprima: nulla viene ancora scritto nell'anagrafica. Il primo giro lo fa {@see step()}.
	 *
	 * @param array $preview anteprima di ImportService::preview_sheets()
	 * @param array $opts    mark_members (bool), keep_balances (bool), source (testo)
	 * @return int numero dell'import
	 * @throws \InvalidArgumentException
	 */
	public static function start( array $preview, array $opts = array() ): int {
		ImportService::assert_can_apply( $preview, $opts );
		return (int) self::locked(
			function () use ( $preview, $opts ) {
				if ( null !== self::current( true ) ) {
					throw new \InvalidArgumentException( Texts::t( 'Un\'importazione è già in corso: riprendila o fermala prima di caricare un altro file.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$has_people = ! empty( $preview['people'] );
				$has_ledger = ! empty( $preview['ledger'] );
				$job        = array(
					'stage'          => $has_people ? 'people' : ( $has_ledger ? 'ledger' : 'done' ),
					'seq'            => $has_people ? count( ImportService::people_sequence( $preview['people']['plan'] ) ) : 0,
					'cursor'         => 0,
					'lcursor'        => 0,
					'links'          => array(),
					'created'        => 0,
					'updated'        => 0,
					'failed'         => array(),
					'mark'           => ! empty( $opts['mark_members'] ),
					'keep'           => ! empty( $opts['keep_balances'] ),
					'has_people'     => $has_people,
					'has_ledger'     => $has_ledger,
					'ledger_started' => false,
					'res_people'     => null,
					'res_ledger'     => null,
				);
				$source = (string) ( $opts['source'] ?? $preview['file'] ?? 'Import' );
				$batch  = ImportService::open_batch( $source );
				try {
					self::write_state( $batch, $job, ImportService::empty_track() );
					update_option( self::OPT_PLAN, $preview, false );
					update_option( self::OPT_JOB, array( 'batch' => $batch, 'source' => $source, 'user' => get_current_user_id(), 'started' => Db::now(), 'ts' => time() ) + self::numbers( $job ) + array( 'stage' => $job['stage'] ), false );
				} catch ( \Throwable $e ) {
					self::forget();
					Db::db()->delete( Db::t( 'import_batches' ), array( 'id' => $batch ) );
					throw $e;
				}
				return $batch;
			}
		);
	}

	/**
	 * Un giro: va avanti finché c'è tempo, poi salva il punto e si ferma.
	 *
	 * @param float|null $budget secondi a disposizione (null = il solito; INF = fino alla fine)
	 * @return array{finished:bool,stage:string,done:int,total:int,result:?array} result (soci, prima nota, numero dell'import) solo a lavoro finito
	 * @throws \InvalidArgumentException con un altro giro in corso o senza importazione da riprendere
	 */
	public static function step( ?float $budget = null ): array {
		return self::locked(
			function () use ( $budget ) {
				return self::run( $budget );
			}
		);
	}

	/**
	 * Ferma l'importazione qui: quello che è già entrato resta (e si annulla in blocco dall'elenco degli import), il resto non viene importato.
	 *
	 * @return array risultato fino a qui, come quello di un'importazione finita
	 * @throws \InvalidArgumentException
	 */
	public static function stop(): array {
		return self::locked(
			function () {
				return self::stop_now();
			}
		);
	}

	/**
	 * L'importazione in corso, se c'è: import, fase e avanzamento. Chiude da sola quella lasciata a metà da troppo tempo
	 * e toglie il segno di una il cui import non esiste più.
	 *
	 * @param bool $locked già dentro il blocco di un giro
	 * @return array|null batch, source, user, started, stage, done, total
	 */
	public static function current( bool $locked = false ): ?array {
		$ptr = get_option( self::OPT_JOB, null );
		if ( ! is_array( $ptr ) || empty( $ptr['batch'] ) ) {
			return null;
		}
		$db = Db::db();
		if ( ! (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'import_batches' ) . ' WHERE id = %d', (int) $ptr['batch'] ) ) ) {
			self::forget();
			return null;
		}
		if ( time() - (int) ( $ptr['ts'] ?? 0 ) > self::STALE_SECONDS ) {
			try {
				if ( $locked ) {
					self::stop_now();
				} else {
					self::stop();
				}
			} catch ( \Throwable $e ) {
				return $ptr; // un altro giro è in corso proprio adesso
			}
			return null;
		}
		return $ptr;
	}

	// ---------- Un giro per volta ----------

	/**
	 * Esegue $fn con il blocco preso: due giri insieme (due schede del browser, un secondo amministratore, WP All Import) non scrivono le stesse righe.
	 * Il blocco è una riga della tabella delle opzioni scritta con un solo comando (nessuno può prenderla due volte) e scade da sola: se un giro muore
	 * senza liberarlo, dopo due minuti chi arriva lo prende.
	 *
	 * @return mixed
	 * @throws \InvalidArgumentException se un altro giro è in corso
	 */
	private static function locked( callable $fn ) {
		if ( ! self::lock() ) {
			throw new \InvalidArgumentException( Texts::t( 'Un\'altra operazione è in corso: riprova tra qualche secondo.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		try {
			return $fn();
		} finally {
			self::unlock();
		}
	}

	private static function lock(): bool {
		$db  = Db::db();
		$now = time();
		if ( 1 === (int) $db->query( $db->prepare( 'INSERT IGNORE INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPT_LOCK, (string) $now ) ) ) {
			return true;
		}
		$old = (string) $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', self::OPT_LOCK ) );
		if ( '' !== $old && $now - (int) $old <= self::LOCK_SECONDS ) {
			return false;
		}
		// scaduto (o appena liberato): lo prende uno solo, quello per cui la riga è ancora com'era
		$won = '' === $old
			? (int) $db->query( $db->prepare( 'INSERT IGNORE INTO ' . $db->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPT_LOCK, (string) $now ) )
			: (int) $db->query( $db->prepare( 'UPDATE ' . $db->options . ' SET option_value = %s WHERE option_name = %s AND option_value = %s', (string) $now, self::OPT_LOCK, $old ) );
		return 1 === $won;
	}

	/** Il giro è ancora vivo: il blocco si rinnova. */
	private static function beat(): void {
		$db = Db::db();
		$db->query( $db->prepare( 'UPDATE ' . $db->options . ' SET option_value = %s WHERE option_name = %s', (string) time(), self::OPT_LOCK ) );
	}

	private static function unlock(): void {
		$db = Db::db();
		$db->query( $db->prepare( 'DELETE FROM ' . $db->options . ' WHERE option_name = %s', self::OPT_LOCK ) );
	}

	/** Questo import è quello in corso adesso (ancora a giri, non finito)? Finché lo è non compare tra i già fatti e non si annulla. */
	public static function is_running( int $batch ): bool {
		$ptr = get_option( self::OPT_JOB, null );
		return is_array( $ptr ) && (int) ( $ptr['batch'] ?? 0 ) === $batch;
	}

	// ---------- Giro ----------

	private static function run( ?float $budget ): array {
		$ptr = get_option( self::OPT_JOB, null );
		if ( ! is_array( $ptr ) || empty( $ptr['batch'] ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Nessuna importazione in corso.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$batch = (int) $ptr['batch'];
		$state = self::load( $batch );
		if ( null === $state ) {
			self::forget();
			throw new \InvalidArgumentException( Texts::t( 'Nessuna importazione in corso.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		list( $job, $track ) = $state;
		$plan = get_option( self::OPT_PLAN, null );
		if ( ! is_array( $plan ) ) {
			self::finish( $batch, $job, $track ); // il piano non c'è più: si chiude con quello che è già entrato
			throw new \InvalidArgumentException( 'L\'anteprima è scaduta: carica di nuovo il file.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( null === $budget ) {
			$budget = (float) apply_filters( 'asem_import_budget_seconds', self::default_budget() );
		}
		$deadline = microtime( true ) + $budget;
		$more     = false;
		ImportService::reset_host_index();
		try {
			if ( 'people' === $job['stage'] ) {
				$more = self::run_people( $batch, $plan, $job, $track, $deadline );
			}
			if ( ! $more && 'links' === $job['stage'] ) {
				$more = self::run_links( $batch, $job, $track, $deadline );
			}
			if ( ! $more && 'ledger' === $job['stage'] ) {
				self::run_ledger( $batch, $plan, $job, $track );
			}
		} finally {
			ImportService::reset_host_index();
		}
		if ( $more ) {
			$n = self::numbers( $job );
			return array( 'finished' => false, 'stage' => (string) $job['stage'], 'done' => $n['done'], 'total' => $n['total'], 'result' => null );
		}
		$n = self::numbers( $job );
		return array( 'finished' => true, 'stage' => 'done', 'done' => $n['done'], 'total' => $n['total'], 'result' => self::finish( $batch, $job, $track ) );
	}

	/** Soci e ospiti, a blocchi. @return bool true se il tempo è finito e resta lavoro */
	private static function run_people( int $batch, array $plan, array &$job, array &$track, float $deadline ): bool {
		$rows   = $plan['people']['plan'];
		$seq    = ImportService::people_sequence( $rows );
		$total  = count( $seq );
		$people = Plugin::people();
		$ctx    = array( 'mark' => ! empty( $job['mark'] ), 'year' => Settings::membership_year()->label(), 'pre' => array(), 'touched' => array(), 'adopt' => 0, 'since' => 0 );
		$flight = self::flight( $batch, 'people', (int) $job['cursor'] );
		while ( (int) $job['cursor'] < $total ) {
			$from = (int) $job['cursor'];
			if ( $flight ) { // un giro è morto in questo blocco: si rifà lo stesso intervallo, senza ripetere quello che c'è già
				$to    = min( $total, (int) $flight['to'] );
				$redo  = $flight;
				$block = $flight;
				$flight = null;
			} else {
				$to    = min( $total, $from + self::BLOCK );
				$redo  = null;
				$ids   = array();
				for ( $i = $from; $i < $to; $i++ ) {
					if ( 'create' !== $rows[ $seq[ $i ][0] ]['action'] ) {
						$ids[] = (int) $rows[ $seq[ $i ][0] ]['matched_id'];
					}
				}
				$pre = array();
				foreach ( $people->get_many( $ids ) as $id => $b ) { // le schede com'erano prima del blocco (per l'annullo)
					$pre[ (int) $id ] = array_intersect_key( $b, array_flip( ImportService::BEFORE_FIELDS ) );
				}
				$block = array( 'batch' => $batch, 'stage' => 'people', 'from' => $from, 'to' => $to, 'at' => Db::now(), 'ts' => time(), 'before' => $pre );
				update_option( self::OPT_FLIGHT, $block, false );
			}
			$ctx['pre']     = (array) ( $block['before'] ?? array() );
			$ctx['since']   = (int) $block['ts'];
			$ctx['touched'] = array();
			$cands          = $redo ? self::candidates( (string) $redo['at'], $track ) : array();
			$snap           = null;
			try {
				for ( $i = $from; $i < $to; $i++ ) {
					$snap         = array( (int) $job['created'], (int) $job['updated'], count( $job['failed'] ), count( $job['links'] ), count( $track['people_created'] ) );
					$row          = $rows[ $seq[ $i ][0] ];
					$ctx['adopt'] = $redo && 'create' === $row['action'] ? self::take_candidate( $row, $cands ) : 0;
					ImportService::apply_person_row( $row, (int) $seq[ $i ][1], $job, $track, $ctx );
					do_action( 'asem_import_after_row', $i ); // per le prove: simula un giro che muore dopo aver scritto la riga e prima di averla registrata
					$job['cursor'] = $i + 1;
					$snap          = null;
					if ( $i + 1 < $to && microtime( true ) >= $deadline ) {
						break;
					}
				}
			} catch ( \Throwable $e ) {
				self::interrupted( $batch, 'people', $job, $track, $block, $snap );
				throw $e;
			}
			self::save( $batch, $job, $track );
			delete_option( self::OPT_FLIGHT );
			if ( (int) $job['cursor'] < $total && microtime( true ) >= $deadline ) {
				return true;
			}
		}
		$job['stage'] = 'links';
		self::save( $batch, $job, $track );
		return false;
	}

	/** I tutori dei minori, a blocchi (si cercano alla fine: possono essere nello stesso file). @return bool true se il tempo è finito e resta lavoro */
	private static function run_links( int $batch, array &$job, array &$track, float $deadline ): bool {
		$links  = (array) $job['links'];
		$total  = count( $links );
		$flight = self::flight( $batch, 'links', (int) $job['lcursor'] );
		while ( (int) $job['lcursor'] < $total ) {
			$from = (int) $job['lcursor'];
			if ( $flight ) {
				$to     = min( $total, (int) $flight['to'] );
				$since  = (string) $flight['at'];
				$block  = $flight;
				$flight = null;
			} else {
				$to    = min( $total, $from + self::BLOCK );
				$since = '';
				$block = array( 'batch' => $batch, 'stage' => 'links', 'from' => $from, 'to' => $to, 'at' => Db::now(), 'ts' => time(), 'before' => array() );
				update_option( self::OPT_FLIGHT, $block, false );
			}
			$snap = null;
			try {
				for ( $i = $from; $i < $to; $i++ ) {
					$snap = array( (int) $job['created'], (int) $job['updated'], count( $job['failed'] ), count( $job['links'] ) );
					ImportService::apply_guardian_link( $links[ $i ], $job, $track, $since );
					$job['lcursor'] = $i + 1;
					$snap           = null;
					if ( $i + 1 < $to && microtime( true ) >= $deadline ) {
						break;
					}
				}
			} catch ( \Throwable $e ) {
				self::interrupted( $batch, 'links', $job, $track, $block, $snap );
				throw $e;
			}
			self::save( $batch, $job, $track );
			delete_option( self::OPT_FLIGHT );
			if ( (int) $job['lcursor'] < $total && microtime( true ) >= $deadline ) {
				return true;
			}
		}
		Audit::log( 'import.applied', 'people', null, array( 'created' => (int) $job['created'], 'updated' => (int) $job['updated'], 'failed' => count( $job['failed'] ) ) );
		$job['res_people'] = array( 'created' => (int) $job['created'], 'updated' => (int) $job['updated'], 'failed' => $job['failed'] );
		$job['stage']      = ! empty( $job['has_ledger'] ) ? 'ledger' : 'done';
		self::save( $batch, $job, $track );
		return false;
	}

	/**
	 * La prima nota si scrive tutta in una volta, in un'unica transazione (tutto o niente). Se un giro muore proprio qui non si rifà da capo:
	 * i movimenti potrebbero esserci già, e rifarli li raddoppierebbe. L'import si chiude con quello che c'è e si può annullare in blocco.
	 */
	private static function run_ledger( int $batch, array $plan, array &$job, array &$track ): void {
		if ( ! empty( $job['ledger_started'] ) ) {
			$job['ledger_interrupted'] = true;
			$job['stage']              = 'done';
			self::save( $batch, $job, $track );
			return;
		}
		$job['ledger_started'] = true;
		self::save( $batch, $job, $track );
		try {
			$job['res_ledger'] = ImportService::apply_ledger( $plan['ledger']['plan'], ! empty( $job['keep'] ), $batch, $track );
		} catch ( \Throwable $e ) {
			$job['stage'] = 'done';
			self::finish( $batch, $job, $track ); // come sempre: quello che è stato scritto si può annullare
			throw $e;
		}
		$job['stage'] = 'done';
		self::save( $batch, $job, $track );
	}

	private static function stop_now(): array {
		$ptr = get_option( self::OPT_JOB, null );
		if ( ! is_array( $ptr ) || empty( $ptr['batch'] ) ) {
			throw new \InvalidArgumentException( Texts::t( 'Nessuna importazione in corso.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$batch = (int) $ptr['batch'];
		$state = self::load( $batch );
		if ( null === $state ) {
			self::forget();
			throw new \InvalidArgumentException( Texts::t( 'Nessuna importazione in corso.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		list( $job, $track ) = $state;
		$flight = self::flight( $batch, 'people', (int) $job['cursor'] );
		$plan   = get_option( self::OPT_PLAN, null );
		if ( $flight && 'people' === $job['stage'] && is_array( $plan ) ) { // schede scritte da un giro interrotto: entrano nella traccia, così l'annullo le copre
			$rows  = $plan['people']['plan'];
			$seq   = ImportService::people_sequence( $rows );
			$cands = self::candidates( (string) $flight['at'], $track );
			$ctx   = array( 'since' => (int) $flight['ts'] );
			for ( $i = (int) $flight['from']; $i < min( count( $seq ), (int) $flight['to'] ); $i++ ) {
				$row = $rows[ $seq[ $i ][0] ];
				$id  = 'create' === $row['action'] ? self::take_candidate( $row, $cands ) : 0;
				if ( $id ) {
					$track['people_created'][] = $id;
					$p                         = Plugin::people()->get( $id );
					if ( $p && ! empty( $p['wp_user_id'] ) && self::user_is_new( (int) $p['wp_user_id'], (int) $ctx['since'] ) ) {
						$track['wp_users_created'][ $id ] = (int) $p['wp_user_id'];
					}
				}
			}
		}
		$job['stopped'] = true;
		return self::finish( $batch, $job, $track );
	}

	// ---------- Ripresa di un blocco interrotto ----------

	/** Il segno del blocco in corso, se è proprio quello che parte dal punto salvato (altrimenti è di un blocco già concluso). */
	private static function flight( int $batch, string $stage, int $cursor ): ?array {
		$f = get_option( self::OPT_FLIGHT, null );
		return is_array( $f ) && (int) ( $f['batch'] ?? 0 ) === $batch && (string) ( $f['stage'] ?? '' ) === $stage && (int) ( $f['from'] ?? -1 ) === $cursor && (int) ( $f['to'] ?? 0 ) > $cursor ? $f : null;
	}

	/** Una riga ha dato un errore a metà: si salva quello che è andato a buon fine e si lascia il segno della sola riga da rifare. */
	private static function interrupted( int $batch, string $stage, array $job, array $track, array $block, ?array $snap ): void {
		try {
			if ( $snap ) { // contatori com'erano prima della riga: alla ripresa si rifà e si conta una volta sola
				$job['created'] = $snap[0];
				$job['updated'] = $snap[1];
				$job['failed']  = array_slice( (array) $job['failed'], 0, $snap[2] );
				$job['links']   = array_slice( (array) $job['links'], 0, $snap[3] );
				if ( isset( $snap[4] ) && count( $track['people_created'] ) > $snap[4] ) { // la scheda appena creata si riconosce alla ripresa (si adotta), non va già nella traccia
					foreach ( array_slice( $track['people_created'], $snap[4] ) as $gone ) {
						unset( $track['wp_users_created'][ $gone ] );
					}
					$track['people_created'] = array_slice( $track['people_created'], 0, $snap[4] );
					$track['memberships']    = array_values( array_filter( (array) $track['memberships'], function ( $m ) use ( $gone ) {
						return (int) $m[0] !== (int) $gone;
					} ) );
				}
			}
			self::save( $batch, $job, $track );
			$cursor = 'people' === $stage ? (int) $job['cursor'] : (int) $job['lcursor'];
			update_option( self::OPT_FLIGHT, array( 'from' => $cursor, 'to' => $cursor + 1 ) + $block, false );
		} catch ( \Throwable $e ) {
			return; // il database non risponde: resta il segno del blocco, che basta a riprendere
		}
	}

	/**
	 * Le schede nate dopo l'inizio di un blocco interrotto e non ancora nella traccia: sono di quel blocco.
	 *
	 * @return array[] id, tipo, nome, cognome, email, tessera
	 */
	private static function candidates( string $at, array $track ): array {
		$db   = Db::db();
		$have = array_map( 'intval', (array) $track['people_created'] );
		$out  = array();
		foreach ( $db->get_results( $db->prepare( 'SELECT id, type, first_name, last_name, email, card_number FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND created_at >= %s ORDER BY id', $at ), ARRAY_A ) ?: array() as $c ) {
			if ( ! in_array( (int) $c['id'], $have, true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** Se una riga da creare ha già la sua scheda, scritta da un giro interrotto, la dà (e la toglie dall'elenco); altrimenti 0. */
	private static function take_candidate( array $row, array &$cands ): int {
		$r = $row['row'];
		foreach ( array( true, false ) as $strict ) { // prima con la tessera, poi senza (la tessera potrebbe essere stata scritta in un'altra forma)
			foreach ( $cands as $k => $c ) {
				if ( (string) $c['type'] !== (string) $row['type'] || Text::normalize( (string) $c['first_name'] ) !== Text::normalize( (string) $r['first'] ) || Text::normalize( (string) $c['last_name'] ) !== Text::normalize( (string) $r['last'] ) ) {
					continue;
				}
				if ( null !== $r['email'] && Text::lower( (string) $c['email'] ) !== Text::lower( (string) $r['email'] ) ) {
					continue;
				}
				if ( $strict && ! empty( $r['card'] ) && Text::lower( (string) $c['card_number'] ) !== Text::lower( (string) $r['card'] ) ) {
					continue;
				}
				unset( $cands[ $k ] );
				return (int) $c['id'];
			}
		}
		return 0;
	}

	private static function user_is_new( int $user_id, int $since ): bool {
		$u = get_userdata( $user_id );
		return $u && $since > 0 && strtotime( (string) $u->user_registered . ' UTC' ) >= $since - 2;
	}

	// ---------- Stato ----------

	/** @return array{0:array,1:array}|null lo stato del lavoro e la traccia per l'annullo; null se l'import non c'è o non è a giri */
	private static function load( int $batch ): ?array {
		$db  = Db::db();
		$raw = $db->get_var( $db->prepare( 'SELECT data FROM ' . Db::t( 'import_batches' ) . ' WHERE id = %d', $batch ) );
		$d   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $d ) || ! isset( $d['job'] ) || ! is_array( $d['job'] ) ) {
			return null;
		}
		$job = $d['job'];
		unset( $d['job'] );
		return array( $job, array_merge( ImportService::empty_track(), $d ) );
	}

	/** Scrive in un colpo solo lo stato e la traccia. @throws \RuntimeException se il database rifiuta la scrittura */
	private static function write_state( int $batch, array $job, array $track ): void {
		$json = wp_json_encode( $track + array( 'job' => $job ) );
		if ( ! is_string( $json ) || false === Db::db()->update( Db::t( 'import_batches' ), array( 'data' => $json ), array( 'id' => $batch ) ) ) {
			throw new \RuntimeException( 'Stato dell\'importazione non salvato: riprova.' );
		}
	}

	private static function save( int $batch, array $job, array $track ): void {
		self::write_state( $batch, $job, $track );
		self::beat();
		$ptr = get_option( self::OPT_JOB, null );
		if ( is_array( $ptr ) ) {
			update_option( self::OPT_JOB, array( 'ts' => time(), 'stage' => (string) $job['stage'] ) + self::numbers( $job ) + $ptr, false );
		}
	}

	/** @return array{done:int,total:int} righe controllate e righe da fare: prima soci e ospiti, poi i tutori */
	private static function numbers( array $job ): array {
		$seq = (int) $job['seq'];
		if ( 'people' === $job['stage'] ) {
			return array( 'done' => (int) $job['cursor'], 'total' => $seq );
		}
		if ( 'links' === $job['stage'] ) {
			return array( 'done' => $seq + (int) $job['lcursor'], 'total' => $seq + count( (array) $job['links'] ) );
		}
		$total = $seq + count( (array) $job['links'] );
		return array( 'done' => $total, 'total' => $total );
	}

	/** Chiude l'import: riepilogo e traccia definitivi, e toglie ogni segno del lavoro in corso. Se non è cambiato nulla l'import sparisce dall'elenco. */
	private static function finish( int $batch, array $job, array $track ): array {
		$people = $job['res_people'] ?? null;
		if ( ! $people && ! empty( $job['has_people'] ) && ( ! empty( $job['stopped'] ) || ! empty( $job['cursor'] ) ) ) {
			$people = array( 'created' => (int) $job['created'], 'updated' => (int) $job['updated'], 'failed' => (array) $job['failed'] ); // fermato prima della fine
		}
		$res = array( 'people' => $people, 'ledger' => $job['res_ledger'] ?? null, 'batch_id' => null );
		if ( ! empty( $job['ledger_interrupted'] ) ) {
			$res['interrupted'] = true;
		}
		if ( ! empty( $job['stopped'] ) ) {
			$res['stopped'] = true;
		}
		ImportService::close_batch( $batch, $res, $track );
		$changed         = ( $res['people']['created'] ?? 0 ) + ( $res['people']['updated'] ?? 0 ) + ( $res['ledger']['created'] ?? 0 ) + ( $res['ledger']['transfers'] ?? 0 );
		$res['batch_id'] = $changed > 0 ? $batch : null;
		if ( ! $changed ) {
			Db::db()->delete( Db::t( 'import_batches' ), array( 'id' => $batch ) );
		}
		self::forget();
		return $res;
	}

	private static function forget(): void {
		delete_option( self::OPT_JOB );
		delete_option( self::OPT_PLAN );
		delete_option( self::OPT_FLIGHT );
	}

	/** Quanto dura un giro: 20 secondi, o meno se il server ne concede pochi. */
	private static function default_budget(): float {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = (float) self::BUDGET_SECONDS;
		return $max > 0 ? min( $budget, max( 5.0, $max * 0.5 ) ) : $budget;
	}
}

<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Numero limitato di password errate in pochi minuti, per chi opera e per la connessione.
 * Raggiunto il numero, per il resto del periodo la password non viene nemmeno controllata. Un esito corretto azzera il conteggio.
 * Lettura e scrittura dei transient non sono atomiche: richieste in parallelo possono superare di poco il numero di tentativi. Per azioni che
 * richiedono già una sessione da amministratore va bene così, senza un lock.
 * Il contatore sta in una memoria passata dal chiamante (di norma i contatori in tabella, vedi {@see Counters}), così si prova senza WordPress.
 */
final class AttemptLimit {

	const MAX_FAILURES = 5;
	const WINDOW       = 900; // 15 minuti
	const PREFIX       = 'asem_pwl_';

	/** @var callable */
	private $get;
	/** @var callable */
	private $set;
	/** @var callable */
	private $del;
	/** @var callable */
	private $now;
	/** @var int */
	private $max;
	/** @var int */
	private $window;

	public function __construct( callable $get, callable $set, callable $del, callable $now, int $max = self::MAX_FAILURES, int $window = self::WINDOW ) {
		$this->get    = $get;
		$this->set    = $set;
		$this->del    = $del;
		$this->now    = $now;
		$this->max    = $max;
		$this->window = $window;
	}

	/** Con la memoria dei contatori. */
	public static function standard(): self {
		return new self(
			function ( string $k ) {
				return Counters::get( $k );
			},
			function ( string $k, array $v, int $ttl ) {
				Counters::set( $k, $v, $ttl );
			},
			function ( string $k ) {
				Counters::delete( $k );
			},
			'time'
		);
	}

	/** Chiavi del conteggio: una per l'utente e una per la connessione (se nota). */
	public static function keys( int $user_id, string $ip ): array {
		$keys = array( self::PREFIX . 'u' . $user_id );
		if ( '' !== $ip ) {
			$keys[] = self::PREFIX . 'i' . md5( $ip );
		}
		return $keys;
	}

	/** Il blocco è in corso per almeno una delle chiavi? */
	public function is_locked( array $keys ): bool {
		foreach ( $keys as $k ) {
			$r = $this->record( $k );
			if ( $r['n'] >= $this->max ) {
				return true;
			}
		}
		return false;
	}

	/** Registra una password errata. @return bool true se ora il blocco è in corso */
	public function fail( array $keys ): bool {
		$now    = (int) call_user_func( $this->now );
		$locked = false;
		foreach ( $keys as $k ) {
			$r = $this->record( $k );
			++$r['n'];
			if ( 1 === $r['n'] || $r['n'] >= $this->max ) {
				$r['t'] = $now; // il periodo parte dal primo errore e, raggiunto il numero, ricomincia da capo per tutto il blocco
			}
			call_user_func( $this->set, $k, $r, max( 1, $this->window - ( $now - $r['t'] ) ) );
			$locked = $locked || $r['n'] >= $this->max;
		}
		return $locked;
	}

	/** Password corretta: si riparte da zero. */
	public function clear( array $keys ): void {
		foreach ( $keys as $k ) {
			call_user_func( $this->del, $k );
		}
	}

	/** @return array{n:int,t:int} conteggio valido adesso (scaduto = zero) */
	private function record( string $key ): array {
		$r = call_user_func( $this->get, $key );
		if ( ! is_array( $r ) || ! isset( $r['n'], $r['t'] ) ) {
			return array( 'n' => 0, 't' => 0 );
		}
		if ( (int) call_user_func( $this->now ) - (int) $r['t'] >= $this->window ) {
			return array( 'n' => 0, 't' => 0 );
		}
		return array( 'n' => (int) $r['n'], 't' => (int) $r['t'] );
	}
}

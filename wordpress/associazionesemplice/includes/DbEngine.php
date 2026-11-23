<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Il motore delle tabelle del plugin (InnoDB o no).
 *
 * Le tabelle si creano col motore che il server dichiara predefinito: quasi sempre InnoDB, ma su qualche hosting vecchio è ancora MyISAM, che non conosce le
 * transazioni. Il plugin le usa per scrivere insieme più righe (una comunicazione e i suoi destinatari, la chiusura di un anno fiscale, il ripristino di una
 * copia…): su MyISAM un tempo scaduto a metà lascia le scritture a metà. Qui:
 * - si controlla all'installazione, all'aggiornamento e una volta alla settimana, e si ricorda l'esito (la Bacheca legge solo quello);
 * - la pagina Copia di sicurezza lo mostra, con il pulsante facoltativo «Converti in InnoDB», che lavora a tappe (poche tabelle per volta, la più piccola per prima);
 * - non si converte mai da soli: è una modifica del database che sull'hosting più lento può durare, e l'amministratore la sceglie.
 */
final class DbEngine {

	const OPT        = 'asem_db_engine';
	const HOOK       = 'asem_db_engine_check';
	const RECHECK    = 7 * DAY_IN_SECONDS;
	const MAX_TABLES = 4;
	const MAX_SECS   = 15.0;

	/**
	 * Le tabelle del plugin con il loro motore.
	 *
	 * @return array<int,array{name:string,engine:?string,bytes:int}>
	 */
	public static function tables(): array {
		$db   = Db::db();
		$like = $db->esc_like( $db->prefix . 'asem_' ) . '%';
		$rows = $db->get_results( $db->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stato delle tabelle del plugin
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$name = (string) ( $r['Name'] ?? '' );
			if ( ! DbEngineRules::is_plugin_table( $name, $db->prefix ) ) {
				continue;
			}
			$out[] = array(
				'name'   => $name,
				'engine' => isset( $r['Engine'] ) ? (string) $r['Engine'] : null,
				'bytes'  => (int) ( $r['Data_length'] ?? 0 ) + (int) ( $r['Index_length'] ?? 0 ),
			);
		}
		return $out;
	}

	/** Il server offre InnoDB? */
	public static function innodb_available(): bool {
		$db = Db::db();
		foreach ( (array) $db->get_results( 'SHOW ENGINES', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- motori offerti dal server
			if ( 0 === strcasecmp( (string) ( $r['Engine'] ?? '' ), DbEngineRules::TARGET ) ) {
				return DbEngineRules::engine_usable( $r['Support'] ?? '' );
			}
		}
		return false;
	}

	/**
	 * Rifà il controllo e ne ricorda l'esito.
	 *
	 * @return array{at:int,total:int,bad:string[],innodb:bool}
	 */
	public static function check( ?array $rows = null ): array {
		$rows = null === $rows ? self::tables() : $rows;
		$bad  = array();
		foreach ( DbEngineRules::to_convert( $rows ) as $t ) {
			$bad[] = $t['name'];
		}
		$r = array( 'at' => time(), 'total' => count( $rows ), 'bad' => $bad, 'innodb' => self::innodb_available() );
		update_option( self::OPT, $r, false );
		return $r;
	}

	/** L'ultimo esito ricordato, o null se non c'è mai stato un controllo. @return array{at:int,total:int,bad:string[],innodb:bool}|null */
	public static function last(): ?array {
		$r = get_option( self::OPT, null );
		if ( ! is_array( $r ) || ! isset( $r['at'] ) ) {
			return null;
		}
		return array(
			'at'     => (int) $r['at'],
			'total'  => (int) ( $r['total'] ?? 0 ),
			'bad'    => array_values( array_map( 'strval', (array) ( $r['bad'] ?? array() ) ) ),
			'innodb' => ! empty( $r['innodb'] ),
		);
	}

	/** Lavoro periodico (una volta al giorno): rifà il controllo se l'ultimo ha più di una settimana. */
	public static function cron(): void {
		$r = self::last();
		if ( null === $r || time() - $r['at'] >= self::RECHECK ) {
			self::check();
		}
	}

	/**
	 * Converte a tappe: al massimo MAX_TABLES tabelle e MAX_SECS secondi per volta (la tabella cominciata si finisce). Un solo lavoro alla volta.
	 * Una tabella che non si converte (ad esempio per un indice troppo lungo per il vecchio formato) resta com'era e viene riferita.
	 *
	 * @return array{done:string[],failed:string[],left:int,busy:bool,unavailable:bool}
	 */
	public static function convert_batch( int $max_tables = self::MAX_TABLES, float $max_secs = self::MAX_SECS ): array {
		$res = array( 'done' => array(), 'failed' => array(), 'left' => 0, 'busy' => false, 'unavailable' => false );
		if ( ! self::innodb_available() ) {
			$res['unavailable'] = true;
			return $res;
		}
		try {
			Db::with_lock(
				'db_engine_convert',
				function () use ( &$res, $max_tables, $max_secs ): void {
					self::convert_locked( $res, $max_tables, $max_secs );
				},
				0,
				true
			);
		} catch ( \InvalidArgumentException $e ) {
			$res['busy'] = true;
		}
		return $res;
	}

	/** @param array<string,mixed> $res */
	private static function convert_locked( array &$res, int $max_tables, float $max_secs ): void {
		$db      = Db::db();
		$todo    = DbEngineRules::to_convert( self::tables() );
		$started = microtime( true );
		$tried   = 0;
		foreach ( $todo as $t ) {
			if ( ! DbEngineRules::may_continue( count( $res['done'] ), $tried, microtime( true ) - $started, $max_tables, $max_secs ) ) {
				break;
			}
			++$tried;
			$name = $t['name']; // già verificato: solo lettere, cifre e «_», col prefisso del plugin
			$db->suppress_errors( true );
			$ok = false !== $db->query( 'ALTER TABLE `' . $name . '` ENGINE=' . DbEngineRules::TARGET ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome di tabella del plugin, validato
			$db->suppress_errors( false );
			if ( $ok ) {
				$res['done'][] = $name;
			} else {
				$res['failed'][] = $name;
			}
		}
		$after        = self::check(); // l'esito vero, letto dal server
		$res['left']  = count( $after['bad'] );
		if ( $res['done'] || $res['failed'] ) {
			Audit::log( 'db_engine.converted', 'database', 0, array( 'done' => count( $res['done'] ), 'failed' => count( $res['failed'] ), 'left' => $res['left'] ) );
		}
	}

	/** Avviso nella Bacheca (solo amministratori): legge l'ultimo esito ricordato, nessuna interrogazione del database. */
	public static function alerts_html(): string {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return '';
		}
		$r = self::last();
		if ( null === $r || ! $r['innodb'] || ! $r['bad'] ) {
			return '';
		}
		return Admin\Dismiss::html(
			'db-engine-' . count( $r['bad'] ),
			'warning',
			'<strong>' . esc_html( Texts::t( 'Il database usa un motore senza transazioni.' ) ) . '</strong> '
				. esc_html( Texts::n( count( $r['bad'] ), 'Una tabella del gestionale non è InnoDB: se un\'operazione si interrompe a metà (tempo scaduto del server), le scritture già fatte non si annullano.', '{n} tabelle del gestionale non sono InnoDB: se un\'operazione si interrompe a metà (tempo scaduto del server), le scritture già fatte non si annullano.' ) )
				. ' <a href="' . esc_url( Admin\Ui::url( 'asem-backup' ) . '#asem-db-engine' ) . '">' . esc_html( Texts::t( 'Converti in InnoDB' ) ) . '</a>.',
			14
		);
	}
}

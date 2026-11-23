<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le regole della scheda «Stato del server»: dai valori letti sul server (vedi {@see ServerStatus::collect()}) alle righe con stato e consiglio.
 * Parte pura, senza WordPress: si prova con i test unitari. Il plugin funziona con il minimo; ogni riga dice se il server è sul minimo (giallo),
 * sul consigliato (verde) o sotto il minimo / con una funzione spenta (rosso). Nessuna riga blocca l'uso: sono consigli.
 */
final class ServerStatusRules {

	const OK   = 'ok';
	const WARN = 'warn';
	const BAD  = 'bad';

	const MB = 1048576;

	/** Oltre queste ore senza un giro dei lavori programmati, il cron non sta girando (il giro è orario; WP-Cron parte con le visite). */
	const CRON_STALE_HOURS = 3;

	/**
	 * @param array<string,mixed> $e valori letti dal server (le chiavi mancanti si saltano: la riga non c'è)
	 * @return array<int,array{key:string,label:string,status:string,value:string,advice:string,code?:string,page?:string,anchor?:string}>
	 */
	public static function rows( array $e ): array {
		$out = array();
		foreach ( array( 'php', 'wp', 'opcache', 'memory', 'time', 'upload', 'mysql', 'db_engine', 'packet', 'object_cache', 'page_cache', 'cron', 'https', 'sodium', 'zip', 'mbstring', 'other_ext' ) as $key ) {
			$row = call_user_func( array( __CLASS__, 'row_' . $key ), $e );
			if ( null !== $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** @return array{bad:int,warn:int,ok:int} */
	public static function summary( array $rows ): array {
		$s = array( 'bad' => 0, 'warn' => 0, 'ok' => 0 );
		foreach ( $rows as $r ) {
			++$s[ $r['status'] ];
		}
		return $s;
	}

	/** La riga di una chiave (null se non c'è). */
	public static function find( array $rows, string $key ): ?array {
		foreach ( $rows as $r ) {
			if ( $r['key'] === $key ) {
				return $r;
			}
		}
		return null;
	}

	/** Il peggiore tra più stati. */
	public static function worst( array $statuses ): string {
		if ( in_array( self::BAD, $statuses, true ) ) {
			return self::BAD;
		}
		return in_array( self::WARN, $statuses, true ) ? self::WARN : self::OK;
	}

	/** Il comando di cron da copiare nel pannello dell'hosting. */
	public static function cron_command( string $url ): string {
		return '*/5 * * * * curl -s "' . $url . '" > /dev/null 2>&1';
	}

	/** «meno di un minuto», «5 minuti», «3 ore», «2 giorni». */
	public static function ago( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			return Texts::t( 'meno di un minuto' );
		}
		if ( $seconds < 3600 ) {
			return Texts::n( (int) floor( $seconds / 60 ), 'un minuto', '{n} minuti' );
		}
		if ( $seconds < 86400 ) {
			return Texts::n( (int) floor( $seconds / 3600 ), 'un\'ora', '{n} ore' );
		}
		return Texts::n( (int) floor( $seconds / 86400 ), 'un giorno', '{n} giorni' );
	}

	/** Megabyte con al più un decimale («20», «2,5»). */
	public static function mb( int $bytes ): string {
		$v = round( $bytes / self::MB, 1 );
		return rtrim( rtrim( number_format( $v, 1, ',', '' ), '0' ), ',' );
	}

	/** La parte numerica iniziale di una versione («8.0.36-0ubuntu» diventa «8.0.36»); stringa vuota se non c'è. */
	public static function clean_version( string $v ): string {
		return 1 === preg_match( '/^\d+(\.\d+){0,3}/', trim( $v ), $m ) ? $m[0] : '';
	}

	private static function row( string $key, string $label, string $status, string $value, string $advice = '' ): array {
		return array( 'key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'advice' => self::OK === $status ? '' : $advice );
	}

	private static function row_php( array $e ): ?array {
		$v = self::clean_version( (string) ( $e['php'] ?? '' ) );
		if ( '' === $v ) {
			return null;
		}
		$label = Texts::t( 'PHP' );
		$value = Texts::t( 'Versione {v}', array( 'v' => $v ) );
		if ( version_compare( $v, '7.4', '<' ) ) {
			return self::row( 'php', $label, self::BAD, $value, Texts::t( 'Questa versione di PHP è troppo vecchia per il plugin. Chiedi all\'hosting di passare a PHP 8.2 o superiore: di solito è un clic nel pannello.' ) );
		}
		if ( version_compare( $v, '8.2', '<' ) ) {
			return self::row( 'php', $label, self::WARN, $value, Texts::t( 'Funziona, ma le versioni più recenti rendono le pagine di amministrazione più rapide, e quelle vecchie non ricevono più correzioni di sicurezza. Chiedi all\'hosting di passare a PHP 8.2 o superiore: di solito è un clic nel pannello.' ) );
		}
		return self::row( 'php', $label, self::OK, $value );
	}

	private static function row_wp( array $e ): ?array {
		$v = self::clean_version( (string) ( $e['wp'] ?? '' ) );
		if ( '' === $v ) {
			return null;
		}
		$label = Texts::t( 'WordPress' );
		$value = Texts::t( 'Versione {v}', array( 'v' => $v ) );
		if ( version_compare( $v, '6.0', '<' ) ) {
			return self::row( 'wp', $label, self::BAD, $value, Texts::t( 'Aggiorna WordPress: il plugin richiede almeno la versione 6.0.' ) );
		}
		if ( version_compare( $v, '6.5', '<' ) ) {
			return self::row( 'wp', $label, self::WARN, $value, Texts::t( 'Aggiorna WordPress: con le versioni recenti le pagine si caricano più in fretta.' ) );
		}
		return self::row( 'wp', $label, self::OK, $value );
	}

	private static function row_opcache( array $e ): ?array {
		if ( ! isset( $e['opcache'] ) ) {
			return null;
		}
		$label = Texts::t( 'OPcache (memoria del codice PHP)' );
		if ( $e['opcache'] ) {
			return self::row( 'opcache', $label, self::OK, Texts::t( 'Attivo' ) );
		}
		return self::row( 'opcache', $label, self::WARN, Texts::t( 'Non attivo' ), Texts::t( 'Senza OPcache il server rilegge e ricompila i file del plugin a ogni visita. Chiedi all\'hosting di attivare OPcache: è il consiglio che migliora di più la velocità.' ) );
	}

	private static function row_memory( array $e ): ?array {
		if ( ! isset( $e['memory'] ) ) {
			return null;
		}
		$b     = (int) $e['memory'];
		$label = Texts::t( 'Memoria di PHP' );
		if ( $b < 0 ) {
			return self::row( 'memory', $label, self::OK, Texts::t( 'Senza limite' ) );
		}
		$value = Texts::t( '{n} MB', array( 'n' => self::mb( $b ) ) );
		if ( $b < 128 * self::MB ) {
			return self::row( 'memory', $label, self::BAD, $value, Texts::t( 'La memoria è sotto il minimo di 128 MB. Chiedi all\'hosting di aumentarla.' ) );
		}
		if ( $b < 256 * self::MB ) {
			return self::row( 'memory', $label, self::WARN, $value, Texts::t( 'Con più di 2.000 soci chiedi all\'hosting 256 MB: libro soci, PDF delle tessere e copia di sicurezza ne usano di più.' ) );
		}
		return self::row( 'memory', $label, self::OK, $value );
	}

	private static function row_time( array $e ): ?array {
		if ( ! isset( $e['time'] ) ) {
			return null;
		}
		$s     = (int) $e['time'];
		$label = Texts::t( 'Tempo massimo di una richiesta' );
		if ( $s <= 0 ) {
			return self::row( 'time', $label, self::OK, Texts::t( 'Senza limite' ) );
		}
		$value = Texts::t( '{n} secondi', array( 'n' => $s ) );
		if ( $s < 30 ) {
			return self::row( 'time', $label, self::BAD, $value, Texts::t( 'Il tempo è sotto il minimo di 30 secondi. Chiedi all\'hosting di alzarlo.' ) );
		}
		if ( $s < 60 ) {
			return self::row( 'time', $label, self::WARN, $value, Texts::t( 'Con 30 secondi le operazioni lunghe (copie di sicurezza, importazioni) vanno a tappe: è normale. Se puoi, chiedi 60 secondi o più.' ) );
		}
		return self::row( 'time', $label, self::OK, $value );
	}

	private static function row_upload( array $e ): ?array {
		if ( empty( $e['upload'] ) ) {
			return null;
		}
		$b     = (int) $e['upload'];
		$label = Texts::t( 'Dimensione massima dei file che si possono caricare' );
		$value = Texts::t( '{n} MB', array( 'n' => self::mb( $b ) ) );
		if ( $b < 2 * self::MB ) {
			return self::row( 'upload', $label, self::BAD, $value, Texts::t( 'L\'hosting accetta file fino a {n} MB: pochi per importare soci da Excel o caricare allegati. Chiedi di portare il limite a 20 MB.', array( 'n' => self::mb( $b ) ) ) );
		}
		if ( $b < 20 * self::MB ) {
			return self::row( 'upload', $label, self::WARN, $value, Texts::t( 'L\'hosting accetta file fino a {n} MB. Per file Excel e allegati grandi chiedi di portare il limite a 20 MB.', array( 'n' => self::mb( $b ) ) ) );
		}
		return self::row( 'upload', $label, self::OK, $value );
	}

	private static function row_mysql( array $e ): ?array {
		$v = self::clean_version( (string) ( $e['db_version'] ?? '' ) );
		if ( '' === $v ) {
			return null;
		}
		$maria = ! empty( $e['db_maria'] );
		$label = Texts::t( 'Database' );
		$value = ( $maria ? 'MariaDB ' : 'MySQL ' ) . $v;
		if ( version_compare( $v, $maria ? '10.0.2' : '5.7.5', '<' ) ) {
			return self::row( 'mysql', $label, self::BAD, $value, Texts::t( 'Il database è troppo vecchio: servono almeno MySQL 5.7.5 o MariaDB 10.0.2. Chiedi all\'hosting di aggiornarlo.' ) );
		}
		if ( version_compare( $v, $maria ? '10.6' : '8.0', '<' ) ) {
			return self::row( 'mysql', $label, self::WARN, $value, Texts::t( 'Funziona. Con MySQL 8.0 o MariaDB 10.6 (o più recenti) gli indici si creano senza fermare il sito e le ricerche su molte righe sono più rapide.' ) );
		}
		return self::row( 'mysql', $label, self::OK, $value );
	}

	private static function row_db_engine( array $e ): ?array {
		$d = $e['db_engine'] ?? null;
		if ( ! is_array( $d ) ) {
			return null;
		}
		$label = Texts::t( 'Motore delle tabelle del database' );
		$total = (int) ( $d['total'] ?? 0 );
		$bad   = (int) ( $d['bad'] ?? 0 );
		if ( $bad <= 0 ) {
			return self::row( 'db_engine', $label, self::OK, Texts::t( 'InnoDB' ) );
		}
		$value = Texts::t( '{n} tabelle su {totale} non usano InnoDB', array( 'n' => $bad, 'totale' => $total ) );
		if ( empty( $d['innodb'] ) ) {
			return self::row( 'db_engine', $label, self::WARN, $value, Texts::t( 'Il server non offre InnoDB: se un salvataggio si interrompe a metà, i dati possono restare a metà. Se vuoi le transazioni, chiedilo a chi gestisce l\'hosting.' ) );
		}
		$row = self::row( 'db_engine', $label, self::WARN, $value, Texts::t( 'Se un salvataggio si interrompe a metà, i dati possono restare a metà. La conversione a InnoDB è facoltativa e non cambia i dati: scarica prima una copia di sicurezza.' ) );
		$row['page']   = 'asem-backup';
		$row['anchor'] = 'asem-db-engine';
		return $row;
	}

	private static function row_packet( array $e ): ?array {
		if ( empty( $e['packet'] ) ) {
			return null;
		}
		$b     = (int) $e['packet'];
		$label = Texts::t( 'Richiesta più grande accettata dal database' );
		$value = Texts::t( '{n} MB', array( 'n' => self::mb( $b ) ) );
		if ( $b < 4 * self::MB ) {
			return self::row( 'packet', $label, self::BAD, $value, Texts::t( 'Sotto i 4 MB l\'anteprima di un\'importazione grande non si salva. Chiedi all\'hosting di portare il valore (max_allowed_packet) a 16 MB.' ) );
		}
		if ( $b < 16 * self::MB ) {
			return self::row( 'packet', $label, self::WARN, $value, Texts::t( 'Per importare file molto grandi conviene 16 MB o più (max_allowed_packet): chiedilo all\'hosting se ti serve.' ) );
		}
		return self::row( 'packet', $label, self::OK, $value );
	}

	private static function row_object_cache( array $e ): ?array {
		if ( ! isset( $e['object_cache'] ) ) {
			return null;
		}
		$label = Texts::t( 'Cache degli oggetti (Redis, Memcached)' );
		if ( $e['object_cache'] ) {
			return self::row( 'object_cache', $label, self::OK, Texts::t( 'Presente' ) );
		}
		return self::row( 'object_cache', $label, self::WARN, Texts::t( 'Assente' ), Texts::t( 'Non è necessaria. Se l\'hosting offre Redis o Memcached, attivarlo con il suo plugin riduce le richieste al database di WordPress e del plugin.' ) );
	}

	private static function row_page_cache( array $e ): ?array {
		if ( ! isset( $e['page_cache'] ) ) {
			return null;
		}
		$label = Texts::t( 'Cache di pagina' );
		$name  = (string) $e['page_cache'];
		if ( '' !== $name ) {
			return self::row( 'page_cache', $label, self::OK, Texts::t( 'Rilevata: {nome}', array( 'nome' => $name ) ) );
		}
		return self::row( 'page_cache', $label, self::WARN, Texts::t( 'Nessuna rilevata' ), Texts::t( 'Non è necessaria. Con una cache di pagina (quella dell\'hosting o un plugin come LiteSpeed Cache o WP Rocket) il sito pubblico e gli elenchi degli eventi si aprono senza impegnare WordPress.' ) );
	}

	private static function row_cron( array $e ): ?array {
		if ( ! isset( $e['cron_last'] ) ) {
			return null;
		}
		$last     = (int) $e['cron_last'];
		$now      = (int) ( $e['now'] ?? time() );
		$disabled = ! empty( $e['cron_disabled'] );
		$cmd      = self::cron_command( (string) ( $e['cron_url'] ?? '' ) );
		$label    = Texts::t( 'Operazioni programmate (promemoria, comunicazioni, pulizie)' );
		if ( 0 === $last ) {
			$row = self::row( 'cron', $label, self::WARN, Texts::t( 'Nessun giro registrato finora' ), Texts::t( 'Le operazioni programmate non sono ancora partite. Su un sito appena installato è normale: ricontrolla tra qualche ora. Se non cambia, chiedi all\'hosting di creare un\'operazione pianificata (cron) ogni 5 minuti con questo comando:' ) );
			$row['code'] = $cmd;
			return $row;
		}
		$age   = max( 0, $now - $last );
		$value = Texts::t( 'Ultimo giro: {tempo} fa', array( 'tempo' => self::ago( $age ) ) ) . ( $disabled ? ' · ' . Texts::t( 'cron del server' ) : '' );
		if ( $age > self::CRON_STALE_HOURS * 3600 ) {
			$advice = $disabled
				? Texts::t( 'WP-Cron è spento nel sito (DISABLE_WP_CRON), ma non risulta nessun cron del server che lo chiami: i promemoria e le comunicazioni non partono. Chiedi all\'hosting di creare un\'operazione pianificata (cron) ogni 5 minuti con questo comando, oppure togli DISABLE_WP_CRON da wp-config.php:' )
				: Texts::t( 'Le operazioni programmate non girano da ore: promemoria e comunicazioni non partono. Può dipendere da poche visite o dal sito che non riesce a chiamare sé stesso. Chiedi all\'hosting di creare un\'operazione pianificata (cron) ogni 5 minuti con questo comando:' );
			$row         = self::row( 'cron', $label, self::BAD, $value, $advice );
			$row['code'] = $cmd;
			return $row;
		}
		return self::row( 'cron', $label, self::OK, $value );
	}

	private static function row_https( array $e ): ?array {
		if ( ! isset( $e['https'] ) ) {
			return null;
		}
		$label = Texts::t( 'Connessione protetta (HTTPS)' );
		if ( $e['https'] ) {
			return self::row( 'https', $label, self::OK, Texts::t( 'Attiva' ) );
		}
		return self::row( 'https', $label, self::BAD, Texts::t( 'Non attiva' ), Texts::t( 'L\'area soci richiede HTTPS. Chiedi all\'hosting di attivare il certificato e imposta l\'indirizzo del sito con https://.' ) );
	}

	private static function row_sodium( array $e ): ?array {
		if ( ! isset( $e['sodium'] ) ) {
			return null;
		}
		$label = Texts::t( 'Cifratura dei file (estensione sodium)' );
		if ( $e['sodium'] ) {
			return self::row( 'sodium', $label, self::OK, Texts::t( 'Presente' ) );
		}
		return self::row( 'sodium', $label, self::BAD, Texts::t( 'Manca' ), Texts::t( 'Senza questa estensione i file riservati non si cifrano. Chiedi all\'hosting di attivare l\'estensione sodium di PHP.' ) );
	}

	private static function row_zip( array $e ): ?array {
		if ( ! isset( $e['zip'] ) ) {
			return null;
		}
		$label = Texts::t( 'Estensione zip' );
		if ( $e['zip'] ) {
			return self::row( 'zip', $label, self::OK, Texts::t( 'Presente' ) );
		}
		return self::row( 'zip', $label, self::BAD, Texts::t( 'Manca' ), Texts::t( 'Senza questa estensione non si possono creare la copia di sicurezza e gli ZIP delle ricevute, né leggere i file Excel. Attiva l\'estensione zip di PHP o chiedilo all\'hosting.' ) );
	}

	private static function row_mbstring( array $e ): ?array {
		if ( ! isset( $e['mbstring'] ) ) {
			return null;
		}
		$label = Texts::t( 'Estensione mbstring' );
		if ( $e['mbstring'] ) {
			return self::row( 'mbstring', $label, self::OK, Texts::t( 'Presente' ) );
		}
		return self::row( 'mbstring', $label, self::BAD, Texts::t( 'Manca' ), Texts::t( 'Il plugin ne ha bisogno per trattare nomi e testi con le lettere accentate. Chiedi all\'hosting di attivare l\'estensione mbstring di PHP.' ) );
	}

	private static function row_other_ext( array $e ): ?array {
		if ( ! isset( $e['missing_ext'] ) ) {
			return null;
		}
		$label   = Texts::t( 'Altre estensioni (zlib, iconv, SimpleXML, openssl, intl)' );
		$missing = array_values( array_map( 'strval', (array) $e['missing_ext'] ) );
		if ( ! $missing ) {
			return self::row( 'other_ext', $label, self::OK, Texts::t( 'Tutte presenti' ) );
		}
		return self::row( 'other_ext', $label, self::WARN, Texts::t( 'Mancano: {elenco}', array( 'elenco' => implode( ', ', $missing ) ) ), Texts::t( 'Sono facoltative, ma ognuna abilita qualcosa: zlib rende i PDF più leggeri, SimpleXML serve a leggere i file Excel, openssl ai collegamenti sicuri, intl a confrontare meglio i nomi. Chiedi all\'hosting di attivare quelle che mancano.' ) );
	}
}

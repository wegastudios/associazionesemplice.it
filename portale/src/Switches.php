<?php
namespace Portale;

/**
 * Interruttori che la titolare accende e spegne dalla pagina «Impostazioni del portale» (/account/impostazioni), senza toccare config.php.
 * I valori di config.php sono i default; quello salvato nel database li sostituisce all'avvio ({@see Switches::load}).
 * Solo interruttori che non servono prima del database e che non possono chiudere fuori nessuno (l'accesso unico e il proxy restano in config.php).
 */
final class Switches {

	/** chiave => [percorso in Config, nome, spiegazione]. */
	const ALL = array(
		'saas_v2'        => array( 'saas_v2.enabled', 'Pagina «Gestisci» del gestionale', 'Nell\'area clienti, accanto a «Apri il gestionale»: stato (soci, operatori, spazio) e controllo «ci sto dentro?» prima di cambiare piano. Se spegni, il pulsante sparisce.' ),
		'saas_v2_domain' => array( 'saas_v2.domain', 'Indirizzo personalizzato del gestionale', 'Il cliente può usare il suo dominio (delega dei nameserver, certificato con AutoSSL). Accendi solo quando il passo sul server è pronto: serve «Pagina Gestisci» accesa.' ),
	);

	/** Applica al Config i valori salvati. Da chiamare dopo la connessione al database. */
	public static function load( Db $db ): void {
		foreach ( self::read( $db ) as $key => $on ) {
			if ( isset( self::ALL[ $key ] ) && is_bool( $on ) ) {
				Config::set( self::ALL[ $key ][0], $on );
			}
		}
	}

	/** @return array<string,bool> stato attuale di ogni interruttore */
	public static function current(): array {
		$out = array();
		foreach ( self::ALL as $key => $def ) {
			$out[ $key ] = true === Config::get( $def[0], false );
		}
		return $out;
	}

	/** Salva tutti gli interruttori (quelli non spuntati sono spenti). Chiavi sconosciute ignorate. */
	public static function save( Db $db, array $in ): void {
		$all = array();
		foreach ( array_keys( self::ALL ) as $key ) {
			$all[ $key ] = ! empty( $in[ $key ] );
		}
		$db->run( 'DELETE FROM settings WHERE name = ?', array( 'switches' ) );
		$db->run( 'INSERT INTO settings (name, val) VALUES (?,?)', array( 'switches', (string) json_encode( $all ) ) );
		self::load( $db );
	}

	private static function read( Db $db ): array {
		$row = $db->one( 'SELECT val FROM settings WHERE name = ?', array( 'switches' ) );
		$v   = $row ? json_decode( (string) $row['val'], true ) : null;
		return is_array( $v ) ? $v : array();
	}
}

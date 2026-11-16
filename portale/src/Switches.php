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
		'saas_sale'      => array(
			'saas_sale.enabled', 'Vendita del gestionale SaaS',
			'Se è acceso, il sito mostra il gestionale SaaS: prezzi, piani, prova gratuita, iscrizione, menu e la colonna nella tabella di confronto. Se è spento (com\'è ora), tutto questo sparisce e chi apre una vecchia pagina del gestionale viene rimandato alla home. Il gestionale in sé, la sua demo e i clienti già attivi non cambiano.',
			'Si accende quando decidi di lanciare il gestionale; spento non cancella né modifica nulla.',
		),
		'indexable'      => array(
			'indexable', 'Il portale compare nei motori di ricerca',
			'Se è acceso, Google e gli altri motori possono indicizzare le pagine pubbliche: il file robots.txt le consente (e indica la sitemap) e il meta noindex non c\'è. Se è spento (com\'è ora), il robots.txt vieta tutto e ogni pagina porta il segnale noindex: il sito non compare nei risultati.',
			'Accendilo solo al lancio, quando i dati della ditta e le pagine legali sono completi. Spegnerlo non toglie subito le pagine già indicizzate: i motori le tolgono alla prossima visita.',
		),
		'maintenance'    => array(
			'maintenance.enabled', 'Modalità manutenzione',
			'Se è acceso, i visitatori vedono una pagina «Il sito è in manutenzione: torna tra poco» (stato 503, con noindex). Tu e lo staff, una volta entrati, vedete il sito normale con una banda «Manutenzione attiva». Le API usate dai siti dei clienti (licenze, aggiornamenti, scarico dei pacchetti), i pagamenti (Stripe e PayPal) e il collegamento con il gestionale NON si fermano mai. Restano aperti anche l\'accesso e la verifica in due passaggi, per poterla spegnere.',
			'Da accendere solo per un intervento; spenta, il sito è come sempre. L\'accensione e lo spegnimento si leggono nel registro delle azioni.',
		),
		'saas_v2'        => array(
			'saas_v2.enabled', 'Pagina «Gestisci» per i clienti del gestionale',
			'Se è acceso, chi ha comprato il gestionale vede nel suo account il pulsante «Gestisci»: lì controlla lo stato dell\'abbonamento (soci, operatori, spazio usato) e può verificare in anticipo se un cambio di piano è possibile. Se è spento, il pulsante non c\'è e i clienti usano solo «Apri il gestionale».',
			'Esiste perché la pagina dipende dal collegamento con il gestionale: si accende quando quel collegamento funziona, e si spegne subito se dà problemi.',
		),
		'saas_v2_domain' => array(
			'saas_v2.domain', 'Indirizzo personalizzato per i clienti del gestionale',
			'Se è acceso, nella pagina «Gestisci» il cliente può chiedere che il gestionale risponda al suo indirizzo (per esempio www.tuaassociazione.it) invece che a quello nostro. Se è spento, la richiesta non compare e i clienti usano l\'indirizzo che diamo noi.',
			'Va acceso solo quando sul server è pronto il modo di avere i certificati di sicurezza per i domini dei clienti. Funziona soltanto se anche l\'interruttore qui sopra è acceso.',
		),
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

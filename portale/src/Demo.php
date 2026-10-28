<?php
namespace Portale;

/**
 * La demo pubblica del plugin (WordPress vero in `associazionesemplice.it/demo/`): indirizzi, accessi di prova e ruoli, per la pagina `/prova`.
 * Le credenziali sono pubbliche di proposito; i due utenti e i dati si ricreano ogni notte (vedi docs/PORTALE.md, «Demo pubblica»).
 * Qui non c'è e non ci sarà mai un utente amministratore.
 */
final class Demo {

	public static function url(): string {
		return rtrim( (string) Config::get( 'demo_url', 'https://associazionesemplice.it/demo' ), '/' );
	}

	/** Gli accessi di prova: utente, password, ruolo, cosa si può fare e da dove si parte. */
	public static function accounts(): array {
		return array(
			array(
				'user' => 'segreteria', 'password' => 'Segreteria-Demo-2026', 'role' => 'Segreteria', 'start' => '/wp-admin/admin.php?page=asem',
				'what' => array( 'Soci, ospiti e tessere', 'Incassi e spese in prima nota', 'Attività, eventi e calendario', 'Registri, verbali e privacy', 'Comunicazioni ai soci' ),
				'note' => 'Lavora nel pannello di WordPress. Non vede le impostazioni, i pagamenti online né la copia di sicurezza: sono dell\'amministratore del sito.',
			),
			array(
				'user' => 'socio', 'password' => 'Socio-Demo-2026', 'role' => 'Socio', 'start' => '/area-soci/',
				'what' => array( 'Tessera con QR', 'Prenotazioni a corsi ed eventi', 'Pagamenti e quote', 'I propri dati e gli ospiti' ),
				'note' => 'Usa l\'area riservata sul sito, come farebbe un socio vero: non entra mai nel pannello di WordPress.',
			),
			array(
				'user' => 'amministratore', 'password' => 'Amministratore-Demo-2026', 'role' => 'Amministratore del plugin', 'start' => '/wp-admin/admin.php?page=asem-backup',
				'what' => array( 'Tutte le impostazioni di Associazione Semplice', 'Copia di sicurezza: scarichi i tuoi dati e li ricarichi il giorno dopo', 'Ruoli, limiti, tessera e registro delle azioni', 'Tutto quello che fa la segreteria' ),
				'note' => 'Ha tutti i permessi del plugin ma non è un amministratore di WordPress: non installa plugin, non cambia utenti né impostazioni del sito. La chiave di licenza non si vede né si cambia.',
			),
		);
	}

	/** Collegamenti utili: gruppo => elenco di [testo, percorso nella demo, spiegazione]. */
	public static function links(): array {
		return array(
			'Senza entrare' => array(
				array( 'Il sito della demo', '/', 'La pagina iniziale, con i collegamenti alle altre.' ),
				array( 'Attività ed eventi', '/attivita-ed-eventi/', 'L\'elenco pubblico di corsi ed eventi, con la prenotazione.' ),
				array( 'Diventa socio', '/diventa-socio/', 'La domanda di adesione online per chi non è ancora socio.' ),
				array( 'Informativa privacy', '/informativa-privacy/', 'L\'informativa compilata con i dati dell\'associazione.' ),
			),
			'Come socio' => array(
				array( 'Area soci', '/area-soci/', 'Tessera, prenotazioni, pagamenti e dati personali.' ),
			),
			'Come amministratore del plugin' => array(
				array( 'Copia di sicurezza', '/wp-admin/admin.php?page=asem-backup', 'Scarichi una copia dei tuoi dati e la ricarichi quando vuoi (la demo si azzera ogni notte alle 4).' ),
				array( 'Impostazioni', '/wp-admin/admin.php?page=asem-settings', 'Funzioni accese, pagine del sito, privacy.' ),
				array( 'Ruoli', '/wp-admin/admin.php?page=asem-roles', 'Chi può fare cosa: carica e assegna i permessi.' ),
				array( 'Registro delle azioni', '/wp-admin/admin.php?page=asem-audit', 'Chi ha fatto cosa, con spazio occupato e pulizia.' ),
			),
			'Come segreteria' => array(
				array( 'Bacheca', '/wp-admin/admin.php?page=asem', 'Il riepilogo con gli avvisi e le cose da fare.' ),
				array( 'Soci e ospiti', '/wp-admin/admin.php?page=asem-people', 'La rubrica dei soci, con schede e tessere.' ),
				array( 'Nuovo incasso', '/wp-admin/admin.php?page=asem-income', 'Quote e attività, con il resto e le ricevute.' ),
				array( 'Prima nota', '/wp-admin/admin.php?page=asem-ledger', 'Entrate e uscite dell\'anno.' ),
				array( 'Calendario', '/wp-admin/admin.php?page=asem-calendar', 'Corsi ed eventi su un calendario.' ),
				array( 'Area segreteria', '/area-segreteria/', 'Lo stesso lavoro, dal sito invece che dal pannello.' ),
			),
		);
	}

	/**
	 * Tutti i ruoli del plugin e cosa possono fare (la stessa tabella delle impostazioni del plugin, «Chi può fare cosa»).
	 *
	 * @return array [ 'columns' => nomi dei ruoli, 'rows' => funzione => celle ]
	 */
	public static function roles(): array {
		$y = '✓';
		$n = '—';
		return array(
			'columns' => array( 'Amministratore', 'Segreteria', 'Presidente e vice', 'Tesoriere', 'Volontario', 'Staff dell\'ente', 'Staff dell\'evento', 'Socio' ),
			'rows'    => array(
				'Impostazioni, pagamenti online, copia di sicurezza' => array( $y, $n, $n, $n, $n, $n, $n, $n ),
				'Soci e iscrizioni'                                   => array( $y, $y, $y, 'iscrive', $n, $n, $n, $n ),
				'Incassi e spese'                                     => array( $y, $y, $y, $y, $n, $n, $n, $n ),
				'Contabilità, registri e adempimenti'                 => array( $y, $y, $y, $n, $n, $n, $n, $n ),
				'Comunicazioni agli iscritti'                         => array( $y, $y, $y, $n, 'ai propri eventi', $n, $n, $n ),
				'Eventi e attività'                                   => array( $y, $y, $y, 'vende', 'propri', $n, $n, $n ),
				'Verifica degli ingressi'                             => array( $y, $y, $y, $n, 'propri eventi', 'tutti gli eventi', 'suo evento', $n ),
				'Incasso del biglietto sul posto'                     => array( $y, $y, $y, $y, 'propri eventi', $n, 'se abilitato', $n ),
				'Propri dati, tessera e pagamenti'                    => array( $y, $y, $y, $y, $y, $y, $y, $y ),
			),
		);
	}

	/** Chi è chi: ruolo => in due righe, e se c'è nella demo. */
	public static function roleNotes(): array {
		return array(
			array( 'Amministratore', 'Chi gestisce il sito WordPress: tutto, comprese impostazioni, pagamenti online, licenza e copia di sicurezza.', false ),
			array( 'Amministratore del plugin', 'Ha tutti i permessi di Associazione Semplice ma non gestisce il sito WordPress (plugin, temi, utenti). Nella demo è l\'unico che scarica e ricarica la copia dei dati.', true ),
			array( 'Segreteria', 'Una persona del sito con il ruolo «Segreteria»: opera su soci, incassi, attività e registri, senza toccare le impostazioni.', true ),
			array( 'Presidente e vicepresidente', 'Cariche del consiglio: se la tessera è in regola agiscono come la segreteria su tutto.', false ),
			array( 'Tesoriere', 'Lavora dall\'area riservata: incassa, registra spese con la foto dello scontrino, iscrive soci e vende gli eventi.', false ),
			array( 'Consigliere', 'Carica del consiglio direttivo, registrata nel libro soci; i permessi restano quelli di socio.', false ),
			array( 'Volontario (referente)', 'Socio e volontario che tiene corsi ed eventi: gestisce iscritti, avvisi e ingressi solo delle sue attività.', false ),
			array( 'Staff dell\'ente e dell\'evento', 'Chi controlla gli ingressi: di tutti gli eventi (staff dell\'ente) o di uno solo (staff dell\'evento, con l\'incasso se abilitato).', false ),
			array( 'Socio', 'Socio fondatore, ordinario o volontario: vede e modifica solo i propri dati, la tessera, i pagamenti e gli ospiti.', true ),
			array( 'Genitore o tutore', 'Non è socio: entra nell\'area riservata per seguire i figli, senza tessera né quota.', false ),
			array( 'Ospite', 'Non ha un accesso: lo registra il socio che lo ospita e partecipa tramite lui.', false ),
		);
	}
}

<?php
namespace Portale;

/**
 * Email per ogni evento. I testi di base sono qui; dal pannello si possono modificare (oggetto, testo, attivo/spento)
 * e riportare al testo originale. Nel testo valgono i segnaposto tra graffe, per esempio {dominio}.
 */
final class Emails {

	/** Firma uguale in tutte le email; {supporto_url} diventa l'indirizzo del supporto. */
	const FOOTER = "\n\n— Associazione Semplice\nHai bisogno di aiuto? Scrivici da {supporto_url}";

	public static function footer( array $vars = array() ): string {
		return self::render( self::FOOTER, $vars + array( 'supporto_url' => SupportSso::supportUrl() ) );
	}

	/** Eventi che non si possono spegnere: senza questi il cliente non riesce a entrare. */
	const ALWAYS_ON = array( 'login_link', 'free_confirm' );

	/** @return array evento => [label, when, vars, subject, body] */
	public static function events(): array {
		return array(
			'login_link' => array(
				'label' => 'Link di accesso', 'when' => 'Il cliente chiede di entrare nell\'area riservata.', 'vars' => array( 'link', 'email' ),
				'subject' => 'Il tuo accesso ad Associazione Semplice',
				'body' => "Ciao,\nper entrare nell'area riservata apri questo link entro 15 minuti:\n\n{link}\n\nSe non hai fatto tu questa richiesta, ignora questo messaggio.",
			),
			'free_confirm' => array(
				'label' => 'Conferma licenza gratuita', 'when' => 'Dopo la richiesta della licenza gratuita: serve a confermare l\'email.', 'vars' => array( 'link', 'dominio', 'email' ),
				'subject' => 'Conferma la tua licenza gratuita',
				'body' => "Ciao,\nper attivare la licenza gratuita di Associazione Semplice per {dominio} apri questo link entro 15 minuti:\n\n{link}\n\nSe non hai fatto tu questa richiesta, ignora questo messaggio.",
			),
			'welcome' => array(
				'label' => 'Abbonamento attivo', 'when' => 'Subito dopo il primo pagamento riuscito.', 'vars' => array( 'link', 'dominio', 'piano', 'scadenza', 'email' ),
				'subject' => 'Il tuo piano {piano} è attivo',
				'body' => "Grazie!\nLa licenza {piano} per {dominio} è pronta e vale fino al {scadenza}.\n\nPer vedere la chiave apri questo link entro 15 minuti:\n\n{link}",
			),
			'renewed' => array(
				'label' => 'Rinnovo ricevuto', 'when' => 'A ogni rinnovo automatico andato a buon fine.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Rinnovo ricevuto: {piano} fino al {scadenza}',
				'body' => "Abbiamo ricevuto il pagamento: la licenza {piano} per {dominio} è rinnovata fino al {scadenza}.\n\nPuoi gestirla qui:\n{account_url}",
			),
			'payment_failed' => array(
				'label' => 'Pagamento non riuscito', 'when' => 'Il pagamento di un rinnovo non è andato a buon fine.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Pagamento non riuscito per {dominio}',
				'body' => "Non siamo riusciti a incassare il rinnovo del piano {piano} per {dominio}.\nControlla il metodo di pagamento: la licenza resta valida fino al {scadenza}, poi tornano le funzioni di base. I tuoi dati non vengono cancellati.\n\n{account_url}",
			),
			'canceled' => array(
				'label' => 'Disdetta registrata', 'when' => 'Il cliente disdice il rinnovo, o il provider annulla l\'abbonamento.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Disdetta registrata per {dominio}',
				'body' => "Abbiamo registrato la disdetta del piano {piano} per {dominio}. La licenza resta valida fino al {scadenza}.\n\nSe cambi idea puoi scegliere di nuovo un piano dall'area riservata:\n{account_url}",
			),
			'plan_upgraded' => array(
				'label' => 'Piano cambiato (upgrade)', 'when' => 'Il cliente passa a un piano superiore e paga la differenza.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Piano aggiornato per {dominio}',
				'body' => "Il pagamento della differenza è andato a buon fine: la licenza per {dominio} ora è {piano}.\nLa chiave e la scadenza ({scadenza}) non cambiano: le nuove funzioni compaiono al prossimo controllo del plugin.\n\n{account_url}",
			),
			'plan_downgrade_scheduled' => array(
				'label' => 'Cambio piano programmato (downgrade)', 'when' => 'Il cliente sceglie un piano più economico dal prossimo rinnovo.', 'vars' => array( 'dominio', 'piano', 'nuovo_piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Cambio piano programmato per {dominio}',
				'body' => "Abbiamo registrato il passaggio a {nuovo_piano} per {dominio}.\nFino al {scadenza} la licenza resta {piano}; dal rinnovo successivo cambia piano e prezzo. Puoi annullare il cambio dall'area riservata prima della scadenza.\n\n{account_url}",
			),
			'plan_downgraded' => array(
				'label' => 'Piano cambiato (downgrade)', 'when' => 'Dopo il rinnovo, la licenza passa al piano più economico scelto.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Ora il piano di {dominio} è {piano}',
				'body' => "Con il rinnovo la licenza per {dominio} è passata a {piano}, come avevi chiesto.\nLa chiave non cambia; le funzioni dei piani superiori si disattivano, i dati restano. Prossima scadenza: {scadenza}.\n\n{account_url}",
			),
			'expiring' => array(
				'label' => 'Licenza in scadenza', 'when' => 'Qualche giorno prima della scadenza di una licenza senza rinnovo automatico.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La licenza di {dominio} scade il {scadenza}',
				'body' => "La licenza {piano} per {dominio} scade il {scadenza} e non si rinnova da sola.\nPer continuare a usare le funzioni Pro puoi rinnovarla da qui:\n\n{account_url}",
			),
			'trial_started' => array(
				'label' => 'Prova Pro attivata', 'when' => 'Il cliente attiva la prova gratuita del piano Pro.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La tua prova di {piano} è attiva',
				'body' => "La prova gratuita di {piano} per {dominio} è attiva fino al {scadenza}.\n\nLa chiave di prova è nell'area riservata: incollala nel plugin al posto di quella gratuita. Alla fine della prova rimetti la chiave gratuita oppure passa al Pro.\n\n{account_url}",
			),
			'trial_ending' => array(
				'label' => 'Prova in scadenza', 'when' => 'Qualche giorno prima della fine della prova gratuita.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La prova di {piano} finisce il {scadenza}',
				'body' => "La prova gratuita di {piano} per {dominio} finisce il {scadenza}.\nPer tenere le funzioni Pro scegli un piano; altrimenti rimetti la chiave gratuita e tutto continua con le funzioni di base.\n\n{account_url}",
			),
			'domain_changed' => array(
				'label' => 'Dominio cambiato', 'when' => 'Il dominio di una licenza viene cambiato.', 'vars' => array( 'dominio', 'vecchio_dominio', 'piano', 'account_url', 'email' ),
				'subject' => 'Dominio aggiornato: {dominio}',
				'body' => "Il dominio della licenza {piano} è passato da {vecchio_dominio} a {dominio}.\nIl vecchio dominio smette di funzionare al prossimo controllo.\n\nSe non hai fatto tu questa richiesta, scrivici subito.\n{account_url}",
			),
			// Gestionale online (acquisto dal portale). Testi dell'audit del 13/11/2026. Regole della titolare (13/11/2026): nuovi tentativi per 7 giorni (3 al giorno
			// nei primi 2, poi 1), sospensione il giorno dopo la scadenza, dati conservati 180 giorni.
			'saas_welcome' => array(
				'label' => 'Gestionale: benvenuto', 'when' => 'Subito dopo il pagamento dell\'ordine del gestionale online.', 'vars' => array( 'nome', 'associazione', 'piano', 'periodo', 'gestionale_url', 'link_password', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Benvenuto: scegli la password per il gestionale di {associazione}',
				'body' => "Ciao {nome},\ngrazie per aver scelto Associazione Semplice. Stiamo preparando il gestionale di {associazione}: ci vogliono pochi minuti.\n\nIntanto scegli la tua password da qui (scrivi questa email e ti arriva il link per sceglierla):\n{link_password}\n\nL'indirizzo del tuo gestionale è {gestionale_url}. Salvalo tra i preferiti: entrerai sempre da lì.\n\nHai scelto il piano {piano}, con pagamento {periodo}. Fatture e pagamenti sono in {account_url}.",
			),
			'saas_ready' => array(
				'label' => 'Gestionale: pronto', 'when' => 'Quando il gestionale dell\'associazione è stato creato.', 'vars' => array( 'nome', 'associazione', 'gestionale_url', 'link_password', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Il gestionale di {associazione} è pronto',
				'body' => "Ciao {nome},\nil gestionale è pronto. Entra da {gestionale_url} con la tua email e la password che hai scelto.\n\nPer cominciare bastano tre passi:\n1. completa i dati dell'associazione nella configurazione guidata;\n2. importa l'elenco dei soci da Excel;\n3. invita le persone che lavorano con te.\n\nNon hai ancora scelto la password? Fallo da qui: {link_password}",
			),
			'saas_renewed' => array(
				'label' => 'Gestionale: rinnovo avvenuto', 'when' => 'A ogni rinnovo del gestionale andato a buon fine.', 'vars' => array( 'nome', 'associazione', 'piano', 'importo', 'scadenza', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Rinnovo riuscito: {piano} valido fino al {scadenza}',
				'body' => "Ciao {nome},\nabbiamo rinnovato il piano {piano} di {associazione}.\n\nImporto: {importo}\nValido fino al: {scadenza}\n\nLa fattura è in {account_url}. Non devi fare nulla.",
			),
			'saas_payment_failed' => array(
				'label' => 'Gestionale: pagamento non riuscito', 'when' => 'Il pagamento di un rinnovo del gestionale non è andato a buon fine.', 'vars' => array( 'nome', 'associazione', 'piano', 'importo', 'scadenza', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Pagamento non riuscito per {associazione}: cosa fare',
				'body' => "Ciao {nome},\nnon siamo riusciti ad addebitare {importo} per il piano {piano}. Di solito succede per una carta scaduta o un limite di spesa.\n\nCosa fare: apri {account_url}, premi «Fatture e metodo di pagamento» e controlla o cambia il metodo di pagamento.\n\nRiproviamo da soli per 7 giorni: più volte nei primi due, poi una volta al giorno. Se in quei giorni sistemi il metodo di pagamento, non devi fare altro.\n\nIl gestionale funziona fino al {scadenza}. Se entro quel giorno il pagamento non va a buon fine, dal giorno dopo viene sospeso finché non è sistemato. I dati restano al loro posto.",
			),
			'saas_expiring' => array(
				'label' => 'Gestionale: piano in scadenza', 'when' => 'Nei giorni di preavviso prima della scadenza di un gestionale disdetto (non si rinnova da solo).', 'vars' => array( 'nome', 'associazione', 'piano', 'scadenza', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Il piano di {associazione} scade il {scadenza}',
				'body' => "Ciao {nome},\nil piano {piano} di {associazione} scade il {scadenza} e non si rinnova da solo.\n\nPer continuare a usare il gestionale rinnovalo da {account_url}. Se non lo rinnovi, dal giorno dopo il gestionale viene sospeso: i dati restano conservati, ma tu e i soci non potrete entrare.",
			),
			'saas_suspended' => array(
				'label' => 'Gestionale: sospeso', 'when' => 'Il giorno dopo la scadenza, se il gestionale non è stato rinnovato.', 'vars' => array( 'nome', 'associazione', 'piano', 'scadenza', 'data_cancellazione', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Il gestionale di {associazione} è sospeso',
				'body' => "Ciao {nome},\nil piano {piano} è scaduto il {scadenza} e il gestionale di {associazione} è sospeso: tu, gli operatori e i soci non potete entrare.\n\nI dati sono al sicuro e restano conservati per 180 giorni, fino al {data_cancellazione}. Dopo quella data vengono cancellati.\n\nPer riattivarlo scrivici da {supporto_url}: ritrovi tutto com'era.",
			),
			'saas_cancelled' => array(
				'label' => 'Gestionale: disdetta registrata', 'when' => 'Il cliente disdice il rinnovo del gestionale dall\'area clienti.', 'vars' => array( 'nome', 'associazione', 'piano', 'scadenza', 'account_url', 'supporto_url', 'email' ),
				'subject' => 'Disdetta registrata: il gestionale resta attivo fino al {scadenza}',
				'body' => "Ciao {nome},\nabbiamo registrato la disdetta del piano {piano} di {associazione}. Non ci saranno altri addebiti.\n\nIl gestionale resta attivo fino al {scadenza}. Prima di quella data ti consigliamo di scaricare i tuoi dati da «Gestione → Esportazione dei dati».\n\nHai cambiato idea? Scrivici da {supporto_url}.\n\nDopo il {scadenza} i dati restano conservati per 180 giorni, poi vengono cancellati.",
			),
		);
	}

	/** @return array|null [subject, body, enabled, custom] */
	public static function template( Db $db, string $event ): ?array {
		$def = self::events()[ $event ] ?? null;
		if ( ! $def ) {
			return null;
		}
		$row = $db->one( 'SELECT * FROM email_templates WHERE event = ?', array( $event ) );
		return $row
			? array( 'subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'enabled' => (bool) $row['enabled'], 'custom' => true )
			: array( 'subject' => $def['subject'], 'body' => $def['body'], 'enabled' => true, 'custom' => false );
	}

	public static function render( string $text, array $vars ): string {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		// Senza nome del cliente «Ciao {nome},» diventa «Ciao,».
		return (string) preg_replace( '/^Ciao ,/m', 'Ciao,', strtr( $text, $map ) );
	}

	/** Segnaposto scritti nel testo che non esistono per questo evento (per avvisare chi modifica). */
	public static function unknownVars( string $event, string $text ): array {
		$def = self::events()[ $event ] ?? null;
		preg_match_all( '/\{([a-z_]+)\}/', $text, $m );
		return $def ? array_values( array_diff( array_unique( $m[1] ), $def['vars'] ) ) : array();
	}

	/** @return bool true se la mail è partita (false se l'evento è spento o l'invio è fallito) */
	public static function send( Db $db, string $event, string $to, array $vars = array() ): bool {
		$t = self::template( $db, $event );
		if ( ! $t || ( ! $t['enabled'] && ! in_array( $event, self::ALWAYS_ON, true ) ) ) {
			return false;
		}
		$vars += array( 'email' => $to, 'account_url' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/account', 'supporto_url' => SupportSso::supportUrl() );
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', self::render( $t['subject'], $vars ) ) );
		return Mailer::send( $to, $subject, self::render( $t['body'], $vars ) . self::footer( $vars ) . "\n" );
	}

	/** Invia il modello com'è adesso (anche se spento) con dati di esempio: serve a vedere l'effetto prima di usarlo. */
	public static function sendTest( Db $db, string $event, string $to, array $vars ): bool {
		$t = self::template( $db, $event );
		if ( ! $t ) {
			return false;
		}
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', self::render( $t['subject'], $vars ) ) );
		return Mailer::send( $to, '[PROVA] ' . $subject, self::render( $t['body'], $vars ) . self::footer( $vars ) . "\n" );
	}

	public static function save( Db $db, string $event, string $subject, string $body, bool $enabled ): void {
		if ( ! isset( self::events()[ $event ] ) ) {
			throw new UserError( 'Evento sconosciuto.' );
		}
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', $subject ) );
		if ( '' === $subject || '' === trim( $body ) ) {
			throw new UserError( 'Oggetto e testo non possono essere vuoti.' );
		}
		$db->run( 'DELETE FROM email_templates WHERE event = ?', array( $event ) );
		$db->run( 'INSERT INTO email_templates (event, subject, body, enabled, updated_at) VALUES (?,?,?,?,?)', array( $event, mb_substr( $subject, 0, 255 ), mb_substr( $body, 0, 8000 ), $enabled ? 1 : 0, Db::now() ) );
	}

	public static function reset( Db $db, string $event ): void {
		$db->run( 'DELETE FROM email_templates WHERE event = ?', array( $event ) );
	}
}

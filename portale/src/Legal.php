<?php
namespace Portale;

/** Informativa privacy e cookie: testi di base modificabili dal pannello, con i dati del titolare. */
final class Legal {

	const PAGES = array(
		'privacy' => array( 'Informativa sulla privacy', 'privacy_body' ),
		'cookie'  => array( 'Informativa sui cookie', 'cookie_body' ),
		'termini' => array( 'Termini di utilizzo del servizio', 'terms_body' ),
	);

	public static function vars(): array {
		$l    = (array) Config::get( 'legal', array() );
		$none = function ( string $k, string $fallback ) use ( $l ) {
			return '' !== trim( (string) ( $l[ $k ] ?? '' ) ) ? (string) $l[ $k ] : $fallback;
		};
		return array(
			'titolare'  => $none( 'titolare', 'Associazione Semplice' ),
			'indirizzo' => $none( 'indirizzo', '' ),
			'piva'      => $none( 'piva', '' ),
			'email'     => $none( 'email', 'info@associazionesemplice.it' ),
			'sito'      => 'associazionesemplice.it',
		);
	}

	/** Testo della pagina: quello salvato dal pannello oppure quello di base. */
	public static function body( string $slug ): string {
		$own = self::own( $slug );
		return '' !== $own ? $own : self::defaults()[ $slug ];
	}

	private static function own( string $slug ): string {
		$l   = (array) Config::get( 'legal', array() );
		$key = self::PAGES[ $slug ][1] ?? '';
		return trim( (string) ( $l[ $key ] ?? '' ) );
	}

	public static function isCustom( string $slug ): bool {
		return '' !== self::own( $slug );
	}

	/** Sostituisce i segnaposto e trasforma il testo semplice in HTML (titoli «## », elenchi «- », **grassetto**). */
	public static function html( string $text ): string {
		$map = array();
		foreach ( self::vars() as $k => $v ) {
			$map[ '{' . $k . '}' ] = $v;
		}
		$text   = strtr( $text, $map );
		$text   = preg_replace( '/[ 	]+(?=[.,;])/', '', preg_replace( '/[ 	]{2,}/', ' ', $text ) );
		$blocks = preg_split( '/\n{2,}/', trim( str_replace( "\r", '', $text ) ) );
		$out    = '';
		$inline = function ( string $s ): string {
			$h = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e( $s ) );
			// Collegamenti [testo](/pagina) o [testo](https://…): solo indirizzi del sito o https.
			return preg_replace( '/\[([^\]]+)\]\(((?:https:\/\/|\/)[^)\s"\'<>]*)\)/', '<a href="$2">$1</a>', $h );
		};
		foreach ( $blocks as $b ) {
			$lines = array_values( array_filter( array_map( 'rtrim', explode( "\n", $b ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			if ( 0 === strpos( $lines[0], '## ' ) ) {
				$out .= '<h2>' . $inline( substr( array_shift( $lines ), 3 ) ) . '</h2>';
				if ( ! $lines ) {
					continue;
				}
			}
			if ( 0 === strpos( $lines[0], '- ' ) ) {
				$out .= '<ul>';
				foreach ( $lines as $l ) {
					$out .= '<li>' . $inline( preg_replace( '/^- /', '', $l ) ) . '</li>';
				}
				$out .= '</ul>';
			} else {
				$out .= '<p>' . implode( '<br>', array_map( $inline, $lines ) ) . '</p>';
			}
		}
		return $out;
	}

	public static function defaults(): array {
		return array(
			'privacy' => <<<'TXT'
## Chi tratta i tuoi dati
Il titolare del trattamento è {titolare} {indirizzo} {piva}. Per qualsiasi richiesta scrivi a {email}.

## Quali dati trattiamo
- Indirizzo email e dominio del sito che indichi quando chiedi una licenza o acquisti un piano.
- Chiave di licenza, piano, scadenza e stato dei pagamenti.
- Dati tecnici di navigazione necessari al funzionamento del sito (cookie tecnici).

I dati della carta di credito non passano da noi: i pagamenti sono gestiti da Stripe e da PayPal.

## Perché e su quale base
Trattiamo i dati per fornirti la licenza, gestire il tuo abbonamento, inviarti le comunicazioni legate al servizio (accesso, rinnovi, scadenze) e adempiere agli obblighi di legge e fiscali. La base giuridica è l'esecuzione del contratto e il rispetto di obblighi di legge.

## A chi li comunichiamo
Solo a fornitori che ci permettono di erogare il servizio: Stripe e PayPal per i pagamenti, il fornitore del servizio di gestione delle licenze, il fornitore di hosting e di posta. Non vendiamo i tuoi dati.

## Il plugin sul tuo sito: verifica della licenza e aggiornamenti
Il plugin gratuito non invia nulla al nostro server. Il plugin Pro, per funzionare, contatta ogni giorno questo sito per verificare la licenza e, almeno ogni 12 ore, per sapere se esiste un aggiornamento. In queste richieste inviamo solo la chiave di licenza, l'indirizzo del sito (dominio) e il numero di versione del plugin. Non inviamo dati dei soci, dei pagamenti dell'associazione né altri contenuti del tuo sito. Per limitare gli abusi conserviamo per breve tempo l'indirizzo IP da cui arrivano le richieste e il numero di richieste. L'aggiornamento diretto si può spegnere dalle impostazioni del plugin e il pacchetto scaricato è firmato: il plugin lo controlla prima di installarlo.

## Per quanto tempo li conserviamo
Per tutta la durata del rapporto e, dopo, per il tempo richiesto dalla legge per le scritture contabili e fiscali (di norma 10 anni). I dati di accesso inutilizzati si cancellano su richiesta.

## I tuoi diritti
Puoi chiedere accesso, rettifica, cancellazione, limitazione, portabilità e opporti al trattamento scrivendo a {email}. Hai anche il diritto di proporre reclamo al Garante per la protezione dei dati personali (garanteprivacy.it).
TXT
			,
			'cookie' => <<<'TXT'
## Che cosa sono i cookie
I cookie sono piccoli file che il sito salva nel tuo browser. Alcuni servono a farlo funzionare, altri a capire come viene usato.

## Cookie che usiamo
- **Tecnici (sempre attivi):** mantengono la sessione quando accedi all'area riservata e ricordano le tue scelte sui cookie. Non richiedono consenso.
- **Statistiche (solo con il tuo consenso):** ci aiutano a capire quali pagine sono utili, in forma aggregata.
- **Marketing (solo con il tuo consenso):** servono a misurare l'efficacia delle comunicazioni.

Gli strumenti di statistica e marketing partono solo se li accetti. Se non li accetti, non vengono caricati.

## Come cambiare scelta
Puoi modificare o ritirare il consenso in ogni momento dal link «Preferenze cookie» in fondo a ogni pagina. Puoi anche cancellare i cookie dalle impostazioni del browser.

## Titolare e contatti
Il titolare è {titolare}. Per domande scrivi a {email}. Per saperne di più su come trattiamo i tuoi dati leggi l'informativa sulla privacy.
TXT
			,
			'termini' => <<<'TXT'
## Chi offre il servizio
Il servizio di licenze, aggiornamenti e area riservata di Associazione Semplice è offerto da {titolare} {indirizzo} {piva}. Per qualsiasi richiesta scrivi a {email}.

## Che cosa include
- **Licenza gratuita:** abilita i servizi collegati del plugin gratuito per un dominio. È gratuita, senza scadenza fissa, e si rinnova da sola finché usi il servizio.
- **Piani Pro (Pro Contabile e Pro Fiscale):** abilitano le funzioni del plugin Pro per un dominio, per il periodo pagato (un mese o un anno), con rinnovo automatico o pagamento singolo secondo la scelta fatta all'acquisto.
- **Area riservata:** per vedere la chiave, cambiare il dominio, scaricare i pacchetti, cambiare piano e disdire.

## Licenza e dominio
Ogni licenza vale per un solo dominio, compresi i suoi sottodomini e le sue sottocartelle (per esempio un sito di prova). Puoi cambiare dominio dall'area riservata, entro i limiti indicati nella pagina stessa. La licenza non si può rivendere né condividere con altre associazioni.

## Pagamenti, rinnovi e cambio piano
I pagamenti sono gestiti da Stripe e da PayPal. Il rinnovo automatico si può disdire in ogni momento dall'area riservata: la licenza resta valida fino alla scadenza già pagata. Passando a un piano superiore paghi la differenza in proporzione ai giorni che mancano alla scadenza; passando a uno inferiore il cambio vale dal prossimo rinnovo. Se un pagamento non va a buon fine, la licenza resta valida fino alla scadenza e poi il plugin torna alle funzioni di base.

## Recesso e rimborsi
Se acquisti come consumatore hai diritto di recesso entro 14 giorni dall'acquisto, scrivendo a {email}, salvo che tu abbia già iniziato a usare il servizio con il tuo consenso. Negli altri casi i periodi già pagati non si rimborsano, salvo errori nostri.

## Cosa succede alla scadenza
Alla scadenza il plugin Pro torna alle funzioni del gratuito. I dati dell'associazione restano sul tuo sito e non vengono cancellati: ci sono sempre, anche senza licenza.

## Uso corretto
Ti chiediamo di non aggirare il controllo della licenza, di non usare il servizio per danneggiare altri e di tenere riservata la tua chiave. Possiamo sospendere una licenza in caso di uso scorretto o di pagamenti contestati, dandone comunicazione.

## Responsabilità
Il plugin e il servizio sono forniti con la cura dovuta, ma non possiamo garantire che siano privi di errori o sempre raggiungibili. Ti consigliamo di fare copie di sicurezza (il plugin ha una funzione apposta). Nei limiti consentiti dalla legge non rispondiamo di danni indiretti o di dati non salvati.

## Dati personali
Come trattiamo i tuoi dati è spiegato nell'informativa sulla privacy.

## Legge applicabile
Si applica la legge italiana. Per i consumatori resta il foro del luogo di residenza.
TXT
			,
		);
	}
}

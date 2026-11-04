<?php
namespace Portale;

/** Contenuti e percorso del sito pubblico condivisi tra le pagine: un solo posto per funzioni, domande, tappe e fascia di chiusura. */
final class Site {

	/** Tappe del percorso: funzioni → demo → prezzi → licenza. */
	const STEPS = array(
		'/funzioni' => 'Funzioni',
		'/prova'    => 'Demo',
		'/prezzi'   => 'Prezzi',
		'/free'     => 'Licenza',
	);

	/** Pagine di presentazione: solo qui compaiono la fascia di chiusura e la barra in basso da telefono. */
	const MARKETING = array( '/', '/funzioni', '/prova', '/prezzi', '/confronto', '/domande', '/free', '/accedi', '/plugin' );

	public static function path(): string {
		$p = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		return '/' === $p ? '/' : rtrim( $p, '/' );
	}

	public static function isMarketing( string $path ): bool {
		return in_array( $path, self::MARKETING, true );
	}

	/** Descrizione per i motori di ricerca, una per pagina di presentazione (le altre usano quella generale). */
	public static function description( string $path ): string {
		$d = array(
			'/'          => 'Plugin WordPress per associazioni italiane: libro soci, tessere, eventi e corsi, prima nota e privacy. Gratuito, con piani Pro per pagamenti online e contabilità.',
			'/funzioni'  => 'Libro soci, eventi e corsi, prima nota, privacy e area riservata dei soci: quello che serve alla segreteria di un\'associazione, dentro il tuo sito WordPress.',
			'/prova'     => 'La demo di Associazione Semplice: un sito WordPress con il plugin e il Pro già installati e dati inventati. Entri come segreteria, socio o amministratore, senza installare nulla.',
			'/prezzi'    => 'Il plugin è gratuito; i piani Pro aggiungono pagamenti online, contabilità e parte fiscale. Rinnovo mensile o annuale, si disdice dall\'area riservata.',
			'/confronto' => 'Funzione per funzione, cosa include la versione gratuita e cosa aggiungono i piani Pro: soci, eventi, cassa, pagamenti online, contabilità e fiscalità.',
			'/domande'   => 'Le risposte alle domande più comuni su Associazione Semplice: licenza e dominio, passaggio al Pro, rinnovo, pagamenti e cosa succede ai tuoi dati.',
			'/free'      => 'Ottieni la licenza gratuita di Associazione Semplice: crei il tuo account e accedi al supporto. Non serve la carta di credito.',
			'/accedi'    => 'Area riservata di Associazione Semplice: chiave di licenza, dominio, pacchetti da scaricare, cambio di piano e disdetta.',
			'/plugin'    => 'Cos\'è Associazione Semplice, il plugin WordPress per le piccole associazioni italiane: cosa include il gratuito, cosa aggiunge il Pro e cosa contatta.',
		);
		return $d[ $path ] ?? 'Soci, eventi, prima nota e privacy per la tua associazione. Plugin WordPress gratuito, con piani Pro per pagamenti online e contabilità.';
	}

	/** Funzioni in vetrina: icona, titolo, testo (home e /funzioni). */
	public static function features(): array {
		return array(
			array( 'users', 'Libro soci in ordine', 'Soci, ospiti e figure diverse, con tessera, quote e storico. Importi l\'elenco che hai già da Excel o CSV.' ),
			array( 'calendar', 'Eventi e corsi', 'Iscrizioni, prenotazioni, lista d\'attesa e calendario. Chi si iscrive riceve avvisi e promemoria per email.' ),
			array( 'book', 'Prima nota', 'Entrate e uscite sempre al loro posto, con allegati e viste pronte per il bilancio.' ),
			array( 'shield', 'Strumenti per la privacy', 'Consensi, informativa da compilare con i dati della tua associazione, esportazione dei dati e anonimizzazione quando serve.' ),
			array( 'card', 'Area riservata per i soci', 'Ogni socio ha la sua pagina sul sito: tessera, pagamenti, ingressi. Nel menu del sito, non in wp-admin.' ),
			array( 'phone', 'Pagamenti e app', 'Con il Pro: quote e biglietti online con carta o PayPal, app installabile e tessera nel telefono.' ),
		);
	}

	/** Domande frequenti (tutte in /domande, le prime tre anche in home). */
	public static function faq(): array {
		return array(
			array( 'Devo pagare per usare Associazione Semplice?', 'No. Il plugin è gratuito e resta tale. La licenza gratuita è facoltativa: è legata al dominio del tuo sito e dà accesso al supporto e alle demo dei piani Pro. La ricevi appena confermi la tua email.' ),
			array( 'Cosa significa «licenza legata a un dominio»?', 'La chiave funziona sul sito per cui l\'hai richiesta. Se cambi indirizzo, lo aggiorni tu dall\'area riservata (fino a 3 volte ogni 30 giorni), senza scrivere a nessuno.' ),
			array( 'Posso passare dal gratuito al Pro più avanti?', 'Sì, in qualsiasi momento. I dati restano dove sono: si attivano soltanto le funzioni in più.' ),
			array( 'Come funziona il rinnovo?', 'Il rinnovo è automatico, mensile o annuale a scelta. Puoi disdire quando vuoi dall\'area riservata: la licenza resta valida fino alla scadenza già pagata.' ),
			array( 'Cosa succede se un pagamento non va a buon fine?', 'Hai alcuni giorni di tolleranza. I tuoi dati non vengono mai cancellati: se la licenza scade, tornano disponibili le funzioni di base.' ),
			array( 'Dove stanno i dati dei miei soci?', 'Sul tuo sito WordPress, non da noi. Il plugin gratuito non contatta nessun servizio; il Pro verifica la licenza una volta al giorno e controlla gli aggiornamenti inviando solo chiave, dominio e versione.' ),
			array( 'Cosa succede ai dati se disdico o la licenza scade?', 'Restano sul tuo sito. Alla scadenza il plugin torna alle funzioni del gratuito: i dati non vengono cancellati e si possono sempre esportare.' ),
			array( 'Come pago?','Con carta tramite Stripe oppure con PayPal. Non vediamo né conserviamo i dati della tua carta.' ),
		);
	}

	/**
	 * Fascia di chiusura di ogni pagina di presentazione: tappa corrente, invito, link principale e secondario.
	 * @return array{step:int,title:string,text:string,primary:array,secondary:array}|null
	 */
	public static function band( string $path ): ?array {
		$demo = array( 'Prova la demo', '/prova' );
		$plans = array( 'Vedi i prezzi', '/prezzi' );
		$free = array( 'Ottieni la licenza gratuita', '/free' );
		$map = array(
			'/'          => array( 0, 'Provala prima di decidere', 'Una demo già piena di dati inventati: entri come segreteria o come socio e clicchi dove vuoi.', $demo, $plans ),
			'/funzioni'  => array( 1, 'Prossimo passo: provala', 'Entra nella demo come segreteria o come socio: dati inventati, niente da installare.', $demo, array( 'Confronta le versioni', '/confronto' ) ),
			'/prova'     => array( 2, 'Prossimo passo: scegli il piano', 'Si parte gratis. I piani Pro servono per incassi online, ricevute e contabilità.', $plans, $free ),
			'/prezzi'    => array( 3, 'Prossimo passo: la licenza', 'Parti dalla licenza gratuita: arriva appena confermi la tua email e al Pro passi quando vuoi, senza reinstallare.', $free, array( 'Leggi le domande', '/domande' ) ),
			'/confronto' => array( 3, 'Prossimo passo: scegli il piano', 'Hai visto cosa cambia tra le versioni: ora i prezzi, con rinnovo mensile o annuale.', $plans, $free ),
			'/domande'   => array( 0, 'Vuoi vedere come funziona?', 'La demo risponde meglio di qualsiasi spiegazione: provala senza registrarti.', $demo, $plans ),
			'/free'      => array( 4, 'Vuoi guardarti intorno prima?', 'Puoi provare la demo e leggere i prezzi: la licenza gratuita ti aspetta quando vuoi.', $demo, $plans ),
			'/accedi'    => array( 0, 'Non hai ancora una licenza?', 'La licenza gratuita arriva appena confermi la tua email. Se vuoi prima vedere il programma, c\'è la demo.', $free, $demo ),
			'/plugin'    => array( 3, 'Pronto a installarlo?', 'Chiedi la licenza gratuita: incolli la chiave nel plugin e la procedura guidata fa il resto.', $free, $plans ),
		);
		if ( ! isset( $map[ $path ] ) ) {
			return null;
		}
		$m = $map[ $path ];
		return array( 'step' => $m[0], 'title' => $m[1], 'text' => $m[2], 'primary' => $m[3], 'secondary' => $m[4] );
	}

	/** Barra in basso da telefono: due scorciatoie, mai quella della pagina in cui sei. */
	public static function bar( string $path ): array {
		$demo = array( 'Prova la demo', '/prova' );
		$plans = array( 'Prezzi', '/prezzi' );
		$free = array( 'Licenza gratuita', '/free' );
		if ( '/prova' === $path ) {
			return array( $plans, $free );
		}
		if ( '/prezzi' === $path ) {
			return array( $free, $demo );
		}
		if ( '/free' === $path ) {
			return array( $demo, $plans );
		}
		return array( $demo, $plans );
	}

	/** Indirizzi fissi dei piani (non cambiano se il nome si modifica dal pannello): codice interno → slug. */
	const SLUGS = array( 'lite' => 'procontabile', 'full' => 'profiscale' );

	public static function slug( string $code ): string {
		return self::SLUGS[ $code ] ?? $code;
	}

	/** Slug (o codice) → codice interno del piano. */
	public static function code( string $slug ): string {
		$flip = array_flip( self::SLUGS );
		return $flip[ $slug ] ?? $slug;
	}

	/** Elenco pubblico dei piani per altri siti (supporto): slug fisso e nome commerciale del pannello (Prezzi → «Nome sul sito»). Nessun prezzo né dato riservato. */
	public static function publicPlans(): array {
		$out = array();
		foreach ( array( 'free' => 'Free', 'lite' => 'Pro Contabile', 'full' => 'Pro Fiscale' ) as $code => $fallback ) {
			$name  = trim( (string) Config::get( 'plans.' . $code . '.label', '' ) );
			$out[] = array( 'slug' => self::slug( $code ), 'nome' => '' !== $name ? $name : $fallback );
		}
		return array( 'piani' => $out );
	}

	/** Frase breve per ogni piano nei riepiloghi. */
	public static function blurb( string $code ): string {
		$b = array(
			'free' => 'Per partire subito: soci, eventi, prima nota e privacy. Senza costi e senza scadenza.',
			'lite' => 'In più incassi online, ricevute, più livelli di socio e comunicazioni ai soci.',
			'full' => 'In più IVA, 5 per mille e rendiconto per cassa, per la parte fiscale dell\'ente.',
		);
		return $b[ $code ] ?? '';
	}
}

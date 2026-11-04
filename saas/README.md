# Associazione Semplice SaaS

Gestionale online per associazioni, con pacchetti aggiuntivi: area soci, risorse e prenotazioni, sito generato
dall'AI, fattura elettronica. Il plugin WordPress resta un prodotto a sé: la differenza è dove stanno i dati e
l'assistenza.

**Stato:** fase 0 completata in locale e in CI; manca solo la messa su staging ([piano di lavoro](docs/PIANO_DI_LAVORO.md)). Ci sono lo scheletro Laravel 13, il database
con i due ruoli, la separazione tra associazioni, l'accesso con la verifica in due passaggi e la cifratura con il servizio chiavi e il
registro delle azioni, il lato SaaS del contratto con il portale e il rilascio con i backup, tutti con i loro test. Avvio in locale: [SVILUPPO.md](docs/SVILUPPO.md).

Priorità, in quest'ordine: **1. sicurezza dei dati personali e fiscali · 2. velocità**.

## Da dove partire

| Documento | Contenuto |
|---|---|
| [PIANO_DI_LAVORO.md](docs/PIANO_DI_LAVORO.md) | **Inizia da qui**: prerequisiti, fasi, primo traguardo |
| [REGOLE_DI_LAVORO.md](docs/REGOLE_DI_LAVORO.md) | Regole per chi sviluppa: sessioni, sicurezza, marchio, qualità |
| [SVILUPPO.md](docs/SVILUPPO.md) | Avvio in locale, ruoli del database, moduli, come si aggiunge una tabella |
| [RILASCIO.md](docs/RILASCIO.md) | Server, rilascio da GitHub Actions, cron e code, backup cifrati e ripristino |
| [CONTRATTO_PORTALE.md](docs/CONTRATTO_PORTALE.md) | Contratto con il portale: diritti firmati, consumi, accesso unico (per la sessione del portale) |
| [ARCHITETTURA.md](docs/ARCHITETTURA.md) | Portale e SaaS, moduli, domini, code, chiavi, ambienti, contratto con il portale |
| [DATABASE.md](docs/DATABASE.md) | Hosting verificato, schemi, separazione tra associazioni, cifratura, prima nota immutabile |
| [PORTING_DAL_PLUGIN.md](docs/PORTING_DAL_PLUGIN.md) | Cosa si porta dal plugin, modulo per modulo, con i test da riusare |
| [PERIMETRO.md](docs/PERIMETRO.md) | Concorrenza, funzioni al lancio, pacchetti, listino, partner, assistenza |
| [SITI.md](docs/SITI.md) | Builder dei siti: specifica AI, catalogo, profili per tipo di ente, obblighi di pubblicazione |

## Decisioni principali

| Tema | Decisione |
|---|---|
| Stack | Laravel 13, PHP 8.3, PostgreSQL 16, Inertia + Vue 3 + Tailwind 4 |
| Struttura | Monolite modulare in `saas/`; il portale resta il centro commerciale e invia i diritti firmati |
| Associazioni | Database condiviso con `org_id`, chiavi esterne composte e Row-Level Security; database dedicato a pagamento |
| Dati sensibili | Cifratura dei campi con `sodium`, chiave per associazione, chiave principale fuori dall'account del SaaS |
| Prima nota | Partita singola, immutabile; la partita doppia è un addon |
| Hosting iniziale | Sub-account del reseller su SupportHost; VPS quando si superano le soglie |
| Sito | L'AI compila una specifica JSON, un generatore nostro produce HTML statico |
| Fattura elettronica | XML generato da noi, trasmissione tramite intermediario con il portale come ponte |

## Documenti di riferimento fuori da questa cartella

Restano al loro posto perché descrivono prodotti che esistono già:

- [docs/PLUGIN_WORDPRESS.md](../docs/PLUGIN_WORDPRESS.md): tutte le funzioni e le regole del plugin;
- [docs/PORTALE.md](../docs/PORTALE.md): abbonamenti, licenze, accesso unico;
- [docs/LICENZE.md](../docs/LICENZE.md), [docs/AREA_RISERVATA.md](../docs/AREA_RISERVATA.md),
  [docs/VISIONE_E_STRUTTURA.md](../docs/VISIONE_E_STRUTTURA.md);
- [docs/brand/](../docs/brand/) e [loghi e brand/](../loghi%20e%20brand/): marchio, colori, carattere Montserrat.

# SaaS: piano di lavoro

Da dove si parte e in che ordine si costruisce. Le funzioni di ogni fase sono descritte in [PERIMETRO.md](PERIMETRO.md).

## 1. Prerequisiti

### Bloccano l'inizio dello sviluppo

Nessuno: la fase 0 si può iniziare in locale.

### Da chiudere prima dei dati veri dei clienti

| Cosa | Chi | Stato |
|---|---|---|
| DPA (art. 28) e sub-responsabili di SupportHost: il link ricevuto era un articolo di blog | Luca | richiesto, risposta da verificare |
| Conferma di PostgreSQL 16 sul server con `SELECT version();` | prima prova sul database | aggiornamento comunicato, non verificato |
| Database di prova `associazionesemp_associazionesemp_test` e utente `associazionesemp_retest`: la password è passata in chat, va cambiata o il database cancellato | Luca | da fare |
| Spazio esterno in UE per i dump orari cifrati | da scegliere | da fare |
| APCu disponibile sul piano (serve al servizio chiavi, [ARCHITETTURA.md](ARCHITETTURA.md) §7) | sessione del portale, via SSH | da verificare |
| Estensione PHP `redis` da attivare in cPanel | Luca | da fare |
| Sub-account di staging | Luca | da creare |
| DPA del SaaS verso i clienti, valutazione d'impatto (DPIA), informativa | consulente privacy | da fare |
| Penetration test esterno | fornitore da scegliere | prima del lancio |

### Da verificare con un commercialista

- Regole della fattura elettronica per i casi tipici e per il regime 398.
- Soglie di pubblicazione di compensi e bilancio sociale, contributi pubblici.
- Modelli di ricevuta e di rendiconto per cassa.

### Ancora da decidere

Promo, nome unico dei piani Pro, percentuale dei partner, scaglioni delle risorse giornaliere
([PERIMETRO.md](PERIMETRO.md) §8). Nessuno di questi blocca le fasi 0 e 1 dello sviluppo.

## 2. Fase 0: fondamenta

Ordine consigliato. Ogni passo si chiude con i suoi test.

1. **Scheletro**: Laravel 13 in `saas/`, moduli in `app/Modules/`, Pest, PHPStan al massimo, Pint, test di architettura,
   `.github/workflows/saas.yml`.
2. **Database**: Postgres 16 locale, schemi, due ruoli (proprietario e applicazione), migrazioni `core` e migrazioni
   delle associazioni, comando unico per tutti i database.
3. **Separazione tra associazioni**: individuazione dell'associazione, scelta della connessione, `app.org_id`, test
   automatici che provano a leggere e scrivere dati di un'altra associazione.
4. **Accesso**: utenti globali, ruoli, permessi dai dati, verifica in due passaggi obbligatoria per gli operatori.
5. **Cifratura**: chiavi per associazione, blind index, servizio chiavi, file allegati cifrati.
6. **Registro delle azioni**: solo in aggiunta, catena di hash, letture dei dati sanitari.
7. **Contratto con il portale**: diritti firmati, conteggi, accesso unico. Si definisce qui, lo sviluppa la sessione del
   portale ([ARCHITETTURA.md](ARCHITETTURA.md) §11).
8. **Rilascio**: staging, GitHub Actions, cartelle di release, cron e code, dump orari con prova di ripristino.

### Primo traguardo

Su staging, due associazioni di prova: un operatore di ciascuna accede con la verifica in due passaggi e registra soci e
movimenti. I test dimostrano che **nessuna delle due vede o modifica i dati dell'altra**, nemmeno con una query senza
filtro, e il codice fiscale è cifrato nel database. Il ripristino da un dump funziona.

## 3. Fase 1: lancio

**Gestionale base**, in quest'ordine: contabilità (prima nota, conti, ricevute, rendiconto, chiusura), soci e
tesseramento, attività come centri di costo, ruoli configurabili, import ed export, configurazione guidata.

**Pacchetti al lancio**: Area soci e pagamenti, Risorse e prenotazioni, Database dedicato.

Alla fine: pilota con 3–5 associazioni vere scelte tra chi usa il plugin, penetration test, demo SaaS.

## 4. Fasi successive

| Fase | Contenuto |
|---|---|
| 2 | Fattura elettronica (primo addon, con il ponte SDI del portale), poi Comunicazioni, Vita associativa, Sport, Contabilità avanzata |
| 3 | Sito, dominio personalizzato, landing di categoria |
| 4 | Biglietteria, registri, affiliazioni, convenzioni, wallet, 5×1000; per ultima la migrazione bidirezionale con il plugin |

Il **programma partner** vive nel portale e può procedere in parallelo, quando il portale è pronto.

## 5. Quando passare a un VPS

Il reseller ha limiti fissi: 2 core, 2 GB di RAM, 25 processi d'ingresso, I/O 20 MB/s. Si passa a VPS o semidedicato
quando succede una di queste cose in modo stabile:

- processi d'ingresso spesso saturi;
- CPU sopra il 70%;
- API con p95 oltre 300 ms;
- connessioni Postgres insufficienti.

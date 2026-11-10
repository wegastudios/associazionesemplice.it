# Licenze: una chiave, un dominio (e i suoi sottodomini)

**Stato: attiva.** Il plugin controlla la chiave col portale (`https://associazionesemplice.it/api/license/validate`), che la verifica con il server licenze Wega e restituisce la risposta di Wega invariata, attestazione firmata compresa. Nel plugin non c'è nessuna credenziale (`ASEM_LICENSE_TOKEN` non esiste più). Specifica di Wega: `https://license.wegastudios.com/docs/wega-license.pdf`; le chiavi si vendono e si gestiscono dal portale (`portale/`, vedi `docs/PORTALE.md`).

## Principio

- Il **plugin gratuito funziona sempre**, anche senza chiave: nessuna funzione di base dipende dalla licenza (regola di WordPress.org).
- La **chiave gratuita** (Free) è facoltativa e si chiede dal portale; abilita i servizi collegati (prova Pro, avvisi di aggiornamento).
- Con il **plugin Pro** le funzioni dipendono dal piano della chiave: Free (nessuna funzione Pro), **Pro Lite** (senza contabilità), **Pro Full** (tutto).
- Ogni chiave è legata a **un dominio registrato**: la licenza vale per quel dominio e per **tutti i suoi sottodomini e sottocartelle** (`staging.esempio.it`, `test.esempio.it`, `esempio.it/staging`); il portale riduce il dominio del sito al dominio registrabile (`LicenseRules::registrable_domain`, suffissi tipo `co.uk` compresi) prima di chiederlo a Wega, e il plugin confronta l'attestazione (`bid`) allo stesso modo (`LicenseRules::same_domain`).
  Il dominio si cambia dall'area riservata del portale: il cambio è libero, poi la chiave resta bloccata per 180 giorni (Impostazioni del portale → «Blocco del dominio»); con l'assistenza il personale lo cambia sempre. Il server Wega applica solo un limite di sicurezza (3 cambi ogni 30 giorni). I siti locali (`localhost`, `*.local`, `*.test`, indirizzi IP) non richiedono licenza.

## Livelli

| Piano (`plan_name` del server) | Livello nel plugin | Funzioni Pro |
|---|---|---|
| contiene «Free» | `free` | nessuna |
| contiene «Lite» | `lite` | tutte tranne contabilità (`Edition::ACCOUNTING`: conti e fondi, report, ricevute) e fiscali (`Edition::FISCAL`: IVA, 5 per mille, anni solari, rendiconto, controllo di bilancio e fondi documentati) |
| contiene «Full» o «Fiscale» | `fiscal` | tutte |
| «contabile» (vecchio livello) | `accounting` | tutte tranne le fiscali |
| altro (piano a pagamento non riconosciuto) | `lite` | come Lite |

Il nome del piano si sceglie nel pannello del portale (Piani e prezzi): deve contenere Free, Lite o Full. Il mapping è in `LicenseClient::plan_from_name()`.

**Chi decide il livello (dalla versione che porta la chiave del portale).** Wega custodisce il nome, le date e la chiave e dice solo se la licenza è valida per quel dominio; il significato del piano (che cosa sblocca «Free», «Pro Contabile»…) lo conosce solo il portale, dalla sua tabella piani. Il portale aggiunge alla risposta di verifica `portal_attestation`: un JWT EdDSA (iss `https://associazionesemplice.it`, aud = `product_code`, `bid` = dominio, `plan` ∈ `free|lite|accounting|fiscal`, `exp`) firmato con la sua chiave, la cui parte pubblica è scritta nel plugin (`LicenseClient::PINNED_PORTAL_KEYS`). Da quando c è la chiave, il plugin non legge più il nome del piano su Wega: il livello viene solo dalla prova del portale (una licenza valida senza prova non aggiorna lo stato; una prova salvata che non regge vale gratuito; subito dopo l aggiornamento del plugin, finché non ne è salvata una, vale per poco la parte firmata da Wega e un controllo immediato porta quella del portale). Se un piano cambia nome su Wega la licenza resta valida e il livello non cambia.

## Verifica

- Una volta al giorno (WP-Cron `asem_license_check`), al salvataggio della chiave e con «Controlla adesso» in Impostazioni → Licenza: `POST /api/license/validate` del portale con `license_key` e `domain`.
- **Nessuna credenziale nel plugin**: la verifica passa dal portale (`LicenseClient::API`), che parla con Wega. Le chiavi pubbliche per la firma (JWKS) si leggono dal portale (`LicenseClient::JWKS_URL`); l'emittente (`iss`) dell'attestazione resta quello di Wega. Sui siti locali la verifica non c'è e vale lo stato `standby` (tutto consentito).
- Risposta → stato (`LicenseClient::interpret()`): `valid` ⇒ `active` con livello e scadenza (`IN_GRACE` = ancora attiva, con invito a rinnovare);
  `EXPIRED` e `SUSPENDED` ⇒ `unpaid`; `REVOKED`, `DOMAIN_MISMATCH`, `LICENSE_NOT_FOUND`, `PENDING` ⇒ `unlicensed`.
- **Attestazione**: la risposta valida porta un JWT firmato EdDSA; il plugin ne verifica firma (chiavi pubbliche da `/.well-known/jwks.json`, 12 ore in cache), emittente, destinatario
  (`product_code`), scadenza e dominio (`bid`); se la firma non torna non si fida e resta allo stato precedente.
- **Errori di rete o del server**: lo stato precedente non cambia (una revoca non si annulla per un errore di rete, un errore di rete non toglie le funzioni); l'errore si mostra in Impostazioni.
- Il file è tutto in `LicenseClient` (parti pure, con test PHPUnit) e `License` (stato, `sync()`, cron).

## Licenza non in regola

Riguarda **solo le funzioni del plugin Pro** (`License::degraded()` richiede che il Pro sia presente): senza il Pro l'edizione gratuita non perde mai nulla.
Stati (`LicensePolicy`): `standby` · `nokey` (verifica attiva, nessuna chiave: valgono le funzioni di base) · `active` · `unpaid` · `unlicensed`.
Con `unpaid` e `unlicensed`: i dati restano leggibili, le funzioni Pro (pagamenti, conti, quote diverse, comunicazioni, app…) sono sospese e un avviso in cima alle pagine
del plugin spiega come regolarizzare (link al portale). Esportazione dei dati e accesso di soci e volontari restano sempre attivi.

## Cosa si vede

- **Impostazioni → Licenza**: chiave, livello e scadenza, dominio, ultimo errore, «Controlla adesso», collegamenti al portale (chiave gratuita con il dominio già compilato, gestione del piano).
- Avviso informativo (non bloccante) per chi non ha ancora la chiave gratuita e per le licenze scadute da pochi giorni.

## Avviso «ci sono aggiornamenti»

`UpdateCheck` (+ `Admin/UpdateNotice`). Con una chiave di licenza inserita (anche la gratuita) e fuori dai siti locali, un lavoro orario (`asem_update_check`) chiede
al portale `GET https://associazionesemplice.it/api/plugin/latest` (filtro `asem_update_api`) e salva l'esito nell'opzione `asem_update_latest`. Parte solo se sono passate
`update_check_hours` ore (default 24, 6–168). **Non si manda niente**: né chiave né dati del sito, la risposta è uguale per tutti.

- Risposta attesa (da realizzare nel portale): `{"free":{"version":"1.1.13","url":"https://associazionesemplice.it/scarica/free/1.1.13"},"pro":{"version":"1.1.5","url":"…"}}`.
  Le versioni devono essere numeri (`1.2`, `1.1.13`); l'indirizzo è accettato solo se `https`; voci malformate si ignorano. Se il portale non risponde l'avviso resta com'è.
- L'avviso (chiudibile 30 giorni, per versione) compare nelle pagine del plugin a chi ha il permesso di gestione: per il gratuito e, se presente, per il Pro. Rimanda all'area clienti.
- Si spegne da Impostazioni → Tecniche → Limiti e soglie (`update_notice`, `update_check_hours`, con spiegazione dei rischi).
- Readme: sezione «Update notice» tra i servizi esterni. Quando il gratuito sarà su WordPress.org gli aggiornamenti li dà WordPress: l'avviso resta utile solo per il Pro.

## Aggiornamento diretto da WordPress

`Updater` (solo nel pacchetto scaricato dal portale; elencato in `tests/portal-files.txt`, quindi **assente** nel pacchetto per WordPress.org, dove aggiorna WordPress.org): ogni 12 ore o più (`update_check_hours`) chiede al portale `POST /api/plugin/update` con `{"product":"free"|"pro","version","domain"}` (il Pro aggiunge `license_key`) e mette la nuova versione tra gli aggiornamenti di WordPress (Bacheca → Aggiornamenti, pagina Plugin, «Visualizza i dettagli»). Il pacchetto firmato dal portale vale pochi minuti: nell'elenco c'è un segnaposto (`asem-portal://free|pro`) e l'indirizzo vero si chiede di nuovo al clic su «Aggiorna» (`upgrader_pre_download`); si scarica solo da un indirizzo https dello stesso sito del portale. Errori, 403 (licenza Pro non in regola) e 429 non cambiano nulla: restano le ultime versioni note. Si spegne con il limite `portal_updates`. `build.sh` produce tre zip: gratuito del portale, gratuito per WordPress.org (senza `Updater`), Pro.

## Da fare

1. (fatto) Verifica tramite il portale, senza credenziali nel plugin.
2. Endpoint `GET /api/plugin/latest` nel portale (il plugin lo usa già) e attivazione della prova Pro dal plugin.
3. Se serve ritirare il limite di 2 installazioni dello schema precedente: la regola è ora quella del server (un dominio per chiave); `LicenseRules` mantiene solo le utilità sui domini.

## Operazioni per l'area clienti (`Portale\Plugin\CustomerApi`)

La nuova area clienti (`/account`, portale-vendita) non interroga le tabelle delle licenze: chiama solo questa classe, che controlla ogni volta che la licenza sia del cliente autenticato.

| Operazione | Metodo | Note |
|---|---|---|
| `license.sites` | `sites($cliente)` | domini, piano (slug), stato, scadenza, quando si potrà cambiare dominio, prodotti scaricabili. Mai la chiave |
| `domain.check` | `domainCheck($cliente,$licenza,$dominio)` | prova senza modificare: valido, libero, non in blocco |
| `domain.set` | `domainSet(...)` | usa `Billing::changeDomain` (primo cambio libero, poi blocco `domain_lock_days`, limite del server licenze) |
| `download.link` | `downloadLink($cliente,$licenza,$prodotto,[$versione])` | collegamento firmato **5 minuti** su `/wordpress/scarica?t=…`, legato alla licenza; il diritto si ricontrolla allo scarico |

Regola di scarico: licenza in regola = qualsiasi versione conservata del prodotto (il Pro solo con piano diverso dal gratuito); licenza scaduta o con pagamento non riuscito = **solo l'ultima versione uscita prima della scadenza**. Limite giornaliero `download_day` per cliente, come sempre.

Non ancora fatto: l'aggancio dal negozio alla notifica di abbonamento (lo fa portale-vendita). Il cambio di dominio NON ha un numero di cambi all'anno nel catalogo: vale la regola esistente (`Billing::domainUnlockAt`, impostazione `domain_lock_days` di `/wordpress/admin/impostazioni`: primo cambio libero, poi blocco di N giorni, il personale può sempre cambiare); `domain.check` e `domain.set` usano la stessa regola. Livelli di assistenza del catalogo: `free`, `paid`, `top`.

## Notifica di abbonamento (negozio → plugin)

`Portale\Plugin\SubscriptionNotice::apply($db, $billing, $catalogo, $messaggio)` sostituisce i prezzi e i diritti calcolati nel negozio: il negozio comunica solo il fatto commerciale, il plugin ricava il resto dal proprio catalogo (ARCHITETTURA_DOMINI §5). Chiamata in processo (stessa applicazione).

| Campo | Significato |
|---|---|
| `ref` | id della licenza |
| `rev` | numero crescente per licenza: un messaggio con `rev` ≤ all'ultimo applicato è ignorato (`stale`), così le consegne ripetute o fuori ordine non fanno danni |
| `plan`, `plan_version` | piano (slug del catalogo) e sua versione; se il catalogo non li conosce: errore `unknown_plan` e **nessuna modifica** |
| `until` | scadenza `YYYY-MM-DD` (fino alle 23:59:59 UTC) |
| `sites` | siti ammessi, ≥ 1 (oggi registrato in `subscriptions.sites_allowed`, non ancora imposto: una licenza resta legata a un dominio) |

Ordine: prima il server licenze (nome del piano e scadenza), poi il database locale; se il server licenze non risponde l'errore sale e il negozio ritenta. Nuove colonne in `subscriptions`: `plan_version`, `sites_allowed`, `notice_rev`. Il negozio la chiamerà al posto del calcolo dei prezzi: l'aggancio lo fa portale-vendita.

## Downgrade bloccato dai limiti

Decisione 10/11/2026: downgrade e disdetta a **fine periodo** (già così: `PlanChange::scheduleDowngrade`), e il downgrade è **bloccato se l'uso supera i limiti** del piano di arrivo. Per il plugin il limite del catalogo è «siti ammessi» (`limits.sites`, si imposta in `/wordpress/admin/catalogo`): `Plugin\Downgrade::blockers()` conta le licenze **attive** (non scadute, non annullate) del cliente con il piano attuale; se sono più dei siti ammessi dal piano di arrivo, la programmazione del passaggio viene rifiutata con un messaggio che dice quanti siti ci sono e quanti ne ammette il nuovo piano (e `PlanChange::options()` lo riporta nel campo `blocked` delle opzioni di downgrade). Un piano di arrivo senza limite di siti non blocca mai. Il blocco guarda il catalogo **pubblicato**: finché non si pubblica un limite, il comportamento è quello di prima.

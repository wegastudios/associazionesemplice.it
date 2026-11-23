# Tempi massimi sul database

Una richiesta che aspetta un blocco o un'istruzione che non finisce non devono tenere per sempre un processo PHP e una
connessione. Per questo ogni connessione dell'associazione ha tre tempi massimi.

## Dove si impostano

`OrgContext::enter()` (`app/Modules/Core/Org/DatabaseOrgContext.php`) li imposta nella stessa istruzione che imposta
`app.org_id`, quindi senza giri di rete in più. I valori stanno in `config/saas.php`, alla voce `database_timeouts`.

| Profilo | `lock_timeout` | `statement_timeout` | `idle_in_transaction_session_timeout` | Chi lo usa |
|---|---|---|---|---|
| `web` | 5 s | 30 s | 60 s | ogni richiesta del gestionale e dell'area soci |
| `long` | 30 s | 900 s | 300 s | comandi e code (tutto il processo), rotte con il middleware `db.long` |

Rotte con `db.long`:
- copia di sicurezza completa;
- archivio delle ricevute (archivia, rimetti, ricostruisci);
- libro soci (PDF e CSV);
- esportazione dei soci e della prima nota;
- importazione dei soci e della prima nota;
- rendiconto;
- verifica delle ricevute (pagina, «fissa», «rifai»);
- chiusura dell'anno fiscale.

Una nuova pagina lunga riceve `->middleware('db.long')` nella sua rotta. Dentro un servizio si usa
`OrgContext::withLongTimeouts()`.

## Quando un tempo scade

Gli SQLSTATE `55P03` (blocco), `57014` (istruzione) e `25P03` (transazione ferma) si gestiscono in un solo punto:
`app/Modules/Core/Http/DatabaseBusy.php`, registrato in `bootstrap/app.php`.

| Tipo di richiesta | Risposta |
|---|---|
| Pagina Inertia | si torna alla pagina, con l'avviso in `errors.ledger` |
| JSON | 503, con `Retry-After: 5` |
| Pagina normale | 503, con la pagina «Riprova tra poco» |

Il testo a schermo è in `DatabaseBusy::MESSAGE`. Nel log si scrivono il codice, la connessione e il testo della query
con i segnaposto, mai i valori.

## Cosa resta fuori

- **Richieste senza associazione.** Accesso, portale, amministrazione e staff non entrano in un'associazione e quindi
  non hanno questi tempi.
- **Valori predefiniti del ruolo.** La via per coprire anche quelle richieste sarebbe dare valori predefiniti al ruolo
  dell'applicazione (`ALTER ROLE ... SET lock_timeout ...`). Non si può fare: l'hosting nega il permesso di modificare
  i ruoli.
- **Migrazioni e backup.** Usano le connessioni del proprietario, di sola lettura e di ripristino, che non passano da
  `enter()`, e hanno regole proprie.

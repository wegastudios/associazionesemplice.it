# SaaS: regole di lavoro

Valgono per chiunque sviluppi in `saas/`, persone e sessioni di Claude. Il file si chiama così perché `CLAUDE.md` è
escluso da git in questo repository.

## Chi fa cosa

| Parte | Dove | Chi la modifica |
|---|---|---|
| SaaS | `saas/` | sessione del SaaS |
| Portale, `deploy.sh`, listino, licenze, partner, ponte SDI | `portale/` | **solo** la sessione Portale e pagamenti: le si chiede |
| Plugin, versioni, zip, rilasci | `wordpress/` | **solo** la sessione «wordpress plugin free» |
| Sito di supporto, guide | `supporto/` | sessione del supporto |
| Demo del plugin | `demo/` | non si tocca dal SaaS; mai azzerarla senza chiedere |

Il codice del plugin si **legge** e si copia in `saas/`, non si modifica da qui. Accesso SSH al server: ce l'ha la
sessione del portale.

## Marchio

Tutto ciò che il cliente vede è **Associazione Semplice**. Wega Studios non compare, salvo dove la legge impone di
indicare chi vende (per esempio sulle fatture). Colori, logo e carattere sono in `docs/brand/` e `loghi e brand/`.

## Sicurezza

- I dati personali dei soci **non arrivano mai all'AI**.
- Nessun segreto nel repository, nei log o in chat: password, chiavi e token solo in file d'ambiente fuori dalla
  cartella pubblica.
- Ogni tabella di `app`, `audit` e `sites` ha `org_id`, chiavi esterne composte e Row-Level Security forzata.
- Il ruolo dell'applicazione non è proprietario delle tabelle e non ha `BYPASSRLS`.
- Ogni nuova tabella ha il suo test di separazione tra associazioni.
- I campi sensibili si cifrano prima del salvataggio ([DATABASE.md](DATABASE.md) §5).
- La prima nota non si modifica e non si cancella: si storna.
- I dati di esempio sono finti: mai dati veri di soci in sviluppo, staging o demo.

## Qualità

- Test con Pest, PHPStan al livello massimo, Pint e controllo delle dipendenze: tutto verde prima di unire
  (`composer check` in locale, `.github/workflows/saas.yml` su GitHub).
- I test girano su PostgreSQL vero, mai su SQLite: la Row-Level Security va provata davvero.
- Le regole portate dal plugin arrivano insieme ai loro test.
- Importi in centesimi interi. Istanti in `timestamptz`, date contabili in `date`.
- Ogni modifica dello schema passa da una migrazione, in due tempi: prima si aggiunge il nuovo, poi si toglie il vecchio.
- I moduli comunicano solo dalle interfacce pubbliche (namespace `Contracts` di ogni modulo; c'è il test).
- Ogni richiesta e ogni lavoro in coda impostano `app.org_id`.

## Server

- Da cron si usa `/usr/local/bin/php`: `php` da solo è la versione CGI.
- Sul server non c'è Node: CSS e JavaScript si compilano su GitHub Actions.
- Redis ha 64 MB e rifiuta le scritture quando è pieno: solo cache e limiti di frequenza, sempre con scadenza.
- Creando database via cPanel UAPI, l'utente deve avere un **nome diverso dal database**.
- Nel pannello, phpPgAdmin mostra solo l'esito dell'ultima istruzione: per le verifiche è meglio `psql` via SSH.

## Documenti

Una decisione nuova si scrive nel documento giusto di `saas/docs/` nella stessa modifica che la introduce. Ogni rilascio
del plugin continua ad aggiornare guide e tabella di confronto; quando il SaaS sarà in vendita, la stessa regola varrà
per lui.

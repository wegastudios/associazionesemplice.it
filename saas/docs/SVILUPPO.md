# SaaS: sviluppo in locale e convenzioni del codice

Come si avvia il progetto e dove si mette il codice. Le regole generali sono in [REGOLE_DI_LAVORO.md](REGOLE_DI_LAVORO.md).

## 1. Avvio

Servono PHP 8.3 (estensioni `pdo_pgsql`, `sodium`, `intl`, `mbstring`), Composer e PostgreSQL 16.

```bash
cd saas
sudo -u postgres scripts/db-locale.sh   # ruoli as_owner e as_app, database as_central, as_test, as_test_dedicato
composer install
cp .env.example .env && php artisan key:generate
php artisan saas:migrate                 # migrazioni su tutti i database
composer check                           # Pint, PHPStan al massimo, Pest
```

Le password dello script sono di sviluppo e valgono solo in locale e in CI. I test usano `as_test` e `as_test_dedicato`
(`phpunit.xml`) e girano su PostgreSQL vero: la Row-Level Security non si può provare con SQLite.

## 2. Due ruoli, due connessioni

| Connessione | Ruolo | Uso |
|---|---|---|
| `pgsql` | `as_app` (`DB_USERNAME`) | richieste web e code; soggetto alla RLS, niente superuser, niente `BYPASSRLS`, niente `DELETE` dove non serve |
| `pgsql_owner` | `as_owner` (`DB_OWNER_USERNAME`) | solo `php artisan saas:migrate` |

Sul server i due ruoli sono due utenti PostgreSQL creati da cPanel; il proprietario va reso proprietario del
database. Il ruolo dell'applicazione non legge nemmeno la tabella `migrations`.

## 3. Moduli

```
app/Modules/<Modulo>/
  Contracts/                   interfaccia pubblica: l'unica parte che gli altri moduli possono usare
  Models/  Http/  Console/  Providers/  …
  Database/Migrations/Central/ solo database centrale (schema core)
  Database/Migrations/Org/     tutti i database: condiviso e dedicati (schemi app, audit, sites)
```

Un test di architettura fallisce se un modulo usa qualcosa di un altro modulo fuori da `Contracts`.

## 4. Associazione corrente

- `Core\Contracts\OrgContext` è l'unico punto che imposta `app.org_id`: `enter()`, `leave()`, `run()`.
- La middleware `ResetOrgContext` è la prima di ogni richiesta: parte senza associazione e la azzera alla fine.
- I lavori in coda implementano `OrgScopedJob` e usano la middleware `RunInOrgContext`; il loro payload contiene solo
  identificativi. La coda sta nel database centrale.
- I modelli con i dati di un'associazione estendono `Core\Contracts\OrgModel`: connessione dell'associazione, filtro
  su `org_id`, `org_id` assegnato in automatico. Senza associazione impostata lanciano `NoOrgContext`.

## 5. Nuova tabella di un'associazione

Ogni tabella di `app`, `audit` e `sites`:

1. ha `org_id` in testa alla chiave primaria (`PRIMARY KEY (org_id, id)`) e `id` UUIDv7;
2. punta ad `app.orgs` con `org_id` e alle altre tabelle con chiavi esterne composte `(org_id, …)`, ciascuna con il suo
   indice;
3. chiama `OrgSchema::isolate('app.tabella')` e `OrgSchema::grant('app.tabella', [...])` con i soli permessi necessari.

I test in `tests/Feature/Core/SchemaRulesTest.php` controllano queste regole su tutte le tabelle presenti nel database:
una tabella nuova che non le rispetta li fa fallire. Ogni tabella aggiunge comunque il suo test di separazione.

## 6. Database dedicati

L'elenco è un file JSON fuori dal repository e fuori dalla cartella pubblica (`SAAS_DEDICATED_DB_FILE`), con il nome del
database e, se si usano ruoli separati, le loro credenziali (formato in `Core\Database\DatabaseRegistry`). In
`core.orgs.database` si scrive il nome del dedicato; `null` vuol dire database condiviso. `saas:migrate` li migra tutti.

## 7. Composer dal cloud di Claude

Nelle sessioni cloud il proxy blocca gli zip di GitHub: Composer ripiega sui sorgenti git, più lento. `phpstan/phpstan`
esiste solo come zip; si scarica il suo repository con git e si mette l'archivio nella cache di Composer
(`~/.cache/composer/files/phpstan/phpstan/<sha1 dell'URL>.zip`). In locale e in GitHub Actions non serve.

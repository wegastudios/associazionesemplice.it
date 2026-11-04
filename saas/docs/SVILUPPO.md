# SaaS: sviluppo in locale e convenzioni del codice

Come si avvia il progetto e dove si mette il codice. Le regole generali sono in [REGOLE_DI_LAVORO.md](REGOLE_DI_LAVORO.md).

## 1. Avvio

Servono PHP 8.3 (estensioni `pdo_pgsql`, `sodium`, `intl`, `mbstring`), Composer e PostgreSQL 16.

```bash
cd saas
sudo -u postgres scripts/db-locale.sh   # ruoli as_owner e as_app, database as_central, as_test, as_test_dedicato
composer install
cp .env.example .env && php artisan key:generate
php artisan saas:keys:generate           # copia la chiave principale in SAAS_MASTER_KEY
php artisan saas:migrate                 # migrazioni su tutti i database
npm install && npm run build             # CSS e JavaScript (Vue, Tailwind)
composer check                           # Pint, PHPStan al massimo, Pest
php artisan saas:org:create "Polisportiva Prova" polisportiva-prova tu@example.com "Nome"   # associazione di prova
php artisan serve
```

L'invito al titolare finisce nel log (`MAIL_MAILER=log`, `storage/logs/laravel.log`): il link porta alla scelta
della password. In locale su http serve `SESSION_SECURE_COOKIE=false`.

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

## 5. Accesso

| Cosa | Dove |
|---|---|
| Accesso, password, verifica in due passaggi | Laravel Fortify, pagine Inertia in `resources/js/pages/Auth` e `Account`; configurazione in `config/fortify.php` |
| Indirizzi | `/accesso`, `/esci`, `/password-dimenticata`, `/reimposta-password`, `/conferma-password`, `/verifica-accesso`, `/account/verifica-in-due-passaggi` |
| Ruoli | `Core\Contracts\OrgRole`: titolare, amministratore, segreteria, commercialista (`core.org_users`) |
| Permessi | `Core\Contracts\Ability`, regola pura `Ability::allows()`; nelle rotte `can:people.edit`, nel codice `Gate::allows(...)` |
| Inviti | `Core\Access\OrgMembers` (`invite`, `changeRole`, `remove`) e i comandi `saas:org:create`, `saas:operator:invite` |

Regole:

- **niente registrazione pubblica**: gli utenti nascono dal portale o da un invito;
- **verifica in due passaggi obbligatoria per chiunque sia operatore**, anche di un'associazione sospesa: senza, il
  gestionale manda alla pagina di attivazione; non si può disattivare né sostituire il segreto confermato;
- l'associazione scelta sta in sessione, ma **ruolo e stato dell'associazione si ricontrollano a ogni richiesta**
  (`IdentifyOperatorOrg`): un ruolo tolto vale subito; un'associazione non propria risponde 404;
- password di almeno 12 caratteri, in produzione anche il controllo sulle violazioni note; 5 tentativi al minuto per
  email e indirizzo, 5 per il codice; conferma della password valida 15 minuti;
- «Password dimenticata» risponde allo stesso modo che l'email esista o no;
- sessione cifrata, cookie solo HTTPS, Content-Security-Policy senza script esterni, pagine con dati non in cache,
  cronologia di Inertia cifrata, strumenti di sviluppo di Inertia spenti.

I permessi dei soci sui propri dati (area soci) non passano dai ruoli: derivano dai dati, come `Access` nel plugin, e
arrivano con il modulo Soci. I ruoli configurabili per segretari e consiglieri sono della fase 1.

## 6. Cifratura

Modulo `Cifratura`; regole in [DATABASE.md](DATABASE.md) §5, chiavi in [ARCHITETTURA.md](ARCHITETTURA.md) §7.

```php
// Modello: l'attributo tax_code vive nelle colonne tax_code_enc e tax_code_bidx (bytea).
protected function casts(): array
{
    return [
        'tax_code'   => Encrypted::class.':index=taxcode', // con indice di ricerca (taxcode, iban, phone, text)
        'birth_date' => Encrypted::class.':date',
        'address'    => Encrypted::class.':json',
        'phone'      => Encrypted::class,
    ];
}

Person::whereEncrypted('tax_code', 'rss mra 80a01 h501u');   // ricerca esatta, normalizzata

// File: cifrati sul disco org_files; nel database si salvano i campi di StoredFile.
$stored = app(EncryptedFiles::class)->store($orgId, $upload->getRealPath(), $upload->getClientOriginalName(), $mime);
return app(EncryptedFiles::class)->download($orgId, $stored);  // dopo il controllo dei permessi
```

- I campi `*_enc` e `*_bidx` vanno in `$hidden` del modello.
- Un campo cifrato si legge solo dentro la sua associazione (OrgContext): altrimenti errore.
- Il servizio chiavi ha la sua cartella, `keyservice/`, senza dipendenze: si copia sull'altro account con
  `config.ini` fuori dalla cartella pubblica ([keyservice/README.md](../keyservice/README.md)).

## 7. Registro delle azioni

Modulo `Audit`; regole in [DATABASE.md](DATABASE.md) §7.

```php
final class Person extends OrgModel
{
    use Audited;   // creazione, modifica, cancellazione e ripristino, con i nomi dei campi
}

app(AuditLog::class)->record('export.done', 'app.people', null, ['rows' => 120, 'format' => 'csv']);
```

- Si scrive solo dentro un'associazione (OrgContext), nella stessa transazione della modifica.
- Azioni nel formato `oggetto.azione` (`person.updated`, `operator.invited`, `health.read`).
- I campi sanitari si marcano `Encrypted::class.':health'`: le letture finiscono nel registro da sole.
- Cron: `saas:audit:partitions` il primo del mese, `saas:audit:verify` ogni notte (già nello scheduler).

## 8. Pacchetti e abbonamento

Diritti dal portale ([CONTRATTO_PORTALE.md](CONTRATTO_PORTALE.md)), letti con `Portale\Contracts\Entitlements`:

```php
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area'])->group(...);

$e = app(Entitlements::class)->for($orgId);
$e->has(Package::Resources);                 // pacchetto attivo?
$e->limit(Package::Resources, 'hourly.tier'); // limite del pacchetto
```

Le associazioni create a mano (pilota, `saas:org:create`) non hanno abbonamento sul portale: niente limite di
operatori e nessun pacchetto finché non ne ricevono.

## 9. Nuova tabella di un'associazione

Ogni tabella di `app`, `audit` e `sites`:

1. ha `org_id` in testa alla chiave primaria (`PRIMARY KEY (org_id, id)`) e `id` UUIDv7;
2. punta ad `app.orgs` con `org_id` e alle altre tabelle con chiavi esterne composte `(org_id, …)`, ciascuna con il suo
   indice;
3. chiama `OrgSchema::isolate('app.tabella')` e `OrgSchema::grant('app.tabella', [...])` con i soli permessi necessari.

I test in `tests/Feature/Core/SchemaRulesTest.php` controllano queste regole su tutte le tabelle presenti nel database:
una tabella nuova che non le rispetta li fa fallire. Ogni tabella aggiunge comunque il suo test di separazione.

## 10. Database dedicati

L'elenco è un file JSON fuori dal repository e fuori dalla cartella pubblica (`SAAS_DEDICATED_DB_FILE`), con il nome del
database e, se si usano ruoli separati, le loro credenziali (formato in `Core\Database\DatabaseRegistry`). In
`core.orgs.database` si scrive il nome del dedicato; `null` vuol dire database condiviso. `saas:migrate` li migra tutti.

## 11. Composer dal cloud di Claude

Nelle sessioni cloud il proxy blocca gli zip di GitHub: Composer ripiega sui sorgenti git, più lento. `phpstan/phpstan`
esiste solo come zip; si scarica il suo repository con git e si mette l'archivio nella cache di Composer
(`~/.cache/composer/files/phpstan/phpstan/<sha1 dell'URL>.zip`). In locale e in GitHub Actions non serve.

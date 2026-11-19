# Associazione Semplice · Supporto

Sito di supporto clienti di Associazione Semplice (https://supporto.associazionesemplice.it): centro assistenza, ticket e pannello dello staff, con marchio e colori di Associazione Semplice. Parte da una copia del codice Laravel di WegaSupport (ruolo `site`) e da qui in poi si evolve per conto proprio: nessun codice o dato è condiviso con altri portali.

- Installato sul server di Associazione Semplice in `~/wega-support` (nome della cartella storico; il sottodominio punta a `public/`). `.env` solo sul server, mai nel repository.
- Marchio: `resources/brand/icon.svg`, colori in `resources/views/partials/brand.blade.php`.
- Aggiornare il codice: caricare i file, poi `composer install --no-dev -o`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache` (PHP 8.4: `/opt/alt/php84/usr/bin/php`).
- Il lavoro sul collegamento con il portale (accesso unico, ruoli, ticket del cliente) è descritto in `docs/PORTALE.md` alla radice del repository.

## Rilascio sul server

Si rilascia solo con `scripts/deploy.sh` (da `supporto/`, con `SUPPORTO_DEPLOY_KEY` che punta alla chiave SSH; `--seed-guide` rilancia anche il seeder delle guide), dopo che le prove della PR sono verdi e la PR è unita.

- **`public/` non si copia mai.** Il suo `.htaccess` sul server contiene il blocco di cPanel con il gestore PHP 8.4: se lo si sovrascrive il sito gira con PHP 8.3 e risponde 500. Lo script copia solo `app bootstrap config database resources routes`. Il `.htaccess` del repository resta quello standard di Laravel e non va allineato a quello del server: è cPanel che lo scrive.
- Lo script salva il `.htaccess` del server in `storage/app/htaccess.prima-deploy-…`, aggiorna il codice, esegue `migrate`, svuota le cache e controlla `/up` e la home. Se una delle due non risponde 200 rimette da solo il `.htaccess` salvato, ricontrolla ed esce con errore.
- Se il sito risponde 500 con «Composer detected issues… PHP >= 8.4.1», il gestore PHP è andato perso: rimettere il `.htaccess` salvato, oppure reimpostare la versione con `uapi LangPHP php_set_vhost_versions version=alt-php84 vhost-0=supporto.associazionesemplice.it`.
- Le prove girano da sole su ogni PR che tocca `supporto/` (`.github/workflows/supporto.yml`).

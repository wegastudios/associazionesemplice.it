# Associazione Semplice · Supporto

Sito di supporto clienti di Associazione Semplice (https://supporto.associazionesemplice.it): centro assistenza, ticket e pannello dello staff, con marchio e colori di Associazione Semplice. Parte da una copia del codice Laravel di WegaSupport (ruolo `site`) e da qui in poi si evolve per conto proprio: nessun codice o dato è condiviso con altri portali.

- Installato sul server di Associazione Semplice in `~/wega-support` (nome della cartella storico; il sottodominio punta a `public/`). `.env` solo sul server, mai nel repository.
- Marchio: `resources/brand/icon.svg`, colori in `resources/views/partials/brand.blade.php`.
- Aggiornare il codice: caricare i file, poi `composer install --no-dev -o`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache` (PHP 8.4: `/opt/alt/php84/usr/bin/php`).
- Il lavoro sul collegamento con il portale (accesso unico, ruoli, ticket del cliente) è descritto in `docs/PORTALE.md` alla radice del repository.

Attenzione agli aggiornamenti: non sovrascrivere `public/.htaccess` sul server. cPanel vi scrive la versione di PHP (8.4); se si sovrascrive, il sito va in errore 500 finché non si reimposta con `uapi LangPHP php_set_vhost_versions version=alt-php84 vhost-0=supporto.associazionesemplice.it`.

#!/usr/bin/env bash
# Azzera la demo pubblica (associazionesemplice.it/demo): database pulito, plugin attivi, dati inventati di nuovo.
# Da cron ogni notte alle 4. Nessun utente amministratore resta: quello d'installazione si toglie alla fine.
set -euo pipefail
cd "$HOME/public_html/demo"
wp db reset --yes >/dev/null
wp core install --url=https://associazionesemplice.it/demo --title="Demo di Associazione Semplice" --admin_user=setup_tmp --admin_password="$(openssl rand -hex 16)" --admin_email=info@associazionesemplice.it --skip-email >/dev/null
wp option update blog_public 0 >/dev/null
wp site switch-language it_IT >/dev/null 2>&1 || true
wp option update timezone_string Europe/Rome >/dev/null
wp rewrite structure "/%postname%/" >/dev/null
wp plugin delete akismet hello >/dev/null 2>&1 || true
bash "$HOME/demo-provision/update.sh"
wp plugin activate associazionesemplice associazionesemplice-pro
wp eval-file "$HOME/demo-provision/provision.php" --user=setup_tmp # come amministratore d'installazione: il plugin lascia collegare a un socio un utente con ruolo solo a un amministratore
# Le pagine create dall'utente d'installazione passano all'amministratore del plugin prima di toglierlo (senza questo si cancellerebbero con lui).
wp user delete setup_tmp --yes --reassign="$(wp user get amministratore --field=ID)" >/dev/null
find wp-content/uploads -mindepth 1 -delete 2>/dev/null || true
wp cache flush >/dev/null 2>&1 || true
echo "$(date -Is) demo azzerata: $(wp user list --format=count) utenti"

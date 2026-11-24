#!/usr/bin/env bash
# LiteSpeed Cache nella demo pubblica (associazionesemplice.it/demo), voluto dal titolare per provare il plugin con una cache vera.
# Lo lancia update.sh (quindi anche reset.sh): installa il plugin dalla directory ufficiale di WordPress.org se manca, lo attiva e rimette SEMPRE le stesse
# impostazioni prudenti, che stanno qui nel file e non solo nel database (un azzeramento cancella il database, non il file).
# Prudenza: cache solo per i visitatori non loggati; niente ESI, niente ottimizzazione di CSS/JS/HTML/immagini, niente QUIC.cloud (nessuna registrazione né chiave),
# niente cache per l'area dei soci, le pagine con moduli, i pagamenti, le richieste del plugin e i cookie di sessione (asem_s, asem_ps).
# Idempotente: si può rilanciare quando si vuole. Non tocca l'.htaccess del portale (il plugin scrive solo nella cartella della demo).
set -uo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"
[ -f "$HOME/demo-provision/demo.env" ] && . "$HOME/demo-provision/demo.env" # facoltativo (vedi update.sh)
cd "${ASEM_DEMO_DIR:-${DEMO_DIR:-$HOME/public_html/demo}}"
SLUG=litespeed-cache
wp plugin is-installed "$SLUG" 2>/dev/null || wp plugin install "$SLUG" >/dev/null || { echo "LiteSpeed Cache: installazione non riuscita." >&2; exit 1; }
wp plugin is-active "$SLUG" 2>/dev/null || wp plugin activate "$SLUG" >/dev/null || { echo "LiteSpeed Cache: attivazione non riuscita." >&2; exit 1; }
set_opt() { wp litespeed-option set "$1" "$2" >/dev/null 2>&1 || echo "LiteSpeed Cache: impostazione $1 non riuscita." >&2; }

set_opt cache true
set_opt cache-priv false          # niente cache privata per chi è loggato
set_opt cache-commenter false
set_opt cache-rest false
set_opt cache-ttl_pub 3600        # un'ora: le novità della demo si vedono presto
set_opt esi false
set_opt debug false
set_opt crawler false
set_opt cdn false
set_opt media-lazy false
set_opt img_optm-auto false
set_opt optm-css_min false
set_opt optm-css_comb false
set_opt optm-ucss false
set_opt optm-css_async false
set_opt optm-js_min false
set_opt optm-js_comb false
set_opt optm-html_min false

# Cookie di sessione del plugin: il socio non ha wordpress_logged_in_*, quindi vanno esclusi a mano.
set_opt cache-exc_cookies $'asem_s\nasem_ps'
# Parametri dei link del plugin (accesso, pagamenti, tessere, biglietti, calendari, esiti dei moduli) e dei pagamenti esterni.
set_opt cache-exc_qs $'asem_member_login\nasem_ml\nasem_ml_reset\nasem_first_access\nasem_activate\nasem_pay\nasem_ret\nasem_apply_pay\nasem_card\nasem_card_pdf\nasem_ticket\nasem_credit\nasem_charge\nasem_res\nasem_evreq\nasem_ical\nasem_ics\nasemf_ok\nasemf_err\nasemf_sig\ntoken\nPayerID\npaymentId\n_back\n_n'
# Percorsi: richieste del plugin e pagine con accesso o moduli (relative alla cartella della demo). Le pagine con l'elenco degli eventi (eventi-in-programma, calendario-eventi) non sono escluse: il plugin le marca come pubbliche e le svuota da solo quando cambia un evento. «/calendario$» è solo la pagina che rimanda al calendario (con «$» non esclude calendario-eventi).
set_opt cache-exc $'/wp-json/associazionesemplice/\n/wp-admin/admin-post.php\n/wp-admin/admin-ajax.php\n/asem-manifest.webmanifest\n/asem-sw.js\n/asem-offline.html\n/area-soci\n/area-volontari\n/area-segreteria\n/area-tesoriere\n/calendario$\n/ingressi-agli-eventi\n/diventa-socio\n/attivita-ed-eventi'
wp litespeed-purge all >/dev/null 2>&1 || true
echo "LiteSpeed Cache: attiva e configurata ($(wp plugin get "$SLUG" --field=version 2>/dev/null))."

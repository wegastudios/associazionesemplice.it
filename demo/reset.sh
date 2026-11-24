#!/usr/bin/env bash
# Azzera la demo pubblica (associazionesemplice.it/demo): database pulito, plugin attivi, dati inventati di nuovo.
# Da cron ogni notte alle 4. Nessun utente amministratore resta: quello d'installazione si toglie alla fine.
set -euo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH" # il cron ha un PATH ridotto: senza questo non trova wp (e php sarebbe quello CGI)
[ -f "$HOME/demo-provision/demo.env" ] && . "$HOME/demo-provision/demo.env" # facoltativo: DEMO_DIR, DEMO_URL (senza il file valgono i valori di oggi)
DEMO="${DEMO_DIR:-$HOME/public_html/demo}"
DEMO_URL="${DEMO_URL:-https://associazionesemplice.it/demo}"
# File scritti durante l'uso della demo (allegati e copie di sicurezza del plugin in uploads/associazionesemplice-private, locandine e file caricati, aggiornamenti
# lasciati a metà, cache, file temporanei del plugin). Percorsi sempre espliciti sotto $DEMO/wp-content: mai plugin, mu-plugin, temi, lingue né i file di provisioning.
# Con «--elenco» si vede soltanto cosa verrebbe tolto, senza cancellare niente e senza toccare il database.
svuota() { # svuota CARTELLA [argomenti di find]: toglie il contenuto, non la cartella
	local dir="$1"; shift
	[ -n "${DEMO:-}" ] || { echo "DEMO vuoto: mi fermo" >&2; exit 4; }
	case "$dir" in "$DEMO"/wp-content/uploads|"$DEMO"/wp-content/upgrade|"$DEMO"/wp-content/cache) ;; *) echo "Percorso non ammesso: $dir" >&2; exit 4;; esac
	[ -d "$dir" ] || return 0
	if [ "${ELENCO:-0}" = 1 ]; then find "$dir" -mindepth 1 "$@" -print; else find "$dir" -mindepth 1 "$@" -delete 2>/dev/null || true; fi
}
svuota_temp() { # file temporanei del plugin nella cartella temporanea del server: ricevute (asem-<24 cifre esadecimali>) e pass Apple (asempk/asemm/asemo/asemw + 6), più vecchi di un'ora
	local t="${TMPDIR:-/tmp}"
	[ -d "$t" ] || return 0
	local act=-delete; [ "${ELENCO:-0}" = 1 ] && act=-print
	find "$t" -maxdepth 1 -user "$(id -un)" -mmin +60 \( -regextype posix-extended -regex "$t/asem-[0-9a-f]{24}" -o -regex "$t/asem(pk|m|o|w)[A-Za-z0-9]{6}" \) "$act" 2>/dev/null || true
}
svuota_file() {
	svuota "$DEMO/wp-content/uploads"
	svuota "$DEMO/wp-content/upgrade" ! -name index.php
	svuota "$DEMO/wp-content/cache"
	svuota_temp
}
if [ "${1:-}" = "--elenco" ]; then
	ELENCO=1
	echo "Verrebbe tolto (nessuna cancellazione):"
	svuota_file
	exit 0
fi
# Il reset cancella TUTTI i dati della demo: parte da solo alle 4 di notte (cron). A mano si rifiuta, a meno che non lo chieda esplicitamente l'utente (--forza).
if [ "$(date +%H)" != "04" ] && [ "${1:-}" != "--forza" ]; then
	echo "Rifiuto: il reset cancella tutti i dati della demo e parte da solo alle 4. Per forzarlo (solo su richiesta esplicita dell'utente): bash reset.sh --forza" >&2
	exit 3
fi
# Un solo azzeramento (o aggiornamento della demo) alla volta, da qualunque parte parta (cron delle 4, tasto del portale, a mano): chi arriva secondo esce senza toccare niente.
# Il blocco sta su un file e lo tiene il processo: se lo script muore o viene fermato, il blocco cade da solo.
exec 9>"$HOME/demo-provision/.demo.lock"
flock -n 9 || { echo "Un altro azzeramento o aggiornamento della demo è in corso: esco senza fare niente." >&2; exit 5; }
export ASEM_DEMO_LOCKED=1 # update.sh, lanciato da qui sotto, non deve aspettare un blocco che ha già questo script
cd "$DEMO"
wp db reset --yes >/dev/null
svuota_file # prima dei dati di prova: le locandine create da extra-data.php devono restare
wp core install --url="$DEMO_URL" --title="Demo di Associazione Semplice" --admin_user=setup_tmp --admin_password="$(openssl rand -hex 16)" --admin_email=info@associazionesemplice.it --skip-email >/dev/null
wp option update blog_public 0 >/dev/null
wp site switch-language it_IT >/dev/null 2>&1 || true
wp option update timezone_string Europe/Rome >/dev/null
wp rewrite structure "/%postname%/" >/dev/null
wp plugin delete akismet hello >/dev/null 2>&1 || true
bash "$HOME/demo-provision/update.sh"
wp plugin activate associazionesemplice associazionesemplice-pro
wp eval-file "$HOME/demo-provision/provision.php" --user=setup_tmp # come amministratore d'installazione: il plugin lascia collegare a un socio un utente con ruolo solo a un amministratore
wp eval-file "$HOME/demo-provision/extra-data.php" --user=setup_tmp # dati di prova in più (soci in vari stati, certificati, prestiti, domande, assemblea): servono a valutare l'aspetto su ogni area
wp eval-file "$HOME/demo-provision/molti-dati.php" --user=setup_tmp # molti dati inventati (soci, prima nota su due anni fiscali, corsi, eventi, risorse): esempio per chi valuta e prova di carico
# Le pagine create dall'utente d'installazione passano all'amministratore del plugin prima di toglierlo (senza questo si cancellerebbero con lui).
wp user delete setup_tmp --yes --reassign="$(wp user get amministratore --field=ID)" >/dev/null
wp cache flush >/dev/null 2>&1 || true
wp litespeed-purge all >/dev/null 2>&1 || true # la cache di LiteSpeed riparte vuota dopo i dati nuovi
echo "$(date -Is) demo azzerata: $(wp user list --format=count) utenti"

#!/usr/bin/env bash
# Carica sul portale i pacchetti del plugin (restano online le ultime N versioni, vedi Impostazioni) FIRMANDOLI sul tuo computer:
# la chiave privata dei pacchetti (Ed25519) non sta mai sul server, così un portale compromesso non può far installare codice sui siti.
# Uso: bash portale/release.sh [--min-version X] free|pro|both ["nota"]   (legge wordpress/dist/associazionesemplice.zip (gratuito) e associazionesemplice-pro.zip (Pro); prima `bash wordpress/build.sh`)
#      bash portale/release.sh [--min-version X] replace free|pro ["nota"]     SOSTITUISCE il pacchetto di una versione già pubblicata (stesso numero): solo con ok scritto della titolare; il vecchio zip resta sul server
#      bash portale/release.sh [--min-version X] sign free|pro VERSIONE       firma una versione già pubblicata (serve il suo zip in wordpress/dist o l'impronta dal pannello)
# Chiave: PORTALE_SIGN_KEY (default ~/.asem/pacchetti-ed25519.pem); identificativo in ~/.asem/pacchetti-ed25519.kid. Fai una copia della chiave in un luogo sicuro.
#
# Ogni pacchetto porta DUE firme: la v1 «prodotto|versione|sha256» (le leggono anche i plugin già installati) e la v2
# «asem-pkg-v2|prodotto|versione|sha256|signed_at|min_version» (signed_at = momento della firma; min_version = versione sotto la quale il plugin
# non installa più nulla). --min-version alza o fissa la soglia; senza, vale quella dell'ultima release del prodotto (o 1.0 se non c'è).
# La v2 si firma con PORTALE_SIGN_KEY_V2 (identificativo accanto, stesso nome con .kid); senza, con la stessa chiave della v1.
# Rotazione della chiave: la release di rotazione si firma ancora con la chiave vecchia (v1 e v2); solo le release dopo possono usare la chiave
# di scorta per la v2. La chiave di scorta NON sta su questo computer di tutti i giorni: qui si dà solo il percorso (PORTALE_SIGN_KEY_V2) quando serve.
set -euo pipefail
cd "$(dirname "$0")"
MINV=""
ARGS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--min-version) MINV="${2:?--min-version vuole un valore, per esempio 1.3.20}"; shift 2 ;;
		--min-version=*) MINV="${1#--min-version=}"; shift ;;
		*) ARGS+=("$1"); shift ;;
	esac
done
set -- ${ARGS[@]+"${ARGS[@]}"}
if [ -n "$MINV" ] && ! [[ "$MINV" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then echo "Versione minima non valida: $MINV (formato 1.2 o 1.2.3)." >&2; exit 2; fi
KEY="${PORTALE_KEY:-../deploy_key}"
# Indirizzo, porta e utente del server stanno in un file non tracciato accanto alla chiave (deploy_key.env, vedi deploy-server.env.example): il repository è pubblico.
ENVF="${PORTALE_ENV:-$(dirname "$KEY")/deploy_key.env}"
[ -f "$ENVF" ] && . "$ENVF"
HOST="${PORTALE_HOST:?manca PORTALE_HOST: copia deploy-server.env.example in deploy_key.env accanto alla chiave}"; PORT="${PORTALE_PORT:?manca PORTALE_PORT}"; USER_="${PORTALE_USER:?manca PORTALE_USER}"
SIGN_KEY="${PORTALE_SIGN_KEY:-$HOME/.asem/pacchetti-ed25519.pem}"
SIGN_KID="$(cat "${SIGN_KEY%.pem}.kid" 2>/dev/null || true)"
SIGN_KEY_V2="${PORTALE_SIGN_KEY_V2:-$SIGN_KEY}"
SIGN_KID_V2="$(cat "${SIGN_KEY_V2%.pem}.kid" 2>/dev/null || true)"
S=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes "$USER_@$HOST")
[ -f "$SIGN_KEY" ] && [ -n "$SIGN_KID" ] || { echo "Manca la chiave di firma dei pacchetti ($SIGN_KEY) o il suo identificativo." >&2; exit 1; }
[ -f "$SIGN_KEY_V2" ] && [ -n "$SIGN_KID_V2" ] || { echo "Manca la chiave di firma v2 ($SIGN_KEY_V2) o il suo identificativo." >&2; exit 1; }

signmsg() { # chiave messaggio → firma Ed25519 base64url
	local msg; msg="$(mktemp)"; printf '%s' "$2" > "$msg"
	openssl pkeyutl -sign -rawin -inkey "$1" -in "$msg" | base64 -w0 | tr '+/' '-_' | tr -d '='
	rm -f "$msg"
}
sign() { signmsg "$SIGN_KEY" "$1|$2|$3"; } # prodotto versione sha256 → firma v1
# prodotto versione sha256 → «firma_v2 kid_v2 signed_at min_version» (da passare a bin/release.php)
signv2() {
	local p="$1" v="$2" sha="$3" min="$MINV" at
	if [ -z "$min" ]; then min="$("${S[@]}" "cd ~/portale && php bin/release.php minver $p" | tr -d '[:space:]')"; fi
	[ -n "$min" ] || min="1.0"
	if [ "$(printf '%s\n%s\n' "$min" "$v" | sort -V | head -n1)" != "$min" ]; then echo "La versione minima $min è più alta della versione $v: controlla --min-version." >&2; return 1; fi
	at="$(date -u +%s)"
	echo "$(signmsg "$SIGN_KEY_V2" "asem-pkg-v2|$p|$v|$sha|$at|$min") $SIGN_KID_V2 $at $min"
}
slug() { [ "$1" = free ] && echo associazionesemplice || echo associazionesemplice-pro; }
zipfile() { if [ "$1" = free ]; then echo "../wordpress/dist/associazionesemplice.zip"; else echo "../wordpress/dist/associazionesemplice-pro.zip"; fi; }

if [ "${1:-}" = sign ]; then
	P="${2:?Uso: release.sh sign free|pro VERSIONE}"; V="${3:?Uso: release.sh sign free|pro VERSIONE}"
	f="$(zipfile "$P")"; [ -f "$f" ] || { echo "Manca $f" >&2; exit 1; }
	SHA="$(openssl dgst -sha256 "$f" | awk '{print $NF}')"
	V2="$(signv2 "$P" "$V" "$SHA")"
	"${S[@]}" "cd ~/portale && php bin/release.php sign $P $V $(sign "$P" "$V" "$SHA") $SIGN_KID $V2"
	exit 0
fi

if [ "${1:-}" = replace ]; then # eccezionale: stessa versione, nuovo pacchetto (solo con ok scritto della titolare); il vecchio zip resta sul server
	P="${2:?Uso: release.sh replace free|pro [nota]}"; NOTE="${3:-}"
	f="$(zipfile "$P")"; [ -f "$f" ] || { echo "Manca $f: lancia prima bash wordpress/build.sh" >&2; exit 1; }
	VER="$(unzip -p "$f" "$(slug "$P")/$(slug "$P").php" | grep -m1 -oE 'Version:[[:space:]]*[0-9]+\.[0-9]+(\.[0-9]+)?' | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?')"
	[ -n "$VER" ] || { echo "Non trovo la versione in $f" >&2; exit 1; }
	SHA="$(openssl dgst -sha256 "$f" | awk '{print $NF}')"
	SIG="$(sign "$P" "$VER" "$SHA")"
	V2="$(signv2 "$P" "$VER" "$SHA")" # nuovo signed_at; stessa min_version dell'ultima release salvo --min-version
	tmp="portale/data/incoming-$P-$$.zip"
	"${S[@]}" "cat > ~/$tmp" < "$f"
	"${S[@]}" "cd ~/portale && php bin/release.php replace $P ~/$tmp $(printf '%q' "$NOTE") $SIG $SIGN_KID $V2; rc=\$?; rm -f ~/$tmp; exit \$rc"
	"${S[@]}" 'bash ~/demo-provision/update.sh' || echo "Attenzione: la demo non è stata aggiornata (la sostituzione è andata a buon fine)." >&2
	exit 0
fi

WHAT="${1:?Uso: release.sh free|pro|both [nota]}"; NOTE="${2:-}"
case "$WHAT" in both) LIST="free pro" ;; free|pro) LIST="$WHAT" ;; *) echo "Prodotto non valido: $WHAT" >&2; exit 2 ;; esac
for p in $LIST; do
	f="$(zipfile "$p")"
	[ -f "$f" ] || { echo "Manca $f: lancia prima bash wordpress/build.sh" >&2; exit 1; }
	VER="$(unzip -p "$f" "$(slug "$p")/$(slug "$p").php" | grep -m1 -oE 'Version:[[:space:]]*[0-9]+\.[0-9]+(\.[0-9]+)?' | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?')"
	[ -n "$VER" ] || { echo "Non trovo la versione in $f" >&2; exit 1; }
	SHA="$(openssl dgst -sha256 "$f" | awk '{print $NF}')"
	SIG="$(sign "$p" "$VER" "$SHA")"
	V2="$(signv2 "$p" "$VER" "$SHA")"
	tmp="portale/data/incoming-$p-$$.zip"
	"${S[@]}" "cat > ~/$tmp" < "$f"
	"${S[@]}" "cd ~/portale && php bin/release.php $p ~/$tmp $(printf '%q' "$NOTE") $SIG $SIGN_KID $V2; rc=\$?; rm -f ~/$tmp; exit \$rc"
done
# La demo pubblica (associazionesemplice.it/demo) segue sempre le ultime versioni: se non si aggiorna, la pubblicazione resta valida.
"${S[@]}" 'bash ~/demo-provision/update.sh' || echo "Attenzione: la demo non è stata aggiornata (la pubblicazione è andata a buon fine)." >&2

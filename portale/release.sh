#!/usr/bin/env bash
# Carica sul portale i pacchetti del plugin (restano online le ultime N versioni, vedi Impostazioni) FIRMANDOLI sul tuo computer:
# la chiave privata dei pacchetti (Ed25519) non sta mai sul server, così un portale compromesso non può far installare codice sui siti.
# Uso: bash portale/release.sh free|pro|both ["nota"]   (legge wordpress/dist/associazionesemplice.zip (gratuito) e associazionesemplice-pro.zip (Pro); prima `bash wordpress/build.sh`)
#      bash portale/release.sh sign free|pro VERSIONE        firma una versione già pubblicata (serve il suo zip in wordpress/dist o l'impronta dal pannello)
# Chiave: PORTALE_SIGN_KEY (default ~/.asem/pacchetti-ed25519.pem); identificativo in ~/.asem/pacchetti-ed25519.kid. Fai una copia della chiave in un luogo sicuro.
set -euo pipefail
cd "$(dirname "$0")"
HOST="${PORTALE_HOST:-65.108.75.89}"; PORT="${PORTALE_PORT:-2299}"; USER_="${PORTALE_USER:-associazionesemp}"
KEY="${PORTALE_KEY:-../deploy_key}"
SIGN_KEY="${PORTALE_SIGN_KEY:-$HOME/.asem/pacchetti-ed25519.pem}"
SIGN_KID="$(cat "${SIGN_KEY%.pem}.kid" 2>/dev/null || true)"
S=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes "$USER_@$HOST")
[ -f "$SIGN_KEY" ] && [ -n "$SIGN_KID" ] || { echo "Manca la chiave di firma dei pacchetti ($SIGN_KEY) o il suo identificativo." >&2; exit 1; }

sign() { # prodotto versione sha256 → firma Ed25519 base64url del messaggio «prodotto|versione|sha256»
	local msg; msg="$(mktemp)"; printf '%s' "$1|$2|$3" > "$msg"
	openssl pkeyutl -sign -rawin -inkey "$SIGN_KEY" -in "$msg" | base64 -w0 | tr '+/' '-_' | tr -d '='
	rm -f "$msg"
}
slug() { [ "$1" = free ] && echo associazionesemplice || echo associazionesemplice-pro; }
zipfile() { if [ "$1" = free ]; then echo "../wordpress/dist/associazionesemplice.zip"; else echo "../wordpress/dist/associazionesemplice-pro.zip"; fi; }

if [ "${1:-}" = sign ]; then
	P="${2:?Uso: release.sh sign free|pro VERSIONE}"; V="${3:?Uso: release.sh sign free|pro VERSIONE}"
	f="$(zipfile "$P")"; [ -f "$f" ] || { echo "Manca $f" >&2; exit 1; }
	SHA="$(openssl dgst -sha256 "$f" | awk '{print $NF}')"
	"${S[@]}" "cd ~/portale && php bin/release.php sign $P $V $(sign "$P" "$V" "$SHA") $SIGN_KID"
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
	tmp="portale/data/incoming-$p-$$.zip"
	"${S[@]}" "cat > ~/$tmp" < "$f"
	"${S[@]}" "cd ~/portale && php bin/release.php $p ~/$tmp $(printf '%q' "$NOTE") $SIG $SIGN_KID; rc=\$?; rm -f ~/$tmp; exit \$rc"
done

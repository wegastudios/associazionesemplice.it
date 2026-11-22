#!/usr/bin/env bash
# Costo fisso del plugin su ogni richiesta: per alcune richieste tipiche conta i file PHP del plugin (gratuito e Pro) letti e i loro byte
# (dal commento del mu-plugin tests/ci/mu/asem-costo-fisso.php) e controlla che le classi che lì non servono non si carichino.
# Uso: bash costo-fisso.sh <indirizzo del sito> [cookie di un amministratore]   (le richieste da misurare: costo-fisso.d/*.sh)
# Scrive una tabella in Markdown su stdout (e nel riepilogo del job, se c'è). Esce con 1 se una richiesta non risponde o carica una classe vietata.
set -u
base="${1:?serve indirizzo del sito}"
cookie="${2:-}"
body="$(mktemp)"
fail=0
table='| Richiesta | Risposta | File PHP del plugin | KB di PHP del plugin |'$'\n''|---|---:|---:|---:|'

# misura <etichetta> <metodo> <percorso> <dati POST> <cookie: 0|1> <codici ammessi> <classi che non si devono caricare> [classi che devono essersi caricate]
misura() {
	local label="$1" method="$2" path="$3" data="$4" auth="$5" codes="$6" forbidden="$7" required="${8:-}"
	local args=( -s -o "$body" -w '%{http_code}' -H 'X-Asem-Costo: 1' )
	if [ "$auth" = 1 ]; then
		[ -n "$cookie" ] || { echo "::error::$label: manca il cookie dell'amministratore" >&2; fail=1; return; }
		args+=( -H "Cookie: $cookie" )
	fi
	if [ "$method" = POST ]; then args+=( --data "$data" ); fi
	local code
	code="$(curl "${args[@]}" "$base$path" || echo 000)"
	local m files bytes classes
	m="$(grep -ao 'asem-costo files=[0-9]* bytes=[0-9]*' "$body" | tail -1 || true)"
	if [ -z "$m" ] || ! echo " $codes " | grep -q " $code "; then
		echo "::error::$label ($path): risposta $code (ammesse: $codes), misura '${m:-assente}'" >&2
		head -c 600 "$body" >&2; echo >&2
		fail=1
		return
	fi
	files="$(echo "$m" | sed 's/.*files=\([0-9]*\).*/\1/')"
	bytes="$(echo "$m" | sed 's/.*bytes=\([0-9]*\).*/\1/')"
	classes=" $(grep -ao 'asem-costo-classi [^>]*' "$body" | tail -1 | sed 's/^asem-costo-classi //; s/ --$//') "
	table+=$'\n'"| $label | $code | $files | $((bytes / 1024)) |"
	echo "== $label: $files file, $((bytes / 1024)) KB"
	grep -ao 'asem-costo-file [^>]*' "$body" | tail -1 | sed 's/^asem-costo-file //; s/ --$//' | tr ' ' '\n' | sort -t: -k2 -n -r | sed 's/^/   /'
	local c
	for c in $forbidden; do
		if echo "$classes" | grep -qF " $c "; then
			echo "::error::$label: la classe $c si carica anche se qui non serve" >&2
			fail=1
		fi
	done
	for c in $required; do
		if ! echo "$classes" | grep -qF " $c "; then
			echo "::error::$label: la classe $c non si è caricata: la richiesta non è arrivata al suo gestore" >&2
			fail=1
		fi
	done
}

# Le richieste da misurare stanno nei file di costo-fisso.d/ (uno per argomento), letti in ordine di nome: ognuno chiama misura().
for f in "$(dirname "$0")"/costo-fisso.d/*.sh; do
	# shellcheck source=/dev/null
	. "$f"
done

echo
echo "$table"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{ echo "### Costo fisso del plugin per richiesta"; echo; echo "$table"; echo; } >> "$GITHUB_STEP_SUMMARY"
fi
rm -f "$body"
exit "$fail"

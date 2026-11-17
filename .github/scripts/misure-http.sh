#!/usr/bin/env bash
# Misure delle pagine pubbliche del sito di prova (workflow «Misure di velocità»): per ogni riga «@@URL|etichetta|indirizzo» del log del generatore
# chiede la pagina quattro volte come un visitatore anonimo e legge dal commento del file di misura (asem-misure.php) query, millisecondi e memoria.
# Scrive una tabella in Markdown su stdout. Esce con 1 solo se una pagina non risponde 200 o manca la misura: i tempi non fanno mai fallire.
# Uso: bash .github/scripts/misure-http.sh misure.log
set -u
log="${1:?serve il log del generatore}"
body="$(mktemp)"
fail=0
echo
echo '| Pagina del sito (visitatore anonimo) | 1ª volta (ms) | Migliore (ms) | Query | Memoria di picco (MB) | Byte | Foglio di stile del plugin |'
echo '|---|---:|---:|---:|---:|---:|---|'
while IFS='|' read -r _ label url; do
	[ -n "$url" ] || continue
	first=""; best=""; q=""; mem=""; size=""; code=""
	for i in 1 2 3 4; do
		code="$(curl -s -o "$body" -w '%{http_code}' -H 'X-Asem-Misure: 1' "$url" || echo 000)"
		m="$(grep -ao 'asem-misure q=[0-9]* ms=[0-9]* mem=[0-9]*' "$body" | tail -1 || true)"
		if [ "$code" != "200" ] || [ -z "$m" ]; then
			echo "::error::Pagina non riuscita: $label ($url) risposta $code, misura '${m:-assente}'" >&2
			fail=1
			break
		fi
		ms="$(echo "$m" | sed 's/.* ms=\([0-9]*\) .*/\1/')"
		if [ "$i" = 1 ]; then
			first="$ms"; q="$(echo "$m" | sed 's/.* q=\([0-9]*\) .*/\1/')"; mem="$(echo "$m" | sed 's/.* mem=\([0-9]*\)/\1/')"; size="$(wc -c < "$body" | tr -d ' ')"
			head_end="$(grep -abo '</head>' "$body" | head -1 | cut -d: -f1 || true)"
			css_pos="$(grep -abo 'asem-frontend-css' "$body" | head -1 | cut -d: -f1 || true)"
			if [ -z "$css_pos" ]; then css="non caricato"; elif [ -n "$head_end" ] && [ "$css_pos" -lt "$head_end" ]; then css="nell'head"; else css="dopo l'head (in fondo alla pagina)"; fi
		fi
		if [ -z "$best" ] || [ "$ms" -lt "$best" ]; then best="$ms"; fi
	done
	[ -n "$best" ] || continue
	printf '| %s | %s | %s | %s | %.1f | %s | %s |\n' "$label" "$first" "$best" "$q" "$(awk "BEGIN{print $mem/1048576}")" "$size" "$css"
	if [ "$best" -gt 1500 ] || [ "$q" -gt 400 ]; then
		echo "::warning title=Pagina molto lenta::$label: $best ms (migliore), $q query" >&2
	fi
done < <(grep '^@@URL|' "$log")
rm -f "$body"

# Dimensione dei file che il visitatore scarica (non minificati) e se il server li comprime
echo
echo '| File del plugin | Byte | Righe |'
echo '|---|---:|---:|'
for f in frontend.css frontend.js; do
	p="wordpress/associazionesemplice/assets/$f"
	if [ -f "$p" ]; then printf '| %s | %s | %s |\n' "$f" "$(wc -c < "$p" | tr -d ' ')" "$(wc -l < "$p" | tr -d ' ')"; fi
done
exit "$fail"

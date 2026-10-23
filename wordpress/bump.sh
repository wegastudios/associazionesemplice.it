#!/usr/bin/env bash
# Aggiorna in un colpo solo intestazione, costante, readme (Stable tag + changelog inglese) e CHANGELOG.md (log in italiano) di UN plugin.
# Uso: bash wordpress/bump.sh free|pro NUOVA_VERSIONE "Voce del readme (in inglese)" "Voce del log (italiano semplice, per chi usa il programma; più voci separate da |)"
# Ricorda: se cambiano solo testi o link, il numero NON si aumenta.
set -euo pipefail
cd "$(dirname "$0")"
case "${1:-}" in
	free) dir=associazionesemplice;     file=associazionesemplice.php;     const=ASEM_VERSION ;;
	pro)  dir=associazionesemplice-pro; file=associazionesemplice-pro.php; const=ASEM_PRO_VERSION ;;
	*) echo "Uso: bump.sh free|pro VERSIONE \"readme (inglese)\" \"log (italiano)\"" >&2; exit 1 ;;
esac
ver="${2:?manca la versione}"; note="${3:?manca la voce del readme}"; log="${4:?manca la voce del log (italiano)}"
[[ "$ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versione non valida: $ver" >&2; exit 1; }
sed -i -E "s/^( \* Version:[[:space:]]+).*/\1$ver/; s/(define\( '$const', ')[^']*(' \);)/\1$ver\2/" "$dir/$file"
sed -i -E "s/^(Stable tag: ).*/\1$ver/" "$dir/readme.txt"
awk -v v="$ver" -v n="$note" '{print} /^== Changelog ==/ && !d {print ""; print "= " v " ="; print "* " n; d=1}' "$dir/readme.txt" > "$dir/readme.tmp" && mv "$dir/readme.tmp" "$dir/readme.txt"
# log in italiano: una voce per versione, la più recente in alto (dopo l'introduzione)
awk -v v="$ver" -v l="$log" 'BEGIN{n=split(l,a,"|")} {print} !d && /^Qui trovi/ {print ""; print "## " v; for(i=1;i<=n;i++){gsub(/^ +| +$/,"",a[i]); if(a[i]!="") print "- " a[i]} d=1}' "$dir/CHANGELOG.md" > "$dir/CHANGELOG.tmp" && mv "$dir/CHANGELOG.tmp" "$dir/CHANGELOG.md"
echo "$dir → $ver"; grep -n "Version:\|define( '$const'" "$dir/$file"; grep -n "Stable tag" "$dir/readme.txt"; sed -n 1,10p "$dir/CHANGELOG.md"

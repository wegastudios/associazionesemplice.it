#!/usr/bin/env bash
# Aggiorna in un colpo solo intestazione, costante, CHANGELOG.md (log in italiano, unica fonte) e readme (Stable tag + changelog, rigenerato dal log) di UN plugin.
# Uso: bash wordpress/bump.sh free|pro NUOVA_VERSIONE "Nuovo: ...|Ottimizzazione: ...|Sicurezza: ...|Altro: ..." ["Da fare dopo l'aggiornamento: voce|altra voce"]
# Le voci (separate da |) iniziano con «Nuovo:», «Ottimizzazione:», «Sicurezza:» o «Altro:» (le correzioni sono «Altro:»); poche righe, voci simili unite. Il secondo testo, facoltativo, sono i passi «Da fare dopo l'aggiornamento». Titolo con la data di oggi e link al changelog di tutte le versioni in fondo.
# Ricorda: se cambiano solo testi o link, il numero NON si aumenta.
set -euo pipefail
cd "$(dirname "$0")"
case "${1:-}" in
	free) dir=associazionesemplice;     file=associazionesemplice.php;     const=ASEM_VERSION ;;
	pro)  dir=associazionesemplice-pro; file=associazionesemplice-pro.php; const=ASEM_PRO_VERSION ;;
	*) echo "Uso: bump.sh free|pro VERSIONE \"log (italiano)\"" >&2; exit 1 ;;
esac
ver="${2:?manca la versione}"; log="${3:?manca la voce del log (italiano)}"; todo="${4:-}"; day="$(date +%F)"
[[ "$ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versione non valida: $ver" >&2; exit 1; }
sed -i -E "s/^( \* Version:[[:space:]]+).*/\1$ver/; s/(define\( '$const', ')[^']*(' \);)/\1$ver\2/" "$dir/$file"
sed -i -E "s/^(Stable tag: ).*/\1$ver/" "$dir/readme.txt"
# log in italiano: una voce per versione, la più recente in alto (dopo l'introduzione)
awk -v v="$ver" -v d="$day" -v l="$log" -v t="$todo" 'BEGIN{n=split(l,a,"|"); m=split(t,b,"|")} {print} !done && /^Qui trovi/ {print ""; print "## " v " – " d; for(i=1;i<=n;i++){gsub(/^ +| +$/,"",a[i]); if(a[i]!="") print "- " a[i]} if(t!=""){ print "Da fare dopo l'"'"'aggiornamento:"; for(i=1;i<=m;i++){gsub(/^ +| +$/,"",b[i]); if(b[i]!="") print "- " b[i]} } print "Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog"; done=1}' "$dir/CHANGELOG.md" > "$dir/CHANGELOG.tmp" && mv "$dir/CHANGELOG.tmp" "$dir/CHANGELOG.md"
bash ./sync-readme-changelog.sh "$1" >/dev/null
echo "$dir → $ver"; grep -n "Version:\|define( '$const'" "$dir/$file"; grep -n "Stable tag" "$dir/readme.txt"; sed -n 1,10p "$dir/CHANGELOG.md"

#!/usr/bin/env bash
# Rigenera la sezione «== Changelog ==» del readme.txt di UN plugin partendo da CHANGELOG.md (italiano semplice, unica fonte).
# È il testo che WordPress mostra in «Visualizza i dettagli» prima di aggiornare. Le voci più vecchie, che non stanno in CHANGELOG.md, restano com'erano.
# Uso: bash wordpress/sync-readme-changelog.sh free|pro   (lo lancia già bump.sh)
set -euo pipefail
cd "$(dirname "$0")"
case "${1:-}" in
	free) dir=associazionesemplice ;;
	pro)  dir=associazionesemplice-pro ;;
	*) echo "Uso: sync-readme-changelog.sh free|pro" >&2; exit 1 ;;
esac
log="$dir/CHANGELOG.md"; readme="$dir/readme.txt"
[ -f "$log" ] && [ -f "$readme" ] || { echo "Mancano $log o $readme" >&2; exit 1; }
grep -q '^== Changelog ==' "$readme" || { echo "$readme non ha la sezione Changelog" >&2; exit 1; }
tmp="$(mktemp)"; new="$(mktemp)"; trap 'rm -f "$tmp" "$new"' EXIT
# 1) voci nuove, dal log in italiano: «## 1.3.18» + «- voce» → «= 1.3.18 =» + «* voce»
tr -d '\r' < "$log" | awk '
	/^## [0-9]+\.[0-9]+(\.[0-9]+)?[[:space:]]*$/ { v = $2; print ""; print "= " v " ="; next }
	v != "" && /^- / { sub(/^- /, ""); print "* " $0 }
' > "$new"
# 2) il readme: tutto fino a «== Changelog ==», poi le voci nuove, poi le vecchie che il log non ha
tr -d '\r' < "$readme" | awk -v newfile="$new" '
	BEGIN {
		while ((getline line < newfile) > 0) { out = out line "\n"; if (line ~ /^= .* =$/) { v = line; gsub(/^= | =$/, "", v); have[v] = 1 } }
	}
	!inlog { print }
	/^== Changelog ==/ && !inlog { inlog = 1; printf "%s", out; next }
	inlog {
		if ($0 == "") next
		if ($0 ~ /^= .* =$/) { v = $0; gsub(/^= | =$/, "", v); keep = !(v in have); if (keep) { print "" } }
		if (keep) print
	}
' > "$tmp"
mv "$tmp" "$readme"; trap - EXIT; rm -f "$new"
echo "$readme: changelog rigenerato da $log"; sed -n '/^== Changelog ==/,+6p' "$readme"
# 3) WordPress.org mostra al massimo 5000 caratteri di Changelog (oltre, il Plugin Check avvisa «too long»): nel readme del gratuito restano le versioni più recenti
#    entro il limite; la cronologia completa resta in CHANGELOG.md.
if [ "$1" = "free" ]; then
	tmp2="$(mktemp)"
	tr -d '\r' < "$readme" | awk '
		/^== Changelog ==/ { inlog = 1; print; next }
		!inlog { print; next }
		/^== / { inlog = 0; print; next }
		{
			if ($0 ~ /^= .* =$/) { if (blk != "") { if (!skipped && (total + length(blk) <= 4600 || nblk == 0)) { printf "%s", blk; total += length(blk); nblk++ } else { skipped = 1 } } blk = "" }
			blk = blk $0 "\n"
		}
		END { if (inlog && blk != "") { if (!skipped && (total + length(blk) <= 4600 || nblk == 0)) printf "%s", blk; else skipped = 1 } if (skipped) print "\nLe versioni precedenti sono nel file CHANGELOG.md del plugin." }
	' > "$tmp2"
	mv "$tmp2" "$readme"
fi

#!/usr/bin/env bash
# Legge i QR generati da prova-qr-tessera.php (uscita in $1) e verifica che il contenuto sia IDENTICO all'indirizzo atteso.
# PDF a 150 e 300 dpi (pdftoppm), SVG a due dimensioni (rsvg-convert). Non corregge nulla: stampa i numeri e fallisce se un QR non si legge.
set -u
in="$1"; out="${2:-qr-prova}"
mkdir -p "$out"
grep '^EXP1 ' "$in" | head -1 | cut -d' ' -f2- > "$out/exp1.txt"
grep '^EXPS ' "$in" | cut -d' ' -f2- > "$out/exps.txt"
grep '^PDF1 ' "$in" | head -1 | cut -d' ' -f2- | base64 -d > "$out/tessera.pdf"
grep '^PDFS ' "$in" | head -1 | cut -d' ' -f2- | base64 -d > "$out/foglio.pdf"
grep '^SVG '  "$in" | head -1 | cut -d' ' -f2- | base64 -d > "$out/qr.svg"
fail=0
report="$out/esito.md"
{ echo "### Lettura del QR della tessera"; echo "| Prova | Atteso | Letto | Esito |"; echo "|---|---|---|---|"; } > "$report"

check() { # nome, file letto, file atteso
  local got exp ok
  got=$(sort "$2" | tr '\n' ' '); exp=$(sort "$3" | tr '\n' ' ')
  if [ "$got" = "$exp" ] && [ -n "$got" ]; then ok=OK; else ok=ERRORE; fail=1; fi
  echo "| $1 | \`$exp\` | \`${got:-nulla}\` | $ok |" >> "$report"
  echo "[$ok] $1"; echo "  atteso: $exp"; echo "  letto : ${got:-nulla}"
}

for dpi in 150 300; do
  pdftoppm -r $dpi -png "$out/tessera.pdf" "$out/t$dpi"
  zbarimg --raw -q "$out"/t$dpi*.png > "$out/t$dpi.txt" 2>/dev/null
  check "PDF tessera singola, $dpi dpi" "$out/t$dpi.txt" "$out/exp1.txt"
  pdftoppm -r $dpi -png "$out/foglio.pdf" "$out/f$dpi"
  zbarimg --raw -q "$out"/f$dpi*.png > "$out/f$dpi.txt" 2>/dev/null
  check "PDF foglio di tessere, $dpi dpi" "$out/f$dpi.txt" "$out/exps.txt"
done
for w in 300 600; do
  rsvg-convert -b white -w $w "$out/qr.svg" -o "$out/s$w.png"
  zbarimg --raw -q "$out/s$w.png" > "$out/s$w.txt" 2>/dev/null
  check "QR digitale (SVG), $w px" "$out/s$w.txt" "$out/exp1.txt"
done
cat "$report" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
exit $fail

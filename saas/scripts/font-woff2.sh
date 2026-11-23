#!/usr/bin/env bash
# Rigenera i font WOFF2 del gestionale (resources/fonts) dai TrueType originali di Montserrat (public/assets/fonts, licenza OFL,
# senza nome riservato: il sottoinsieme è consentito). Serve Python con fonttools e brotli: pip install fonttools brotli
# Uso: scripts/font-woff2.sh   (dalla cartella saas). I file prodotti si mettono nel repository: la build non li rigenera.
#
# Per ogni peso due file, come fa Google Fonts:
#   - «latin»: alfabeto latino di base, lettere accentate dell'Europa occidentale, punteggiatura, euro. È quello che si scarica sempre.
#   - «esteso»: il carattere completo (latino esteso, cirillico, vietnamita). Il browser lo scarica solo se la pagina contiene
#     un carattere dei blocchi indicati in app.css (es. un cognome polacco o rumeno): il testo resta identico a prima.
set -euo pipefail
cd "$(dirname "$0")/.."

SRC=public/assets/fonts
OUT=resources/fonts
mkdir -p "$OUT"

# Stesso insieme «latin» di Google Fonts, più le frecce semplici. Va tenuto uguale a unicode-range in resources/css/app.css.
LATIN='U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2190-2193,U+2212,U+2215,U+FEFF,U+FFFD'

for pair in Medium:500 SemiBold:600 Bold:700; do
    name="${pair%%:*}"; weight="${pair##*:}"
    pyftsubset "$SRC/Montserrat-$name.ttf" --flavor=woff2 --unicodes="$LATIN" --layout-features='*' \
        --output-file="$OUT/montserrat-$weight-latin.woff2"
    pyftsubset "$SRC/Montserrat-$name.ttf" --flavor=woff2 --unicodes='*' --glyphs='*' --layout-features='*' \
        --output-file="$OUT/montserrat-$weight-esteso.woff2"
done

cp "$SRC/OFL.txt" "$OUT/OFL.txt"
ls -l "$SRC"/*.ttf "$OUT"/*.woff2

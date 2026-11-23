#!/usr/bin/env bash
# Alleggerisce i file SVG del marchio (public/assets/brand) con svgo: coordinate dei tracciati a due decimali su tavole
# di 4-11 mila unità, offset e colori dei gradienti a tre decimali (come gli originali), titolo e viewBox conservati.
# Confrontati con gli originali a 64 e 200 px: stessi pixel, salvo pochi bordi con differenze di 1-2 livelli su 255.
# Uso (dalla cartella saas, serve Node): scripts/svg-ottimizza.sh file.svg [altri.svg ...]
# I file prodotti si mettono nel repository: la build non li rigenera.
set -euo pipefail
cd "$(dirname "$0")/.."

CONFIG="$(mktemp --suffix=.mjs)"
trap 'rm -f "$CONFIG"' EXIT
cat > "$CONFIG" <<'CFG'
export default {
  multipass: true,
  plugins: [
    { name: 'preset-default', params: { overrides: {
      removeTitle: false, removeViewBox: false, removeDesc: false, convertShapeToPath: false,
      cleanupNumericValues: { floatPrecision: 3 },
      convertPathData: { floatPrecision: 2 },
    } } },
  ],
};
CFG

npx -y svgo@3.3.2 --config "$CONFIG" "$@"

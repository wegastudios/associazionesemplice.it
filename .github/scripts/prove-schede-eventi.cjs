/**
 * Schede dell'elenco attività su un tema vero (workflow «Prove in sandbox»): schermate a 360/390/768/1024/1440 px e due controlli misurati
 * (nessuna scorrimento orizzontale; titolo della scheda con contrasto ≥ 4,5:1 sullo sfondo della scheda, anche con tema scuro).
 * Uso: NODE_PATH=<playwright> node prove-schede-eventi.cjs <indirizzo-della-pagina> <nome-tema> <cartella-di-uscita>
 * Esce con 1 se un controllo misurato fallisce; una schermata mancante si scrive in esito-schede.md.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const url = process.argv[2];
const theme = process.argv[3] || 'tema';
const out = process.argv[4] || 'schermate';
fs.mkdirSync(out, { recursive: true });
const widths = [360, 390, 768, 1024, 1440];

function lum(rgb) {
  const [r, g, b] = rgb.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const parse = (s) => (s.match(/[\d.]+/g) || [0, 0, 0]).slice(0, 3).map(Number);
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

(async () => {
  const browser = await chromium.launch();
  const lines = [];
  let bad = 0;
  for (const scheme of ['light', 'dark']) {
    for (const w of widths) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 }, locale: 'it-IT', colorScheme: scheme });
      try {
        await page.goto(url, { waitUntil: 'load' });
        await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); }); // le immagini in attesa (lazy loading) si caricano prima di misurare e fotografare
        await page.waitForTimeout(600);
        const m = await page.evaluate(() => {
          const grid = document.querySelector('.asemf-grid');
          const cards = [...document.querySelectorAll('.asemf-grid .asemf-card')];
          return {
            grid: !!grid,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            titles: cards.map((c) => { const a = c.querySelector('h3 a') || c.querySelector('h3'); return a ? [getComputedStyle(a).color, getComputedStyle(c).backgroundColor] : null; }).filter(Boolean),
            empty: cards.filter((c) => { const p = c.querySelector('.asemf-card-poster'); if (!p) return true; const img = p.querySelector('img'); const ok = img && img.complete && img.naturalWidth > 0; return !ok && getComputedStyle(p).backgroundColor === 'rgba(0, 0, 0, 0)'; }).length,
            count: cards.length,
            maxCard: Math.max(0, ...cards.map((c) => c.getBoundingClientRect().height)),
            maxImg: Math.max(0, ...[...document.querySelectorAll('.asemf img')].map((i) => i.getBoundingClientRect().height)),
            lowRes: cards.filter((c) => { const i = c.querySelector('.asemf-card-poster img'); return i && i.currentSrc && i.naturalWidth > 0 && i.naturalWidth < i.getBoundingClientRect().width * 1.5; }).length,
            posters: cards.map((c) => { const p = c.querySelector('.asemf-card-poster'); return p ? Math.round(p.getBoundingClientRect().width / p.getBoundingClientRect().height * 100) / 100 : null; }),
          };
        });
        const tag = `${theme} ${scheme} ${w}px`;
        if (!m.grid) { lines.push(`- MANCA: ${tag}: nessuna griglia di schede nella pagina`); bad++; }
        if (m.overflow > 1) { lines.push(`- ERRORE: ${tag}: scorrimento orizzontale di ${m.overflow}px`); bad++; }
        for (const [fg, bg] of m.titles) { const r = ratio(parse(fg), parse(bg)); if (r < 4.5) { lines.push(`- ERRORE: ${tag}: contrasto del titolo ${r.toFixed(2)}:1`); bad++; break; } }
        if (m.count < 12) { lines.push(`- ERRORE: ${tag}: solo ${m.count} schede, ne servono almeno 12 con immagine`); bad++; }
        if (w >= 1440 && m.maxCard > 600) { lines.push(`- ERRORE: ${tag}: una scheda è alta ${Math.round(m.maxCard)}px (massimo 600)`); bad++; }
        if (m.maxImg > 700) { lines.push(`- ERRORE: ${tag}: un'immagine è alta ${Math.round(m.maxImg)}px`); bad++; }
        if (m.empty) { lines.push(`- ERRORE: ${tag}: ${m.empty} fasce immagine vuote (né immagine né colore)`); bad++; }
        const odd = m.posters.filter((x) => x !== null && Math.abs(x - 16 / 9) > 0.05);
        if (odd.length) { lines.push(`- ERRORE: ${tag}: immagine non 16/9 (${odd.join(', ')})`); bad++; }
        await page.screenshot({ path: path.join(out, `${theme}-${scheme}-${w}.png`), fullPage: true });
        lines.push(`- riuscita: ${tag}`);
      } catch (e) {
        lines.push(`- MANCA: ${theme} ${scheme} ${w}px: ${String(e.message || e).split('\n')[0]}`);
        bad++;
      }
      await page.close();
    }
  }
  await browser.close();
  fs.appendFileSync(path.join(out, 'esito-schede.md'), `## Schede attività, tema ${theme}\n\n${lines.join('\n')}\n\n`);
  console.log(lines.join('\n'));
  process.exit(bad ? 1 : 0);
})();

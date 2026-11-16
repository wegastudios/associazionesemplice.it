/**
 * Schermate a pagina intera dei pannelli del plugin (workflow «Prove in sandbox»).
 * Uso: NODE_PATH=<cartella con playwright> node prove-schermate.cjs <indirizzo-del-sito> <cartella-di-uscita>
 * Non esce mai con errore per una schermata mancante: scrive l'elenco di cosa è riuscito e cosa no in esito.md.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = (process.argv[2] || 'http://localhost:8888').replace(/\/$/, '');
const out = process.argv[3] || 'schermate';
fs.mkdirSync(out, { recursive: true });
const results = [];

/** Ricava dove comincia e dove finisce una sezione (titoli h2) nella pagina intera. */
async function sectionClip(page, startText, stopText) {
  return page.evaluate(({ startText, stopText }) => {
    const total = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
    const top = (el) => el.getBoundingClientRect().top + window.scrollY;
    const h2s = [...document.querySelectorAll('#wpbody-content h2')];
    const find = (t) => h2s.find((h) => h.textContent.trim().includes(t));
    let start = 0;
    let end = total;
    let from = null;
    if (startText) {
      from = find(startText);
      if (!from) return null;
      start = top(from);
    }
    if (stopText) {
      const to = find(stopText);
      if (!to) return null;
      end = top(to);
    } else if (from) {
      const next = h2s.find((h) => h !== from && (from.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING));
      if (next) end = top(next);
    }
    return { x: 0, y: Math.max(0, Math.floor(start) - 8), width: Math.ceil(document.documentElement.clientWidth), height: Math.ceil(end - start) + 8 };
  }, { startText, stopText });
}

async function shot(name, label, fn) {
  try {
    await fn(path.join(out, name + '.png'));
    results.push({ ok: true, name, label });
    console.log('ok   ' + name);
  } catch (e) {
    const why = String(e && e.message ? e.message : e).split('\n')[0];
    results.push({ ok: false, name, label, why });
    console.log('MANCA ' + name + ': ' + why);
  }
}

(async () => {
  let browser;
  try {
    browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'it-IT' });
    page.setDefaultTimeout(30000);
    await page.goto(base + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password'); // utente di prova di wp-env, nessun dato vero
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

    const open = async (slug) => {
      const r = await page.goto(base + '/wp-admin/admin.php?page=' + slug, { waitUntil: 'load' });
      if (!r || !r.ok()) throw new Error('pagina ' + slug + ' non raggiungibile');
      if (await page.locator('.wp-die-message').count()) throw new Error('pagina ' + slug + ': ' + (await page.locator('.wp-die-message').first().innerText()).slice(0, 120));
      await page.waitForTimeout(500);
    };
    const crop = async (file, start, stop) => {
      const clip = await sectionClip(page, start, stop);
      if (!clip || clip.height < 40) throw new Error('sezione «' + (start || 'inizio') + '» non trovata nella pagina');
      await page.screenshot({ path: file, fullPage: true, clip });
    };

    await shot('01-bacheca-avvisi', 'Avvisi della Bacheca (pagina intera)', async (f) => {
      await open('asem');
      await page.screenshot({ path: f, fullPage: true });
    });
    await shot('02-copia-di-sicurezza-pagina-intera', 'Copia di sicurezza (pagina intera)', async (f) => {
      await open('asem-backup');
      await page.screenshot({ path: f, fullPage: true });
    });
    await shot('03-cifratura-dei-file', 'Pannello della cifratura dei file', async (f) => {
      await open('asem-backup');
      await crop(f, 'Cifratura dei file riservati', null);
    });
    await shot('04-cartella-riservata', 'Pannello della cartella riservata', async (f) => {
      await open('asem-backup');
      await crop(f, 'Cartella dei file riservati', null);
    });
    await shot('05-ripristino', 'Pagina di ripristino', async (f) => {
      await open('asem-backup');
      await crop(f, 'Ripristino', null);
    });
    await shot('06-scarico-copia-con-password', 'Scarico della copia con password', async (f) => {
      await open('asem-backup');
      await crop(f, null, 'Ripristino');
    });
  } catch (e) {
    const why = String(e && e.message ? e.message : e).split('\n')[0];
    results.push({ ok: false, name: 'accesso', label: 'Accesso come amministratore', why });
    console.log('Errore generale: ' + why);
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
  const failed = results.filter((r) => !r.ok);
  const lines = ['## Schermate dei pannelli', '', results.filter((r) => r.ok).length + ' riuscite, ' + failed.length + ' mancanti.', ''];
  for (const r of results) lines.push('- ' + (r.ok ? 'riuscita' : 'MANCA') + ': ' + r.label + ' (`' + r.name + '`)' + (r.ok ? '' : ' — ' + r.why));
  fs.writeFileSync(path.join(out, 'esito.md'), lines.join('\n') + '\n');
  process.exit(0);
})();

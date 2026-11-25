/**
 * Lavori lunghi in un browser vero (workflow «Prove in sandbox»): con la pagina aperta il lavoro avanza tappa per tappa e finisce, e «Annulla» lo ferma.
 * Il cron del sito è spento, quindi ad andare avanti è solo la pagina. Misura anche lo scorrimento orizzontale della Bacheca a 375 px (senza fallire).
 * Uso: NODE_PATH=<playwright> node prove-lavori.cjs <sito> avanza|annulla <cartella>. Esce con 1 se qualcosa non va.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.argv[2].replace(/\/$/, '');
const mode = process.argv[3];
const out = process.argv[4];
fs.mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'it-IT' });
  page.setDefaultTimeout(20000);
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  let rc = 0;
  const say = (ok, text) => { if (!ok) rc = 1; console.log(`- ${ok ? 'ok' : 'ERRORE'}: ${text}`); };
  try {
    await page.goto(base + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password'); // utente di prova di wp-env, nessun dato vero
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
    let runs = 0;
    const seen = new Set();
    page.on('response', async (r) => {
      if (!r.url().includes('admin-ajax.php') || !(r.request().postData() || '').includes('asem_long_job')) return;
      runs++;
      try { const j = await r.json(); if (j && j.success) seen.add(j.data.status + ':' + j.data.percent); } catch (e) { /* risposta interrotta dal cambio di pagina */ }
    });
    await page.goto(base + '/wp-admin/admin.php?page=asem-backup', { waitUntil: 'load' });
    say((await page.locator('.asem-longjob[data-asem-running]').count()) === 1, 'la pagina mostra il lavoro in corso');
    if (mode === 'avanza') {
      await page.waitForFunction(() => !document.querySelector('.asem-longjob[data-asem-running="1"]') && document.body.innerText.includes('pronta'), null, { timeout: 180000 });
      say(runs > 2 && [...seen].filter((s) => s.startsWith('running')).length > 1, `il lavoro avanza dalla pagina: ${runs} richieste, stati ${[...seen].slice(0, 6).join(' ')}`);
      say((await page.locator('button', { hasText: 'Scarica la copia pronta' }).count()) === 1, 'a lavoro finito la copia è pronta');
    } else {
      await page.waitForTimeout(700);
      await page.locator('.asem-longjob-cancel').click();
      await page.waitForFunction(() => { const b = document.querySelector('.asem-longjob'); return b && b.getAttribute('data-asem-running') === '0'; }, null, { timeout: 60000 });
      const msg = await page.locator('.asem-longjob-msg').innerText();
      say(/annullata/i.test(msg), '«Annulla» risponde: ' + msg);
      const before = runs;
      await page.waitForTimeout(3000);
      say(runs - before <= 1, 'dopo «Annulla» la pagina non chiede altre tappe');
    }
    await page.screenshot({ path: path.join(out, 'lavoro-' + mode + '.png'), fullPage: true });
    if (mode === 'avanza') {
      await page.setViewportSize({ width: 375, height: 900 });
      await page.goto(base + '/wp-admin/admin.php?page=asem', { waitUntil: 'load' });
      await page.waitForTimeout(600);
      const m = await page.evaluate(() => {
        const w = document.documentElement.clientWidth;
        const wide = [...document.querySelectorAll('#wpbody-content *')].map((e) => [Math.round(e.getBoundingClientRect().right), e.tagName + '.' + String(e.className).split(' ').join('.') + (e.name ? '[' + e.name + ']' : '')]).filter((x) => x[0] > w + 1).sort((a, b) => b[0] - a[0]).slice(0, 6);
        return { over: document.documentElement.scrollWidth - w, wide };
      });
      console.log(`- misura: Bacheca a 375 px, scorrimento orizzontale ${m.over} px; elementi oltre il bordo: ${m.wide.map((x) => x.join(' ')).join(' | ')}`);
    }
    say(errors.length === 0, 'nessun errore negli script delle pagine' + (errors.length ? ': ' + errors[0] : ''));
  } catch (e) {
    rc = 1;
    console.log('- ERRORE: ' + String(e.message || e).split('\n')[0] + (errors.length ? ' (script: ' + errors[0] + ')' : ''));
    await page.screenshot({ path: path.join(out, 'errore-' + mode + '.png'), fullPage: true }).catch(() => {});
  }
  await browser.close();
  process.exit(rc);
})();

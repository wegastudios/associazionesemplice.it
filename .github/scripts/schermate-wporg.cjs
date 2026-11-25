/**
 * Le sei schermate della pagina WordPress.org (1440×900), dal SOLO plugin gratuito con dati finti.
 * Uso: NODE_PATH=<cartella con playwright> node schermate-wporg.cjs <indirizzo-del-sito> <cartella-di-uscita>
 * Scrive screenshot-1.png … screenshot-6.png (stessi nomi di wordpress/wporg-assets) e esito.md con l'elenco delle voci di menu, così si controlla che non compaia nulla del Pro.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = (process.argv[2] || 'http://localhost:8888').replace(/\/$/, '');
const out = process.argv[3] || 'wporg';
fs.mkdirSync(out, { recursive: true });
const notes = [];

const HIDE = 'html{scrollbar-width:none!important}::-webkit-scrollbar{display:none!important}'
  + '#asem-demo-note,.asem-missing-band,.notice,.updated,#wpfooter,#screen-meta-links,#wp-admin-bar-updates,.update-plugins,.update-count{display:none!important}';

(async () => {
  const browser = await chromium.launch({ args: ["--lang=it-IT"] });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'it-IT' });
  page.setDefaultTimeout(30000);
  const settle = async () => {
    await page.addStyleTag({ content: HIDE });
    await page.evaluate(() => { document.querySelectorAll('img[loading=lazy]').forEach((i) => { i.loading = 'eager'; }); window.scrollTo(0, 0); });
    await page.evaluate(() => Promise.race([new Promise((r) => setTimeout(r, 8000)), Promise.all([...document.images].map((i) => { i.loading = "eager"; return i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; }); }))]));
    await page.waitForTimeout(800);
  };
  const shot = async (n, label, url) => {
    try {
      await page.goto(url.startsWith('http') ? url : base + url, { waitUntil: 'load' });
      await settle();
      await page.screenshot({ path: path.join(out, 'screenshot-' + n + '.png') });
      notes.push('- ok ' + n + ': ' + label);
    } catch (e) {
      notes.push('- MANCA ' + n + ': ' + label + ' — ' + String(e.message).split('\n')[0]);
    }
  };

  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password'); // utente di prova di wp-env, nessun dato vero
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

  const admin = (s) => '/wp-admin/admin.php?page=' + s;
  await shot(1, 'Bacheca', admin('asem'));
  await shot(2, 'Rubrica soci', admin('asem-people'));
  try {
    await page.goto(base + admin('asem-people'), { waitUntil: 'load' });
    const href = await page.locator('a[href*="page=asem-person&id="]', { hasText: /Mario.*Demo|Demo.*Mario/ }).first().getAttribute("href");
    await shot(3, 'Scheda socio', href);
  } catch (e) { notes.push('- MANCA 3: scheda socio — ' + String(e.message).split('\n')[0]); }
  await shot(4, 'Corsi ed eventi', admin('asem-activities'));
  await shot(5, 'Prima nota', admin('asem-ledger'));
  // 6: visitatore (senza barra di WordPress), contenuto largo come nella demo
  try {
    const vis = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: "it-IT" });
    await vis.goto(base + "/attivita-ed-eventi/", { waitUntil: "load" });
    await vis.addStyleTag({ content: HIDE + ":root{--wp--style--global--content-size:1160px!important}.wp-block-post-content>*,.entry-content>*{max-width:1160px!important}.wp-site-blocks{padding-top:0!important}#wpadminbar{display:none!important}html{margin-top:0!important}" });
    await vis.evaluate(() => Promise.race([new Promise((r) => setTimeout(r, 8000)), Promise.all([...document.images].map((i) => { i.loading = "eager"; return i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; }); }))]));
    await vis.waitForTimeout(800);
    await vis.screenshot({ path: path.join(out, "screenshot-6.png") });
    notes.push("- ok 6: Pagine pubbliche degli eventi (visitatore)");
  } catch (e) { notes.push("- MANCA 6: " + String(e.message).split('\n')[0]); }

  // Controllo «solo gratuito»: voci del menu del plugin e schede di ogni schermata admin.
  try {
    await page.goto(base + admin('asem'), { waitUntil: 'load' });
    const menu = await page.$$eval('#adminmenu .wp-submenu a, #adminmenu a.menu-top', (a) => a.map((x) => x.textContent.trim().replace(/\s+/g, ' ')));
    notes.push('', 'Voci del menu: ' + menu.join(' | '));
    for (const s of ['asem-people', 'asem-activities', 'asem-ledger']) {
      await page.goto(base + admin(s), { waitUntil: 'load' });
      const tabs = await page.$$eval('.nav-tab-wrapper a, [role=tab]', (a) => a.map((x) => x.textContent.trim()));
      notes.push('Schede di ' + s + ': ' + (tabs.join(' | ') || '(nessuna)'));
    }
  } catch (e) { notes.push('controllo menu non riuscito: ' + e.message); }

  await browser.close();
  fs.writeFileSync(path.join(out, 'esito.md'), '## Schermate WordPress.org (solo gratuito)\n\n' + notes.join('\n') + '\n');
  console.log(notes.join('\n'));
})().catch((e) => { console.log('Errore generale: ' + e.message); process.exit(0); });

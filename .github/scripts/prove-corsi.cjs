/**
 * Cassa dei corsi in un browser vero (workflow «Prove in sandbox»): l'importo proposto deve essere quello del server (attesi.json), anche cambiando mese e per
 * una persona nuova nella cassa per più persone. Poi schermate a 1440 e 375 px delle pagine dei corsi, senza scorrimento orizzontale.
 * Uso: NODE_PATH=<playwright> node prove-corsi.cjs <sito> <cartella>. Esce con 1 se un importo non coincide.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.argv[2].replace(/\/$/, '');
const out = process.argv[3];
const want = JSON.parse(fs.readFileSync(path.join(out, 'attesi.json'), 'utf8'));
const cents = (s) => Math.round(parseFloat(String(s).replace(/\./g, '').replace(',', '.')) * 100);
let bad = 0;
const lines = [];
const check = (name, got, exp) => { const ok = got === exp; if (!ok) bad++; lines.push(`- ${ok ? 'ok' : 'ERRORE'}: ${name}: ${got} (atteso ${exp})`); };

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'it-IT' });
  page.setDefaultTimeout(20000);
  page.on('pageerror', (e) => { bad++; lines.push('- ERRORE: errore nello script della pagina: ' + e.message); });
  try {
    await page.goto(base + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password'); // utente di prova di wp-env, nessun dato vero
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

    // Incasso di una persona non iscritta
    const amountOf = async (course) => {
      const loaded = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().method() !== 'OPTIONS');
      await page.goto(base + '/wp-admin/admin.php?page=asem-income&person_id=' + want.person, { waitUntil: 'load' });
      await loaded;
      await page.waitForTimeout(600);
      const before = await page.locator('#asem-lines .asem-line').count();
      await page.selectOption('#asem-add-activity-select', String(course));
      await page.waitForTimeout(200);
      return page.locator('#asem-lines .asem-line').nth(before);
    };
    let row = await amountOf(want.share.id);
    check('incasso, corso in proporzione', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.share.member);
    await page.screenshot({ path: path.join(out, 'cassa-1440.png'), fullPage: true });
    await row.locator('button', { hasText: '›' }).click();
    row = page.locator('#asem-lines .asem-line').last();
    check('incasso, mese dopo', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.fee);
    await row.locator('button', { hasText: '‹' }).click();
    row = page.locator('#asem-lines .asem-line').last();
    check('incasso, tornando al primo mese', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.share.member);
    row = await amountOf(want.full.id);
    check('incasso, corso a mese intero', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.full.member);
    check('il corso a mese intero è intero', want.full.member, want.fee);

    row = await amountOf(want.lessons.id);
    check('incasso, corso a lezioni', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.lessons.now);
    await row.locator('button', { hasText: '›' }).click();
    row = page.locator('#asem-lines .asem-line').last();
    check('incasso, corso a lezioni, mese dopo', cents(await row.locator('input[aria-label="Importo"]').inputValue()), want.lessons.next);

    // Schermate a due larghezze
    const pages = { 'corso-lezioni': 'asem-activity&id=' + want.lessons.id, corso: 'asem-activity&id=' + want.share.id, impostazioni: 'asem-settings', incasso: 'asem-income&person_id=' + want.person, bacheca: 'asem' };
    for (const w of [1440, 375]) {
      await page.setViewportSize({ width: w, height: 900 });
      for (const [name, slug] of Object.entries(pages)) {
        await page.goto(base + '/wp-admin/admin.php?page=' + slug, { waitUntil: 'load' });
        await page.waitForTimeout(600);
        await page.evaluate(() => document.querySelectorAll('#wpbody-content details').forEach((d) => { d.open = true; }));
        const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        lines.push(`- misura: ${name} a ${w}px, scorrimento orizzontale ${over}px`);
        await page.screenshot({ path: path.join(out, `${name}-${w}.png`), fullPage: true });
      }
    }

    // Cassa per più persone, ospite nuovo
    if (want.group) {
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(base + '/wp-admin/admin.php?page=asem-group', { waitUntil: 'load' });
      const data = JSON.parse(await page.locator('#asem-group-data').textContent());
      const c = data.courses.find((x) => x.id === want.share.id);
      check('cassa per più persone, ospite nuovo (dati)', c.part_guest, want.share.guest);
      const add = page.locator('button', { hasText: /ospite/i }).first();
      if (await add.count()) {
        await add.click();
        await page.waitForTimeout(200);
        const sel = page.locator('#asem-g-people select', { hasText: 'Corso (mese in corso)' }).last();
        await sel.selectOption(String(data.courses.indexOf(c)));
        await page.waitForTimeout(200);
        check('cassa per più persone, ospite nuovo (modulo)', cents(await page.locator('#asem-g-people .asem-line input[inputmode="decimal"]').last().inputValue()), want.share.guest);
        await page.screenshot({ path: path.join(out, 'cassa-gruppo-1440.png'), fullPage: true });
      } else { bad++; lines.push('- ERRORE: pulsante per aggiungere un ospite non trovato'); }
    }
  } catch (e) {
    bad++;
    lines.push('- ERRORE: ' + String(e.message || e).split('\n')[0]);
    await page.screenshot({ path: path.join(out, 'errore.png'), fullPage: true }).catch(() => {});
  }
  await browser.close();
  fs.writeFileSync(path.join(out, 'esito.md'), lines.join('\n') + '\n');
  console.log(lines.join('\n'));
  process.exit(bad ? 1 : 0);
})();

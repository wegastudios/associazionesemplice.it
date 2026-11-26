/**
 * Schermate nuove dei corsi in un browser vero (workflow «Prove in sandbox»), con i dati di prepara-uscita-corsi.php (dati.json nella cartella):
 *  fase 1 (14/05/2031): scheda del corso con prova, corso a lezioni (lezioni del mese, lezioni annullate: recupero e restituzione con conto e data),
 *          Bacheca, area soci con il pagamento di un mese in moneta (socio e figlio);
 *  fase 2 (giorno dopo la scadenza): riquadro «Importi a favore scaduti da chiudere» in Bacheca, chiusura con la conferma.
 * Ogni pagina a 1440 e 375 px, con la misura dello scorrimento orizzontale. Uso: NODE_PATH=<playwright> node prove-uscita-corsi.cjs <sito> <fase> <cartella>.
 * Esce con 1 se un controllo non riesce o se la pagina dà un errore di script.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.argv[2].replace(/\/$/, '');
const phase = process.argv[3];
const out = process.argv[4];
const data = JSON.parse(fs.readFileSync(path.join(out, 'dati.json'), 'utf8'));
let bad = 0;
const lines = [];
const ok = (name, cond, extra = '') => { if (!cond) bad++; lines.push(`- ${cond ? 'ok' : 'ERRORE'}: ${name}${extra ? ' (' + extra + ')' : ''}`); };

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'it-IT' });
  const page = await ctx.newPage();
  page.setDefaultTimeout(20000);
  page.on('pageerror', (e) => { bad++; lines.push('- ERRORE: errore nello script della pagina: ' + e.message); });
  page.on('dialog', (d) => d.accept());
  const login = async (user, pass) => {
    await page.goto(base + '/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
  };
  const shots = async (name, url, prep) => {
    for (const w of [1440, 375]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.goto(url, { waitUntil: 'load' });
      await page.waitForTimeout(500);
      if (prep) await prep();
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      lines.push(`- misura: ${name} a ${w}px, fase ${phase}, scorrimento orizzontale ${over}px`);
      await page.screenshot({ path: path.join(out, `${phase}-${name}-${w}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  };
  const admin = (slug) => base + '/wp-admin/admin.php?page=' + slug;
  const text = async () => (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  try {
    await login('admin', 'password'); // utente di prova di wp-env, nessun dato vero
    if (phase === '1') {
      await shots('corso-con-prova', admin('asem-activity&id=' + data.trial));
      await page.goto(admin('asem-activity&id=' + data.trial));
      let t = await text();
      ok('scheda del corso con prova: riquadro «Prove gratuite» con chi l\'ha fatta', t.includes('Prove gratuite') && t.includes('Senzaiscrizione'));

      await shots('corso-a-lezioni', admin('asem-activity&id=' + data.lessons));
      await page.goto(admin('asem-activity&id=' + data.lessons));
      t = await text();
      ok('corso a lezioni: «Lezioni del mese» e «Lezioni annullate»', t.includes('Lezioni del mese') && t.includes('Lezioni annullate'));
      // recupero per chi non aveva pagato
      const rowDue = page.locator('#asem-credits li', { hasText: data.due }).filter({ hasText: '13/05/2031' }).first();
      await rowDue.locator('input[name="makeup_date"]').fill('2031-05-30');
      await Promise.all([page.waitForLoadState('load'), rowDue.locator('button[value="makeup"]').click()]);
      await page.waitForTimeout(300);
      t = await text();
      ok('lezione annullata: recupero segnato dalla scheda', t.includes('recupero il 30/05/2031'));
      // restituzione per chi aveva pagato, con conto e data
      const rowPaid = page.locator('#asem-credits li', { hasText: data.uno }).filter({ hasText: '13/05/2031' }).first();
      await rowPaid.locator('input[name="refund_date"]').fill('2031-05-14');
      await Promise.all([page.waitForLoadState('load'), rowPaid.locator('button[value="refunded"]').click()]);
      await page.waitForTimeout(300);
      const after = page.locator('#asem-credits li', { hasText: data.uno }).filter({ hasText: '13/05/2031' }).first();
      ok('lezione annullata: restituzione con conto e data dalla scheda', (await after.innerText()).includes('restituito'), await after.innerText());
      await shots('corso-a-lezioni-dopo', admin('asem-activity&id=' + data.lessons) + '#asem-credits');

      await shots('bacheca', admin('asem'));
      await page.goto(admin('asem'));
      ok('Bacheca: prove fatte e non ancora iscritti', (await text()).includes('Prove fatte, non ancora iscritti'));

      // area soci: pagamento in moneta
      await ctx.clearCookies();
      await login(data.member.login, data.member.pass);
      await shots('area-soci-moneta', data.area);
      await page.goto(data.area, { waitUntil: 'load' });
      t = await text();
      ok('area soci: sezione «Oppure paga in ' + data.coins + '» con il socio e il figlio', t.includes('Oppure paga in ' + data.coins) && t.includes('Socio Moneta') && t.includes('Figlio Moneta'), t.includes('Oppure paga') ? '' : t.slice(0, 300));
      const row = page.locator('.asemf-coins li', { hasText: 'Figlio Moneta' }).first();
      await Promise.all([page.waitForLoadState('load'), row.locator('button').first().click()]);
      await page.waitForTimeout(500);
      t = await text();
      ok('area soci: il genitore paga il mese del figlio, saldo scalato', t.includes('Pagato con 40 ' + data.coins) && t.includes('Saldo: 60 ' + data.coins), t.slice(0, 400));
      await shots('area-soci-moneta-dopo', data.area);
    } else {
      await shots('bacheca-scaduti', admin('asem'));
      await page.goto(admin('asem'));
      ok('Bacheca: riquadro «Importi a favore scaduti da chiudere»', (await text()).includes('Importi a favore scaduti da chiudere'));
      const card = page.locator('.asem-card', { hasText: 'Importi a favore scaduti da chiudere' });
      await card.locator('input[name="confirm"]').check();
      await Promise.all([page.waitForLoadState('load'), card.locator('button').first().click()]);
      await page.waitForTimeout(300);
      ok('Bacheca: chiusi con la conferma, il riquadro sparisce', !(await text()).includes('Importi a favore scaduti da chiudere'));
      await shots('bacheca-scaduti-chiusi', admin('asem'));
    }
  } catch (e) {
    bad++;
    lines.push('- ERRORE: ' + String(e.message || e).split('\n')[0]);
    await page.screenshot({ path: path.join(out, `${phase}-errore.png`), fullPage: true }).catch(() => {});
  }
  await browser.close();
  fs.appendFileSync(path.join(out, 'esito.md'), lines.join('\n') + '\n');
  console.log(lines.join('\n'));
  process.exit(bad ? 1 : 0);
})();

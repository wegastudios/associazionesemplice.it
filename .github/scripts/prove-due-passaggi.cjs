/**
 * Verifica in due passaggi in un browser vero (workflow «Prove in sandbox»), con i dati di prepara-due-passaggi.php (dati.json nella cartella):
 *  1) segreteria con la verifica accesa: su wp-login la password giusta porta al modulo del codice (non in wp-admin), il codice sbagliato dà il
 *     messaggio, il codice giusto (TOTP calcolato qui) fa entrare;
 *  2) obbligo «dipendenti e segreteria»: il dipendente senza verifica va alla pagina di attivazione e non apre altre pagine del pannello;
 *  3) obbligo «soci»: il socio senza verifica vede nell'area soci solo l'attivazione;
 *  4) socia con la verifica: la pagina del codice offre «Mandami il link» (con la segreteria come altra via) sia dopo la password sia dopo il link via email.
 * Pagine del codice e dell'attivazione a 1440 e 375 px, con la misura dello scorrimento orizzontale (oltre 0 è un errore) e le schermate.
 * Uso: NODE_PATH=<playwright> node prove-due-passaggi.cjs <sito> <cartella>. Esce con 1 se un controllo non riesce.
 */
const { chromium } = require('playwright');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const base = process.argv[2].replace(/\/$/, '');
const out = process.argv[3];
const data = JSON.parse(fs.readFileSync(path.join(out, 'dati.json'), 'utf8'));
let bad = 0;
const lines = [];
const ok = (name, cond, extra = '') => { if (!cond) bad++; lines.push(`- ${cond ? 'ok' : 'ERRORE'}: ${name}${extra ? ' (' + extra + ')' : ''}`); };

/** TOTP RFC 6238 (SHA1, 6 cifre, 30 secondi), scritto qui a parte. Il passo dopo quello di adesso: quello di adesso può essere già stato usato all'accensione. */
const totp = (b32, offset = 30) => {
  const alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const ch of b32) bits += alpha.indexOf(ch).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const step = Math.floor((Date.now() / 1000 + offset) / 30);
  const msg = Buffer.alloc(8);
  msg.writeUInt32BE(Math.floor(step / 0x100000000), 0);
  msg.writeUInt32BE(step % 0x100000000, 4);
  const h = crypto.createHmac('sha1', Buffer.from(bytes)).update(msg).digest();
  const o = h[h.length - 1] & 15;
  const v = (h.readUInt32BE(o) & 0x7fffffff) % 1000000;
  return String(v).padStart(6, '0');
};
const wrong = (b32) => { const w = new Set([totp(b32, -30), totp(b32, 0), totp(b32, 30), totp(b32, 60)]); for (let i = 0; i < 20; i++) { const c = String(100000 + i * 37); if (!w.has(c)) return c; } return '000000'; };

(async () => {
  const browser = await chromium.launch();
  const fresh = async () => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'it-IT' });
    const page = await ctx.newPage();
    page.setDefaultTimeout(20000);
    page.on('pageerror', (e) => { bad++; lines.push('- ERRORE: errore nello script della pagina: ' + e.message); });
    page.on('dialog', (d) => d.accept());
    return { ctx, page };
  };
  const text = async (page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  /** Misura e schermata della pagina com'è adesso, a 1440 e 375 px (senza ricaricarla: le pagine del codice nascono da un invio). */
  const shots = async (page, name) => {
    for (const w of [1440, 375]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.waitForTimeout(300);
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      ok(`${name} a ${w}px senza scorrimento orizzontale`, over <= 0, `${over}px`);
      await page.screenshot({ path: path.join(out, `${name}-${w}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  };
  const wpLogin = async (page, user, pass) => {
    await page.goto(base + '/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
  };
  const memberLogin = async (page, email, pass) => {
    await page.goto(data.login);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', pass);
    await Promise.all([page.waitForLoadState('load'), page.locator('button[value="password_login"]').click()]);
  };
  try {
    // 1) Segreteria con la verifica accesa
    let { ctx, page } = await fresh();
    await wpLogin(page, data.sec.login, data.sec.pass);
    ok('wp-login: dopo la password compare il modulo del codice', (await page.locator('input[name="asem_tfa_code"]').count()) === 1 && !page.url().includes('/wp-admin'), page.url());
    const cookies = (await ctx.cookies()).filter((c) => c.name.startsWith('wordpress_logged_in_'));
    ok('wp-login: senza codice nessun cookie di accesso', cookies.length === 0);
    await shots(page, 'wp-codice');
    await page.fill('input[name="asem_tfa_code"]', wrong(data.sec.secret));
    await Promise.all([page.waitForLoadState('load'), page.locator('#loginform input[type="submit"]').click()]);
    ok('wp-login: con il codice sbagliato compare il messaggio', (await text(page)).includes('Il codice non è corretto') && !page.url().includes('/wp-admin'));
    await page.fill('input[name="asem_tfa_code"]', totp(data.sec.secret));
    await Promise.all([page.waitForLoadState('load'), page.locator('#loginform input[type="submit"]').click()]);
    ok('wp-login: con il codice giusto si entra in wp-admin', page.url().includes('/wp-admin'), page.url());
    await ctx.close();

    // 2) Dipendente senza verifica, obbligo acceso
    ({ ctx, page } = await fresh());
    await wpLogin(page, data.emp.login, data.emp.pass);
    ok('obbligo dipendenti: dopo il login va alla pagina di attivazione', page.url().includes('page=asem-tfa') && (await text(page)).includes('richiede la verifica in due passaggi'), page.url());
    await shots(page, 'pannello-attivazione');
    await page.goto(base + '/wp-admin/admin.php?page=asem', { waitUntil: 'load' });
    ok('obbligo dipendenti: un\'altra pagina del pannello riporta all\'attivazione', page.url().includes('page=asem-tfa'), page.url());
    await ctx.close();

    // 3) Socio con l'accesso proprio, obbligo per i soci acceso
    ({ ctx, page } = await fresh());
    await memberLogin(page, data.free.email, data.free.pass);
    await page.goto(data.area, { waitUntil: 'load' });
    const t3 = await text(page);
    ok('obbligo soci: l\'area soci mostra solo l\'attivazione', t3.includes('Accendi la verifica in due passaggi') && t3.includes('richiede la verifica') && (await page.locator('input[name="phone"]').count()) === 0, t3.slice(0, 200));
    await shots(page, 'area-attivazione');
    await ctx.close();

    // 4) Socia con la verifica: «Mandami il link» dopo la password e anche dopo il link via email (decisione del titolare «Recupero anche con email»)
    ({ ctx, page } = await fresh());
    await memberLogin(page, data.mem.email, data.mem.pass);
    const t4 = await text(page);
    ok('area soci, dopo la password: pagina del codice con «Mandami il link»', (await page.locator('input[name="tfa_code"]').count()) === 1 && t4.includes('Mandami il link'), t4.slice(0, 200));
    await shots(page, 'soci-codice-password');
    await ctx.close();
    ({ ctx, page } = await fresh());
    ok('area soci: c\'è il link di accesso via email della prova', data.mem.link !== '');
    await page.goto(data.mem.link, { waitUntil: 'load' });
    await Promise.all([page.waitForLoadState('load'), page.locator('button', { hasText: 'Entra' }).first().click()]);
    const t5 = await text(page);
    ok('area soci, dopo il link via email: c\'è «Mandami il link», con la segreteria come altra via', (await page.locator('input[name="tfa_code"]').count()) === 1 && t5.includes('Mandami il link') && t5.includes('Oppure chiedi alla segreteria di spegnere la verifica'), t5.slice(0, 200));
    await shots(page, 'soci-codice-link');
    await Promise.all([page.waitForLoadState('load'), page.locator('button', { hasText: 'Mandami il link' }).click()]);
    ok('area soci, dopo il link via email: «Mandami il link» risponde e il codice si può ancora scrivere', (await text(page)).includes('Ti abbiamo scritto') && (await page.locator('input[name="tfa_code"]').count()) === 1);
    await page.fill('input[name="tfa_code"]', totp(data.mem.secret));
    await Promise.all([page.waitForLoadState('load'), page.locator('button', { hasText: 'Entra' }).first().click()]);
    ok('area soci: con il codice giusto si entra', (await text(page)).includes('Accesso riuscito') || page.url().startsWith(data.area), page.url());
    await ctx.close();
  } catch (e) {
    bad++;
    lines.push('- ERRORE: ' + String(e.message || e).split('\n')[0]);
  }
  await browser.close();
  fs.appendFileSync(path.join(out, 'esito.md'), lines.join('\n') + '\n');
  console.log(lines.join('\n'));
  process.exit(bad ? 1 : 0);
})();

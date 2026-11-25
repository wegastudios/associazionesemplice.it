/**
 * Prove visive delle pagine di ruolo (segreteria, tesoriere), «1 partecipante», origine tessera e pulsante della scheda, a 1440 e 375.
 * Uso: node prove-visive-1338.cjs <sito> <dati.json> <cartella>. Solo utenti di prova di wp-env. Esce con 1 se una prova non riesce.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const [base, file, out] = [process.argv[2].replace(/\/$/, ''), process.argv[3], process.argv[4]];
const d = JSON.parse(fs.readFileSync(file, 'utf8'));
fs.mkdirSync(out, { recursive: true });
const notes = [];
let bad = 0;
const ok = (c, t) => { notes.push((c ? '- ok ' : '- MANCA ') + t); if (!c) { bad++; } };

(async () => {
  const browser = await chromium.launch({ args: ['--lang=it-IT'] });
  for (const [w, h] of [[1440, 900], [375, 812]]) {
    const ctx = () => browser.newContext({ viewport: { width: w, height: h }, locale: 'it-IT' });
    const shot = async (p, n) => { await p.waitForTimeout(400); await p.screenshot({ path: path.join(out, n + '-' + w + '.png') }); };
    const wide = async (p, n) => ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), n + ' ' + w + ': nessuno scorrimento laterale');
    const step = async (t, fn) => { try { await fn(); } catch (e) { ok(false, t + ' ' + w + ': ' + String(e.message).split('\n')[0]); } };
    for (const k of ['segreteria', 'tesoriere']) {
      await step(k + ' visitatore', async () => {
        const c = await ctx();
        const p = await c.newPage();
        await p.goto(d[k]);
        const t = await p.textContent('body');
        const href = await p.getAttribute('.asemf-card a.asemf-btn', 'href', { timeout: 3000 }).catch(() => null);
        await shot(p, k + '-visitatore');
        ok(/Questa pagina è per chi lavora con l.associazione/.test(t), k + ' ' + w + ', visitatore: invito dello staff');
        ok(/entra dall.area soci/.test(t), k + ' ' + w + ', visitatore: rimando all\'area soci');
        ok(!!href && /redirect_to=/.test(href), k + ' ' + w + ', visitatore: «Entra» porta con sé la pagina di partenza (' + href + ')');
        await wide(p, k + ' visitatore');
        await c.close();
      });
      await step(k + ' socio', async () => {
        if (!d.sessione) { throw new Error('nessuna sessione di prova'); }
        const c = await ctx();
        await c.addCookies([{ name: 'asem_s', value: d.sessione, url: base }]);
        const p = await c.newPage();
        await p.goto(d[k]);
        const t = await p.textContent('body');
        await shot(p, k + '-socio');
        ok(/Hai fatto l.accesso come socio/.test(t), k + ' ' + w + ', socio entrato: spiega l\'accesso al sito');
        await wide(p, k + ' socio');
        await c.close();
      });
    }
    for (const [k, u] of [['segreteria', 'prova_segreteria'], ['tesoriere', 'prova_tesoriere']]) {
      await step('ritorno ' + k, async () => {
        const c = await ctx();
        const p = await c.newPage();
        await p.goto(d[k]);
        await p.click('.asemf-card a.asemf-btn');
        await p.fill('#user_login', u);
        await p.fill('#user_pass', 'password'); // utente di prova di wp-env, nessun dato vero
        await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#wp-submit')]);
        const t = await p.textContent('body');
        await shot(p, k + '-entrato');
        ok(p.url() === d[k], k + ' ' + w + ': dopo l\'accesso si torna alla pagina (' + p.url() + ')');
        ok(!/Questa pagina è riservata|Questa pagina è per chi lavora/.test(t), k + ' ' + w + ': la pagina si apre a chi ha il ruolo');
        await c.close();
      });
    }
    await step('scheda', async () => {
      const c = await ctx();
      const p = await c.newPage();
      await p.goto(d.attivita);
      const b = await p.$eval('.asemf-card-end a.asemf-btn', (a) => {
        const m = a.querySelector('.asemf-more');
        return { t: a.textContent.replace(/\s+/g, ' ').trim(), card: Math.round(a.closest('.asemf-card').getBoundingClientRect().width), m: m ? getComputedStyle(m).position : 'manca', nb: !!m && m.innerHTML.startsWith('&nbsp;') };
      });
      await p.$eval('.asemf-card-end', (e) => e.scrollIntoView({ block: 'center' }));
      await shot(p, 'scheda');
      ok(b.t === 'Dettagli e prenotazione' && b.nb, 'scheda ' + w + ': testo del pulsante «' + b.t + '»');
      ok(b.m === 'static', 'scheda ' + w + ': testo visibile per intero (scheda di ' + b.card + ' px, parte finale ' + b.m + ')');
      await wide(p, 'scheda');
      await c.close();
    });
    await step('pannello', async () => {
      const c = await ctx();
      const p = await c.newPage();
      await p.goto(base + '/wp-login.php');
      await p.fill('#user_login', 'admin');
      await p.fill('#user_pass', 'password'); // utente di prova di wp-env
      await Promise.all([p.waitForURL(/wp-admin/), p.click('#wp-submit')]);
      const seen = async (sel, re, n, label) => {
        const t = await p.textContent('#wpbody');
        await p.evaluate(([s, r]) => {
          const e = [...document.querySelectorAll(s)].find((x) => new RegExp(r).test(x.textContent));
          if (e) { for (let x = e; x; x = x.parentElement) { if (x.tagName === 'DETAILS') { x.open = true; } } e.scrollIntoView({ block: 'center' }); }
        }, [sel, re.source]);
        await shot(p, n);
        ok(re.test(t), label + ' ' + w);
        return t;
      };
      await p.goto(d.attivita_admin);
      const t = await seen('#wpbody p', /1 partecipante /, 'un-partecipante', 'attività: «1 partecipante»');
      ok(!/1 partecipanti/.test(t), 'attività ' + w + ': mai «1 partecipanti»');
      await p.goto(d.persona);
      await seen('#wpbody td', /Inserita a mano/, 'origine-tessera', 'persona: origine della tessera in italiano');
      await c.close();
    });
  }
  await browser.close();
  fs.writeFileSync(path.join(out, 'esito.md'), '## Prove visive, build ' + d.versione + '\n\n' + notes.join('\n') + '\n');
  console.log(notes.join('\n'));
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.log('Errore generale: ' + e.message); process.exit(1); });

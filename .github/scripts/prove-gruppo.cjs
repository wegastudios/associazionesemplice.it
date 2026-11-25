/**
 * Prove nel browser della prenotazione di gruppo: modulo, riepilogo, conferma, errori, profilo, rimozione e conferma dell'interessato, «paga chi partecipa»,
 * a 1440 e 375, anche senza JavaScript e da tastiera; poi due richieste HTTP vere in parallelo sull'ultimo posto.
 * Uso: node prove-gruppo.cjs <sito> <dati.json> <cartella>. Solo utenti di prova di wp-env.
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
const GEN = 'Non è stato possibile prenotare una delle persone indicate';

(async () => {
  const browser = await chromium.launch({ args: ['--lang=it-IT'] });
  const as = async (session, w, js) => {
    const c = await browser.newContext({ viewport: { width: w, height: w > 700 ? 900 : 812 }, locale: 'it-IT', javaScriptEnabled: js });
    await c.addCookies([{ name: 'asem_s', value: session, url: base }]);
    const p = await c.newPage();
    p.setDefaultTimeout(15000);
    return [c, p];
  };
  const step = async (t, fn) => { try { await fn(); } catch (e) { ok(false, t + ': ' + String(e.message).split('\n')[0]); } };
  const form = 'form:has(input[value="asem_front_book_group"]):has(input[name="new_guest_first[]"])';
  const conf = 'form:has(input[name="group_token"])';
  for (const w of [1440, 375]) {
    const shot = async (p, n) => { await p.waitForTimeout(300); await p.screenshot({ path: path.join(out, n + '-' + w + '.png'), fullPage: true }); };
    const flat = async (p, n) => ok(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth).catch(() => true), n + ' ' + w + ': nessuno scorrimento laterale');

    await step('errore ' + w, async () => {
      const [c, p] = await as(d.s_paola, w, true);
      await p.goto(d['senzajs' + w].url);
      await p.click('details.asemf-group > summary');
      await shot(p, 'modulo');
      await flat(p, 'modulo');
      await p.click(form + ' button[type=submit]');
      await p.waitForLoadState('load');
      ok(/Scegli almeno una persona/.test(await p.textContent('body')), 'errore ' + w + ': modulo vuoto');
      await shot(p, 'errore-vuoto');
      await p.click('details.asemf-group > summary');
      await p.fill(form + ' input[name="other_last[]"] >> nth=0', 'Nessuno');
      await p.fill(form + ' input[name="other_card[]"] >> nth=0', '0000');
      await p.click(form + ' button[type=submit]');
      await p.waitForLoadState('load');
      ok(/Totale a tuo carico/.test(await p.textContent('body')), 'errore ' + w + ': il riepilogo non dice se la tessera esiste');
      await p.click(conf + ' button[type=submit]');
      await p.waitForLoadState('load');
      ok((await p.textContent('body')).includes(GEN), 'errore ' + w + ': tessera inesistente, frase generica');
      ok((await p.locator('[role=alert]').count()) > 0, 'errore ' + w + ': il messaggio si annuncia (role=alert)');
      await shot(p, 'errore-generico');
      await c.close();
    });

    await step('senza JavaScript ' + w, async () => {
      const [c, p] = await as(d.s_paola, w, false);
      await p.goto(d['senzajs' + w].url);
      await p.click('details.asemf-group > summary');
      await p.check(form + ' input[name="me"]');
      await p.locator(form + ' input[name="new_guest_first[]"]').nth(0).fill('Nuova' + w);
      await p.locator(form + ' input[name="new_guest_last[]"]').nth(0).fill('Ospite');
      await p.locator(form + ' input[name="new_guest_phone[]"]').nth(0).fill('333 77700' + String(w).slice(-2));
      await p.locator(form + ' input[name="other_last[]"]').nth(0).fill('Socio');
      await p.locator(form + ' input[name="other_card[]"]').nth(0).fill('9202');
      await p.click(form + ' button[type=submit]');
      await p.waitForLoadState('load');
      const t = await p.textContent('body');
      ok(/Riepilogo della prenotazione/.test(t) && /15,00/.test(t), 'senza JavaScript ' + w + ': totale visibile prima della conferma (15,00)');
      ok(/socio con tessera n\. 9202/.test(t) && !/Gino/.test(t), 'senza JavaScript ' + w + ': l\'altro socio è la tessera, mai il nome');
      await shot(p, 'riepilogo-senzajs');
      await p.click(conf + ' button[type=submit]');
      await p.waitForLoadState('load');
      ok(/Prenotazioni registrate per 3 persone/.test(await p.textContent('body')), 'senza JavaScript ' + w + ': confermata per 3 persone');
      await shot(p, 'confermata-senzajs');
      await c.close();
    });

    await step('tastiera ' + w, async () => {
      const [c, p] = await as(d.s_paola, w, true);
      await p.goto(d['tastiera' + w].url);
      const tab = async (test, label) => {
        for (let i = 0; i < 150; i++) {
          await p.keyboard.press('Tab');
          if (await p.evaluate(test)) { return; }
        }
        throw new Error('da tastiera non si arriva a ' + label);
      };
      await tab(() => document.activeElement.tagName === 'SUMMARY' && /Prenota per più persone/.test(document.activeElement.textContent), '«Prenota per più persone»');
      await p.keyboard.press('Enter');
      await tab(() => document.activeElement.name === 'me', 'la spunta «Io»');
      await p.keyboard.press('Space');
      ok(await p.isChecked(form + ' input[name="me"]'), 'tastiera ' + w + ': spunta con la barra spaziatrice');
      await tab(() => /Vedi il totale/.test(document.activeElement.textContent) && document.activeElement.tagName === 'BUTTON', '«Vedi il totale»');
      ok(await p.evaluate(() => { const s = getComputedStyle(document.activeElement); return s.outlineStyle !== 'none' || s.boxShadow !== 'none'; }), 'tastiera ' + w + ': il pulsante attivo si vede');
      await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.keyboard.press('Enter')]);
      await tab(() => /Conferma e prenota/.test(document.activeElement.textContent) && document.activeElement.tagName === 'BUTTON', '«Conferma e prenota»');
      await shot(p, 'tastiera-riepilogo');
      await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.keyboard.press('Enter')]);
      ok(/Prenotazione registrata/.test(await p.textContent('body')), 'tastiera ' + w + ': prenotata senza mouse');
      const nolabel = await p.evaluate(() => [...document.querySelectorAll('details.asemf-group input:not([type=hidden]), details.asemf-group select')].filter((e) => !e.labels?.length && !e.getAttribute('aria-label')).length);
      ok(nolabel === 0, 'modulo ' + w + ': ogni campo ha un nome accessibile (senza: ' + nolabel + ')');
      await c.close();
    });

    await step('Gino ' + w, async () => {
      const [c, p] = await as(d.s_gino, w, true);
      p.on('dialog', (x) => x.accept());
      await p.goto(d.mie);
      ok(/Un altro socio ha prenotato per te/.test(await p.textContent('body')), 'Gino ' + w + ': vede la prenotazione fatta per lui');
      ok(/a carico di chi ha prenotato/.test(await p.textContent('body')), 'Gino ' + w + ': legge che non deve pagare');
      await shot(p, 'gino-area');
      await flat(p, 'gino');
      const item = (name) => p.locator('li', { hasText: name });
      await item('Prova conferma ' + w).locator('button', { hasText: 'Conferma' }).click();
      await p.waitForLoadState('load');
      ok(/Prenotazione confermata/.test(await p.textContent('body')), 'Gino ' + w + ': conferma');
      await item('Prova senzajs ' + w).locator('button', { hasText: 'Non partecipo' }).click();
      await p.waitForLoadState('load');
      ok(/non sei più in questa prenotazione/.test(await p.textContent('body')), 'Gino ' + w + ': si toglie');
      await shot(p, 'gino-dopo');
      await p.goto(d.profilo);
      ok(await p.isChecked('input[name="booking_by_others"]'), 'profilo ' + w + ': la spunta parte accesa');
      await shot(p, 'profilo');
      for (const on of [false, true]) {
        await p.setChecked('input[name="booking_by_others"]', on);
        await p.click('form:has(input[name="booking_by_others"]) button[type=submit]');
        await p.waitForLoadState('load');
        await p.goto(d.profilo);
        ok((await p.isChecked('input[name="booking_by_others"]')) === on, 'profilo ' + w + ': la scelta resta ' + (on ? 'accesa' : 'spenta'));
      }
      await c.close();
    });

    await step('paga chi partecipa ' + w, async () => {
      const c = await browser.newContext({ viewport: { width: w, height: 900 }, locale: 'it-IT' });
      const p = await c.newPage();
      p.setDefaultTimeout(15000);
      let asked = '';
      p.on('dialog', (x) => { asked = x.message(); x.accept(); });
      await p.goto(base + '/wp-login.php');
      await p.fill('#user_login', 'admin');
      await p.fill('#user_pass', 'password'); // utente di prova di wp-env
      await Promise.all([p.waitForURL(/wp-admin/), p.click('#wp-submit')]);
      await p.goto(d.incasso + '&person_id=' + d.gino);
      const label = 'Prova paga ' + w;
      await p.waitForFunction((l) => [...document.querySelectorAll('#asem-add-booking-select option')].some((o) => o.textContent.includes(l)), label);
      ok((await p.textContent('#asem-lines')).indexOf(label) < 0, 'paga chi partecipa ' + w + ': non si compila da solo');
      const v = await p.evaluate((l) => [...document.querySelectorAll('#asem-add-booking-select option')].find((o) => o.textContent.includes(l)).value, label);
      await p.selectOption('#asem-add-booking-select', v);
      ok(/a carico di Paola Prenota/.test(asked), 'paga chi partecipa ' + w + ': chiede conferma («' + asked + '»)');
      await shot(p, 'paga-chi-partecipa');
      await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form.asem-income button[type=submit], form.asem-income input[type=submit]')]);
      ok(/Incasso registrato/.test(await p.textContent('body')), 'paga chi partecipa ' + w + ': incasso registrato');
      await c.close();
    });
  }

  // Due richieste HTTP vere in parallelo sull'ultimo posto: due soci, due posti, ognuno prenota sé e un ospite nuovo
  await step('gare', async () => {
    const [c1, p1] = await as(d.s_paola, 1440, true);
    const [c2, p2] = await as(d.s_rina, 1440, true);
    const ready = async (p, ev, tag) => {
      await p.goto(ev.url);
      await p.click('details.asemf-group > summary');
      await p.check(form + ' input[name="me"]');
      await p.locator(form + ' input[name="new_guest_first[]"]').nth(0).fill('Gara' + tag);
      await p.locator(form + ' input[name="new_guest_last[]"]').nth(0).fill('Ospite');
      await p.locator(form + ' input[name="new_guest_phone[]"]').nth(0).fill('334 55' + tag.padStart(5, '0'));
      await p.click(form + ' button[type=submit]');
      await p.waitForLoadState('load');
      return p.$eval(conf, (f) => ({ url: f.getAttribute('action'), data: Object.fromEntries(new FormData(f)) }));
    };
    let n = 0;
    for (const [i, ev] of d.gare.entries()) {
      const f1 = await ready(p1, ev, '1' + i);
      const f2 = await ready(p2, ev, '2' + i);
      const t0 = Date.now();
      const times = [];
      const send = (c, f) => c.request.post(f.url, { form: f.data, headers: { referer: ev.url } }).then(async (r) => { times.push(Date.now() - t0); return r.text(); });
      const [a, b] = await Promise.all([send(c1, f1), send(c2, f2)]);
      const win = [a, b].filter((x) => /Prenotazioni registrate per 2 persone/.test(x)).length;
      const lose = [a, b].filter((x) => /Posti esauriti|Non ci sono posti|Un&#039;altra operazione|Un'altra operazione/.test(x)).length;
      ok(win === 1 && lose === 1, 'gara ' + i + ': uno entra e l\'altro legge che i posti sono finiti (vince ' + win + ', rifiuta ' + lose + ', risposte a ' + times.join(' e ') + ' ms)');
      n++;
    }
    ok(n === d.gare.length, 'gare eseguite: ' + n);
    await c1.close();
    await c2.close();
  });
  await browser.close();
  fs.writeFileSync(path.join(out, 'esito.md'), '## Prenotazione di gruppo, prove nel browser\n\n' + notes.join('\n') + '\n');
  console.log(notes.join('\n'));
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.log(notes.join('\n')); console.log('Errore generale: ' + e.message); process.exit(1); });

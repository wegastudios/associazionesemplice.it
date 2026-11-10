# Tabella di confronto: plugin o gestionale online

Specifica di progetto per la sessione «Portale: vendita e area clienti». Completa [ARCHITETTURA_DOMINI.md](ARCHITETTURA_DOMINI.md)
(catalogo `catalogo/v1`, §4) e sostituisce il confronto scritto a mano per la parte che confronta i due prodotti.

## 1. Cosa si confronta (tre tabelle, non una)

Chi prende il plugin non prende il SaaS: sono **alternative**. Quindi serve una guida alla scelta, poi il dettaglio di ciascuno.

| # | Tabella | Dove | Colonne | Da dove arriva |
|---|---|---|---|---|
| 1 | **Plugin o gestionale online?** (guida alla scelta) | `associazionesemplice.it/confronto` | Plugin · Gestionale online | testo del portale + celle riempite dal catalogo |
| 2 | **Confronto dei piani del plugin** (oggi `portale/content/confronto.md`) | `associazionesemplice.it/wordpress/confronto` (e il popup della demo `/confronto-demo`, invariato) | Gratuito · Contabilità · Contabilità & Fiscale | catalogo del plugin (`features`, `plans`, `prices`) |
| 3 | **Cosa comprende il gestionale e i suoi pacchetti** | `associazionesemplice.it/saas/prezzi` | base · un pacchetto per riga con il prezzo | catalogo del SaaS (`features`, `addons`, `prices`) |

La tabella 1 è nuova e va nella home di vendita. Le altre due esistono già in forma diversa e si generano dal catalogo, non a mano
(regola già decisa: nessun prezzo o limite che non venga dal catalogo).

## 2. Tabella 1: guida alla scelta (bozza dei contenuti)

Testo sopra: «Tutti e due gestiscono soci, tessere, prima nota, corsi ed eventi. Cambia **dove stanno i dati** e **chi si occupa
dell'installazione**.» Le celle `{…}` si riempiono dal catalogo; la riga segnata ★ va **verificata** prima di pubblicare
(non le ho potute confermare dai documenti).

| | Plugin WordPress | Gestionale online |
|---|---|---|
| **A chi serve** | a chi ha già un sito WordPress e vuole i dati sul proprio spazio web | a chi vuole un gestionale pronto, senza WordPress e senza installare nulla |
| **Dove stanno i dati** | sul tuo spazio web | sui nostri server, con campi sensibili cifrati e copie di sicurezza |
| **Installazione e aggiornamenti** | installi e aggiorni tu dal pannello di WordPress | nessuna installazione; aggiornamenti automatici |
| **Serve un sito WordPress** | sì | no (il sito dell'associazione è un pacchetto a parte) |
| Soci, tessere e libro soci | ✓ | ✓ |
| Prima nota e ricevute | ✓ | ✓ |
| Corsi, eventi e presenze | ✓ | ✓ |
| **Area soci** (tessera, prenotazioni) | ✓ area riservata nel tuo sito | pacchetto «Area soci e pagamenti» · {addon:member_area} |
| **Pagamenti online** (Stripe, PayPal) | con Pro | con il pacchetto Area soci |
| **Risorse e prenotazioni** (campi, sale) | con Pro | pacchetto «Risorse e prenotazioni» · {addon:resources_hourly_web} |
| **Fattura elettronica** | tramite il ponte con la piattaforma, **solo nel piano «Contabilità & Fiscale»** (in arrivo: si mostra solo quando esiste) | pacchetto · {addon:einvoice} |
| **Rendiconto per cassa e parte fiscale** | piano «Contabilità & Fiscale» | ★ da verificare (base o «Contabilità avanzata») |
| **Operatori / utenti** | ruoli di WordPress | {plan:gestionale.operators} |
| **Verifica in due passaggi** | quella di WordPress | obbligatoria per tutti gli operatori |
| **Sito dell'associazione** | il tuo WordPress | pacchetto «Sito» · {addon:site} |
| **Dominio personalizzato** | il tuo | pacchetto · {addon:custom_domain} |
| **Prova gratuita** | Pro: 30 giorni | {plan:gestionale.trial_days} giorni |
| **Prezzo** | Gratuito per sempre; Pro da {plugin:lite.month_price} al mese | da {plan:gestionale.base_price} al mese fino a 50 soci, poi a scaglioni |
| **Assistenza** | {support:free}, {support:paid} o {support:top} secondo il piano | {support:paid} o {support:top} secondo il piano |
| **Passare dall'uno all'altro** | nessun passaggio automatico: sottoscrivi l'altro e lasci scadere quello che non usi più; casi particolari a mano, scrivendo all'assistenza | idem |

Sotto la tabella, due pulsanti affiancati: «Guarda il plugin» → `/wordpress`, «Guarda il gestionale» → `/saas`; e «Provali»: demo
del plugin (`/demo`) e demo del gestionale (`demoapp.associazionesemplice.it`). Non ci sono offerte combinate.

### Riga «Assistenza» (da catalogo)

| Livello | Testo mostrato |
|---|---|
| `free` | Gratuita: prima risposta entro 48 ore lavorative, da lunedì a venerdì |
| `paid` | Standard: prima risposta entro 48 ore lavorative, da lunedì a sabato, con priorità |
| `top` | Top: prima risposta entro 24 ore lavorative, da lunedì a sabato, con priorità in cima |

«Ore lavorative» = contate dall'apertura del ticket solo nei giorni coperti, senza domeniche e festività nazionali (ARCHITETTURA_DOMINI §7).
Il testo dice «obiettivo di prima risposta», non «garanzia di risoluzione».

## 3. Come si generano le celle

Il catalogo di ciascun prodotto ha già `features[]` e, per piano, `features: {id: {state: included|limit|excluded, text}}`.
Per allineare le righe **tra** i due prodotti serve una chiave comune:

- **Nuovo campo opzionale `compare` in ogni feature del catalogo** (stringa stabile, p.es. `members`, `ledger`, `activities`,
  `member_area`, `online_payments`, `resources`, `einvoice`, `fiscal`, `site`, `custom_domain`, `two_factor`). Il portale mette
  nella stessa riga le feature con lo stesso `compare` dei due cataloghi. Chi non ha corrispondenza compare solo nella tabella del
  suo prodotto (2 o 3).
- Segnaposto delle celle: `{addon:<code>}` = prezzo base dell'addon («da 19 € al mese»), `{plan:<code>.<campo>}` = campo del piano,
  `{plugin:<piano>.month_price}` = prezzo del piano del plugin, `{support:<livello>}` = frase della tabella sopra. Un segnaposto
  senza dato nel catalogo **non** si mostra: la riga resta con «—» e il pannello staff avvisa.
- Prezzi **sempre «IVA esclusa»** con l'indicazione nel titolo della colonna; mensile e annuale come nel catalogo.
- Se il catalogo non risponde si usa l'ultimo salvato con la data di aggiornamento nel pannello staff, ma **non nella pagina**.

Le righe editoriali (A chi serve, Dove stanno i dati, Installazione…) sono testo del portale, non del catalogo: si modificano nelle
pagine di contenuti, e non devono contenere cifre.

## 4. Tabella 3: gestionale e pacchetti (dal catalogo, oggi)

Dalla configurazione iniziale del SaaS (`saas/config/catalogo.php`, prezzi IVA esclusa, da verificare sul catalogo pubblicato):

| Cosa | Prezzo |
|---|---|
| **Gestionale** (soci, prima nota, corsi ed eventi; 1 operatore incluso) | 15 € al mese fino a 50 soci, poi 0,18 € per socio fino a 150, 0,12 € fino a 400, 0,06 € oltre; annuale = 10 mensilità |
| Operatore aggiuntivo | 3 € al mese |
| Spazio file: 5 MB a socio (minimo 250 MB); +10 GB | 3 € al mese |
| Area soci e pagamenti | 5 € al mese fino a 50 soci, poi 0,06 · 0,04 · 0,02 € a socio |
| Risorse (a ore, a giornata, stagionali, versione Web) | da 19 € al mese, a fasce di risorse |
| Sito | 19 € al mese |
| Fattura elettronica (50 fatture all'anno) | 7 € al mese |
| Comunicazioni | 9 € al mese |
| Contabilità avanzata | 19 € al mese |
| Vita associativa | 7 € al mese |
| Sport | 12 € al mese |
| Dominio personalizzato | 3 € al mese |
| Database dedicato | 25 € al mese |

Per il telefono: un `list-group` con una riga per pacchetto (titolo, riga di dettaglio, prezzo a destra) e il gestionale base in
cima come primo gruppo; da `lg` la tabella. Un **calcolatore** («quanti soci hai? quali pacchetti?») sotto la tabella dà il totale
mensile e annuale: è un'idea di progetto, da realizzare dopo la tabella.

## 5. Aspetto

- Telefono: la tabella 1 diventa **due viste alternate** (`Segmented` «Plugin | Gestionale online») con la stessa riga per ogni voce
  (titolo, dettaglio, ✓ o testo a destra); non una tabella che scorre di lato. Da `lg`: tabella a tre colonne (voce, plugin, gestionale),
  intestazione che resta in vista.
- Ogni cella ha una **parola** oltre al segno: ✓ ha il testo nascosto «incluso», — ha «non incluso» (screen reader). Il colore non è
  l'unico segnale (salvia per incluso, grigio per no).
- Nessuna colonna «consigliato», nessun badge promozionale: è una guida, non una pressione ([DESIGN.md](../saas/docs/DESIGN.md) §3).
- Ogni riga che porta a un approfondimento ha un link («Come funziona») verso la pagina di `/saas` o `/wordpress`.

## 6. Quando si aggiorna

- Cambia un prezzo, una promo o un limite: lo cambia l'amministrazione del prodotto; la pagina si aggiorna da sola entro 10 minuti.
- Esce una versione del plugin o una funzione del SaaS: si aggiorna `features` del catalogo **nella stessa modifica** (regola «tabella
  di confronto sempre aggiornata»), poi guide e demo come oggi.
- Il popup della demo `/confronto-demo` continua a leggere il confronto del plugin (tabella 2): nessun cambio.

## 7. Da verificare prima di pubblicare

1. La riga ★ rimasta della tabella 1 (parte fiscale del gestionale: base o «Contabilità avanzata»). Sul resto la titolare ha deciso il 10/11/2026: la fattura elettronica del plugin passa dal ponte e solo nel piano «Contabilità & Fiscale»; tra plugin e gestionale non c'è migrazione, chi cambia sottoscrive il nuovo e lascia scadere il vecchio.
2. I nomi pubblici dei piani del plugin: «Contabilità» (Pro Contabile) e «Contabilità & Fiscale» (Pro Fiscale); nelle tabelle si usano solo questi e i prezzi dal catalogo del plugin (la vetrina del portale ha
   5 € / 48 € e 12 € / 119 € in `config.example.php`, ma il listino vero sta nelle impostazioni di produzione).
3. Che la parte fiscale (IVA, 5 per mille, rendiconto per cassa) sia davvero in «Contabilità avanzata» o nella base del gestionale
   (`saas/docs/PERIMETRO.md` §3 e `saas/config/catalogo.php` non coincidono del tutto).
4. L'elenco `compare` da fissare insieme dalle sessioni portale-saas e portale-plugin.

## 8. Cosa fa ogni sessione

| Sessione | Compito |
|---|---|
| portale-vendita | pagine `/confronto`, `/wordpress/confronto`, `/saas/prezzi`; unione delle feature per `compare`; segnaposto; vista a due schede su telefono |
| portale-saas | campo `compare` e `support_level` nel catalogo del SaaS e nel validatore; testi delle feature in italiano semplice |
| portale-plugin | stesso campo `compare` nel catalogo del plugin; mappare le righe di `confronto.md` su `features` con id stabili |
| wordpress plugin free | resta a monte: a ogni rilascio dice quali feature cambiano |

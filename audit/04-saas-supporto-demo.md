# Audit 04 · SaaS, supporto e demo: testi, mobile, logica

Revisione di sola lettura del 13/11/2026 sul codice del worktree. Ogni voce è stata verificata nel file indicato
(`file:riga`). Percorsi relativi alla radice del repository. Gravità: **Bloccante** (l'utente non riesce a finire o
viene informato male), **Importante** (errore probabile, confusione, incoerenza visibile), **Miglioria**.

Indice: 1. SaaS · 2. Prezzi del SaaS · 3. Supporto · 4. Demo · 5. Incoerenze di nomi tra prodotti · 6. Mobile ·
7. Logica. Limiti della revisione: la resa da telefono è dedotta da CSS e markup, non da prove su dispositivo; le
guide del supporto (circa 190 KB) sono state controllate a campione sui percorsi di menu, non lette per intero; le
viste dell'amministrazione del supporto (`supporto/resources/views/admin`) non sono state riviste.

## I 20 problemi più gravi

| N. | Gravità | Area | Problema | Dove | Voce |
|---|---|---|---|---|---|
| 1 | Bloccante | SaaS, logica | Gli errori dei moduli di prima nota restano nascosti dietro il foglio: si preme «Registra» e non succede niente | `saas/resources/js/components/ui/FlashMessages.vue:23`, `Contabilita/PrimaNota.vue:79` | S-B1 |
| 2 | Bloccante | Supporto | Nessuna guida per il gestionale online: tutte le guide sono del plugin | `supporto/database/seeders/GuideContentSeeder.php:41` | U-B1 |
| 3 | Bloccante | SaaS, logica | Nel gestionale non c'è la voce «Abbonamento»: la rotta esiste ma nessuno la collega | `saas/resources/js/layouts/AppLayout.vue:20-41`, `PortaleServiceProvider.php:78` | S-B2 |
| 4 | Bloccante | SaaS, testi | La pagina 403 dice sempre «chiedi il permesso», anche quando manca il pacchetto o l'utente è disattivato | `saas/resources/views/errors/403.blade.php:4` | S-B3 |
| 5 | Bloccante | SaaS, area soci | Le pagine d'errore mandano i soci a «Il tuo account» del portale, che non hanno | `saas/resources/views/errors/layout.blade.php:30` | S-B4 |
| 6 | Bloccante | SaaS, logica | Dalla verifica in due passaggi non si torna al gestionale se non è obbligatoria | `saas/resources/js/pages/Account/TwoFactor.vue:86` | S-B5 |
| 7 | Bloccante | SaaS | Dodici azioni che cancellano o chiudono senza conferma (certificato medico, uscita del socio, chiusura dell'esercizio, pubblicazione del listino…) | `Soci/Scheda.vue:74`, `Contabilita/Rendiconto.vue:8`, `admin/catalog.blade.php:32` | S-B6 |
| 8 | Importante | Prezzi | Gli scaglioni non sono scritti per il cliente: il prezzo per 120 o 400 soci non si ricava senza il calcolatore | `portale/views/_catalog_saas.php:12-22` | §2.1 |
| 9 | Importante | Prezzi, logica | «Prova gratuita di 30 giorni, senza carta» porta a una pagina con il solo «Ordina e paga» | `portale/views/_catalog_saas.php:23`, `saas_buy.php:81` | §2.5 |
| 10 | Importante | SaaS, testi | Messaggi di validazione non tradotti (13 regole e quasi tutti i nomi dei campi): escono chiavi grezze | `saas/lang/it/validation.php:6-43` | S-I1 |
| 11 | Importante | Nomi | «Pacchetto» vuol dire sia il pacchetto in vendita sia il carnet di ingressi | `Attivita/Scheda.vue:131`, `Risorse/Elenco.vue:22` | S-I3 |
| 12 | Importante | Nomi | I piani del plugin hanno quattro coppie di nomi tra portale, supporto e documenti | `supporto/config/support.php:82-83`, `portale/config.example.php:53` | §5 |
| 13 | Importante | Nomi | «SaaS» detto ai clienti nel modulo delle richieste; altrove è «Gestionale online» | `supporto/app/Support/ProductPlans.php:8` | U-I1 |
| 14 | Importante | SaaS, commercialista | Il rendiconto per cassa non si stampa né si scarica, e non cita il Mod. D | `Contabilita/Rendiconto.vue:19` | S-I6, S-I7 |
| 15 | Importante | Prezzi, logica | «Parte fiscale» è in vetrina tra i pacchetti ma nessun pacchetto la dà; il rendiconto per cassa è promesso lì e incluso nella base | `saas/config/catalogo.php:66` | §2.6 |
| 16 | Importante | SaaS, logica | La domanda di adesione respinta non viene comunicata, mentre la pagina pubblica promette una risposta per email | `saas/app/Modules/Soci/Services/ApplicationService.php:180-186` | S-I16 |
| 17 | Importante | Mobile | Due intestazioni fisse più la barra in basso: 172 px occupati su ogni schermata | `saas/resources/js/layouts/AppLayout.vue:74`, `:119` | M-I1 |
| 18 | Importante | Mobile | Tabelle fino a 8 colonne senza versione a elenco; tre senza nemmeno lo scorrimento | `AreaSoci/PagamentiOnline.vue:63`, `Soci/Scheda.vue:190`, `Attivita/Scheda.vue:228` | M-I2 |
| 19 | Importante | Supporto | Guide con percorsi di menu che il plugin non ha («Cassa → Anni solari», «Promemoria, privacy e ricevute») | `supporto/database/guides/part4.php:71`, `:309` | U-I7 |
| 20 | Importante | Demo, mobile | La fascia fissa della demo occupa cinque o sei righe sul telefono e copre i pulsanti in basso | `demo/asem-demo.php:77-91` | D-I1 |

---

## 1. SaaS

### 1.1 Bloccanti

**S-B1 · Gli errori dei moduli nei fogli restano nascosti dietro il foglio**
- Dove: `saas/resources/js/components/ui/FlashMessages.vue:9` e `:23` (l'errore `errors.ledger` è disegnato in cima alla
  pagina); `saas/resources/js/components/ui/Sheet.vue:45` (il foglio copre la pagina con `fixed inset-0 z-50 bg-ink/45`);
  `saas/resources/js/pages/Contabilita/PrimaNota.vue:79` (il foglio si chiude solo con `onSuccess`).
- Cosa succede: i controller rimandano gli errori di dominio con la chiave `ledger`
  (`saas/app/Modules/Contabilita/Http/Controllers/LedgerController.php:227`: esercizio chiuso, conto chiuso…). In Prima
  nota i moduli «Registra un incasso», «Registra una spesa», «Giroconto» e lo storno stanno in un foglio: se il server
  rifiuta, il foglio resta aperto e il messaggio compare **sotto** il velo scuro. L'operatore preme «Registra l'incasso»
  e non succede niente. Dentro al foglio si vede solo l'errore dell'importo (`PrimaNota.vue:247`, `:271`); data, conto,
  categoria e giroconto non mostrano mai il loro errore.
- Proposta: mostrare l'errore dentro al foglio, sopra il pulsante. Da incollare in ciascuno dei tre moduli, prima del
  pulsante di invio:
  ```vue
  <p v-if="$page.props.errors?.ledger || Object.keys(income.errors).length" class="rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger" role="alert">
      {{ $page.props.errors?.ledger ?? Object.values(income.errors)[0] }}
  </p>
  ```

**S-B2 · Nel gestionale non c'è nessuna voce per l'abbonamento**
- Dove: la rotta esiste, `saas/app/Modules/Portale/Providers/PortaleServiceProvider.php:78-80` (`/gestionale/abbonamento`),
  ma nessuna pagina la collega: il menu `saas/resources/js/layouts/AppLayout.vue:20-41` non la contiene e in
  `saas/resources/js` la stringa `/abbonamento` non compare.
- Perché è grave: tre testi mandano l'utente «all'abbonamento» senza dirgli dove:
  `saas/resources/js/pages/Orgs/Choose.vue:28` («controlla lo stato dell'abbonamento»),
  `saas/resources/js/pages/Operatori/Elenco.vue:84` («Abbonamento, impostazioni e operatori restano a titolare e
  amministratore»), `saas/app/Modules/Portale/Http/Middleware/RequirePackage.php:28` («puoi aggiungerlo dal portale»).
  Chi vuole aggiungere un pacchetto o un operatore non ha un punto di partenza.
- Proposta: aggiungere al gruppo «Gestione» di `AppLayout.vue` (dopo la riga 37):
  ```js
  { href: '/gestionale/abbonamento', label: 'Abbonamento e pacchetti', icon: CreditCard, can: 'org.subscription' },
  ```
  (è un reindirizzamento esterno: nel template va reso con `<a>` e non con `<Link>`).

**S-B3 · La pagina 403 dà sempre la stessa spiegazione, anche quando è sbagliata**
- Dove: `saas/resources/views/errors/403.blade.php:4`: «Se pensi che dovresti averlo, chiedi a chi gestisce
  l'associazione di darti il permesso.» Il testo è fisso: i messaggi passati ad `abort(403, …)` non vengono mai mostrati
  (`RequirePackage.php:28`, `saas/app/Modules/Core/Http/Middleware/EnsureUserActive.php:33`,
  `EnsureTwoFactorConfirmed.php:38`).
- Perché non va: chi apre «Risorse» senza il pacchetto legge che gli manca un permesso e lo chiede al presidente, che
  non può darglielo. Lo stesso per l'utente disattivato.
- Proposta (`403.blade.php`):
  ```blade
  @section('message', $exception->getMessage() !== '' ? $exception->getMessage() : 'Il tuo ruolo non comprende questa pagina. Se ti serve, chiedi al titolare o a un amministratore dell’associazione di cambiarti il ruolo.')
  ```
  e in `RequirePackage.php:28`: «Questa funzione fa parte di un pacchetto che la tua associazione non ha attivato. Il
  titolare può aggiungerlo da Gestione › Abbonamento e pacchetti.»

**S-B4 · Le pagine d'errore mandano i soci in un posto dove non hanno un account**
- Dove: `saas/resources/views/errors/layout.blade.php:30-31` («Torna al tuo account» →
  `https://associazionesemplice.it/account`, «Vai alla home») e `errors/404.blade.php:4` («Riparti dal tuo account.»).
- Perché non va: le stesse viste servono l'area soci (`{slug}.…/area`, `saas/app/Modules/AreaSoci/Http/routes.php:16`),
  dove `abort(404)` è frequente (`MemberAreaController.php:44`, `:61`, `:92`). Il socio non ha un account sul portale:
  finisce su una pagina di accesso che non lo riconosce, e il nome della sua associazione sparisce.
- Proposta: nel layout scegliere i pulsanti in base all'host.
  ```blade
  @if (request()->is('area', 'area/*'))
      <a class="btn primary" href="/area">Torna all'area soci</a>
  @else
      <a class="btn primary" href="/gestionale">Torna al gestionale</a>
      <a class="btn" href="https://supporto.associazionesemplice.it/">Chiedi aiuto</a>
  @endif
  ```
  404: «L'indirizzo potrebbe essere cambiato o la pagina non esiste più.»

**S-B5 · Dalla pagina della verifica in due passaggi non si torna indietro**
- Dove: `saas/resources/js/pages/Account/TwoFactor.vue:35` (usa `GuestLayout`, senza menu) e `:86`
  (`<Link v-if="props.required" href="/gestionale">Vai al gestionale</Link>`).
- Perché non va: il collegamento di ritorno c'è solo quando la verifica è obbligatoria. Con
  `SAAS_REQUIRE_TWO_FACTOR=false` (oggi sul server) chi apre «Sicurezza» dal menu resta in una pagina senza uscita, sia
  prima di attivarla sia dopo.
- Proposta: togliere `v-if="props.required"` dalla riga 86 e spostare il collegamento fuori dal blocco `v-else`, in fondo:
  ```vue
  <Link href="/gestionale/profilo" class="mt-6 block text-center font-semibold text-accent hover:underline">Torna al tuo profilo</Link>
  ```

**S-B6 · Azioni che cancellano o chiudono senza chiedere conferma**
Altrove la conferma c'è (`Operatori/Elenco.vue:20`, `Attivita/Data.vue:18`, `Risorse/Scheda.vue:76`): qui manca.

| Dove | Pulsante | Effetto | Conferma da aggiungere |
|---|---|---|---|
| `saas/resources/js/pages/Soci/Scheda.vue:74` e `:197` | «Togli» (certificato) | cancella il certificato medico e il file | `confirm('Cancellare questo certificato medico? Il file allegato viene eliminato.')` |
| `Soci/Scheda.vue:63` e `:288` | «Registra l'uscita» | il socio esce; nel codice non c'è una rotta per farlo rientrare | `confirm(`Registrare l'uscita di ${person.first_name} ${person.last_name} dall'associazione?`)` |
| `Soci/Scheda.vue:61` e `:165` | «Togli» (tessera) | la tessera dell'anno non vale più | `confirm('Togliere la tessera di questo anno sociale?')` |
| `Soci/Scheda.vue:69` e `:224` | «Scollega» (tutore) | il genitore perde l'accesso al minore nell'area soci | `confirm(`Scollegare ${g.name}? Non vedrà più la scheda del minore nell'area soci.`)` |
| `saas/resources/js/pages/Contabilita/Rendiconto.vue:8` e `:64` | «Chiudi l'esercizio 2026» | blocca registrazioni e storni dell'anno | `confirm(`Chiudere l'esercizio ${statement.year}? Non potrai più registrare né stornare movimenti con date di quell'anno finché non lo riapri.`)` |
| `saas/resources/js/pages/Operatori/Elenco.vue:42` e `:107` | «Elimina» (ruolo) | cancella il ruolo | `confirm(`Eliminare il ruolo «${r.name}»?`)` |
| `saas/resources/js/pages/Attivita/Scheda.vue:37` e `:179` | «Chiudi l'iscrizione» | chiude l'iscrizione al corso | `confirm(`Chiudere l'iscrizione di ${e.name}?`)` |
| `saas/resources/js/pages/Account/TwoFactor.vue:30` e `:82` | «Disattiva la verifica» | toglie la protezione dell'account | `confirm('Disattivare la verifica in due passaggi? Per entrare basterà la password.')` |
| `Account/TwoFactor.vue:29` e `:77` | «Genera nuovi codici di recupero» | i codici salvati smettono di valere, senza avviso | testo: «Genera nuovi codici di recupero (quelli vecchi non varranno più)» |
| `saas/resources/js/pages/AreaSoci/Risorse.vue:43` | tocco su una fascia | prenota subito (e nasce un importo dovuto) | `confirm(`Prenotare ${res.name} dalle ${s.start} alle ${s.end}?`)` |
| `saas/resources/views/admin/catalog.blade.php:32` e `:34` | «Pubblica come revisione N», «Scarta la bozza» | cambia i prezzi in vendita / butta le modifiche | `onsubmit="return confirm('Pubblicare la revisione? I prezzi cambiano subito sul portale.')"` e `…confirm('Scartare la bozza? Le modifiche non pubblicate si perdono.')` |

### 1.2 Importanti

**S-I1 · Messaggi di validazione non tradotti**
- Dove: `saas/lang/it/validation.php:6-33` traduce 14 regole. Nei controller si usano anche `date_format` (27 volte),
  `array` (20), `file` (17), `between` (14), `regex` (6), `json` (6), `url` (7), `size` (4), `Rule::enum` (8),
  `required_if`, `mimetypes`, `digits`, `max` su file. Con `fallback_locale` = `it`
  (`saas/config/app.php:85`) per queste l'utente legge la chiave grezza, per esempio «validation.date_format».
- Mancano anche i nomi dei campi: `attributes` (righe 35-43) ne ha 7; il resto esce in inglese («Il campo first name è
  obbligatorio», «Il campo birth date non è una data valida»).
- Da aggiungere al file:
  ```php
  'array' => 'Il campo :attribute non è valido.',
  'between' => ['numeric' => 'Il campo :attribute deve essere tra :min e :max.', 'string' => 'Il campo :attribute deve avere tra :min e :max caratteri.', 'file' => 'Il file deve pesare tra :min e :max KB.'],
  'date_format' => 'Il campo :attribute non è una data valida.',
  'digits' => 'Il campo :attribute deve avere :digits cifre.',
  'enum' => 'Il valore di :attribute non è valido.',
  'file' => 'Scegli un file.',
  'json' => 'Il campo :attribute non è valido.',
  'mimetypes' => 'Il file deve essere di tipo: :values.',
  'regex' => 'Il campo :attribute non ha il formato giusto.',
  'required_if' => 'Il campo :attribute è obbligatorio.',
  'size' => ['string' => 'Il campo :attribute deve avere :size caratteri.'],
  'uploaded' => 'Il file non è stato caricato: riprova, o usa un file più piccolo.',
  'url' => 'Il campo :attribute deve essere un indirizzo web (comincia con https://).',
  ```
  e in `max`: `'file' => 'Il file non può superare :max KB.'`. In `attributes`: `first_name` → nome, `last_name` →
  cognome, `birth_date` → data di nascita, `birth_place` → luogo di nascita, `tax_code` → codice fiscale, `phone` →
  cellulare, `address` → indirizzo, `card_number` → numero di tessera, `amount` → importo, `date` → data, `account_id` →
  conto, `category_id` → categoria, `reason` → motivo, `fee` → quota, `file` → file, `privacy_url` → indirizzo
  dell'informativa, `rules_url` → indirizzo del regolamento.

**S-I2 · «Home», «riepilogo», «cruscotto»: la stessa pagina ha tre nomi**
- `saas/resources/js/layouts/AppLayout.vue:60` e `:99` «Home»; `saas/app/Modules/Core/Http/Controllers/SetupController.php:52`
  «Puoi riaprirla quando vuoi dal riepilogo.» e `:59` «Configurazione rimandata: la trovi nel riepilogo.»
- Proposta: `:52` «Configurazione salvata. Puoi riaprirla quando vuoi da Altro › Configurazione guidata.»; `:59`
  «Va bene, più tardi: la trovi in Altro › Configurazione guidata.»

**S-I3 · «Pacchetto» vuol dire due cose**
- Pacchetto commerciale (Area soci, Risorse…): `saas/resources/js/pages/Risorse/Elenco.vue:22` («Risorse del
  pacchetto»), `:26` («non comprese nel pacchetto»), `RequirePackage.php:28`.
- Carnet di ingressi di un'attività: `saas/resources/js/pages/Attivita/Scheda.vue:114` («Pacchetto 5 ingressi 40 x5»),
  `:131` («Pacchetti con ingressi residui»), `:132`, `saas/resources/js/pages/Attivita/Data.vue:57` («dal pacchetto»),
  `:84` («Un ingresso da un pacchetto già preso»).
- Proposta: per gli ingressi usare **carnet**. `Scheda.vue:131` «Carnet con ingressi da usare»; `:132` «Carnet già
  pagati con ingressi ancora da usare. Per scalarne uno, prenota la persona a una data scegliendo «Un ingresso dal
  carnet».»; `:114` segnaposto «Carnet 5 ingressi 40 x5»; `Data.vue:57` «dal carnet»; `:84` «Un ingresso dal carnet già
  pagato». `Risorse/Elenco.vue:26` «non comprese nel tuo abbonamento: si aggiungono da Abbonamento e pacchetti».

**S-I4 · Cinque parole per «quanto costa»**
- «Quota» (`Soci/Scheda.vue:171`), «Contributo» come prezzo (`Attivita/Data.vue:82`, `AreaSoci/Eventi.vue:14`) e come
  cadenza (`Attivita/Scheda.vue:80`: l'etichetta «Contributo» apre una tendina mensile/unico), «Importo per i soci»
  (`Attivita/Scheda.vue:81`), «Prezzo» e «Listini» (`Attivita/Data.vue:80`, `Attivita/Scheda.vue:113`), «Tariffa»
  (`Risorse/Scheda.vue:232`).
- Proposta: **quota** = quota associativa; **contributo** = quanto si paga per corsi ed eventi; **tariffa** = risorse.
  `Attivita/Scheda.vue:80` «Come si paga»; `:81` «Contributo per i soci»; `:82` «Contributo per gli ospiti»; `:113`
  «Tariffe particolari e carnet»; `Attivita/Data.vue:80` «Contributo».

**S-I5 · «Categoria», «Voce», «Voce di entrata»; «incasso/spesa» ed «entrate/uscite»**
- `Contabilita/PrimaNota.vue:242`, `:272` «Categoria»; `Contabilita/Importa.vue:35` colonna «Voce»;
  `Risorse/Scheda.vue:246` «Voce di entrata»; `PrimaNota.vue:241` «Voce 1» indica invece una riga dell'incasso.
- Proposta: tenere **Categoria** nei moduli e spiegare una volta il termine del commercialista: `PrimaNota.vue:242`
  etichetta «Categoria (voce del rendiconto)»; `Risorse/Scheda.vue:246` «Categoria dell'incasso»; `PrimaNota.vue:241`
  «Riga 1».
- Incasso/spesa (operatore) ed entrate/uscite (rendiconto) possono convivere, ma va detto: `PrimaNota.vue:112`
  sottotitolo «Esercizio 2026 · incassi (entrate) e spese (uscite) dell'anno».

**S-I6 · Termini contabili senza spiegazione alla prima comparsa**
| Dove | Testo attuale | Proposta |
|---|---|---|
| `Contabilita/PrimaNota.vue:112` | `Esercizio ${year}` | `Esercizio ${year} (l'anno contabile)` |
| `PrimaNota.vue:245` | «Mese di competenza (facoltativo)» | «Mese a cui si riferisce (facoltativo)» + nota: «Per esempio la quota di ottobre pagata a novembre. È il mese di competenza.» |
| `Contabilita/Conti.vue:22` | solo «Conti» | aggiungere sotto: «Dove stanno i soldi dell'associazione: la cassa, il conto in banca, il conto PayPal. Ogni incasso e ogni spesa passa da uno di questi.» |
| `Conti.vue:26` | «Commissione di incasso» | «Commissione trattenuta a ogni incasso (es. POS)» |
| `Contabilita/Rendiconto.vue:19` | «Rendiconto per cassa {anno}» | «Rendiconto per cassa {anno}» + sottotitolo «Entrate e uscite dell'anno per categoria, nello schema del Mod. D degli enti del Terzo settore. È il documento da portare in assemblea e al commercialista.» |
| `Rendiconto.vue:46` | «Avanzo / disavanzo:» | «Avanzo (o disavanzo) dell'esercizio:» |
| `Rendiconto.vue:63` | «Con l'esercizio chiuso non si registrano né si stornano movimenti con date al suo interno.» | «Chiudi l'esercizio dopo l'approvazione del rendiconto. Da quel momento non si registrano né si stornano movimenti con date di quell'anno; se serve, si può riaprire.» |
| `PrimaNota.vue:218-220` | «Storna il movimento» | tenere, con nota: «Stornare vuol dire annullare: il movimento resta in elenco, barrato, ma non conta più. Scrivi perché lo storni.» |

**S-I7 · Il rendiconto non si può stampare né scaricare**
- Dove: `saas/app/Modules/Contabilita/Http/routes.php` ha solo `GET /rendiconto/{year?}`; in
  `Contabilita/Rendiconto.vue` non c'è nessun pulsante di stampa o di esportazione (c'è per la prima nota,
  `PrimaNota.vue:193`, e per il libro soci, `Soci/LibroSoci.vue:25`).
- Perché non va: è il documento che il commercialista e l'assemblea chiedono in PDF.
- Proposta minima, in attesa del PDF: pulsante «Stampa» (`window.print()`) accanto al selettore dell'esercizio.

**S-I8 · Testi che parlano di sicurezza in gergo**
| Dove | Testo attuale | Proposta |
|---|---|---|
| `Soci/Scheda.vue:139` | «Questi dati sono cifrati: nel database non si leggono.» | «Dati protetti: li vede solo chi ha il permesso di aprire le schede.» |
| `Soci/Scheda.vue:189` | «Dato sanitario: è cifrato e ogni consultazione resta nel registro delle azioni.» | «È un dato sanitario: resta traccia di chi lo apre.» |
| `Soci/Scheda.vue:216` | «Nessun tutore collegato. Il tutore operativo segue il minore nell'area soci.» | «Nessun genitore o tutore collegato. Collegane uno: sarà lui a entrare nell'area soci per il minore.» |
| `Soci/Scheda.vue:221`, `:223` | «operativo», «Rendi operativo» | «entra nell'area soci», «Fai entrare lui nell'area soci» |
| `AreaSoci/PagamentiOnline.vue:56` | «Le chiavi sono cifrate e non si rileggono.» | «Le chiavi salvate non si possono rileggere: per cambiarle, scrivile di nuovo.» |
| `Esportazione.vue:14` | «Lo scarico resta nel registro delle azioni.» | «Resta traccia di chi scarica l'archivio.» (nel gestionale non esiste una pagina «registro delle azioni») |

**S-I9 · Operatori: due frasi false quando la verifica in due passaggi non è obbligatoria**
- `Operatori/Elenco.vue:52`: «Ogni operatore entra con la verifica in due passaggi.»; `:60`: chi non ha la verifica
  attiva è indicato come «non ha ancora attivato l'accesso» anche se entra ogni giorno con la sola password.
- Proposta: `:52` «Chi entra nel gestionale e cosa può fare.»; `:60` «· verifica in due passaggi non attiva».
- Nella stessa pagina l'errore sul nome dell'invito non si vede: la riga 75 mostra solo `invite.errors.email`.

**S-I10 · Le impostazioni del tesseramento stanno solo nella pagina «Nuova scheda»**
- Dove: `Soci/Scheda.vue:293` (`v-if="canSettings && !person"`).
- Perché non va: quota associativa, inizio dell'anno sociale e preavviso dei certificati si cambiano solo aprendo
  «Nuova scheda socio» e scorrendo in fondo. Nessuno le cerca lì. Il foglio «Altri strumenti» dei soci
  (`Soci/Elenco.vue:75-82`) ha già «Livelli e quote».
- Proposta: spostare il modulo nella pagina «Livelli e quote» (`Soci/Livelli.vue`), come prima sezione «Quota e anno
  sociale».

**S-I11 · Stati del socio con parole diverse**
- `Soci/Elenco.vue:56-58`: «Uscito», «In regola», «Senza tessera»; `Soci/Scheda.vue:101`: «Senza tessera valida»;
  `Soci/LibroSoci.vue:6`: «In carica», «Cessati»; `Soci/Scheda.vue:164`: tessera «Tolta».
- Proposta: «In regola» / «Da rinnovare» / «Uscito» ovunque (l'area soci dice già «Da rinnovare»,
  `AreaSoci/Tessera.vue:30`). Nel libro soci, che è un registro formale: filtri «Attuali» e «Cessati» («in carica» si
  dice degli organi, non dei soci). `Scheda.vue:164` «Annullata».

**S-I12 · Stati vuoti senza passo successivo**
| Dove | Testo attuale | Proposta |
|---|---|---|
| `Attivita/Elenco.vue:38` | «Nessuna attività in questo anno sociale.» | «Ancora nessun corso o evento per questo anno sociale. Crea il primo con «Nuova attività».» |
| `Soci/Domande.vue:20` | «Nessuna domanda in attesa.» | «Nessuna domanda da esaminare. Le domande arrivano dal modulo online: attivalo qui sotto e pubblica l'indirizzo sul sito.» |
| `Soci/Certificati.vue:24` | «Nessun socio.» | «Nessun certificato con questo filtro. I certificati si registrano dalla scheda del socio, sezione «Certificato».» |
| `Soci/LibroSoci.vue:46` | «Nessun socio.» | «Il libro soci è vuoto: si riempie da solo quando registri la prima tessera.» |
| `Risorse/Scheda.vue:160` | «Nessuna.» | «Nessuna prenotazione in arrivo.» |
| `AreaSoci/Risorse.vue:57` | «Nessuna.» | «Non hai prenotazioni. Scegli una risorsa qui sopra.» |
| `AreaSoci/Eventi.vue:15` | «Nessun evento in programma.» | «Nei prossimi due mesi non ci sono date da prenotare.» |
| `AreaSoci/Quote.vue:17`, `:30` | «Nessuna iscrizione.», «Nessuna ricevuta.» | «Non sei iscritto a corsi o attività.», «Qui trovi le ricevute dei tuoi pagamenti.» |
| `Orgs/Choose.vue:26-28` | «…controlla lo stato dell'abbonamento.» | «Chiedi a chi gestisce la tua associazione di invitarti. Se l'associazione è tua, controlla l'abbonamento dal <a href="https://associazionesemplice.it/account">tuo account</a>.» |

**S-I13 · Errori che non dicono cosa fare**
| Dove | Testo attuale | Proposta |
|---|---|---|
| `Soci/Scheda.vue:49` | «Non è stato possibile.» | «Non siamo riusciti a preparare il link. Riprova tra poco.» |
| `AreaSoci/Esito.vue:15` | «Pagamento non riuscito» (nessun testo sotto) | aggiungere: «Non ti è stato addebitato nulla. Puoi riprovare o pagare in segreteria.» |
| `AreaSoci/Esito.vue:21` | «…ricarica tra qualche istante.» | «…aspetta un momento e tocca «Aggiorna».» + pulsante `router.reload()` |
| `AreaSoci/Eventi.vue:30` | «per annullare scrivi alla segreteria» | «troppo tardi per annullare da qui: scrivi alla segreteria» |
| `saas/resources/views/errors/419.blade.php:4` | «Per sicurezza le pagine restano valide a lungo ma non per sempre. Torna indietro e riprova.» | «Sei rimasto fermo a lungo su questa pagina. Torna indietro, ricarica e riprova: i dati già salvati non si perdono.» |
| `errors/500.blade.php:4` | «…scrivici dal supporto.» (senza collegamento) | aggiungere il pulsante «Scrivi all'assistenza» → `https://supporto.associazionesemplice.it/` |
| `saas/app/Modules/Core/Http/Middleware/IdentifyOperatorOrg.php:37`, `:50` | `abort(401)`, `abort(409, …)` | non esistono `errors/401.blade.php` e `409.blade.php`: esce la pagina inglese di Laravel. Creare le due viste («Devi accedere di nuovo», «Scegli l'associazione»). |

**S-I14 · «Pagamenti online»: pagina per tecnici, tono «voi»**
- `AreaSoci/PagamentiOnline.vue:22`: «…i soldi arrivano a voi, senza commissioni nostre.» (il resto del gestionale dà
  del tu) → «…i soldi arrivano direttamente all'associazione; noi non tratteniamo commissioni.»
- `:33-35`, `:44-46`: «Chiave segreta», «Segreto del webhook», «checkout.session.completed», «Client ID», «sandbox»
  senza un collegamento a una guida. Proposta: sotto il titolo, «Serve un conto Stripe o PayPal intestato
  all'associazione. <a>Guida passo passo</a>» (la guida va scritta nel sito di supporto).
- `:72` pulsante «controllato» e `:15` stato «da controllare» non spiegano che cosa. Lo stato nasce quando il pagamento
  è arrivato ma una voce non è stata registrata in prima nota (`saas/app/Modules/AreaSoci/Payments/OnlinePayments.php:233`).
  → stato «pagato, da registrare a mano», pulsante «Ho sistemato», nota: «Il pagamento è arrivato ma non siamo riusciti a
  registrare tutte le voci in prima nota: guarda il motivo, registra l'incasso a mano e poi premi «Ho sistemato».»

**S-I15 · Area soci: nomi delle sezioni che non coincidono**
- Barra: «Quote» (`saas/resources/js/layouts/MemberLayout.vue:14`); titolo della pagina «Quote e ricevute»
  (`AreaSoci/Quote.vue:12`); pagina «Paga online» (`AreaSoci/Paga.vue:24`) raggiungibile solo da un collegamento;
  i messaggi dicono «da «Paga»» (`AreaSoci/Eventi.vue:14`, `:28`, `MemberResourcesController.php:84`) ma nessuna voce
  si chiama «Paga». `saas/docs/DESIGN.md` §5 prevedeva «Paga» e «Ricevute».
- Proposta: voce della barra «Pagamenti» (pagina unica: da pagare in alto, ricevute sotto), e nei messaggi «da
  «Pagamenti»».
- «Eventi» (`MemberLayout.vue:12`) mostra anche le lezioni prenotabili: → «Prenota» per le date e «Campi e sale» per le
  risorse, oppure «Date» / «Risorse»; oggi «Eventi» e «Prenota» (`:13`) si confondono.

**S-I16 · Domanda di adesione: errori lontani dai campi e respinta senza avviso**
- `Soci/Adesione.vue:48-60`: nessun `FormField` riceve `:error`; gli errori stanno solo nel riquadro in alto (`:40-43`).
  Da telefono il modulo è lungo e non si capisce quale campo correggere. Passare `:error="form.errors.first_name"` ecc.
- `Soci/Domanda.vue:54-56`: «Motivo» + «Respingi». A chi ha fatto domanda non parte nessuna email: il servizio scrive
  solo alla ricezione e all'accoglimento (`saas/app/Modules/Soci/Services/ApplicationService.php:87`, `:175`; `reject`,
  righe 180-186, non manda nulla), mentre la pagina pubblica promette «La segreteria la esamina e ti risponde per
  email» (`Soci/Adesione.vue:36`). → finché l'email non c'è, etichetta «Motivo (resta nel gestionale: avvisa tu la
  persona)»; meglio mandare l'email anche quando la domanda è respinta.

**S-I17 · Invito dello staff: dopo la password non c'è dove andare**
- `saas/resources/views/staff/invite.blade.php:27-28`: «Ora puoi accedere alle aree dello staff…» senza collegamento.
  Aggiungere `<p><a href="{{ rtrim(config('saas.portal.url'), '/') }}/account">Vai all'accesso</a></p>`.
- Stessa pagina `:10`: colori diversi dal marchio (`--brand: #3b3fa8` invece di `#1f218c`) e nessun logo.

**S-I18 · Amministrazione di vendita: codici interni mostrati così come sono**
- `saas/resources/views/admin/addon-form.blade.php:29`: «Tipo: **package**» (mostra il codice; l'elenco alla riga 24
  ha già le etichette). `admin/audit.blade.php:13`: `{{ $row->action }}` senza etichetta.
  `admin/promos.blade.php:31-32`: date da scrivere a mano «AAAA-MM-GG» in un campo di testo → `type="date"`.
- `admin/layout.blade.php:13` scheda «Pacchetti», pagina «Pacchetti e servizi» (`admin/addons.blade.php:4`), modulo
  «Nuovo pacchetto o servizio» (`addon-form.blade.php:4`): scegliere «Pacchetti ed extra» ovunque (in
  `saas/docs/PERIMETRO.md` §7.2 operatori, spazio e dominio sono «Extra»).

### 1.3 Migliorie

- `saas/resources/js/layouts/AppLayout.vue:84` «Il mio account» (porta al portale) e `:164` «Il tuo account» (foglio
  del gestionale): due cose diverse con quasi lo stesso nome e due persone grammaticali. → `:84` «Account e abbonamento».
- `AppLayout.vue:39` «Configurazione guidata» nel menu, `Configurazione.vue:27` titolo uguale, `Dashboard.vue:32`
  «Completa la configurazione», `:33` «Dati dell'ente»: altrove si dice sempre «associazione». → «Dati
  dell'associazione».
- `Configurazione.vue:90` «E poi» → «Passi successivi». `:85` «Più tardi» → «Lo faccio più tardi».
- `Configurazione.vue:56` e `Soci/Scheda.vue:298` «Quota associativa» senza unità → «Quota associativa (€ all'anno)».
- `Soci/Scheda.vue:124-126` etichetta «Tessera» + «Primo libero (12)» → «Numero di tessera» + «Usa il primo libero (12)».
- `Soci/Livelli.vue:18` titolo «Livelli di socio», nel foglio `Soci/Elenco.vue:79` «Livelli e quote». Unificare in
  «Livelli e quote». `Livelli.vue:26` `aria-label="Base"` → «Tipo di socio»; i campi alle righe 25-27 e 37-39 hanno solo
  il segnaposto come etichetta.
- `Soci/Importa.vue:24` e `Contabilita/Importa.vue:25` «Import completato» → «Importazione completata».
  `Contabilita/Importa.vue:43` «nessuno (sono errori)» → «nessuno: le righe senza conto vengono scartate».
- `Attivita/Scheda.vue:88` «Il mese è dovuto dalla prima lezione del mese.» → «Chi si iscrive paga il mese intero a
  partire dalla prima lezione di quel mese.»
- `Attivita/Scheda.vue:102-103` «Sposta gli slot occupati…» → «Sposta le fasce occupate…» (altrove si dice «fasce»).
- `Attivita/Scheda.vue:109` «Referente (socio volontario)» va bene; `:107` «Occupa la risorsa solo se prenotata» →
  «Tieni occupata la risorsa solo nelle date con prenotazioni».
- `Attivita/Scheda.vue:114-115` e `Risorse/Scheda.vue:247-252`: prezzi da scrivere in un'area di testo con una sintassi
  («x5», «feriali 18:00-22:00 15,00»). Per un volontario è un punto d'errore: valutare righe con campi separati.
- `Attivita/Presenze.vue:52-53` «tutti presenti», «nessuno»; `Attivita/Data.vue:96` e `Risorse/Scheda.vue:204` «togli»:
  pulsanti in minuscolo, diversi dagli altri («Togli»).
- `Attivita/Calendario.vue:27`, `:29`, `Attivita/Presenze.vue:27`, `:29`, `Risorse/Calendario.vue:21`, `:23`,
  `Risorse/Scheda.vue:110`, `:112`: pulsanti con la sola freccia «←» «→».
- `Risorse/Scheda.vue:130` «Esterno», `:168` «non socio»: scegliere «Non socio».
- `AreaSoci/Paga.vue:25` «Non hai niente da pagare. 🎉»: unica emoji del prodotto (`saas/docs/DESIGN.md` §3.7 «niente
  decorazioni») → «Sei in regola: non hai niente da pagare.»
- `AreaSoci/Eventi.vue:33` pulsante «Lista d'attesa» → «Mettimi in lista d'attesa».
- `AreaSoci/Tessera.vue:37` «Risulti uscito dall'associazione.» → «Non risulti più socio. Se è un errore, scrivi alla
  segreteria.»
- `saas/resources/views/mail/area-soci-accesso.blade.php:1` «Ciao,» senza nome; va bene, ma l'oggetto e il mittente
  vanno verificati in `saas/app/Modules/AreaSoci/Mail/LoginLink.php`.
- `saas/app/Modules/Piattaforma/Catalogo/CatalogValidator.php:47`, `:138`, `:196` («currency deve essere EUR»,
  «plans.x.support_level non valido»): compaiono nei moduli dell'amministrazione (`plan-form.blade.php:6`); per lo
  staff non tecnico servirebbe il nome del campo del modulo.

---

## 2. Prezzi del SaaS (`saas/config/catalogo.php`)

Modello: base «Gestionale» a scaglioni marginali di soci (`catalogo.php:105`: 15 € fino a 50 soci, poi 0,18 € dal 51°
al 150°, 0,12 € dal 151° al 400°, 0,06 € oltre) più pacchetti. «Area soci e pagamenti» segue gli stessi scaglioni
(`:119`: 5 € fino a 50, poi 0,06 · 0,04 · 0,02 €). «Contabilità avanzata» è fissa a 19 € (`:124`). Annuale = 10
mensilità (`:20`, `:26`). Tutto IVA esclusa (22%).

| Associazione | Solo base | Area soci | Contabilità avanzata | Totale al mese (IVA esclusa) | Con IVA 22% | All'anno (×10, IVA esclusa) |
|---|---|---|---|---|---|---|
| 40 soci | 15,00 € | 5,00 € | 19,00 € | **39,00 €** | 47,58 € | 390,00 € |
| 120 soci | 15 + 70 × 0,18 = 27,60 € | 5 + 70 × 0,06 = 9,20 € | 19,00 € | **55,80 €** | 68,08 € | 558,00 € |
| 400 soci | 15 + 100 × 0,18 + 250 × 0,12 = 63,00 € | 5 + 100 × 0,06 + 250 × 0,04 = 21,00 € | 19,00 € | **103,00 €** | 125,66 € | 1.030,00 € |

Solo base, con IVA: 18,30 € · 33,67 € · 76,86 €.

Gli sconti per combinazione di `saas/docs/PERIMETRO.md` §7.2 (15% su due pacchetti: 24 € → 20,40 €) **non sono nel
catalogo** (`catalogo.php:142`: `'promos' => []`) e in `saas/app` e `saas/config` non c'è codice che li applichi: le
cifre sopra sono quindi quelle che il portale calcola oggi.

**Si capisce da soli? No.**

1. **Gli scaglioni non sono scritti da nessuna parte per il cliente.** La scheda del piano dice solo «da 15,00 € al mese
   + IVA» e «Il prezzo cresce con i soci: nessun limite blocca la segreteria» (`portale/views/_catalog_saas.php:12`,
   `:21`). La parola «scaglioni» nel portale compare solo nel codice del calcolo (`portale/src/PriceCalculator.php`,
   `portale/src/Quote.php`). Un tesoriere con 120 soci non può arrivare a 27,60 €: deve aprire «Prendi il gestionale»,
   scrivere i soci e premere «Calcola il totale» (`portale/views/saas_buy.php:39-50`).
   Proposta, sotto le schede di `_catalog_saas.php`:
   > **Quanto costa.** Fino a 50 soci: 15 € al mese. Poi ogni socio in più costa pochi centesimi: 0,18 € dal 51° al
   > 150°, 0,12 € dal 151° al 400°, 0,06 € oltre. Esempi: 40 soci 15 € · 120 soci 27,60 € · 400 soci 63 €. Prezzi al
   > mese, IVA esclusa; pagando l'anno intero paghi 10 mesi invece di 12.
2. **I pacchetti non mostrano il prezzo finché non si calcola.** In `saas_buy.php:42-46` ogni pacchetto è una riga con
   un campo numerico da 0 a 1000, senza prezzo e senza dire che cosa conta il numero: per «Area soci e pagamenti» o
   «Contabilità avanzata» il numero giusto è 1, per «Operatore aggiuntivo» è quanti operatori, per «Risorse orarie, Web»
   è quante risorse. Proposta: caselle da spuntare per i pacchetti («Area soci e pagamenti — da 5 € al mese»), numero
   solo per operatori e risorse, con l'etichetta «Quanti campi o sale?».
3. **«Area soci e pagamenti» cambia prezzo con i soci, ma non lo dice**: nome e nessuna spiegazione (`catalogo.php:115`).
   Proposta di sottotitolo: «5 € al mese fino a 50 soci, poi da 0,06 a 0,02 € a socio.»
4. **IVA.** Le schede dicono «+ IVA», il riepilogo «(IVA inclusa)» (`saas_buy.php:59`), a destra «I prezzi sono IVA
   esclusa nel listino; qui sopra vedi il totale con IVA» (`:94`). È corretto ma per un'associazione senza partita IVA
   l'IVA è un costo pieno: conviene mostrare da subito i due importi («27,60 € + IVA = 33,67 €»).
5. **«Prova gratuita di 30 giorni, senza carta»** (`_catalog_saas.php:23`) porta a `/saas/acquista`, dove l'unico
   pulsante è «Ordina e paga {totale}» (`saas_buy.php:81`): la prova promessa non ha un percorso. O si aggiunge
   «Comincia la prova gratuita», o si toglie la frase.
6. **Nel catalogo c'è una funzione senza pacchetto**: «Parte fiscale: IVA e corrispettivi, regime L.398, 5 per mille,
   rendiconto per cassa» è nel gruppo «Pacchetti» (`catalogo.php:66`) ma tra gli `addons` (`:109-140`) non esiste un
   pacchetto che la dia. Nella tabella di confronto compare come «—» senza modo di averla. Inoltre il «rendiconto per
   cassa» è già nella base del gestionale (`saas/resources/js/pages/Contabilita/Rendiconto.vue:19`): la riga promette
   a pagamento una cosa inclusa.
7. Testo del limite `catalogo.php:85` «1 incluso, altri a 3 € al mese» → «1 operatore incluso; ogni operatore in più
   3 € al mese». `:86` «5 MB a socio (minimo 250 MB)» → «Spazio per i file: 5 MB per socio, almeno 250 MB (bastano per
   i certificati medici)».

---

## 3. Supporto

### 3.1 Bloccanti

**U-B1 · Non esiste nessuna guida per il gestionale online**
- Dove: `supporto/database/seeders/GuideContentSeeder.php:41` crea tutti gli argomenti con `'product' => 'plugin'`; in
  `supporto/database/guides/part1.php`…`part11.php` le parole «gestionale online» e «SaaS» non compaiono mai. Gli otto
  argomenti (`:18-27`) parlano di installazione del plugin, licenza, WordPress.
- Perché è grave: il SaaS è il prodotto con l'assistenza «sulle funzioni» inclusa (`saas/docs/PERIMETRO.md` §7.2,
  «Assistenza»). Chi cerca «prima nota» o «tessera» dal gestionale trova percorsi di menu di WordPress («Cassa → Prima
  nota», «Impostazioni → Limiti e soglie») che nel gestionale non esistono, senza un avviso che la guida è del plugin.
- Proposta: (a) in cima a ogni guida del plugin la riga «Questa guida vale per il **plugin WordPress**. Usi il
  gestionale online? Vai alle guide del gestionale.»; (b) un argomento «Gestionale online» con almeno: primo accesso e
  configurazione guidata, importare i soci, registrare un incasso e una spesa, rinnovare una tessera, chiudere
  l'esercizio e leggere il rendiconto, operatori e ruoli, area soci e pagamenti online (Stripe/PayPal), abbonamento e
  pacchetti.

### 3.2 Importanti

**U-I1 · «SaaS» detto ai clienti**
- `supporto/app/Support/ProductPlans.php:8` (`'saas' => 'SaaS'`, tendina «Di cosa hai bisogno?»),
  `supporto/resources/views/site/index.blade.php:10`, `supporto/resources/views/site/ticket-new.blade.php:21`
  (`['plugin' => 'Plugin', 'saas' => 'SaaS', '' => 'Per tutti']`).
- Il portale lo chiama «Gestionale online» (`portale/views/_catalog_saas.php:12`). → `'plugin' => 'Plugin WordPress',
  'saas' => 'Gestionale online', 'billing' => 'Pagamenti, fatture e account'`.

**U-I2 · «Di cosa hai bisogno?» è l'etichetta della scelta del prodotto**
- `ticket-new.blade.php:17`. Chi legge risponde con il problema, non con il prodotto. → «Quale prodotto usi?».
- `:5` «Stai scrivendo come {email}: i dati del tuo account non si modificano qui.» → «Stai scrivendo come {email}.»
- `:30` «Descrivi il problema» → «Cosa succede? Scrivi cosa stavi facendo, cosa ti aspettavi e cosa è comparso.»
- `:13-14` nome ed email senza `autocomplete` → `autocomplete="name"`, `autocomplete="email"`.

**U-I3 · Stati della richiesta al maschile**
- `supporto/resources/views/site/mine.blade.php:10` e `site/ticket.blade.php:5`: «Richiesta n. 12 … aperto / risolto /
  chiuso». Il cliente legge «richiesta» ovunque, lo stato è rimasto di «ticket». →
  `['open' => 'aperta', 'pending' => 'aspettiamo la tua risposta', 'on_hold' => 'ci stiamo lavorando', 'solved' => 'risolta', 'closed' => 'chiusa']`.

**U-I4 · «Abbiamo preso in carico» detto alla ricezione**
- `supporto/resources/views/site/ticket-received.blade.php:6`. Per il listino «presa in carico» è l'impegno entro 24 o
  48 ore lavorative: qui la richiesta è solo arrivata. → «Abbiamo ricevuto la tua richiesta. Ti rispondiamo per email,
  di solito entro due giorni lavorativi (uno per chi usa il gestionale online). Ti abbiamo mandato una conferma con il
  link per seguirla.»
- Stessa cosa in `supporto/resources/views/mail/ticket-received.blade.php:3`: aggiungere quando arriva la risposta.

**U-I5 · Il link «entra con l'email della tua licenza» esclude chi ha il gestionale**
- `site/index.blade.php:22`: «Per scrivere al supporto entri con l'email della tua licenza: al primo accesso ti
  mandiamo un link.» Chi ha il gestionale online non ha una licenza. → «Per seguire le tue richieste entra con l'email
  del tuo account Associazione Semplice.»

**U-I6 · Pagine d'errore che mandano via dal supporto**
- `supporto/resources/views/errors/419.blade.php:3`: «Per sicurezza il modulo è rimasto aperto troppo a lungo. Torna al
  tuo account e riprova.» La frase non regge e tornando all'account si perde il testo scritto. → «Il modulo è rimasto
  aperto troppo a lungo ed è scaduto. Torna indietro con il browser: il testo che hai scritto di solito è ancora lì.
  Ricarica e invia di nuovo.»
- `errors/500.blade.php:3`: «…torna al tuo account e scrivici dal supporto.» L'utente è già nel supporto. → «Il problema
  è nostro, non tuo. Riprova tra poco; se continua scrivi a info@associazionesemplice.it.»
- `errors/403.blade.php:3` e `errors/429.blade.php:3` chiudono con «torna al tuo account»: nel centro assistenza il
  ritorno utile è «Torna al centro assistenza».

**U-I7 · Guide che citano schermate con un nome che il plugin non ha**
Controllo a campione dei percorsi in grassetto contro i menu del plugin
(`wordpress/associazionesemplice/includes/Admin/ProMenu.php:20`, `:27-28`, `Admin/Admin.php:51`).

| Guida | Testo | Nel plugin | Correzione |
|---|---|---|---|
| `supporto/database/guides/part4.php:71` | «Vai in **Cassa → Anni solari**» | «Anni solari» sta sotto «Contabilità» (`ProMenu.php:20`); la guida `part11.php:177` dice infatti «Contabilità → Anni solari» | «Vai in **Contabilità → Anni solari**» |
| `part4.php:309` | «**Impostazioni → Promemoria, privacy e ricevute**» | la scheda si chiama «Privacy, regolamento e ricevute» (`ProMenu.php:27`); la stringa della guida non esiste nel plugin | «**Impostazioni → Ente e fiscalità → Privacy, regolamento e ricevute**» |
| `part3.php:421` | «Vai in **Rubrica → Comunicazioni**» | il menu è «Rubrica soci» (`part9.php:376` lo scrive giusto) | «**Rubrica soci → Comunicazioni**» |
| `part4.php:188`, `:216`, `part9.php:436` contro `part4.php:158` | «Impostazioni → Pagamenti online» e «Impostazioni → Tecniche → Pagamenti online» | lo stesso posto con due percorsi | scegliere il percorso completo ovunque |
| `part11.php:15` contro `part5.php:268` | «Impostazioni → Dati e fiscalità» e «Impostazioni → Ente e fiscalità → Dati e fiscalità» | scheda «Dati e fiscalità» nel gruppo «Ente e fiscalità» (`ProMenu.php:27`) | percorso completo ovunque |
| `part2.php:232` contro `part2.php:193` | «Impostazioni → Aspetto» e «Impostazioni → Soci e identità → Aspetto» | gruppo «Soci e identità» (`ProMenu.php:28`) | percorso completo ovunque |
| `part3.php:211` | «**Corsi ed eventi › Calendario**» | unico percorso scritto con «›» | usare «→» come nel resto |

**U-I8 · Nomi dei piani nelle guide**
- I segnaposto `{piano_contabile}` e `{piano_fiscale}` sono risolti dal seeder (`GuideContentSeeder.php:63-70`) e nei
  file non ne restano altri non risolti: `{associazione}`, `{nome}`, `{codice_fiscale}` sono esempi di segnaposto del
  plugin. Però il valore predefinito è «PRO Contabilità» e «PRO Contabilità & Fiscalità»
  (`supporto/config/support.php:82-83`) mentre il portale d'esempio usa «Pro Contabile» e «Pro Fiscale»
  (`portale/config.example.php:53`, `:61`): se sul server la variabile non è impostata, guide e portale mostrano nomi
  diversi. Vedi sezione 5.
- I nomi fissi restano scritti a mano: argomento «Plugin Pro» (`GuideContentSeeder.php:24`), «Free» (`:25`), «Chiave
  gratuita, piani Pro» (`:20`).

### 3.3 Migliorie

- `site/article.blade.php:9`: «**Questa guida ti è stata utile?**» è una domanda senza risposta possibile (non ci sono
  i pulsanti sì/no). → «Non hai risolto? Scrivici: ti rispondiamo per email.»
- `site/topic.blade.php:14` «Nessun articolo.» → «Stiamo scrivendo le guide di questa sezione. Intanto puoi aprire una
  richiesta.»
- `site/ticket.blade.php:19` pulsante «Invia» → «Invia la risposta».
- `site/layout.blade.php:16` «Supporto», `:26` «Centro assistenza», pulsanti «Apri una richiesta», email «Il supporto
  di…»: per il cliente «supporto», «assistenza» e «centro assistenza» sono la stessa cosa. Tenere «Assistenza» come
  nome del posto e «richiesta» per il ticket.
- Email allo staff con codici grezzi: `supporto/resources/views/mail/customer-replied-staff.blade.php:5`
  («Stato: {{ $ticket->status }}»), `mail/high-priority-ticket.blade.php:4`, `:11` («Portale:», «Apri la casella»:
  parole rimaste dal prodotto d'origine).
- `supporto/README.md:3` e `:5` nominano «WegaSupport» e la cartella `~/wega-support`: è documentazione interna, non
  arriva ai clienti. Nelle viste pubbliche del supporto (`supporto/resources/views/site`, `mail`, `errors`), nelle
  viste del SaaS e nella demo il nome «Wega» non compare. L'unica occorrenza è in una pagina dello staff:
  `supporto/resources/views/admin/hub/sites/_rules.blade.php:32` («Si aggiungono a [email dello staff]…»), da
  sostituire con l'indirizzo di Associazione Semplice.

---

## 4. Demo (`demo/`)

### 4.1 Importanti

**D-I1 · La fascia fissa in basso copre lo schermo del telefono**
- Dove: `demo/asem-demo.php:91` (`position:fixed; bottom:0; z-index:99999; font:14px`), testo alle righe 77-86:
  «Questa è una demo pubblica di Associazione Semplice: i dati sono inventati, non vengono inviate email e tutto si
  azzera ogni notte alle 4. Sei entrato come … · Esci · Ottieni la licenza gratuita».
- Perché non va: sono circa 200 caratteri centrati a 14 px, senza `@media` e senza pulsante per chiuderla. A 360 px
  fanno cinque o sei righe fisse sopra il contenuto: nell'area soci coprono proprio i pulsanti in basso, cioè la parte
  che la demo dovrebbe far provare.
- Proposta (testo corto, una riga sul telefono):
  ```php
  $asem_demo_note = '<strong>Demo</strong>: dati inventati, nessuna email, si azzera ogni notte.';
  ```
  e nel `wp_footer` aggiungere `padding-bottom:env(safe-area-inset-bottom)` e al `body` un `padding-bottom` pari
  all'altezza della fascia. Il dettaglio «alle 4» resta nell'avviso del pannello (`:87-89`) e nella pagina iniziale.

**D-I2 · «Ottieni la licenza gratuita» è l'unico invito, anche per chi sta provando il Pro**
- `demo/asem-demo.php:79` e `demo/provision.php:77`. La pagina iniziale dice «Una demo per provare le funzioni del Pro»
  (`provision.php:66`) ma l'invito finale porta alla licenza gratuita e non dice quale Pro si sta provando.
- Proposta: `provision.php:66` «Una demo con tutte le funzioni, comprese quelle dei piani a pagamento: un sito WordPress
  vero con il plugin già installato e dati inventati. Clicca, inserisci, cancella: non rompi niente. Tutto si azzera
  ogni notte alle 4 e non parte nessuna email.»; `:77` «**Ti è piaciuta?** Comincia con la <a>licenza gratuita</a>
  oppure <a href="https://associazionesemplice.it/confronto">confronta i piani</a>.»

**D-I3 · Dati di esempio: tutta la contabilità passa dalla cassa**
- `demo/provision.php` e `demo/extra-data.php` non creano un conto in banca (la stringa `'bank'` non c'è) e tutti i
  movimenti usano il conto di cassa (`provision.php` quote, donazioni e spese con `'method' => 'cash'`;
  `extra-data.php:120-138`). In `extra-data.php:97` metà delle quote è registrata con metodo «bonifico» sullo stesso
  conto usato per i contanti.
- Perché non va: un tesoriere o un commercialista che apre la demo non vede giroconti, saldo di banca, riconciliazione,
  cioè le funzioni dei piani a pagamento («Conti multipli e cassa condivisa», `portale/content/confronto.md:103`).
  Un'erogazione liberale da 100 € e una da 75 € in contanti (`provision.php` «donazioni», `extra-data.php:127`) sono
  anche un cattivo esempio: in contanti non sono detraibili.
- Proposta: aggiungere un conto «Banca», registrare lì bonifici ed erogazioni liberali, un versamento dalla cassa alla
  banca (giroconto) e una spesa con commissione POS.

### 4.2 Migliorie

- Mancano i casi che fanno la differenza per ASD e famiglie: nessun minore con genitore o tutore (in `provision.php` e
  `extra-data.php` le parole «guardian», «minor», «tutore» non compaiono), nessun esempio di 5 per mille né di IVA
  per la parte fiscale. Tutti i soci sono in provincia di Roma (`provision.php` `'province' => 'RM'`).
- Nomi «Laura Demo» e «Mario Demo» (`provision.php`, passo «soci») in mezzo a nomi realistici: nel libro soci stonano.
  → «Laura Marchetti (segreteria)» e «Mario Rinaldi (socio)».
- C'è il presidente ma non il tesoriere (`provision.php`, passo «cariche»: il commento dice «Presidente e tesoriere
  inventati», il codice assegna solo `PRESIDENT`).
- `demo/asem-demo.php:85` «Sei entrato come **segreteria@…**» mostra il nome utente: più utile il ruolo («Stai provando
  come **segreteria**»).
- `demo/asem-demo.php:56` «…le chiavi resterebbero salvate fino all'azzeramento di notte» → «…chiunque entri nella demo
  potrebbe vederle fino a stanotte».
- `demo/provision.php:74` (carta «Come amministratore del plugin»): «Qui puoi scaricare una copia dei tuoi dati e
  ricaricarla il giorno dopo, per provare a lungo.» → «Vuoi provare per più giorni? Scarica la copia di sicurezza prima
  delle 4 e ricaricala il giorno dopo.»
- La demo copre solo il plugin. Il portale invita a «Prova la demo del gestionale» (`portale/views/saas_section.php:9`)
  su un altro indirizzo: nella pagina iniziale della demo del plugin manca un collegamento reciproco («Cerchi il
  gestionale online, senza WordPress? Prova l'altra demo»).

---

## 5. Incoerenze di nomi tra plugin, SaaS, portale e supporto

| Cosa | Plugin | SaaS | Portale | Supporto | Proposta |
|---|---|---|---|---|---|
| Piano intermedio del plugin | «Pro Lite» inviato al server licenze (`portale/config.example.php:53`) | — | «Pro Contabile» (`config.example.php:53`), «PRO Contabilità» come ripiego (`portale/views/confronto.php:49`) | «PRO Contabilità» (`supporto/config/support.php:82`) | un solo nome da pannello; stesso valore di ripiego in portale e supporto |
| Piano completo del plugin | «Pro Full» (`config.example.php:61`) | — | «Pro Fiscale» (`config.example.php:61`) | «PRO Contabilità & Fiscalità» (`support.php:83`); `docs/CONFRONTO_PIANI.md:124` scrive «Contabilità & Fiscale» | come sopra |
| Il prodotto online | — | «Gestionale» (`saas/resources/js/layouts/AppLayout.vue:83`), «Associazione Semplice · Gestionale» (`saas/config/catalogo.php:48`) | «Gestionale online» (`portale/views/_catalog_saas.php:12`), «Gestisci SaaS» per lo staff | «SaaS» (`supporto/app/Support/ProductPlans.php:8`) | «Gestionale online» verso i clienti, «SaaS» solo nelle pagine dello staff |
| Ciò che si aggiunge al piano | «addon» nei documenti | «pacchetto» (`RequirePackage.php:28`), `addons` nel catalogo | «Componenti in più» (`portale/views/saas_buy.php:42`) | — | «pacchetti» per le funzioni, «extra» per operatori, spazio, dominio (come `saas/docs/PERIMETRO.md` §7.2); amministrazione: «Pacchetti ed extra» invece di «Pacchetti e servizi» (`saas/resources/views/admin/addons.blade.php:4`) |
| Area soci | «Area riservata dei soci» (`portale/content/confronto.md:35`) | «Area soci» (`saas/resources/js/layouts/MemberLayout.vue:30`); nel catalogo «Area soci e pagamenti online» (`catalogo.php:60`) e «Area soci e pagamenti» (`:115`) | — | «area riservata» (`GuideContentSeeder.php:21`) | «Area soci» ovunque; nel catalogo un solo nome, «Area soci e pagamenti online» |
| Anno contabile | «Anno solare», «Anni solari» (`wordpress/associazionesemplice/includes/Admin/ProMenu.php:20`) | «Esercizio» (`saas/resources/js/pages/Contabilita/PrimaNota.vue:112`, `Rendiconto.vue:20`) | «Anni solari» (`confronto.md:93`), «Anno fiscale» (`:123`) | «anni solari» (`part4.php:71`) | «Esercizio» è il termine del commercialista: usarlo ovunque con la spiegazione «(l'anno contabile)» alla prima comparsa |
| Categoria del movimento | «Voce di prima nota» (`confronto.md:107`) | «Categoria» (`PrimaNota.vue:242`), «Voce» (`Contabilita/Importa.vue:35`), «Voce di entrata» (`Risorse/Scheda.vue:246`) | — | — | «Categoria (voce del rendiconto)» |
| Sezione dei soldi | «Cassa» e «Contabilità» (menu) | gruppo «Soldi», voce «Prima nota» (`AppLayout.vue:30-31`) | «Cassa e conti» (`confronto.md:84`) | argomento «Pagamenti» (`GuideContentSeeder.php:23`) | SaaS: «Cassa e conti» al posto di «Soldi»; supporto: «Incassi, spese e pagamenti» |
| Pagina iniziale | «Bacheca» | «Home» (`AppLayout.vue:99`), «riepilogo» (`SetupController.php:52`), «Cruscotto» nell'amministrazione (`saas/resources/views/admin/layout.blade.php:10`) | — | «Bacheca» nelle guide | SaaS: «Home» ovunque; non serve allinearlo al plugin |
| Elenco delle persone | «Rubrica soci» | «Soci» (menu), «schede in anagrafica» (`Dashboard.vue:95`), «Persone» (gruppo, `AppLayout.vue:21`) | — | «Soci e tessere» | SaaS: gruppo «Soci» e contatore «persone in anagrafica (soci, ospiti, genitori)» |
| Corsi ed eventi | «Corsi ed eventi» | menu «Attività» (`AppLayout.vue:26`), catalogo «Corsi, eventi e presenze» (`catalogo.php:57`), area soci «Eventi» (`MemberLayout.vue:12`) | «Corsi ed eventi» (`confronto.md:48`) | «Corsi ed eventi» | SaaS: voce di menu «Corsi ed eventi» |
| Risorse | «a fasce orarie», «assegnate a periodo» (`confronto.md:72-73`) | «A ore», «A giornata», «A stagione» (`Risorse/Calendario.vue:30`, `:42`, `:55`); catalogo «Risorse orarie / giornaliere / stagionali, Web» (`catalogo.php:128-135`) | — | — | «a ore / a giornata / a stagione» anche nel catalogo: «Risorse a ore, prenotabili online» |
| Carnet di ingressi | «pacchetti di ingressi» (`confronto.md:61-62`) | «pacchetto» (`Attivita/Scheda.vue:131`) | — | «Pacchetti di …» (`part3.php:345`) | «carnet di ingressi» in tutti i prodotti, per non confonderlo con i pacchetti in vendita |
| Chi lavora nel gestionale | «Ruoli e accessi», «segreteria» | «Operatori e ruoli» (`AppLayout.vue:37`), «Utente disattivato.» (`EnsureUserActive.php:33`) | — | — | SaaS: «operatore» sempre; `EnsureUserActive.php:33` «Il tuo accesso è stato disattivato. Scrivi al titolare dell'associazione.» |
| Chi esce | «cessazioni», «recesso» (`confronto.md:9-10`) | «Uscito», «Cessati» (`Soci/Elenco.vue:56`, `Soci/LibroSoci.vue:6`) | — | — | elenco: «Uscito»; libro soci: «Cessato» (termine del registro) |
| Rendiconto | «Rendiconto per cassa per il commercialista», solo nel piano completo (`confronto.md:122`) | «Rendiconto per cassa», nella base (`Rendiconto.vue:19`) e insieme nella riga a pagamento «Parte fiscale» (`catalogo.php:66`) | — | «rendiconto-per-cassa-e-report» (`GuideContentSeeder.php:30`) | decidere dove sta nel SaaS e togliere «rendiconto per cassa» da `catalogo.php:66` se resta nella base; aggiungere «(Mod. D)» in tutti e tre |
| Livello di assistenza | — | «Gratuita / Standard / Top» (`saas/resources/views/admin/plan-form.blade.php:43`) | — | «Gratuita / Standard / Top» (`ProductPlans.php:13`) | coerente; nel listino però si parla di «entro 24 ore / 48 ore»: aggiungere il tempo all'etichetta |

---

## 6. Mobile (dal codice)

### 6.1 Importanti

**M-I1 · Due intestazioni fisse una sopra l'altra**
- `saas/resources/js/layouts/AppLayout.vue:74` (barra del sito, `fixed … h-14`) e `:119` (intestazione
  dell'associazione, `sticky top-14 … h-14`), più la barra in basso (`:136`, 60 px). Su un telefono da 640 px di altezza
  restano circa 470 px. `saas/docs/DESIGN.md` §2 elencava proprio questo difetto («l'intestazione occupa quasi un quinto
  dello schermo») e §5 prevedeva una sola intestazione da 56 px.
- Proposta: sotto `lg` nascondere la barra del sito (`hidden lg:block` alla riga 74, `pt-14` → `lg:pt-14` alla riga 72,
  `top-14` → `top-0 lg:top-14` alla riga 119) e portare «Il mio account» nel foglio «Altro».

**M-I2 · Tabelle larghe senza alternativa a elenco**
`saas/docs/DESIGN.md` §3.3 dice «le liste battono le tabelle» e segna il passo 5 come «da fare». Oggi:

| Dove | Colonne | Sul telefono |
|---|---|---|
| `saas/resources/js/pages/Soci/LibroSoci.vue:32` | 8 | scorre di lato (`overflow-x-auto`, riga 31) |
| `saas/resources/js/pages/AreaSoci/PagamentiOnline.vue:63` | 6 | **nessun contenitore che scorre**: la pagina si allarga |
| `saas/resources/js/pages/Contabilita/Conti.vue:24` | 6, con un modulo dentro la cella (`:35-39`) | scorre di lato |
| `saas/resources/js/pages/Soci/Scheda.vue:190` | 5 | **nessun contenitore che scorre** |
| `saas/resources/js/pages/Attivita/Elenco.vue:27` | 5 | scorre di lato |
| `saas/resources/js/pages/Attivita/Scheda.vue:228` | 4 | **nessun contenitore che scorre** |
| `saas/resources/js/pages/Attivita/Presenze.vue:60` | 2 + una per lezione | scorre di lato |
| `saas/resources/js/pages/Risorse/Calendario.vue:43` | 15, testo `text-xs` (12 px) | scorre di lato |
| `saas/resources/js/pages/Risorse/Elenco.vue:31`, `Soci/Certificati.vue:21` | 4 | scorre di lato |
| `saas/resources/views/admin/subscriptions.blade.php:30` | 8 | scorre (`.scroll`) |

Nessuna pagina ha una versione a elenco sotto `lg` (non ci sono classi `lg:table` / `lg:hidden`). Priorità: Conti e
Attività, che il tesoriere apre dal telefono; per le tre senza contenitore basta intanto avvolgere la tabella in
`<div class="overflow-x-auto">`.

**M-I3 · Bersagli sotto i 44 px**
- `.btn-sm` è alta 36 px (`saas/resources/css/app.css:136-138`, `min-h-9`) ed è usata per «Nuovo» nell'intestazione del
  telefono (`AppLayout.vue:125`) e per «Esci» nell'area soci (`MemberLayout.vue:32`): il pulsante più usato del
  gestionale è sotto misura. → togliere `btn-sm` in quei due punti.
- Azioni scritte come semplice testo, senza altezza minima (una riga da 15 px, circa 22 px di tocco), spesso accanto a
  un'azione distruttiva: `AreaSoci/Eventi.vue:29` («Annulla»), `AreaSoci/Risorse.vue:62` («Annulla»),
  `AreaSoci/Accesso.vue:33` («Mandami un altro codice»), `Attivita/Data.vue:63`, `:96`, `Attivita/Scheda.vue:178-179`
  («Incassa» e «Chiudi l'iscrizione» affiancati), `Operatori/Elenco.vue:66`, `:106-107` («Modifica» ed «Elimina»
  affiancati), `Contabilita/Conti.vue:38`, `:42`, `Attivita/Presenze.vue:52-53`, `Account/TwoFactor.vue:77`, `:82`,
  `AreaSoci/PagamentiOnline.vue:72`. → classe `btn btn-ghost` (o `btn-danger`) al posto di `text-accent hover:underline`.
- `AreaSoci/Risorse.vue:24`: scelta della risorsa con `px-3 py-1` (circa 30 px). `Attivita/Presenze.vue:36`: giorni del
  mese con `py-1.5` (circa 34 px). `AreaSoci/PagamentiOnline.vue:37`, `:48`: «Prova le chiavi» con `py-1.5`.
- `saas/resources/js/components/PersonPicker.vue:51` («Togli», solo testo) e `:55` (voci dell'elenco `px-3 py-2`,
  circa 38 px): è il componente con cui si sceglie chi paga.
- `saas/resources/js/components/ui/FlashMessages.vue:26`: chiusura dell'avviso `size-9` (36 px).
- `Configurazione.vue:84`: il pulsante principale non usa `.btn` (`rounded-lg … py-2.5`, circa 42 px e raggio diverso)
  → `class="btn btn-primary"`.
- Supporto: `supporto/resources/views/partials/brand.blade.php:44` (`.menu a { padding:8px 12px }`, circa 38 px); la
  regola dei 44 px alla riga 110 vale solo per `.bar nav a`, non per il menu «Centro assistenza / Apri una richiesta».
  → aggiungere `.menu a` alla riga 110.
- Demo: `demo/asem-demo-style.php:74` pulsante di accesso `min-height:40px`.

**M-I4 · Campi sotto i 16 px o senza attributi per la tastiera**
- `Soci/Scheda.vue:276`: il campo con il link d'accesso ha `text-sm` (14 px) e `min-w-64`: su iOS la pagina si
  ingrandisce al tocco e il campo sfora a 320 px. → `class="field"`.
- `Configurazione.vue:37-46`: codice fiscale, partita IVA, provincia, RUNTS senza `autocapitalize`, `autocomplete` e
  `inputmode` (partita IVA: `inputmode="numeric"`; codice fiscale e provincia: `autocapitalize="characters"`; indirizzo,
  CAP, comune: `autocomplete="street-address"`, `postal-code`, `address-level2`).
- `Soci/Adesione.vue:66-70`: i campi del genitore non hanno `autocomplete` (`given-name`, `family-name`, `email`,
  `tel`), mentre quelli del socio sì (`:48-60`): è proprio il genitore a compilare dal telefono.
- `Operatori/Elenco.vue:70-71` e `Soci/Livelli.vue:25-27`, `:37-39`: campi con il solo segnaposto come etichetta
  visibile (in Livelli) o senza `autocomplete="off"` per dati di altre persone.
- `saas/resources/views/admin/promos.blade.php:31-32`: date come testo «AAAA-MM-GG» → `type="date"`.
- `supporto/resources/views/site/ticket-new.blade.php:13-14`: nome ed email senza `autocomplete`.

### 6.2 Migliorie

- `saas/resources/css/app.css:89-91`: testo base a 15 px, etichette della barra in basso a 12 px
  (`AppLayout.vue:139`): al limite per utenti non giovani. I campi sono a 16 px (`.field`, `text-base`), corretto.
- Larghezze minime che obbligano ad andare a capo ma non rompono: `min-w-64` nei moduli in riga (`Attivita/Data.vue:79`,
  `:99`, `Attivita/Scheda.vue:202`, `Risorse/Scheda.vue:208`, `Soci/Scheda.vue:179`, `:229`, `:287`,
  `Contabilita/Rendiconto.vue:67`) e `min-w-48` (`Operatori/Elenco.vue:58`): a 320 px con il margine di 16 px e il
  riquadro (`p-4`) restano 256 px, pari esatti. Meglio `min-w-0 flex-1 basis-full sm:basis-64`.
- Fogli (`components/ui/Sheet.vue:46`): `max-h-[88dvh]`, chiusura da 44 px, tasto Esc e ritorno del fuoco: ben fatti.
  Manca solo la chiusura trascinando verso il basso, che la maniglia disegnata alla riga 48 lascia intendere.
- Menu sul telefono (`AppLayout.vue:136-150`): barra in basso con 3 sezioni + «Altro», bersagli da 60 px: corretto. Il
  foglio «Altro» ripete le quattro voci dell'account (`:187-192`) già presenti nel foglio «Il tuo account» (`:164-171`),
  che dal telefono non si può aprire (il pulsante è solo nella barra laterale, `:110`): si può togliere il doppione
  estraendo un componente.
- Pagine d'errore del SaaS (`saas/resources/views/errors/layout.blade.php:20`): pulsanti da 48 px, testo a 16 px: bene.
- Invito dello staff (`saas/resources/views/staff/invite.blade.php:17-18`): campi `padding:10px 12px` a 16 px (circa 44
  px) e pulsante `padding:10px 18px` (circa 44 px): al limite, accettabile.

---

## 7. Logica

Le voci più gravi sono già nei Bloccanti del SaaS (S-B1 errori nascosti dietro il foglio, S-B2 abbonamento senza voce
di menu, S-B3 pagina 403, S-B4 errori dell'area soci, S-B5 vicolo cieco della verifica in due passaggi, S-B6 conferme
mancanti). Qui il resto.

**Collegamenti e rotte.** Tutti i collegamenti delle pagine Vue sono stati confrontati con le rotte dei moduli
(`saas/app/Modules/*/Http/routes.php`, `saas/config/fortify.php:30-45`, `PortaleServiceProvider.php:78`): **non ci
sono collegamenti a rotte inesistenti**. Esiste invece una rotta senza collegamento (`/gestionale/abbonamento`, S-B2).

**L-I1 · La domanda respinta non viene comunicata** (dettaglio in S-I16): `ApplicationService.php:180-186`.

**L-I2 · Prova gratuita promessa ma senza percorso** (dettaglio in §2, punto 5): `portale/views/_catalog_saas.php:23`,
`portale/views/saas_buy.php:81`.

**L-I3 · Funzione «Parte fiscale» in vetrina senza pacchetto acquistabile** (§2, punto 6): `saas/config/catalogo.php:66`.

**L-I4 · Il socio uscito non può rientrare dall'interfaccia**
- `saas/app/Modules/Soci/Http/routes.php` ha `POST /soci/{person}/uscita` e nessuna rotta inversa; in
  `Soci/Scheda.vue:283` la sezione «Uscita dall'associazione» sparisce quando `person.left_on` è valorizzato e il
  rinnovo della tessera è nascosto (`:168`, `!person.left_on`). Un'uscita registrata per errore non si corregge.
- Proposta: pulsante «Annulla l'uscita» nella stessa sezione, oppure testo chiaro prima della conferma («L'uscita non
  si annulla: se la persona torna, va fatta una nuova domanda»).

**L-I5 · Area soci: si prenota sempre una sola fascia**
- `saas/resources/js/pages/AreaSoci/Risorse.vue:9` (`units: 1`) e `:43`: il socio tocca una fascia e parte la
  prenotazione di una fascia. Se la risorsa richiede più fasce di seguito (`min_slots`, impostabile in
  `Risorse/Scheda.vue:238`), il socio riceve un errore senza poter scegliere quante fasce. Il gestionale ha invece la
  tendina «Fasce» (`Risorse/Scheda.vue:138-139`).
- Proposta: partire da `units: res.min_slots` e mostrare la scelta «Per quante ore?» quando `max_slots > 1`.

**L-I6 · «Mandami un altro codice»: l'errore non si vede**
- `AreaSoci/Accesso.vue:32-33` riusa il modulo `ask`. Se il server risponde «Troppi tentativi: riprova tra qualche
  minuto.» (`saas/app/Modules/AreaSoci/Http/Controllers/AccessController.php:118`, chiave `email`), il messaggio è
  disegnato solo alla riga 22, dentro il modulo con `v-if="step !== 'code'"`: nel passo del codice non compare e il
  socio continua a premere senza ricevere niente.
- Proposta: alla riga 29 mostrare anche `ask.errors.email`:
  `<p v-if="code.errors.code || code.errors.email || ask.errors.email" role="alert">{{ code.errors.code || code.errors.email || ask.errors.email }}</p>`.

**L-I7 · Il registro delle azioni è citato ma non consultabile**
- `Esportazione.vue:14` e `Soci/Scheda.vue:189` dicono che scarichi e consultazioni «restano nel registro delle
  azioni»; l'esportazione lo include tra i file (`Esportazione.vue:10`), ma nel gestionale non c'è una pagina per
  leggerlo (nessuna rotta in `saas/app/Modules/Audit` o `Core/Http/routes.php`). Per il titolare, che è il responsabile
  dei dati, è un'informazione promessa e non raggiungibile se non scaricando lo ZIP.

**L-M1 · Stati mostrati in modo diverso tra gestionale e area soci**
- Il socio senza tessera valida è «Senza tessera» nel gestionale (`Soci/Elenco.vue:58`) e «Da rinnovare per il 2026»
  nell'area soci (`AreaSoci/Tessera.vue:30`); la tessera sospesa («Tessera sospesa», `Tessera.vue:28`) non ha un
  equivalente visibile nella scheda del gestionale (`Soci/Scheda.vue:99-102` conosce solo uscito / in regola / senza
  tessera valida).

**L-M2 · Dopo il salvataggio della configurazione il messaggio rimanda a una pagina che non si chiama così** (S-I2).

**L-M3 · Amministrazione: la bozza si pubblica da un'altra scheda**
- In «Piani», «Pacchetti» e «Promozioni» il pulsante è «Controlla e salva nella bozza»
  (`saas/resources/views/admin/plan-form.blade.php:90`, `addon-form.blade.php:51`, `promos.blade.php:45`); per
  pubblicare bisogna andare in «Catalogo» (`admin/catalog.blade.php:32`). L'avviso c'è nel testo
  (`admin/plans.blade.php:6`) e nel riepilogo (`admin/home.blade.php:5`), ma dopo il salvataggio manca un collegamento
  diretto nel messaggio di conferma (`AdminCatalogEditorController.php:83`, `:136`, `:156`). → aggiungere il
  collegamento «Vai a pubblicare» accanto al messaggio.

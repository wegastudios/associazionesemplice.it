# Design, passo 5: dalle tabelle agli elenchi

Specifica di progetto per la sessione di sviluppo. Completa [DESIGN.md](DESIGN.md) §6 (passo 5). Riferimento di come
deve venire: `resources/js/pages/Soci/Elenco.vue` (ricerca, filtri a pastiglia, `list-group`, foglio «Altri strumenti»).
Si disegna a 390 px; da `lg` (1024 px) tornano le tabelle dove i dati sono davvero a colonne.

## 1. Regola generale

| Tipo di dato | Telefono (< `lg`) | Da `lg` |
|---|---|---|
| Elenco di cose (attività, risorse, conti, certificati, operatori, domande) | `list-group` con `list-row`: titolo, dettaglio, valore o badge a destra | resta lo stesso elenco, più largo; niente tabella |
| Registro con molte colonne (libro soci, elenco pagamenti online) | `list-row` con i 3 dati chiave; il resto in un `Sheet` di dettaglio | tabella completa |
| Griglia persona × giorno (presenze, calendari) | un giorno alla volta (selettore di giorno + elenco) | griglia |
| Prospetto con totali (rendiconto, totali attività) | righe `label — importo` dentro un `list-group`, totale in riga evidenziata | stesso, in due colonne |

Una sola struttura nel codice: i dati si preparano una volta (`rows`) e due viste (`lg:hidden` / `hidden lg:block`)
leggono le stesse righe. Dove non serve la tabella, niente doppia vista.

## 2. Componenti nuovi (da creare in `components/ui`)

1. **`Chips`**: gruppo di filtri a pastiglia (oggi la funzione `chip()` è copiata in Soci/Elenco e Certificati). Props:
   `options [{value,label,count?}]`, `v-model`. Scorre di lato su telefono con `-mx-4 px-4`, `aria-pressed`, altezza 40 px.
2. **`StatRow`**: riga di 2–3 cifre in alto (es. Entrate / Uscite / Risultato). Griglia `grid-cols-3`, valore 17/24
   SemiBold `tabular-nums`, etichetta 13/18 grigia; il negativo in rosso **con segno meno**, non solo colore.
3. **`DayPicker`**: striscia di giorni (7 pastiglie scorrevoli, oggi evidenziato) per presenze e calendari.
4. **`SectionList`**: `list-group` con titolo di sezione 17/24 sopra (sostituisce i `card p-4` con `<h2>` + tabella).

`list-row` ha già il comportamento giusto (bersaglio 44 px). Non servono nuove classi in `app.css` oltre a un
`list-row-total` (fondo `surface`, testo SemiBold, filetto sopra) per le righe dei totali.

## 3. Pagina per pagina

### Attività (`Attivita/Elenco`)
- Intestazione: `PageHeader` «Attività» con sottotitolo; azione «Nuova attività» a destra solo da `lg` (da telefono sta
  nel foglio **Nuovo**); «Calendario» nel foglio «Altri strumenti» (icona `…` accanto alla ricerca).
- Anno sociale: `select` nativo → `Segmented`/`Chips` se gli anni sono ≤ 3, altrimenti `select` a larghezza piena sotto il titolo.
- In alto `StatRow`: Entrate · Uscite · Risultato dell'anno.
- Riga: titolo = nome; dettaglio = `Corso · 18 iscritti` (+ `· archiviata` e testo grigio); a destra il **risultato**
  (rosso con «−» se negativo). Entrate e uscite nel dettaglio solo da `sm`: `+1.200 € / −950 €`.
- Vuoto: `EmptyState` «Nessuna attività in questo anno sociale» + «Nuova attività».

### Risorse (`Risorse/Elenco`)
- Riga: titolo = risorsa; dettaglio = `A ore · da 10 €/h`; a destra badge di oggi: «Libera» (salvia), «Occupata»
  (giallo), «Bloccata» (neutro). Il badge ha sempre la parola.
- Filtro `Chips` per tipologia (A ore, A giornata, Stagionale) se ne esistono almeno due.
- Vuoto: se la tipologia non è nel pacchetto, testo «Questa tipologia non è nel tuo pacchetto» (non il pulsante Crea).

### Conti (`Contabilita/Conti`)
- Riga: titolo = nome del conto; dettaglio = tipo (Cassa, Banca…); a destra **saldo**. Il saldo iniziale solo nel foglio di modifica.
- Totale dei conti come ultima riga `list-row-total`.
- Modifica e nuovo conto: in `Sheet`, non in pagina.

### Certificati medici (`Soci/Certificati`)
- `Chips` con conteggi: Tutti · In scadenza (3) · Scaduti (2) · Mancanti · Validi.
- Riga: `Avatar` + nome; dettaglio `n. 125 · scade 14/03/2027`; a destra badge: «Valido» salvia, «In scadenza» giallo,
  «Scaduto» rosso, «Mancante» neutro. Tocco = scheda del socio, sezione certificati.
- Ordine predefinito: prima scaduti, poi in scadenza, poi gli altri (oggi alfabetico: nasconde il lavoro da fare).

### Libro soci (`Soci/LibroSoci`)
- Telefono: riga = numero d'ordine + nome; dettaglio = `Ammesso il 12/09/2024 · tessera 125`; badge «Uscito» se cessato.
  Tocco → `Sheet` «Dettaglio»: nascita, residenza, ammissione, cessazione, ultima quota, tutore (elenco `dt/dd`).
- Da `lg`: tabella completa com'è (è un registro, le colonne contano).
- Strumenti (PDF, CSV) nel foglio «Altri strumenti» sul telefono; pulsanti in intestazione da `lg`.

### Rendiconto (`Contabilita/Rendiconto`)
- Selettore esercizio sotto il titolo (`select` a larghezza piena su telefono).
- `StatRow`: Entrate · Uscite · Avanzo/disavanzo (con segno).
- Due `SectionList` (Entrate, Uscite): riga = categoria, gruppo in grigio sopra (come ora), importo a destra; totale in
  `list-row-total`. Da `lg` affiancate in due colonne.
- «Conti»: riga = nome, a destra `fine esercizio`; l'inizio esercizio è nel dettaglio sotto il nome (`da 1.200 €`).
- «Chiusura dell'esercizio»: pulsante a larghezza piena su telefono; riapertura chiede il motivo in `Sheet`.

### Presenze e calendari (`Attivita/Presenze`, `Attivita/Calendario`, `Risorse/Calendario`)
- Telefono: `DayPicker` in alto, sotto l'elenco di quel giorno. Presenze: riga = persona con interruttore
  Presente/Assente (due pulsanti `btn-sm` affiancati, 44 px, mai una casella piccola). Calendario risorse: riga =
  fascia oraria (`18:00–19:00`), a destra chi ha prenotato o «Libera»; tocco libero = apre il foglio «Prenota».
- Da `lg`: griglia com'è.
- Salvataggio presenze: automatico a ogni tocco con conferma a comparsa («Salvato»); niente pulsante finale su telefono.

### Operatori e Domande (`Operatori/Elenco`, `Soci/Domande`)
- Operatori: riga = `Avatar` + nome; dettaglio = email; badge ruolo (Titolare, Segreteria…) e, se manca, «2FA da attivare»
  in giallo. Invito, cambio ruolo e rimozione nel `Sheet` della persona. Ruoli personalizzati: sezione separata in fondo.
- Domande: riga = nome + `ricevuta il 03/11`; badge «Da esaminare» giallo; ordine dalla più vecchia (è un elenco di cose da fare).
  Accogliere/Respingere nel dettaglio, «Respingi» chiede il motivo in `Sheet`, pulsante pericoloso `btn-danger`.

### Pagamenti online, gestionale (`AreaSoci/PagamentiOnline`)
- Riga = socio; dettaglio = `3 voci · 14/11 · Stripe`; a destra importo; sotto l'importo badge stato: «Pagato» salvia,
  «Da controllare» giallo (primo nell'elenco, con conteggio nella Home), «Fallito» rosso. Ricevute nel `Sheet` di dettaglio.
- Impostazioni (chiavi) in pagina separata o `Sheet` con conferma password; mai mostrare le chiavi.

## 4. Verifica richiesta a ogni pagina

Screenshot a 360, 390, 768 e 1280 px; nessuno scorrimento orizzontale tranne i `Chips`; tutti i bersagli ≥ 44 px;
contrasto ≥ 4,5:1 (giallo con testo `#6B4F00`); ogni stato ha una parola; con «riduci movimento» il foglio non anima;
importi con `tabular-nums` e segno esplicito per i negativi; i test Pest delle pagine continuano a passare (i dati
inviati da Inertia non cambiano, tranne `count` nei filtri e l'ordine dei certificati).

## 5. Ordine di lavoro consigliato

1. `Chips`, `StatRow`, `list-row-total` (poi Soci/Elenco e Certificati usano `Chips`).
2. Certificati, Domande, Operatori, Conti (elenchi semplici).
3. Attività, Risorse, Pagamenti online.
4. Rendiconto, Libro soci (con `Sheet` di dettaglio).
5. Presenze e calendari con `DayPicker` (il pezzo più grande).

Aggiornare lo stato del passo 5 in [DESIGN.md](DESIGN.md) §6 solo a lavoro finito.

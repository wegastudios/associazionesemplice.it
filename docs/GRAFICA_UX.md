# Grafica e UX: una regola per tutti i sistemi

**Stato: decisione della titolare (11/11/2026), scritta dalla sessione Portale.** Vale per portale, supporto, gestionale (SaaS) e area admin del plugin. Se un sistema fa diversamente, ha torto il sistema, non questo documento. Si cambia solo qui, in una PR, e si avvisano le altre sessioni.

Base visiva già esistente: `saas/docs/DESIGN.md` (principi, componenti, navigazione del gestionale) e `docs/brand/README.md` (marchio). Questo documento aggiunge ciò che mancava: **una sola fonte dei valori, una sola barra comune, una sola regola per i link, un controllo che li verifica**.

## 1. Una sola fonte dei valori

- Colori, raggi, tocchi e carattere sono in **`docs/brand/tokens.css`** (variabili `--as-*`, tema chiaro e scuro). Non si inventano colori, raggi o ombre: si usano quelli.
- Si prende il valore dal file, non lo si «arrotonda»: oggi portale (`#f6f8f6`, `#14172e`, raggio 16 ovunque), gestionale (`#f5f6fa`, `#181a2e`) e supporto sono leggermente diversi tra loro. **Vince `tokens.css`** (è quello di `DESIGN.md`, con i contrasti verificati).
- Carattere: **Montserrat** ospitato da noi (Medium, SemiBold, Bold), mai da servizi esterni. Scala sul telefono: titolo pagina 24/30 Bold, sezione 17/24 SemiBold, testo 15/22, dettagli 13/18 in grigio, campi 16.
- Forme: campi e pulsanti **12**, riquadri **16**, fogli **24**. Niente sfumature, niente ombre sui riquadri (solo fogli e barra in basso), niente animazioni di ingresso; si rispetta «riduci movimento».
- Icone: Lucide, tratto 1,75, sempre con la parola accanto nelle voci di navigazione.
- Il colore non è mai l'unico segnale: ogni stato ha anche una parola. Salvia (`--as-sage`) mai per testo.
- Dove il sistema non controlla l'aspetto (wp-admin: menu, barra e colori di WordPress; sito soci: tema del cliente) si rispetta solo ciò che è nostro: i contenuti delle pagine del plugin (`docs/RESTYLE.md`).

## 2. La barra comune (identica su portale, supporto e gestionale)

Stessa barra in alto, nello stesso ordine, con le stesse parole. Cambia solo il contenuto sotto.

| Posto | Voce | Dove porta |
|---|---|---|
| Sinistra | **Logo** | `https://associazionesemplice.it/` (sempre il portale, mai la home del sistema in cui si è) |
| Centro (solo sito pubblico) | Funzioni · Prezzi · Domande · Supporto | pagine del portale; «Supporto» → `https://supporto.associazionesemplice.it/` |
| Destra, non connesso | **Accedi** | `https://associazionesemplice.it/accedi` |
| Destra, connesso | **Il mio account** (nome o email) | `https://associazionesemplice.it/account` |

Dentro supporto e gestionale la barra ha solo: logo, il nome del posto («Supporto», «Gestionale»), **Il mio account**. Il menu proprio del sistema (voci del gestionale, argomenti del supporto) sta sotto la barra, non dentro.

- **Esci** è una sola azione ovunque: le altre aree linkano `https://associazionesemplice.it/account/esci` (una GET: mostra «Vuoi uscire?» col pulsante che fa la POST con il token del portale; nessun POST da altri domini). Chiude la sessione unica e quindi tutte le aree. Nessun sistema ha un proprio «Esci» che chiude solo se stesso.
- **Nomi fissi** (mai sinonimi): «Il mio account» (`/account`), «Gestionale», «Supporto», «Gestione del plugin» (solo staff), piani «Contabilità» e «Contabilità & Fiscale». Il nome del fornitore interno non compare mai ai clienti.
- Mobile: da 1024 px barra e menu laterale; sul telefono barra bassa (56 px) più barra in basso come in `DESIGN.md` §5. La barra comune non supera 56 px.

## 3. Come si passa da un posto all'altro (la regola dei link)

1. **Tutto parte da `/account`.** Verso un'altra area *protetta* (supporto, gestionale, gestione del plugin) non si mette un link diretto: si manda a `/account`, dove «Entra in: …» chiede il token al SaaS e porta all'area senza un altro accesso. Eccezione: dentro `/account` e nelle email è ammesso il collegamento diretto già firmato (`/account/da-gestionale?t=…`).
2. **Chi non è connesso** e apre un'area protetta va a `https://associazionesemplice.it/accedi` e, dopo l'accesso, torna lì (destinazione scelta dal sistema, mai da un parametro dell'indirizzo: niente rinvii aperti).
3. **Link tra sistemi sempre assoluti**, con `https://` e il dominio giusto (tabella §4); mai relativi al sistema sbagliato, mai a indirizzi tecnici (`app.…`, `/saas/staff/accedi`, `/wordpress/admin/accedi`) nel testo visibile.
4. **Ogni pagina ha una via d'uscita**: logo, «Il mio account» o un «Torna a …» esplicito. Mai un vicolo cieco (pagine d'errore comprese).
5. **404 e errori** hanno lo stesso schema in tutti i sistemi: titolo in italiano semplice, una frase su cosa fare, due pulsanti: «Torna al tuo account» e «Vai alla home».
6. **Un link o c'è e funziona, o non c'è.** Una voce di menu senza permesso non compare, non si mostra grigia.

## 4. Indirizzi ufficiali (unica tabella)

| Cosa | Indirizzo |
|---|---|
| Sito, listino, confronto | `https://associazionesemplice.it/` |
| Accesso, reset password, 2FA | `/accedi`, `/accedi/password-dimenticata`, `/twofactor` |
| Il mio account (punto d'arrivo) | `/account` |
| Esci | `/account/esci` (GET: conferma; POST: esce) |
| Acquisto gestionale | `/saas/acquista?plan=` |
| Gestione del plugin (staff) | `/wordpress/admin` |
| Gestionale (staff e clienti) | `https://associazionesemplice.it/saas/admin` (servito dal SaaS sotto lo stesso dominio) |
| Supporto | `https://supporto.associazionesemplice.it/` |
| Demo | `https://demo.associazionesemplice.it/` |

Se un indirizzo cambia si cambia qui per primo.

## 5. Testi

- Italiano semplice e informale; azioni che dicono cosa fanno («Registra l'incasso», non «Invia»); lo stesso nome dall'inizio alla fine.
- Errori che spiegano come rimediare; schermate vuote che propongono il passo successivo.
- Frasi con la maiuscola iniziale, mai tutto maiuscolo.

## 6. Controlli (cosa deve passare prima di unire una PR)

1. **Valori**: nessun colore esadecimale o raggio nel CSS del sistema che non sia in `tokens.css` (si controlla con una ricerca nel CSS).
2. **Link**: ogni pagina nuova o toccata ha il logo che porta al portale, «Il mio account» o «Accedi», e nessun link che non sia in §4.
3. **Schermi**: screenshot a 360, 390, 768 e 1280 px, tema chiaro e scuro, navigazione da tastiera.
4. **Verifica dei link in produzione**: dopo ogni pubblicazione un controllo automatico apre le pagine pubbliche e segue i link della barra e del piede: ogni risposta deve essere 200 o un rinvio che finisce in 200. Un 404 su un link nostro blocca il rilascio. (Lo script sta in `portale/bin/link-check.php`, lo lancia chi pubblica.)

## 7. Chi applica cosa

| Sistema | Chi | Cosa cambia |
|---|---|---|
| Portale (sito, `/account`, `/accedi`) | sessione Portale | `portale.css` prende i valori da `tokens.css`; barra e piede secondo §2; pagine d'errore §3.5 |
| Supporto | sessione Supporto | stessa barra; valori da `tokens.css` (oggi segue `portale.css`: ora segue `tokens.css`); «Il mio account» e «Esci» come §2 |
| Gestionale (SaaS) | sessione Sviluppo SaaS | barra comune sopra la propria; «Esci» → `/account/esci`; indirizzi di §4; il tema (`app.css`) è già vicino: allinearlo ai token |
| Plugin WordPress | sessione Plugin | solo contenuti delle pagine (`docs/RESTYLE.md`); link «Il mio account» dove ha senso |

Avviso: chi cambia `tokens.css` o la barra avvisa le altre tre sessioni nello stesso momento.

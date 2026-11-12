# Prenotazione dalla pagina evento per chi non è socio, e «Prenota e paga»

Bozza del 12/11/2026. **Non implementata**: tocca anagrafica, privacy, regole sugli ospiti e pagamenti; le scelte di sotto sono della titolare. Le pagine pubbliche (fasi 1-5) prenotano oggi solo i soci con accesso.

## Cosa esiste già nel plugin (da riusare, non reinventare)
- **Ospiti**: persone di tipo ospite, con `guests_enabled`, limite `guest_max_events` (oggi 2 partecipazioni prima di iscriversi), contributo ospiti per attività (`guest_fee_cents`), regole su cellulare/ospitante (`Rules`).
- **Domanda di adesione online** (`Applications::submit`): modulo pubblico con risposta uguale a prescindere (nessuna enumerazione), limite di richieste in coda (`applications_max`), email di conferma, approvazione dalla Bacheca.
- **Blocco posti** (`hold_hours`/`Holds`), **lista d'attesa** (`Waitlist`), **ingresso sul posto** (`DoorSales`: prenota + incassa + ingresso), pagamenti online del Pro (Stripe, PayPal, SumUp, Satispay).

## Proposta
**Percorso A: «Richiedi un posto» (consigliato come primo passo, gratuito e Pro)**
1. Sulla pagina evento, chi non è collegato vede, accanto a «Accedi», il modulo breve: nome, cognome, email, telefono, numero di posti (1–4), consenso privacy (informativa collegata), campo trappola e tempo minimo di compilazione. Nessun CAPTCHA esterno.
2. Si salva una **richiesta** (non una prenotazione) e parte un'email di conferma con collegamento a scadenza (24 ore). Risposta uguale se l'email è già nota.
3. Alla conferma dell'email la richiesta passa «in attesa di approvazione» e arriva alla segreteria (Bacheca → Richieste). **Con approvazione** o **automatica** (scelta per evento): approvando si crea l'ospite e la prenotazione, rispettando posti e lista d'attesa, `guest_max_events` e contributo ospiti.
4. Limiti: richieste per indirizzo IP (`ClientIp`) e per email, tetto di richieste in coda (come l'adesione), privacy: la pagina pubblica non mostra mai i nomi.

**Percorso B: «Prenota e paga» (Pro, dopo A)**
Se i pagamenti online sono configurati e l'evento ha un contributo: la prenotazione resta «in attesa di pagamento» per `hold_hours` e si conferma al pagamento (webhook esistenti). I soci pagano dalla propria area, come oggi; i non soci dal collegamento ricevuto nell'email di conferma.

## Domande per la titolare
1. **Chi può prenotare online come non socio?** Tutti (ospiti) o solo chi poi si iscrive come socio (rimando alla «Diventa socio»)? *(proposta: ospiti attivi con il limite `guest_max_events`; oltre, invito a iscriversi)*
2. **Approvazione**: automatica o con approvazione della segreteria, per evento? *(proposta: casella per evento, predefinita «con approvazione»)*
3. **Dati da chiedere**: bastano nome, cognome, email, telefono? Codice fiscale e data di nascita servono per l'assicurazione/registro di chi partecipa? *(proposta: solo i quattro campi; il resto lo raccoglie la segreteria all'approvazione)*
4. **Minori**: se l'evento è aperto ai minori, serve il dato del genitore al momento della richiesta? *(proposta: dicitura «prenota un adulto per i minori» e raccolta all'approvazione)*
5. **Quanti posti** per richiesta (accompagnatori)? *(proposta: fino a 4, ciascuno un ospite)*
6. **Pagamento dei non soci**: online sempre, o «paga sul posto/bonifico» come oggi per i soci? *(proposta: se il Pro ha pagamenti online, «Prenota e paga»; altrimenti da versare sul posto)*
7. **Conservazione dei dati** delle richieste non approvate o non confermate: cancellarle dopo 30 giorni? *(proposta: sì, automatico)*
8. **Disdetta** del non socio: dal collegamento nell'email (stessi termini del regolamento)? *(proposta: sì)*

## Rischi e protezioni da prevedere
Invio massivo di richieste (limite per IP/email/giorno e tetto globale), account fantasma (conferma email), enumerazione (risposta uguale), spam di email dal modulo (un solo messaggio per email e ora), dati personali minimi, informativa e consenso registrati (`Privacy::set_consent`). Plugin Check: nonce sul modulo pubblico (come l'adesione), niente richieste a servizi esterni.

---
## Stato (12/11/2026): fase 6 implementata con le risposte della titolare
Vedi `EVENTI_DOMANDE_APERTE.md` per le decisioni. Realizzato: tabella `event_requests`, classe `EventRequests` (modulo «Non sei socio?» sulla pagina evento, conferma email entro 24 ore, prenotazione automatica o con approvazione, bonifico istantaneo entro `event_pay_hours` (Limiti, 24 ore) altrimenti posto cancellato, cancellazione dopo 30 giorni, limite partecipazioni ospiti), opzioni per evento nella pagina (ospiti, approvazione, posti per richiesta, nascondi se annullato), elenco con «Tutti i giovedì».
Fase 7: la richiesta di partecipazione con approvazione vale anche per i **soci** (pulsante «Chiedi di partecipare»; la segreteria le esamina nell'ordine di arrivo, senza priorità automatiche). Non ancora: «Prenota e paga» online (percorso B).

# Crediti (Pro Lite)

Il socio compra **crediti** (token) e li spende nelle attività e nelle risorse per cui è previsto il pagamento in crediti. Serve a ridurre le transazioni con il gateway: una ricarica online, poi molte spese. Si può spendere anche **dal vivo**, con un QR: la spesa la conferma il socio, l'incasso lo vede lo staff.

## Principi

- Il credito è un'unità a sé (numero intero), non un saldo in euro. L'ente decide quanti crediti costa ogni cosa.
- Si comprano **pacchetti** decisi dall'ente («10 crediti = 10,00 €», «50 crediti = 45,00 €»): online con il gateway, oppure in segreteria (contanti/POS).
- Il saldo è sempre la somma dei movimenti (`acquisto`, `spesa`, `rimborso`, `rettifica`); non è mai un numero memorizzato. Ogni spesa si fa sotto blocco per socio: due richieste insieme non portano il saldo sotto zero.
- Solo i soci. Gli ospiti non hanno crediti e non ne spendono.
- I crediti non scadono e non si convertono in denaro (decisioni da fissare nel regolamento dell'ente).

## Contabilità (ipotesi di lavoro, da confermare con il commercialista)

- **La vendita di un pacchetto è l'entrata** (principio di cassa), in prima nota come «Altra entrata» con la descrizione «Acquisto crediti». **La spesa dei crediti non registra niente in prima nota.**
- Per sapere cosa è stato speso dove c'è il registro dei movimenti (per attività, risorsa, staff e data).
- Alternativa, se serve la competenza: conto virtuale «Crediti dei soci» (debito verso i soci) e incasso registrato a ogni spesa. I movimenti ci sono già tutti, quindi si può aggiungere dopo senza perdere niente.

## Dove si spendono

- **Risorse** (`resources.credit_cost`): crediti per fascia (o per periodo, se assegnate). Se la risorsa ha un costo in crediti, prenotando il socio può pagare subito in crediti, oltre alle vie dell'ente (online, segreteria). Annullando una prenotazione pagata in crediti i crediti tornano al socio.
- **Attività** (`activities.credit_price`): i crediti previsti per partecipare. Per ora servono allo staff per l'addebito dal vivo (la scelta dell'attività compila l'importo); la prenotazione online di un evento con i crediti è un passo successivo.
- **Dal vivo**: qualunque cosa che lo staff addebita con l'importo scelto sul momento.

## Spesa dal vivo con il QR

1. Lo staff apre «Incasso crediti» nell'area soci, indica i crediti (o sceglie un'attività) e un testo, e preme «Mostra il QR».
2. Il sito crea un **addebito** valido 5 minuti, con un codice casuale, e mostra un QR grande con l'importo.
3. Il socio inquadra il QR con la fotocamera del telefono (o con la scansione dell'app): si apre una pagina che chiede l'accesso, mostra «Pagare N crediti per …? Saldo: X» e un pulsante **Conferma**.
4. Alla conferma i crediti si scalano (una sola volta), il socio vede «Pagato» e lo schermo dello staff passa a «Incassato da …» da solo.
5. Se il saldo non basta il socio lo vede e può ricaricare; lo staff può annullare l'addebito. Scaduto, il QR non funziona più.

Chi può addebitare: segreteria, tesoriere e lo staff degli eventi con il permesso di incasso. Il socio non conferma mai un importo che non ha visto: il QR non contiene dati personali, solo il codice dell'addebito.

## Dati

- `asem_credit_entries`: `person_id`, `delta` (con segno), `kind` (`buy`, `spend`, `refund`, `adjust`), `label`, `ref_type` e `ref_id` (prenotazione, addebito, pagamento), `created_by`, `created_at`.
- `asem_credit_charges`: `code` (casuale, univoco), `credits`, `label`, `activity_id`, `staff_person_id`, `status` (`pending`, `paid`, `cancelled`), `payer_person_id`, `entry_id`, `created_at`, `expires_at`, `paid_at`.
- `asem_activities.credit_price`, `asem_resources.credit_cost`, `asem_reservations.credits_paid`.
- Pacchetti nell'opzione `asem_credit_packs`.

## Licenza e permessi

Funzione `credits` (`Credits.php`), inclusa nel Pro Lite e nel Pro Full. Pagina di amministrazione `asem-credits` (pacchetti, saldi, vendita e rettifica a mano, movimenti). Viste per il sito: `[associazionesemplice_crediti]` (socio) e `[associazionesemplice_incasso_crediti]` (staff).

## Fuori da questa fase

- Prenotare un evento o un corso pagando in crediti dalla scheda dell'attività.
- Il QR personale del socio scansionato dallo staff (richiede una conferma del socio, per non addebitare con un codice che si può fotografare).
- Scadenza dei crediti, rimborso del residuo in denaro, regali tra soci.

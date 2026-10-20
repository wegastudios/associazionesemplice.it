# Risorse prenotabili (Pro Lite)

Specifica della funzione «Risorse»: campi da gioco, sale, posti barca, posti auto, armadietti e simili, gestiti accanto a corsi ed eventi.

## Principio

Un corso o un evento **conta posti** (quante persone ci stanno). Una risorsa è **esclusiva**: o è occupata o è libera, e a decidere è un conflitto di orario o di periodo. Per questo le risorse hanno un modello a sé e non sono un nuovo `ActivityKind`.

## Chi può fare cosa

- **Solo i soci** (tessera valida, non sospesi, regolamento accettato) prenotano o ricevono una risorsa. I non soci non hanno accesso a nulla.
- Un socio può **indicare degli ospiti** solo dove la risorsa lo ammette (es. tennis sì; posto auto, armadietto, posto barca no).
- L'ospite non diventa mai una persona in anagrafe: è un nome scritto sulla prenotazione. La prenotazione è e resta del socio, che ne risponde.

## Licenza e integrazione

- Funzione `resources` in `Edition::FEATURES` (file `Resources.php`), pagina `asem-resources` in `Edition::PAGES`. È inclusa nel **Pro Lite** e nel Pro Full; manca nell'edizione gratuita.
- Lo schema sta in `Install::create_tables()` come per le altre tabelle (`DB_VERSION` sale di uno). Senza licenza la funzione sparisce dall'interfaccia e i dati restano.
- Nel pacchetto gratuito non entrano i file della funzione: sono elencati in `tests/pro-files.txt`.

## Dati

### `asem_resources`

| campo | note |
|---|---|
| `id`, `name` | nome mostrato ai soci |
| `parent_id` | raggruppamento facoltativo («Impianto» → «Campo 1») |
| `category` | `court`, `room`, `berth`, `parking`, `locker`, `other` (solo etichetta e icona) |
| `mode` | `slot` (a fasce orarie) oppure `assign` (assegnazione a periodo) |
| `description`, `rules` | testo libero e regole d'uso mostrate prima di prenotare |
| `open_time`, `close_time` | solo `slot`: orario di apertura e chiusura |
| `slot_minutes` | solo `slot`: durata della fascia (es. 60) |
| `min_slots`, `max_slots` | solo `slot`: quante fasce di seguito si possono prendere |
| `advance_days` | solo `slot`: quanti giorni prima si può prenotare (0 = senza limite) |
| `max_per_person` | prenotazioni future attive per socio (0 = senza limite) |
| `fee_cents` | `slot`: per fascia; `assign`: per periodo |
| `rates` | solo `slot`: tariffe a fasce (JSON), una per riga nel modulo: «feriali 18:00-22:00 15,00» (giorni: tutti, feriali lun–ven, weekend sab–dom). Vale la prima riga che corrisponde all'ora di inizio di ogni fascia, altrimenti la tariffa base |
| `guests_max` | ospiti ammessi per prenotazione (0 = nessuno) |
| `guest_fee_cents` | supplemento per ospite (0 = nessuno) |
| `cancel_policy`, `cancellable` | stessa logica di `CancelPolicy` |
| `active`, `created_at`, `deleted_at` | |

### `asem_reservations`

| campo | note |
|---|---|
| `id`, `resource_id`, `person_id` | `person_id` vuoto solo per i blocchi |
| `kind` | `slot`, `assign`, `block` (manutenzione, chiusura, torneo) |
| `starts_at`, `ends_at` | data e ora; `ends_at` vuoto = assegnazione senza scadenza |
| `status` | `booked`, `cancelled` |
| `fee_due_cents` | dovuto alla prenotazione (fascia + ospiti) |
| `note`, `series_id` | `series_id` = numero della prima prenotazione di una ripetizione settimanale (Fase 2) |
| `session_id` | non usato: i corsi e gli eventi non creano prenotazioni, occupano la risorsa a calendario (vedi sotto) |
| `created_by`, `created_at`, `cancelled_at` | |

### `asem_reservation_guests`

`reservation_id`, `name` (nome e cognome). Niente altro in Fase 1.

### Incassi

`asem_transactions` riceve la colonna `reservation_id`. Il pagato di una prenotazione è la somma degli incassi con quel `reservation_id`, come per `session_id` nelle prenotazioni degli eventi.

## Regole

1. **Conflitto**: due prenotazioni attive della stessa risorsa non possono sovrapporsi (`a.starts < b.ends AND b.starts < a.ends`). Il controllo e la scrittura avvengono sotto `Db::with_lock('asem_res_<id>')`. Un'assegnazione senza scadenza occupa da `starts_at` in avanti.
2. **Risorse raggruppate**: prenotare il gruppo non è previsto in Fase 1; ogni risorsa si prenota da sola.
3. **Fasce**: l'inizio cade su un multiplo di `slot_minutes` dall'apertura; la fine non supera la chiusura; la durata è tra `min_slots` e `max_slots`.
4. **Anticipo e passato**: non si prenota nel passato né oltre `advance_days`. La segreteria può registrare anche a ritroso.
5. **Ospiti**: da 0 a `guests_max`; ogni ospite aggiunge `guest_fee_cents` al dovuto. Una risorsa con `guests_max` = 0 rifiuta gli ospiti.
6. **Assegnazioni** (`assign`): solo la segreteria assegna in Fase 1; il socio vede ciò che ha. Si assegna solo a soci con tessera valida. Se la tessera scade l'assegnazione resta ma compare in un elenco «da verificare» (rinnovo o rilascio).
7. **Annullamento**: il socio annulla con le regole di `CancelPolicy` (la fascia conta come evento: data e ora di inizio); la segreteria sempre. Gli incassi restano registrati (rimborso a mano), come per gli eventi.
8. **Blocchi**: la segreteria blocca una risorsa per un intervallo. Se esistono già prenotazioni nell'intervallo il blocco viene rifiutato: prima si annullano quelle (dal calendario della risorsa).
9. **Regolamento e tessera**: stessi controlli di `do_book` (`Regulation::booking_block`, `is_active_member`).
10. **Audit**: ogni creazione, annullamento e blocco passa da `Audit::log`.

## Interfaccia

### Amministrazione (menu «Risorse»)

- **Elenco**: risorse con categoria, modalità, prossime prenotazioni; creazione e modifica.
- **Scheda risorsa**: dati, regole, tariffe, ospiti ammessi; calendario del giorno/settimana a griglia con le fasce libere e occupate; prenotazione per conto di un socio; blocchi; elenco delle assegnazioni.
- **Prenotazione**: chi, quando, ospiti, dovuto, pagato, incasso con un clic (usa i conti già presenti), annullamento.

### Sito (shortcode `[associazionesemplice_risorse]`, blocco e widget Elementor)

- Elenco delle risorse prenotabili; per le `slot` la griglia del giorno con le fasce libere; per le `assign` ciò che il socio ha.
- Prenotazione con scelta delle fasce, ospiti (se ammessi) e riepilogo del dovuto.
- Vista «Le mie risorse» con prenotazioni future, assegnazioni e annullamento; si aggiunge all'area soci (`sezioni`).
- Chi non è socio o non ha la tessera valida vede il motivo e nient'altro.

## Fasi

**Fase 1 (fatta)**: tabelle, servizio con conflitti e ospiti, pagina admin (elenco, scheda, calendario, prenotazione, blocchi, assegnazioni), prenotazione e annullamento dal sito, dovuto e incasso in segreteria, test.

**Fase 2a (fatta)**:
- **Ripetizioni**: la segreteria prenota la stessa fascia per più settimane («ogni martedì alle 21», fino a 52), tutte o nessuna; si annullano una prenotazione e le successive della serie. I soci non ripetono da soli (il limite di prenotazioni per socio lo impedirebbe).
- **Tariffe a fasce**: feriale/weekend e diurno/serale, per risorsa (campo `rates`). Non ci sono i festivi di calendario: contano solo sabato e domenica.
- **Corsi ed eventi**: un corso o un evento si collega a una risorsa a fasce dal suo modulo (campo `activities.resource_id`). Non crea prenotazioni: la risorsa risulta occupata nelle sue date dal calendario (`Calendar::occurrences`), quindi non c'è niente da sincronizzare se l'attività cambia. Un'attività senza orario occupa tutto il giorno. Collegando si avvisa se ci sono già prenotazioni negli stessi orari (prossimi 120 giorni).
- **Lista d'attesa delle assegnazioni** (`asem_resource_waitlist`): il socio si mette in coda se il posto è occupato; quando la segreteria rilascia, il primo riceve un'email e l'assegnazione resta alla segreteria; assegnando, chi era in coda esce dalla lista.

**Fase 2b (da fare)**: pagamento online del socio (`PaymentItems` riconosce oggi solo le prenotazioni degli eventi), tolleranza di pagamento (`Holds`), promemoria prima della prenotazione, categoria contabile per risorsa.

**Fase 3**: ingresso con QR, carnet di ore, report di occupazione, assegnazioni richieste dal socio con approvazione.

## Fuori perimetro

- Spazi a capienza condivisa (una sala da 20 posti): sono eventi.
- Prenotazioni di non soci e account per non soci.
- Persone ospiti in anagrafe, comunicazioni agli ospiti.

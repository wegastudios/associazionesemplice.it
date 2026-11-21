# Chiave principale: passaggio al servizio chiavi e ritorno

Come si passa dalla chiave principale locale (`SAAS_KEY_VAULT=local`, chiave in `SAAS_MASTER_KEY`) al servizio chiavi
sull'account separato (`SAAS_KEY_VAULT=remote`), come si torna indietro e cosa si conserva. Le regole sulle chiavi
sono in [DATABASE.md](DATABASE.md) §5 e [ARCHITETTURA.md](ARCHITETTURA.md) §7; il servizio in
[../keyservice/README.md](../keyservice/README.md).

Le **chiavi dati** delle associazioni non cambiano: cambia solo chi le avvolge (`core.org_keys.wrapped_key`). I dati
cifrati, i file e le impronte di ricerca restano come sono.

## Comandi

| Comando | Cosa fa |
|---|---|
| `saas:keys:recovery FILE [--con-servizio]` | Copia di recupero cifrata con una frase segreta (prompt nascosto, chiesta due volte, almeno 16 caratteri). Contiene `SAAS_MASTER_KEY` se c'è, indirizzo e chiave di firma del servizio; con `--con-servizio` anche la chiave principale del servizio (prompt nascosto, provata sul servizio prima di salvarla) e gli indirizzi ammessi. File nuovo, permessi 600, mai nella cartella web né nella versione installata. |
| `saas:keys:recovery-check FILE [--ripristina=CARTELLA]` | Apre la copia con la frase e controlla che riapra le chiavi dati salvate (fino a 20), che chiave di firma e chiave pubblica siano una coppia e che il servizio usi la chiave della copia. Con `--ripristina` scrive `config.ini` del servizio e `saas.env` (permessi 600, mai sovrascritti). |
| `saas:keys:rewrap remote\|local [--dry-run] [--force]` | Riavvolge le chiavi dati di tutte le associazioni verso il servizio (`remote`) o verso la chiave locale (`local`). Per associazione, in transazione: apre, riavvolge, controlla che si riapra uguale, poi sostituisce. Ripetibile; `--dry-run` prova tutto senza scrivere. Una riga `keys.rewrap` in `core.platform_audit`. |
| `saas:keys:local-retire [--distruggi] [--file=…]` | Senza opzioni: data del passaggio e da quando la chiave locale si può distruggere. Con `--distruggi`: registra la distruzione (`keys.local_destroyed`) e ricopre e cancella le copie di recupero indicate con `--file`. Rifiuta prima di 30 giorni dal passaggio, se il SaaS non usa il servizio, se il servizio non apre tutte le chiavi o se `SAAS_MASTER_KEY` è ancora nel file d'ambiente. |
| `saas:keys:monitor` | Ogni 5 minuti dallo scheduler, solo con `SAAS_KEY_VAULT=remote`: il servizio risponde, accetta la firma, avvolge e svolge una chiave di prova e apre una chiave dati vera. Se non va, un'email agli indirizzi di amministrazione (`SAAS_STAFF_SUPER_ADMINS` e super amministratori dello staff, per identificativo della persona), una sola per guasto; una al ritorno. |

Nessun comando stampa chiavi o frasi, né le mette nel registro.

## Passaggio (prima sulla demo o sullo staging)

1. Account del servizio pronto (`keyservice/README.md`): chiavi da `php artisan saas:keys:generate`, **chiave
   principale nuova** (non quella di `SAAS_MASTER_KEY`), `config.ini` con gli indirizzi del SaaS.
2. Nel file d'ambiente del SaaS: `SAAS_KEY_SERVICE_URL` e `SAAS_KEY_SERVICE_SIGNING_KEY`, **lasciando**
   `SAAS_KEY_VAULT=local` e `SAAS_MASTER_KEY`. `php artisan config:cache`.
3. `php artisan saas:keys:recovery ~/chiavi-recupero-AAAA-MM.askr --con-servizio`, poi `saas:keys:recovery-check` sullo
   stesso file. La copia contiene la vecchia chiave locale e quella del servizio.
4. `php artisan saas:keys:rewrap remote --dry-run`: deve dire 0 associazioni non riuscite.
5. `php artisan down`, poi `php artisan saas:keys:rewrap remote`.
6. `SAAS_KEY_VAULT=remote` nel file d'ambiente, `php artisan config:cache`, di nuovo `saas:keys:rewrap remote` (deve
   dire 0 riavvolte), `php artisan saas:health`, `php artisan up`.
7. Tolta `SAAS_MASTER_KEY` dal file d'ambiente, `config:cache`. Da qui la vecchia chiave esiste solo nella copia di
   recupero.
8. Copia di recupero scaricata offline in due posti diversi, cancellata dal server.

## Ritorno alla chiave locale

Con `SAAS_MASTER_KEY` di nuovo nel file d'ambiente (dalla copia di recupero con `saas:keys:recovery-check
--ripristina`, o una nuova da `saas:keys:generate`): `php artisan down`, `saas:keys:rewrap local --dry-run`,
`saas:keys:rewrap local`, `SAAS_KEY_VAULT=local`, `config:cache`, di nuovo `saas:keys:rewrap local` (0 riavvolte),
`php artisan up`. In produzione la chiave locale rende la piattaforma «di prova» (`RILASCIO.md`).

## Cosa conservare

- **La copia di recupero**, offline, in due posti, e la **frase segreta**, separata dal file (gestore di password).
- **La vecchia chiave locale per almeno 30 giorni** dopo il passaggio: è dentro la copia di recupero. I backup
  giornalieri restano 30 giorni e quelli fatti prima del passaggio hanno le chiavi avvolte con lei.
- Dopo 30 giorni: nuova copia di recupero (senza chiave locale) e sua verifica, poi
  `saas:keys:local-retire --distruggi --file=…` per le copie rimaste sul server e distruzione a mano delle copie
  offline vecchie.

## Cosa non fare mai con i segreti

- Mai chiavi o frase come argomento di un comando, in un ticket, in chat, in un commit o in un'email.
- Mai la copia di recupero nella cartella pubblica, nel repository o accanto alla sua frase.
- Mai la chiave principale del servizio nel file d'ambiente del SaaS: sta solo nel `config.ini` del servizio e nella
  copia di recupero.
- Mai `saas:keys:local-retire --distruggi` senza una copia di recupero nuova già provata.
- Questi comandi non ruotano le chiavi dati: per questo la vecchia chiave locale si distrugge appena i backup che la
  richiedono sono spariti, e non si tiene più a lungo del necessario.

# Servizio chiavi

Custodisce la chiave principale del SaaS e cifra o decifra le chiavi dati delle associazioni. Gira in un **account
cPanel separato** da quello del SaaS, così un dump del database o dei file del SaaS, da solo, non apre nessun dato
cifrato.

- `public/index.php`: unico file nella cartella pubblica (document root del sottodominio, solo HTTPS).
- `src/`: protocollo (firma Ed25519, ora, nonce), cifratura delle chiavi; nessuna dipendenza esterna, PHP 8.3 con sodium.
- `config.ini` (da `config.ini.example`, permessi 600) e `storage/` (registro, nonce e contatori) stanno fuori dalla
  cartella pubblica.

Il SaaS usa la stessa classe `KeyWrap` per il ripiego locale (`SAAS_KEY_VAULT=local`). I test sono in
`tests/Unit/KeyService`. Dettagli in [../docs/DATABASE.md](../docs/DATABASE.md) §5 e
[../docs/SVILUPPO.md](../docs/SVILUPPO.md); passaggio dalla chiave locale al servizio, copia di recupero e controllo
in [../docs/CHIAVE_REMOTA.md](../docs/CHIAVE_REMOTA.md).

## Come risponde e cosa scrive

- **Errori**: ogni errore imprevisto esce come `500 {"error":"errore interno"}`; avvisi ed errori PHP non vanno mai a
  schermo (`display_errors` spento dal servizio). Nel registro finisce solo il tipo dell'errore, non il messaggio, che
  potrebbe contenere percorsi.
- **Registro** (`log_file`): una riga per operazione (ora, indirizzo, operazione, associazione, versione), mai chiavi.
  Caratteri di controllo tolti, righe tagliate a 400 caratteri. Oltre `log_max_bytes` (partenza 5 MiB) passa a `.1`,
  `.2`, ... e se ne tengono `log_keep` (partenza 3) oltre a quello in corso: lo spazio occupato ha un tetto.
- **Aperture al minuto**: ogni operazione `unwrap` (apertura di una chiave dati) è contata, su due corsie.
  - **Per associazione** (le pagine e i lavori in coda): oltre `unwrap_alarm_per_org_per_minute` (partenza 100) il
    registro riporta `ALLARME` e, se c'è `alarm_email`, parte un'email; oltre `unwrap_limit_per_org_per_minute`
    (partenza 200) il servizio risponde `429` a quella associazione, e solo a lei, fino al minuto dopo. I nomi di prima
    (`unwrap_alarm_per_minute`, `unwrap_limit_per_minute`) valgono ancora, con lo stesso significato.
  - **Comandi su tutte le associazioni** (la richiesta firmata porta `"lane": "batch"`): un contatore per tutta la
    corsia, `batch_unwrap_alarm_per_minute` (250) e `batch_unwrap_limit_per_minute` (300). Il SaaS fa una pausa tra
    un'associazione e l'altra (`SAAS_KEY_BATCH_PAUSE_MS`, 500 ms: al massimo 120 associazioni al minuto) e resta
    sotto il limite anche con due aperture per associazione. `saas:keys:rewrap` ne fa due per ogni versione di
    chiave: con più versioni per associazione, alzare la pausa o il limite per il tempo del passaggio.
  - **Totale**: allarme oltre `total_unwrap_alarm_per_minute` (1000); `total_unwrap_limit_per_minute` parte spento (0).

  Le soglie si cambiano in `config.ini`. Se il contatore non si scrive, il servizio risponde 500 e non apre nulla.
- Il controllo `saas:keys:monitor` del SaaS vede un `429` o un `500` come guasto e manda l'email di avviso.

## Cosa regge e cosa no

**Regge contro:**
- un dump o un backup del database del SaaS rubato: le chiavi dati vi stanno solo avvolte con la chiave principale, che
  non c'è;
- una copia dei file del SaaS (codice, `.env`) senza accesso al servizio: la chiave di firma da sola non apre nulla se
  la richiesta non arriva da un indirizzo ammesso;
- una richiesta ripetuta o alterata (firma, ora, nonce);
- un uso massiccio per svuotare tutte le chiavi in poco tempo: oltre il limite il servizio rifiuta e segnala.

**Non regge contro:**
- chi esegue codice nell'account del SaaS: ha la chiave di firma e l'indirizzo ammesso, quindi può chiedere al servizio
  di aprire le chiavi, una associazione dopo l'altra, restando sotto il limite (il limite lo rallenta e lo rende
  visibile, non lo impedisce);
- chi amministra l'hosting (accesso root o a entrambi gli account): vede la chiave principale in `config.ini` e il
  codice di entrambi;
- chi ha già in memoria o nella cache le chiavi sbloccate: restano fino a 5 minuti.

**Separazione solo apparente se** il servizio e il SaaS stanno nello **stesso account di sistema** (stesso utente,
stessa cartella personale, stesso PHP-FPM): chi legge i file del SaaS legge anche `config.ini`. La separazione c'è solo
con un account diverso, con permessi 600 e cartelle non leggibili dall'altro account. Da verificare sul server.

**L'elenco degli indirizzi ammessi (`allowed_ips`) su hosting condiviso è l'indirizzo di tutto il server**, non del
singolo account: chiunque ospiti un sito sullo stesso indirizzo di uscita passa il controllo sull'indirizzo. A
fermarlo resta solo la firma. L'elenco serve contro il resto di Internet, non contro gli altri siti dello stesso server.

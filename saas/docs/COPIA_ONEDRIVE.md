# Seconda copia dei backup (OneDrive o disco fuori dal cloud)

Decisione del titolare del 24/11/2026: una **seconda copia** dei dump dei backup, fuori dal fornitore del server. Ogni ora il
tuo PC scarica i dump (già cifrati) e li mette in una cartella che scegli tu. Questa guida dice cosa prepara il codice e
cosa fai tu, passo per passo. **Nulla è installato: ogni passo qui sotto lo fai tu, quando decidi.**

File nel repository (cartella `saas/deploy/backup-onedrive/`):

| File | A cosa serve |
|---|---|
| `Scarica-Backup.ps1` | lo script che gira sul PC (Windows PowerShell 5.1) |
| `backup-readonly.sh` | il piccolo script che sta sul server e permette solo di leggere i dump |
| `test/` | prove con un server finto (si lanciano a mano o dalla CI, sezione 9) |

## 1. Come funziona, in breve

- Il PC si collega al server con una **chiave SSH dedicata**, diversa da quella dei rilasci (`deploy_key`).
- Quella chiave, sul server, può fare **solo due cose**: «elenca i dump» e «leggi questo dump». Non ha una shell, non può
  scrivere, cancellare o rinominare. Lo garantisce una riga in `~/.ssh/authorized_keys` che costringe a usare
  `backup-readonly.sh`.
- Lo script controlla ogni file scaricato: calcola l'impronta sha256 e la confronta con quella scritta nel `.json`
  accanto. Se non coincide il file **viene scartato** e compare l'avviso.
- I percorsi che arrivano dal server sono trattati come dati: se non hanno la forma esatta
  `<database>/<anno>/<mese>/<database>-<data UTC>.dump.enc[.json]`, se contengono `..` o sono assoluti, se il nome del
  database è riservato a Windows (`CON`, `NUL`, `COM1`…) o se il percorso locale uscirebbe dalla cartella di destinazione,
  non vengono usati.
- Sul PC tiene i dump delle ultime 48 ore e poi uno al giorno, **mai oltre 30 giorni** (la scadenza promessa ai clienti),
  con una sola eccezione voluta (sezione 6, «Potatura sospesa»).
- Se l'ultimo dump scaricato ha più di **26 ore**, compare l'avviso (notifica di Windows, file `ATTENZIONE.txt` nella
  cartella, riga nel log, codice di uscita diverso da 0).
- La chiave che apre i dump (`SAAS_BACKUP_SECRET_KEY`, la chiave privata dei backup) **non va mai** nella cartella di
  destinazione né sul PC che scarica. Lo script si rifiuta di partire se trova nella cartella file che sembrano chiavi.

Perché non `rrsync`, `scp` o `sftp`: su Windows non c'è `rsync`; su un hosting cPanel condiviso non si può usare
`internal-sftp` con `ChrootDirectory`; `scp`/`sftp` da soli non limitano bene cosa si legge. Un comando forzato con due soli
verbi (`list`, `get`) e un controllo rigido del percorso è la soluzione più corta da verificare.

## 2. Scegli dove tenere la copia (decide il titolare)

La cartella di destinazione è un **parametro obbligatorio** (`-Destination`), senza valore di partenza. Due varianti:

| Variante | Cosa è | Da sapere |
|---|---|---|
| **A. Cartella in OneDrive** | per esempio `C:\Users\<tu>\OneDrive\Backup Associazione Semplice` | la copia è anche su un cloud di terzi (Microsoft): vedi sezione 7 (cestino, cronologia, account) e [CANCELLAZIONE_DATI.md](CANCELLAZIONE_DATI.md) §3 ter. |
| **B. Cartella locale o disco esterno, fuori dal cloud** | per esempio `D:\BackupAssociazioneSemplice` | nessun terzo in più, ma il disco deve essere collegato e acceso quando gira l'attività; è una copia sola in più, quindi tienila in un posto diverso dal PC (disco spento e riposto, o un secondo disco). |

Tutto il resto della guida vale per entrambe; la sezione 7 solo per la A. In ogni caso la **chiave SSH** sta fuori dalla
cartella scelta (e, per la A, fuori da OneDrive).

## 3. Prima di cominciare

1. Windows 10/11 con **Client OpenSSH** (di serie; se manca: Impostazioni, App, Funzionalità facoltative).
2. Hai scelto la destinazione (sezione 2). Per la A: OneDrive attivo e account Microsoft con verifica in due passaggi,
   cartella non condivisa. (Il «Personal Vault» di OneDrive non va bene: da bloccato lo script non può scrivere.)
3. Per la A decidi cosa fare del **cestino di OneDrive** (sezione 7).

## 4. Crea la chiave dedicata (sul tuo PC, fuori da OneDrive)

In PowerShell, come utente normale. Controlla che `C:\Users\<tu>\.ssh` non sia dentro OneDrive; se la cartella del profilo è
spostata dentro OneDrive, scegli un'altra cartella.

```powershell
New-Item -ItemType Directory -Force "$env:USERPROFILE\.ssh" | Out-Null
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\backup_readonly_ed25519" -C "backup-sola-lettura"
```

Per un'attività automatica ogni ora la passphrase si lascia vuota: il file resta leggibile solo da te grazie ai permessi
qui sotto, e la chiave sul server ha comunque solo il permesso di lettura.

```powershell
$k = "$env:USERPROFILE\.ssh\backup_readonly_ed25519"
icacls $k /inheritance:r
icacls $k /grant:r "$($env:USERNAME):(R)"
```

Il file `backup_readonly_ed25519.pub` (la parte **pubblica**) va sul server; la privata non esce dal PC e non va mai
incollata in chat o nel repository.

## 5. Metti la chiave sul server (accesso nuovo: solo dopo il tuo sì)

Questo è un accesso nuovo al server: **lo si fa solo dopo che hai detto sì per iscritto**. Nessuno lo ha fatto.

1. **Prima di installare, in sola lettura, controlla che `find -printf` funzioni sul server vero** (il comando `list` lo usa
   e non l'abbiamo potuto provare lì). Dalla connessione con la chiave dei rilasci, senza scrivere nulla:
   `find ~/saas/shared/storage/app/backups/production -type f -name '*.dump.enc' -printf '%P\t%s\n' | head -3`.
   Deve stampare percorso, tabulazione e dimensione. Se stampa un errore su `-printf`, non andare avanti: va cambiato
   lo script lato server.
2. Copia `backup-readonly.sh` sul server in `~/saas/backup-readonly.sh` (fuori da `releases/`, così i rilasci non lo
   toccano) e imposta i permessi: `chmod 700 ~/saas/backup-readonly.sh`.
3. Apri `~/.ssh/authorized_keys` **senza toccare le righe esistenti** (c'è quella dei rilasci) e **aggiungi in fondo una riga**:

```
restrict,command="/home/ACCOUNT/saas/backup-readonly.sh" ssh-ed25519 AAAA...contenuto-del-file-.pub... backup-sola-lettura
```

   - `ACCOUNT` è il nome dell'account cPanel (lo stesso della cartella `/home/...` dei rilasci).
   - `restrict` spegne terminale, inoltro delle porte e agente; `command=` costringe la chiave a usare solo lo script.
   - Se l'indirizzo IP da cui ti colleghi è fisso puoi aggiungere `from="203.0.113.7"` (`restrict,from="…",command="…"`):
     è una difesa in più. Se l'IP cambia, non metterlo, o l'accesso smette di funzionare.
   - Se usi «SSH Access» di cPanel, la riga può essere aggiunta anche da lì; poi modificala nel file per aggiungere
     `restrict,command=...` davanti, perché cPanel da solo non mette restrizioni.
4. Prova dal PC (sola lettura, deve elencare i dump; **la prima volta** chiede di confermare l'impronta del server:
   confrontala con quella che mostra cPanel e rispondi `yes`):

```powershell
ssh -i "$env:USERPROFILE\.ssh\backup_readonly_ed25519" -p PORTA UTENTE@SERVER list
```

   Un comando diverso (per esempio `ls`) deve rispondere «comando non ammesso»: è la prova che le restrizioni
   funzionano. Falla con lo stesso utente Windows con cui girerà l'attività, perché l'impronta del server si salva nel
   profilo di chi la conferma.

Per **revocare** l'accesso basta togliere quella riga da `authorized_keys`.

## 6. Prepara lo script e l'attività pianificata

1. Copia `Scarica-Backup.ps1` in una cartella **fuori dalla destinazione**, per esempio `C:\AssociazioneSemplice\`.
2. In testa al file (blocco `param`) compila `Server` e `User` (e `Port` se non è 22); controlla `KeyPath`. **`Destination`
   non ha un valore di partenza: va sempre passata** (variante A o B della sezione 2). `Days` è 30 e non può superare 30;
   `MaxAgeHours` è 26. Nel file non va scritto nessun segreto.
3. Provalo a mano (sezione 8).
4. Crea l'attività pianificata, ogni ora al minuto :25 (i dump del server partono al minuto :07).

**Con un comando** (PowerShell, come te stesso; adatta i percorsi, qui la destinazione è un esempio):

```powershell
schtasks /Create /TN "Associazione Semplice - copia backup" /SC HOURLY /MO 1 /ST 00:25 /RU "$env:USERNAME" /IT /RL LIMITED `
  /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"C:\AssociazioneSemplice\Scarica-Backup.ps1\" -Destination \"D:\BackupAssociazioneSemplice\""
```

`/IT` la fa girare solo quando sei collegato (così la notifica di Windows si vede). Il comando non scrive nessuna password.

**Oppure da Utilità di pianificazione** (`taskschd.msc`):

1. «Crea attività…», nome «Associazione Semplice - copia backup».
2. **Generale**: «Esegui solo se l'utente è connesso» (per vedere la notifica) oppure «Esegui indipendentemente dalla
   connessione» (Windows chiede la password dell'account; la notifica non si vede, ma restano `ATTENZIONE.txt`, il log e
   l'esito dell'attività).
3. **Attivazione**, «Nuova…»: giornaliera, inizio alle 00:25, «Ripeti ogni 1 ora per una durata: Indefinitamente».
4. **Azioni**, «Nuova…»: programma `powershell.exe`; argomenti
   `-NoProfile -ExecutionPolicy Bypass -File "C:\AssociazioneSemplice\Scarica-Backup.ps1" -Destination "D:\BackupAssociazioneSemplice"`.
5. **Condizioni**: se è un portatile, togli «Avvia solo se il computer è alimentato a rete».
6. **Impostazioni**: «Esegui l'attività non appena possibile se non è stata avviata all'ora prevista» e «Se l'attività è
   già in esecuzione, non avviare una nuova istanza».

Il PC (e il disco esterno, nella variante B) deve essere acceso. Se resta spento per giorni, alla ripresa lo script
scarica quello che il server ha ancora (le ore perse delle ultime 48 ore e un dump al giorno per 30 giorni) e ti avvisa del ritardo.

### Potatura sospesa: quando la copia resta oltre i 30 giorni

Lo script **pota** (cancella i dump oltre la regola 48 ore + uno al giorno, e oltre 30 giorni) **solo se in quel giro ha
scaricato e verificato (sha256 corretto) almeno un dump nuovo di meno di 26 ore**. Altrimenti non cancella niente e
allarma («potatura SOSPESA»): se il server smette di fare dump, la copia sul PC può essere l'unica rimasta e non va
consumata. In quel caso **la copia resta oltre i 30 giorni finché non decide il titolare**: dopo aver capito perché il
server non produce dump nuovi, o accetti di tenere le copie vecchie, o le cancelli a mano. Se rilanci lo script due volte
nella stessa ora, il secondo giro può non trovare un dump nuovo e dare questo avviso: è normale, passa al giro dopo.

## 7. Solo variante A (OneDrive): cestino, cronologia, account

Quando lo script pota un dump vecchio, OneDrive lo mette nel **cestino online**, dove resta 30 giorni (account personale) o
fino a 93 (account di lavoro o scuola). Un dump potato a 30 giorni può quindi restare recuperabile fino ad altri 30
giorni: in tutto 60. La cronologia delle versioni di un file cancellato segue il cestino. Per tenere la promessa dei 30
giorni:

1. **Svuota il cestino online ogni settimana** (onedrive.live.com, Cestino, «Svuota il cestino»), con un promemoria
   ricorrente: strada consigliata per la variante A. Dopo lo svuotamento il dump non è più recuperabile.
2. Oppure **cambi la frase ai clienti**: le copie possono restare fino a 60 giorni ([CANCELLAZIONE_DATI.md](CANCELLAZIONE_DATI.md) §3 ter). Decide il titolare.

Nella variante B questa sezione non si applica: quando lo script cancella, il file non c'è più (attenzione solo a
cestino di Windows e copie del disco fatte da altri programmi).

## 8. Come provare, leggere il log e capire l'avviso

**Prova a mano** (non cancella nulla sul server; passa la tua destinazione):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\AssociazioneSemplice\Scarica-Backup.ps1" -Destination "D:\BackupAssociazioneSemplice"
echo $LASTEXITCODE
```

Codici di uscita: **0** tutto bene; **1** configurazione o controllo di sicurezza non superato (non parte);
**2** problema passeggero (server non raggiungibile per un'ora, un dump non scaricato); **3** allarme.
Ogni tanto aggiungi `-VerifyAll` per ricontrollare anche i file già presenti (lento).

**Il log** è nella cartella di destinazione, `_log\scarica-backup-AAAA-MM.log` (un file al mese, tenuto 90 giorni), con
orari e nomi di file, mai chiavi o password. Righe `[INFO]` normali, `[AVVISO]` passeggero, `[ALLARME]` da guardare.

### Se compare l'avviso

Notifica di Windows «Backup Associazione Semplice: ATTENZIONE» (al massimo una ogni 4 ore), file `ATTENZIONE.txt` nella
cartella (nella variante A lo vedi anche dal telefono) e esito diverso da 0 dell'attività. Il file sparisce da solo quando
tutto torna a posto.

| Messaggio | Cosa significa e cosa fai |
|---|---|
| «ultimo dump scaricato di N ore fa» | il PC era spento o senza rete, oppure il server non ha fatto il dump. Lancia la prova a mano. Se il server è raggiungibile ma i dump non ci sono, il problema è sul server (`saas:backup:dump`): avvisa chi segue il SaaS. |
| «file SCARTATO, impronta sha256 diversa» | il file scaricato non è identico a quello del server, o è stato alterato. Lo script non lo tiene. Se si ripete a ogni ora per lo stesso file, avvisa chi segue il SaaS. |
| «potatura SOSPESA» | vedi sezione 6. Non è un guasto del PC: è la protezione contro la perdita dell'unica copia. |
| «nessun dump scaricato» | installazione non riuscita: guarda il log, la chiave, il percorso, l'impronta del server. |
| «sembrano chiavi» (codice 1) | nella cartella di destinazione c'è un file con nome da chiave. Spostalo fuori. |

## 9. Senza la chiave privata dei backup le copie non si aprono: provalo

I dump sono cifrati con la chiave pubblica dei backup. **Senza la chiave privata (`SAAS_BACKUP_SECRET_KEY`) le copie non
si aprono**, da nessuna parte: non sul server, non qui. La seconda copia serve a qualcosa solo se la chiave privata è
conservata a parte e funziona. Quindi:

1. Tieni la chiave privata **in un posto diverso** dalla copia (non nella destinazione, non sul PC che scarica).
2. **Fai almeno una volta la prova di apertura** con un dump scaricato da qui: `php artisan saas:backup:restore-test` su una
   macchina di prova, con la chiave privata dei backup in un `.env` locale e un database di prova locale
   ([RILASCIO.md](RILASCIO.md)). Ripetila quando cambi la chiave ([ROTAZIONE_CHIAVI.md](ROTAZIONE_CHIAVI.md)).
3. Lo script, da solo, prova solo che il file è **intero e uguale** a quello del server; non prova che si apra.

## 10. Prove del codice (server finto)

`saas/deploy/backup-onedrive/test/Test-ScaricaBackup.ps1` lancia lo script vero contro un finto `ssh` che inoltra `list` e
`get` al vero `backup-readonly.sh` su cartelle di prova (serve Git for Windows per `sh` e `find` GNU). Prova: scarico e
potatura normali; server morto (nessuna cancellazione e allarme); impronta sbagliata (file scartato); percorsi cattivi dal
server (`../`, assoluti, `CON`, `NUL`, `COM1`, `LPT9`, barre rovesce, lettere di unità: mai usati); file-chiave nella cartella
(non parte). Lo lancia anche la CI su Windows (`.github/workflows/saas-copia-backup.yml`) quando cambia questa cartella.
Il `find -printf` del server vero **non** è coperto: si verifica in sola lettura prima dell'installazione (sezione 5, punto 1).

## 11. Cosa fai tu e cosa non fa nessun altro

Tu:

1. Scegli la destinazione (sezione 2) e dai il «sì» all'accesso nuovo sul server; crei la chiave dedicata.
2. Fai la verifica in sola lettura di `find -printf`, installi lo script sul server e aggiungi la riga (sezione 5).
3. Crei l'attività pianificata (sezione 6) e, per la variante A, scegli come trattare il cestino (sezione 7).
4. Tieni la chiave privata dei backup lontana dalla copia e fai la prova di apertura (sezione 9).

Nessun altro: non si installa nulla sul PC o sul server al posto tuo, non si usa `deploy_key` per questo scopo, non si
tocca il server senza il tuo sì scritto.

## 12. Limiti noti

- L'avviso delle 26 ore vale sempre per il database `shared`. Per i database dedicati delle associazioni scatta solo se
  sul server c'è un dump recente che non siamo riusciti a scaricare; se anche il server non ne fa più (per esempio perché
  l'associazione è stata cancellata) resta una riga `[NOTA]` nel log.
- Chi manomette sia il dump sia il `.json` sul server non verrebbe scoperto dal controllo dell'impronta.
- Con l'attività «anche se non connesso» la notifica non si vede: restano file, log ed esito dell'attività.
- La notifica è un fumetto di Windows e può sparire con «Non disturbare».
- Un dump grande scaricato ogni ora occupa banda: i dump già presenti non si riscaricano.

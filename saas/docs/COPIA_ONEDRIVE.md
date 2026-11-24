# Copia dei backup anche su OneDrive

Decisione del titolare del 24/11/2026. Ogni ora il tuo PC scarica dal server i dump dei backup (già cifrati) e li mette in
una cartella OneDrive. È una **seconda copia**, fuori dal fornitore del server. Questa guida dice cosa prepara il codice e
cosa fai tu, passo per passo. **Nulla è installato: ogni passo qui sotto lo fai tu, quando decidi.**

File nel repository (cartella `saas/deploy/backup-onedrive/`):

| File | A cosa serve |
|---|---|
| `Scarica-Backup.ps1` | lo script che gira sul PC (Windows PowerShell 5.1) |
| `backup-readonly.sh` | il piccolo script che sta sul server e permette solo di leggere i dump |

## 1. Come funziona, in breve

- Il PC si collega al server con una **chiave SSH dedicata**, diversa da quella dei rilasci (`deploy_key`).
- Quella chiave, sul server, può fare **solo due cose**: «elenca i dump» e «leggi questo dump». Non ha una shell, non può
  scrivere, cancellare o rinominare. Lo garantisce una riga in `~/.ssh/authorized_keys` che costringe a usare
  `backup-readonly.sh`.
- Lo script controlla ogni file scaricato: calcola l'impronta sha256 e la confronta con quella scritta nel `.json`
  accanto. Se non coincide il file **viene scartato** e compare l'avviso.
- Sul PC tiene i dump delle ultime 48 ore e poi uno al giorno, **mai oltre 30 giorni** (la scadenza promessa ai clienti).
- Se l'ultimo dump scaricato ha più di **26 ore**, compare l'avviso (notifica di Windows, file `ATTENZIONE.txt` nella
  cartella, riga nel log, codice di uscita diverso da 0).
- La chiave che apre i dump (`SAAS_BACKUP_SECRET_KEY`, la chiave privata dei backup) **non va mai** nella cartella OneDrive
  e nemmeno sul PC insieme ai dump. Lo script si rifiuta di partire se trova nella cartella file che sembrano chiavi.

Perché non `rrsync`, `scp` o `sftp`: su Windows non c'è `rsync`; su un hosting cPanel condiviso non si può usare
`internal-sftp` con `ChrootDirectory`; `scp`/`sftp` da soli non limitano bene cosa si legge. Un comando forzato con due soli
verbi (`list`, `get`) e un controllo rigido del percorso è la soluzione più corta da verificare.

## 2. Prima di cominciare

1. Windows 10/11 con **Client OpenSSH** (di serie; se manca: Impostazioni, App, Funzionalità facoltative).
2. OneDrive attivo sul PC. Scegli la cartella, per esempio `C:\Users\<tu>\OneDrive\Backup Associazione Semplice`.
3. Sull'account Microsoft: **verifica in due passaggi accesa** e nessuna condivisione di quella cartella.
   (Il «Personal Vault» di OneDrive non va bene: da bloccato lo script non può scrivere.)
4. Decidi cosa fare del **cestino di OneDrive** (sezione 7): è il punto che può allungare la conservazione oltre 30 giorni.

## 3. Crea la chiave dedicata (sul tuo PC, fuori da OneDrive)

In PowerShell, come utente normale. La chiave resta in `.ssh` del tuo profilo, che non è sincronizzato con OneDrive
(controlla che `C:\Users\<tu>\.ssh` non sia dentro la cartella OneDrive). Se la cartella del profilo è
spostata dentro OneDrive, scegli un'altra cartella fuori da OneDrive.

```powershell
New-Item -ItemType Directory -Force "$env:USERPROFILE\.ssh" | Out-Null
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\backup_readonly_ed25519" -C "backup-onedrive-sola-lettura"
```

Quando chiede la passphrase: per un'attività automatica ogni ora la lasci vuota (il file resta leggibile solo da te grazie
ai permessi del punto sotto, e la chiave sul server ha comunque solo il permesso di lettura).

Restringi chi può leggere il file (solo il tuo utente):

```powershell
$k = "$env:USERPROFILE\.ssh\backup_readonly_ed25519"
icacls $k /inheritance:r
icacls $k /grant:r "$($env:USERNAME):(R)"
```

Il file `backup_readonly_ed25519.pub` (la parte **pubblica**) è quella che va sul server; la privata non esce dal PC e non va
mai incollata in chat o nel repository.

## 4. Metti la chiave sul server (accesso nuovo: solo dopo il tuo sì)

Questo è un accesso nuovo al server: **lo si fa solo dopo che hai detto sì per iscritto**. Nessuno lo ha fatto.

1. Copia `backup-readonly.sh` sul server in `~/saas/backup-readonly.sh` (fuori da `releases/`, così i rilasci non lo
   toccano) e imposta i permessi: `chmod 700 ~/saas/backup-readonly.sh`.
2. Apri `~/.ssh/authorized_keys` **senza toccare le righe esistenti** (c'è quella dei rilasci) e **aggiungi in fondo una riga**:

```
restrict,command="/home/ACCOUNT/saas/backup-readonly.sh" ssh-ed25519 AAAA...contenuto-del-file-.pub... backup-onedrive-sola-lettura
```

   - `ACCOUNT` è il nome dell'account cPanel (lo stesso della cartella `/home/...` dei rilasci).
   - `restrict` spegne terminale, inoltro delle porte e agente; `command=` costringe la chiave a usare solo lo script.
   - Se l'indirizzo IP di casa o dell'ufficio è fisso puoi aggiungere `from="203.0.113.7"` davanti (`restrict,from="…",command="…"`):
     è una difesa in più. Se l'IP cambia, non metterlo, o l'accesso smette di funzionare.
   - Se usi l'impostazione «SSH Access» di cPanel, la riga può essere aggiunta anche da lì; poi modificala nel file per
     aggiungere `restrict,command=...` davanti, perché cPanel da solo non mette restrizioni.
3. Prova dal PC (comando di sola lettura, deve elencare i dump; **la prima volta** chiede di confermare l'impronta del
   server: confrontala con quella che mostra cPanel e rispondi `yes`):

```powershell
ssh -i "$env:USERPROFILE\.ssh\backup_readonly_ed25519" -p PORTA UTENTE@SERVER list
```

   Un comando qualsiasi diverso (per esempio `ls`) deve rispondere «comando non ammesso»: è la prova che le restrizioni
   funzionano. Esegui la prova con lo stesso utente Windows con cui girerà l'attività pianificata, perché l'impronta del
   server si salva nel profilo di chi la conferma.

Per **revocare** l'accesso basta togliere quella riga da `authorized_keys`.

## 5. Prepara lo script sul PC

1. Copia `Scarica-Backup.ps1` in una cartella **fuori da OneDrive**, per esempio `C:\AssociazioneSemplice\`.
2. In testa al file (blocco `param`) sono già scritti i valori di partenza. Compila `Server`, `User` e, se serve, `Port`;
   controlla `KeyPath` e `Destination`. Altrimenti passali come parametri nel comando dell'attività. `Days` è 30 e non può
   superare 30; `MaxAgeHours` è 26. **Nel file non va scritto nessun segreto**: ci sono solo nomi e percorsi.
3. Prova a mano (sezione 7 sotto, «Prova a mano»).

## 6. L'attività pianificata (la crei tu)

Ogni ora al minuto :25 (i dump del server partono al minuto :07, così il nuovo dump c'è già).

**Con un comando** (PowerShell, come te stesso; adatta il percorso dello script):

```powershell
schtasks /Create /TN "Associazione Semplice - copia backup" /SC HOURLY /MO 1 /ST 00:25 /RU "$env:USERNAME" /IT /RL LIMITED `
  /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"C:\AssociazioneSemplice\Scarica-Backup.ps1\""
```

`/IT` la fa girare solo quando sei collegato (così la notifica di Windows si vede). Il comando non scrive nessuna password.

**Oppure da Utilità di pianificazione** (`taskschd.msc`):

1. Azione, «Crea attività…». Nome: «Associazione Semplice - copia backup».
2. Scheda **Generale**: «Esegui solo se l'utente è connesso» (per avere la notifica) oppure «Esegui indipendentemente
   dalla connessione» (Windows ti chiede la password dell'account; la notifica allora non si vede, ma restano
   `ATTENZIONE.txt`, il log e l'esito dell'attività).
3. Scheda **Attivazione**, «Nuova…»: giornaliera, inizio alle 00:25, «Ripeti ogni 1 ora per una durata: Indefinitamente».
4. Scheda **Azioni**, «Nuova…»: programma `powershell.exe`; argomenti
   `-NoProfile -ExecutionPolicy Bypass -File "C:\AssociazioneSemplice\Scarica-Backup.ps1"`.
5. Scheda **Condizioni**: togli la spunta a «Avvia solo se il computer è alimentato a rete» se il PC è un portatile;
   lascia attiva la richiesta di rete.
6. Scheda **Impostazioni**: spunta «Esegui l'attività non appena possibile se non è stata avviata all'ora prevista» e
   «Se l'attività è già in esecuzione, non avviare una nuova istanza».

Il PC deve essere acceso e collegato a Internet. Se resta spento per giorni, alla ripresa lo script scarica quello che il
server ha ancora (le ore perse delle ultime 48 ore e un dump al giorno per 30 giorni) e ti avvisa che la copia era in ritardo.

## 7. Come provare, leggere il log e capire l'avviso

**Prova a mano** (la prima volta mostra l'avanzamento a schermo; non cancella nulla sul server):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\AssociazioneSemplice\Scarica-Backup.ps1"
echo $LASTEXITCODE
```

Il codice di uscita: **0** tutto bene; **1** configurazione o controllo di sicurezza non superato (lo script non parte);
**2** problema passeggero (server non raggiungibile un'ora, un dump non scaricato); **3** allarme.
Ogni tanto, per ricontrollare anche i file già presenti, aggiungi `-VerifyAll` (è lento).

**Il log** è nella cartella di destinazione, `_log\scarica-backup-AAAA-MM.log` (un file al mese, tenuto 90 giorni). Ha
orari e nomi di file, mai chiavi o password. Righe `[INFO]` normali, `[AVVISO]` passeggero, `[ALLARME]` da guardare.

### Se compare l'avviso

L'avviso è: notifica di Windows «Backup Associazione Semplice: ATTENZIONE» (al massimo una ogni 4 ore), il file
`ATTENZIONE.txt` nella cartella OneDrive (lo vedi anche dal telefono con l'app OneDrive) e l'esito diverso da 0
dell'attività. Il file sparisce da solo quando tutto torna a posto. Cosa può essere:

| Messaggio | Cosa significa e cosa fai |
|---|---|
| «ultimo dump scaricato di N ore fa» | il PC era spento o senza rete, oppure il server non ha fatto il dump. Lancia la prova a mano. Se il server è raggiungibile ma i dump non ci sono, il problema è sul server (comando `saas:backup:dump`): avvisa chi segue il SaaS. |
| «file SCARTATO, impronta sha256 diversa» | il file scaricato non è identico a quello del server (rete) oppure è stato alterato. Lo script non lo tiene. Se si ripete a ogni ora per lo stesso file, avvisa chi segue il SaaS. |
| «nessun dump scaricato» | prima installazione non riuscita: guarda il log, la chiave, il percorso, l'impronta del server. |
| «sembrano chiavi» (codice 1) | nella cartella OneDrive c'è un file con nome da chiave. Spostalo fuori da OneDrive. |

### Il cestino e la cronologia di OneDrive

Quando lo script pota un dump vecchio, OneDrive lo mette nel **cestino online**, dove resta 30 giorni (account personale)
o fino a 93 (account di lavoro o scuola). Quindi un dump potato a 30 giorni può restare recuperabile fino a **altri 30
giorni**: in tutto 60. La cronologia delle versioni segue lo stesso destino (le versioni di un file cancellato vanno
nel cestino con lui). Per tenere la promessa dei 30 giorni scegli una di queste strade:

1. **Svuoti il cestino online ogni settimana** (onedrive.live.com, Cestino, «Svuota il cestino»): metti un promemoria
   ricorrente. È la strada consigliata. Se un dump è già nel cestino, dopo lo svuotamento non è recuperabile.
2. Oppure **cambi la frase ai clienti**, dicendo che le copie di sicurezza possono restare fino a 60 giorni (vedi
   [CANCELLAZIONE_DATI.md](CANCELLAZIONE_DATI.md) §3 ter). Decide il titolare.

In entrambi i casi le copie sono cifrate e la chiave per aprirle non sta su OneDrive; resta il fatto che i nomi, le email
e la contabilità, non cifrati per campo, si aprono con la chiave dei backup (§3 bis di quel documento).

## 8. Cosa fai tu e cosa non fa nessun altro

Tu:

1. Decidi il «sì» all'accesso nuovo sul server (sezione 4) e crei la chiave dedicata sul tuo PC.
2. Crei l'attività pianificata (sezione 6).
3. Scegli come trattare il cestino di OneDrive (sezione 7).
4. Tieni la chiave privata dei backup **fuori** dal PC che scarica e fuori da OneDrive (come oggi nel posto sicuro).

Nessun altro: non si installa nulla sul PC o sul server al posto tuo, non si usa `deploy_key` per questo scopo, non si
tocca il server senza il tuo sì scritto.

## 9. Limiti noti

- Lo script verifica che il file sia **intero e identico** a quello del server (impronta del `.json`), non che il dump
  si apra: la prova di ripristino resta `saas:backup:restore-test` (settimanale, solo sullo staging). Chi manomette sia il
  dump sia il `.json` sul server non verrebbe scoperto da questo controllo.
- L'avviso delle 26 ore vale sempre per il database `shared`. Per i database dedicati delle associazioni scatta solo se
  sul server c'è un dump recente che non siamo riusciti a scaricare; se anche il server non ne fa più (per esempio perché
  l'associazione è stata cancellata) resta una riga `[NOTA]` nel log.
- Con l'attività impostata «anche se non connesso» la notifica non si vede: restano file, log ed esito dell'attività.
- La notifica è un fumetto di Windows e può sparire se hai attivato «Non disturbare».
- Il comando `list` usa `find -printf` (presente nei server Linux di cPanel).
- Un dump grande scaricato ogni ora occupa banda: i dump già presenti non si riscaricano.

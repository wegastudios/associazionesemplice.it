# Posta del gestionale: servizio di invio Emailit

Il gestionale può mandare la posta con Emailit (SMTP) e tenere il server di posta di sempre (cPanel) come ripiego.
Il codice è già pronto ma **spento**: finché non si fanno i passi qui sotto, la posta parte come oggi.
La posta SMTP è già attiva in produzione (24/11/2026, letto dal .env di produzione); lo stato di Emailit in particolare non è verificato qui.

Nessun valore segreto in questo documento né nel repository: la chiave si scrive solo sul server.

## Come funziona, acceso

- La posta parte da Emailit entro i tetti del gestionale (`config/saas.php`, `mail_queue.mailers.emailit`, cambiabili
  dal file d'ambiente): 2 al secondo, 900 all'ora, 4.000 al giorno (di cui 3.500 massivi), 900.000 al mese (il 90% del
  milione di crediti del piano). Il giorno è quello UTC: il conteggio si azzera a mezzanotte UTC.
- Il workspace Emailit è condiviso: al giorno gestionale 4.000, portale 500, supporto 200, margine 300.
- I conteggi stanno in Redis e, per ripartire se Redis si svuota, anche nella tabella `core.mail_counters` (giorno, ora,
  mailer, inviati, di cui massivi, errori, ripieghi): dopo uno svuotamento ripartono da lì sia il totale sia i massivi.
- Troppi al secondo (`452 4.4.5`): il messaggio riprova dopo 2 secondi.
- Quota del giorno finita (`452 4.5.3`, o il conteggio del gestionale): fino alla mezzanotte UTC i messaggi urgenti
  (link e codici) passano a cPanel entro i suoi 140 all'ora; gli avvisi e i promemoria aspettano la mezzanotte.
- Errori del servizio (connessione, chiave rifiutata, 5xx): il messaggio torna in coda. Al terzo errore di fila Emailit
  si spegne per 15 minuti e tutta la posta passa a cPanel entro i suoi tetti (prima gli urgenti, i massivi al massimo 100
  all'ora); poi un solo messaggio prova Emailit e, se parte, lo riaccende.
- `saas:health` segnala: Emailit spento per errori (problema); oltre il 90% della quota del giorno (avviso); ripiego su
  cPanel da più di un'ora (avviso); `SAAS_MAIL_PRIMARY=emailit` senza chiave (problema di configurazione).
- Nei log solo il nome del servizio e il codice SMTP: mai la chiave, mai gli indirizzi.

La chiave non si imposta da `/admin/piattaforma`: lì si cambia solo il server di posta di sempre, e la chiave di Emailit
non deve stare nel database.

## Passi per accenderlo

1. **Dominio su Emailit.** Nel pannello Emailit aggiungere il dominio del mittente (lo stesso di `MAIL_FROM_ADDRESS`) e
   avviare la verifica.
2. **Record DNS.** Aggiungere solo i record che il pannello Emailit mostra per quel dominio (di solito MX e TXT/SPF su un
   sottodominio di ritorno, e il DKIM con un nome proprio di Emailit). **Non toccare** i record che ci sono già: lo SPF
   della radice del dominio, il DKIM `default`, gli MX della radice e il DMARC servono alla posta di sempre.
3. **Verifica.** Aspettare che il pannello segni il dominio come verificato.
4. **Chiave.** Creare una chiave API di tipo **«Sending Only»** limitata a quel dominio. La chiave si vede una volta sola:
   copiarla direttamente nel passo successivo, senza salvarla altrove.
5. **File d'ambiente sul server.** Solo in `~/saas/shared/.env` (mai nel repository, mai in chat):

   ```
   EMAILIT_PASSWORD=<la chiave>
   ```

   Gli altri valori hanno già quelli giusti: server `smtp.emailit.com`, porta 465 con TLS implicito, utente `emailit`.
   La connessione è sempre cifrata: con `EMAILIT_PORT=587` lo STARTTLS è obbligatorio e, se il server non lo offre,
   l'invio fallisce invece di mandare la chiave in chiaro. Il mittente resta `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`.
   `SAAS_MAIL_PRIMARY` resta `cpanel` per ora.
6. **Rileggere la configurazione:** `php artisan optimize` nella cartella della versione in uso.
7. **Prova d'invio** con Emailit, ancora spento per tutti gli altri messaggi:

   ```
   php artisan tinker --execute='Mail::mailer("emailit")->raw("Prova Emailit", fn ($m) => $m->to("<indirizzo di prova>")->subject("Prova Emailit"));'
   ```

   Controllare che il messaggio arrivi, che non finisca nello spam e che nelle intestazioni SPF, DKIM e DMARC risultino
   «pass».
8. **Solo dopo la prova riuscita:** in `~/saas/shared/.env` scrivere `SAAS_MAIL_PRIMARY=emailit`, poi di nuovo
   `php artisan optimize`. Da qui la posta parte da Emailit.
9. **Controllo:** `php artisan saas:health` deve dare `ok` su «servizio di invio» e «configurazione di produzione».

Per spegnerlo: `SAAS_MAIL_PRIMARY=cpanel` e `php artisan optimize`. La posta torna subito tutta su cPanel.

## Da controllare con Emailit

- **Limite mensile reale del piano:** leggerlo nel pannello. Se è diverso da un milione di crediti, impostare
  `SAAS_MAIL_EMAILIT_PER_MONTH` al 90% del valore letto.
- **Limiti di velocità:** chiedere a support@emailit.com l'aumento dei limiti al secondo e al giorno del workspace, se
  servono. Con un limite al secondo più alto si può alzare `SAAS_MAIL_EMAILIT_PER_SECOND` (oggi 2); se Emailit rifiuta
  spesso per velocità (`452 4.4.5` nei log), abbassarlo a 1.
- I tetti del giorno di portale e supporto si impostano nei loro progetti: la somma con il gestionale non deve superare
  il limite del workspace.

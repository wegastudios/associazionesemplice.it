# Guide di supporto (part1–part7)
File letti: 7 · Proposte: 125 (Alta 5, Media 78, Bassa 42)

Nota: nei sette file non c'è nessuna guida con `'topic' => 'gestionale'`, quindi sono state riviste tutte (55 guide e domande frequenti). La prima domanda frequente di `part6.php` (riga 8) prende il testo da `confronto.md`, che non fa parte di questo lotto. In «Ora» il segno […] indica un taglio dentro lo stesso capoverso; il resto è citato alla lettera.

## Difetti ricorrenti

- **Note di rilascio rimaste nelle guide.** Molte frasi raccontano cosa è cambiato («non … più», «come prima», «resta com'era», «prima di questo aggiornamento», la sezione «Aggiornamento») invece di dire come funziona oggi. Chi legge non sa com'era prima e non sa di quale aggiornamento si parla. Regola: la guida descrive solo lo stato attuale; le novità stanno nel changelog. Punti: part1 85, 145; part2 51, 53, 83, 313, 332-334; part3 44, 167, 250, 257, 286; part4 90, 313; part5 207; part7 160, 198.
- **Messaggi a schermo citati per intero.** Interi capoversi riportano parola per parola avvisi e conferme (a volte in due varianti). La guida si allunga e va riscritta a ogni ritocco del messaggio. Regola: citare alla lettera solo il nome del pulsante o del riquadro e, al massimo, un messaggio per guida; per il resto dire cosa legge l'utente e cosa deve fare. Punti: part2 71, 299, 309; part3 292, 316, 330, 332, 343; part4 283, 315, 322.
- **Guide troppo lunghe, con capoversi a muro.** «creare-un-corso» (115 righe), «pagina-pubblica-degli-eventi-sul-sito» (70), «prenotarsi-a-un-evento-o-a-un-corso-dal-sito» (55), «ricevute-in-pdf» (60), «copia-di-sicurezza-e-ripristino» (40) non si scorrono in un minuto. Regola: una guida, un compito. Senza toccare slug e link esistenti, i sotto-argomenti autonomi possono diventare guide nuove collegate con una riga: varianti del contributo; annullare o spostare una lezione; quando un corso finisce prima; limiti alle richieste dal sito; importare da The Events Calendar e pubblicare le bozze; verifica delle ricevute.
- **Lo stesso testo in più guide.** Blocchi identici o quasi: durata della tessera e cambio del mese (part2 59-63 = part6 157-161); «gratuito e Pro vanno aggiornati insieme» (part1 123, part2 334, part6 68, part7 63); email facoltativa (part2 47, 278; part6 170); password dopo il ripristino (part1 168, part2 309); blocco dopo 5 password (part1 147, 195); rimborsi su Stripe (part4 184, 274); dicitura «Emesso il gg/mm/aaaa…» (part4 90, part5 58); cosa invia il plugin (part1 216, part6 109-110, part7 68); chiave gratuita (part1 204, part6 75-77); cookie e informativa (part2 330, part5 278). Regola: il testo completo sta in una guida sola, le altre hanno una frase e il link.
- **Stesso concetto, nomi diversi.** Regola: un nome solo, quello del glossario.
  - Account sul portale: «area riservata», «area soci» (part1 278; part6 59, 141, 253), «area clienti» (part1 121; part7 68). Usare sempre «area riservata»; «area soci» è solo la pagina dei soci sul sito dell'associazione.
  - Pagina dei soci: «area soci», ma la sezione delle guide si chiama «Area riservata» (part2 221, 251, 286) e part6 20 dice «Area riservata per i soci».
  - Chi fa le cose: «il plugin», «il programma» (part1 157; part2 71; part4 86, 311, 315), «il sistema» (part4 11, 266), «il sito», «il pannello» (part2 161; part3 254). Usare «il plugin» e, per le pagine di gestione, «l'amministrazione».
  - Fornitori di pagamento: «gateway» (part4 172, 227, 243, 247; part5 30), «fornitore», «metodo». Usare «fornitore di pagamento».
  - Valore iniziale: «di default» (part2 170, 204; part3 219, 290; part4 130; part5 101, 316; part6 112, 121) e «di partenza» (altrove). Usare «di partenza».
  - «collegamento» (part3 24, 240, 278, 315; part7 23) e «link» (altrove): il glossario sceglie «link».
  - «quota» per corsi ed eventi (part3 51, 75-81, 117, 255; part4 283): il glossario riserva «quota» alla quota associativa, qui è «contributo». Lo stesso per «biglietto» (part2 150; part3 424) e «tariffa» per il listino (part3 181, 379).
  - «cancellare» dove si intende eliminare o annullare (part3 51, 157, 163; part5 166); «rendere» accanto a «restituire» (part3 97-100); «stornare» (part4 184, 274) per «annullare»; «coda» accanto a «lista d'attesa» (part3 178, 181, 183, 343); «tetto» accanto a «limite» (part3 322, 330); «potere» per «ruolo» (part2 53, 313, 324); «scheda persona», «scheda della persona», «scheda del socio».
- **Grassetto usato per dare enfasi.** In molte guide recenti il grassetto cade su parole qualunque («**tornano**», «**entrare di nuovo**», «**non si accorciano mai**», «**una sola schermata**») e i nomi di menu e pulsanti non risaltano più. Regola: grassetto solo per menu, pulsanti, riquadri e per l'unico avvertimento importante della sezione. Punti più carichi: part1 136-168; part2 53-63, 293-313; part3 286-294, 315-336; part4 311-332.
- **Gergo tecnico.** «database» (part1 24; part6 121), «normalizzato», «schema, porta e percorso» (part1 302), «tipi di contenuto di appoggio» (part2 35), «44 px» (part2 238), «indirizzo IP» (part2 266), «campo trappola», «gettone firmato» (part3 357), «in background» (part3 475), «dal tuo server non parte nessuna richiesta» (part4 140), «scorporo» (part5 92), «migrazione» (part5 166), «wp-admin» (part5 207; part7 11). Dove il termine serve a chi configura il sito (webhook, shortcode, `wp-config.php`, certificati dei wallet) va bene lasciarlo.
- **Guide che partono senza una frase di apertura.** Dopo «Disponibile in» si entra subito in un titolo di sezione: part1 231, 264; part2 45, 256, 291; part3 176, 203; part4 40, 111; part5 300. Regola: una riga che dica a cosa serve la guida.
- **Elenchi numerati che non sono passi.** Punti numerati con condizioni o descrizioni al posto di azioni (part1 46-54; part4 78-80, 179-182; part5 139-141; part3 383-385). Regola: i numeri solo per le azioni, una per riga; il resto in elenco puntato o in una frase sotto.

## Proposte

### supporto/database/guides/part1.php:24 · Bassa · guida «installare-e-attivare-il-plugin»
- Ora: «I dati restano nel database del sito anche se disattivi il plugin.»
- Proposta: «I dati restano sul sito anche se disattivi il plugin.»
- Perché: «database» è gergo e non aggiunge nulla.

### supporto/database/guides/part1.php:46 · Media · guida «completare-la-configurazione-guidata»
- Ora: «Chi ha già configurato non cambia nulla finché non riapre la procedura guidata. Al **primo passo** scegli subito i **due mesi**: «Da che mese iniziano corsi ed eventi (anno delle attività)?» (solo il calendario di corsi ed eventi) e «Da che mese inizia il tuo anno fiscale (bilancio e rendiconto)?» (l'anno dei conti, ma anche della **tessera**, delle quote e degli incassi), poi metti la spunta **«Ho controllato i due mesi»**. Sono precompilati con gennaio: se va bene, basta la spunta. I due mesi sono indipendenti. »
- Proposta: spezzare il punto 1 in tre righe sotto il titolo «Il tuo ente»: «**Dati dell'ente**, obbligatori (vedi sotto).» · «**Chi partecipa**: come chiamarlo (socio, tesserato…), se accetti ospiti e chi può iscriversi. Con **«Chiunque può chiedere di iscriversi»** si accende la pagina **«Diventa socio»**; con **«Solo su presentazione»** si spegne.» · «**I due mesi**: da che mese iniziano corsi ed eventi (anno delle attività) e da che mese inizia l'anno fiscale, che vale per conti, tessera, quote e incassi. Sono indipendenti e partono da gennaio: se va bene, metti la spunta **«Ho controllato i due mesi»**.»
- Perché: oggi il primo punto è un capoverso di 130 parole con quattro argomenti; la frase su «chi ha già configurato» è una nota di rilascio e c'è uno spazio in fondo alla riga.

### supporto/database/guides/part1.php:48 · Media · guida «completare-la-configurazione-guidata»
- Ora: «I dati obbligatori sono: la **denominazione**; la **forma dell'ente** («ASD – associazione sportiva dilettantistica», «APS – associazione di promozione sociale», «ODV – organizzazione di volontariato», «ETS – altro ente del Terzo settore», «Circolo», «Altro»); il **codice fiscale**; la **sede legale** (indirizzo, CAP, comune, provincia); l'**email** e il **telefono della segreteria**.»
- Proposta: «I dati obbligatori sono: **denominazione**, **forma dell'ente** (ASD, APS, ODV, ETS, circolo o altro), **codice fiscale**, **sede legale**, **email** e **telefono della segreteria**.»
- Perché: l'elenco delle voci del menu è già a schermo e c'è una guida dedicata, collegata nella stessa riga.

### supporto/database/guides/part1.php:56 · Bassa · guida «completare-la-configurazione-guidata»
- Ora: «Le chiavi dei fornitori di pagamento e gli IBAN non si inseriscono qui: alla fine ti porta nella pagina giusta per farlo in sicurezza.»
- Proposta: «Le chiavi dei fornitori di pagamento e gli IBAN non si inseriscono qui: alla fine la procedura ti porta nella pagina giusta.»
- Perché: manca il soggetto di «ti porta».

### supporto/database/guides/part1.php:78 · Media · guida «creare-le-pagine-del-sito»
- Ora: «**Segreteria**: richieste di accesso da evadere e collegamenti alla gestione, per segreteria, presidente e vicepresidente.»
- Proposta: «**Segreteria**: le richieste di accesso a cui rispondere e i link alla gestione, per segreteria, presidente e vicepresidente.»
- Perché: «evadere» è burocratese (il glossario lo segnala già nel plugin).

### supporto/database/guides/part1.php:85 · Media · guida «creare-le-pagine-del-sito»
- Ora: «Gli elenchi pubblici di attività ed eventi e i posti liberi non restano più «vecchi» nella cache delle pagine (per esempio con LiteSpeed). Se hai pagine in cache, **svuota la cache una volta** dal plugin di cache dopo aver aggiornato il plugin.»
- Proposta: «Elenchi di attività, eventi e posti liberi restano aggiornati anche con un plugin di cache (per esempio LiteSpeed). Dopo aver aggiornato Associazione Semplice **svuota la cache una volta**.»
- Perché: «non restano più» è una nota di rilascio; «plugin» indica due cose diverse nella stessa frase.

### supporto/database/guides/part1.php:121 · Media · guida «installare-associazione-semplice-pro» (e part7.php:68, guida «leggere-le-novita-di-ogni-versione»)
- Ora: «Altrimenti l'avviso ti porta a scaricare il pacchetto dall'area clienti, da caricare in **Plugin → Aggiungi nuovo**: i dati restano.»
- Proposta: «Altrimenti l'avviso ti porta a scaricare il file dall'area riservata, da caricare in **Plugin → Aggiungi nuovo**: i dati restano.»
- Perché: l'account sul portale si chiama «area riservata» ovunque; «area clienti» compare solo qui e in part7:68 («si scaricano dall'area clienti»).

### supporto/database/guides/part1.php:143 · Bassa · guida «copia-di-sicurezza-e-ripristino»
- Ora: «Conservalo in un posto sicuro, fuori dal sito.»
- Proposta: «Conserva il file in un posto sicuro, fuori dal sito.»
- Perché: «lo» non ha un nome a cui riferirsi (prima si parla de «la copia»).

### supporto/database/guides/part1.php:145 · Media · guida «copia-di-sicurezza-e-ripristino»
- Ora: «La password serve **anche per scaricare le copie salvate prima dei ripristini** (le trovi sotto «Copie di sicurezza fatte prima dei ripristini»): scrivi la password e premi il nome della copia. Un vecchio indirizzo diretto di scarico non scarica più nulla: ti riporta alla pagina, dove scrivi la password.»
- Proposta: «La password serve anche per scaricare le copie salvate prima dei ripristini: sotto **«Copie di sicurezza fatte prima dei ripristini»** scrivi la password e premi il nome della copia.»
- Perché: l'ultima frase è una nota di rilascio che interessa solo chi aveva salvato un vecchio indirizzo.

### supporto/database/guides/part1.php:147 · Media · guida «copia-di-sicurezza-e-ripristino»
- Ora: «**Dopo 5 password sbagliate in 15 minuti** il plugin si ferma per qualche minuto e non controlla nemmeno più la password: compare «Troppi tentativi: riprova tra qualche minuto.» Il blocco vale **insieme per scarico, ripristino e azzeramento dei dati**; con la password giusta il conteggio riparte da zero.»
- Proposta: «**Dopo 5 password sbagliate in 15 minuti** compare «Troppi tentativi: riprova tra qualche minuto.»: per qualche minuto non passa nemmeno la password giusta. Il conteggio è unico per scarico, ripristino e azzeramento dei dati, e riparte da zero quando entri con la password giusta.»
- Perché: «il plugin si ferma» fa pensare che si blocchi tutto il plugin.

### supporto/database/guides/part1.php:162 · Media · guida «copia-di-sicurezza-e-ripristino»
- Ora: «**Dopo un cambio dei codici di sicurezza**, le copie fatte prima chiedono la conferma per altro sito.»
- Proposta: «Se sono cambiati i **codici di sicurezza di WordPress**, le copie fatte prima si ripristinano come quelle di un altro sito: scrivi **RIPRISTINA DA ALTRO SITO**.»
- Perché: non si capisce quali codici né cosa fare; è più lunga perché manca l'azione.

### supporto/database/guides/part1.php:166 · Bassa · guida «copia-di-sicurezza-e-ripristino»
- Ora: «- Gli utenti di WordPress non fanno parte della copia.»
- Proposta: togliere la riga.
- Perché: la riga 167 lo ripete («Non si ripristinano: […] e gli **utenti WordPress**»).

### supporto/database/guides/part1.php:168 · Media · guida «copia-di-sicurezza-e-ripristino»
- Ora: «**Le password dei soci tornano.** La copia contiene le **impronte** delle password (**mai le password in chiaro**) e il ripristino le rimette: ogni socio entra con la **password di prima** e deve **scegliere subito una nuova password**, anche se entra con il link ricevuto per email. Vale solo per chi aveva una password.»
- Proposta: «**Le password dei soci tornano.** La copia non contiene le password in chiaro, ma il ripristino le rimette: chi aveva una password entra con quella di prima e deve sceglierne subito una nuova, anche se entra con il link ricevuto per email.»
- Perché: cinque grassetti in tre righe e «impronte» senza spiegazione.

### supporto/database/guides/part1.php:182 · Alta · guida «azzerare-i-dati-e-ripartire»
- Ora: «Prima di azzerare devi **scaricare una copia completa con gli allegati** (file di scontrini, fatture e altri documenti; vale poco tempo), scrivendo la tua password di amministratore: senza lo scarico l'azzeramento non parte. Conservala tu.»
- Proposta: «Prima di azzerare devi **scaricare una copia completa con gli allegati** (scontrini, fatture e altri documenti), scrivendo la tua password di amministratore: senza lo scarico l'azzeramento non parte. Lo scarico vale per poco tempo: se aspetti troppo va rifatto. Conserva tu la copia.»
- Perché: «vale poco tempo» dentro la parentesi non si capisce a cosa si riferisca (da confermare che sia lo scarico); è l'unica copia dei dati prima di un'operazione che non si annulla.

### supporto/database/guides/part1.php:195 · Media · guida «azzerare-i-dati-e-ripartire»
- Ora: «Anche qui, **dopo 5 password sbagliate in 15 minuti** compare «Troppi tentativi: riprova tra qualche minuto.» e per un po' la password non si controlla (il blocco è lo stesso del ripristino e dello scarico della copia).»
- Proposta: «**Dopo 5 password sbagliate in 15 minuti** compare «Troppi tentativi: riprova tra qualche minuto.»: aspetta qualche minuto. Il conteggio è lo stesso dello scarico e del ripristino della copia.»
- Perché: «la password non si controlla» è il punto di vista del programma; all'utente serve sapere che deve aspettare.

### supporto/database/guides/part1.php:204 · Media · guida «richiedere-la-licenza-gratuita» (e part6.php:77, domanda «serve-la-licenza-per-il-plugin-gratuito»)
- Ora: «Crea un account che dà accesso al supporto e alle demo dei piani Pro.»
- Proposta: «Chiedendola crei anche un account, con cui accedi al supporto e alle demo dei piani Pro.»
- Perché: il soggetto di «Crea» non si capisce (la chiave? tu, all'imperativo?).

### supporto/database/guides/part1.php:222 · Media · guida «richiedere-la-licenza-gratuita»
- Ora: «La chiave gratuita si **rinnova da sola** poco prima della scadenza, finché la usi: serve almeno un accesso recente, cioè una verifica dal plugin o il tuo ingresso nell'area riservata. Se per un po' non usi il plugin né entri nell'area riservata, la chiave scade. Il plugin gratuito continua a funzionare anche senza chiave.»
- Proposta: «La chiave gratuita si **rinnova da sola** poco prima della scadenza, se di recente il plugin l'ha verificata o sei entrato nell'area riservata. Altrimenti scade, ma il plugin gratuito continua a funzionare.»
- Perché: la stessa condizione è detta due volte, in positivo e in negativo.

### supporto/database/guides/part1.php:278 · Alta · guida «passare-a-pro-e-rinnovare» (e part6.php:59, 141, 253)
- Ora: «Dall'area soci puoi rinnovare, cambiare piano, aggiornare i dati di fatturazione o disdire il rinnovo in autonomia.»
- Proposta: «Dall'area riservata puoi rinnovare, cambiare piano, aggiornare i dati di fatturazione o disdire il rinnovo in autonomia.»
- Perché: «area soci» è la pagina dei soci sul sito dell'associazione; qui si parla dell'account su associazionesemplice.it, e chi legge va a cercare nel posto sbagliato. Stesso scambio in part6:59 («dall'area soci, senza reinstallare nulla»), part6:141 («dall'area soci puoi aprire anche il portale di fatturazione») e part6:253 («accedi dall'area soci»).

### supporto/database/guides/part1.php:302 · Media · guida «cambiare-il-dominio-della-licenza»
- Ora: «Il dominio viene normalizzato: minuscolo, senza `www`, senza schema, porta e percorso.»
- Proposta: «Del dominio conta solo il nome: maiuscole, `https://`, `www` e ciò che segue il nome (porta o percorso) vengono ignorati.»
- Perché: «normalizzato» e «schema» sono parole da programmatore.

### supporto/database/guides/part2.php:20 · Bassa · guida «importare-i-soci-da-excel-o-csv»
- Ora: «Un foglio Excel con **più di 16.384 colonne** (oltre la colonna XFD) viene rifiutato subito con «Il foglio ha più di 16384 colonne: controlla che sia il file giusto.»»
- Proposta: «Un foglio con più di 16.384 colonne viene rifiutato: controlla di aver scelto il file giusto.»
- Perché: caso rarissimo che occupa due righe nella sezione «Prepara il file»; il messaggio citato ripete la frase.

### supporto/database/guides/part2.php:31 · Media · guida «importare-i-soci-da-excel-o-csv»
- Ora: «Se il file ha **colonne con gli anni** (2023, 2024…), la colonna è l'**anno fiscale** della tessera: con l'anno fiscale che non inizia a gennaio la colonna «2025» è la tessera «2025/2026». L'anteprima di ogni riga lo dice, per esempio «Iscritto: Anno 2025 → tessera 2025/2026».»
- Proposta: «Le **colonne con gli anni** (2023, 2024…) indicano l'**anno fiscale** della tessera. Se il tuo anno fiscale non inizia a gennaio, la colonna «2025» vale per la tessera «2025/2026»: l'anteprima lo dice riga per riga, per esempio «Iscritto: Anno 2025 → tessera 2025/2026».»
- Perché: «se il file ha colonne…, la colonna è…» si legge a fatica.

### supporto/database/guides/part2.php:33 · Bassa · guida «importare-i-soci-da-excel-o-csv»
- Ora: «Se un'importazione tocca un **anno fiscale chiuso**, l'importazione può fermarsi con il messaggio «L'anno … è chiuso…»»
- Proposta: «Se l'importazione tocca un **anno fiscale chiuso**, può fermarsi con il messaggio «L'anno … è chiuso…»»
- Perché: «importazione» ripetuta due volte in dieci parole.

### supporto/database/guides/part2.php:35 · Media · guida «importare-i-soci-da-excel-o-csv»
- Ora: «In alternativa puoi usare **WP All Import**: il plugin mette a disposizione due tipi di contenuto di appoggio, applicando le stesse regole.»
- Proposta: «In alternativa puoi usare **WP All Import**: valgono le stesse regole.»
- Perché: «tipi di contenuto di appoggio» è gergo che un volontario non può usare.

### supporto/database/guides/part2.php:51 · Media · guida «iscrivere-un-socio-e-rinnovare-la-tessera»
- Ora: «La scheda del socio è divisa in sezioni. Nell'elenco dei soci, il foglio **«Altro»** raccoglie le azioni meno usate. Anche la prima nota e l'incasso sono più leggibili, soprattutto da telefono.»
- Proposta: «Nell'elenco dei soci, **«Altro»** raccoglie le azioni meno usate.»
- Perché: il resto è una nota di rilascio («più leggibili») che non insegna nulla.

### supporto/database/guides/part2.php:53 · Media · guida «iscrivere-un-socio-e-rinnovare-la-tessera»
- Ora: «I nuovi soci **non hanno più un utente WordPress**: entrano nell'area soci con **email e password** (obbligatoria, la scelgono alla prima entrata); il link o codice ricevuto per email resta come opzione. L'utente WordPress nasce **solo quando si dà un potere** al socio (segreteria, tesoriere, staff). Chi ha già un utente WordPress continua a entrare da WordPress. Se esiste già un utente con l'email del socio, viene collegato senza cambiargli ruolo. Se quell'utente ha **altri ruoli** (redattore, amministratore…) la segreteria vede un errore: **solo un amministratore** può collegarlo.»
- Proposta: «I nuovi soci entrano nell'area soci con **email e password**, che scelgono alla prima entrata. Non hanno un utente WordPress: nasce solo quando dai al socio un ruolo (segreteria, tesoriere, staff). Se esiste già un utente con l'email del socio, viene collegato senza cambiargli ruolo; se ha altri ruoli (redattore, amministratore…) può collegarlo **solo un amministratore**.»
- Perché: «non hanno più» è una nota di rilascio, «potere» sta per «ruolo» e il resto è già nella guida collegata subito sotto.

### supporto/database/guides/part2.php:61 · Bassa · guida «iscrivere-un-socio-e-rinnovare-la-tessera» (e part6.php:159)
- Ora: «Negli **ultimi giorni prima della scadenza** (quelli dell'avviso di scadenza, **30** di partenza) si può **rinnovare in anticipo**.»
- Proposta: «Negli ultimi giorni prima della scadenza (30, se non hai cambiato i giorni dell'avviso) si può **rinnovare in anticipo**.»
- Perché: la parentesi si capisce solo alla seconda lettura.

### supporto/database/guides/part2.php:63 · Media · guida «iscrivere-un-socio-e-rinnovare-la-tessera»
- Ora: «Se **cambi il mese dell'anno fiscale** (finché non hai chiuso il primo anno), compare il riquadro **«Cosa succede alle tessere»**: le tessere degli anni fiscali ancora aperti che hanno un **pagamento registrato** si riallineano al nuovo anno fiscale, **non si accorciano mai** e quelle degli **anni chiusi** non si toccano. Le tessere omaggio, onorarie e a quota zero restano come sono. Due tessere dello stesso anno non possono convivere: resta la più recente e l'altra risulta **«sostituita»** (resta registrata e i pagamenti non cambiano; lo vedi solo nella scheda del socio). Per cambiare il mese devi spuntare **«Ho letto cosa succede alle tessere e voglio cambiare il mese dell'anno fiscale.»** e prima del cambio il plugin salva una **copia di sicurezza** dei dati, che resta sul sito per **7 giorni**. Vedi [Impostare il mese di inizio dell'anno fiscale](guida:inizio-dell-anno-fiscale).»
- Proposta: «Se **cambi il mese dell'anno fiscale** (puoi farlo finché non hai chiuso il primo anno), il riquadro **«Cosa succede alle tessere»** ti mostra l'effetto prima di confermare: le tessere pagate degli anni ancora aperti si riallineano senza mai accorciarsi, quelle degli anni chiusi non si toccano. Vedi [Impostare il mese di inizio dell'anno fiscale](guida:inizio-dell-anno-fiscale).»
- Perché: 130 parole su un caso raro dentro la guida più usata; il dettaglio (tessere omaggio, «sostituita», copia di 7 giorni) appartiene alla guida collegata, da controllare che lo riporti.

### supporto/database/guides/part2.php:71 · Media · guida «iscrivere-un-socio-e-rinnovare-la-tessera»
- Ora: «Nella pagina Incasso la quota non viene più proposta da sola, e se la aggiungi a mano compare l'avviso **«⚠ il socio ha già la tessera 2026: l'incasso non verrà salvato finché c'è questa voce»** (con l'anno giusto al posto di 2026). Se provi a salvare, il programma dice, per esempio, **«Voce 2: il socio ha già la tessera 2026.»** (il numero indica la riga dell'incasso) e l'incasso non viene salvato, nemmeno le altre voci (per esempio un corso): togli la voce della quota e salva di nuovo.»
- Proposta: «La pagina Incasso non propone la quota e, se la aggiungi a mano, avvisa: **«⚠ il socio ha già la tessera 2026: l'incasso non verrà salvato finché c'è questa voce»** (con l'anno giusto). Finché la voce resta non si salva nulla, nemmeno le altre voci: toglila e salva di nuovo.»
- Perché: due messaggi citati per lo stesso caso, tre parentesi e una frase di 60 parole.

### supporto/database/guides/part2.php:83 · Bassa · guida «iscrivere-un-socio-e-rinnovare-la-tessera»
- Ora: «Se lo **spegni**, basta un versamento qualsiasi per attivare la tessera, come prima.»
- Proposta: «Se lo **spegni**, basta un versamento qualsiasi per attivare la tessera.»
- Perché: «come prima» rimanda a una versione che il lettore non conosce.

### supporto/database/guides/part2.php:101 · Media · guida «gestire-gli-ospiti»
- Ora: «Ai non soci è consentito partecipare solo poche volte: oltre, anche per ragioni assicurative, dovrebbero iscriversi.»
- Proposta: «Un ospite può partecipare solo poche volte: poi, anche per ragioni assicurative, dovrebbe diventare socio.»
- Perché: passivo burocratico («è consentito»); il glossario dice «ospite» e riserva «iscriversi» ai corsi.

### supporto/database/guides/part2.php:140 · Media · guida «assegnare-ruoli-e-permessi»
- Ora: «I permessi derivano dai dati della persona, non solo dai ruoli di WordPress. Si assegnano dalla **scheda del socio**.»
- Proposta: «I permessi si assegnano dalla **scheda del socio**: contano il ruolo e le cariche che gli dai lì, non solo i ruoli di WordPress.»
- Perché: «derivano dai dati della persona» è una frase da programmatore.

### supporto/database/guides/part2.php:150 · Bassa · guida «assegnare-ruoli-e-permessi» (e part3.php:424)
- Ora: «**Staff dell'evento**: verifica gli ingressi di quell'evento e, se abilitato, incassa il biglietto sul posto.»
- Proposta: «**Staff dell'evento**: verifica gli ingressi di quell'evento e, se abilitato, incassa il contributo sul posto.»
- Perché: il glossario dice «contributo», non «biglietto»; lo stesso in part3:424 («incassare il biglietto sul posto»).

### supporto/database/guides/part2.php:159 · Bassa · guida «assegnare-ruoli-e-permessi»
- Ora: «## Quadro completo

Gli amministratori di WordPress hanno tutto. Gli altri entrano nel pannello solo con il ruolo **Segreteria** o **Dipendente** (si assegnano dalla scheda del socio e da Rubrica soci → Dipendenti) o come presidente o vicepresidente in regola.»
- Proposta: «## Chi entra nell'amministrazione

Gli amministratori di WordPress possono fare tutto. Gli altri entrano nell'amministrazione solo con il ruolo **Segreteria** o **Dipendente** (si assegnano dalla scheda del socio e da **Rubrica soci → Dipendenti**) o come presidente o vicepresidente in regola.»
- Perché: il titolo non dice di cosa parla la sezione; «pannello» altrove è «amministrazione»; il percorso di menu non è in grassetto.

### supporto/database/guides/part2.php:204 · Media · guida «personalizzare-la-tessera»
- Ora: «2. Scegli il logo dalla libreria media e il colore principale e secondario. Di default si prendono dal sito (Elementor o il tema).
3. Spunta **Personalizza** per cambiarli. Togli la spunta per tornare ai colori del sito.»
- Proposta: «2. Di partenza logo e colori sono quelli del sito (Elementor o il tema): per cambiarli spunta **Personalizza**.
3. Scegli il logo dalla libreria media e i colori principale e secondario. Per tornare a quelli del sito togli la spunta.»
- Perché: i passi sono in ordine inverso: prima si dice di scegliere, poi che per scegliere serve la spunta.

### supporto/database/guides/part2.php:215 · Bassa · guida «personalizzare-la-tessera»
- Ora: «Le misure sono in percentuale della larghezza, quindi la tessera si ridimensiona da sola ed è identica a schermo e in stampa (**Stampa la tessera**).»
- Proposta: «La tessera si ridimensiona da sola ed è identica a schermo e in stampa (**Stampa la tessera**).»
- Perché: il dettaglio tecnico sulle percentuali non serve a chi la disegna.

### supporto/database/guides/part2.php:222 · Media · guida «area-riservata-dei-soci» (titolo; e nome della sezione alle righe 221, 251, 286)
- Ora: «Creare l'area soci dei soci»
- Proposta: «Creare l'area soci»
- Perché: ripetizione evidente nel titolo. Le tre guide stanno nella sezione `'section' => 'Area riservata'`: per coerenza con il glossario dovrebbe chiamarsi «Area soci».

### supporto/database/guides/part2.php:230 · Bassa · guida «area-riservata-dei-soci»
- Ora: «1. La configurazione guidata crea la pagina **Area soci**: se non l'hai fatto, riaprila da **Strumenti → Configurazione guidata**.»
- Proposta: «1. La configurazione guidata crea la pagina **Area soci**: se hai saltato quel passo, riaprila da **Strumenti → Configurazione guidata**.»
- Perché: «se non l'hai fatto» non dice cosa.

### supporto/database/guides/part2.php:238 · Bassa · guida «area-riservata-dei-soci»
- Ora: «**Da telefono**: una barra permette di **saltare alle sezioni** dell'area soci e i pulsanti sono più grandi (44 px).»
- Proposta: «**Da telefono**: una barra porta alle sezioni dell'area soci e i pulsanti sono più grandi.»
- Perché: «44 px» è un dato da sviluppatore.

### supporto/database/guides/part2.php:266 · Bassa · guida «primo-accesso-e-soci-senza-email»
- Ora: «Ogni indirizzo IP può fare 10 tentativi l'ora.»
- Proposta: «Dalla stessa connessione si possono fare 10 tentativi l'ora.»
- Perché: «indirizzo IP» è gergo; le altre guide dicono «connessione».

### supporto/database/guides/part2.php:280 · Media · guida «primo-accesso-e-soci-senza-email»
- Ora: «## Se l'ente chiede dati obbligatori»
- Proposta: «## Informativa e regolamento da accettare»
- Perché: la sezione parla di privacy e regolamento da accettare all'attivazione, non di dati obbligatori.

### supporto/database/guides/part2.php:299 · Media · guida «accesso-dei-soci-link-codice-o-utente-wordpress»
- Ora: «I moduli di accesso (entrare con la password, con il codice, il recupero della password) e quelli di attivazione e di «Primo accesso» **valgono solo dal browser che ha aperto la pagina**. Se il socio ha lasciato la pagina aperta troppo a lungo, o il modulo non arriva dalla pagina giusta, legge «Il modulo deve partire dalla pagina di accesso, oppure è scaduto: ricarica la pagina e riprova.» (nell'attivazione e nel primo accesso: «Il modulo deve partire dalla sua pagina, oppure è scaduto: ricarica la pagina e riprova.»). Basta **ricaricare la pagina** e riprovare.»
- Proposta: «Se il socio lascia aperta troppo a lungo la pagina di accesso, di attivazione o di «Primo accesso», oppure invia il modulo da un altro browser, legge «Il modulo deve partire dalla pagina di accesso, oppure è scaduto: ricarica la pagina e riprova.» Basta **ricaricare la pagina** e riprovare.»
- Perché: 90 parole e due messaggi quasi uguali per dire «ricarica la pagina».

### supporto/database/guides/part2.php:303 · Bassa · guida «accesso-dei-soci-link-codice-o-utente-wordpress»
- Ora: «Cambiata la password, gli **altri dispositivi devono rientrare**.

Per **cambiare** la password dall'area soci serve **quella attuale**; anche in questo caso gli altri dispositivi vengono chiusi.»
- Proposta: «Per **cambiare** la password dall'area soci serve quella attuale.

In entrambi i casi, sugli altri dispositivi il socio deve entrare di nuovo.»
- Perché: la stessa conseguenza è detta due volte con parole diverse («devono rientrare», «vengono chiusi»), e non sono i dispositivi a rientrare.

### supporto/database/guides/part2.php:309 · Media · guida «accesso-dei-soci-link-codice-o-utente-wordpress»
- Ora: «Le password dei soci **tornano come erano nella copia**. Al primo accesso il socio entra con la password di prima e trova la pagina **«Scegli una nuova password»**, con il testo «Il sito è stato ripristinato da una copia di sicurezza: per la tua sicurezza scegli una nuova password prima di continuare.» Deve sceglierla **prima di fare altro**, **anche se entra con il link ricevuto per email**. Vale solo per chi aveva una password. Le **sessioni aperte** e le **notifiche push** non si ripristinano: i soci devono **entrare di nuovo** e riattivare le notifiche.»
- Proposta: «Dopo un ripristino i soci devono **entrare di nuovo** e riattivare le notifiche. Chi aveva una password entra con quella di prima e trova la pagina **«Scegli una nuova password»**: deve sceglierla prima di fare altro, anche se entra con il link ricevuto per email.»
- Perché: sette grassetti e un messaggio citato per intero; la versione completa è già nella guida della copia di sicurezza.

### supporto/database/guides/part2.php:313 · Media · guida «accesso-dei-soci-link-codice-o-utente-wordpress» (e riga 324)
- Ora: «L'utente WordPress nasce **solo quando si dà un potere** al socio: segreteria, tesoriere, staff. Chi ha già un utente WordPress continua a entrare da WordPress, come prima.»
- Proposta: «L'utente WordPress nasce **solo quando dai un ruolo** al socio: segreteria, tesoriere, staff. Chi ha già un utente WordPress continua a entrare da WordPress.»
- Perché: «potere» non è usato in nessun'altra guida (altrove «ruolo»); «come prima» è una nota di rilascio. Lo stesso alla riga 324: «## Togliere l'ultimo potere» e «Quando si toglie l'ultimo potere a un socio» diventano «Togliere l'ultimo ruolo» e «Quando togli l'ultimo ruolo a un socio».

### supporto/database/guides/part2.php:332 · Media · guida «accesso-dei-soci-link-codice-o-utente-wordpress»
- Ora: «## Aggiornamento

Gratuito e Pro vanno aggiornati insieme. Crediti, risorse e notifiche push funzionano anche per i soci senza utente WordPress, e lo staff di un evento con l'incasso abilitato può addebitare crediti con il QR anche senza utente WordPress. La ricerca dei soci per cellulare è più veloce.»
- Proposta: «## Soci senza utente WordPress

Crediti, risorse e notifiche push funzionano anche per i soci senza utente WordPress. Lo staff di un evento con l'incasso abilitato può addebitare crediti con il QR anche senza utente WordPress.»
- Perché: la sezione è una nota di rilascio («è più veloce»); l'avviso sull'aggiornare insieme è già in quattro guide.

### supporto/database/guides/part3.php:24 · Media · guida «creare-un-corso»
- Ora: «Per una nuova edizione di un corso o di un evento già fatto, nell'**elenco delle attività** usa il collegamento **«Riproponi»**: apre la sezione **«Riproponi»** della scheda dell'attività. Crea una **nuova edizione** con gli stessi dati: contributi, referente, regole, note, luogo e staff. **Iscrizioni, prenotazioni e incassi non si copiano**: la nuova attività parte vuota.»
- Proposta: «Per una nuova edizione di un corso o di un evento già fatto, nell'**elenco delle attività** premi **«Riproponi»**. La nuova edizione ha gli stessi dati (contributi, referente, regole, note, luogo e staff) ma parte vuota: iscrizioni, prenotazioni e incassi non si copiano.»
- Perché: «Riproponi» e «nuova edizione» sono ripetuti due volte ciascuno.

### supporto/database/guides/part3.php:30 · Bassa · guida «creare-un-corso»
- Ora: «1. Compili la riga **«Sport e disciplina»** nel modulo **«Nuova attività»** (da **Corsi ed eventi**) oppure, per un'attività esistente, nella scheda **«Dati dell'attività»**.»
- Proposta: «1. Compila la riga **«Sport e disciplina»** nel modulo **«Nuova attività»** (da **Corsi ed eventi**) oppure, per un'attività esistente, nella scheda **«Dati dell'attività»**.»
- Perché: tutti gli altri passi sono all'imperativo.

### supporto/database/guides/part3.php:44 · Media · guida «creare-un-corso»
- Ora: «Inoltre un'attività accetta **qualsiasi sport scritto in modo sensato**, non solo quelli dell'elenco. L'elenco di base resta «basato sull'elenco CONI».»
- Proposta: «Un'attività accetta anche uno sport che non è nell'elenco.»
- Perché: «scritto in modo sensato» non dice quale sia il criterio (vedi «Da verificare»); la seconda frase è una nota di rilascio e ripete la riga 31.

### supporto/database/guides/part3.php:46 · Media · guida «creare-un-corso»
- Ora: «Con Pro questi dati finiscono nel file per i portali degli enti: le colonne **Sport** e **Disciplina** sono ricavate dai corsi a cui la persona è iscritta in quel momento e dagli eventi a cui ha una prenotazione, separati da «;» se sono più di uno»
- Proposta: «Con Pro, sport e disciplina compaiono nel file per i portali degli enti: nelle colonne **Sport** e **Disciplina** trovi quelli dei corsi a cui la persona è iscritta e degli eventi a cui è prenotata, separati da «;»»
- Perché: «sono ricavate dai» è un passivo tecnico; la frase si accorcia.

### supporto/database/guides/part3.php:50 · Media · guida «creare-un-corso»
- Ora: «**Rinnovo mensile**: il corso si rinnova da solo ogni mese finché l'iscritto non viene «disdetto».»
- Proposta: «**Rinnovo mensile**: il corso si rinnova da solo ogni mese finché non disdici il rinnovo dell'iscritto.»
- Perché: si disdice un rinnovo, non una persona (il glossario: disdire = fermare il rinnovo di un corso).

### supporto/database/guides/part3.php:51 · Media · guida «creare-un-corso»
- Ora: «**Pagamento unico**: la quota è il totale (per esempio «10 incontri a 120 €»), dovuta all'iscrizione e versabile anche a rate. Se l'iscrizione viene cancellata prima di qualunque pagamento, non è dovuto nulla.»
- Proposta: «**Pagamento unico**: il contributo è il totale (per esempio «10 incontri a 120 €»), dovuto all'iscrizione e versabile anche a rate. Se elimini l'iscrizione prima di qualunque pagamento, non è dovuto nulla.»
- Perché: per i corsi il glossario dice «contributo» e «eliminare», non «quota» e «cancellare».

### supporto/database/guides/part3.php:52 · Media · guida «creare-un-corso» (e riga 11)
- Ora: «**Pagamento trimestrale**: oltre a «una tantum» e «rinnovo mensile» puoi scegliere il pagamento del corso a trimestre. I **promemoria mensili** saltano i corsi trimestrali.»
- Proposta: «**Pagamento trimestrale**: il contributo si paga ogni trimestre. I **promemoria mensili** saltano questi corsi.»
- Perché: due righe sopra la stessa scelta si chiama «Pagamento unico», qui «una tantum». L'apertura della guida (riga 11) parla ancora di due sole forme di pagamento: «Gli iscritti pagano **ogni mese** (rinnovo mensile) oppure **una volta sola** (pagamento unico).» va completata con «ogni trimestre».

### supporto/database/guides/part3.php:75 · Media · guida «creare-un-corso» (righe 75-79, 117; e riga 255 della guida «pagina-pubblica-degli-eventi-sul-sito»)
- Ora: «### Cosa succede alla quota

- La quota dell'iscritto segue la variante in **«Pagamenti da incassare»**, nell'incasso già compilato (l'importo proposto) e nei pagamenti online del socio.»
- Proposta: «### Cosa succede al contributo

- Il contributo dell'iscritto segue la variante in **«Pagamenti da incassare»**, nell'incasso già compilato e nei pagamenti online del socio.»
- Perché: nella stessa sezione si parla di «Varianti del contributo» e poi di «quota»; il glossario riserva «quota» alla quota associativa. Lo stesso alle righe 79 («una normale quota del corso»), 117 («**Le quote non cambiano**») e 255 («paga **una quota sola**»). «Quota base» alla riga 69 è un'etichetta a schermo e resta finché non cambia nel plugin.

### supporto/database/guides/part3.php:85 · Bassa · guida «creare-un-corso»
- Ora: «Chi smette di venire a un corso ha già usufruito del periodo che ha pagato: **non si restituisce nulla**.»
- Proposta: «Chi smette di venire ha già frequentato il periodo pagato: **non si restituisce nulla**.»
- Perché: «ha usufruito del periodo» è burocratese.

### supporto/database/guides/part3.php:95 · Media · guida «creare-un-corso» (righe 95-100)
- Ora: «Si restituisce ciò che non è stato ancora fatto quando è l'associazione a non poter più tenere l'attività (per esempio l'insegnante non può più fare il corso). Eliminando il corso:

- un mese pagato per intero ma fatto a metà: si rende la quota delle **lezioni non ancora tenute**. Esempio: mese da 60 €, 4 lezioni, 2 già tenute: si rendono **30 €**;»
- Proposta: «In questo caso (per esempio se l'insegnante non può più tenere il corso) si restituisce ciò che non è stato ancora fatto. Eliminando il corso:

- di un mese pagato per intero ma fatto a metà si restituisce la parte delle **lezioni non ancora tenute**. Esempio: mese da 60 €, 4 lezioni, 2 già tenute: si restituiscono **30 €**;»
- Perché: la prima frase ripete il titolo della sezione; «rendere» e «restituire» si alternano per la stessa cosa (anche alle righe 99 e 100).

### supporto/database/guides/part3.php:121 · Media · guida «creare-un-corso»
- Ora: «Nel modulo di iscrizione hai due pulsanti in più: **«Salva e prossimo inserimento»** iscrive la persona e resta sulla stessa pagina, pronta per iscrivere quella dopo; **«Iscrivi e vai alla cassa»** la iscrive e apre l'incasso già compilato, se c'è qualcosa da pagare (altrimenti resta lì). Per togliere chi non è più iscritto usa **Togli dall'elenco** (solo chi non ha incassi registrati).»
- Proposta: mettere i pulsanti in elenco: «- **«Salva e prossimo inserimento»**: iscrive la persona e resta sulla pagina, pronta per la successiva.» · «- **«Iscrivi e vai alla cassa»**: iscrive la persona e, se c'è qualcosa da pagare, apre l'incasso già compilato.» · «- **Togli dall'elenco**: toglie chi non è più iscritto, solo se non ha incassi registrati.»
- Perché: tre pulsanti descritti in un capoverso unico di 90 parole; in elenco si trovano a colpo d'occhio.

### supporto/database/guides/part3.php:144 · Media · guida «creare-un-evento»
- Ora: «Tutte le date di un evento devono cadere nell'**anno delle attività** (ogni attività ne ha uno solo), altrimenti il salvataggio viene rifiutato e non resta nulla a metà.»
- Proposta: «Tutte le date di un evento devono cadere nello stesso **anno delle attività**: altrimenti l'evento non viene salvato.»
- Perché: «il salvataggio viene rifiutato e non resta nulla a metà» descrive il programma, non cosa vede l'utente.

### supporto/database/guides/part3.php:157 · Media · guida «creare-un-evento» (righe 157-163)
- Ora: «## Cancellare un evento»
- Proposta: «## Eliminare un evento»
- Perché: il pulsante è **Elimina l'evento** e il testo sotto dice «eliminare»; il glossario esclude «cancellare». Lo stesso alla riga 163 («se cancelli una **singola iscrizione**»).

### supporto/database/guides/part3.php:163 · Media · guida «creare-un-evento»
- Ora: «Vale lo stesso se cancelli una **singola iscrizione** (a una data di un evento o a un corso): si restituisce o si annulla solo ciò che non è ancora stato fatto. Gli incassi di date passate, o di mesi con almeno una lezione svolta, restano in prima nota. Per la parte già fatta la pagina e il messaggio finale ti dicono **quanto manca** (da incassare) oppure **quanto avanza** (da restituire a mano).»
- Proposta: «Vale lo stesso se elimini una **singola iscrizione** (a una data di un evento o a un corso). Per la parte già fatta la pagina e il messaggio finale ti dicono **quanto manca** (da incassare) oppure **quanto avanza** (da restituire a mano).»
- Perché: le due frasi centrali ripetono parola per parola il capoverso precedente.

### supporto/database/guides/part3.php:167 · Bassa · guida «creare-un-evento»
- Ora: «L'annullamento di una data **non registra rimborsi**: le somme si restituiscono a mano, come prima.»
- Proposta: «L'annullamento di una data **non registra rimborsi**: le somme si restituiscono a mano.»
- Perché: «come prima» è una nota di rilascio.

### supporto/database/guides/part3.php:178 · Media · guida «lista-d-attesa-e-tolleranza-sul-pagamento» (e righe 181, 183)
- Ora: «Il socio (o chi ospita) può mettere sé stesso o un ospite in coda dall'elenco eventi, vede la posizione e può uscire dalla lista.»
- Proposta: «Dall'elenco eventi il socio può mettere in lista sé stesso o un suo ospite, vedere la posizione e uscire dalla lista.»
- Perché: «il socio (o chi ospita)» è la stessa persona; «coda» e «lista» si alternano (anche alle righe 181 «Una persona in coda» e 183 «La coda è visibile»).

### supporto/database/guides/part3.php:181 · Media · guida «lista-d-attesa-e-tolleranza-sul-pagamento» (e riga 379)
- Ora: «Se l'attività ha dei **listini**, chi si mette in lista **sceglie la tariffa**: il plugin la ricorda e, quando il posto si libera, prenota con quella. Una persona in coda **senza tariffa valida** (per esempio perché si è messa in lista prima) **non entra da sola**»
- Proposta: «Se l'attività ha dei **listini**, chi si mette in lista **sceglie il listino**: quando il posto si libera, il plugin prenota con quello. Una persona in lista **senza un listino valido** (per esempio perché si è messa in lista prima) **non entra da sola**»
- Perché: «listino» e «tariffa» indicano la stessa cosa nella stessa frase. I messaggi a schermo citati dicono ancora «tariffa» (righe 181 e 379): da allineare nel plugin.

### supporto/database/guides/part3.php:192 · Bassa · guida «lista-d-attesa-e-tolleranza-sul-pagamento» (e part4.php:279)
- Ora: «Il controllo gira ogni ora e a ogni nuova prenotazione.»
- Proposta: «Il controllo avviene ogni ora e a ogni nuova prenotazione.»
- Perché: «gira» è gergo da programmatore; lo stesso in part4:279 («Il controllo gira comunque ogni ora.»).

### supporto/database/guides/part3.php:232 · Bassa · guida «pagina-pubblica-degli-eventi-sul-sito»
- Ora: «La pagina è del tipo **«Eventi sul sito»** e nasce da sola dall'attività.»
- Proposta: «La pagina nasce da sola dall'attività e la trovi tra gli **«Eventi sul sito»**.»
- Perché: «è del tipo» è linguaggio da WordPress per sviluppatori.

### supporto/database/guides/part3.php:250 · Bassa · guida «pagina-pubblica-degli-eventi-sul-sito» (e riga 257)
- Ora: «Se lo lasci vuoto, la data finisce lo stesso giorno, come prima.»
- Proposta: «Se lo lasci vuoto, la data finisce lo stesso giorno.»
- Perché: nota di rilascio. Lo stesso alla riga 257: «Le date **di un giorno solo** non cambiano: si prenotano online come sempre, **anche il giorno stesso**, finché non sono passate.» diventa «Le date **di un giorno solo** si prenotano online **anche il giorno stesso**, finché non sono passate.»

### supporto/database/guides/part3.php:286 · Media · guida «pagina-pubblica-degli-eventi-sul-sito»
- Ora: «Il pulsante fisso **«Scrivici su WhatsApp»** usa il **telefono della segreteria** che hai indicato nei **Dati dell'ente** e compare sul sito **solo se accendi la casella «Pulsante WhatsApp»** (in **Impostazioni → Soci e quote**). Accendila solo se è un **cellulare su cui usate WhatsApp**: il telefono della segreteria, che è obbligatorio, può anche essere un fisso. Il pulsante compare anche sulle pagine degli eventi, accanto ai contatti. Un numero diverso, scelto per un singolo evento, resta com'era.»
- Proposta: «Il pulsante **«Scrivici su WhatsApp»** usa il telefono della segreteria indicato nei **Dati dell'ente** e compare sul sito, anche nelle pagine degli eventi, solo se accendi la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**. Accendila solo se quel numero è un cellulare con WhatsApp. Se per un evento hai scelto un numero diverso, vale quello.»
- Perché: sette grassetti, «usate» al voi, e «resta com'era» è una nota di rilascio.

### supporto/database/guides/part3.php:292 · Media · guida «pagina-pubblica-degli-eventi-sul-sito»
- Ora: «Le pagine rimaste in bozza le trovi nell'elenco **Attività**, nella scheda **«Pagine in bozza: non si vedono ancora sul sito»**: ogni riga ha il nome e la data (con **«(annullato)»** se tutte le date sono annullate) e le caselle sono **vuote** all'apertura; **«Seleziona tutte»** le spunta in un colpo. Premi **«Pubblica le pagine selezionate»** e conferma «Pubblicare le pagine selezionate? Compariranno subito sul sito.»: a fine lavoro leggi «Pagine pubblicate: N. Ora si vedono sul sito.» Se non hai spuntato niente, «Spunta almeno una pagina da pubblicare.»»
- Proposta: «## Pubblicare le pagine in bozza

Le pagine rimaste in bozza le trovi nell'elenco **Attività**, nella scheda **«Pagine in bozza: non si vedono ancora sul sito»**. Spunta quelle da pubblicare (o **«Seleziona tutte»**), premi **«Pubblica le pagine selezionate»** e conferma: compaiono subito sul sito.»
- Perché: tre messaggi citati per un'azione ovvia; inoltre la pubblicazione delle bozze vale per tutte le pagine, ma oggi sta sotto il titolo «Se arrivi da The Events Calendar», dove chi non importa non la cerca.

### supporto/database/guides/part3.php:294 · Media · guida «pagina-pubblica-degli-eventi-sul-sito»
- Ora: «Le pagine le può pubblicare **chi ha l'area della pagina**: **Eventi** per le pagine degli eventi, **Corsi** per quelle dei corsi. Gli **amministratori** e la **segreteria** le possono pubblicare **tutte**.»
- Proposta: «Le pagine le pubblica chi ha il permesso per quell'area: **Eventi** per le pagine degli eventi, **Corsi** per quelle dei corsi. Amministratori e segreteria le pubblicano tutte.»
- Perché: «chi ha l'area della pagina» non si capisce. Queste due frasi vanno nella sezione nuova «Pubblicare le pagine in bozza»; il resto del capoverso (ricorrenze, data di fine, doppioni) riguarda l'importazione e resta sotto «Se arrivi da The Events Calendar».

### supporto/database/guides/part3.php:315 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito» (righe 315-318)
- Ora: «**Dopo la conferma** il non socio vede «Email confermata. La segreteria esamina la richiesta e ti scrive appena è approvata.» (per i corsi: «Email confermata. La segreteria esamina la richiesta di iscrizione e ti scrive appena è approvata, spiegandoti come pagare.»). Gli arriva anche un'email, **«Richiesta ricevuta: …»**: «Abbiamo confermato la tua richiesta per «…». La segreteria la esamina e ti scrive appena è approvata. Per qualsiasi dubbio scrivi alla segreteria.»

**Richieste non confermate:** se il non socio non conferma l'email **entro 24 ore**, la richiesta **si cancella da sola**.»
- Proposta: «**Dopo la conferma** legge che la segreteria esaminerà la richiesta e gli scriverà appena è approvata (per i corsi, spiegando anche come pagare). Lo stesso gli arriva per email, con oggetto **«Richiesta ricevuta: …»**.

Se non conferma entro 24 ore, la richiesta si cancella da sola.»
- Perché: tre messaggi quasi identici citati per intero. Tra la riga 315 e la 316 manca la riga vuota: oggi «**Dopo la conferma**» si attacca al capoverso precedente.

### supporto/database/guides/part3.php:320 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito»
- Ora: «**Se il codice fiscale è già in anagrafica**, chi chiede **non vede né il nome né lo stato della persona**: la richiesta passa alla segreteria. **Se il codice fiscale è già in anagrafica con dati diversi**, la segreteria riceve un'email **«Richiesta di partecipazione da verificare: …»**. Se i dati sono della **stessa persona**, la approva e si usa la scheda che c'è già; altrimenti la **rifiuta**.»
- Proposta: «**Se il codice fiscale è già registrato**, chi chiede non vede né il nome né lo stato di quella persona: la richiesta passa alla segreteria. Se i dati sono diversi, la segreteria riceve l'email **«Richiesta di partecipazione da verificare: …»**: approva se è la stessa persona (si usa la scheda che c'è già), altrimenti rifiuta.»
- Perché: «in anagrafica» è burocratese ripetuto due volte; tre «se» di fila.

### supporto/database/guides/part3.php:330 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito» (e titolo alla riga 322)
- Ora: «**Quando il tetto è raggiunto**, chi scrive vede «In questo momento non possiamo ricevere altre richieste per questa attività: riprova più tardi o scrivi alla segreteria (indirizzo).» (l'indirizzo compare solo se la segreteria ne ha uno). Tu trovi in **Bacheca** l'avviso **«Le richieste di evento e di corso sono al limite»**, con quante richieste ci sono in attesa, il limite e, se ce ne sono, quante nuove sono state scartate negli ultimi giorni.»
- Proposta: «**Quando il limite è raggiunto**, chi scrive legge che per ora non si ricevono altre richieste e che può riprovare più tardi o scrivere alla segreteria. Tu trovi in **Bacheca** l'avviso **«Le richieste di evento e di corso sono al limite»**, con il numero di richieste in attesa e di quelle scartate.»
- Perché: «tetto» e «limite» si alternano (il titolo alla riga 322 è «I tetti alle richieste», le impostazioni si chiamano «Limiti e soglie»): usare «limite» anche nel titolo, «I limiti alle richieste».

### supporto/database/guides/part3.php:332 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito»
- Ora: «**Se il limite è per email o per connessione**, chi scrive vede, per l'email, «Con questo indirizzo email sono già stati chiesti molti posti nelle ultime 24 ore: per altri posti scrivi alla segreteria (indirizzo).» e, per la connessione, «Da questa connessione sono già stati chiesti molti posti nelle ultime 24 ore: riprova più tardi o scrivi alla segreteria (indirizzo).» (l'indirizzo compare solo se la segreteria ne ha uno). Vale per eventi e corsi. La richiesta **non viene registrata** e **non parte nessuna email**. Se nell'ultima settimana ce ne sono state di respinte, nell'avviso in Bacheca trovi una riga in più: «N richieste sono state respinte perché una email o una connessione aveva già chiesto molti posti (il limite si cambia da Limiti e soglie).»»
- Proposta: «**Se il limite è per email o per connessione**, chi scrive legge che da quell'indirizzo o da quella connessione sono già stati chiesti molti posti nelle ultime 24 ore, con l'invito a scrivere alla segreteria. La richiesta **non viene registrata** e non parte nessuna email. L'avviso in Bacheca conta anche le richieste respinte nell'ultima settimana.»
- Perché: 130 parole, tre messaggi citati per intero e una parentesi ripetuta identica due righe sopra.

### supporto/database/guides/part3.php:336 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito»
- Ora: «Per gli eventi **a pagamento** il non socio **versa in anticipo con bonifico istantaneo** e manda la **ricevuta alla segreteria** entro il termine impostato in **Impostazioni → Limiti** (24 ore se non lo cambi).»
- Proposta: spostare il capoverso, senza cambiarlo, nella sezione «Chi non è socio» (dopo la riga 320).
- Perché: oggi il modo di pagare del non socio sta in fondo alla sezione sui limiti alle richieste, dove nessuno lo cerca. Il percorso «Impostazioni → Limiti» è in «Da verificare».

### supporto/database/guides/part3.php:343 · Alta · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito»
- Ora: «se la prenotazione è **automatica** o **su richiesta** (la segreteria approva ogni richiesta, in ordine di arrivo). **In un evento su richiesta si prenota dalla segreteria**: chi vuole partecipare chiede dalla pagina dell'evento. Se cerca di prenotare in altro modo vede «Questo evento è su richiesta: chiedi di partecipare dalla pagina dell'evento e la segreteria ti risponde.» Non c'è la **lista d'attesa**: se prova a iscriversi vede «Questo evento è su richiesta: la lista d'attesa non c'è, la segreteria decide chi partecipa.» Chi era già in coda **resta dov'è, ma non entra da solo**: nella scheda dell'evento la coda dice «Evento su richiesta: la lista d'attesa non avanza da sola. Prenota tu chi vuoi far entrare.»;»
- Proposta: «se la prenotazione è **automatica** o **su richiesta**. Su richiesta, chi vuole partecipare chiede dalla pagina dell'evento e la segreteria approva in ordine di arrivo. Non c'è **lista d'attesa**: chi era già in lista resta dov'è ma non entra da solo, lo prenoti tu dalla scheda dell'evento;»
- Perché: una voce di elenco di 110 parole con tre messaggi citati; «si prenota dalla segreteria» e subito dopo «chiede dalla pagina dell'evento» sembrano dire due cose opposte.

### supporto/database/guides/part3.php:357 · Media · guida «prenotarsi-a-un-evento-o-a-un-corso-dal-sito»
- Ora: «Il modulo ha un campo trappola, un gettone firmato e un limite di richieste per indirizzo, senza CAPTCHA di terzi. Chi non è socio non ha un pannello: le disdette le gestisce la segreteria.»
- Proposta: «Il modulo si protegge da solo dalle richieste automatiche, senza CAPTCHA di terzi. Chi non è socio non ha un'area personale: per annullare la prenotazione scrive alla segreteria.»
- Perché: «campo trappola» e «gettone firmato» sono gergo; per il glossario una prenotazione si annulla, si disdice solo il rinnovo di un corso.

### supporto/database/guides/part3.php:371 · Bassa · guida «listini-e-pacchetti-per-eventi-e-corsi»
- Ora: «2. Nella riga **Listini** aggiungi un prezzo per ogni listino con **nome**, **prezzo** e numero di **ingressi**. Premi **«+ Aggiungi un prezzo»** per averne altri: uno o più prezzi, quanti ne servono.»
- Proposta: «2. Nella riga **Listini** indica **nome**, **prezzo** e numero di **ingressi** di ogni listino. Per aggiungerne un altro premi **«+ Aggiungi un prezzo»**.»
- Perché: «uno o più prezzi, quanti ne servono» ripete la frase precedente.

### supporto/database/guides/part3.php:385 · Bassa · guida «listini-e-pacchetti-per-eventi-e-corsi»
- Ora: «3. Gli ingressi del pacchetto si consumano a ogni prenotazione. Nel modulo **«Prenota»** di un evento, scelto il socio, la casella dice quale pacchetto ha e quanti ingressi restano: l'ingresso si scala già alla prenotazione.»
- Proposta: «3. Ogni prenotazione consuma un ingresso del pacchetto. Nel modulo **«Prenota»** di un evento, scelto il socio, la casella dice quale pacchetto ha e quanti ingressi restano.»
- Perché: la prima e l'ultima frase dicono la stessa cosa.

### supporto/database/guides/part3.php:418 · Bassa · guida «registrare-gli-ingressi» (righe 418 e 424)
- Ora: «- Con lo **staff dell'evento** abilitato, si può anche incassare il biglietto sul posto (funzione Pro).»
- Proposta: unire alla riga 418: «Con **Pro**, se per l'evento è **abilitato l'incasso**, lo **staff dell'evento** può incassare il contributo sul posto e **addebitare crediti** al socio con il QR, anche se il socio non ha un utente WordPress.» e togliere la riga 424.
- Perché: l'incasso sul posto è spiegato due volte nella stessa guida, a sei righe di distanza.

### supporto/database/guides/part3.php:475 · Media · guida «comunicazioni-a-gruppi»
- Ora: «I primi 25 partono subito, il resto continua in background (circa 25 al minuto). Gli ospiti senza email ricevono tramite il socio che li ospita.»
- Proposta: «I primi 25 partono subito, gli altri poco dopo (circa 25 al minuto). Gli ospiti senza email la ricevono tramite il socio che li ospita.»
- Perché: «in background» è gergo; «ricevono» è senza oggetto.

### supporto/database/guides/part3.php:481 · Bassa · guida «comunicazioni-a-gruppi»
- Ora: «C'è un limite di 20 comunicazioni nelle 24 ore, per proteggere la reputazione del tuo indirizzo di posta.»
- Proposta: «Puoi inviare al massimo 20 comunicazioni in 24 ore: così le tue email non finiscono nello spam.»
- Perché: «reputazione dell'indirizzo di posta» è un concetto tecnico; il beneficio concreto è più chiaro.

### supporto/database/guides/part4.php:11 · Bassa · guida «registrare-un-incasso» (e riga 266)
- Ora: «Importi e voci vengono sempre ricalcolati dal sistema.»
- Proposta: «Il plugin ricalcola sempre importi e voci.»
- Perché: passivo e «sistema» (altrove «plugin»); lo stesso alla riga 266 («quello che il sistema si aspettava» diventa «quello che il plugin si aspettava»).

### supporto/database/guides/part4.php:29 · Media · guida «registrare-un-incasso»
- Ora: «Se annulli un incasso della quota, la **tessera resta** quando c'è **un altro incasso valido** della stessa quota per lo stesso socio e lo stesso anno (per esempio se era stata incassata due volte); se era l'unico incasso, la tessera non è più valida.»
- Proposta: «Se annulli l'incasso di una quota, la tessera non è più valida, a meno che ci sia **un altro incasso valido** della stessa quota per lo stesso socio e anno (per esempio se era stata incassata due volte).»
- Perché: oggi il caso raro viene prima della regola e la frase ha 45 parole.

### supporto/database/guides/part4.php:58 · Media · guida «registrare-una-spesa-con-allegato»
- Ora: «Non vanno nella libreria media del sito: stanno in una cartella privata e si aprono solo da un indirizzo del plugin, con controllo dei permessi.»
- Proposta: «Non vanno nella libreria media del sito: stanno in una cartella privata e li apre solo chi ne ha il permesso.»
- Perché: «da un indirizzo del plugin, con controllo dei permessi» è una descrizione tecnica.

### supporto/database/guides/part4.php:67 · Media · guida «aprire-e-chiudere-gli-anni-solari»
- Ora: «L'**anno fiscale** (l'«esercizio», per il commercialista) è l'anno della contabilità: la prima nota si organizza per anno fiscale, e l'apertura e la chiusura sono disponibili in tutte le versioni. Solo gli amministratori possono aprire, chiudere e riaprire un anno fiscale. Di partenza l'anno fiscale coincide con l'anno solare;»
- Proposta: «L'**anno fiscale** (l'«esercizio», per il commercialista) è l'anno della contabilità: la prima nota è divisa per anno fiscale. Solo gli amministratori possono aprirlo, chiuderlo e riaprirlo. Di partenza coincide con l'anno solare;»
- Perché: «anno fiscale» quattro volte in tre righe; «disponibili in tutte le versioni» è già nella riga «Disponibile in».

### supporto/database/guides/part4.php:78 · Bassa · guida «aprire-e-chiudere-gli-anni-solari» (righe 78-80)
- Ora: «2. Gli anni fiscali si chiudono **in ordine, dal più vecchio**. L'anno fiscale in corso non si chiude finché non è finito.
3. Per chiudere un anno fiscale non devono restare fondi non rimborsati (funzione Pro).»
- Proposta: lasciare numerato solo il passo 1 («Nella stessa pagina premi **Chiudi l'anno** accanto all'anno fiscale.») e mettere le altre due righe in elenco puntato sotto «Prima di chiudere:».
- Perché: i punti 2 e 3 sono condizioni, non azioni.

### supporto/database/guides/part4.php:82 · Media · guida «aprire-e-chiudere-gli-anni-solari»
- Ora: «Si può incassare, spendere e girare denaro solo negli anni fiscali **aperti**. Se provi a modificare un anno chiuso, un messaggio ti dice che per modificarlo va riaperto dalla pagina «Anni fiscali» (si può riaprire solo l'ultimo anno chiuso). Un anno fiscale chiuso non si modifica più: nemmeno allegati, saldi iniziali, cessazioni dei soci. Si bloccano anche **assemblee** (anche non svolte), **votazioni**, **sondaggi** e **verbali** di quell'anno, la **relazione del rendiconto**, la **cessione del posto** e l'eliminazione di eventi e iscrizioni con incassi in quell'anno, l'accantonamento e il rilascio dei **fondi**; il **nome e il tipo di un conto** con movimenti in quell'anno non si cambiano (commissione, chiusura e riapertura del conto sì).»
- Proposta: «Si può incassare, spendere e fare giroconti solo negli anni fiscali **aperti**. Un anno chiuso non si modifica più, nemmeno negli allegati, nei saldi iniziali e nelle cessazioni dei soci: se ci provi, un messaggio ti rimanda alla pagina «Anni fiscali». In un anno chiuso si bloccano anche:

- **assemblee** (anche non svolte), **votazioni**, **sondaggi** e **verbali**;
- la **relazione del rendiconto**;
- la **cessione del posto** e l'eliminazione di eventi e iscrizioni con incassi in quell'anno;
- l'accantonamento e il rilascio dei **fondi**;
- il **nome e il tipo di un conto** con movimenti in quell'anno (commissione, chiusura e riapertura del conto si possono cambiare).»
- Perché: capoverso unico di 115 parole; «girare denaro» è escluso dal glossario (giroconto); la riapertura del solo ultimo anno è spiegata nella sezione «Riaprire un anno fiscale».

### supporto/database/guides/part4.php:86 · Media · guida «aprire-e-chiudere-gli-anni-solari»
- Ora: «Se mancano delle entrate, registrale in **Prima nota** con una data dentro quell'anno; se invece i conti sono giusti, controllali e poi chiudi l'anno: il programma te lo fa confermare con la spunta **«chiudo anche se l'anno è in disavanzo di …»** (con l'importo). Non blocca nulla.»
- Proposta: «Se mancano delle entrate, registrale in **Prima nota** con una data dentro quell'anno. Se invece i conti sono giusti, chiudi l'anno confermando con la spunta **«chiudo anche se l'anno è in disavanzo di …»** (con l'importo). L'avviso non blocca nulla.»
- Perché: «se i conti sono giusti, controllali» è un controsenso; «Non blocca nulla» non ha soggetto.

### supporto/database/guides/part4.php:90 · Media · guida «aprire-e-chiudere-gli-anni-solari»
- Ora: «Per gli anni fiscali chiusi prima di questa funzione compare **«Emetti i documenti definitivi degli anni già chiusi»**: ogni documento porta la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» Non si può annullare.»
- Proposta: «Per gli anni chiusi che non hanno ancora il libro soci definitivo compare **«Emetti i documenti definitivi degli anni già chiusi»**: ogni documento porta la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» L'emissione non si può annullare.»
- Perché: «prima di questa funzione» è una nota di rilascio; «Non si può annullare» non dice cosa.

### supporto/database/guides/part4.php:140 · Media · guida «donazioni-con-paypal»
- Ora: «Dal tuo server non parte nessuna richiesta verso PayPal: il pulsante si apre direttamente nel browser del visitatore.»
- Proposta: «Il tuo sito non comunica con PayPal: la pagina si apre nel browser di chi dona.»
- Perché: «dal tuo server non parte nessuna richiesta» è una frase da programmatore.

### supporto/database/guides/part4.php:150 · Alta · guida «donazioni-con-paypal»
- Ora: «Le donazioni vanno **direttamente sul tuo account PayPal**. **Il conto PayPal lo cambiano solo gli amministratori**: ogni modifica, da qualunque strada arrivi (anche un ripristino di copia), è scritta nel **registro azioni** (con il conto mascherato) e **avvisata per email a tutti gli amministratori**. Se non sei stato tu, cambia subito le password degli amministratori e controlla le impostazioni delle donazioni. Il plugin non le vede: le registri tu in prima nota, con un incasso. Se vuoi, per ogni versamento puoi emettere una ricevuta.»
- Proposta: «Le donazioni vanno **direttamente sul tuo account PayPal**. Il plugin non le vede: registrale tu in prima nota, con un incasso. Se vuoi, per ogni versamento puoi emettere una ricevuta.

**L'account PayPal lo cambiano solo gli amministratori.** Ogni modifica, anche con il ripristino di una copia, resta nel **registro azioni** e viene segnalata per email a tutti gli amministratori. Se non sei stato tu, cambia subito le password degli amministratori e controlla le impostazioni delle donazioni.»
- Perché: l'inciso sulla sicurezza spezza il discorso: «Il plugin non le vede» sembra riferito alle impostazioni o alle password, non alle donazioni. «Account» e «conto» PayPal si alternano; «avvisata per email» non è italiano corretto (si avvisa una persona, non una modifica).

### supporto/database/guides/part4.php:172 · Bassa · guida «attivare-stripe-o-paypal» (e righe 227, 243, 247; part5.php:30)
- Ora: «2. Scegli il gateway: Stripe, PayPal, SumUp (anche combinati), WooCommerce (in alternativa) oppure solo bonifico.»
- Proposta: «2. Scegli il fornitore di pagamento: Stripe, PayPal, SumUp (anche insieme), WooCommerce (in alternativa) oppure solo bonifico.»
- Perché: «gateway» è gergo; nella stessa guida, due righe sotto, è «fornitore». Lo stesso in «Ogni gateway trattiene una commissione» (227), «Le tariffe applicate dal gateway» (243), «l'estratto conto del gateway» (247), «dai gateway di pagamento» (part5:30).

### supporto/database/guides/part4.php:184 · Media · guida «attivare-stripe-o-paypal» (e riga 274)
- Ora: «controlla dal pannello di Stripe e, se serve, **storna** il movimento in prima nota.»
- Proposta: «controlla dal pannello di Stripe e, se serve, **annulla** il movimento in prima nota.»
- Perché: in tutte le altre guide i movimenti «si annullano»; «stornare» fa pensare a un'operazione diversa. Lo stesso alla riga 274.

### supporto/database/guides/part4.php:262 · Bassa · guida «controllare-un-pagamento-non-abbinato» (titolo)
- Ora: «Controllare un pagamento «da controllare» o non abbinato»
- Proposta: «Sistemare un pagamento online «da controllare» o non abbinato»
- Perché: «controllare un pagamento da controllare» è un gioco di parole involontario e manca «online».

### supporto/database/guides/part4.php:274 · Media · guida «controllare-un-pagamento-non-abbinato»
- Ora: «5. Se il pagamento è «da controllare» perché **rimborsato o contestato da Stripe** (compare l'avviso «Pagamenti online da controllare» in Bacheca), il plugin non ha cambiato nulla: guarda nel pannello di Stripe com'è andata e, se serve, **storna** il movimento in prima nota. Questo avviso arriva solo se nel webhook ci sono `charge.refunded` e `charge.dispute.created`, e solo per Stripe.»
- Proposta: «5. Se il pagamento è «da controllare» perché **rimborsato o contestato su Stripe**, il plugin non ha cambiato nulla: guarda nel pannello di Stripe com'è andata e, se serve, **annulla** il movimento in prima nota. L'avviso arriva solo per Stripe e solo con i due eventi `charge.refunded` e `charge.dispute.created` nel webhook.»
- Perché: ripete quasi per intero la nota della guida «attivare-stripe-o-paypal»; un rimborso non è «contestato da Stripe» ma dal cliente, su Stripe.

### supporto/database/guides/part4.php:283 · Media · guida «controllare-un-pagamento-non-abbinato»
- Ora: «Con **bonifico, assegno o contrassegno** la quota risulta pagata solo quando metti l'ordine su **«Completato»**: finché l'ordine non è «Completato» la quota resta da pagare, quindi controlla di averla ricevuta e poi completa l'ordine; se l'importo è diverso entra come «non abbinato».»
- Proposta: riscrivere tutto il capoverso della riga 283 in elenco: «Con WooCommerce l'incasso entra in prima nota una sola volta:» · «- con **carta e PayPal**, quando l'ordine passa a «in lavorazione» o «completato»;» · «- con **bonifico, assegno o contrassegno**, solo quando metti l'ordine su **«Completato»**: prima controlla di aver ricevuto i soldi.» · «Se l'importo è diverso, entra come «non abbinato». Un **secondo ordine** per lo stesso pagamento non si incassa due volte: finisce in **«da controllare»**. Gli ordini senza voci di Associazione Semplice non vengono toccati.» · «Se leggi «Incasso registrato, ma un passo successivo non è riuscito (…): controlla il pagamento.», l'incasso è già in prima nota: controlla soltanto il resto.»
- Perché: capoverso unico di 150 parole con cinque casi; la regola del «Completato» è detta due volte nella stessa frase.

### supporto/database/guides/part4.php:313 · Alta · guida «ricevute-in-pdf»
- Ora: «Le ricevute emesse **prima di questo aggiornamento** si fissano **in automatico** dopo l'aggiornamento: per il loro PDF valgono i dati dell'emissione ma i **testi di oggi** (i tuoi testi personalizzati, le parole scelte per il tipo di ente, la grafica del programma). Quelle emesse da ora in poi restano identiche per sempre.»
- Proposta: «Le ricevute emesse con le **versioni precedenti del plugin** sono state fissate in automatico: il loro PDF ha i dati dell'emissione ma i **testi di oggi** (i tuoi testi personalizzati, le parole scelte per il tipo di ente, la grafica).»
- Perché: «questo aggiornamento» e «da ora in poi» non hanno senso in una guida senza data: chi la legge tra sei mesi non sa di quale aggiornamento si parla.

### supporto/database/guides/part4.php:315 · Media · guida «ricevute-in-pdf»
- Ora: «Le ricevute emesse molto tempo fa, prima che il sito salvasse i dati, non hanno ancora un PDF fisso. Dalla pagina **«Verifica delle ricevute»**, sotto **«Emesse prima che si salvassero i dati»**, l'**amministratore** può fissarle con il pulsante **«Fissa anche queste con i dati di oggi»** (a gruppi di 100), senza aprirle una per una. Il programma avvisa: **«I dati sono quelli di oggi, non quelli dell'emissione.»** Il PDF usa quindi i dati di oggi e da quel momento non cambia più. Conviene farlo prima di una copia di sicurezza. Le ricevute annullate restano come sono: il programma dice «Le … rimaste sono ricevute annullate: restano come sono.»»
- Proposta: «Le ricevute più vecchie, emesse quando il sito non salvava ancora i dati, non hanno un PDF fisso. Dalla pagina **«Verifica delle ricevute»**, sotto **«Emesse prima che si salvassero i dati»**, l'amministratore le fissa a gruppi di 100 con **«Fissa anche queste con i dati di oggi»**: il PDF usa i dati di oggi, non quelli dell'emissione, e da quel momento non cambia più. Conviene farlo prima di una copia di sicurezza. Le ricevute annullate restano come sono.»
- Perché: la stessa informazione («dati di oggi») è data tre volte e due messaggi sono citati per ripeterla.

### supporto/database/guides/part4.php:322 · Bassa · guida «ricevute-in-pdf»
- Ora: «**«Rifai dai dati salvati»** (per un file mancante o diverso) rifà il PDF dai dati salvati all'emissione. Prima chiede conferma: «Rifare questo PDF dai dati salvati all'emissione? Un file diverso resta messo da parte e l'operazione viene scritta nel registro azioni.» Poi compare «PDF rifatto dai dati salvati all'emissione. L'operazione è scritta nel registro azioni.»»
- Proposta: «**«Rifai dai dati salvati»** (per un file mancante o diverso) rifà il PDF dai dati salvati all'emissione, dopo una conferma. Il file diverso resta messo da parte e l'operazione resta nel registro azioni.»
- Perché: «dai dati salvati all'emissione» compare quattro volte in tre righe.

### supporto/database/guides/part4.php:330 · Media · guida «ricevute-in-pdf» (righe 330-332)
- Ora: «L'**annullo** di un incasso annulla subito la ricevuta già emessa, ma **non libera il suo numero**.»
- Proposta: «Se annulli un incasso, anche la ricevuta già emessa viene annullata, ma il suo **numero non si libera**.»
- Perché: «l'annullo» è burocratese e «l'annullo annulla» una ripetizione; alla riga 332 «dopo l'annullo» diventa «dopo l'annullamento».

### supporto/database/guides/part4.php:346 · Bassa · guida «ricevute-in-pdf»
- Ora: «Le vedono solo chi ha pagato e gli amministratori.»
- Proposta: «Le vede solo chi ha pagato, oltre agli amministratori.»
- Perché: accordo sbagliato («le vedono solo chi»).

### supporto/database/guides/part5.php:58 · Bassa · guida «rendiconto-per-cassa-e-report»
- Ora: «i dati sono quelli di quel giorno, non quelli della chiusura.».»
- Proposta: «i dati sono quelli di quel giorno, non quelli della chiusura.»»
- Perché: doppio punto dopo la virgoletta di chiusura.

### supporto/database/guides/part5.php:73 · Media · guida «iva-su-quote-e-incassi» (e part6.php:224, domanda «gestione-iva»)
- Ora: «Se l'ente ha la partita IVA, il plugin la gestisce a un livello minimo, pensato per chi ha un commercialista che completa i quadri.»
- Proposta: «Se l'ente ha la partita IVA, il plugin gestisce l'IVA in modo essenziale: registra aliquote e importi, il resto lo completa il commercialista.»
- Perché: «la gestisce» sembra riferito alla partita IVA; «a un livello minimo» e «completa i quadri» non dicono cosa fa il plugin. In part6:224 la stessa frase («Sì, a un livello minimo pensato per chi ha un commercialista che completa i quadri, con **{piano_fiscale}**.») diventa «Sì, con **{piano_fiscale}**, in modo essenziale: il resto lo completa il commercialista.»

### supporto/database/guides/part5.php:92 · Media · guida «iva-su-quote-e-incassi»
- Ora: «Il plugin calcola lo scorporo mantenendo sempre la somma esatta.»
- Proposta: «Imponibile e IVA, sommati, danno sempre l'importo esatto al centesimo.»
- Perché: «scorporo» è gergo contabile non spiegato.

### supporto/database/guides/part5.php:101 · Media · guida «cinque-per-mille»
- Ora: «Il 5 per mille è spento di default e lo attiva un amministratore.»
- Proposta: «Qui chiedi il 5 per mille ai soci, registri i contributi ricevuti e tieni d'occhio la scadenza del rendiconto. La funzione è spenta di partenza: la attiva un amministratore.»
- Perché: l'apertura non dice a cosa serve la funzione; è più lunga perché manca proprio questa informazione.

### supporto/database/guides/part5.php:140 · Bassa · guida «assicurazioni-e-presenze»
- Ora: «3. Il riepilogo per periodo mostra presenze, lezioni e percentuale; il PDF è a matrice (fino a 10 lezioni) o riepilogativo.»
- Proposta: «3. Il riepilogo per periodo mostra presenze, lezioni e percentuale; il PDF ha una colonna per lezione (fino a 10 lezioni) oppure solo i totali.»
- Perché: «a matrice» e «riepilogativo» sono termini tecnici.

### supporto/database/guides/part5.php:162 · Bassa · guida «livelli-di-socio-e-nucleo-familiare» (righe 162-163)
- Ora: «una **quota propria** facoltativa: se vuota, vale la quota predefinita.»
- Proposta: «una **quota propria** facoltativa: se vuota, vale la quota predefinita;» e, alla riga 163, chiudere l'ultima voce con il punto («I minorenni non votano mai.»).
- Perché: punteggiatura dell'elenco invertita: il punto è sulla penultima voce e il punto e virgola sull'ultima.

### supporto/database/guides/part5.php:166 · Media · guida «livelli-di-socio-e-nucleo-familiare»
- Ora: «Chi non ha un livello assegnato usa il nome della base e la quota predefinita: nessuna migrazione dei soci esistenti. Un livello con dei soci non si cancella: resta disattivato.»
- Proposta: «Chi non ha un livello assegnato usa il nome della base e la quota predefinita: per i soci che hai già non devi cambiare nulla. Un livello con dei soci non si elimina: si disattiva.»
- Perché: «migrazione» è gergo; «non si cancella: resta disattivato» non dice che è l'utente a disattivarlo.

### supporto/database/guides/part5.php:207 · Media · guida «app-installabile-e-notifiche»
- Ora: «Per la privacy l'app **non salva mai pagine con dati personali**: offre soltanto una pagina «Sei offline», con il pulsante **«Riprova»**, e gli stili del plugin. L'app **non interferisce con l'amministrazione né con l'accesso del sito**: su wp-admin e sulla pagina di login non compaiono blocchi o pagine offline. Spegnendo l'app, scompare da sola dai dispositivi.»
- Proposta: «Per la privacy l'app **non salva mai pagine con dati personali**: senza connessione mostra solo la pagina «Sei offline», con il pulsante **«Riprova»**. Non tocca l'amministrazione né la pagina di accesso del sito. Se spegni l'app, scompare da sola dai dispositivi.»
- Perché: «gli stili del plugin» e «wp-admin» sono gergo; la frase sui «blocchi» è la correzione di un vecchio difetto, non un'istruzione.

### supporto/database/guides/part5.php:226 · Bassa · guida «tessera-nel-wallet» (righe 226-227)
- Ora: «2. Carica le credenziali Apple e/o Google.»
- Proposta: «2. Carica le credenziali di Apple, di Google o di entrambi.»
- Perché: «e/o» è scrittura da modulo; lo stesso alla riga 227 («**Aggiungi ad Apple Wallet** e/o **Salva su Google Wallet**» diventa «**Aggiungi ad Apple Wallet**, **Salva su Google Wallet** o entrambi»).

### supporto/database/guides/part5.php:296 · Bassa · guida «anonimizzare-un-ex-socio-e-scaricare-i-dati» (titolo)
- Ora: «Anonimizzare un ex socio e scaricare i propri dati»
- Proposta: «Anonimizzare un ex socio e scaricare i dati di una persona»
- Perché: «i propri dati» sembra riferito all'ex socio o a chi legge; la guida spiega lo scarico dei dati di qualunque persona.

### supporto/database/guides/part6.php:20 · Media · domanda «quali-funzioni-sono-gratuite»
- Ora: «**Area riservata** per i soci, con avvisi, regolamento, tessera, eventi e corsi»
- Proposta: «**Area soci**, con avvisi, regolamento, tessera, eventi e corsi»
- Perché: in tutte le guide la pagina dei soci è l'«area soci»; «area riservata» è l'account sul portale.

### supporto/database/guides/part6.php:59 · Media · domanda «differenza-tra-contabile-e-fiscale»
- Ora: «Se la tua associazione non ha partita IVA e un commercialista che richiede questi documenti, {piano_contabile} è di solito sufficiente.»
- Proposta: «Se la tua associazione non ha la partita IVA né un commercialista che chiede questi documenti, di solito basta {piano_contabile}.»
- Perché: con «non ha … e» non si capisce se la negazione vale anche per il commercialista.

### supporto/database/guides/part6.php:89 · Media · domanda «cosa-succede-se-la-licenza-pro-scade»
- Ora: «I pagamenti online già avviati si chiudono comunque, così gli incassi in arrivo si registrano.»
- Proposta: «I pagamenti online già avviati arrivano comunque a buon fine e l'incasso viene registrato.»
- Perché: «si chiudono» può voler dire che vengono interrotti, cioè il contrario.

### supporto/database/guides/part6.php:110 · Media · domanda «quali-dati-escono-dal-sito»
- Ora: «il plugin contatta il sito associazionesemplice.it e gli invia **solo il nome del prodotto e la versione installata** (di solito una volta al giorno, anche senza chiave). Non invia la chiave, il dominio né dati dei soci. Si spegne da **Impostazioni → Limiti e soglie**, togliendo il segno da «Aggiornamenti diretti»; il testo completo è in **Strumenti → Privacy**. Con Pro attivo la richiesta porta anche la chiave e il dominio.»
- Proposta: «il plugin contatta il sito associazionesemplice.it, di solito una volta al giorno e anche senza chiave, e gli invia **solo il nome del prodotto e la versione installata**; con Pro attivo, anche la chiave e il dominio. Mai dati dei soci. Si spegne da **Impostazioni → Limiti e soglie**, togliendo la spunta da «Aggiornamenti diretti»; il testo completo è in **Strumenti → Privacy**.»
- Perché: prima dice «non invia la chiave, il dominio», tre frasi dopo «porta anche la chiave e il dominio»; «togliendo il segno» altrove è «togli la spunta».

### supporto/database/guides/part6.php:121 · Bassa · domanda «cosa-succede-ai-dati-se-elimino-il-plugin»
- Ora: «Di default **restano nel database** del sito, anche se disattivi o elimini il plugin.»
- Proposta: «**Restano sul sito**, anche se disattivi o elimini il plugin, a meno che tu scelga di cancellarli.»
- Perché: «di default» e «database» sono gergo nella prima riga di una domanda frequente.

### supporto/database/guides/part6.php:157 · Media · domanda «quando-scade-la-tessera» (righe 157-161)
- Ora: «Negli **ultimi giorni prima della scadenza** (quelli dell'avviso di scadenza, **30** di partenza) si può **rinnovare in anticipo**. L'anno fiscale è anche quello delle **quote** e degli **incassi**, iscrizioni comprese; l'**anno delle attività** è invece solo il calendario di corsi ed eventi e non c'entra con la tessera.»
- Proposta: tenere il primo capoverso (riga 157), accorciare il secondo a «Negli ultimi giorni prima della scadenza si può rinnovare in anticipo. L'**anno delle attività** (corsi ed eventi) non c'entra con la tessera.» e sostituire tutto il terzo (riga 161, cambio del mese) con «Per il rinnovo e per cosa succede se cambi il mese dell'anno fiscale vedi [Iscrivere un socio e rinnovare la tessera](guida:iscrivere-un-socio-e-rinnovare-la-tessera).»
- Perché: la risposta copia parola per parola tre capoversi della guida (part2 59-63): una domanda frequente deve rispondere in poche righe e rimandare. Attenzione: il link `guida:inizio-dell-anno-fiscale` oggi presente alla riga 161 uscirebbe dalla risposta; se va tenuto, lasciarlo al posto di quello proposto.

### supporto/database/guides/part6.php:181 · Media · domanda «pagamenti-con-carta»
- Ora: «Nel **plugin gratuito** puoi comunque indicare le coordinate del bonifico, che le modificano solo gli amministratori: ogni modifica, anche con un ripristino di copia, è scritta nel registro azioni (con l'IBAN mascherato) e avvisata per email a tutti gli amministratori; registrare a mano contanti e bonifici e ricevere **donazioni** con un modulo che porta alla pagina di PayPal.»
- Proposta: «Nel **plugin gratuito** puoi comunque indicare le coordinate del bonifico, registrare a mano contanti e bonifici e ricevere **donazioni** con un modulo che porta alla pagina di PayPal. Le coordinate le modificano solo gli amministratori: ogni modifica resta nel registro azioni e viene segnalata per email a tutti gli amministratori.»
- Perché: l'inciso sulla sicurezza spezza l'elenco delle tre cose che si possono fare, e «che le modificano» è sgrammaticato.

### supporto/database/guides/part6.php:215 · Bassa · domanda «come-emettere-una-ricevuta»
- Ora: «Con i pagamenti online il socio la riceve da solo.»
- Proposta: «Con i pagamenti online il socio la riceve in automatico.»
- Perché: «da solo» sembra riferito al socio.

### supporto/database/guides/part7.php:11 · Bassa · guida «bacheca-da-lavorare-ed-eventi-della-settimana»
- Ora: «Appena entri nella **Bacheca di WordPress** (la pagina iniziale di `wp-admin`) trovi due riquadri con ciò che ti serve oggi.»
- Proposta: «Appena entri nella **Bacheca di WordPress** (la prima pagina dopo l'accesso) trovi due riquadri con ciò che ti serve oggi.»
- Perché: `wp-admin` è un nome tecnico che un volontario non conosce.

### supporto/database/guides/part7.php:20 · Media · guida «bacheca-da-lavorare-ed-eventi-della-settimana»
- Ora: «Per ogni scheda il plugin controlla codice fiscale, data e luogo di nascita e residenza (indirizzo **e** comune). Per un **minorenne** serve almeno un genitore o tutore, con l'email del tutore che opera sul sito e il codice fiscale di ciascun genitore; per un maggiorenne (o senza data di nascita) serve almeno **email o telefono**. L'elenco mostra le voci mancanti scheda per scheda. Ospiti, genitori non soci, soci receduti o anonimizzati e domande in attesa non sono controllati, e la liberatoria non conta perché è facoltativa;»
- Proposta: «Il plugin controlla codice fiscale, data e luogo di nascita, residenza (indirizzo **e** comune) e un contatto: per un maggiorenne **email o telefono**, per un **minorenne** un genitore o tutore con la sua email e il codice fiscale di ciascun genitore. L'elenco dice cosa manca, scheda per scheda. Non sono controllati ospiti, genitori non soci, soci receduti o anonimizzati e domande in attesa;»
- Perché: una voce di elenco di 110 parole in mezzo a voci di una riga; la nota sulla liberatoria risponde a una domanda che nessuno si fa.

### supporto/database/guides/part7.php:77 · Bassa · guida «creare-e-gestire-le-risorse»
- Ora: «Una risorsa è **esclusiva**: o è libera o è occupata. Non c'è una capienza, perché ospita una prenotazione alla volta.»
- Proposta: «Una risorsa ospita **una prenotazione alla volta**: o è libera o è occupata.»
- Perché: la stessa cosa è detta tre volte («esclusiva», «libera o occupata», «una alla volta»).

### supporto/database/guides/part7.php:105 · Bassa · guida «creare-e-gestire-le-risorse»
- Ora: «Lo usano gli amministratori e chi opera (segreteria e consiglieri con accesso operativo).»
- Proposta: «Lo usano gli amministratori, la segreteria e i consiglieri con accesso operativo.»
- Perché: «chi opera» è un'etichetta interna spiegata subito dopo tra parentesi.

### supporto/database/guides/part7.php:140 · Bassa · guida «prenotare-una-risorsa»
- Ora: «Per le risorse **assegnate a periodo** (posto auto, armadietto) non c'è una prenotazione del socio: lo assegna la segreteria, con lista d'attesa.»
- Proposta: «Le risorse **assegnate a periodo** (posto auto, armadietto) non si prenotano: le assegna la segreteria, con lista d'attesa.»
- Perché: «lo assegna» non concorda con «le risorse».

### supporto/database/guides/part7.php:160 · Media · guida «collegare-attivita-e-risorse-con-allestimento» (righe 158-160)
- Ora: «Gli eventi già salvati scelgono la risorsa alla prima modifica.»
- Proposta: «Per gli eventi creati prima, la risorsa ti viene chiesta alla prima modifica.»
- Perché: non sono gli eventi a scegliere. Alla riga 158 «così i calendari si incrociano» si capisce meglio come «così il plugin vede le sovrapposizioni».

### supporto/database/guides/part7.php:198 · Media · guida «collegare-attivita-e-risorse-con-allestimento»
- Ora: «Le sovrapposizioni **già presenti** prima dell'aggiornamento non cambiano da sole: in Bacheca compare l'avviso **«Risorse occupate due volte»** e vanno sistemate a mano, modificando le date o la risorsa di una delle due attività.»
- Proposta: «Se in Bacheca compare l'avviso **«Risorse occupate due volte»**, ci sono sovrapposizioni nate prima di questi controlli: sistemale a mano, modificando le date o la risorsa di una delle due attività.»
- Perché: «prima dell'aggiornamento» non dice quale; meglio partire da ciò che l'utente vede (l'avviso).

## Da verificare (sospetti errori di fatto, non riscritti)

- **Voce di menu «Area riservata»** (part1.php:93; part2.php:232): la voce che il plugin aggiunge al menu del sito per la pagina «Area soci» si chiama davvero «Area riservata»? Se sì, il nome a schermo contraddice il glossario e va cambiato nel plugin; se no, va corretta la guida.
- **Percorsi di menu diversi per la stessa pagina.** «Impostazioni → Ente → Dati dell'ente» (part2:59; part6:157) e «Impostazioni → Ente e fiscalità → Dati e fiscalità» (part5:77, 272); «Impostazioni → Soci e quote» (part2:118; part3:286) e «Impostazioni → Soci e identità» (part2:174, 203, 242; part5:225); «Impostazioni → Limiti» (part3:336) e «Impostazioni → Limiti e soglie» (altrove); «Corsi ed eventi» (part3:15, 134) e «Attività» (part3:292, 370); «Soci → Importa da Excel/CSV» (part2:24; part4:121), «Rubrica soci → …» (part2:161; part3:466), «Rubrica» (part5:285; part7:33) ed «elenco persone» (part2:112); «Cassa → Anni fiscali» e «Contabilità → Anni fiscali» (part4:71, dichiarato come differenza tra piani); «Impostazioni → Ospiti» (part2:111), «Impostazioni → Assicurazioni» (part5:131), «Impostazioni → Livelli di socio» (part5:158), «Impostazioni → Sistema → Azzeramento dati» (part1:186) rispetto a «Strumenti → Copia di sicurezza». Vanno controllati uno per uno sul plugin attuale.
- **Installazione dalla ricerca di WordPress** (part1.php:16): «Vai su **Plugin → Aggiungi nuovo** e cerca «Associazione Semplice»» vale solo se il plugin è già pubblicato su WordPress.org.
- **Copia di sicurezza, passi 2 e 3** (part1.php:141-142): il passo 2 nomina il pulsante «Scarica la copia con gli allegati…», il passo 3 dice «premi il pulsante per creare la copia». Sono due pulsanti (con e senza allegati) o uno solo? La guida non nomina quello senza allegati.
- **«La frase della copia»** (part1.php:160): la guida dice che è «il codice di licenza o, nel gratuito, la data e l'ora in cui era stata creata la chiave dei file (in UTC…)» ma non dice dove l'utente la legge sul sito di partenza. Senza quell'indicazione il capoverso non è utilizzabile.
- **Fornitori di pagamento elencati in modo diverso.** La configurazione guidata elenca «nessuno, Stripe, PayPal, WooCommerce o bonifico» (part1:52) senza SumUp; le commissioni citano anche **Satispay** (part4:232-241), che non compare in nessun altro elenco (part4:172; part6:33, 179); «I dati della carta» nomina solo Stripe e PayPal (part4:161; part6:188) anche se c'è SumUp.
- **Ruoli** (part2.php:144-151): l'elenco non ha né «Dipendente» (citato alla riga 161) né i «consiglieri con accesso operativo» (part2:124; part7:11, 105; part6:21). Mancano o hanno un altro nome?
- **«Approva e manda il link: si crea l'utente»** (part2.php:274): le altre guide dicono che i nuovi soci non hanno un utente WordPress. Qui si crea davvero un utente o solo l'accesso?
- **«ogni modifica seleziona la tessera personalizzata»** (part2.php:217): la frase non si capisce (salvando si passa dalla tessera standard a quella personalizzata?). Va riscritta da chi conosce il comportamento.
- **«Quota per il rimborso del volontario», «quota del volontario»** (part2.php:126-131): per il glossario «quota» è solo la quota associativa. Se è l'etichetta a schermo va cambiata nel plugin prima che nella guida.
- **«qualsiasi sport scritto in modo sensato»** (part3.php:44): il passo 2 (riga 31) parla di un menu a scelta. Dove si scrive uno sport fuori elenco, e quale controllo fa il plugin?
- **Nome della forma di pagamento** (part3.php:11, 51, 52): a schermo è «Pagamento unico» o «una tantum»? La guida usa tutti e due.
- **«Apri l'area volontari»** (part3.php:437): questo nome compare solo qui; le altre guide parlano di area soci e delle pagine Segreteria, Tesoriere, Ingressi.
- **Donazioni** (part4.php:144-146): «Vai nelle impostazioni delle **donazioni** del plugin» non dà il percorso e «lo shortcode dedicato» non dà il nome, a differenza di tutte le altre guide.
- **«Il responsabile del trattamento è il presidente»** (part5.php:276): nel GDPR il «responsabile del trattamento» è chi tratta i dati per conto del titolare (di solito un fornitore esterno); il titolare è l'associazione. Da far controllare al consulente privacy, anche nel testo dell'informativa generata.
- **Aliquote** (part5.php:79): «(22, 10, 5, 4 o 0 %)» ha lo spazio prima di «%», le altre percentuali nelle guide no («1,5%», «-25%»).
- **Nomi dei piani scritti a mano** (part6.php:179): «con **Pro** (Contabile o Fiscale)» non usa i segnaposto {piano_contabile} e {piano_fiscale}: se i nomi dei piani cambiano dal pannello, qui restano quelli vecchi.
- **«scelte»** (part5.php:116): «inserisci anno di imposta, importo, scelte e data di accredito»: se «scelte» è il numero di firme ricevute, conviene dirlo.

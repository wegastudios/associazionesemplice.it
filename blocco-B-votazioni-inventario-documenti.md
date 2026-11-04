# Blocco B — testo per le guide di supporto

Tre funzioni nuove nel plugin **gratuito** (Associazione Semplice 1.2.x). Si trovano in **Registri**, accanto a «Libro soci» e «Verbali»: schede **Votazioni**, **Inventario**, **Documenti**. Le usano la segreteria, il presidente, il vicepresidente e gli amministratori.

## Votazioni e sondaggi

**A cosa serve.** Far votare i soci su una decisione (votazione) o raccogliere pareri (sondaggio), senza fogli e riunioni.

**Come si crea.** Registri → Votazioni → «Nuova votazione». Si scrivono titolo, domanda e le risposte (una per riga, da 2 a 20). Si sceglie quando si apre e quando si chiude, se si può dare una sola risposta o più, e se il voto è segreto.

**Chi può votare.** Tutti i soci in regola con la tessera, oppure solo gli iscritti a un'attività. Non votano: genitori e tutori che non sono soci, ospiti, soci sospesi, soci usciti, soci con la tessera scaduta.

**Come votano i soci.** Dall'area riservata, nella sezione «Votazioni e sondaggi». Si vota una volta sola e non si può cambiare. Si può mettere la sezione anche in una pagina con `[associazionesemplice_votazioni]`.

**Voto segreto.** Il sito ricorda *chi* ha votato (così nessuno vota due volte) ma non *cosa* ha scelto. Nemmeno la segreteria può risalirci. Nel voto palese la segreteria vede i nomi accanto a ogni risposta.

**Risultati.** La segreteria li vede quando vuole, anche a votazione aperta (sono provvisori). I soci li vedono solo dopo la chiusura e solo se la segreteria ha spuntato «Dopo la chiusura i soci vedono i risultati». Si scaricano il **verbale dei risultati in PDF** (con le firme di segretario e presidente) e la tabella in CSV.

**Avvisi ai soci.** Con «Avvisa i soci per email» e i promemoria attivi (Impostazioni → Privacy e regolamento), parte un'email all'apertura e un promemoria nelle ultime 48 ore, solo a chi non ha ancora votato. «Avvisa i soci ora» la manda subito.

**Cose da sapere.** Dopo il primo voto non si cambiano più risposte, tipo di scelta, voto segreto e chi può votare: si possono cambiare titolo, chiusura e avvisi. Se serve cambiare altro, si crea una nuova votazione.

## Inventario e prestiti

**A cosa serve.** Tenere l'elenco di ciò che l'associazione possiede (casse, proiettore, tavoli…) e sapere chi ha cosa in prestito. **Non** è il sistema delle Risorse prenotabili (sale e attrezzature da prenotare, solo Pro): qui è un registro dei beni.

**Beni.** Registri → Inventario → «Nuovo bene»: nome, categoria, codice (unico, facoltativo), quantità, stato, ubicazione; facoltativi valore e data d'acquisto, foto o allegato. Si esporta tutto in CSV.

**Prestiti.** Dalla scheda del bene: si sceglie il socio o volontario, i pezzi, la data di uscita e quella di rientro previsto, e si possono annotare le condizioni. Non si prestano più pezzi di quelli disponibili, né un bene da riparare, perso o dismesso. Al rientro: «Rientrato», con data, note sullo stato e, se il bene si è rovinato, il nuovo stato. Lo storico si vede per bene e per persona.

**Rientri scaduti.** Il prestito non rientrato diventa scaduto il giorno dopo la data prevista. Compare nel riquadro «Prestiti da recuperare» della Bacheca e nel widget «Da lavorare» di WordPress, e a chi ha il bene arriva un promemoria per email (una volta per prestito; si attiva da Impostazioni → Privacy e regolamento → «Prestiti»).

**Privacy.** Un socio con beni in prestito non si può anonimizzare finché non li riporta. I suoi prestiti compaiono nell'esportazione dei suoi dati.

## Archivio documenti

**A cosa serve.** Tenere in un posto solo statuto, regolamenti, verbali firmati e moduli, decidendo chi li può leggere.

**Come si carica.** Registri → Documenti → «Carica un documento»: titolo, categoria (si sceglie o si scrive), data, descrizione, file e **chi lo vede**:
- *Solo segreteria*: lo vede solo chi gestisce il plugin;
- *Soci*: i soci in regola, dall'area riservata (sezione «Documenti», oppure `[associazionesemplice_documenti_soci]`);
- *Pubblico*: chiunque, con lo shortcode `[associazionesemplice_documenti]` (si può limitare a una categoria: `[associazionesemplice_documenti categoria="Moduli"]`) o con il blocco Associazione Semplice.

**File ammessi.** PDF, foto (JPG, PNG, WebP), Word, Excel, LibreOffice e testo, fino al limite «Peso massimo di un allegato» (10 MB di norma).

**Sicurezza.** I file sono in una cartella protetta e **non hanno un indirizzo diretto**: ogni apertura passa da un controllo dei permessi. Chi non ha il diritto vede un errore; chi non ha fatto l'accesso viene mandato al login (tranne per i documenti pubblici).

**Eliminare.** «Elimina» toglie il documento dagli elenchi; il file resta sul sito (come gli allegati dei movimenti). Per sostituire il file di un documento, lo si apre e si sceglie un nuovo file.

## Domande frequenti

- *Un socio dice di non vedere la votazione.* Controllare: tessera valida, non sospeso, non uscito; se la votazione è per gli iscritti di un'attività, che sia iscritto; che la votazione sia già aperta. I genitori/tutori non soci non votano.
- *Si può votare per delega?* No: ogni socio vota dal proprio accesso.
- *Si possono riaprire i voti o correggerli?* No. Per ripartire si crea una nuova votazione.
- *Il verbale dei risultati vale come verbale dell'assemblea?* È un documento di supporto con i risultati e lo spazio per le firme; il verbale dell'assemblea resta quello di Registri → Verbali.
- *Dove sono i dati nella copia di sicurezza?* Tutto è incluso (tabelle e file); l'azzeramento dei dati cancella anche questi.

# Blocco F — testo per le guide di supporto

Una funzione nuova nel plugin **gratuito** (Associazione Semplice 1.2.x): le **convenzioni per i soci**. Si trova nel gruppo Registri del menu di WordPress: **Associazione Semplice → Registri → Convenzioni**. La usano la segreteria, il presidente, il vicepresidente e gli amministratori.

## A cosa serve

Raccogliere in un posto solo gli sconti e i vantaggi che negozi, palestre, teatri e altri enti partner riservano ai soci, e farli vedere ai soci giusti, al momento giusto, nell'area riservata.

## Come si crea una convenzione

Registri → Convenzioni → «Nuova convenzione». I campi:

- **Esercente o ente partner** (obbligatorio);
- **Categoria**: si sceglie dall'elenco o se ne scrive una nuova. L'elenco si gestisce in fondo alla pagina «Convenzioni» (una categoria per riga);
- **Vantaggio** (obbligatorio): per esempio «Sconto del 10%», «Prezzo agevolato 8 €», «Un caffè in omaggio»;
- **Condizioni d'uso**: compaiono solo ai soci;
- **Valida dal / al**: facoltative. L'ultimo giorno è compreso. Senza data di fine la convenzione non scade;
- **Indirizzo, telefono, email, sito**: facoltativi. Il sito si può scrivere anche senza «https://»;
- **Logo**: un'immagine scelta dalla libreria media di WordPress;
- **A chi spetta**: tutti i soci in regola, oppure solo alcuni tipi (socio fondatore, ordinario, socio e volontario);
- **Visibile anche al pubblico**: sì/no;
- **Codice convenzione**: facoltativo, lo mostra il socio all'esercente.

Dalla scheda si può anche **archiviare** la convenzione (esce dall'area soci ma resta in elenco, e si può riportare indietro) oppure **eliminarla**.

## Quando una convenzione è visibile

Una convenzione è «in corso» se non è archiviata, è già iniziata e non è finita. Solo quelle in corso si vedono ai soci e sul sito. Quando passa il giorno di fine la convenzione **sparisce da sola** dall'area soci, ma resta nella pagina di amministrazione con lo stato «Scaduta» (si può filtrare per stato). Per riattivarla basta cambiare la data di fine.

Nella Bacheca compare il riquadro **«Convenzioni in scadenza»** con quelle che finiscono nei prossimi 30 giorni.

## Cosa vedono i soci

- **Nell'area riservata**, sezione **Convenzioni** (oppure con lo shortcode `[convenzioni_soci]` in una pagina): vedono le convenzioni in corso che spettano a loro. Serve essere un socio con la **tessera valida oggi** e del tipo previsto. Un socio sospeso, con la tessera scaduta o uscito non le vede. **Gli ospiti e i genitori o tutori che non sono soci non le vedono**: i tutori non le vedono nemmeno per i figli minori.
- Si può filtrare per **categoria**.
- Aprendo «Come usarla» il socio trova il **codice convenzione** (se c'è), le **condizioni**, i contatti dell'esercente e la riga per farsi riconoscere: **nome e cognome, numero di tessera e scadenza del tesseramento**. Può mostrarla all'esercente insieme alla tessera digitale.

## Elenco pubblico sul sito

Lo shortcode **`[convenzioni]`** mostra a tutti le convenzioni segnate «Visibile anche al pubblico» e in corso: nome, logo, categoria e vantaggio in breve. Per una sola categoria: `[convenzioni categoria="Sport e benessere"]`. **Non** mostra il codice, le condizioni né i contatti. Gli stessi shortcode esistono anche con il prefisso: `[associazionesemplice_convenzioni]` e `[associazionesemplice_convenzioni_soci]`.

Le pagine con questi shortcode non vengono messe nella cache di pagina, così le convenzioni scadute non restano visibili.

## Avviso ai soci

Nella scheda della convenzione il pulsante **«Avvisa i soci della nuova convenzione»** manda un'email a tutti i soci a cui spetta (tessera valida e tipo previsto) con il vantaggio e il rimando all'area riservata. Si può fare **una sola volta per convenzione** e solo se la convenzione è in corso; ogni socio la riceve una volta sola. Prima dell'invio la pagina dice quanti soci hanno un indirizzo email. L'avviso non dipende dall'interruttore «Promemoria attivi» di Impostazioni → Privacy e regolamento. Per un minore l'email va al tutore.

## Esportazione

Nella pagina «Convenzioni», il pulsante **Esporta (CSV)** scarica tutte le convenzioni (anche scadute e archiviate), con codice e contatti. Il file si apre in Excel.

## Impostazioni e dati

- Non ci sono impostazioni da attivare: la funzione è sempre disponibile nel gratuito.
- L'elenco delle categorie è salvato nell'opzione `asem_convention_categories` ed è incluso nella copia di sicurezza, come la tabella `conventions`. L'azzeramento dei dati cancella anche le convenzioni.
- Il database passa alla versione 44 (si aggiorna da solo).

## Domande frequenti

- *Un socio dice di non vedere nessuna convenzione.* Controllare: tessera valida oggi, socio non sospeso né uscito, che esistano convenzioni in corso per il suo tipo. Gli ospiti e i tutori non vedono mai le convenzioni.
- *La convenzione è scaduta ma serve ancora.* Aprirla e spostare la data di fine (oppure toglierla); se è archiviata, «Riporta in elenco».
- *Si può mandare di nuovo l'avviso?* No, parte una sola volta per convenzione. Per un'altra comunicazione usare le comunicazioni ai soci.
- *Il logo non si vede.* Deve essere un'immagine della libreria media; i file di altro tipo vengono ignorati.
- *Il pubblico può vedere il codice?* No: l'elenco pubblico non mostra mai codice, condizioni né contatti.

# SaaS: builder dei siti

Il pacchetto **Sito**: un sito generato interamente dall'AI. Il cliente non ha strumenti grafici: tocca solo **testi
e allegati**, e può guidare l'AI a parole. Architettura generale in [SAAS_ARCHITETTURA.md](SAAS_ARCHITETTURA.md),
pacchetti in [SAAS_PERIMETRO.md](SAAS_PERIMETRO.md).

## 1. Il principio: l'AI compila una specifica, non scrive HTML

L'AI produce una **specifica JSON** del sito (tema, pagine, sezioni scelte da un catalogo, testi, riferimenti alle
immagini), validata contro uno schema rigido. Un **generatore nostro, deterministico**, la trasforma in HTML statico.

- **Sicurezza**: niente codice imprevedibile; istruzioni malevole nei testi possono al massimo cambiare la specifica,
  che resta comunque validata.
- **Qualità costante**: accessibilità, velocità e SEO sono garantite dai componenti.
- **Costi bassi e prevedibili**: una generazione per sito, poi solo le sezioni toccate.

## 2. Flusso

1. **Raccolta**, quasi tutta automatica:
   - dal gestionale: nome, tipo di ente, sede, contatti pubblici, attività ed eventi con descrizioni, date, orari,
     luoghi, prezzi; documenti pubblici;
   - dal cliente: logo, foto, locandine, e un questionario di 3–4 domande (a chi vi rivolgete, tono, cosa vi distingue);
   - **mai i dati personali dei soci.**
2. **Generazione**: lavoro in coda, chiamata all'AI con output strutturato vincolato allo schema.
3. **Anteprima** completa su un indirizzo provvisorio.
4. **Ritocchi**:
   - testi modificati direttamente sull'anteprima; foto, locandine e allegati sostituibili;
   - **"Rigenera con un'indicazione"**: il cliente guida l'AI a parole ("più sobrio", "metti in evidenza i corsi per
     bambini"), sull'intero sito o su una sezione;
   - **richieste di pagine nuove** a parole ("aggiungi una pagina sui campi estivi"): l'AI decide struttura e
     sezioni.
5. **Pubblicazione** con un clic; ogni versione resta salvata, il ritorno indietro è immediato.

I testi modificati a mano diventano **bloccati**: le rigenerazioni non li toccano. Un certo numero di rigenerazioni al
mese è incluso nel pacchetto.

## 3. Catalogo dei componenti

Circa 25–30 sezioni, ognuna con 2–3 varianti grafiche.

| Tipo | Sezioni |
|---|---|
| Presentazione | apertura con immagine e frase, chi siamo, testo e immagine, numeri, citazione |
| **Dal gestionale** | eventi in evidenza (schede con locandina verticale), calendario eventi con filtri, corsi e attività, orari, sedi, documenti pubblici, trasparenza |
| **Pagine generate** | una pagina per ogni evento e per ogni corso, con data, orario, luogo, prezzo, posti, locandina |
| **Con l'Area soci** | iscriviti, rinnova la tessera, prenota, accedi all'area soci |
| Spazi | sale e spazi con foto, capienza, uso possibile e richiesta di informazioni |
| Comunità | invito a unirsi ("scopri, crea, partecipa"), canali (WhatsApp, Telegram, newsletter), social |
| Sostegno | sostienici, donazioni, 5×1000 con il codice fiscale, sponsor e partner |
| Altro | **notizie** (se il cliente le vuole: solo testi e foto), galleria, vetrina (per esempio opere dei soci), domande frequenti, contatti con mappa |
| Sempre presenti | intestazione e menu, piè di pagina con i dati legali (codice fiscale, partita IVA, sede) |

**Tema**
- Colori ricavati dal logo in modo automatico; l'AI sceglie tra **palette già verificate** per il contrasto
  (accessibilità AA garantita).
- Caratteri da una rosa curata di abbinamenti.
- Un unico CSS precompilato per tutti i siti, con il tema passato tramite variabili CSS: nessuna compilazione per
  singolo sito.

**Sito di riferimento**: [sognaragazzosogna.it](https://sognaragazzosogna.it/) (associazione culturale a Napoli). Menu
con attività (calendario, corsi), sale, associazione (diventa socio), contatti, area riservata. Home: frase di apertura,
eventi in evidenza come locandine verticali, chi siamo, corsi, sale, calendario, invito a unirsi, comunità e attività
con i soci, piè di pagina con contatti, social, canale WhatsApp e "Sostienici". Il catalogo deve poterlo riprodurre
per intero, sia nei contenuti sia nel tipo di grafica.

## 4. Il gestionale tiene il sito aggiornato

- **Contenuti stabili** (eventi, corsi, orari, documenti, contributi pubblici): quando cambiano nel gestionale, le
  pagine interessate si **rigenerano da sole** in coda. Il sito resta statico e sempre aggiornato.
- **Contenuti vivi** (posti disponibili, moduli di iscrizione, accesso all'area soci): piccoli blocchi JavaScript
  sull'API, solo con il pacchetto Area soci.
- **SEO**: sitemap, dati strutturati per associazione, eventi e luoghi, anteprime per i social. Gli eventi possono
  comparire direttamente nei risultati di ricerca.

## 5. Contenuti legali e obblighi

Il sito si adegua da solo agli obblighi che la contabilità rende evidenti. **Dove c'è una norma, prevale sulle scelte
del cliente.**

| Contenuto | Quando | Come |
|---|---|---|
| Privacy e note legali | sempre | compilate con i dati dell'ente |
| Trasparenza dei contributi pubblici (L. 124/2017) | contributi pubblici sopra la soglia di legge | elenco generato dalla contabilità |
| Compensi di amministratori, controllori, dirigenti e associati (art. 14 c. 2 CTS) | ETS con entrate annue sopra 100.000 € (da verificare se la soglia è più bassa per alcuni enti) | pubblicazione annuale, anche in forma anonima |
| Bilancio sociale (art. 14 c. 1 CTS) | ETS con entrate sopra 1 milione € | pubblicazione del documento |
| **Consiglio direttivo** | **sempre previsto**, visibile **a richiesta** dell'associazione | nomi e ruoli solo con il consenso delle persone registrato nel gestionale; se una norma lo impone, la norma prevale |

Le soglie e i testi normativi vanno verificati con un commercialista prima del lancio.

## 6. Statistiche e script

- **Statistiche senza cookie** incluse, per impostazione.
- **Script personalizzati** (Google Analytics, pixel, chat…): il cliente li inserisce e li **dichiara** come tecnici o
  non tecnici (statistiche, marketing). Stesso meccanismo del portale: gli script tecnici partono subito, gli altri
  restano inerti dentro `<template>` finché il visitatore non dà il consenso alla loro categoria.
- Il **banner dei cookie** compare solo se c'è almeno uno script non tecnico.
- Gli script passano da un controllo prima di essere salvati (non possono chiudere il contenitore che li ospita), e
  restano limitati al sito: non arrivano mai al gestionale né all'area soci.

## 7. Immagini e allegati

- Ridimensionate in WebP e AVIF, in più misure.
- **Dati EXIF rimossi**, compresa la posizione GPS.
- Limiti di dimensione e controllo antivirus.
- Solo foto e locandine del cliente, più icone e illustrazioni del nostro catalogo. **Niente immagini di persone
  generate dall'AI.**
- Link ammessi: `https`, `mailto`, `tel`. Tutti i testi passano da un filtro sui contenuti illeciti.

## 8. Pubblicazione tecnica

- La specifica di ogni versione si salva in `sites.site_versions`.
- Il generatore scrive i file in `sites/<associazione>/<versione>/`; un **link simbolico** passa alla nuova versione in
  un istante, e il ritorno indietro è lo stesso gesto al contrario.
- Il sito è servito dal sottodominio (host virtuale jolly) o dal dominio proprio, più la CDN.

## 9. Fonti

[Brocardi: art. 14 Codice del Terzo settore](https://brocardi.it/codice-terzo-settore/titolo-ii/art14.html) ·
[ecnews: pubblicazione dei compensi anche in forma anonima](https://www.ecnews.it/fiscale/fisco-e-patrimonio/enti-non-commerciali/ets-obbligo-di-pubblicazione-dei-compensi-sul-sito-internet-anche-in-forma-anonima/) ·
[TeamSystem: quando vige l'obbligo del sito web](https://www.teamsystem.com/magazine/terzo-settore/obbligo-sito-web-associazioni/) ·
[fiscoetasse: bilancio sociale e compensi](https://www.fiscoetasse.com/rassegna-stampa/29901-bilancio-sociale-e-indicazione-compensi-organi-di-amministrazione-e-controllo-degli-ets.html)

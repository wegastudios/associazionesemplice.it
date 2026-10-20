# Pulizia del gratuito: nessuna traccia delle funzioni Pro

Decisione del titolare (2026-10-20): il pacchetto gratuito (`AssociazioneSemplice-wporg.zip`) non deve contenere codice, tabelle, impostazioni o nomi delle funzioni Pro.
Motivi: (1) un revisore di WordPress.org potrebbe obiettare; (2) sicurezza: un gratuito analizzato con cattive intenzioni non deve far intuire cosa c'è nel Pro.
La **promozione** del Pro resta consentita (pagina «Scopri il Pro», testi che spiegano vantaggi e funzioni): è testo, non logica.
Tutto il sistema di **licenza** passa nel Pro: il gratuito non sa nulla di chiavi, piani, avvisi o portale.

## Principio

Il gratuito espone pochi **punti di estensione generici** (filtri e azioni `asem_*` con nomi neutri); il Pro li usa per aggiungere schema, impostazioni, limiti, pagine, azioni,
sezioni dell'area soci, shortcode e controlli. Niente più elenchi di funzioni Pro nel gratuito (`Edition::FEATURES`, `PAGES`, `FEATURE_ACTIONS`).

## Controllo automatico

`bash wordpress/check-free-clean.sh [--strict]` cerca nel pacchetto gratuito i nomi delle classi elencate in `tests/pro-files.txt` e le tabelle Pro.
Senza `--strict` stampa il rapporto (è il cruscotto dell'avanzamento); con `--strict` esce con errore: si accende in CI a pulizia finita.

## Fasi (ognuna è una PR con CI verde e un rilascio)

1. **Registro delle estensioni e licenza nel Pro.** `Edition` diventa un registro: le funzioni, le pagine e le azioni le dichiara il Pro all'avvio (`ProBoot`).
   Licenza, avvisi di aggiornamento e aggiornatore (`License*`, `UpdateCheck`, `Updater`, `Admin/License*`, `Admin/UpdateNotice`, lista dei suffissi) passano nel Pro.
2. **Schema nel Pro.** Le tabelle delle funzioni Pro le crea il Pro (`dbDelta` suo, con versione sua). Il gratuito non le crea né le interroga: copia di sicurezza, azzeramento,
   privacy, esportazioni e documenti chiamano punti di estensione. Le installazioni esistenti restano com'erano (le tabelle non si cancellano).
3. **Impostazioni e limiti nel Pro.** `Settings::defaults()/update()` e `Limits::defs()` accettano filtri; chiavi e limiti dei pagamenti, dell'IVA, delle comunicazioni ecc. li porta il Pro.
4. **Amministrazione.** Pagine, schede, azioni (`admin-post`) e gestori delle funzioni Pro si dichiarano con filtri; i gestori in `Actions.php`, `TechActions.php`, `RegistersActions.php`
   che riguardano il Pro passano in file Pro.
5. **Sito e area soci.** Sezioni dell'area soci, shortcode, blocchi, widget e aree si estendono con filtri; viste e sezioni Pro passano nel Pro.
6. **Il resto:** fabbrica dei pagamenti, testi e traduzioni, tessera nel telefono, procedura guidata, guida, bacheca.
7. **Chiusura.** `Edition` ridotto al minimo, `check-free-clean.sh --strict` in CI, documentazione.

## Regole durante la pulizia

- Il Pro resta funzionante a ogni passo (la CI «Free + Pro together» prova il pacchetto completo).
- Compatibilità: `ASEM_API` e `ASEM_PRO_API` salgono insieme a 2 quando cambia l'interfaccia tra i due plugin (fase 1).
- Nessuna perdita di dati: le tabelle esistenti non si toccano; il Pro le riconosce.
- Numerazione: gratuito 1.2.x, Pro 1.1.x (il test sulla versione lo impone).

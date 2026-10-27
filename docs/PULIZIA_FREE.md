# Pulizia del gratuito: nessuna traccia delle funzioni Pro

Decisione del titolare (2026-10-20): il pacchetto gratuito (`associazionesemplice.zip`) non deve contenere codice, tabelle, impostazioni o nomi delle funzioni Pro.
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

## Stato (2026-10-20): completata

Fasi 1-7 eseguite. `bash wordpress/check-free-clean.sh --strict` gira in CI (job «Installable zips») e non trova nulla nel pacchetto gratuito.
Livello di compatibilità `ASEM_API`/`ASEM_PRO_API` = 9 (dalla 9 il Pro usa nuove funzioni del gratuito per certificati e affiliazioni: promemoria, esportazioni e invio dei file; dalla 8 SumUp e commissioni di incasso; dalla 7 domanda di adesione online e minori; dalla 6 il Pro usa `Ui::inline_js` e `Plugin::post_data`), hook `asem_session_errors` (dalla 5 le risorse e gli allestimenti degli eventi; dalla 4 gli anni solari e le loro azioni sono nel gratuito; hook `asem_years_page_after`).

### Punti di estensione del gratuito (filtri e azioni `asem_*`)
- Registro: `Edition::register()`, `asem_feature_available`, `asem_features_suspended`, `asem_feature_unavailable_message|html`.
- Schema e dati: `asem_schema_tables`, `asem_privacy_export`, `asem_person_anonymized`, `asem_activity_deleted`, `asem_registration_deleted`, `asem_activity_delete_blockers`, `asem_online_payment_pending`, `asem_activity_saved`.
- Impostazioni e limiti: `asem_settings_defaults`, `asem_settings_clean`, `asem_secret_keys`, `asem_limits_defs`, `asem_feature_switches`, `asem_settings_page_end`, `asem_card_settings`, `asem_comms_settings`, `asem_entity_values`.
- Amministrazione: `asem_admin_groups|sections|only|parents|menu_pages|hidden_pages`, `asem_admin_init`, `Admin\Actions::add_handler()`, `asem_card_page_end|switches`, `asem_entity_rows|sections|page_end`, `asem_comms_sections`, `asem_integrations_top|list`, `asem_dashboard_alerts`, `asem_years_page_after`, `asem_session_errors`, `asem_person_receipts`, `asem_ledger_row_actions`, `asem_vat_row|input`, `asem_activity_form_rows`, `asem_guide_options`, `asem_document`.
- Procedura guidata: `asem_wizard_entity_intro|entity_rows|steps_entity|module_extra|levels_rows|payment_choices|steps_payments|own_step|feature_conditions|page_if|values|done|next`.
- Sito: `asem_front_init`, `asem_shortcode_views`, `asem_render_view`, `asem_view_available`, `asem_areas`, `asem_page_defs|areas`, `asem_my_sections`, `asem_area_section`, `asem_card_extra`, `asem_checkin_extra`, `asem_treasurer_sections`, `Frontend\Actions::add_handler()`.
- Servizi: `asem_scheduled_jobs`, `asem_admin_post_classes`, `asem_rest_routes`, `asem_payments_service`, `asem_online_dues`, `asem_reservation_active`, `asem_text_plain|html`, `asem_texts_flush`, `asem_texts_admin_buffer`, `asem_notify_email`, `asem_privacy_uses|processors|transfer_note`.

### Decisioni
- `member_levels`, `fiscal_years` e `fund_entries` restano nel gratuito: sono dati del nucleo (una quota, guardia degli anni chiusi, quote dei referenti) usati anche senza il Pro.
- I nomi delle funzioni (`payments`, `receipts`, `texts`…) restano come identificatori generici in `Edition::has()`; la pagina «Scopri il Pro» è promozione, ammessa.
- Il Pro dichiara tutto in `ProBoot::init()` (priorità 5 di `plugins_loaded`) e nelle classi `Pro*`/`DocsPro`; i file sono elencati in `tests/pro-files.txt`.

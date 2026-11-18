# La tessera segue l'anno fiscale (decisione del titolare, 18/11/2026)

## Decisione
- La **tessera di socio** (etichetta, validità, rinnovo), le **quote di iscrizione** e gli **incassi** seguono l'**anno fiscale** (impostazione «inizio anno fiscale»), non l'anno sociale.
- L'**anno sociale** resta solo per **corsi ed eventi** (il calendario): ogni attività sta in un anno e le sue date devono cadere dentro. A schermo si chiama in modo chiaro «anno delle attività» (o «anno del calendario»), mai «anno sociale» se si parla di tessere o soldi. Testi da approvare da Audit.
- Le **tessere già emesse** (anche «2025/2026») si **riallineano** all'anno fiscale (risposta del titolare, 18/11/2026): etichetta e scadenza passano all'anno fiscale che le contiene.
- Gli eventi **non** guadagnano le date vere né il superamento del confine d'anno: «resta un anno per attività» (risposta del titolare). Un corso a cavallo d'anno si divide o si tiene in un anno diverso.

## Da fare (in un'unica pubblicazione, mai a fasi)
1. `Settings::membership_year()` e il rinnovo seguono `FiscalYears::start_month()`; l'etichetta è «2026» con gennaio, «2025/2026» con altri mesi.
2. Riallineamento delle tessere esistenti: etichetta, `valid_from`, `valid_to`; due tessere della stessa persona non devono avere la stessa etichetta (le si unisce o si tiene la più lunga: da decidere con Audit); registro azioni; copia di sicurezza prima.
3. Impostazioni: «Anno sociale» diventa «Anno delle attività (corsi ed eventi)»; il passaggio a gennaio non tocca più le tessere (quindi cade il vincolo «puoi passare solo a gennaio»).
4. Elenchi, esportazioni, importazione soci, scheda socio, avvisi di scadenza, certificati/tessere: parlano di anno fiscale per la tessera.
5. Gestionale SaaS (stessa regola), guide e tabella di confronto: avvisare Audit prima della pubblicazione.

## Punti aperti per Audit
- Persone con due tessere che dopo il riallineamento avrebbero la stessa etichetta.
- Tessere di soci fondatori e tessere cancellate.
- Parole a schermo: «anno sociale» → «anno delle attività»? Testi uno per uno.

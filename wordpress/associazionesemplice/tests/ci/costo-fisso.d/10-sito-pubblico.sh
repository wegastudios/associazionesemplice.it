# Richieste del sito pubblico (letto da costo-fisso.sh).
# Pagina pubblica del sito, visitatore anonimo: niente amministrazione, aggiornatore, schema, né le pagine che rispondono a un parametro dell'indirizzo.
PUBLIC_FORBIDDEN='Install Updater EventRequests GuardianConsent Frontend\CardVerify Frontend\CardPdf Frontend\TicketVerify Frontend\Activation Frontend\FirstAccess Frontend\MemberLoginPage Frontend\Application Frontend\BlockBViews Frontend\BlockFViews Admin\Admin Admin\Actions'
# ... e con il Pro: menu e avvisi di amministrazione, schema delle sue tabelle, ritorno dal pagamento
PUBLIC_FORBIDDEN+=' Admin\ProMenu Admin\ProPanels Admin\ProWizard Admin\LicenseNotice Admin\UpdateNotice Admin\ReceiptCheckPage ProSchema Frontend\PayReturn'
misura 'Pagina pubblica (visitatore)' GET '/' '' 0 '200' "$PUBLIC_FORBIDDEN"
# I link con un parametro arrivano ancora al loro gestore (e solo quello si carica)
misura 'Link: verifica di una tessera' GET '/?asem_card=prova' '' 0 '200 404' 'EventRequests Frontend\TicketVerify Install' 'Frontend\CardVerify'
misura 'Link: conferma della prenotazione di un ospite' GET '/?asem_evreq=prova' '' 0 '200 400 403 404 410' 'Frontend\CardVerify Install' 'EventRequests'
misura 'Link: calendario con un codice sbagliato' GET '/?asem_ical=prova' '' 0 '404' 'EventRequests Install' 'Calendar'

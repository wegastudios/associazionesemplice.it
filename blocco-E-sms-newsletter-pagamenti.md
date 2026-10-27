# Blocco E — testo per le guide di supporto

Funzioni nuove nell'edizione **Pro** (Pro Contabile e Pro Fiscale): SMS ai soci, newsletter, Satispay e Klarna.

## Consensi

Prima di scrivere a qualcuno per SMS o di mandarlo in newsletter serve il suo consenso. Si registra nella scheda della persona (riquadro «Consensi SMS e newsletter»: modulo cartaceo o a voce) oppure lo dà il socio da solo nell'area riservata, sezione «Le mie comunicazioni». Si può revocare quando si vuole: da quel momento non parte più nulla.

## SMS ai soci

1. **Strumenti → Integrazioni → SMS ai soci**: scegli Brevo o Twilio, inserisci le chiavi e il mittente (fino a 11 lettere o cifre), salva. «Verifica il collegamento» mostra anche i crediti residui.
2. **Rubrica soci → Comunicazioni**: sotto «Canali» spunta Email, SMS o tutti e due. L'anteprima dice quanti riceveranno l'SMS e quanti no perché senza consenso o senza numero valido. Il testo può avere al massimo 480 caratteri; messaggi lunghi o con emoji usano più SMS.
3. Nella scheda della comunicazione vedi chi ha ricevuto l'SMS. Se un invio non riesce il sito riprova da solo (dopo 15 minuti e poi dopo 2 ore); per gli errori definitivi (numero non valido) c'è il pulsante «Riprova».
4. I promemoria automatici possono partire anche per SMS: si accende in Integrazioni («Manda anche per SMS i promemoria»). È spento di partenza.

Il costo degli SMS è quello del fornitore scelto. I numeri nel registro sono sempre in parte nascosti.

## Newsletter

In **Strumenti → Integrazioni → Newsletter** scegli Brevo, Mailchimp o MailPoet (se è attivo sul sito), inserisci la chiave, premi «Leggi le liste», scegli la lista e salva. «Sincronizza adesso» allinea subito; ogni giorno lo fa da solo.

- Si iscrive **solo chi ha dato il consenso alla newsletter** e ha un'email valida.
- Chi revoca il consenso, cambia indirizzo o viene anonimizzato esce dalla lista.
- Chi si è disiscritto dal servizio resta disiscritto: il sito non lo reiscrive.
- Con «Invia anche tipo di socio e stato della tessera»: in Brevo servono due campi di testo, `TIPO_SOCIO` e `STATO_TESSERA`; in Mailchimp arrivano come etichette; MailPoet non li supporta.

## Satispay

In **Impostazioni → Pagamenti online** scegli Satispay (da solo o con gli altri metodi), poi: genera in Satispay Business un **codice di attivazione**, incollalo in «Collega Satispay» e premi il pulsante. Il sito crea e conserva da solo la chiave di firma. «Verifica connessione Satispay» controlla che tutto funzioni. Se cambi modalità (prova o reale) bisogna ricollegare. Il socio paga nell'app Satispay; l'incasso entra da solo in prima nota sul conto «Satispay». In modalità reale il sito deve essere in https.

## Klarna

Sempre in Pagamenti online, con Stripe attivo: spunta «Offri anche Klarna». Va attivato anche nel tuo account Stripe (Metodi di pagamento). Si propone solo per pagamenti in euro da 1 € a 10.000 € e a chi ha un'email; altrimenti resta la sola carta. Nel webhook di Stripe aggiungi l'evento `checkout.session.async_payment_failed`.

## Domande frequenti

- *Perché non è partito l'SMS a una persona?* Controlla che abbia un numero di cellulare valido e il consenso agli SMS.
- *Posso mandare la newsletter da qui?* No: il sito tiene allineata la lista; la newsletter si scrive e si invia dal servizio scelto.
- *Che piani?* SMS, newsletter, Satispay e Klarna sono in Pro Contabile e Pro Fiscale.

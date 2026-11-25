# Dati della demo del plugin

Tutti i dati della demo (demo.associazionesemplice.it) sono **inventati**: nomi da elenchi comuni combinati a caso, email `@demo.invalid`, telefoni `06 556xxxx` di fantasia, nessun codice fiscale nei dati abbondanti. Nessuno di questi dati è di una persona reale.

## Come si costruisce (reset notturno alle 4)

`demo/reset.sh` azzera e poi lancia, nell'ordine:

1. `demo/provision.php`: ente, pagine, segreteria, soci base, corso, eventi, prima nota minima. Imposta anno sociale ad **agosto** e anno fiscale a **settembre**, e crea l'anno fiscale scorso (set–ago).
2. `demo/extra-data.php`: soci in vari stati, certificati, prestiti, domande, assemblea, eventi sul sito, pagine, WhatsApp.
3. `demo/molti-dati.php`: **tanti dati** (circa 240 persone, 1.300 movimenti su due anni fiscali, 10 corsi con iscritti, 12 eventi con prenotazioni, 6 risorse con circa 150 prenotazioni, rimborsi, certificati, inventario e prestiti). Si cambia la quantità con `MOLTI_SCALA=0.2` (per una prova veloce).

Le date sono sempre relative a oggi: nessun anno scritto a mano, gli anni scorrono da soli.

## Regole che i dati rispettano

- **Cassa mai negativa**, in nessun giorno e per nessun conto (lo script lo controlla con il saldo iniziale e avvisa se non è così).
- **L'anno fiscale scorso è sempre in disavanzo** (−160 €): serve a mostrare il controllo del plugin. L'anno in corso è in avanzo.
- Nessun anno chiuso in demo. Dati dell'ente e contatti della segreteria restano vuoti per scelta: si vede l'avviso che li chiede.
- Ricevute definitive e allegati cifrati si aggiungono solo dopo il rilascio che li porta.

## Tempi

Con questi dati l'intero reset (installazione di WordPress, i tre script) dura circa un minuto sul server della demo; `molti-dati.php` da solo circa 3 secondi.

## Come si prova senza toccare la demo

Si copia la demo fuori da `public_html` con un prefisso di tabelle diverso (`wpt_`), si lanciano i tre script e si controllano conti e risultati; poi si eliminano la cartella e le tabelle.

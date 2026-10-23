<?php

// Guide aggiunte con i rilasci del plugin (1.2.20 e successivi).
return [
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'bacheca-da-lavorare-ed-eventi-della-settimana',
        'title' => 'Usare la Bacheca: cosa c\'è da lavorare e gli eventi della settimana',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.20 del plugin)

Appena entri nella **Bacheca di WordPress** (la pagina iniziale di `wp-admin`) trovi due riquadri con ciò che ti serve oggi. Li vedono gli amministratori, la segreteria e i consiglieri con accesso operativo.

## Da lavorare

Il riquadro elenca le cose che aspettano te:

- **richieste di accesso** da controllare (i soci che hanno chiesto di entrare);
- **soci da rinnovare**, con tessera scaduta o in scadenza;
- **ospiti da invitare** a iscriversi;
- **email non partite** (solo per l'amministratore).

Ogni voce ha un collegamento che porta alla pagina giusta. In fondo trovi il collegamento alla **Bacheca di Associazione Semplice**, con il quadro completo.

## Eventi e corsi della settimana

Il secondo riquadro mostra i **prossimi 7 giorni**, con orario, luogo e numero di prenotati. Sotto ci sono i pulsanti per le operazioni più frequenti:

- **Nuovo incasso**
- **Prenota**
- **Nuovo socio**
- **Corsi ed eventi**
- **Rubrica**
- **Prima nota**

## Se non vedi i riquadri

1. Controlla di avere un ruolo con accesso operativo (vedi [Assegnare ruoli e permessi](guida:assegnare-ruoli-e-permessi)).
2. Apri **Impostazioni schermata** in alto nella Bacheca di WordPress e verifica che i riquadri non siano nascosti.

> I riquadri compaiono solo nella Bacheca di WordPress: nelle pagine del plugin trovi invece la Bacheca di Associazione Semplice.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'leggere-le-novita-di-ogni-versione',
        'title' => 'Leggere le novità di ogni versione',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Ogni plugin ha un **elenco delle novità** (il *changelog*), scritto in italiano semplice: ti dice cosa è stato aggiunto, cambiato o corretto in ogni versione.

## Dove lo trovi

- **Plugin gratuito**: nel file `CHANGELOG.md` incluso nel plugin e nella scheda dei dettagli del plugin in WordPress.
- **Associazione Semplice Pro**: nei **dettagli dell'aggiornamento** del plugin, quando WordPress segnala che c'è una nuova versione. Le voci arrivano dal portale di Associazione Semplice.

## Come aggiornare in sicurezza

1. Prima di aggiornare, leggi le novità della versione nuova.
2. Fai una [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino).
3. Aggiorna il plugin da **Plugin → Plugin installati**. Il plugin gratuito e Pro hanno versioni indipendenti: puoi aggiornare l'uno o l'altro in qualsiasi ordine.
4. Controlla la Bacheca di Associazione Semplice e prova un'operazione semplice, per esempio aprire la scheda di un socio.

## Avviso degli aggiornamenti

Con una chiave di licenza inserita (anche quella gratuita), il plugin ti avvisa quando esce una versione più recente. L'avviso si può chiudere e si può spegnere dalle impostazioni.
MD,
    ],
];

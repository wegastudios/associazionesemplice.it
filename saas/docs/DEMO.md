# Demo del gestionale: piano operativo

La demo del gestionale vive su **un solo indirizzo**, `https://demosaas.associazionesemplice.it`, che è insieme:

- l'indirizzo dell'app (`APP_URL`): accesso degli operatori e gestionale;
- lo slug «demosaas» sotto `associazionesemplice.it` (`SAAS_MEMBERS_DOMAIN`): quindi anche l'area soci, `/area`;
- l'indirizzo dei moduli pubblici `/dona/demosaas` e `/adesione/demosaas`.

Il resto del contesto (cosa mostra la demo, dati finti, utenti pubblici) è in `PIATTAFORMA.md` §6. Questo documento dice come si mette in piedi.
Il gestionale resta **nascosto**: nessun collegamento dal portale o dalla vendita, nessun interruttore di produzione cambia.

## Due modi di installarla: stesso piano

1. **Account cPanel suo**, separato dalla demo WordPress (consigliato).
2. **Stesso account della demo WordPress** (pubblica). Valgono le stesse istruzioni, più le regole della sezione «Se l'account è condiviso».

In entrambi i casi la demo del gestionale sta in `~/saas-demo`; mai in `~/saas` (la produzione, anche se un giorno stesse sullo stesso account).

## Requisiti

- Sottodominio `demosaas.associazionesemplice.it`, con cartella pubblica `~/saas-demo/current/public`, HTTPS attivo (i cookie `__Host-` lo richiedono).
- PHP e PostgreSQL come in produzione (stessa versione di PHP, PostgreSQL 16). **Niente Redis**: `CACHE_STORE=database`.
- Un database PostgreSQL e **tre utenti con nomi e password diversi**: applicazione (`DB_USERNAME`), proprietario (`DB_OWNER_USERNAME`, migrazioni e azzeramento), sola lettura (`DB_BACKUP_USERNAME`). Diversi da quelli di ogni altro posto.
- Cron di cPanel, una riga sola, scritta dal rilascio: `* * * * * cd ~/saas-demo/current && php artisan schedule:run` (`remote.sh` la aggiunge se manca; se `crontab` non c'è, la metti tu).
- La licenza della demo è dedicata: la demo non usa nessuna chiave o licenza di produzione (`SAAS_KEY_VAULT=local`, associazione con piano «demo» interno; nessun collegamento col portale).

## Ambiente GitHub `demo`

- Ambiente **`demo`** nelle impostazioni del repository, con la regola «solo il ramo `main`».
- Segreti **propri** dell'ambiente (non quelli della produzione): `SAAS_SSH_PRIVATE_KEY`, `SAAS_SSH_KNOWN_HOSTS`, `SAAS_SSH_HOST`, `SAAS_SSH_PORT`, `SAAS_SSH_USER`.
- Il workflow «SaaS · rilascio» ha già l'ambiente `demo` e rilascia in `~/saas-demo` (mai `~/saas`).

## Chiavi SSH: due coppie

Le genera il titolare con **Git Bash**, **fuori da OneDrive** (per esempio in `C:\chiavi\`), e non le incolla mai in chat.

| Coppia | A cosa serve | Dove va la parte pubblica | Dove va la parte privata |
|---|---|---|---|
| **sessioni** | entrare per lavorare a mano sul server | `~/.ssh/authorized_keys` dell'account | il tuo computer (passphrase **facoltativa**) |
| **Actions** | rilascio automatico dal workflow | `~/.ssh/authorized_keys` dell'account | segreto `SAAS_SSH_PRIVATE_KEY` dell'ambiente `demo` (senza passphrase: Actions non può digitarla) |

```
ssh-keygen -t ed25519 -f /c/chiavi/demo-sessioni -C "demo sessioni"
ssh-keygen -t ed25519 -f /c/chiavi/demo-actions  -C "demo actions"
```

La passphrase si può mettere sulla prima coppia; la seconda resta senza. Le due coppie sono diverse tra loro e da quelle della produzione.

## Il file `.env`

Lo **scrive il titolare sul server**, a mano, partendo dal modello `saas/deploy/env.demo.example`: copia in `~/saas-demo/shared/.env` (permessi 600) e compila i punti segnati `DA COMPILARE`. Il file non passa mai da chat, repository o log.

Prima di ogni rilascio, e ogni volta che lo modifichi:

```
cd ~/saas-demo/current && php artisan saas:demo:check-env
```

Il comando legge il file e dice **quali** variabili mancano, sono vuote, sono ancora sul valore d'esempio o rompono una regola; **non stampa mai i valori**. Controlla anche:

- **indirizzo unico**: `APP_URL` = `SAAS_DEMO_SLUG` + `.` + `SAAS_MEMBERS_DOMAIN`, in HTTPS;
- **cookie**: `SESSION_COOKIE` comincia con `__Host-`, `SESSION_DOMAIN` vuota/`null`, `SESSION_SECURE_COOKIE=true`;
- **isolamento** (vedi sotto);
- `APP_ENV=demo`, `SAAS_DEMO=true`, `SAAS_DEMO_DATABASE` uguale a `DB_DATABASE`, `MAIL_MAILER=log`, `SAAS_UNIFIED_OPERATORS=false`, `SAAS_REQUIRE_TWO_FACTOR=false`, `SAAS_KEY_VAULT=local`;
- tre utenti PostgreSQL con nomi diversi, password diverse tra loro e diverse da `APP_KEY`, `SAAS_MASTER_KEY` e `SAAS_DEMO_PASSWORD`; chiavi non vuote.

### Regola di isolamento

Nel `.env` della demo **nessun valore deve valere altrove**: niente chiavi o token verso GitHub, portale, Emailit/SMTP, Stripe, produzione, Redis, servizio chiavi. Il controllo accetta solo le variabili del modello (più poche opzioni): ogni altra variabile con un valore è un errore, e quelle che sembrano collegamenti esterni sono segnalate come tali. Motivo: la demo WordPress è pubblica e, se sta nello stesso account, un difetto lì potrebbe leggere questo file; il file non deve contenere nulla che si possa usare contro qualcosa di vero.

## Cookie

Tutti i cookie dell'app sono **solo dell'indirizzo** (nessun `Domain`) e non sono sostituibili da un cookie del dominio padre `.associazionesemplice.it` scritto da un sottodominio fratello (per esempio la demo WordPress):

| Cookie | Nome | Note |
|---|---|---|
| sessione operatore | `SESSION_COOKIE` = `__Host-as_demo` (produzione: `__Host-as_gestionale`) | `Secure`, percorso `/`, senza `Domain`: il browser rifiuta un cookie omonimo scritto da un altro indirizzo |
| pagine pubbliche (area soci, `/dona`, `/adesione`) | `__Host-as_pubblico` | vale ovunque ci sia HTTPS (produzione, staging, demo); `as_pubblico` solo in locale |
| «ricordami» | `remember_web_<hash>` | senza `Domain`, `Secure`, `HttpOnly`; il nome lo decide Laravel e non ha il prefisso; il valore è cifrato con `APP_KEY`: non si può falsificare, al massimo un cookie omonimo ne sostituirebbe uno con quello di un altro account valido |
| `XSRF-TOKEN` | fisso | senza `Domain`; il token si confronta con quello della sessione sul server: un cookie omonimo scritto altrove non può far passare una richiesta, al massimo la fa rifiutare (419) |
| socio nell'area soci | `as_socio` | percorso `/area`, senza `Domain`, `HttpOnly`; il valore è un codice casuale riconosciuto dal database |

I cookie dell'amministrazione (`/saas/admin`) e dello staff non esistono nella demo (404).

## noindex

Con `SAAS_DEMO=true` (e ambiente non di produzione): ogni risposta ha `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` e `/robots.txt` risponde `Disallow: /` (il file lo serve l'applicazione, non il file statico: lo garantisce `public/.htaccess`). Fuori dalla demo non cambia nulla.

## Azzeramento notturno e orari

| Ora | Cosa |
|---|---|
| 02:23 | controlli notturni dell'app (`saas:night`) |
| **03:30** | **azzeramento della demo del gestionale** (`saas:demo:reset`, dallo scheduler) |
| **04:00** | **azzeramento della demo WordPress** |

Regola: **nessuna sovrapposizione**. L'azzeramento del gestionale parte alle 03:30 e deve finire prima delle 04:00; quello di WordPress parte alle 04:00 e non prima. Non si spostano le due ore senza cambiare l'altra: tra l'una e l'altra restano almeno 30 minuti. Se l'azzeramento del gestionale dovesse superare i 25 minuti (con `SAAS_DEMO_PEOPLE` più alto o un server lento), si scende di numero di persone, non si sposta WordPress sopra. Altri lavori dello scheduler vicini: `saas:applications:prune` alle 03:41 e, il lunedì, `saas:audit:prune-security` alle 03:27; sono brevi e non toccano i dati dell'azzeramento.

L'azzeramento cancella **tutto** il database della demo e i file cifrati, ed è protetto: si rifiuta se `SAAS_DEMO_DATABASE` non è il database collegato, se ci sono altre associazioni o se la cartella non si chiama `saas-demo`. Si lancia a mano solo sul server demo, e solo se il titolare lo chiede (il primo dopo la prima installazione).

## Se l'account è condiviso con la demo WordPress

- Cartelle **separate**: `~/saas-demo` per il gestionale, quelle di WordPress altrove (sue); mai file in comune, mai un collegamento simbolico dall'una all'altra.
- `~/saas-demo/shared/.env` con permessi 600 e **nessun segreto vero** nell'account: né chiavi di produzione, né token GitHub, né password di posta o Stripe (il controllo del `.env` lo verifica). Nemmeno nei file di WordPress.
- Orari: WordPress azzera alle **04:00**, il gestionale alle **03:30**.
- Licenza dedicata alla demo; il database PostgreSQL e i suoi tre utenti sono solo della demo.
- Il gestionale resta nascosto: nessuna pagina di WordPress lo collega, nessun collegamento al portale.
- Le chiavi SSH di rilascio sono quelle dell'ambiente `demo`, diverse da quelle della produzione.

## Installazione, in ordine

Sul server **niente si installa finché il titolare non lo chiede**. Quando sarà il momento:

1. Account (o cartelle) e sottodominio `demosaas.associazionesemplice.it` con HTTPS.
2. Database PostgreSQL e tre utenti; niente Redis.
3. Due coppie di chiavi SSH; parte pubblica in `authorized_keys`; privata di Actions nell'ambiente GitHub `demo`.
4. `.env` dal modello, compilato dal titolare; `php artisan saas:demo:check-env` senza problemi.
5. Rilascio dal workflow «SaaS · rilascio» con ambiente `demo` (solo `main`).
6. Primo `php artisan saas:demo:reset`, a mano; poi lo fa lo scheduler alle 03:30. Cron: `schedule:run` ogni minuto.
7. Prove: `/accesso` con gli accessi della pagina, `/area/accesso`, `/adesione/demosaas`, `https://demosaas.associazionesemplice.it/robots.txt` (deve dire `Disallow: /`).

## Cosa è provato dai test

`tests/Feature/Core/DemoSameHostTest.php`: sullo stesso indirizzo ogni percorso va alla rotta giusta (`/area` all'area soci, `/accesso` e `/gestionale` all'operatore, `/dona` e `/adesione` ai moduli pubblici), i cookie sono senza `Domain` e con prefisso `__Host-`, noindex e robots. `tests/Feature/Core/DemoCheckEnvTest.php`: il controllo del `.env` e il modello.

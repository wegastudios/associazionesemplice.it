<?php

declare(strict_types=1);

return [

    'db' => [
        // Database condiviso: connessione dell'applicazione e del proprietario (config/database.php).
        'app_connection' => 'pgsql',
        'owner_connection' => 'pgsql_owner',

        // Elenco dei database dedicati (pacchetto Database dedicato), fuori dal repository e dalla cartella
        // pubblica. Formato in App\Modules\Core\Database\DatabaseRegistry.
        'dedicated_file' => env('SAAS_DEDICATED_DB_FILE'),

        // Attesa massima di un blocco forte (migrazioni, partizioni, potatura), poi si riprova: tre tentativi in tutto
        // (App\Modules\Core\Contracts\ShortLocks). Dietro un'istruzione in fila si fermano tutte le query sulla tabella.
        'lock_timeout' => env('SAAS_DB_LOCK_TIMEOUT', '3s'),
    ],

    'auth' => [
        // Verifica in due passaggi obbligatoria per chi è operatore. Si spegne SOLO in sviluppo, con dati inventati
        // (SAAS_REQUIRE_TWO_FACTOR=false nel file d'ambiente): saas:health lo segnala finché è spenta.
        // Si spegne solo con un «no» esplicito (false, 0, off, no): un valore vuoto o sconosciuto lascia la verifica accesa.
        'require_two_factor' => env('SAAS_REQUIRE_TWO_FACTOR', true) === '' ? true : (filter_var(env('SAAS_REQUIRE_TWO_FACTOR', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true),
        // Durata massima del gestionale dall'accesso (minuti), anche se si lavora di continuo: 12 ore.
        'session_max_minutes' => (int) env('SAAS_SESSION_MAX_MINUTES', 720),
    ],

    'crypto' => [
        // remote = servizio chiavi su un account separato (produzione); local = chiave principale nel file
        // d'ambiente (sviluppo, test e ripiego).
        'vault' => env('SAAS_KEY_VAULT', 'local'),
        'master_key' => env('SAAS_MASTER_KEY'),
        'service_url' => env('SAAS_KEY_SERVICE_URL'),
        'signing_key' => env('SAAS_KEY_SERVICE_SIGNING_KEY'),
        'timeout' => env('SAAS_KEY_SERVICE_TIMEOUT', '3'),
        // Quanto resta in memoria (APCu) una chiave sbloccata, in secondi.
        'cache_ttl' => 300,
        // Pausa tra un'associazione e l'altra nei comandi che girano su tutte (millisecondi): con 500 al massimo 120
        // associazioni al minuto, sotto il tetto della corsia dei comandi del servizio chiavi anche con due versioni di
        // chiave ciascuna.
        'batch_pause_ms' => env('SAAS_KEY_BATCH_PAUSE_MS', '500'),
        // Formato dei campi cifrati legato alla riga (0x02, con l'id della riga) per i campi che lo dichiarano (soci e
        // impostazioni di pagamento). Interruttore d'emergenza: con SAAS_ROW_BOUND_FORMAT=false le nuove scritture
        // tornano a 0x01; la lettura apre sempre entrambi i formati.
        'row_bound_format' => (bool) env('SAAS_ROW_BOUND_FORMAT', true),
    ],

    // Area soci: https://<slug>.<members_domain>/area (docs/ARCHITETTURA.md §3). In locale si usa per esempio
    // «localhost»: i browser portano <slug>.localhost sul computer stesso.
    'members_domain' => env('SAAS_MEMBERS_DOMAIN', 'associazionesemplice.it'),

    // Nomi che nessuna associazione vera può avere come indirizzo dell'area soci (sono indirizzi della piattaforma).
    'reserved_slugs' => ['app', 'app-demo', 'demo', 'demosaas', 'wpdemo', 'www', 'mail', 'api', 'admin', 'staging', 'supporto', 'chiavi'],

    // Percorso dell'amministrazione della piattaforma (docs/PIATTAFORMA.md §4): `admin` qui; dietro il proxy del
    // portale sarà `saas/admin`, con `ASSET_URL` e `APP_URL` coerenti.
    'admin_path' => env('SAAS_ADMIN_PATH', 'saas/admin'),

    // Segreto condiviso con il proxy del portale (almeno 32 caratteri): solo con questo l'indirizzo del visitatore si legge da
    // X-Forwarded-For. Quando c'è, SAAS_TRUSTED_PROXIES non si usa più.
    'proxy_secret' => env('SAAS_PROXY_SECRET', ''),

    // `saas:health`: con SAAS_HEALTH_STRICT acceso la chiave principale locale e i permessi eccessivi del ruolo fermano il rilascio;
    // spento (sviluppo, dati inventati) restano avvisi. Va acceso insieme alla 2FA e al repository privato, prima dei dati veri.
    'health_strict' => filter_var(env('SAAS_HEALTH_STRICT', false), FILTER_VALIDATE_BOOLEAN),

    // Cartella dei file di manutenzione con permessi 600 (~/saas/shared sul server): vuoto = quella che contiene storage.
    'ops_dir' => env('SAAS_OPS_DIR', ''),

    // Indirizzi da cui fidarsi degli X-Forwarded-* (il proxy del portale), separati da virgole; vuoto = nessuno.
    'trusted_proxies' => env('SAAS_TRUSTED_PROXIES', ''),

    // Impostazioni della piattaforma lette all'avvio (PlatformConfig): si tengono, ancora cifrate, in questa cache per
    // 5 minuti e si dimenticano a ogni salvataggio. «file» perché la cache generale dipende proprio da queste impostazioni.
    'platform_settings_cache' => env('SAAS_PLATFORM_SETTINGS_CACHE', 'file'),

    // Cache tra le richieste (H20, Core\Contracts\SharedCache): secondi di validità, 0 = spenta. Il catalogo si dimentica a
    // ogni pubblicazione; i diritti di un'associazione a ogni scrittura dal portale e a ogni cambio di stato (sospensione).
    'shared_cache' => [
        'catalog' => (int) env('SAAS_CACHE_CATALOG_SECONDS', 300),
        'entitlements' => (int) env('SAAS_CACHE_ENTITLEMENTS_SECONDS', 300),
        // Stato dell'associazione e sola lettura: al massimo 60 secondi (SharedCache::MAX_SECONDS).
        'status' => (int) env('SAAS_CACHE_STATUS_SECONDS', 60),
    ],

    // Registro delle query lente (millisecondi; 0 = spento). Scrive solo il testo della query con i segnaposto, mai i valori.
    'slow_query_ms' => (int) env('SAAS_SLOW_QUERY_MS', 200),

    // Tempi massimi sulla connessione dell'associazione (OrgContext::enter, nella stessa istruzione di app.org_id): attesa
    // di un blocco, durata di un'istruzione, transazione aperta e ferma. Scaduti, la richiesta risponde «riprova tra poco».
    // «long»: comandi, code e le rotte lunghe del gestionale con il middleware «db.long». Vedi docs/TEMPI_MASSIMI_DATABASE.md.
    // Le migrazioni e i backup usano le loro connessioni (proprietario, sola lettura) e non passano da qui.
    'database_timeouts' => [
        'web' => ['lock' => '5s', 'statement' => '30s', 'idle_in_transaction' => '60s'],
        'long' => ['lock' => '30s', 'statement' => '900s', 'idle_in_transaction' => '300s'],
    ],

    // Import di soci e prima nota in coda (H10, H11): righe per blocco (una transazione ciascuno) e secondi di lavoro per
    // giro di coda; finiti i secondi, il lavoro si rimette in coda e riparte dal blocco successivo.
    'imports' => [
        'block' => (int) env('SAAS_IMPORT_BLOCK', 100),
        'seconds_per_run' => (int) env('SAAS_IMPORT_SECONDS', 40),
    ],

    // Indirizzo pubblico di /saas/admin e /saas/staff dietro il proxy del portale (https://associazionesemplice.it): gli
    // indirizzi scritti dal SaaS in quelle pagine partono da qui, mai dalle intestazioni della richiesta. Vuoto = nessuno.
    'public_url' => env('SAAS_PUBLIC_URL', ''),

    // Risorse prenotate dai soci dall'area soci (la segreteria non ha questi limiti): prenotazioni ancora da usare per
    // persona. L'anticipo massimo è solo quello indicato dalla risorsa (a zero: nessun limite).
    'resources' => [
        'member_max_active' => (int) env('SAAS_MEMBER_MAX_ACTIVE_BOOKINGS', 10),
    ],

    // Code: quanti lavoratori il cron tiene accesi insieme oltre a quello della posta urgente, che c'è sempre (1-2; il server
    // ha 25 processi PHP in tutto, condivisi con il web). 2 = lavori brevi e lavori lunghi; 1 = uno per entrambi.
    'queue' => [
        'max_workers' => (int) env('SAAS_QUEUE_MAX_WORKERS', 2),
    ],

    // Promemoria delle scadenze: vanno in coda come posta massiva, un'email per lavoro (tetti in mail_queue). In più, un
    // massimo di email al minuto per ogni associazione (oltre, il lavoro riparte più tardi).
    'reminders' => [
        'per_org_per_minute' => (int) env('SAAS_REMINDERS_PER_ORG_PER_MINUTE', 5),
    ],

    // Posta in coda (App\Modules\Core\Contracts\MailQueue): tetti di invio per mailer, contati a parte per ciascuno.
    // Chiavi: per_second, per_minute, per_hour, per_day, per_month (30 giorni) sul totale; le stesse con «bulk_» davanti
    // sui massivi, che contano anche sul totale. Un mailer non elencato usa i numeri di «smtp».
    // smtp: quota del gestionale sul limite orario condiviso dell'account di posta (140, di cui 40 lasciati agli urgenti).
    // per_day: giorno UTC, azzerato a mezzanotte UTC.
    'mail_queue' => [
        // Dopo un rifiuto del server di posta, per quanti minuti la posta in coda resta ferma.
        'blocked_minutes' => (int) env('SAAS_MAIL_BLOCKED_MINUTES', 60),
        // Servizio di invio principale (Core\Mail\PrimaryMailer, docs/POSTA_EMAILIT.md): «cpanel» (di base) = solo il mailer
        // di sempre; «emailit» = prima Emailit (con la chiave EMAILIT_PASSWORD), il mailer di sempre come ripiego.
        'primary' => env('SAAS_MAIL_PRIMARY', 'cpanel'),
        'mailers' => [
            'smtp' => [
                'per_hour' => (int) env('SAAS_MAIL_SMTP_PER_HOUR', 140),
                'bulk_per_hour' => (int) env('SAAS_MAIL_SMTP_BULK_PER_HOUR', 100),
                'bulk_per_minute' => (int) env('SAAS_MAIL_SMTP_BULK_PER_MINUTE', 3),
            ],
            // Quota del gestionale sul workspace Emailit condiviso (al giorno: gestionale 4.000, portale 500, supporto 200,
            // margine 300); al mese il 90% dei crediti del piano.
            'emailit' => [
                'per_second' => (int) env('SAAS_MAIL_EMAILIT_PER_SECOND', 2),
                'per_hour' => (int) env('SAAS_MAIL_EMAILIT_PER_HOUR', 900),
                'per_day' => (int) env('SAAS_MAIL_EMAILIT_PER_DAY', 4000),
                'bulk_per_day' => (int) env('SAAS_MAIL_EMAILIT_BULK_PER_DAY', 3500),
                'per_month' => (int) env('SAAS_MAIL_EMAILIT_PER_MONTH', 900000),
            ],
        ],
    ],

    // Copia di sicurezza completa (Core\Export\FullExports): si prepara in coda, a riposo è cifrata con la chiave
    // dell'associazione e resta scaricabile per keep_hours ore (PHP la decifra mentre la manda); poi il file si cancella
    // (saas:tmp:purge).
    'export' => [
        'keep_hours' => (int) env('SAAS_EXPORT_KEEP_HOURS', 6),
    ],

    // Ambiente demo (docs/PIATTAFORMA.md §6): acceso solo sul server demo (APP_ENV=demo), mai in produzione.
    // `saas:demo:reset` cancella il database e lo ricrea con dati finti; gli accessi sono pubblici per ruolo.
    'demo' => [
        'enabled' => filter_var(env('SAAS_DEMO', false), FILTER_VALIDATE_BOOLEAN),
        // Nome del database della demo: l'azzeramento si rifiuta se il database collegato è un altro (obbligatorio).
        'database' => env('SAAS_DEMO_DATABASE', ''),
        // L'applicazione e i suoi file devono stare in una cartella con questo nome: altrimenti niente azzeramento.
        'path_marker' => env('SAAS_DEMO_PATH_MARKER', 'saas-demo'),
        'org_name' => 'Polisportiva Demo',
        'org_slug' => env('SAAS_DEMO_SLUG', 'polisportiva-demo'),
        // Quante persone fisiche ha l'associazione dimostrativa: più sono, più il reset notturno dura (ricevute comprese).
        'people' => env('SAAS_DEMO_PEOPLE', 400),
        // Password pubblica degli utenti di prova (titolare@, segreteria@, commercialista@ su mail_domain).
        'password' => env('SAAS_DEMO_PASSWORD', 'demo-demo-demo-2026'),
        'mail_domain' => env('SAAS_DEMO_MAIL_DOMAIN', 'demosaas.associazionesemplice.it'),
    ],

    // Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md): /saas/admin si apre con il token `staff_sso` del portale.
    // Spento finché SAAS_STAFF_SSO non è true; spento resta l'accesso locale dello staff.
    'staff_sso' => [
        'enabled' => filter_var(env('SAAS_STAFF_SSO', false), FILTER_VALIDATE_BOOLEAN),
        // Dove mandare chi non ha sessione (vuoto = <portale>/staff/accedi?area=saas).
        'login_url' => env('SAAS_STAFF_LOGIN_URL'),
    ],

    // Il SaaS come fornitore di identità dello staff (docs/UTENTI_E_ACCESSI.md): spento finché SAAS_STAFF_ISSUER non è true.
    'staff_issuer' => [
        'enabled' => filter_var(env('SAAS_STAFF_ISSUER', false), FILTER_VALIDATE_BOOLEAN),
        'issuer' => env('SAAS_STAFF_ISSUER_URL', 'https://associazionesemplice.it/saas'),
        'request_issuer' => env('SAAS_STAFF_REQUEST_ISSUER', 'https://associazionesemplice.it'),
        // Seme Ed25519 (base64, 32 byte) della chiave staff-… che firma gli staff_sso.
        'signing_key' => env('SAAS_STAFF_SIGNING_KEY'),
        // Chiavi pubbliche delle aree: "kid=x@area,…" (area = wordpress|supporto).
        'area_keys' => env('SAAS_STAFF_AREA_KEYS', ''),
        // Email della titolare (admin di tutte le aree, sempre): solo qui, mai nel repository.
        'super_admins' => env('SAAS_STAFF_SUPER_ADMINS', ''),
        // Dove porta il link degli inviti (vuoto = <APP_URL>/saas): dietro il proxy del portale è https://associazionesemplice.it/saas.
        'invite_base' => env('SAAS_STAFF_INVITE_BASE', ''),
    ],

    // Accesso unico dello staff (docs/LOGIN_UNICO_E_DATABASE.md): l'accesso è /accedi del portale; acceso, i vecchi moduli
    // di /saas/admin rinviano lì. Spento finché SAAS_UNIFIED_LOGIN non è true.
    'unified_login' => [
        'enabled' => filter_var(env('SAAS_UNIFIED_LOGIN', false), FILTER_VALIDATE_BOOLEAN),
        // Anche gli operatori delle associazioni entrano nel gestionale da /accedi (passo 6); spento finché SAAS_UNIFIED_OPERATORS non è true.
        'operators' => filter_var(env('SAAS_UNIFIED_OPERATORS', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Registro delle azioni: cancellare le partizioni più vecchie dell'anno precedente (saas:audit:prune). Spento finché non si accende.
    'audit_prune_enabled' => filter_var(env('SAAS_AUDIT_PRUNE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Contratto con il portale (docs/CONTRATTO_PORTALE.md).
    'portal' => [
        'url' => env('SAAS_PORTAL_URL', 'https://associazionesemplice.it'),
        // Chiavi pubbliche del portale fissate qui: "kid=x,kid2=x2" (x = chiave pubblica Ed25519 in base64url).
        'public_keys' => env('SAAS_PORTAL_PUBLIC_KEYS', ''),
        // Seme della chiave Ed25519 con cui il SaaS firma verso il portale (base64, 32 byte).
        'signing_key' => env('SAAS_PORTAL_SIGNING_KEY'),
        // Giorni dopo la scadenza prima che il SaaS sospenda da sé un'associazione: 0 = sospesa il giorno dopo l'ultimo giorno pagato
        // (decisione del 13/11/2026).
        'grace_days' => 0,
        // Giorni in cui si conservano i dati di un'associazione sospesa o che ha disdetto, poi si cancellano (decisione del 13/11/2026).
        // La cancellazione la fa `saas:portal:purge-expired`, ogni notte solo se `purge_enabled` è acceso.
        'retention_days' => 180,
        // Giorni dopo la fine della prova, senza un piano, prima di cancellare i dati della prova (decisione del 13/11/2026).
        'trial_purge_days' => 7,
        // Avvisi sul ciclo di vita (scadenza, sospensione, cancellazione, fine prova): spenti finché non si accendono.
        'notices_enabled' => filter_var(env('SAAS_NOTICES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Interruttore della cancellazione notturna: spento finché la titolare non dà il via.
        'purge_enabled' => filter_var(env('SAAS_PURGE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Se in una notte i candidati sono di più la cancellazione non parte e avvisa: un errore di data non cancella tutto.
        'purge_max_per_night' => 5,
    ],

    // Dominio personalizzato: il cliente delega il dominio ai nostri nameserver (elenco separato da virgole), noi emettiamo
    // il certificato (cPanel/AutoSSL) sul dominio associato al sottodominio dell'associazione.
    'domains' => [
        'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SAAS_DOMAIN_NAMESERVERS', ''))))),
        // Ore dopo le quali una delega non ancora visibile è segnalata come errore.
        'delegation_hours' => 72,
    ],

    // API firmate (portale e staff): il limite scatta dopo il controllo della firma, con due secchi per indirizzo e per
    // ambito. Richieste con firma valida: `valid_per_minute`; firma assente o non valida: `invalid_per_minute`, oltre
    // il quale rispondono 429 senza altro lavoro. I due secchi non si toccano.
    'signed_api' => [
        'valid_per_minute' => (int) env('SAAS_SIGNED_API_VALID_PER_MINUTE', 120),
        'invalid_per_minute' => (int) env('SAAS_SIGNED_API_INVALID_PER_MINUTE', 10),
    ],

    // Dump orari cifrati (docs/RILASCIO.md). Sul server solo la chiave pubblica; la coppia completa solo dove si fa
    // la prova di ripristino (staging).
    'backup' => [
        'public_key' => env('SAAS_BACKUP_PUBLIC_KEY'),
        'secret_key' => env('SAAS_BACKUP_SECRET_KEY'),
        'pg_dump' => env('SAAS_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('SAAS_PG_RESTORE', 'pg_restore'),
    ],

];

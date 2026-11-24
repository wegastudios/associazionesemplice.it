<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

/**
 * Controllo del file .env della demo (docs/DEMO.md): dice QUALI variabili mancano, sono vuote, sono rimaste sul valore
 * d'esempio o rompono la regola di isolamento. Restituisce solo nomi e motivi, mai valori: l'uscita può finire in un log.
 *
 * Oggi la demo del gestionale ha un account suo (demosaasassociaz), separato da quello della demo del plugin; la regola
 * però vale in ogni caso, anche se un giorno i due dividessero l'account: un difetto in un sito pubblico vicino potrebbe
 * leggere questo file. Per questo nel .env non deve esserci niente che valga altrove (GitHub, portale, posta, Stripe,
 * produzione) e ogni variabile non prevista è un errore.
 */
final class DemoEnvCheck
{
    public const EMPTY = 'manca o è vuota';

    public const PLACEHOLDER = 'ha ancora il valore d\'esempio';

    public const UNEXPECTED = 'non è prevista nella demo: va tolta';

    public const EXTERNAL = 'è un collegamento verso l\'esterno (non deve valere altrove): va tolta';

    /** Variabili che devono avere un valore. */
    public const REQUIRED = [
        'APP_KEY', 'APP_URL', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD',
        'DB_BACKUP_USERNAME', 'DB_BACKUP_PASSWORD', 'SAAS_MASTER_KEY', 'SAAS_FILES_ROOT', 'SAAS_DEMO_DATABASE', 'SAAS_DEMO_SLUG',
        'SAAS_MEMBERS_DOMAIN', 'SAAS_DEMO_MAIL_DOMAIN', 'SESSION_COOKIE', 'SAAS_DEMO_PASSWORD',
    ];

    /** Lunghezza minima della password pubblica degli utenti di prova. */
    public const MIN_PASSWORD = 12;

    /** Le uniche variabili ammesse nel .env della demo (quelle di deploy/env.demo.example e poche opzioni). */
    public const ALLOWED = [
        'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_TIMEZONE',
        'LOG_CHANNEL', 'LOG_DAILY_DAYS', 'LOG_LEVEL',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_SSLMODE', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'DB_BACKUP_USERNAME', 'DB_BACKUP_PASSWORD',
        'SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE', 'SESSION_DOMAIN', 'SESSION_COOKIE', 'SESSION_EXPIRE_ON_CLOSE',
        'QUEUE_CONNECTION', 'CACHE_STORE', 'SAAS_QUEUE_MAX_WORKERS',
        'MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'SAAS_KEY_VAULT', 'SAAS_MASTER_KEY', 'SAAS_FILES_ROOT',
        'SAAS_UNIFIED_OPERATORS', 'SAAS_REQUIRE_TWO_FACTOR',
        'SAAS_DEMO', 'SAAS_DEMO_DATABASE', 'SAAS_DEMO_SLUG', 'SAAS_DEMO_PEOPLE', 'SAAS_DEMO_PASSWORD', 'SAAS_DEMO_MAIL_DOMAIN', 'SAAS_DEMO_PATH_MARKER',
        'SAAS_MEMBERS_DOMAIN',
    ];

    /** Valori casuali che non si confrontano con il modello. */
    private const SECRETS = ['APP_KEY', 'DB_PASSWORD', 'DB_OWNER_PASSWORD', 'DB_BACKUP_PASSWORD', 'SAAS_MASTER_KEY', 'SAAS_DEMO_PASSWORD'];

    /** Nomi che indicano un collegamento a servizi esterni o alla produzione. */
    private const EXTERNAL_PATTERN = '/^(GITHUB|GH_|STRIPE|EMAILIT|SMTP|REDIS|AWS_|SLACK|POSTMARK|RESEND|PAPERTRAIL|MEMCACHED|BEANSTALKD|SQS_|DYNAMODB|PAYPAL)'
        .'|^SAAS_(PORTAL|PROXY|PUBLIC_URL|KEY_SERVICE|BACKUP|STAFF|MAIL_|STRIPE|GITHUB|UNIFIED_LOGIN|DEDICATED|PG_)'
        .'|^MAIL_(HOST|PORT|USERNAME|PASSWORD|URL|SCHEME|EHLO)'
        .'|^DB_(URL|OWNER_URL|RESTORE)/';

    /** Pezzi che segnano un valore lasciato com'era nel modello. */
    private const PLACEHOLDER_PATTERN = '/\bACCOUNT\b|DA_COMPILARE|CAMBIA|\bEXAMPLE\b|CHANGEME|<[^>]*>|app-demo\./';

    /**
     * @param  array<string, string|null>  $env  variabili del file, già lette
     * @return list<array{0: string, 1: string}> nome e motivo, mai il valore
     */
    public static function problems(array $env): array
    {
        $out = [];
        $add = static function (string $name, string $why) use (&$out): void {
            $out[] = [$name, $why];
        };
        $value = static function (string $name) use ($env): string {
            $v = trim((string) ($env[$name] ?? ''));

            return in_array(strtolower($v), ['null', '(null)', '(empty)'], true) ? '' : $v;
        };
        $flag = static fn (string $name): ?bool => match (strtolower($value($name))) {
            'true', '(true)', '1', 'on', 'yes' => true,
            'false', '(false)', '0', 'off', 'no' => false,
            default => null,
        };
        $must = static function (string $name, string $expected, string $why) use ($value, $add): void {
            if ($value($name) !== $expected) {
                $add($name, $why);
            }
        };

        // 1. Variabili obbligatorie, vuote o ancora d'esempio.
        foreach (self::REQUIRED as $name) {
            if ($value($name) === '') {
                $add($name, self::EMPTY);
            }
        }
        // (Chiavi e password sono casuali: lì non si cerca il modello, solo l'uguaglianza più sotto.)
        foreach (array_keys($env) as $name) {
            if (! in_array($name, self::SECRETS, true) && $value($name) !== '' && preg_match(self::PLACEHOLDER_PATTERN, $value($name)) === 1) {
                $add($name, self::PLACEHOLDER);
            }
        }

        // 2. Isolamento: solo variabili previste; niente che punti fuori dalla demo.
        foreach (array_keys($env) as $name) {
            if (in_array($name, self::ALLOWED, true) || $value($name) === '') {
                continue;
            }
            $add($name, preg_match(self::EXTERNAL_PATTERN, $name) === 1 ? self::EXTERNAL : self::UNEXPECTED);
        }
        $host = $value('DB_HOST');
        if ($host !== '' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ! str_starts_with($host, '/')) {
            $add('DB_HOST', 'non è questa macchina (127.0.0.1, localhost o il socket): il database della demo sta qui');
        }

        // 3. Valori fissi della demo.
        $must('APP_ENV', 'demo', 'deve essere «demo» (mai production: l\'azzeramento cancella il database)');
        if ($flag('APP_DEBUG') !== false) {
            $add('APP_DEBUG', 'deve essere false');
        }
        if ($flag('SAAS_DEMO') !== true) {
            $add('SAAS_DEMO', 'deve essere true');
        }
        if ($flag('SAAS_UNIFIED_OPERATORS') !== false) {
            $add('SAAS_UNIFIED_OPERATORS', 'deve essere false (altrimenti l\'accesso rimanda al portale)');
        }
        if ($flag('SAAS_REQUIRE_TWO_FACTOR') !== false) {
            $add('SAAS_REQUIRE_TWO_FACTOR', 'deve essere false (gli accessi di prova sono pubblici)');
        }
        $must('MAIL_MAILER', 'array', 'deve essere «array»: nessuna email parte e nessun indirizzo, codice o link resta scritto nel log');
        $must('SAAS_KEY_VAULT', 'local', 'deve essere «local»: la demo non usa il servizio chiavi');
        $must('SESSION_DRIVER', 'database', 'deve essere «database»');
        $must('QUEUE_CONNECTION', 'database', 'deve essere «database»');
        $must('CACHE_STORE', 'database', 'deve essere «database» (niente Redis sulla demo)');

        // 4. Cookie solo del suo indirizzo: __Host- vuole Secure, percorso «/» e nessun Domain.
        if (! str_starts_with($value('SESSION_COOKIE'), '__Host-')) {
            $add('SESSION_COOKIE', 'deve cominciare con «__Host-»: così nessun cookie del dominio padre lo sostituisce');
        }
        if ($value('SESSION_DOMAIN') !== '') {
            $add('SESSION_DOMAIN', 'deve essere vuota o «null»: nessun Domain nei cookie');
        }
        if ($flag('SESSION_SECURE_COOKIE') !== true) {
            $add('SESSION_SECURE_COOKIE', 'deve essere true');
        }

        // 5. Coerenza tra le variabili: un solo indirizzo per app e area soci, un solo database.
        $slug = $value('SAAS_DEMO_SLUG');
        $domain = $value('SAAS_MEMBERS_DOMAIN');
        $appHost = parse_url($value('APP_URL'), PHP_URL_HOST);
        if ($value('APP_URL') !== '' && parse_url($value('APP_URL'), PHP_URL_SCHEME) !== 'https') {
            $add('APP_URL', 'deve cominciare con https://');
        }
        if ($slug !== '' && $domain !== '' && $value('APP_URL') !== '' && (! is_string($appHost) || strtolower($appHost) !== strtolower($slug.'.'.$domain))) {
            $add('APP_URL', 'l\'indirizzo deve essere SAAS_DEMO_SLUG + «.» + SAAS_MEMBERS_DOMAIN: app e area soci stanno sullo stesso indirizzo');
        }
        if ($value('SAAS_DEMO_DATABASE') !== $value('DB_DATABASE')) {
            $add('SAAS_DEMO_DATABASE', 'deve essere uguale a DB_DATABASE');
        }
        $root = str_replace('\\', '/', $value('SAAS_FILES_ROOT'));
        if ($root !== '' && ! in_array('saas-demo', explode('/', $root), true)) {
            $add('SAAS_FILES_ROOT', 'deve stare dentro la cartella saas-demo');
        }

        // 6. Ruoli del database e chiavi: tutti diversi tra loro (un valore uguale a un altro è un valore che vale altrove).
        $groups = [
            ['DB_USERNAME', 'DB_OWNER_USERNAME', 'DB_BACKUP_USERNAME'],
            ['DB_PASSWORD', 'DB_OWNER_PASSWORD', 'DB_BACKUP_PASSWORD', 'APP_KEY', 'SAAS_MASTER_KEY', 'SAAS_DEMO_PASSWORD'],
        ];
        foreach ($groups as $names) {
            $seen = [];
            foreach ($names as $name) {
                $v = $value($name);
                if ($v === '') {
                    continue;
                }
                if (isset($seen[$v])) {
                    $add($name, 'è uguale a '.$seen[$v].': devono essere diversi');
                } else {
                    $seen[$v] = $name;
                }
            }
        }
        $key = $value('APP_KEY');
        if ($key !== '' && (! str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32)) {
            $add('APP_KEY', 'non è una chiave valida (php artisan key:generate --show)');
        }
        // Password pubblica degli utenti di prova: scritta (non vuota, già controllato sopra) e di almeno 12 caratteri.
        $demoPassword = $value('SAAS_DEMO_PASSWORD');
        if ($demoPassword !== '' && mb_strlen($demoPassword) < self::MIN_PASSWORD) {
            $add('SAAS_DEMO_PASSWORD', 'è troppo corta (almeno '.self::MIN_PASSWORD.' caratteri)');
        }
        $master = $value('SAAS_MASTER_KEY');
        if ($master !== '' && strlen($master) < 32) {
            $add('SAAS_MASTER_KEY', 'è troppo corta');
        }

        return $out;
    }
}

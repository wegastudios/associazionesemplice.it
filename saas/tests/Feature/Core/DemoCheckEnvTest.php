<?php

declare(strict_types=1);

use App\Modules\Core\Demo\DemoEnvCheck;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;

/** Un .env della demo completo e corretto, con valori inventati. */
function goodDemoEnv(): array
{
    return [
        'APP_NAME' => 'Associazione Semplice (demo)', 'APP_ENV' => 'demo', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)), 'APP_DEBUG' => 'false',
        'APP_URL' => 'https://demosaas.associazionesemplice.it', 'APP_LOCALE' => 'it', 'APP_FALLBACK_LOCALE' => 'it',
        'LOG_CHANNEL' => 'daily', 'LOG_DAILY_DAYS' => '7', 'LOG_LEVEL' => 'warning',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'acct_demo',
        'DB_USERNAME' => 'acct_app', 'DB_PASSWORD' => 'pw-uno-inventata', 'DB_OWNER_USERNAME' => 'acct_own', 'DB_OWNER_PASSWORD' => 'pw-due-inventata',
        'DB_BACKUP_USERNAME' => 'acct_bk', 'DB_BACKUP_PASSWORD' => 'pw-tre-inventata',
        'SESSION_DRIVER' => 'database', 'SESSION_LIFETIME' => '120', 'SESSION_ENCRYPT' => 'true', 'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_DOMAIN' => 'null', 'SESSION_COOKIE' => '__Host-as_demo', 'SESSION_EXPIRE_ON_CLOSE' => 'true',
        'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database',
        'MAIL_MAILER' => 'log', 'MAIL_FROM_ADDRESS' => 'demo@associazionesemplice.it', 'MAIL_FROM_NAME' => 'Associazione Semplice (demo)',
        'SAAS_KEY_VAULT' => 'local', 'SAAS_MASTER_KEY' => str_repeat('m', 40), 'SAAS_FILES_ROOT' => '/home/utentedemo/saas-demo/shared/storage/app/org-files',
        'SAAS_UNIFIED_OPERATORS' => 'false', 'SAAS_REQUIRE_TWO_FACTOR' => 'false',
        'SAAS_DEMO' => 'true', 'SAAS_DEMO_DATABASE' => 'acct_demo', 'SAAS_DEMO_SLUG' => 'demosaas', 'SAAS_DEMO_PEOPLE' => '400',
        'SAAS_DEMO_PASSWORD' => 'password-demo-inventata', 'SAAS_DEMO_MAIL_DOMAIN' => 'demosaas.associazionesemplice.it',
        'SAAS_MEMBERS_DOMAIN' => 'associazionesemplice.it',
    ];
}

/** @param  array<string, string|null>  $env */
function demoEnvProblemNames(array $env): array
{
    return array_values(array_unique(array_map(static fn (array $p): string => $p[0], DemoEnvCheck::problems($env))));
}

it('un .env della demo corretto non ha problemi', function (): void {
    expect(DemoEnvCheck::problems(goodDemoEnv()))->toBe([]);
});

it('il modello env.demo.example ha solo i punti da riempire, e riempiti non dà problemi', function (): void {
    $example = Dotenv::parse((string) file_get_contents(dirname(__DIR__, 3).'/deploy/env.demo.example'));

    expect(array_values(array_diff(array_keys($example), DemoEnvCheck::ALLOWED)))->toBe([]);
    $names = demoEnvProblemNames($example);
    sort($names);
    expect($names)->toBe(['APP_KEY', 'DB_BACKUP_PASSWORD', 'DB_BACKUP_USERNAME', 'DB_DATABASE', 'DB_OWNER_PASSWORD', 'DB_OWNER_USERNAME', 'DB_PASSWORD', 'DB_USERNAME', 'SAAS_DEMO_DATABASE', 'SAAS_FILES_ROOT', 'SAAS_MASTER_KEY']);

    $filled = array_merge($example, array_intersect_key(goodDemoEnv(), array_flip($names)));
    expect(DemoEnvCheck::problems($filled))->toBe([]);
    // Il modello dice cosa vale per i cookie e per l'indirizzo unico.
    expect($example['SESSION_COOKIE'])->toStartWith('__Host-')->and($example['APP_URL'])->toBe('https://demosaas.associazionesemplice.it')
        ->and($example['SAAS_DEMO_SLUG'].'.'.$example['SAAS_MEMBERS_DOMAIN'])->toBe('demosaas.associazionesemplice.it');
});

dataset('errori del .env della demo', [
    'chiave vuota' => [['APP_KEY' => ''], 'APP_KEY', DemoEnvCheck::EMPTY],
    'variabile tolta' => [['DB_PASSWORD' => null], 'DB_PASSWORD', DemoEnvCheck::EMPTY],
    'percorso d\'esempio' => [['SAAS_FILES_ROOT' => '/home/ACCOUNT/saas-demo/shared/storage/app/org-files'], 'SAAS_FILES_ROOT', DemoEnvCheck::PLACEHOLDER],
    'vecchio indirizzo' => [['APP_URL' => 'https://app-demo.associazionesemplice.it'], 'APP_URL', DemoEnvCheck::PLACEHOLDER],
    'indirizzo diverso da slug e dominio' => [['APP_URL' => 'https://altro.associazionesemplice.it'], 'APP_URL', 'SAAS_DEMO_SLUG'],
    'ambiente production' => [['APP_ENV' => 'production'], 'APP_ENV', 'demo'],
    'demo spenta' => [['SAAS_DEMO' => 'false'], 'SAAS_DEMO', 'true'],
    'posta vera' => [['MAIL_MAILER' => 'smtp'], 'MAIL_MAILER', 'log'],
    'accesso unico acceso' => [['SAAS_UNIFIED_OPERATORS' => 'true'], 'SAAS_UNIFIED_OPERATORS', 'false'],
    'accesso unico non scritto' => [['SAAS_UNIFIED_OPERATORS' => null], 'SAAS_UNIFIED_OPERATORS', 'false'],
    'servizio chiavi' => [['SAAS_KEY_VAULT' => 'remote'], 'SAAS_KEY_VAULT', 'local'],
    'database della demo diverso' => [['SAAS_DEMO_DATABASE' => 'altro_db'], 'SAAS_DEMO_DATABASE', 'DB_DATABASE'],
    'cookie senza __Host-' => [['SESSION_COOKIE' => 'as_demo'], 'SESSION_COOKIE', '__Host-'],
    'cookie con dominio' => [['SESSION_DOMAIN' => '.associazionesemplice.it'], 'SESSION_DOMAIN', 'Domain'],
    'cookie non Secure' => [['SESSION_SECURE_COOKIE' => 'false'], 'SESSION_SECURE_COOKIE', 'true'],
    'cache Redis' => [['CACHE_STORE' => 'redis'], 'CACHE_STORE', 'Redis'],
    'database su un\'altra macchina' => [['DB_HOST' => 'db.altrove.example'], 'DB_HOST', 'questa macchina'],
    'ruolo uguale' => [['DB_OWNER_USERNAME' => 'acct_app'], 'DB_OWNER_USERNAME', 'diversi'],
    'password uguale' => [['DB_BACKUP_PASSWORD' => 'pw-uno-inventata'], 'DB_BACKUP_PASSWORD', 'diversi'],
    'chiave principale troppo corta' => [['SAAS_MASTER_KEY' => 'base64:'.'x'], 'SAAS_MASTER_KEY', 'corta'],
    'file fuori da saas-demo' => [['SAAS_FILES_ROOT' => '/home/utentedemo/saas/shared/storage/app/org-files'], 'SAAS_FILES_ROOT', 'saas-demo'],
]);

it('segnala ogni errore con il nome della variabile e un motivo, senza il valore', function (array $change, string $name, string $reason): void {
    $env = array_merge(goodDemoEnv(), $change);
    $found = array_values(array_filter(DemoEnvCheck::problems($env), static fn (array $p): bool => $p[0] === $name));

    expect($found)->not->toBeEmpty()->and($found[0][1])->toContain($reason);
    foreach (DemoEnvCheck::problems($env) as [, $why]) {
        foreach ($change as $value) {
            if (is_string($value) && strlen($value) > 12) {
                expect($why)->not->toContain($value);
            }
        }
    }
})->with('errori del .env della demo');

it('regola di isolamento: nessun collegamento verso GitHub, portale, posta, Stripe, produzione', function (): void {
    $env = array_merge(goodDemoEnv(), [
        'GITHUB_TOKEN' => 'ghp_finto', 'SAAS_PORTAL_URL' => 'https://associazionesemplice.it', 'SAAS_PORTAL_SIGNING_KEY' => 'finta',
        'EMAILIT_PASSWORD' => 'finta', 'MAIL_HOST' => 'mail.example.test', 'MAIL_PASSWORD' => 'finta', 'STRIPE_SECRET' => 'sk_finta',
        'SAAS_KEY_SERVICE_URL' => 'https://chiavi.example.test', 'SAAS_PROXY_SECRET' => 'finto', 'REDIS_HOST' => '/tmp/redis.sock',
        'DB_URL' => 'pgsql://u:p@host/db', 'SAAS_UNIFIED_LOGIN' => 'true',
        'EMAILIT_HOST' => '', // vuota: non punta da nessuna parte
        'VARIABILE_STRANA' => 'x',
    ]);
    $problems = [];
    foreach (DemoEnvCheck::problems($env) as [$name, $why]) {
        $problems[$name] = $why;
    }

    foreach (['GITHUB_TOKEN', 'SAAS_PORTAL_URL', 'SAAS_PORTAL_SIGNING_KEY', 'EMAILIT_PASSWORD', 'MAIL_HOST', 'MAIL_PASSWORD', 'STRIPE_SECRET', 'SAAS_KEY_SERVICE_URL', 'SAAS_PROXY_SECRET', 'REDIS_HOST', 'DB_URL', 'SAAS_UNIFIED_LOGIN'] as $name) {
        expect($problems[$name] ?? null)->toBe(DemoEnvCheck::EXTERNAL, $name);
    }
    expect($problems['VARIABILE_STRANA'])->toBe(DemoEnvCheck::UNEXPECTED)->and($problems)->not->toHaveKey('EMAILIT_HOST');
});

it('il comando dice quali variabili non vanno e non stampa mai i valori', function (): void {
    $env = array_merge(goodDemoEnv(), ['MAIL_MAILER' => 'smtp', 'STRIPE_SECRET' => 'sk_segretissima_finta', 'DB_PASSWORD' => '']);
    $file = (string) tempnam(sys_get_temp_dir(), 'demoenv');
    file_put_contents($file, implode("\n", array_map(static fn (string $k, ?string $v): string => $k.'="'.$v.'"', array_keys($env), $env))."\n");

    try {
        expect(Artisan::call('saas:demo:check-env', ['--file' => $file]))->toBe(1);
        $out = Artisan::output();
        expect($out)->toContain('MAIL_MAILER')->toContain('STRIPE_SECRET')->toContain('DB_PASSWORD')
            ->not->toContain('sk_segretissima_finta')->not->toContain('pw-due-inventata')->not->toContain('password-demo-inventata')
            ->not->toContain((string) $env['APP_KEY'])->not->toContain((string) $env['SAAS_MASTER_KEY']);

        file_put_contents($file, implode("\n", array_map(static fn (string $k, ?string $v): string => $k.'="'.$v.'"', array_keys(goodDemoEnv()), goodDemoEnv()))."\n");
        expect(Artisan::call('saas:demo:check-env', ['--file' => $file]))->toBe(0);
        expect(Artisan::output())->not->toContain('pw-uno-inventata')->not->toContain('password-demo-inventata');
    } finally {
        @unlink($file);
    }

    expect(Artisan::call('saas:demo:check-env', ['--file' => $file.'-non-esiste']))->toBe(1);
});

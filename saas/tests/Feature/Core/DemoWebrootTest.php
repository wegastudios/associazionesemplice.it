<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Cartella pubblica della demo (soluzione B, docs/DEMO.md): public_html resta una cartella vera di cPanel con file stabili.
 * Si prova il blocco .htaccess (cosa si serve, cosa mai) e lo script che lo installa: i blocchi di cPanel, .user.ini,
 * php.ini e .well-known restano intatti, l'installazione si ripete senza duplicare, il ritorno indietro è esatto.
 */

const CPANEL_HTACCESS = "# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler application/x-httpd-ea-php83 .php\n# php -- END cPanel-generated handler, do not edit\n"
    ."php_value error_log \"/home/x/public_html/error_log\"\nphp_flag log_errors On\n";

function deployFile(string $name): string
{
    return dirname(__DIR__, 3).'/deploy/'.$name;
}

/** Una finta casa: ~/saas-demo con una versione in uso e ~/public_html come lo lascia cPanel. */
function fakeDemoHome(): string
{
    $home = sys_get_temp_dir().'/demo-home-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/saas-demo/releases/r1/public/assets');
    File::put($home.'/saas-demo/releases/r1/public/index.php', "<?php\n");
    File::put($home.'/saas-demo/releases/r1/public/favicon.ico', 'x');
    File::put($home.'/saas-demo/releases/r1/public/assets/a.css', 'x');
    symlink($home.'/saas-demo/releases/r1', $home.'/saas-demo/current');
    File::ensureDirectoryExists($home.'/public_html/.well-known/pki-validation');
    File::put($home.'/public_html/.well-known/pki-validation/prova.txt', 'dcv');
    File::put($home.'/public_html/.htaccess', CPANEL_HTACCESS);
    File::put($home.'/public_html/.user.ini', "log_errors = On\n");
    File::put($home.'/public_html/php.ini', "memory_limit = 256M\n");

    return $home;
}

function webroot(string $home, string $action): Illuminate\Contracts\Process\ProcessResult
{
    return Process::env(['SAAS_BASE' => $home.'/saas-demo', 'DEMO_WEB' => $home.'/public_html', 'HOME' => $home])->run(['bash', deployFile('demo-webroot.sh'), $action]);
}

it('il blocco .htaccess manda tutto all\'app, serve .well-known e i file veri, e vieta il resto', function (): void {
    $conf = (string) file_get_contents(deployFile('demo-htaccess.conf'));

    expect($conf)->toStartWith('# BEGIN AssociazioneSemplice demo')->and(trim($conf))->toEndWith('# END AssociazioneSemplice demo')
        // niente elenco delle cartelle, niente MultiViews
        ->toContain('Options -Indexes')->toContain('Options -MultiViews')
        // AutoSSL/DCV: .well-known passa prima di ogni divieto; ogni altro file o cartella nascosti è vietato
        ->toContain('RewriteRule ^\.well-known/ - [L]')->toContain('RewriteRule (^|/)\. - [F]')
        // .user.ini, php.ini, .env, composer.*, artisan e simili non si servono mai
        ->toContain('\.user\.ini')->toContain('php\.ini')->toContain('\.env')->toContain('composer\.')->toContain('Require all denied')
        // lo stesso rewrite di saas/public/.htaccess: robots.txt e tutto ciò che non è un file vero vanno a index.php
        ->toContain('RewriteRule ^robots\.txt$ index.php [L]')->toContain('RewriteCond %{REQUEST_FILENAME} !-f')->toContain('RewriteRule ^ index.php [L]')
        ->toContain('X-Robots-Tag');
    // .well-known viene prima del divieto dei file nascosti.
    expect(strpos($conf, '^\.well-known/'))->toBeLessThan((int) strpos($conf, '(^|/)\.'));
    // Il blocco non tocca il PHP (quello è di cPanel).
    expect($conf)->not->toContain('php_value')->not->toContain('AddHandler')->not->toContain('php_flag');

    // Il divieto per nome non colpisce i file statici dell'area soci (manifest, mappe, testi, immagini, font), ma sì i file di servizio.
    expect(preg_match('/<FilesMatch "([^"]+)">/', $conf, $found))->toBe(1);
    $deny = '/'.str_replace('/', '\/', $found[1]).'/';
    foreach (['manifest.json', 'manifest.webmanifest', 'site.webmanifest', 'icons.json', 'app.js.map', 'OFL.txt', 'logo.svg', 'Montserrat-Bold.ttf', 'index.php', 'robots.txt'] as $served) {
        expect(preg_match($deny, $served))->toBe(0, $served);
    }
    foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.env', '.env.production', 'artisan', 'phpunit.xml', 'laravel.log', 'deploy.sh', 'dump.sql', 'copia.bak', '.user.ini', 'php.ini', '.htaccess'] as $refused) {
        expect(preg_match($deny, $refused))->toBe(1, $refused);
    }

    // Le direttive di rewrite sono le stesse del .htaccess dell'app.
    $app = (string) file_get_contents(dirname(__DIR__, 3).'/public/.htaccess');
    foreach (['RewriteCond %{REQUEST_FILENAME} !-d', 'RewriteCond %{REQUEST_FILENAME} !-f', 'RewriteRule ^ index.php [L]', 'RewriteRule ^robots\.txt$ index.php [L]'] as $line) {
        expect($app)->toContain($line)->and($conf)->toContain($line);
    }
});

it('l\'avvio fisso carica la versione in uso dal collegamento e azzera la cache dei percorsi', function (): void {
    $home = fakeDemoHome();
    try {
        expect(webroot($home, 'installa')->successful())->toBeTrue();
        $boot = (string) file_get_contents($home.'/public_html/index.php');

        expect($boot)->toContain('associazionesemplice-demo-avvio')->toContain('clearstatcache(true);')
            ->toContain("require dirname(__DIR__).'/saas-demo/current/public/index.php';")
            // il file pubblico dell'app calcola tutto da __DIR__, che per un file incluso è il percorso reale della versione
            ->not->toContain('vendor')->not->toContain('.env');
        expect((string) file_get_contents(dirname(__DIR__, 3).'/public/index.php'))->toContain("__DIR__.'/../vendor/autoload.php'");
    } finally {
        File::deleteDirectory($home);
    }
});

it('installa senza toccare i blocchi di cPanel, .user.ini, php.ini e .well-known, e si può ripetere', function (): void {
    $home = fakeDemoHome();
    $before = [];
    foreach (['.user.ini', 'php.ini', '.well-known/pki-validation/prova.txt'] as $f) {
        $before[$f] = (string) file_get_contents($home.'/public_html/'.$f);
    }
    try {
        $first = webroot($home, 'installa');
        expect($first->successful())->toBeTrue($first->errorOutput());
        $second = webroot($home, 'installa');
        expect($second->successful())->toBeTrue($second->errorOutput());

        $htaccess = (string) file_get_contents($home.'/public_html/.htaccess');
        expect($htaccess)->toStartWith(CPANEL_HTACCESS)
            ->and(substr_count($htaccess, '# BEGIN AssociazioneSemplice demo'))->toBe(1)
            ->and(substr_count($htaccess, '# END AssociazioneSemplice demo'))->toBe(1)
            ->and($htaccess)->toContain('RewriteRule ^ index.php [L]');
        foreach ($before as $f => $content) {
            expect((string) file_get_contents($home.'/public_html/'.$f))->toBe($content, $f);
        }
        // I file statici sono collegamenti alla versione in uso (tramite «current»), non copie.
        foreach (['assets', 'favicon.ico'] as $name) {
            expect(is_link($home.'/public_html/'.$name))->toBeTrue($name)->and(readlink($home.'/public_html/'.$name))->toBe($home.'/saas-demo/current/public/'.$name);
        }
        // Dentro public_html non c'è altro che i file attesi.
        $names = array_values(array_diff((array) scandir($home.'/public_html'), ['.', '..']));
        sort($names);
        expect($names)->toBe(['.htaccess', '.user.ini', '.well-known', 'assets', 'favicon.ico', 'index.php', 'php.ini']);
    } finally {
        File::deleteDirectory($home);
    }
});

it('ripristina rimette .htaccess e index.php come prima e toglie i collegamenti', function (): void {
    $home = fakeDemoHome();
    try {
        webroot($home, 'installa');
        webroot($home, 'installa');
        $back = webroot($home, 'ripristina');
        expect($back->successful())->toBeTrue($back->errorOutput());

        expect((string) file_get_contents($home.'/public_html/.htaccess'))->toBe(CPANEL_HTACCESS)
            ->and(file_exists($home.'/public_html/index.php'))->toBeFalse()
            ->and(is_link($home.'/public_html/assets'))->toBeFalse()->and(is_link($home.'/public_html/favicon.ico'))->toBeFalse()
            ->and((string) file_get_contents($home.'/public_html/.user.ini'))->toBe("log_errors = On\n");
    } finally {
        File::deleteDirectory($home);
    }
});

it('non scrive su un file vero che si chiama come un collegamento e non lavora fuori da saas-demo', function (): void {
    $home = fakeDemoHome();
    File::put($home.'/public_html/favicon.ico', 'mio');
    try {
        $result = webroot($home, 'installa');
        expect($result->successful())->toBeTrue()->and($result->errorOutput())->toContain('favicon.ico')
            ->and((string) file_get_contents($home.'/public_html/favicon.ico'))->toBe('mio');

        $wrong = Process::env(['SAAS_BASE' => $home.'/saas', 'DEMO_WEB' => $home.'/public_html', 'HOME' => $home])->run(['bash', deployFile('demo-webroot.sh'), 'stato']);
        expect($wrong->failed())->toBeTrue()->and($wrong->errorOutput())->toContain('saas-demo');
    } finally {
        File::deleteDirectory($home);
    }
});

it('lo script non scrive mai su .user.ini, php.ini, .well-known e non cancella a caso', function (): void {
    $script = (string) file_get_contents(deployFile('demo-webroot.sh'));

    foreach (explode("\n", $script) as $line) {
        if (str_starts_with(ltrim($line), '#')) {
            continue;
        }
        // comandi che scrivono o cancellano: mai insieme ai nomi che non si toccano
        if (preg_match('/(>|\brm\b|\bmv\b|\bcp\b|\bln\b|\bchmod\b|\btouch\b)/', $line) === 1) {
            expect($line)->not->toMatch('/\.user\.ini|php\.ini|\.well-known/');
        }
    }
    expect($script)->not->toContain('rm -rf')->not->toContain('--force');
    // la prova richiede dal dominio ciò che non deve rispondere
    foreach (['/.env', '/storage/logs/laravel.log', '/vendor/autoload.php', '/composer.json', '/releases', '/.user.ini', '/php.ini'] as $path) {
        expect($script)->toContain($path);
    }
});

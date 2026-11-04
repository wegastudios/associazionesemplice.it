<?php

declare(strict_types=1);

/*
 * Regole del codice: tipi stretti, niente funzioni di debug o pericolose, moduli che si parlano solo dalle
 * interfacce pubbliche (namespace Contracts).
 */

arch('PHP pulito')->preset()->php();

arch('niente funzioni pericolose')->preset()->security();

it('dichiara i tipi stretti in ogni file PHP', function (): void {
    $base = dirname(__DIR__, 2);
    $missing = [];

    foreach (['app', 'bootstrap', 'config', 'keyservice', 'routes', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$dir}", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                continue;
            }
            if (! str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
                $missing[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }
    }

    expect($missing)->toBe([]);
});

arch('i modelli delle associazioni estendono OrgModel')
    ->expect('App\Modules\Soci\Models')
    ->toExtend('App\Modules\Core\Contracts\OrgModel');

arch('le classi di Core sono final o interfacce')
    ->expect('App\Modules\Core')
    ->classes()
    ->toBeFinal()
    ->ignoring(['App\Modules\Core\Contracts\OrgModel']);

it('fa parlare i moduli solo dalle interfacce pubbliche', function (): void {
    $violations = [];
    $modulesDir = dirname(__DIR__, 2).'/app/Modules';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($modulesDir) + 1);
        $module = explode('/', $relative)[0];
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)\\\\([A-Za-z]+)/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as [$reference, $otherModule, $part]) {
            if ($otherModule !== $module && $part !== 'Contracts') {
                $violations[] = "{$relative} usa {$reference}";
            }
        }
    }

    expect(array_unique($violations))->toBe([]);
});

it('non mette chiavi o password nel codice', function (): void {
    $dirs = ['app', 'config', 'routes', 'bootstrap', 'database'];
    $base = dirname(__DIR__, 2);
    $found = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$dir}", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/(sk_live_|pk_live_|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|base64:[A-Za-z0-9+\/]{40,})/', $source) === 1) {
                $found[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

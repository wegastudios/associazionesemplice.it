<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('toglie solo i file temporanei vecchi e conta senza cancellare', function (): void {
    $dir = storage_path('app/tmp');
    @mkdir($dir.'/sotto', 0700, true);
    file_put_contents($dir.'/vecchio-prova', 'x');
    file_put_contents($dir.'/sotto/vecchio-prova', 'x');
    file_put_contents($dir.'/nuovo-prova', 'x');
    touch($dir.'/vecchio-prova', time() - 7200);
    touch($dir.'/sotto/vecchio-prova', time() - 7200);

    expect(Artisan::call('saas:tmp:purge', ['--count' => true]))->toBe(0)
        ->and(file_exists($dir.'/vecchio-prova'))->toBeTrue();

    expect(Artisan::call('saas:tmp:purge'))->toBe(0)
        ->and(file_exists($dir.'/vecchio-prova'))->toBeFalse()
        ->and(file_exists($dir.'/sotto/vecchio-prova'))->toBeFalse()
        ->and(file_exists($dir.'/nuovo-prova'))->toBeTrue();
    @unlink($dir.'/nuovo-prova');
});

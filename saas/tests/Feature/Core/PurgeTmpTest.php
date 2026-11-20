<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
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

it('toglie anche i dump in chiaro e gli archivi dell\'esportazione rimasti da più di un\'ora', function (): void {
    $dir = storage_path('app/tmp');
    @mkdir($dir, 0700, true);
    // Nomi come quelli dei lavori veri: dump (32 caratteri esadecimali), archivio e cartella di lavoro dell'esportazione.
    $dump = $dir.'/'.bin2hex(random_bytes(16));
    $zip = $dir.'/export-'.bin2hex(random_bytes(16)).'.zip';
    $work = $dir.'/export-'.bin2hex(random_bytes(16));
    mkdir($work, 0700);
    file_put_contents($dump, 'dump');
    file_put_contents($zip, 'zip');
    file_put_contents($work.'/1', 'parte');
    foreach ([$dump, $zip, $work.'/1'] as $file) {
        touch($file, time() - 3 * 3600);
    }
    $recent = $dir.'/'.bin2hex(random_bytes(16));
    file_put_contents($recent, 'in corso');

    expect(Artisan::call('saas:tmp:purge', ['--minutes' => 60]))->toBe(0)
        ->and(file_exists($dump))->toBeFalse()
        ->and(file_exists($zip))->toBeFalse()
        ->and(file_exists($work.'/1'))->toBeFalse()
        ->and(is_dir($work))->toBeFalse()
        ->and(file_exists($recent))->toBeTrue();
    @unlink($recent);
});

it('è pianificato ogni quarto d\'ora, con una soglia di un\'ora', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->command, 'saas:tmp:purge'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and((string) $events[0]->command)->toContain('--minutes=60');
});

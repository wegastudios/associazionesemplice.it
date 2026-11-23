<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * Spazio dei file per la quota (H33): un contatore per associazione invece di scorrere tutti i file a ogni caricamento.
 * Stessi numeri della lettura dal disco dopo ogni salvataggio e cancellazione, compresa la copia di sicurezza completa
 * (cifrata, resta sul disco finché è scaricabile); riallineato di notte.
 */

afterEach(function (): void {
    Storage::disk('org_files')->deleteDirectory('/');
});

/** Lo spazio letto dal disco, come faceva il controllo della quota prima del contatore. */
function diskBytes(string $orgId): int
{
    $disk = Storage::disk('org_files');

    return array_sum(array_map(static fn (string $p): int => $disk->size($p), $disk->allFiles($orgId)));
}

function storeBytes(string $orgId, int $bytes, string $name = 'a.bin'): StoredFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'as');
    file_put_contents($path, random_bytes($bytes));

    return app(EncryptedFiles::class)->store($orgId, $path, $name, 'application/octet-stream');
}

it('il contatore dà gli stessi numeri del disco dopo ogni salvataggio e cancellazione', function (): void {
    $files = app(EncryptedFiles::class);
    $alfa = $this->createOrg('Polisportiva Alfa')->id;
    $beta = $this->createOrg('Circolo Beta')->id;
    $same = static function () use ($files, $alfa, $beta): void {
        expect($files->usedBytes($alfa))->toBe(diskBytes($alfa))
            ->and($files->usedBytes($beta))->toBe(diskBytes($beta));
    };

    $same();
    $a = storeBytes($alfa, 1000);
    $same();
    storeBytes($alfa, 70000);
    storeBytes($beta, 500);
    $same();
    // La copia di sicurezza completa: si salva cifrata, conta finché c'è, poi si cancella.
    $copy = storeBytes($alfa, 300000, 'copia-di-sicurezza.zip');
    $same();
    $files->delete($alfa, $copy);
    $same();
    $files->delete($alfa, $a);
    $files->delete($alfa, $a); // già cancellato: non toglie due volte
    $same();
    expect($files->usedBytes($alfa))->toBeGreaterThan(70000)->toBeLessThan(71000);
});

it('il controllo della quota non riconta il disco a ogni caricamento; di notte il contatore si riallinea', function (): void {
    $files = app(EncryptedFiles::class);
    $alfa = $this->createOrg('Polisportiva Alfa')->id;
    storeBytes($alfa, 1000);
    $before = $files->usedBytes($alfa);

    // Un file arrivato sul disco senza passare dal salvataggio: la lettura non lo vede, perché non scorre il disco.
    Storage::disk('org_files')->put($alfa.'/2026/fuori-conto', str_repeat('x', 5000));
    expect($files->usedBytes($alfa))->toBe($before);

    $this->artisan('saas:files:usage')->assertSuccessful();
    expect($files->usedBytes($alfa))->toBe($before + 5000)->toBe(diskBytes($alfa));
});

it('il riallineamento del contatore gira ogni notte, dopo l\'azzeramento della demo', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->command, 'saas:files:usage'),
    ));

    expect($events)->toHaveCount(1)->and($events[0]->expression)->toBe('51 4 * * *');
});

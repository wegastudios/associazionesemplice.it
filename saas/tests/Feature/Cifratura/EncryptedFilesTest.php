<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use Illuminate\Support\Facades\Storage;

function files(): EncryptedFiles
{
    return app(EncryptedFiles::class);
}

function tempFileWith(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'as');
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    Storage::disk('org_files')->deleteDirectory('/');
});

it('cifra e rilegge file di ogni dimensione', function (int $size): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $contents = $size === 0 ? '' : random_bytes($size);

    $stored = files()->store($org->id, tempFileWith($contents), 'certificato medico.pdf', 'application/pdf');

    expect($stored->size)->toBe($size)
        ->and($stored->sha256)->toBe(hash('sha256', $contents))
        ->and($stored->path)->toStartWith($org->id.'/')
        ->and(files()->read($org->id, $stored))->toBe($contents);
})->with([0, 10, 65536, 65537, 300000]);

it('conta lo spazio occupato da ogni associazione, senza mescolarle', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    expect(files()->usedBytes($alfa->id))->toBe(0);

    files()->store($alfa->id, tempFileWith(random_bytes(1000)), 'a.bin', 'application/octet-stream');
    files()->store($alfa->id, tempFileWith(random_bytes(2000)), 'b.bin', 'application/octet-stream');
    files()->store($beta->id, tempFileWith(random_bytes(500)), 'c.bin', 'application/octet-stream');

    // Il file cifrato è un po' più grande dell'originale (intestazione e controllo di ogni blocco).
    expect(files()->usedBytes($alfa->id))->toBeGreaterThan(3000)->toBeLessThan(3300)
        ->and(files()->usedBytes($beta->id))->toBeGreaterThan(500)->toBeLessThan(600);
});

it('sul disco il file non è leggibile', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $contents = str_repeat('Certificato medico di Mario Rossi. ', 100);

    $stored = files()->store($org->id, tempFileWith($contents), 'certificato.txt', 'text/plain');

    expect(Storage::disk('org_files')->get($stored->path))->not->toContain('Mario Rossi');
});

it('rifiuta un file alterato o troncato', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $stored = files()->store($org->id, tempFileWith(random_bytes(100000)), 'a.bin', 'application/octet-stream');
    $disk = Storage::disk('org_files');
    $original = (string) $disk->get($stored->path);

    $tampered = $original;
    $tampered[200] = $tampered[200] === 'a' ? 'b' : 'a';
    $disk->put($stored->path, $tampered);
    expect(fn () => files()->read($org->id, $stored))->toThrow(DecryptionFailed::class);

    $disk->put($stored->path, substr($original, 0, 65536 + 50));
    expect(fn () => files()->read($org->id, $stored))->toThrow(DecryptionFailed::class);
});

it('non apre i file di un\'altra associazione, nemmeno copiati', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $stored = files()->store($alfa->id, tempFileWith('riservato'), 'a.txt', 'text/plain');

    expect(fn () => files()->read($beta->id, $stored))->toThrow(DecryptionFailed::class);

    $copyPath = $beta->id.'/copia';
    Storage::disk('org_files')->copy($stored->path, $copyPath);
    $copy = new StoredFile($copyPath, 'a.txt', 'text/plain', $stored->size, $stored->sha256);

    expect(fn () => files()->read($beta->id, $copy))->toThrow(DecryptionFailed::class)
        ->and(fn () => files()->read($beta->id, new StoredFile($beta->id.'/../'.$stored->path, 'a', 'text/plain', 1, '')))->toThrow(DecryptionFailed::class);
});

it('scarica come allegato, senza cache, con un nome sicuro', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $stored = files()->store($org->id, tempFileWith('<html><script>alert(1)</script></html>'), "../../ricevuta\n2026.html", 'text/html');

    $response = files()->download($org->id, $stored);
    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($stored->originalName)->toBe('ricevuta_2026.html')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body)->toBe('<html><script>alert(1)</script></html>');
});

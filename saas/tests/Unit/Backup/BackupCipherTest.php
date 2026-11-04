<?php

declare(strict_types=1);

use App\Modules\Backup\Crypto\BackupCipher;

/** @return resource */
function memoryStream(string $contents = '')
{
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function encryptBackup(string $plain, string $public): string
{
    $out = memoryStream();
    BackupCipher::encrypt(memoryStream($plain), $out, $public);
    rewind($out);

    return (string) stream_get_contents($out);
}

function decryptBackup(string $cipher, string $pair): string
{
    $out = memoryStream();
    BackupCipher::decrypt(memoryStream($cipher), $out, $pair);
    rewind($out);

    return (string) stream_get_contents($out);
}

beforeEach(function (): void {
    $this->pair = sodium_crypto_box_keypair();
    $this->public = sodium_crypto_box_publickey($this->pair);
});

it('cifra con la sola chiave pubblica e decifra con la coppia', function (int $size): void {
    $plain = $size === 0 ? '' : random_bytes($size);
    $cipher = encryptBackup($plain, $this->public);

    expect(decryptBackup($cipher, $this->pair))->toBe($plain);
})->with([0, 100, 65536, 200000]);

it('non si apre con un\'altra chiave, alterato o troncato', function (): void {
    $cipher = encryptBackup(str_repeat('dati del dump ', 10000), $this->public);

    $tampered = $cipher;
    $tampered[300] = $tampered[300] === 'x' ? 'y' : 'x';

    expect(fn () => decryptBackup($cipher, sodium_crypto_box_keypair()))->toThrow(RuntimeException::class)
        ->and(fn () => decryptBackup($tampered, $this->pair))->toThrow(RuntimeException::class)
        ->and(fn () => decryptBackup(substr($cipher, 0, -20), $this->pair))->toThrow(RuntimeException::class)
        ->and(encryptBackup('uguale', $this->public))->not->toBe(encryptBackup('uguale', $this->public));
});

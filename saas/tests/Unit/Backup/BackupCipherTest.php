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

it('porta database e ora nel dato autenticato: cambiarli rende il file illeggibile', function (): void {
    $meta = ['created_at' => '2026-11-20T10:00:00Z', 'database' => 'shared', 'env' => 'production'];
    $out = memoryStream();
    BackupCipher::encrypt(memoryStream('dati del dump'), $out, $this->public, $meta);
    rewind($out);
    $cipher = (string) stream_get_contents($out);

    $opened = BackupCipher::decrypt(memoryStream($cipher), memoryStream(), $this->pair);
    expect($opened)->toBe(['legacy' => false, 'meta' => $meta]);

    // Stessa lunghezza, ora diversa: i blocchi non si aprono.
    $changed = str_replace('2026-11-20T10', '2026-11-20T11', $cipher);
    expect($changed)->not->toBe($cipher)
        ->and(fn () => decryptBackup($changed, $this->pair))->toThrow(RuntimeException::class);
});

it('apre ancora il formato vecchio e dice che è vecchio', function (): void {
    $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
    $legacy = "ASBK\x01".sodium_crypto_box_seal($fileKey, $this->public).$header
        .sodium_crypto_secretstream_xchacha20poly1305_push($state, 'dati vecchi', "ASBK\x01", SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

    $out = memoryStream();
    $opened = BackupCipher::decrypt(memoryStream($legacy), $out, $this->pair);
    rewind($out);

    expect($opened)->toBe(['legacy' => true, 'meta' => []])
        ->and(stream_get_contents($out))->toBe('dati vecchi');
});

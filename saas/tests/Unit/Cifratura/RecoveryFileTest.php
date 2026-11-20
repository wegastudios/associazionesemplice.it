<?php

declare(strict_types=1);

use App\Modules\Cifratura\Keys\RecoveryFile;

/*
 * Formato della copia di recupero: Argon2id + XChaCha20-Poly1305, intestazione autenticata. Qui con i limiti più
 * bassi ammessi, per fare in fretta.
 */

const RECOVERY_PASSPHRASE = 'cavallo batteria graffetta corretta';

function recoverySealFast(array $items, string $passphrase = RECOVERY_PASSPHRASE): string
{
    return RecoveryFile::seal($items, $passphrase, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
}

it('riapre con la frase giusta le stesse voci, senza testo in chiaro nel file', function (): void {
    $master = base64_encode(random_bytes(32));
    $sealed = recoverySealFast(['saas_master_key' => $master, 'service_url' => 'https://chiavi.example.test/']);

    expect(RecoveryFile::looksLike($sealed))->toBeTrue()
        ->and($sealed)->not->toContain($master)
        ->and($sealed)->not->toContain('chiavi.example.test')
        ->and(RecoveryFile::open($sealed, RECOVERY_PASSPHRASE)['items'])->toBe(['saas_master_key' => $master, 'service_url' => 'https://chiavi.example.test/']);
});

it('rifiuta la frase sbagliata senza dire altro', function (): void {
    $sealed = recoverySealFast(['saas_master_key' => base64_encode(random_bytes(32))]);

    expect(fn () => RecoveryFile::open($sealed, 'un\'altra frase abbastanza lunga'))
        ->toThrow(RuntimeException::class, 'Frase segreta sbagliata o copia di recupero danneggiata.');
});

it('non si apre se cambia un byte, anche nell\'intestazione', function (int $offset): void {
    $sealed = recoverySealFast(['saas_master_key' => base64_encode(random_bytes(32))]);
    $sealed[$offset] = chr(ord($sealed[$offset]) ^ 0x01);

    expect(fn () => RecoveryFile::open($sealed, RECOVERY_PASSPHRASE))->toThrow(RuntimeException::class);
})->with([
    'versione' => 4,
    'parametri' => 8,
    'sale' => 20,
    'nonce' => 40,
    'testo cifrato' => 60,
]);

it('rifiuta frasi corte, voci sconosciute e file che non sono copie', function (): void {
    expect(fn () => recoverySealFast(['saas_master_key' => 'x'], 'corta'))->toThrow(RuntimeException::class, 'almeno 16 caratteri')
        ->and(fn () => recoverySealFast(['password_database' => 'x']))->toThrow(RuntimeException::class, 'Voce non ammessa')
        ->and(fn () => RecoveryFile::open(str_repeat('x', 200), RECOVERY_PASSPHRASE))->toThrow(RuntimeException::class, 'non è una copia di recupero');
});

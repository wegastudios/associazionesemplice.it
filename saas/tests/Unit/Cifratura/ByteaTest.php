<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\Bytea;

/** @return resource */
function byteStream(string $bytes)
{
    $s = fopen('php://memory', 'w+b');
    if ($s === false) {
        throw new RuntimeException('stream');
    }
    fwrite($s, $bytes);

    return $s;
}

it('legge i byte dello stream così come sono, anche se cominciano con «\\x»', function (): void {
    $raw = '\\x'.random_bytes(30);
    expect(Bytea::decode(byteStream($raw)))->toBe($raw)
        ->and(Bytea::decode(byteStream("\x00\x01ab")))->toBe("\x00\x01ab");
});

it('legge il formato esadecimale di PostgreSQL e scrive quello', function (): void {
    $bin = random_bytes(32);
    expect(Bytea::decode(Bytea::encode($bin)))->toBe($bin)
        ->and(Bytea::encode("\x00\xff"))->toBe('\\x00ff')
        ->and(fn () => Bytea::decode('\\xzz'))->toThrow(UnexpectedValueException::class)
        ->and(fn () => Bytea::decode(12))->toThrow(UnexpectedValueException::class);
});

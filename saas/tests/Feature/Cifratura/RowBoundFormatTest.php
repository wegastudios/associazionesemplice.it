<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\FieldCipher;

it('lascia invariato il formato storico senza id riga', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $cipher = app(FieldCipher::class);

    $text = $cipher->encrypt($org->id, 'people.iban', 'IT60X0542811101000000123456');

    expect($text[0])->toBe("\x01")
        ->and($cipher->decrypt($org->id, 'people.iban', $text))->toBe('IT60X0542811101000000123456')
        ->and($cipher->decrypt($org->id, 'people.iban', $text, 'qualsiasi-id'))->toBe('IT60X0542811101000000123456');
});

it('cifra e decifra legando il testo alla riga', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $cipher = app(FieldCipher::class);

    $text = $cipher->encrypt($org->id, 'people.iban', 'segreto', 'riga-1');

    expect($text[0])->toBe("\x02")
        ->and($cipher->decrypt($org->id, 'people.iban', $text, 'riga-1'))->toBe('segreto');
});

it('non apre il testo legato a una riga con id sbagliato o mancante', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $cipher = app(FieldCipher::class);
    $text = $cipher->encrypt($org->id, 'people.iban', 'segreto', 'riga-1');

    expect(fn () => $cipher->decrypt($org->id, 'people.iban', $text, 'riga-2'))->toThrow(DecryptionFailed::class)
        ->and(fn () => $cipher->decrypt($org->id, 'people.iban', $text))->toThrow(DecryptionFailed::class)
        ->and(fn () => $cipher->decrypt($org->id, 'people.iban', $text, ''))->toThrow(DecryptionFailed::class);
});

it('non apre il testo legato a una riga spostato in un altro campo o in un\'altra associazione', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Polisportiva Beta');
    $cipher = app(FieldCipher::class);
    $text = $cipher->encrypt($alfa->id, 'people.iban', 'segreto', 'riga-1');

    expect(fn () => $cipher->decrypt($alfa->id, 'people.phone', $text, 'riga-1'))->toThrow(DecryptionFailed::class)
        ->and(fn () => $cipher->decrypt($beta->id, 'people.iban', $text, 'riga-1'))->toThrow(DecryptionFailed::class);
});

it('non scambia un formato con l\'altro cambiando il primo byte', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $cipher = app(FieldCipher::class);
    $old = $cipher->encrypt($org->id, 'people.iban', 'segreto');
    $bound = $cipher->encrypt($org->id, 'people.iban', 'segreto', 'riga-1');

    expect(fn () => $cipher->decrypt($org->id, 'people.iban', "\x02".substr($old, 1), 'riga-1'))->toThrow(DecryptionFailed::class)
        ->and(fn () => $cipher->decrypt($org->id, 'people.iban', "\x01".substr($bound, 1), 'riga-1'))->toThrow(DecryptionFailed::class);
});

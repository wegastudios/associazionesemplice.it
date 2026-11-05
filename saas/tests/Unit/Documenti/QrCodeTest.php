<?php

declare(strict_types=1);

use App\Modules\Documenti\Contracts\QrCode;

// Casi portati da QrCodeTest del plugin.

it('calcola la correzione Reed-Solomon come l\'esempio dello standard', function (): void {
    $data = [16, 32, 12, 86, 97, 128, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17];
    expect(QrCode::rsEncode($data, 10))->toBe([165, 36, 212, 193, 237, 54, 199, 135, 44, 85]);
});

it('calcola i bit di formato e di versione', function (): void {
    $expected = [0x5412, 0b101000100100101, 0b101111001111100, 0b101101101001011, 0b100010111111001, 0b100000011001110, 0b100111110010111, 0b100101010100000];
    foreach ($expected as $mask => $bits) {
        expect(QrCode::formatBits($mask))->toBe($bits);
    }
    expect(QrCode::versionBits(7))->toBe(0b000111110010010100)
        ->and(QrCode::versionBits(8))->toBe(0b001000010110111100)
        ->and(QrCode::versionBits(9))->toBe(0b001001101010011001)
        ->and(QrCode::versionBits(10))->toBe(0b001010010011010011);
});

it('sceglie la versione dalla lunghezza e conta le parole di codice', function (): void {
    $limits = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];
    foreach ($limits as $v => $max) {
        expect(QrCode::versionFor($max))->toBe($v);
        if ($v < 10) {
            expect(QrCode::versionFor($max + 1))->toBe($v + 1);
        }
    }
    expect(QrCode::versionFor(214))->toBeNull();
    $totals = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346];
    foreach ($totals as $v => $total) {
        expect(QrCode::codewords('x', $v))->toHaveCount($total);
    }
});

it('costruisce la matrice con i modelli fissi', function (): void {
    $m = QrCode::matrix('https://example.org/?asem_card=1.abcdefabcdefabcdefab');
    $n = count($m);
    expect($n)->toBe(17 + 4 * 4);
    foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as $o) {
        for ($i = 0; $i < 7; $i++) {
            expect($m[$o[0]][$o[1] + $i] && $m[$o[0] + 6][$o[1] + $i] && $m[$o[0] + $i][$o[1]] && $m[$o[0] + $i][$o[1] + 6])->toBeTrue();
        }
        expect($m[$o[0] + 1][$o[1] + 1])->toBeFalse()->and($m[$o[0] + 3][$o[1] + 3])->toBeTrue();
    }
    for ($i = 8; $i < $n - 8; $i++) {
        expect($m[6][$i])->toBe($i % 2 === 0)->and($m[$i][6])->toBe($i % 2 === 0);
    }
    expect($m[$n - 8][8])->toBeTrue()
        ->and(QrCode::matrix('ciao'))->toBe(QrCode::matrix('ciao'))
        ->and(QrCode::matrix('ciao'))->not->toBe(QrCode::matrix('cian'))
        ->and(fn () => QrCode::matrix(str_repeat('a', 214)))->toThrow(InvalidArgumentException::class);
});

it('produce un SVG con l\'etichetta protetta', function (): void {
    $svg = QrCode::svg('https://example.org/', 4, 'Prova <b>');
    expect($svg)->toStartWith('<svg ')->toContain('viewBox="0 0 33 33"')->toContain('aria-label="Prova &lt;b&gt;"')->not->toContain('<b>');
});

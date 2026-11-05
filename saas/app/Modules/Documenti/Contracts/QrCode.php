<?php

declare(strict_types=1);

namespace App\Modules\Documenti\Contracts;

use InvalidArgumentException;

/**
 * Generatore di QR Code (ISO 18004), senza librerie (dal QrCode del plugin): modalità byte, correzione errori M (≈15%),
 * versioni 1–10 (fino a 213 byte). Basta per l'indirizzo di verifica della tessera.
 */
final class QrCode
{
    const MAX_BYTES = 213;

    /** Versione => [byte di correzione per blocco, [[numero di blocchi, byte di dati per blocco], ...]] (livello M). */
    const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Posizioni dei modelli di allineamento per versione. */
    const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int, int> */
    private static array $exp = [];

    /** @var array<int, int> */
    private static array $log = [];

    // ---------- Campo di Galois GF(256), polinomio 0x11D ----------

    private static function tables(): void
    {
        if (self::$exp !== []) {
            return;
        }
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * Codici di correzione (Reed-Solomon) per un blocco di dati.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    public static function rsEncode(array $data, int $ec): array
    {
        self::tables();
        $gen = [1];
        for ($i = 0; $i < $ec; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= self::gfMul($c, self::$exp[$i]);
            }
            $gen = $next;
        }
        $res = array_merge($data, array_fill(0, $ec, 0));
        $n = count($data);
        for ($i = 0; $i < $n; $i++) {
            $coef = $res[$i];
            if ($coef !== 0) {
                foreach ($gen as $j => $g) {
                    $res[$i + $j] ^= self::gfMul($g, $coef);
                }
            }
        }

        return array_slice($res, $n);
    }

    // ---------- Dati ----------

    /** Versione più piccola che contiene $len byte (null se troppo lungo). */
    public static function versionFor(int $len): ?int
    {
        foreach (self::BLOCKS as $v => $b) {
            $cw = 0;
            foreach ($b[1] as $g) {
                $cw += $g[0] * $g[1];
            }
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= 8 * $cw) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Parole di codice (dati + correzione, già interlacciate) per i byte dati.
     *
     * @return list<int>
     */
    public static function codewords(string $data, int $version): array
    {
        $info = self::BLOCKS[$version];
        $cap = 0;
        foreach ($info[1] as $g) {
            $cap += $g[0] * $g[1];
        }
        $bits = '0100'.str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $words = [];
        for ($i = 0, $l = strlen($bits); $i < $l; $i += 8) {
            $words[] = (int) bindec(substr($bits, $i, 8));
        }
        for ($pad = 0; count($words) < $cap; $pad++) {
            $words[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }
        // Blocchi
        $blocks = [];
        $pos = 0;
        foreach ($info[1] as $g) {
            for ($b = 0; $b < $g[0]; $b++) {
                $blocks[] = array_slice($words, $pos, $g[1]);
                $pos += $g[1];
            }
        }
        $ecs = [];
        $max = 0;
        foreach ($blocks as $i => $blk) {
            $ecs[$i] = self::rsEncode($blk, $info[0]);
            $max = max($max, count($blk));
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            foreach ($blocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $info[0]; $i++) {
            foreach ($ecs as $e) {
                $out[] = $e[$i];
            }
        }

        return $out;
    }

    // ---------- Matrice ----------

    /**
     * @return array<int, array<int, bool>> matrice [riga][colonna], true = modulo scuro (senza la zona bianca intorno)
     *
     * @throws InvalidArgumentException se il testo è troppo lungo
     */
    public static function matrix(string $data): array
    {
        $ver = self::versionFor(strlen($data));
        if ($ver === null) {
            throw new InvalidArgumentException('Testo troppo lungo per il QR (massimo '.self::MAX_BYTES.' caratteri).');
        }
        $words = self::codewords($data, $ver);
        $size = 17 + 4 * $ver;
        $best = [];
        $score = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::build($ver, $size, $words, $mask);
            $s = self::penalty($m, $size);
            if ($s < $score) {
                $score = $s;
                $best = $m;
            }
        }

        return array_map(static fn (array $row): array => array_map(static fn (?bool $v): bool => $v === true, $row), $best);
    }

    /**
     * @param  list<int>  $words
     * @return array<int, array<int, bool|null>>
     */
    private static function build(int $ver, int $size, array $words, int $mask): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, null));
        // Modelli di posizione e separatori
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as $o) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $o[0] + $r;
                    $cc = $o[1] + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $m[$rr][$cc] = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6)) || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                }
            }
        }
        // Allineamento
        $al = self::ALIGN[$ver];
        foreach ($al as $ar) {
            foreach ($al as $ac) {
                if ($m[$ar][$ac] !== null) {
                    continue; // si sovrappone a un modello di posizione
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $m[$ar + $r][$ac + $c] = abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0);
                    }
                }
            }
        }
        // Temporizzazione
        for ($i = 8; $i < $size - 8; $i++) {
            if ($m[$i][6] === null) {
                $m[$i][6] = $i % 2 === 0;
            }
            if ($m[6][$i] === null) {
                $m[6][$i] = $i % 2 === 0;
            }
        }
        // Informazioni sul formato (livello M = 00) e modulo scuro fisso
        $fmt = self::formatBits($mask);
        for ($i = 0; $i < 15; $i++) {
            $bit = 1 === (($fmt >> $i) & 1);
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i < 8) {
                $m[$i + 1][8] = $bit;
            } else {
                $m[$size - 15 + $i][8] = $bit;
            }
            if ($i < 8) {
                $m[8][$size - $i - 1] = $bit;
            } elseif ($i < 9) {
                $m[8][15 - $i - 1 + 1] = $bit;
            } else {
                $m[8][15 - $i - 1] = $bit;
            }
        }
        $m[$size - 8][8] = true;
        // Informazioni sulla versione (da 7 in su)
        if ($ver >= 7) {
            $vb = self::versionBits($ver);
            for ($i = 0; $i < 18; $i++) {
                $bit = 1 === (($vb >> $i) & 1);
                $m[(int) floor($i / 3)][$i % 3 + $size - 11] = $bit;
                $m[$i % 3 + $size - 11][(int) floor($i / 3)] = $bit;
            }
        }
        // Dati, a zig-zag dal basso a destra
        $inc = -1;
        $row = $size - 1;
        $bit = 7;
        $byte = 0;
        $n = count($words);
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    if ($m[$row][$col - $c] === null) {
                        $dark = false;
                        if ($byte < $n) {
                            $dark = 1 === (($words[$byte] >> $bit) & 1);
                        }
                        if (self::mask($mask, $row, $col - $c)) {
                            $dark = ! $dark;
                        }
                        $m[$row][$col - $c] = $dark;
                        $bit--;
                        if ($bit === -1) {
                            $byte++;
                            $bit = 7;
                        }
                    }
                }
                $row += $inc;
                if ($row < 0 || $size <= $row) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }

        return $m;
    }

    private static function mask(int $p, int $i, int $j): bool
    {
        switch ($p) {
            case 0:
                return 0 === ($i + $j) % 2;
            case 1:
                return $i % 2 === 0;
            case 2:
                return $j % 3 === 0;
            case 3:
                return 0 === ($i + $j) % 3;
            case 4:
                return 0 === ((int) floor($i / 2) + (int) floor($j / 3)) % 2;
            case 5:
                return 0 === ($i * $j) % 2 + ($i * $j) % 3;
            case 6:
                return 0 === (($i * $j) % 2 + ($i * $j) % 3) % 2;
            default:
                return 0 === (($i * $j) % 3 + ($i + $j) % 2) % 2;
        }
    }

    /** 15 bit di formato: livello M (00) + maschera, con BCH(15,5) e maschera 0x5412. */
    public static function formatBits(int $mask): int
    {
        $d = $mask & 7; // livello M = 00
        $r = $d << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($r >> $i) & 1) {
                $r ^= 0x537 << ($i - 10);
            }
        }

        return (($d << 10) | $r) ^ 0x5412;
    }

    /** 18 bit di versione (da 7 in su): BCH(18,6). */
    public static function versionBits(int $ver): int
    {
        $r = $ver << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($r >> $i) & 1) {
                $r ^= 0x1F25 << ($i - 12);
            }
        }

        return ($ver << 12) | $r;
    }

    /** @param  array<int, array<int, bool|null>>  $m */
    private static function penalty(array $m, int $size): int
    {
        $lost = 0;
        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $v = $m[$r][$c] === true;
                if ($v) {
                    $dark++;
                }
                $same = 0;
                for ($dr = -1; $dr <= 1; $dr++) {
                    for ($dc = -1; $dc <= 1; $dc++) {
                        if (($dr === 0 && $dc === 0) || $r + $dr < 0 || $r + $dr >= $size || $c + $dc < 0 || $c + $dc >= $size) {
                            continue;
                        }
                        if ($v === ($m[$r + $dr][$c + $dc] === true)) {
                            $same++;
                        }
                    }
                }
                if ($same > 5) {
                    $lost += 3 + $same - 5;
                }
                if ($r < $size - 1 && $c < $size - 1) {
                    $s = ($v ? 1 : 0) + ($m[$r + 1][$c] === true ? 1 : 0) + ($m[$r][$c + 1] === true ? 1 : 0) + ($m[$r + 1][$c + 1] === true ? 1 : 0);
                    if ($s === 0 || $s === 4) {
                        $lost += 3;
                    }
                }
                if ($c < $size - 6 && $v && $m[$r][$c + 1] !== true && $m[$r][$c + 2] === true && $m[$r][$c + 3] === true && $m[$r][$c + 4] === true && $m[$r][$c + 5] !== true && $m[$r][$c + 6] === true) {
                    $lost += 40;
                }
                if ($r < $size - 6 && $v && $m[$r + 1][$c] !== true && $m[$r + 2][$c] === true && $m[$r + 3][$c] === true && $m[$r + 4][$c] === true && $m[$r + 5][$c] !== true && $m[$r + 6][$c] === true) {
                    $lost += 40;
                }
            }
        }
        $lost += (int) (abs(100 * $dark / ($size * $size) - 50) / 5) * 10;

        return $lost;
    }

    // ---------- Uscita ----------

    /**
     * QR come immagine SVG (nitida a qualunque dimensione). Le dimensioni si decidono con il CSS (width/height).
     *
     * @param  int  $quiet  moduli bianchi intorno (almeno 4 per una lettura affidabile)
     */
    public static function svg(string $data, int $quiet = 4, string $label = 'Codice QR'): string
    {
        $m = self::matrix($data);
        $n = count($m);
        $full = $n + 2 * $quiet;
        $path = '';
        foreach ($m as $r => $row) {
            $c = 0;
            while ($c < $n) {
                if ($row[$c]) {
                    $start = $c;
                    while ($c < $n && $row[$c]) {
                        $c++;
                    }
                    $path .= 'M'.($start + $quiet).','.($r + $quiet).'h'.($c - $start).'v1h-'.($c - $start).'z';
                } else {
                    $c++;
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$full.' '.$full.'" shape-rendering="crispEdges" role="img" aria-label="'.htmlspecialchars($label, ENT_QUOTES).'">'
            .'<rect width="'.$full.'" height="'.$full.'" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }
}

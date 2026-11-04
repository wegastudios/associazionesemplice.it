<?php

declare(strict_types=1);

namespace App\Modules\Documenti\Contracts;

/**
 * Generatore minimo di PDF (A4, testo, linee, rettangoli, font Helvetica standard), senza librerie esterne: ricevute,
 * libro soci, attestazioni. Dal Pdf del plugin (senza immagini: arriveranno con loghi e tessere). Le coordinate sono in
 * punti misurati dall'angolo in alto a sinistra della pagina.
 */
final class Pdf
{
    /** Larghezze dei caratteri ASCII 32..126 di Helvetica (millesimi di corpo). */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    public readonly float $width;

    public readonly float $height;

    /** @var list<string> contenuto delle pagine finite */
    private array $pages = [];

    private string $cur = '';

    private string $fill = '';

    private bool $dirty = false;

    /** A4 verticale, oppure orizzontale con $landscape. */
    public function __construct(bool $landscape = false)
    {
        $this->width = $landscape ? 842.0 : 595.0;
        $this->height = $landscape ? 595.0 : 842.0;
    }

    public function addPage(): void
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
    }

    public function pageCount(): int
    {
        return count($this->pages) + 1;
    }

    /** Testo UTF-8 → byte Windows-1252 (accenti ed €), con parentesi e barra protette. */
    public static function encode(string $s): string
    {
        $e = (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $e);
    }

    /** Larghezza approssimata di un testo, in punti. */
    public static function textWidth(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        foreach (mb_str_split($s) as $ch) {
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            $w += ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : 556;
        }

        return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
    }

    /** @param  'L'|'R'|'C'  $align  con R $x è il bordo destro, con C il centro */
    public function text(float $x, float $y, string $s, float $size = 11, bool $bold = false, string $align = 'L'): void
    {
        if ($s === '') {
            return;
        }
        if ($align === 'R') {
            $x -= self::textWidth($s, $size, $bold);
        } elseif ($align === 'C') {
            $x -= self::textWidth($s, $size, $bold) / 2;
        }
        $op = '';
        if ($this->fill !== '') {
            $op = $this->fill.' ';
            $this->dirty = true;
        } elseif ($this->dirty) {
            $op = '0 g ';
            $this->dirty = false;
        }
        $this->cur .= sprintf("BT %s/F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $op, $bold ? 2 : 1, $size, $x, $this->height - $y, self::encode($s));
    }

    /** Colore dei testi che seguono (#rrggbb); vuoto = nero. */
    public function color(string $hex = ''): void
    {
        $c = ltrim($hex, '#');
        $this->fill = strlen($c) === 6 && ctype_xdigit($c)
            ? sprintf('%.3F %.3F %.3F rg', hexdec(substr($c, 0, 2)) / 255, hexdec(substr($c, 2, 2)) / 255, hexdec(substr($c, 4, 2)) / 255)
            : '';
    }

    /**
     * Spezza un testo lungo in righe larghe al massimo $width punti.
     *
     * @return list<string>
     */
    public static function wrap(string $s, float $width, float $size = 11, bool $bold = false): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && self::textWidth($try, $size, $bold) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** Taglia un testo con «…» perché stia in $width punti. */
    public static function fit(string $s, float $width, float $size = 11, bool $bold = false): string
    {
        if (self::textWidth($s, $size, $bold) <= $width) {
            return $s;
        }
        while ($s !== '' && self::textWidth($s.'…', $size, $bold) > $width) {
            $s = mb_substr($s, 0, -1);
        }

        return $s.'…';
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6): void
    {
        $this->cur .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $this->height - $y1, $x2, $this->height - $y2);
    }

    /** Rettangolo pieno in un tono di grigio (0 nero … 1 bianco). */
    public function rect(float $x, float $y, float $w, float $h, float $gray = 0.93): void
    {
        $this->cur .= sprintf("%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $this->height - $y - $h, $w, $h);
    }

    /** Il documento completo (byte del file PDF). */
    public function output(): string
    {
        $pages = [...$this->pages, $this->cur];
        $n = count($pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + $i * 2).' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$n.' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $box = sprintf('[0 0 %d %d]', (int) $this->width, (int) $this->height);
        foreach ($pages as $i => $content) {
            $pg = 5 + $i * 2;
            $objs[$pg] = '<< /Type /Page /Parent 2 0 R /MediaBox '.$box.' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($pg + 1).' 0 R >>';
            $objs[$pg + 1] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
        }
        ksort($objs);
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }

        return $out.'trailer << /Size '.(count($objs) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }
}

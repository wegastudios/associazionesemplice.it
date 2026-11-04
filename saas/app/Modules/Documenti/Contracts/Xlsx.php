<?php

declare(strict_types=1);

namespace App\Modules\Documenti\Contracts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lettura di file Excel (.xlsx) senza librerie esterne (dall'Xlsx del plugin). {@see parse()} lavora sulle parti già
 * estratte; {@see read()} apre il file. Le celle diventano testo: i numeri restano numeri, le date «AAAA-MM-GG». Le
 * formule non si calcolano (si usa il risultato salvato da Excel).
 */
final class Xlsx
{
    public const MAX_ROWS = 20000;

    /** 50 MB di XML decompresso per parte: difesa dai file «bomba». */
    public const MAX_ENTRY_SIZE = 52428800;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /** @return list<array{name: string, rows: list<list<string>>, lines: list<int>}> fogli visibili */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Il file non è un Excel (.xlsx) valido.');
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $name = $st['name'];
            if (preg_match('#^xl/(workbook\.xml|_rels/workbook\.xml\.rels|sharedStrings\.xml|styles\.xml|worksheets/[^/]+\.xml)$#', $name) !== 1) {
                continue;
            }
            if ($st['size'] > self::MAX_ENTRY_SIZE) {
                $zip->close();
                throw new InvalidArgumentException('Il file Excel è troppo grande: dividilo in più file o salvalo come CSV.');
            }
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return self::parse($files);
    }

    /**
     * @param  array<string, string>  $files  parti del file (chiave = percorso nello zip)
     * @return list<array{name: string, rows: list<list<string>>, lines: list<int>}>
     */
    public static function parse(array $files): array
    {
        if (($files['xl/workbook.xml'] ?? '') === '') {
            throw new InvalidArgumentException('Il file non è un Excel (.xlsx) valido.');
        }
        $wb = self::xml($files['xl/workbook.xml']);
        $targets = [];
        if (isset($files['xl/_rels/workbook.xml.rels'])) {
            foreach (self::xml($files['xl/_rels/workbook.xml.rels'])->Relationship as $r) {
                $t = (string) $r['Target'];
                $targets[(string) $r['Id']] = ltrim(str_starts_with($t, '/') ? substr($t, 1) : 'xl/'.$t, '/');
            }
        }
        $date1904 = isset($wb->workbookPr) && in_array(strtolower((string) $wb->workbookPr['date1904']), ['1', 'true'], true);
        $shared = isset($files['xl/sharedStrings.xml']) ? self::sharedStrings($files['xl/sharedStrings.xml']) : [];
        $dates = isset($files['xl/styles.xml']) ? self::dateStyles($files['xl/styles.xml']) : [];

        $sheets = [];
        if (isset($wb->sheets)) {
            foreach ($wb->sheets->sheet as $s) {
                if (in_array((string) $s['state'], ['hidden', 'veryHidden'], true)) {
                    continue;
                }
                $rid = (string) ($s->attributes(self::NS_REL)['id'] ?? '');
                $path = $targets[$rid] ?? '';
                if ($path === '' || ($files[$path] ?? '') === '') {
                    continue;
                }
                $sheets[] = ['name' => (string) $s['name'], ...self::sheet($files[$path], $shared, $dates, $date1904)];
            }
        }
        if ($sheets === []) {
            throw new InvalidArgumentException('Nel file Excel non ci sono fogli leggibili.');
        }

        return $sheets;
    }

    public static function isDateFormat(string $code): bool
    {
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./', '', $code) ?? '';

        return preg_match('/[dmyhs]/i', $code) === 1 && preg_match('/^(General|@|0|#)/i', $code) !== 1;
    }

    /** Numero seriale di Excel → «AAAA-MM-GG» (null se fuori intervallo). */
    public static function serialToDate(float $serial, bool $date1904 = false): ?string
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $days = (int) floor($serial);
        $utc = new DateTimeZone('UTC');
        if ($date1904) {
            return (new DateTimeImmutable('1904-01-01', $utc))->modify('+'.$days.' days')->format('Y-m-d');
        }
        if ($days < 60) { // prima del finto 29/02/1900 di Excel
            $days++;
        }

        return (new DateTimeImmutable('1899-12-30', $utc))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /** Indice di colonna (0 = A) da un riferimento come «BC12». */
    public static function columnIndex(string $ref): int
    {
        $n = 0;
        for ($i = 0, $l = strlen($ref); $i < $l; $i++) {
            $c = ord($ref[$i]);
            if ($c < 65 || $c > 90) {
                break;
            }
            $n = $n * 26 + ($c - 64);
        }

        return max(0, $n - 1);
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        // Un XML in UTF-16 (byte nulli) potrebbe nascondere un DOCTYPE: gli .xlsx di Excel sono sempre UTF-8.
        if (str_contains($xml, "\0") || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('Il file Excel contiene dati non ammessi.');
        }
        $xml = (string) preg_replace('/\sxmlns="[^"]*"/', '', $xml);
        if (preg_match('/xmlns:([A-Za-z_][\w.\-]*)="'.preg_quote(self::NS_MAIN, '/').'"/', $xml, $m) === 1) {
            $p = preg_quote($m[1], '/');
            $xml = (string) preg_replace(['/<(\/?)'.$p.':/', '/\sxmlns:'.$p.'="[^"]*"/'], ['<$1', ''], $xml);
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new InvalidArgumentException('Il file Excel è danneggiato.');
        }

        return $x;
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        $out = [];
        foreach (self::xml($xml)->si as $si) {
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $run) {
                $t .= (string) $run->t;
            }
            $out[] = $t;
        }

        return $out;
    }

    /** @return array<int, bool> indici di stile che rappresentano una data */
    private static function dateStyles(string $xml): array
    {
        $x = self::xml($xml);
        $custom = [];
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->numFmt as $f) {
                $custom[(int) $f['numFmtId']] = (string) $f['formatCode'];
            }
        }
        $out = [];
        if (isset($x->cellXfs)) {
            foreach ($x->cellXfs->xf as $xf) {
                $id = (int) $xf['numFmtId'];
                $out[] = in_array($id, self::BUILTIN_DATE_FORMATS, true) || (isset($custom[$id]) && self::isDateFormat($custom[$id]));
            }
        }

        return $out;
    }

    private static function numberText(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1) {
            return $raw;
        }

        return is_numeric($raw) ? rtrim(rtrim(sprintf('%.10F', (float) $raw), '0'), '.') : $raw;
    }

    /**
     * @param  list<string>  $shared
     * @param  array<int, bool>  $dates
     * @return array{rows: list<list<string>>, lines: list<int>}
     */
    private static function sheet(string $xml, array $shared, array $dates, bool $date1904): array
    {
        $x = self::xml($xml);
        $rows = [];
        $lines = [];
        if (! isset($x->sheetData)) {
            return ['rows' => [], 'lines' => []];
        }
        $seq = 0;
        foreach ($x->sheetData->row as $row) {
            $seq++;
            $line = (int) $row['r'] ?: $seq;
            $cells = [];
            $pos = 0;
            foreach ($row->c as $c) {
                $idx = isset($c['r']) ? self::columnIndex((string) $c['r']) : $pos;
                $pos = $idx + 1;
                $t = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : '';
                if ($t === 's') {
                    $val = $shared[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $val = isset($c->is->t) ? (string) $c->is->t : '';
                    foreach ($c->is->r as $run) {
                        $val .= (string) $run->t;
                    }
                } elseif ($t === 'str') {
                    $val = $v;
                } elseif ($t === 'b') {
                    $val = $v === '1' ? '1' : '0';
                } elseif ($t === 'e' || $v === '') {
                    $val = '';
                } elseif (($dates[(int) $c['s']] ?? false) && is_numeric($v)) {
                    $val = self::serialToDate((float) $v, $date1904) ?? $v;
                } else {
                    $val = self::numberText($v);
                }
                $cells[$idx] = $val;
            }
            if ($cells === []) {
                continue;
            }
            $out = [];
            for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                $out[] = $cells[$i] ?? '';
            }
            if (implode('', array_map('trim', $out)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new InvalidArgumentException('Il foglio ha più di '.self::MAX_ROWS.' righe: dividilo in più file.');
            }
            $rows[] = $out;
            $lines[] = $line;
        }

        return ['rows' => $rows, 'lines' => $lines];
    }
}

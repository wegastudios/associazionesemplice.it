<?php

declare(strict_types=1);

namespace App\Modules\Documenti\Contracts;

/** CSV per Excel: UTF-8 con BOM, separatore «;», righe CRLF, celle che sembrano formule precedute da un apostrofo. */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        self::line($out, $header);
        foreach ($rows as $row) {
            self::line($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Una cella che Excel leggerebbe come formula (=, +, -, @ non seguiti da cifre, anche nella forma a larghezza intera
     * «＝» e anche dopo spazi o caratteri invisibili) si apre con un apostrofo.
     */
    public static function cell(string|int|null $v): string
    {
        $v = (string) ($v ?? '');
        if ($v === '') {
            return $v;
        }
        // Spazi, a capo e caratteri invisibili all'inizio non fermano Excel: si guarda il primo carattere che conta.
        $lead = preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+/u', '', $v);
        $lead = $lead ?? $v;
        // Le forme a larghezza intera (＝ ＋ － ＠) si trattano come quelle normali.
        $lead = strtr($lead, ['＝' => '=', '＋' => '+', '－' => '-', '＠' => '@']);
        if ($lead === '') {
            return $v;
        }
        if (in_array($lead[0], ['=', '@'], true) || in_array($v[0], ["\t", "\r"], true)
            || (in_array($lead[0], ['+', '-'], true) && preg_match('/^[+-][\d\s().,]+$/', $lead) !== 1)) {
            return "'".$v;
        }

        return $v;
    }

    /**
     * @param  resource  $out
     * @param  list<string|int|null>  $cells
     */
    private static function line($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), $cells), ';', '"', '', "\r\n");
    }
}

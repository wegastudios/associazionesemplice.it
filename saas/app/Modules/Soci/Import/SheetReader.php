<?php

declare(strict_types=1);

namespace App\Modules\Soci\Import;

use InvalidArgumentException;

/** Apre i file di import (Excel .xlsx o CSV) e li porta alla stessa forma: fogli di righe di testo. Dal plugin. */
final class SheetReader
{
    /** L'intestazione può non essere alla prima riga (titoli sopra la tabella). */
    public const SCAN_ROWS = 12;

    /** @return list<array{name: string, rows: list<list<string>>, lines: list<int>}> */
    public static function read(string $path, string $originalName): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (str_starts_with($head, "\xD0\xCF\x11\xE0") || $ext === 'xls') {
            throw new InvalidArgumentException('Il vecchio formato Excel (.xls) non si legge: in Excel scegli File → Salva con nome → «Cartella di lavoro di Excel (.xlsx)» oppure CSV.');
        }
        if (in_array($ext, ['ods', 'numbers'], true)) {
            throw new InvalidArgumentException('Salva il foglio come Excel (.xlsx) o CSV e ricaricalo.');
        }
        if (str_starts_with($head, 'PK') || $ext === 'xlsx') { // un .xlsx è uno zip
            return Xlsx::read($path);
        }
        $table = PeopleSheet::readTable(PeopleSheet::decode((string) file_get_contents($path)));
        if ($table === []) {
            throw new InvalidArgumentException('Il file è vuoto.');
        }

        return [['name' => 'CSV', 'rows' => $table, 'lines' => range(1, count($table))]];
    }

    /**
     * @param  list<string>  $cells
     * @return list<string> intestazioni normalizzate
     */
    public static function normalizeHeader(array $cells): array
    {
        return array_map(Text::normalize(...), $cells);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $names
     */
    public static function col(array $header, array $names): int
    {
        foreach ($header as $i => $h) {
            if (in_array($h, $names, true)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  callable(list<string>): bool  $accept
     */
    public static function findHeader(array $rows, callable $accept): ?int
    {
        foreach (array_slice($rows, 0, self::SCAN_ROWS, true) as $i => $cells) {
            if ($accept(self::normalizeHeader($cells))) {
                return $i;
            }
        }

        return null;
    }
}

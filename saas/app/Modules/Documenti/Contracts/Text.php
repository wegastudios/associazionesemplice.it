<?php

declare(strict_types=1);

namespace App\Modules\Documenti\Contracts;

/** Testo per confronti: minuscolo, senza accenti, spazi e segni (dal Text del plugin). */
final class Text
{
    public static function normalize(string $s): string
    {
        $s = strtr(mb_strtolower($s, 'UTF-8'), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $s);
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }
}

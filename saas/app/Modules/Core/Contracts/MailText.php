<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Testo scritto da una persona (il nome di un'associazione, per esempio) da mettere dentro un'email: le email passano dal
 * Markdown, dove certe sequenze diventano collegamenti. Qui si tolgono quelle sequenze, così il testo resta testo.
 */
final class MailText
{
    public static function plain(string $text): string
    {
        $text = str_replace(['[', ']', '<', '>', '`'], '', $text);
        $text = str_replace(['://', '@'], [': //', ' @ '], $text);
        $text = (string) preg_replace('/\bwww\./i', 'www ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

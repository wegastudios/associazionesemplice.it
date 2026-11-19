<?php

namespace App\Support;

use App\Models\Article;

/**
 * Testo degli articoli: un sottoinsieme di Markdown, sempre con l'HTML del testo reso innocuo.
 * Titoli (## e ###), elenchi puntati (- ) e numerati (1. ), citazioni (> ), tabelle con |, **grassetto**, *corsivo*, `codice` e collegamenti
 * [testo](https://...) o [testo](/percorso). Qualsiasi altro codice HTML scritto nel testo compare come testo.
 */
class ArticleBody
{
    public static function html(string $body): string
    {
        $lines = preg_split('/\R/', trim($body)) ?: [];
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = rtrim($lines[$i]);

            if ($line === '') {
                $i++;

                continue;
            }

            if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m)) {
                $tag = 'h'.strlen($m[1]);
                $out[] = "<$tag>".self::inline($m[2])."</$tag>";
                $i++;
            } elseif (str_starts_with($line, '|')) {
                $rows = [];
                while ($i < $n && str_starts_with(rtrim($lines[$i]), '|')) {
                    $rows[] = array_map('trim', explode('|', trim(trim(rtrim($lines[$i])), '|')));
                    $i++;
                }
                $out[] = self::table($rows);
            } elseif (preg_match('/^>\s?/', $line)) {
                $text = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $m)) {
                    $text[] = $m[1];
                    $i++;
                }
                $out[] = '<blockquote><p>'.self::inline(implode(' ', $text)).'</p></blockquote>';
            } elseif (preg_match('/^(\d+)\.\s+/', $line)) {
                $out[] = self::list($lines, $i, $n, true);
            } elseif (preg_match('/^-\s+/', $line)) {
                $out[] = self::list($lines, $i, $n, false);
            } else {
                $text = [];
                while ($i < $n && trim($lines[$i]) !== '' && ! preg_match('/^(#{2,3}\s|\||>|\d+\.\s|-\s)/', $lines[$i])) {
                    $text[] = trim($lines[$i]);
                    $i++;
                }
                $out[] = '<p>'.self::inline(implode(' ', $text)).'</p>';
            }
        }

        return implode("\n", $out);
    }

    /**
     * Un elenco numerato o puntato. Sotto una voce possono stare, rientrati (anche dopo una riga vuota),
     * capoversi, sotto-elenchi e passi numerati: appartengono alla voce e l'elenco continua con la voce dopo.
     *
     * @param  array<int, string>  $lines
     */
    private static function list(array $lines, int &$i, int $n, bool $ordered): string
    {
        $marker = $ordered ? '/^(\d+)\.\s+(.*)$/' : '/^()-\s+(.*)$/';
        $items = [];
        $start = null;

        while ($i < $n && preg_match($marker, rtrim($lines[$i]), $m)) {
            $start ??= (int) $m[1];
            $li = self::inline($m[2]);
            $i++;

            $block = [];
            $j = $i;
            while ($j < $n) {
                if (trim($lines[$j]) === '') {
                    $block[] = '';
                    $j++;

                    continue;
                }
                if (! preg_match('/^\s+\S/', $lines[$j])) {
                    break;
                }
                $block[] = rtrim($lines[$j]);
                $j++;
            }

            // le righe vuote in coda non fanno parte della voce
            while ($block !== [] && end($block) === '') {
                array_pop($block);
            }
            if ($block !== []) {
                $indent = min(array_map(fn ($l) => strlen($l) - strlen(ltrim($l)), array_filter($block, fn ($l) => $l !== '')));
                $li .= self::html(implode("\n", array_map(fn ($l) => $l === '' ? '' : substr($l, $indent), $block)));
                $i += count($block);
            }

            $items[] = '<li>'.$li.'</li>';
        }

        if (! $ordered) {
            return '<ul>'.implode('', $items).'</ul>';
        }

        return '<ol'.($start > 1 ? ' start="'.$start.'"' : '').'>'.implode('', $items).'</ol>';
    }

    /** Testo semplice per riassunti, anteprime e meta description: senza segni di formattazione né HTML. */
    public static function plain(string $body): string
    {
        $t = preg_replace('/\[([^\]]+)\]\([^)\s]+\)/u', '$1', $body) ?? $body; // [testo](indirizzo) → testo
        $t = strip_tags($t);
        $t = preg_replace('/^\s*(?:#{1,6}|>|-|\d+\.)\s+/mu', '', $t) ?? $t; // titoli, citazioni, elenchi
        $t = str_replace(['**', '__', '`'], '', $t);
        $t = str_replace('|', ' ', $t);
        $t = preg_replace('/(?<![*\w])\*(?![*\s])([^*]+?)(?<![*\s])\*(?![*\w])/u', '$1', $t) ?? $t;

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** @param  array<int, array<int, string>>  $rows  la prima riga è l'intestazione; una seconda riga di soli trattini si salta */
    private static function table(array $rows): string
    {
        $head = array_shift($rows) ?? [];
        if ($rows !== [] && count(array_filter($rows[0], fn ($c) => ! preg_match('/^:?-{2,}:?$/', $c))) === 0) {
            array_shift($rows);
        }

        $html = '<div class="tablewrap"><table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>'.self::inline($cell).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.self::inline($cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    private static function inline(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*(?![*\s])([^*]+?)(?<![*\s])\*(?![*\w])/u', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;

        return preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/|guida:)[^\s)]+)\)/u', function (array $m): string {
            // [testo](guida:slug) rimanda a un'altra guida del centro assistenza; se non esiste resta solo il testo
            if (str_starts_with($m[2], 'guida:')) {
                $id = Article::query()->where('slug', substr($m[2], 6))->where('is_published', true)
                    ->whereHas('section.topic', fn ($t) => $t->forPublic())->value('id');

                return $id === null ? $m[1] : '<a href="'.route('help.article', $id).'">'.$m[1].'</a>';
            }

            $external = str_starts_with($m[2], 'http');

            return '<a href="'.$m[2].'"'.($external ? ' target="_blank" rel="noopener"' : '').'>'.$m[1].'</a>';
        }, $text) ?? $text;
    }
}

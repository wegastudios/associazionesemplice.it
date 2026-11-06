<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Una voce della Home del gestionale: una cosa da fare, qualcosa che succede oggi o un dato da tenere d'occhio. La
 * tinta (`warn`, `danger`, `info`, `ok`) accompagna sempre le parole, mai da sola.
 */
final readonly class HomeItem
{
    private function __construct(
        public string $section,
        public string $title,
        public ?string $detail,
        public ?string $href,
        public string $tone,
        public ?string $time,
        public int $weight,
    ) {}

    /** Da fare: più il peso è basso, più sale in alto. */
    public static function todo(string $title, string $href, string $tone = 'warn', ?string $detail = null, int $weight = 50): self
    {
        return new self('todo', $title, $detail, $href, $tone, null, $weight);
    }

    /** Oggi: ordinate per ora. */
    public static function today(string $time, string $title, ?string $detail = null, ?string $href = null): self
    {
        return new self('today', $title, $detail, $href, 'info', $time, 0);
    }

    /** Un dato (per esempio il saldo di un conto): il valore va in `detail`. */
    public static function stat(string $title, string $value, ?string $href = null): self
    {
        return new self('stat', $title, $value, $href, 'info', null, 0);
    }

    /** @return array{section: string, title: string, detail: string|null, href: string|null, tone: string, time: string|null} */
    public function toArray(): array
    {
        return ['section' => $this->section, 'title' => $this->title, 'detail' => $this->detail, 'href' => $this->href, 'tone' => $this->tone, 'time' => $this->time];
    }
}

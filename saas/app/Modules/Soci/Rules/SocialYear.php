<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Anno sociale (per esempio dal 1° settembre 2025 al 31 agosto 2026), distinto dall'esercizio contabile. Il mese di
 * inizio è un'impostazione dell'associazione. Con inizio a gennaio l'etichetta è «2026», altrimenti «2025/2026». Dal
 * plugin.
 */
final readonly class SocialYear
{
    public function __construct(
        public int $startYear,
        public int $startMonth,
    ) {}

    public static function forDate(DateTimeInterface|string $date, int $startMonth): self
    {
        $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
        $y = (int) $d->format('Y');

        return new self((int) $d->format('n') >= $startMonth ? $y : $y - 1, $startMonth);
    }

    public static function fromLabel(string $label, int $startMonth): self
    {
        return new self((int) substr($label, 0, 4), str_contains($label, '/') ? $startMonth : 1);
    }

    public function label(): string
    {
        return $this->startMonth === 1 ? (string) $this->startYear : $this->startYear.'/'.($this->startYear + 1);
    }

    public function start(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-01', $this->startYear, $this->startMonth));
    }

    public function end(): DateTimeImmutable
    {
        return $this->start()->modify('+1 year')->modify('-1 day');
    }

    public function next(): self
    {
        return new self($this->startYear + 1, $this->startMonth);
    }

    /** @return list<string> i dodici mesi «AAAA-MM» dell'anno sociale */
    public function months(): array
    {
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[] = $this->start()->modify('+'.$i.' month')->format('Y-m');
        }

        return $out;
    }

    public function previous(): self
    {
        return new self($this->startYear - 1, $this->startMonth);
    }
}

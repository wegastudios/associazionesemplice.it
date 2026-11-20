<?php

declare(strict_types=1);

namespace KeyService;

/** Contatore in memoria: per i test. */
final class MemoryRateCounter implements RateCounter
{
    /** @var array<string, int> */
    private array $counts = [];

    public function hit(string $name, int $now): int
    {
        $key = $name.'-'.intdiv($now, 60);
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key];
    }
}

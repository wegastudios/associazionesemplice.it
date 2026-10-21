<?php

namespace App\Support;

final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
    ) {}

    public static function system(): self
    {
        return new self('system');
    }

    public static function console(string $label = 'cli'): self
    {
        return new self('console', $label);
    }

    /** Un sito di supporto che agisce sull'hub tramite API. */
    public static function site(string $id): self
    {
        return new self('site', $id);
    }

    public static function user(int|string $id): self
    {
        return new self('user', (string) $id);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Contracts;

/**
 * Il catalogo pubblicato (formato catalogo/v1, CONTRATTO_PORTALE.md §2): è ciò che il portale legge. Il documento è
 * immutabile; cambia solo con una nuova pubblicazione, che aumenta `rev`.
 */
final readonly class PublishedCatalog
{
    /** @param  array<string, mixed>  $document */
    public function __construct(public int $rev, public array $document) {}

    /** I byte esatti che si servono, quindi anche quelli che si firmano: stabili finché la `rev` non cambia. */
    public function json(): string
    {
        return json_encode($this->document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Versione e codice di ogni piano del catalogo: serve a chi riceve un abbonamento per sapere se la conosce.
     *
     * @return list<array{code: string, plan_version: int}>
     */
    public function planVersions(): array
    {
        $found = [];
        foreach (is_array($this->document['plans'] ?? null) ? $this->document['plans'] : [] as $plan) {
            if (is_array($plan) && is_string($plan['code'] ?? null) && is_int($plan['plan_version'] ?? null)) {
                $found[] = ['code' => $plan['code'], 'plan_version' => $plan['plan_version']];
            }
        }

        return $found;
    }
}

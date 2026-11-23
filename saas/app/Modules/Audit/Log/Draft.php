<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

/** Riga del registro non ancora nella catena: numero e ora si assegnano quando si lega, con la testa bloccata. */
final readonly class Draft
{
    /** @param  array<mixed>  $details */
    public function __construct(
        public string $orgId,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $ip,
        public array $details,
    ) {}

    public function entry(int $seq, string $occurredAt): Entry
    {
        return new Entry($this->orgId, $seq, $occurredAt, $this->actorType, $this->actorId, $this->action, $this->subjectType, $this->subjectId, $this->ip, $this->details);
    }
}

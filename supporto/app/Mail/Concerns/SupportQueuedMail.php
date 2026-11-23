<?php

namespace App\Mail\Concerns;

use Illuminate\Queue\Middleware\RateLimited;

/**
 * Regole comuni delle email del supporto messe in coda: al massimo 20 invii l'ora (il resto aspetta, non si perde,
 * perché l'account di hosting ha un tetto orario complessivo), nuovi tentativi per un giorno e pause crescenti dopo un rifiuto.
 */
trait SupportQueuedMail
{
    /** @var array<int, int> */
    public $backoff = [60, 300, 900, 3600];

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('support-mail')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }
}

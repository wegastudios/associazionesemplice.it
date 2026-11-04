<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;

/**
 * Middleware dei lavori in coda: esegue il lavoro dentro la sua associazione e la azzera alla fine.
 *
 *   public function middleware(): array { return [new RunInOrgContext]; }
 */
final class RunInOrgContext
{
    /** @param  Closure(OrgScopedJob): mixed  $next */
    public function handle(OrgScopedJob $job, Closure $next): mixed
    {
        /** @var OrgContext $context */
        $context = app(OrgContext::class);

        return $context->run($job->orgId(), static fn (): mixed => $next($job));
    }
}

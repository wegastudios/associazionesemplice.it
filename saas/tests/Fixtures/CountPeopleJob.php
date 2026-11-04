<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Core\Contracts\OrgScopedJob;
use App\Modules\Core\Contracts\RunInOrgContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Lavoro di prova: conta le persone con una query senza filtro. */
final class CountPeopleJob implements OrgScopedJob, ShouldQueue
{
    use Queueable;

    /** @var array<string, int> */
    public static array $seen = [];

    public function __construct(private readonly string $orgId) {}

    public function orgId(): string
    {
        return $this->orgId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunInOrgContext];
    }

    public function handle(): void
    {
        self::$seen[$this->orgId] = DB::table('app.people')->count();
    }
}

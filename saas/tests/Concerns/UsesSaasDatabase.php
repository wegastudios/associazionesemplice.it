<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Org\OrgProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database dei test: PostgreSQL vero, con i due ruoli. Le migrazioni girano una volta per processo con il
 * proprietario; ogni test lavora con il ruolo dell'applicazione dentro una transazione annullata alla fine.
 *
 * Il database dedicato di prova non è nella transazione: useDedicatedDatabase() lo svuota all'inizio.
 */
trait UsesSaasDatabase
{
    use DatabaseTransactions;

    public const DEDICATED = 'dedicato';

    private static bool $sharedMigrated = false;

    private static bool $dedicatedMigrated = false;

    /** @var list<string> */
    protected array $connectionsToTransact = ['pgsql'];

    public function setUpUsesSaasDatabase(): void
    {
        if (! self::$sharedMigrated) {
            $this->assertSame(0, Artisan::call('saas:migrate', ['--fresh' => true, '--database' => [DatabaseRegistry::SHARED]]), Artisan::output());
            self::$sharedMigrated = true;
        }

        $this->beforeApplicationDestroyed(function (): void {
            $this->app?->make(OrgContext::class)->leave();
        });
    }

    protected function useDedicatedDatabase(): void
    {
        $database = getenv('TEST_DEDICATED_DATABASE');
        $this->assertIsString($database, 'TEST_DEDICATED_DATABASE non impostato.');

        $registry = $this->app->make(DatabaseRegistry::class);
        $registry->define(self::DEDICATED, ['database' => $database]);

        if (! self::$dedicatedMigrated) {
            $this->assertSame(0, Artisan::call('saas:migrate', ['--fresh' => true, '--database' => [self::DEDICATED]]), Artisan::output());
            self::$dedicatedMigrated = true;
        }

        // TRUNCATE non è soggetto alla Row-Level Security: lo può fare solo il proprietario.
        DB::connection($registry->ownerConnection(self::DEDICATED))->statement('TRUNCATE app.orgs CASCADE');
    }

    protected function createOrg(string $name, ?string $database = null): Org
    {
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));

        return $this->app->make(OrgProvisioner::class)->create($name, $slug, $database);
    }

    protected function orgContext(): OrgContext
    {
        return $this->app->make(OrgContext::class);
    }

    /** Valore di app.org_id sulla connessione indicata ('' se non impostato). */
    protected function orgSetting(string $connection = 'pgsql'): string
    {
        $value = DB::connection($connection)->scalar("SELECT coalesce(current_setting('app.org_id', true), '')");

        return is_string($value) ? $value : '';
    }
}

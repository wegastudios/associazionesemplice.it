<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Core\Org\OrgProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

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

    public const PASSWORD = 'una-password-di-prova-lunga';

    /** Utente di prova; con $twoFactor ha la verifica in due passaggi confermata (segreto in twoFactorSecret()). */
    protected function createUser(string $email, bool $twoFactor = false): User
    {
        $user = new User;
        $user->name = 'Utente di prova';
        $user->email = $email;
        $user->password = Hash::make(self::PASSWORD);
        if ($twoFactor) {
            $user->two_factor_secret = Fortify::currentEncrypter()->encrypt((new Google2FA)->generateSecretKey());
            $user->two_factor_recovery_codes = Fortify::currentEncrypter()->encrypt((string) json_encode(['codice-uno-1234', 'codice-due-5678']));
            $user->two_factor_confirmed_at = now();
        }
        $user->save();

        return $user->refresh();
    }

    protected function addOperator(Org $org, User $user, OrgRole $role = OrgRole::Owner): void
    {
        DB::table('core.org_users')->insert([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Codice TOTP valido adesso per l'utente. */
    protected function totp(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt((string) $user->fresh()?->two_factor_secret);

        return (new Google2FA)->getCurrentOtp(is_string($secret) ? $secret : '');
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

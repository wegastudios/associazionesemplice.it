<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Persona dello staff della piattaforma. Ruoli: `admin` (può modificare) e `readonly` (solo lettura). Non è un
 * operatore di un'associazione e non ha niente a che fare con `core.users`.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Carbon\CarbonImmutable|null $two_factor_confirmed_at
 * @property \Carbon\CarbonImmutable|null $disabled_at
 * @property \Carbon\CarbonImmutable|null $last_login_at
 */
final class PlatformStaff extends Authenticatable
{
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $connection = 'pgsql';

    protected $table = 'core.platform_staff';

    /** @var list<string> */
    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    public function canWrite(): bool
    {
        return $this->role === 'admin';
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Auth\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use SensitiveParameter;

/**
 * Utente di accesso, globale: una persona attiva in più associazioni ha un solo utente e una scheda
 * (app.people) per ciascuna.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $platform_admin
 * @property \Carbon\CarbonImmutable|null $disabled_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Carbon\CarbonImmutable|null $two_factor_confirmed_at
 * @property \Carbon\CarbonImmutable|null $email_verified_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class User extends Authenticatable
{
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $connection = 'pgsql';

    protected $table = 'core.users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'platform_admin' => 'boolean',
            'disabled_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'immutable_datetime',
        ];
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** @param  string  $token */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}

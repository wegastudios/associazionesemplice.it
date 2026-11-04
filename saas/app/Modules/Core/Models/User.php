<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Utente di accesso, globale: una persona attiva in più associazioni ha un solo utente e una scheda
 * (app.people) per ciascuna.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class User extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $connection = 'pgsql';

    protected $table = 'core.users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}

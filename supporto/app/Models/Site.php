<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un sito di supporto registrato nell'hub, con le sue credenziali e le sue regole. */
class Site extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['secret_hash', 'previous_secret_hash', 'hmac_secret_encrypted'];

    protected function casts(): array
    {
        return [
            'allowed_priorities' => 'array',
            'allow_reopen' => 'boolean',
            'notify_emails' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_secret_expires_at' => 'datetime',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function allowsPriority(int $level): bool
    {
        return in_array($level, array_map('intval', $this->allowed_priorities ?? []), true);
    }

    /** Livelli consentiti, ordinati. @return list<int> */
    public function priorities(): array
    {
        $levels = array_map('intval', $this->allowed_priorities ?? []);
        sort($levels);

        return array_values(array_unique($levels));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Copia centrale di un ticket: l'id e assegnato dall'hub (anonimo e crescente tra tutti i portali). */
class Ticket extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}

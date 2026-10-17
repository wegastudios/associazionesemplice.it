<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ticket del sito di supporto: l'id pubblico (hub_id) e assegnato dall'hub. */
class SupportTicket extends Model
{
    use HasUlids;

    protected $table = 'support_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hub_id' => 'integer',
            'priority' => 'integer',
            'portal_context' => 'array',
            'last_activity_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    /** Il ticket ha ricevuto l'id dall'hub. */
    public function isRegistered(): bool
    {
        return $this->hub_id !== null;
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}

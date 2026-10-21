<?php

namespace App\Services\Site;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\URL;

/** Link firmato e a scadenza con cui il cliente segue il proprio ticket (nessuna password cliente). */
class CustomerLink
{
    public static function show(SupportTicket $ticket, ?int $hours = null): string
    {
        return URL::temporarySignedRoute('ticket.show', $hours !== null ? now()->addHours($hours) : now()->addDays((int) config('support.customer_link_days')), ['hubId' => $ticket->hub_id]);
    }

    public static function reply(SupportTicket $ticket): string
    {
        return URL::temporarySignedRoute('ticket.reply', now()->addDays((int) config('support.customer_link_days')), ['hubId' => $ticket->hub_id]);
    }
}

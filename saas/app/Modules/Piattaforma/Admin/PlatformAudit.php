<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Admin;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Registro delle azioni dell'amministrazione: si aggiunge e basta (nessun permesso di modifica o cancellazione). */
final readonly class PlatformAudit
{
    public function __construct(private DatabaseManager $db) {}

    /** @param  array<string, mixed>  $details  mai password, segreti o dati dei soci */
    public function record(string $action, ?string $staffId, ?string $subject = null, array $details = [], ?Request $request = null): void
    {
        $this->db->connection('pgsql')->table('core.platform_audit')->insert([
            'id' => (string) Str::uuid7(),
            'staff_id' => $staffId,
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}

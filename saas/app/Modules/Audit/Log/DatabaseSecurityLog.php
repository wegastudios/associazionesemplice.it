<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Audit\Contracts\SecurityLog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class DatabaseSecurityLog implements SecurityLog
{
    public function __construct(
        private Application $app,
        private DatabaseManager $db,
    ) {}

    public function record(string $event, ?string $userId, array $details = []): void
    {
        $actor = Actor::current($this->app);

        $this->db->connection('pgsql')->table('core.security_events')->insert([
            'id' => Str::uuid7()->toString(),
            'event' => $event,
            'user_id' => $userId,
            'ip' => $actor->ip,
            'user_agent' => $actor->userAgent,
            'details' => json_encode(Entry::validDetails($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}

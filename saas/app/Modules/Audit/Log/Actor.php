<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Audit\Contracts\ActingMember;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;

/** Chi sta agendo: l'utente collegato, il socio dall'area soci, oppure il sistema (cron, code, comandi). */
final readonly class Actor
{
    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $ip,
        public ?string $userAgent,
    ) {}

    public static function current(Application $app): self
    {
        $id = $app->make(Auth::class)->guard()->id();
        $member = is_string($id) ? null : $app->make(ActingMember::class)->get();
        // In console la richiesta non ha indirizzo: ip() restituisce null.
        $request = $app->bound('request') ? $app->make('request') : null;
        $agent = $request?->userAgent();

        return new self(
            is_string($id) ? 'user' : ($member !== null ? 'member' : 'system'),
            is_string($id) ? $id : $member,
            $request?->ip(),
            $agent === null ? null : mb_substr($agent, 0, 255),
        );
    }
}

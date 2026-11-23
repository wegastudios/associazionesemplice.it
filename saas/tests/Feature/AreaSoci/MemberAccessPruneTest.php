<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Link, codici e sessioni dell'area soci (H30): quelli che non valgono più da oltre 30 giorni si cancellano ogni giorno;
 * quelli validi o scaduti da poco restano. Dati di prova inventati.
 */

it('cancella link, codici e sessioni scaduti da più di 30 giorni e lascia gli altri', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $ids = $this->orgContext()->run($org->id, function () use ($org): array {
        $person = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com'])->id;
        $token = static function (string $expires) use ($org): string {
            $id = Str::uuid7()->toString();
            DB::table('app.member_login_tokens')->insert([
                'org_id' => $org->id, 'id' => $id, 'kind' => 'email', 'email' => 'mario@example.com',
                'link_hash' => hash('sha256', $id), 'code_hash' => hash('sha256', 'c'.$id), 'expires_at' => $expires,
            ]);

            return $id;
        };
        $session = static function (array $times) use ($org, $person): string {
            $id = Str::uuid7()->toString();
            DB::table('app.member_sessions')->insert(['org_id' => $org->id, 'id' => $id, 'token_hash' => hash('sha256', $id), 'person_id' => $person] + $times + [
                'created_at' => now()->subDays(100), 'last_used_at' => now(), 'expires_at' => now()->addDays(10),
            ]);

            return $id;
        };

        return [
            'gone' => [
                $token(now()->subDays(31)->toIso8601String()),
                $session(['expires_at' => now()->subDays(31)]),
                $session(['revoked_at' => now()->subDays(31)]),
                $session(['last_used_at' => now()->subDays(61)]),
            ],
            'kept' => [
                $token(now()->addMinutes(10)->toIso8601String()),
                $token(now()->subDays(29)->toIso8601String()),
                $session([]),
                $session(['expires_at' => now()->subDays(29)]),
                $session(['revoked_at' => now()->subDays(29)]),
                $session(['last_used_at' => now()->subDays(59)]),
            ],
        ];
    });

    $this->artisan('saas:member-access:prune')->expectsOutputToContain('tokens=1 sessions=3')->assertSuccessful();

    $left = $this->orgContext()->run($org->id, static fn (): array => [
        ...DB::table('app.member_login_tokens')->pluck('id')->all(),
        ...DB::table('app.member_sessions')->pluck('id')->all(),
    ]);
    expect($left)->toEqualCanonicalizing($ids['kept']);
});

it('la pulizia degli accessi dei soci gira ogni giorno, fuori dalla notte dei backup e delle verifiche', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->command, 'saas:member-access:prune'),
    ));

    expect($events)->toHaveCount(1)->and($events[0]->expression)->toBe('13 6 * * *');
});

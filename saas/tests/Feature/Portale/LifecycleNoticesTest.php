<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Portale\Console\SendLifecycleNoticesCommand;
use App\Modules\Portale\Jobs\SendLifecycleNotice;
use App\Modules\Portale\Notifications\OrgLifecycleNotification;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/PortalHelpers.php';

it('sceglie l\'avviso giusto per ogni giorno del ciclo', function (): void {
    $config = new PortalConfig(new Repository(['saas' => ['portal' => ['retention_days' => 180, 'trial_purge_days' => 7]]]));
    $n = static fn (string $e, string $o, int $offset): ?string => SendLifecycleNoticesCommand::notice($e, $o, $offset, $config)[0] ?? null;

    expect($n('cancelled', 'active', -30))->toBeNull()          // la scadenza vicina è un avviso sui soldi: lo manda il portale
        ->and($n('past_due', 'active', -7))->toBeNull()
        ->and($n('active', 'active', -7))->toBeNull()          // chi paga non riceve l'avviso di scadenza
        ->and($n('active', 'suspended', 1))->toBe('suspended')
        ->and($n('active', 'suspended', 30))->toBe('purge_30')
        ->and($n('active', 'suspended', 90))->toBe('purge_90')
        ->and($n('active', 'suspended', 150))->toBe('purge_150')
        ->and($n('active', 'suspended', 170))->toBe('purge_170')
        ->and($n('active', 'suspended', 179))->toBe('purge_1')
        ->and($n('active', 'suspended', 180))->toBeNull()
        ->and($n('trial', 'active', -7))->toBe('trial_7')
        ->and($n('trial', 'active', -2))->toBe('trial_2')
        ->and($n('trial', 'active', 0))->toBe('trial_0')
        ->and($n('trial', 'suspended', 3))->toBe('trial_3')
        ->and($n('trial', 'suspended', 6))->toBe('trial_6')
        ->and($n('trial', 'suspended', 8))->toBeNull();
});

it('manda l\'avviso al titolare una sola volta per scadenza', function (): void {
    Notification::fake();
    $portal = fakePortal();
    $orgId = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($portal, 'provision', [
        'ref' => 'sub_n', 'org' => ['name' => 'Alfa', 'slug' => 'alfa-n'], 'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna'],
    ])])->json('org_id');
    applySubscription($portal, subscriptionClaims($orgId, 1, ['ref' => 'sub_n', 'status' => 'suspended', 'until' => now()->subDays(31)->toDateString()]), $orgId);

    $this->artisan('saas:portal:lifecycle-notices')->assertSuccessful();
    Notification::assertSentTimes(OrgLifecycleNotification::class, 1);

    $this->artisan('saas:portal:lifecycle-notices')->assertSuccessful();
    Notification::assertSentTimes(OrgLifecycleNotification::class, 1);
});

it('l\'avviso va in coda e un errore all\'invio non consuma la prenotazione', function (): void {
    $portal = fakePortal();
    $orgId = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($portal, 'provision', [
        'ref' => 'sub_q', 'org' => ['name' => 'Alfa', 'slug' => 'alfa-q'], 'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna'],
    ])])->json('org_id');
    applySubscription($portal, subscriptionClaims($orgId, 1, ['ref' => 'sub_q', 'status' => 'suspended', 'until' => now()->subDays(31)->toDateString()]), $orgId);
    Queue::fake();

    $this->artisan('saas:portal:lifecycle-notices')->expectsOutput('Avvisi in coda: 1')->assertSuccessful();
    Queue::assertPushedOn(MailQueue::BULK, SendLifecycleNotice::class);
    $job = Queue::pushed(SendLifecycleNotice::class)->first();
    expect(DB::table('core.org_notices')->where('org_id', $orgId)->count())->toBe(0);

    // Il server di posta non risponde: l'errore torna al lavoro (che riprova) e la prenotazione resta libera.
    config(['mail.mailers.rotto' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 1], 'mail.default' => 'rotto']);
    expect(fn () => $job->handle(app(DatabaseManager::class)))->toThrow(TransportException::class);
    expect(DB::table('core.org_notices')->where('org_id', $orgId)->count())->toBe(0);

    Notification::fake();
    $job->handle(app(DatabaseManager::class));
    $job->handle(app(DatabaseManager::class));
    Notification::assertSentOnDemandTimes(OrgLifecycleNotification::class, 1);
    expect(DB::table('core.org_notices')->where('org_id', $orgId)->count())->toBe(1);
});

it('ogni avviso del gestionale è nell\'elenco dei suoi e nessuno riguarda i soldi', function (): void {
    $config = new PortalConfig(new Repository(['saas' => ['portal' => ['retention_days' => 180, 'trial_purge_days' => 7]]]));
    $found = [];
    foreach (['active', 'cancelled', 'past_due', 'trial'] as $entitlement) {
        foreach (['active', 'suspended'] as $org) {
            for ($offset = -40; $offset <= 200; $offset++) {
                $kind = SendLifecycleNoticesCommand::notice($entitlement, $org, $offset, $config)[0] ?? null;
                if ($kind !== null) {
                    $found[$kind] = true;
                }
            }
        }
    }

    expect(array_diff(array_keys($found), SendLifecycleNoticesCommand::KINDS))->toBe([])
        ->and(array_diff(SendLifecycleNoticesCommand::KINDS, array_keys($found)))->toBe([])
        ->and(array_filter(SendLifecycleNoticesCommand::KINDS, static fn (string $k): bool => str_starts_with($k, 'expiry')))->toBe([]);
});

it('i testi dicono la data vera e il giusto singolare o plurale', function (): void {
    $this->travelTo('2026-12-01');
    $last = CarbonImmutable::parse('2026-12-10');
    $text = static function (string $kind, int $days) use ($last): string {
        $mail = (new OrgLifecycleNotification($kind, 'Circolo Alfa', $days, 'https://example.com/account', 180, $last))->toMail(new AnonymousNotifiable);

        return $mail->subject."\n".implode("\n", $mail->introLines)."\n".$mail->actionText;
    };

    $suspended = $text('suspended', 0);
    expect($suspended)->toContain('Circolo Alfa · Gestionale sospeso')
        ->and($suspended)->toContain('per 180 giorni dalla scadenza, fino al 10/12/2026; poi vengono eliminati dal gestionale; le copie di sicurezza scadono da sole entro 30 giorni.')
        ->and($suspended)->toContain('Se rinnovi entro il 10/12/2026 ritrovi tutti i dati come li hai lasciati.')
        ->and($suspended)->toContain('Riattiva il gestionale');

    $purge = $text('purge_30', 0);
    expect($purge)->toContain('I dati vengono eliminati dopo il 10/12/2026')->and($purge)->toContain('tra 9 giorni')
        ->and($purge)->toContain('Fino al 10/12/2026 puoi riattivare il gestionale rinnovando l\'abbonamento.');

    $this->travelTo('2026-12-09');
    expect($text('purge_1', 0))->toContain('Ultimo giorno per riattivare: domani');

    expect($text('trial_7', 7))->toContain('La prova finisce tra 7 giorni')
        ->and($text('trial_2', 1))->toContain('La prova finisce domani')
        ->and($text('trial_0', 0))->toContain('La prova finisce oggi')
        ->and($text('trial_3', -3))->toContain('La prova è finita')
        ->and($text('trial_3', -3))->not->toContain('sconto')
        ->and($text('trial_3', -3))->not->toMatch('/(^|\s)0 giorni/')
        ->and($text('trial_3', -3))->not->toMatch('/(^|\s)1 giorni/');
});

it('gli avvisi del ciclo di vita partono alle 9:10, fuori dall\'ora della posta del portale', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => str_contains((string) $e->command, 'saas:portal:lifecycle-notices'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('10 9 * * *');
});

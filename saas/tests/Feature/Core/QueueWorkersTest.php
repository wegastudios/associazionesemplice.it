<?php

declare(strict_types=1);

use App\Modules\Contabilita\Jobs\RunLedgerImport;
use App\Modules\Core\Contracts\JobQueues;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Jobs\BuildFullExport;
use App\Modules\Soci\Jobs\RunPeopleImport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/*
 * Code per tipo di lavoro (H13): i lavori lunghi (copia completa, import) in una coda loro, su una connessione con un
 * tempo di ripresa più lungo del lavoro, e un lavoratore loro; i lavoratori del cron non superano un massimo configurabile
 * (il server ha 25 processi PHP in tutto).
 */

const QW_ORG = '00000000-0000-7000-8000-000000000001';

/**
 * Le classi dei lavori in coda dell'applicazione.
 *
 * @return list<class-string>
 */
function qwJobClasses(): array
{
    $base = dirname(__DIR__, 3).'/app/Modules';
    $classes = [];
    foreach (glob($base.'/*/Jobs/*.php') ?: [] as $file) {
        $class = 'App\\Modules\\'.basename(dirname($file, 2)).'\\Jobs\\'.basename($file, '.php');
        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Un lavoro costruito con argomenti finti (identificativi e valori semplici, come nei lavori veri); null se il costruttore
 * non è pubblico o chiede un oggetto.
 */
function qwMake(string $class): ?object
{
    $constructor = (new ReflectionClass($class))->getConstructor();
    if ($constructor !== null && ! $constructor->isPublic()) {
        return null;
    }
    $args = [];
    foreach ($constructor?->getParameters() ?? [] as $p) {
        $type = $p->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        if (! $p->isDefaultValueAvailable() && ! $p->allowsNull() && ! in_array($name, ['string', 'int', 'bool', 'array'], true)) {
            return null;
        }
        $args[] = match (true) {
            $p->isDefaultValueAvailable() => $p->getDefaultValue(),
            $name === 'string' => QW_ORG,
            $name === 'int' => 1,
            $name === 'bool' => false,
            $name === 'array' => [],
            default => null,
        };
    }

    return new $class(...$args);
}

function qwTimeout(string $class): ?int
{
    $default = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

    return is_int($default) ? $default : null;
}

it('la copia completa va nella coda dei lavori lunghi', function (): void {
    expect((new BuildFullExport(QW_ORG, QW_ORG))->queue)->toBe(JobQueues::LONG);
});

it('ogni lavoro finisce prima che la coda lo riprenda: i brevi sotto i 90 s, i lunghi nella coda lunga', function (): void {
    $shortRetry = config('queue.connections.database.retry_after');
    $longRetry = config('queue.connections.'.JobQueues::LONG_CONNECTION.'.retry_after');

    expect(JobQueues::SHORT_TIMEOUT)->toBeLessThan($shortRetry)
        ->and(JobQueues::LONG_TIMEOUT)->toBeLessThan($longRetry);

    $classes = qwJobClasses();
    expect($classes)->toContain(BuildFullExport::class, RunPeopleImport::class, RunLedgerImport::class);
    foreach ($classes as $class) {
        $timeout = qwTimeout($class) ?? JobQueues::SHORT_TIMEOUT;
        $job = qwMake($class);
        if ($timeout > JobQueues::SHORT_TIMEOUT) {
            expect($job)->not->toBeNull("{$class}: la prova non sa costruirlo, aggiungere il caso in qwMake");
            expect($job->queue ?? null)->toBe(JobQueues::LONG, "{$class}: {$timeout} s, deve stare nella coda lunga")
                ->and($timeout)->toBeLessThanOrEqual(JobQueues::LONG_TIMEOUT, "{$class}: oltre il tempo dei lavori lunghi");
        } elseif ($job !== null) {
            expect($job->queue ?? null)->not->toBe(JobQueues::LONG, "{$class}: un lavoro breve non occupa la coda lunga");
        }
    }
});

/**
 * Connessione e code di un comando queue:work (senza connessione: quella predefinita).
 *
 * @return array{string, list<string>}
 */
function qwParse(string $command): array
{
    $connection = preg_match('/^queue:work ([a-z][a-z-]*) /', $command, $m) === 1 ? $m[1] : (string) config('queue.default');
    preg_match('/--queue=(\S+)/', $command, $q);

    return [$connection, explode(',', $q[1] ?? '')];
}

it('con qualsiasi massimo, un codice di accesso non aspetta mai la ripresa dei lavori lunghi', function (): void {
    config(['queue.default' => 'database']);

    foreach ([0, 1, 2, 9] as $setting) {
        config(['saas.queue.max_workers' => $setting]);
        $workers = JobQueues::workers();
        $connectionOf = [];
        $urgentOnly = 0;
        foreach ($workers as $w) {
            [$connection, $queues] = qwParse($w['command']);
            if ($queues === [MailQueue::URGENT]) {
                $urgentOnly++;
                // Lavoratore suo: ogni minuto, giro corto, esclusione propria di pochi minuti.
                expect($w['command'])->toContain('--max-time=55')->and($w['mutex_minutes'])->toBeLessThanOrEqual(5)->and($w['only_if_queued'])->toBeNull();
            }
            foreach ($queues as $queue) {
                // Ogni coda la prendono solo lavoratori della stessa connessione: il tempo di ripresa è uno solo.
                expect($connectionOf[$queue] ?? $connection)->toBe($connection, "max {$setting}: {$queue} su due connessioni");
                $connectionOf[$queue] = $connection;
            }
        }

        expect($urgentOnly)->toBe(1, "max {$setting}: manca il lavoratore della posta urgente")
            ->and(count($workers))->toBe(JobQueues::maxWorkers() + 1)
            ->and($connectionOf[MailQueue::URGENT])->not->toBe(JobQueues::LONG_CONNECTION)
            ->and(config('queue.connections.'.$connectionOf[MailQueue::URGENT].'.retry_after'))->toBeLessThanOrEqual(JobQueues::URGENT_MAX_RETRY)
            ->and($connectionOf[JobQueues::LONG])->toBe(JobQueues::LONG_CONNECTION);
    }
});

it('di base 2 lavoratori più quello della posta urgente, ognuno con la sua esclusione', function (): void {
    expect(JobQueues::maxWorkers())->toBe(2);

    $workers = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => str_contains((string) $e->command, 'queue:work'));
    $long = $workers->first(static fn ($e): bool => str_contains((string) $e->command, '--queue='.JobQueues::LONG.' ') || str_ends_with((string) $e->command, '--queue='.JobQueues::LONG));

    expect($workers)->toHaveCount(3)
        ->and($long)->not->toBeNull()
        ->and((string) $long->command)->toContain('--stop-when-empty')->toContain('--timeout='.JobQueues::LONG_TIMEOUT)
        ->and($long->expression)->toBe('* * * * *')
        // L'esclusione dura più del lavoro più lungo: nessun secondo lavoratore lungo mentre il primo lavora.
        ->and($long->expiresAt * 60)->toBeGreaterThan(JobQueues::LONG_TIMEOUT)
        ->and($workers->map(static fn ($e): string => $e->mutexName())->unique())->toHaveCount(3);

    // Con le code sul database (il server): il lungo sulla sua connessione, i brevi fermano un lavoro bloccato prima che
    // la coda lo riprenda.
    config(['queue.default' => 'database']);
    foreach (JobQueues::workers(2) as $w) {
        if (str_contains($w['command'], '--queue='.JobQueues::LONG.' ')) {
            expect($w['command'])->toStartWith('queue:work '.JobQueues::LONG_CONNECTION.' ')
                ->and($w['mutex_minutes'] * 60)->toBeGreaterThanOrEqual(config('queue.connections.'.JobQueues::LONG_CONNECTION.'.retry_after'));
        } else {
            expect($w['command'])->toContain('--timeout='.JobQueues::SHORT_TIMEOUT)->not->toContain(JobQueues::LONG_CONNECTION);
        }
    }
});

it('con 1 un solo lavoratore per brevi e lunghi, sulla connessione lunga, oltre a quello degli urgenti', function (): void {
    config(['queue.default' => 'database']);
    $workers = JobQueues::workers(1);
    $other = $workers[1] ?? null;

    expect($workers)->toHaveCount(2)
        // Sulla connessione lunga, così una copia completa non viene ripresa a metà.
        ->and($other['command'])->toStartWith('queue:work '.JobQueues::LONG_CONNECTION.' ')
        ->toContain('--queue='.JobQueues::DEFAULT.','.MailQueue::BULK.','.JobQueues::LONG.' ')
        ->and($other['mutex_minutes'] * 60)->toBeGreaterThan(JobQueues::LONG_TIMEOUT);

    config(['saas.queue.max_workers' => 0]);
    expect(JobQueues::maxWorkers())->toBe(1);
    config(['saas.queue.max_workers' => 9]);
    expect(JobQueues::maxWorkers())->toBe(2);
});

it('il lavoratore dei lunghi parte solo se nella sua coda c\'è un lavoro', function (): void {
    config(['queue.default' => 'database']);
    $long = collect(app(Schedule::class)->events())->first(static fn ($e): bool => str_contains((string) $e->command, '--queue='.JobQueues::LONG.' '));
    $short = collect(app(Schedule::class)->events())->first(static fn ($e): bool => str_contains((string) $e->command, '--queue='.MailQueue::WORKER_QUEUES.' '));

    expect($long->filtersPass(app()))->toBeFalse()
        ->and($short->filtersPass(app()))->toBeTrue();

    DB::table('core.jobs')->insert(['queue' => JobQueues::LONG, 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    expect($long->filtersPass(app()))->toBeTrue();
});

it('i lunghi si riprendono dopo il lavoro più lungo più 5 minuti, dalla stessa tabella', function (): void {
    expect(config('queue.connections.'.JobQueues::LONG_CONNECTION.'.retry_after'))->toBe(JobQueues::LONG_TIMEOUT + 300)
        ->and(config('queue.connections.'.JobQueues::LONG_CONNECTION.'.table'))->toBe(config('queue.connections.database.table'));
});

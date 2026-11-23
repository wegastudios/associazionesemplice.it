<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\JobQueues;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Jobs\BuildFullExport;
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

/** Un lavoro costruito con argomenti finti (solo identificativi e valori semplici, come nei lavori veri). */
function qwMake(string $class): object
{
    $constructor = (new ReflectionClass($class))->getConstructor();
    $args = [];
    foreach ($constructor?->getParameters() ?? [] as $p) {
        $type = $p->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        $args[] = match (true) {
            $p->isDefaultValueAvailable() => $p->getDefaultValue(),
            $name === 'string' => QW_ORG,
            $name === 'int' => 1,
            $name === 'bool' => false,
            $name === 'array' => [],
            $p->allowsNull() => null,
            default => throw new RuntimeException("{$class}: argomento {$p->getName()} di tipo {$name} non previsto dal test"),
        };
    }

    return new $class(...$args);
}

function qwTimeout(string $class): ?int
{
    $default = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

    return is_int($default) ? $default : null;
}

it('la copia completa va nella coda dei lavori lunghi, sulla connessione con il tempo di ripresa lungo', function (): void {
    config(['queue.default' => 'database']);
    $job = new BuildFullExport(QW_ORG, QW_ORG);

    expect($job->queue)->toBe(JobQueues::LONG)
        ->and($job->connection)->toBe(JobQueues::LONG_CONNECTION)
        ->and(config('queue.connections.'.JobQueues::LONG_CONNECTION.'.table'))->toBe(config('queue.connections.database.table'));
});

it('senza code sul database (sync nei test) il lavoro lungo resta sulla connessione predefinita', function (): void {
    config(['queue.default' => 'sync']);

    expect((new BuildFullExport(QW_ORG, QW_ORG))->connection)->toBeNull();
});

it('ogni lavoro finisce prima che la coda lo riprenda: i brevi sotto i 90 s, i lunghi nella coda lunga', function (): void {
    config(['queue.default' => 'database']);
    $shortRetry = config('queue.connections.database.retry_after');
    $longRetry = config('queue.connections.'.JobQueues::LONG_CONNECTION.'.retry_after');

    expect(JobQueues::SHORT_TIMEOUT)->toBeLessThan($shortRetry)
        ->and(JobQueues::LONG_TIMEOUT)->toBeLessThan($longRetry);

    $classes = qwJobClasses();
    expect($classes)->toContain(BuildFullExport::class);
    foreach ($classes as $class) {
        $timeout = qwTimeout($class) ?? JobQueues::SHORT_TIMEOUT;
        $job = qwMake($class);
        if ($timeout > JobQueues::SHORT_TIMEOUT) {
            expect($job->queue ?? null)->toBe(JobQueues::LONG, "{$class}: {$timeout} s, deve stare nella coda lunga")
                ->and($job->connection ?? null)->toBe(JobQueues::LONG_CONNECTION, "{$class}: connessione dei lavori lunghi")
                ->and($timeout)->toBeLessThanOrEqual(JobQueues::LONG_TIMEOUT, "{$class}: oltre il tempo dei lavori lunghi");
        } else {
            expect($job->queue ?? null)->not->toBe(JobQueues::LONG, "{$class}: un lavoro breve non occupa la coda lunga");
        }
    }
});

it('con 3 lavoratori: principale, lavori lunghi e posta urgente, ognuno con la sua esclusione', function (): void {
    expect(JobQueues::maxWorkers())->toBe(3);

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
    foreach (JobQueues::workers(3) as $w) {
        if (str_contains($w['command'], '--queue='.JobQueues::LONG.' ')) {
            expect($w['command'])->toStartWith('queue:work '.JobQueues::LONG_CONNECTION.' ')
                ->and($w['mutex_minutes'] * 60)->toBeGreaterThanOrEqual(config('queue.connections.'.JobQueues::LONG_CONNECTION.'.retry_after'));
        } else {
            expect($w['command'])->toContain('--timeout='.JobQueues::SHORT_TIMEOUT)->not->toContain(JobQueues::LONG_CONNECTION);
        }
    }
});

it('il massimo dei lavoratori si regola: con 2 niente lavoratore a parte per gli urgenti, con 1 uno solo per tutto', function (): void {
    config(['queue.default' => 'database']);
    expect(JobQueues::workers(2))->toHaveCount(2)
        ->and(array_column(JobQueues::workers(2), 'command'))->each->not->toContain('--queue='.MailQueue::URGENT.' ');

    $single = JobQueues::workers(1);
    expect($single)->toHaveCount(1)
        // Uno solo: sulla connessione lunga, così una copia completa non viene ripresa a metà; prima gli urgenti.
        ->and($single[0]['command'])->toContain(' '.JobQueues::LONG_CONNECTION.' ')
        ->toContain('--queue='.MailQueue::URGENT.',default,'.MailQueue::BULK.','.JobQueues::LONG)
        ->and($single[0]['mutex_minutes'] * 60)->toBeGreaterThan(JobQueues::LONG_TIMEOUT);

    config(['saas.queue.max_workers' => 0]);
    expect(JobQueues::maxWorkers())->toBe(1);
    config(['saas.queue.max_workers' => 9]);
    expect(JobQueues::maxWorkers())->toBe(3);
});

it('la coda ha un indice per i lavori in attesa', function (): void {
    $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'core' AND tablename = 'jobs' AND indexname = 'jobs_waiting_idx'");

    expect($index)->not->toBeNull()
        ->and((string) $index->indexdef)->toContain('(queue, available_at)')->toContain('WHERE (reserved_at IS NULL)');
});

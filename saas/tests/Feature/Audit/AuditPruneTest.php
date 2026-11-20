<?php

declare(strict_types=1);

use App\Modules\Audit\Log\Partitions;
use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('con --dry-run elenca le partizioni da cancellare e non tocca niente', function (): void {
    $this->artisan('saas:audit:prune', ['--before' => '2000-01-01', '--dry-run' => true])
        ->expectsOutputToContain('Partizioni da cancellare: 0')->assertSuccessful();
    $this->artisan('saas:audit:prune', ['--dry-run' => true])->assertSuccessful();
});

it('con una data scelta a mano chiede conferma, rifiuta senza terminale e cancella solo con --force', function (): void {
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    Partitions::ensure($owner, CarbonImmutable::parse('2019-12-01', 'UTC'), 1);

    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01'])
        ->expectsConfirmation('Cancellare queste partizioni?', 'no')->assertFailed();
    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--dry-run' => true])->expectsOutputToContain('log_2019_12')->assertSuccessful();

    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--no-interaction' => true])
        ->expectsOutputToContain('--force')->assertFailed();
    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--dry-run' => true])->expectsOutputToContain('log_2019_12')->assertSuccessful();

    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--force' => true])
        ->expectsOutputToContain('cancellata log_2019_12')->assertSuccessful();
    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--dry-run' => true])->expectsOutputToContain('Partizioni da cancellare: 0')->assertSuccessful();
});

it('non scende sotto il limite di conservazione nemmeno con --force', function (): void {
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    Partitions::ensure($owner, CarbonImmutable::parse('2019-12-01', 'UTC'), 1);
    $this->travelTo(CarbonImmutable::parse('2021-03-10 03:00', 'UTC'));

    // Il limite è il 1° gennaio 2020: il 2021 e il 2020 si tengono sempre.
    $this->artisan('saas:audit:prune', ['--before' => '2021-01-01', '--force' => true])
        ->expectsOutputToContain('limite di conservazione')->assertFailed();
    $this->artisan('saas:audit:prune', ['--before' => '2020-01-01', '--dry-run' => true])->expectsOutputToContain('log_2019_12')->assertSuccessful();
});

it('tiene l\'anno solare corrente e il precedente: dal 1° gennaio si cancella l\'anno prima del precedente', function (): void {
    // Partizioni vuote: dicembre 2019, gennaio e febbraio 2020 (l'ambiente di prova non le ha).
    Partitions::ensure(DB::connection(app(DatabaseNames::class)->ownerConnection(null)), CarbonImmutable::parse('2019-12-01'), 3);

    // Il 10 marzo 2021 si tiene il 2020 intero e il 2021: dicembre 2019 è l'unica partizione da togliere.
    $this->travelTo(CarbonImmutable::parse('2021-03-10 03:00', 'UTC'));
    $this->artisan('saas:audit:prune', ['--dry-run' => true])
        ->expectsOutputToContain('log_2019_12')->doesntExpectOutputToContain('log_2020_01')->assertSuccessful();

    // Il 31 dicembre 2020 il 2019 è ancora «anno precedente»: non si toglie niente.
    $this->travelTo(CarbonImmutable::parse('2020-12-31 23:00', 'UTC'));
    $this->artisan('saas:audit:prune', ['--dry-run' => true])->expectsOutputToContain('Partizioni da cancellare: 0')->assertSuccessful();

    // Dal 1° gennaio 2021 il 2019 non serve più.
    $this->travelTo(CarbonImmutable::parse('2021-01-01 03:00', 'UTC'));
    $this->artisan('saas:audit:prune', ['--dry-run' => true])->expectsOutputToContain('log_2019_12')->assertSuccessful();
});

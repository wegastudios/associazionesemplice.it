<?php

declare(strict_types=1);

it('con --dry-run elenca le partizioni da cancellare e non tocca niente', function (): void {
    $this->artisan('saas:audit:prune', ['--before' => '2000-01-01', '--dry-run' => true])
        ->expectsOutputToContain('Partizioni da cancellare: 0')->assertSuccessful();
    $this->artisan('saas:audit:prune', ['--dry-run' => true])->assertSuccessful();
});

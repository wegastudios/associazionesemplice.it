<?php

declare(strict_types=1);

it('in produzione rifiuta --fresh e chiede --force', function (): void {
    $this->app->instance('env', 'production');

    $this->artisan('saas:migrate', ['--fresh' => true, '--force' => true])->assertFailed();
    $this->artisan('saas:migrate')->assertFailed();
});

it('rifiuta un database sconosciuto', function (): void {
    $this->artisan('saas:migrate', ['--database' => ['inesistente']])->assertFailed();
});

it('risponde al controllo di salute', function (): void {
    $this->get('/up')->assertOk();
});

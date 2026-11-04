<?php

declare(strict_types=1);

use App\Modules\Core\Database\DatabaseRegistry;

beforeEach(function (): void {
    $this->registry = new DatabaseRegistry(app('config'));
});

it('elenca il database condiviso per primo', function (): void {
    expect($this->registry->names())->toBe([DatabaseRegistry::SHARED])
        ->and($this->registry->appConnection(null))->toBe('pgsql')
        ->and($this->registry->ownerConnection(null))->toBe('pgsql_owner');
});

it('costruisce le connessioni di un database dedicato partendo da quelle condivise', function (): void {
    $this->registry->define('gamma', ['database' => 'as_gamma', 'username' => 'gamma_app', 'password' => 'x', 'owner_username' => 'gamma_owner', 'owner_password' => 'y']);

    $app = config('database.connections.'.$this->registry->appConnection('gamma'));
    $owner = config('database.connections.'.$this->registry->ownerConnection('gamma'));

    expect($this->registry->names())->toBe([DatabaseRegistry::SHARED, 'gamma'])
        ->and($app['database'])->toBe('as_gamma')
        ->and($app['username'])->toBe('gamma_app')
        ->and($app['host'])->toBe(config('database.connections.pgsql.host'))
        ->and($owner['username'])->toBe('gamma_owner')
        ->and($owner['app_role'])->toBe('gamma_app');
});

it('senza credenziali proprie usa i ruoli del database condiviso', function (): void {
    $this->registry->define('gamma', ['database' => 'as_gamma']);

    $owner = config('database.connections.'.$this->registry->ownerConnection('gamma'));

    expect($owner['username'])->toBe(config('database.connections.pgsql_owner.username'))
        ->and($owner['app_role'])->toBe(config('database.connections.pgsql.username'));
});

it('rifiuta nomi non validi', function (string $name): void {
    expect(fn () => $this->registry->define($name, ['database' => 'x']))->toThrow(InvalidArgumentException::class);
})->with(['shared', 'A', 'con-trattino', 'x', '1abc', "x'; DROP"]);

it('rifiuta un database dedicato sconosciuto', function (): void {
    expect(fn () => $this->registry->appConnection('nessuno'))->toThrow(InvalidArgumentException::class);
});

it('legge i database dedicati dal file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'dbs');
    file_put_contents($file, json_encode(['delta' => ['database' => 'as_delta']]));
    config(['saas.db.dedicated_file' => $file]);

    $registry = new DatabaseRegistry(app('config'));

    expect($registry->names())->toBe([DatabaseRegistry::SHARED, 'delta'])
        ->and(config('database.connections.'.$registry->appConnection('delta').'.database'))->toBe('as_delta');

    unlink($file);
});

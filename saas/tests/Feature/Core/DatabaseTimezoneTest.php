<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

it('apre tutte le connessioni in UTC, qualunque sia il fuso del server', function (string $connection): void {
    expect(DB::connection($connection)->selectOne("select current_setting('TimeZone') as tz")->tz)->toBe('UTC');
})->with(['pgsql', 'pgsql_owner', 'pgsql_backup']);

it('un link di reimpostazione appena creato non risulta scaduto', function (): void {
    $user = $this->createUser('anna@example.com');
    $broker = Password::broker();

    $token = $broker->createToken($user);

    expect($broker->tokenExists($user, $token))->toBeTrue();
});

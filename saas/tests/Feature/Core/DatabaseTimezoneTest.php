<?php

declare(strict_types=1);

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

it('apre tutte le connessioni nello stesso fuso dell\'applicazione', function (string $connection): void {
    expect(DB::connection($connection)->selectOne("select current_setting('TimeZone') as tz")->tz)->toBe(config('app.timezone'));
})->with(['pgsql', 'pgsql_owner', 'pgsql_backup']);

it('apre la connessione senza istruzioni dopo l\'accesso: fuso e schema arrivano con la richiesta di connessione (H16)', function (string $connection): void {
    // Connettore di Laravel con una connessione che annota le istruzioni mandate prima di essere restituita.
    $connector = new class extends PostgresConnector
    {
        protected function createPdoConnection($dsn, $username, #[SensitiveParameter] $password, $options): PDO
        {
            return new class($dsn, $username, $password, $options) extends PDO
            {
                /** @var list<string> */
                public array $sent = [];

                public function prepare(string $query, array $options = []): PDOStatement|false
                {
                    $this->sent[] = $query;

                    return parent::prepare($query, $options);
                }

                public function exec(string $statement): int|false
                {
                    $this->sent[] = $statement;

                    return parent::exec($statement);
                }
            };
        }
    };

    $config = config("database.connections.{$connection}");
    expect($config)->toBeArray();
    $pdo = $connector->connect($config);

    // Prima: «set time zone» e «set search_path to» a ogni apertura, due giri di rete. Dopo: nessuno.
    expect($pdo->sent ?? null)->toBe([])
        ->and($pdo->query("select current_setting('TimeZone')")->fetchColumn())->toBe(config('app.timezone'))
        ->and($pdo->query("select current_setting('search_path')")->fetchColumn())->toBe('public');
})->with(['pgsql', 'pgsql_owner', 'pgsql_backup']);

it('un link di reimpostazione appena creato non risulta scaduto', function (): void {
    $user = $this->createUser('anna@example.com');
    $broker = Password::broker();

    $token = $broker->createToken($user);

    expect($broker->tokenExists($user, $token))->toBeTrue();
});

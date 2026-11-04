<?php

declare(strict_types=1);

namespace App\Modules\Core\Database;

use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Elenco dei database delle associazioni: il condiviso e quelli dedicati.
 *
 * Ogni database ha due connessioni: una per l'applicazione (ruolo senza privilegi, soggetto alla
 * Row-Level Security) e una per il proprietario, usata solo dalle migrazioni. I database dedicati
 * stanno in un file JSON fuori dal repository e fuori dalla cartella pubblica (SAAS_DEDICATED_DB_FILE):
 *
 *   { "nome": { "database": "...", "host": "...", "username": "...", "password": "...",
 *               "owner_username": "...", "owner_password": "..." } }
 *
 * Le chiavi mancanti si prendono dal database condiviso: sullo stesso server bastano il nome del
 * database e, se si vogliono ruoli separati, le loro credenziali.
 */
final class DatabaseRegistry implements DatabaseNames
{
    public const SHARED = 'shared';

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,30}$/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $dedicated = null;

    public function __construct(private readonly Config $config) {}

    /**
     * Tutti i database, il condiviso per primo.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [self::SHARED, ...array_keys($this->dedicated())];
    }

    public function has(string $name): bool
    {
        return $name === self::SHARED || array_key_exists($name, $this->dedicated());
    }

    /** Connessione dell'applicazione per il database indicato (null = condiviso). */
    public function appConnection(?string $name): string
    {
        $name ??= self::SHARED;
        if ($name === self::SHARED) {
            return $this->sharedAppConnection();
        }

        return $this->register($name, 'app');
    }

    /** Connessione del proprietario, solo per le migrazioni. */
    public function ownerConnection(?string $name): string
    {
        $name ??= self::SHARED;
        if ($name === self::SHARED) {
            return $this->sharedOwnerConnection();
        }

        return $this->register($name, 'owner');
    }

    /** Connessione del ruolo dei backup (sola lettura), per pg_dump. */
    public function backupConnection(?string $name): string
    {
        $name ??= self::SHARED;
        if ($name === self::SHARED) {
            return 'pgsql_backup';
        }

        return $this->register($name, 'backup');
    }

    /**
     * Aggiunge (o sostituisce) un database dedicato: usato dal provisioning e dai test.
     *
     * @param  array<string, mixed>  $settings
     */
    public function define(string $name, array $settings): void
    {
        $this->assertValidName($name);
        $this->dedicated();
        $this->dedicated[$name] = $settings;

        foreach (['app', 'owner', 'backup'] as $kind) {
            $this->config->set('database.connections.'.$this->connectionName($name, $kind), null);
        }
    }

    public function sharedAppConnection(): string
    {
        return $this->configString('saas.db.app_connection');
    }

    public function sharedOwnerConnection(): string
    {
        return $this->configString('saas.db.owner_connection');
    }

    /** @param  'app'|'owner'|'backup'  $kind */
    private function register(string $name, string $kind): string
    {
        $settings = $this->dedicated()[$name] ?? throw new InvalidArgumentException("Database dedicato sconosciuto: {$name}");
        $connection = $this->connectionName($name, $kind);

        if ($this->config->get('database.connections.'.$connection) === null) {
            $base = $this->config->get('database.connections.'.match ($kind) {
                'app' => $this->sharedAppConnection(),
                'owner' => $this->sharedOwnerConnection(),
                'backup' => 'pgsql_backup',
            });
            if (! is_array($base)) {
                throw new RuntimeException('Connessione condivisa non configurata.');
            }
            $appBase = $this->config->get('database.connections.'.$this->sharedAppConnection());
            $appRole = $settings['username'] ?? (is_array($appBase) ? ($appBase['username'] ?? null) : null);

            $overrides = array_filter([
                'host' => $settings['host'] ?? null,
                'port' => $settings['port'] ?? null,
                'database' => $settings['database'] ?? null,
                'username' => $settings[match ($kind) {
                    'app' => 'username', 'owner' => 'owner_username', 'backup' => 'backup_username'
                }] ?? null,
                'password' => $settings[match ($kind) {
                    'app' => 'password', 'owner' => 'owner_password', 'backup' => 'backup_password'
                }] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

            if (! isset($overrides['database'])) {
                throw new RuntimeException("Database dedicato {$name} senza nome del database.");
            }

            $this->config->set('database.connections.'.$connection, array_merge($base, $overrides, [
                'app_role' => $appRole,
                'backup_role' => $settings['backup_username'] ?? ($this->config->get('database.connections.pgsql_backup.username')),
            ]));
        }

        return $connection;
    }

    private function connectionName(string $name, string $kind): string
    {
        return 'org_db_'.$name.($kind === 'app' ? '' : '_'.$kind);
    }

    /** @return array<string, array<string, mixed>> */
    private function dedicated(): array
    {
        if ($this->dedicated !== null) {
            return $this->dedicated;
        }

        $this->dedicated = [];
        $file = $this->config->get('saas.db.dedicated_file');
        if (! is_string($file) || $file === '') {
            return $this->dedicated;
        }
        if (! is_readable($file)) {
            throw new RuntimeException('File dei database dedicati non leggibile.');
        }

        $decoded = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('File dei database dedicati non valido.');
        }

        foreach ($decoded as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                throw new RuntimeException('File dei database dedicati non valido.');
            }
            $this->assertValidName($name);
            /** @var array<string, mixed> $settings */
            $this->dedicated[$name] = $settings;
        }

        return $this->dedicated;
    }

    private function assertValidName(string $name): void
    {
        if ($name === self::SHARED || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Nome di database dedicato non valido: {$name}");
        }
    }

    private function configString(string $key): string
    {
        $value = $this->config->get($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Configurazione mancante: {$key}");
        }

        return $value;
    }
}

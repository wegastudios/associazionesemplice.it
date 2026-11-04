<?php

declare(strict_types=1);

namespace App\Modules\Backup;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class BackupConfig
{
    public function __construct(private Repository $config) {}

    public function publicKey(): string
    {
        return $this->key('saas.backup.public_key', 'SAAS_BACKUP_PUBLIC_KEY', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
    }

    /** Coppia di chiavi per decifrare: c'è solo dove si fa la prova di ripristino. */
    public function keyPair(): string
    {
        return $this->key('saas.backup.secret_key', 'SAAS_BACKUP_SECRET_KEY', SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
    }

    public function hasKeyPair(): bool
    {
        $value = $this->config->get('saas.backup.secret_key');

        return is_string($value) && $value !== '';
    }

    public function binary(string $tool): string
    {
        $value = $this->config->get("saas.backup.{$tool}");

        return is_string($value) && $value !== '' ? $value : $tool;
    }

    /** Prefisso nello spazio dei backup: un ambiente non tocca quelli di un altro. */
    public function prefix(): string
    {
        $env = $this->config->get('app.env');

        return is_string($env) && preg_match('/^[a-z]+$/', $env) === 1 ? $env : 'local';
    }

    private function key(string $key, string $name, int $bytes): string
    {
        $value = $this->config->get($key);
        $raw = is_string($value) ? base64_decode($value, true) : false;

        return $raw !== false && strlen($raw) === $bytes ? $raw : throw new RuntimeException("{$name} non configurata o non valida.");
    }
}

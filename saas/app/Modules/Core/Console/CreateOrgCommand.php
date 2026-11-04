<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Org\OrgProvisioner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Crea un'associazione e ne invita il titolare. In produzione lo farà il portale con i diritti firmati; il comando
 * serve per staging e per i pilota.
 */
#[AsCommand(name: 'saas:org:create')]
final class CreateOrgCommand extends Command
{
    protected $signature = 'saas:org:create
        {name : Nome dell\'associazione}
        {slug : Sottodominio (minuscole, cifre, trattini)}
        {owner-email : Email del titolare}
        {owner-name : Nome del titolare}
        {--database= : Nome del database dedicato (vuoto = condiviso)}';

    protected $description = 'Crea un\'associazione e invita il titolare';

    public function handle(OrgProvisioner $provisioner, OrgMembers $members): int
    {
        $database = $this->option('database');

        try {
            $org = $provisioner->create(
                $this->stringArgument('name'),
                $this->stringArgument('slug'),
                is_string($database) && $database !== '' ? $database : null,
            );
            $members->invite($org, $this->stringArgument('owner-email'), $this->stringArgument('owner-name'), OrgRole::Owner, null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Associazione creata: {$org->id}. Invito mandato al titolare.");

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}

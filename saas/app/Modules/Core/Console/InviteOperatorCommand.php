<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'saas:operator:invite')]
final class InviteOperatorCommand extends Command
{
    protected $signature = 'saas:operator:invite
        {org : Sottodominio dell\'associazione}
        {email : Email dell\'operatore}
        {name : Nome dell\'operatore}
        {--role=operator : owner, admin, operator, accountant}';

    protected $description = 'Invita un operatore in un\'associazione';

    public function handle(OrgMembers $members): int
    {
        $role = OrgRole::tryFrom((string) $this->option('role'));
        $org = Org::query()->where('slug', (string) $this->argument('org'))->first();

        if ($role === null || $org === null) {
            $this->error($role === null ? 'Ruolo non valido.' : 'Associazione inesistente.');

            return self::FAILURE;
        }

        try {
            $members->invite($org, (string) $this->argument('email'), (string) $this->argument('name'), $role, null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Invito mandato.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Core\Org\OrgProvisioner;
use App\Modules\Core\Org\SetupWizard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Ambiente demo (docs/PIATTAFORMA.md §6): un'associazione di fantasia con utenti pubblici per ruolo e dati finti.
 * Esiste solo dove `saas.demo.enabled` è acceso e l'ambiente non è la produzione: lì si ricrea ogni notte.
 */
final readonly class DemoEnvironment
{
    /** @var array<string, array{0: OrgRole, 1: string}> */
    public const USERS = [
        'titolare' => [OrgRole::Owner, 'Titolare Demo'],
        'amministratore' => [OrgRole::Admin, 'Amministratore Demo'],
        'segreteria' => [OrgRole::Operator, 'Segreteria Demo'],
        'dipendente' => [OrgRole::Employee, 'Dipendente Demo'],
        'commercialista' => [OrgRole::Accountant, 'Commercialista Demo'],
    ];

    /**
     * Accessi pubblici della demo, da mostrare nella pagina di accesso: vuoto dove la demo non c'è.
     *
     * @return list<array{role: string, email: string, password: string}>
     */
    public static function credentials(): array
    {
        if (! (bool) config('saas.demo.enabled') || app()->environment('production')) {
            return [];
        }
        $password = config('saas.demo.password');
        $out = [];
        foreach (self::USERS as $key => [$role, $label]) {
            $out[] = ['role' => $role->label(), 'email' => self::email($key), 'password' => is_string($password) ? $password : ''];
        }

        return $out;
    }

    public function __construct(
        private Application $app,
        private Container $container,
        private OrgProvisioner $provisioner,
        private OrgContext $context,
        private OrgSettings $settings,
        private DatabaseManager $db,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('saas.demo.enabled') && ! $this->app->environment('production');
    }

    /** Email pubblica dell'utente di prova per quel ruolo. */
    public static function email(string $role): string
    {
        $domain = config('saas.demo.mail_domain');

        return $role.'@'.(is_string($domain) ? $domain : 'demo.example.test');
    }

    /**
     * Crea l'associazione di fantasia e la riempie. Presuppone il database appena azzerato.
     *
     * @throws RuntimeException se la demo non è attiva qui
     */
    public function seed(): Org
    {
        if (! $this->enabled()) {
            throw new RuntimeException('La demo non è attiva in questo ambiente (SAAS_DEMO, mai in produzione).');
        }

        $name = config('saas.demo.org_name');
        $slug = config('saas.demo.org_slug');
        $org = $this->provisioner->create(is_string($name) ? $name : 'Polisportiva Demo', is_string($slug) ? $slug : 'polisportiva-demo');

        $password = config('saas.demo.password');
        foreach (self::USERS as $key => [$role, $label]) {
            $user = new User;
            $user->name = $label;
            $user->email = self::email($key);
            $user->password = Hash::make(is_string($password) ? $password : '');
            $user->email_verified_at = now();
            $user->save();

            $this->db->connection('pgsql')->table('core.org_users')->insert([
                'org_id' => $org->id, 'user_id' => $user->id, 'role' => $role->value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->context->run($org->id, function () use ($org): void {
            $this->settings->set(SetupWizard::STATUS, SetupWizard::DONE);
            $this->settings->set('ente.kind', 'aps'); // la pagina «Dati per il RUNTS» mostra così il minimo di soci

            $seeders = [];
            foreach ($this->container->tagged(DemoSeeder::TAG) as $seeder) {
                if ($seeder instanceof DemoSeeder) {
                    $seeders[] = $seeder;
                }
            }
            usort($seeders, static fn (DemoSeeder $a, DemoSeeder $b): int => $a->order() <=> $b->order());
            foreach ($seeders as $seeder) {
                $seeder->seed($org->id);
            }
        });

        return $org;
    }
}

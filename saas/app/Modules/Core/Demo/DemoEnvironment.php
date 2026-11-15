<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Core\Contracts\DemoScale;
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
            $this->settings->set('ente.email', 'segreteria@polisportiva-demo.example');
            $this->settings->set('ente.phone', '06 1234567');
            // Dati di fantasia: la demo non mostra il promemoria dei dati mancanti.
            // Il codice fiscale e la partita IVA sono quelli d'esempio dei controlli (numero che torna, ma palesemente finto).
            foreach (['tax_code' => '12345678903', 'vat' => '12345678903', 'legal_rep' => 'Mario Rossi (dato di esempio)', 'address' => 'Via Roma 1 (dato di esempio)', 'zip' => '00100', 'city' => 'Roma', 'province' => 'RM'] as $k => $v) {
                $this->settings->set('ente.'.$k, $v);
            }
            foreach (['runts_none', 'pec_none'] as $k) {
                $this->settings->set('ente.'.$k, true);
            }
            $this->settings->set('ente.kind', 'aps'); // la pagina «Dati per il RUNTS» mostra così il minimo di soci
            // Anni fiscale e sociale da settembre ad agosto, come la demo del plugin; quota e prezzi senza IVA per mostrare «Aspetto fiscale».
            $this->settings->set('contabilita.fiscal_year_start_month', DemoScale::START_MONTH);
            $this->settings->set('soci.social_year_start_month', DemoScale::START_MONTH);
            $this->settings->set('soci.membership_fee_cents', 2500);
            $this->settings->set('fiscal.prices_include_vat', false);
            $this->grantPackages($org->id);

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

    /** Tutti i pacchetti, senza scadenza: la demo mostra ogni funzione. I limiti sono larghi ma finiti. */
    private function grantPackages(string $orgId): void
    {
        $kinds = ['hourly' => ['tier' => 10, 'web' => true], 'daily' => ['tier' => 10, 'web' => true], 'seasonal' => ['tier' => 10, 'web' => true]];
        $claims = [
            'sub' => $orgId, 'ref' => 'demo', 'rev' => 1, 'status' => 'active', 'until' => '2099-12-31', 'trial_ends' => null,
            'members' => 100000, 'operators' => 20, 'storage_gb' => 10, 'partner' => null,
            'packages' => ['member_area' => [], 'resources' => $kinds, 'site' => [], 'communications' => [], 'advanced_accounting' => [], 'association_life' => [], 'sport' => [], 'einvoice' => ['yearly' => 1000]],
        ];
        $this->db->connection('pgsql')->table('core.org_entitlements')->upsert([[
            'org_id' => $orgId, 'rev' => 1, 'status' => 'active', 'until' => '2099-12-31',
            'claims' => json_encode($claims, JSON_THROW_ON_ERROR), 'token' => 'demo', 'received_at' => now(),
        ]], ['org_id'], ['rev', 'status', 'until', 'claims', 'token', 'received_at']);
    }
}

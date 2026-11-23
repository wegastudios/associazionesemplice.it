<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
it('conta nel cruscotto tutti i pagamenti online da controllare, anche oltre i più recenti', function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, function () use ($org): void {
        $person = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi'])->id;
        // I più vecchi da controllare, poi 250 pagamenti chiusi più recenti: prima si contavano solo tra gli ultimi 200.
        $rows = [];
        foreach (range(1, 260) as $i) {
            $rows[] = [
                'org_id' => $org->id, 'id' => (string) Str::uuid7(), 'person_id' => $person, 'provider' => 'stripe',
                'status' => $i <= 10 ? 'review' : 'settled', 'amount_cents' => 1000, 'items' => '[]',
                'created_at' => now()->subDays(300)->addDays($i),
            ];
        }
        DB::table('app.online_payments')->insert($rows);
    });
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $owner, OrgRole::Owner);

    $this->actingAs($owner)->get('/gestionale')->assertInertia(fn (Assert $page) => $page
        ->where('todo', fn ($todo) => collect($todo)->pluck('title')->contains('10 pagamenti online da controllare')));
});

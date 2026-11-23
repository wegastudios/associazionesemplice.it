<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Ricerche e filtri ricaricano solo l'elenco (Elenco.vue, PrimaNota.vue, Certificati.vue): stessi risultati del
// caricamento intero, meno query. Dati di prova inventati.

/**
 * Carica la pagina intera, poi la ricarica come fa il filtro (solo $only): confronta i valori di $only e restituisce
 * le query dei due caricamenti.
 *
 * @param  list<string>  $only
 * @param  list<string>  $skipped  valori che la ricarica non deve ricalcolare
 * @return array{full: int, partial: int}
 */
function partialReload(string $url, string $component, array $only, array $skipped): array
{
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $response = test()->get($url)->assertOk();
    $full = $queries;
    $partial = 0;

    $response->assertInertia(function (Assert $page) use ($component, $only, $skipped, &$queries, &$partial): void {
        $props = $page->component($component)->toArray()['props'];
        foreach ($skipped as $key) {
            expect($props)->toHaveKey($key);
        }
        $queries = 0;
        $page->reloadOnly($only, function (Assert $reload) use ($only, $skipped, $props, &$queries, &$partial): void {
            $partial = $queries;
            foreach ($only as $key) {
                $reload->where($key, $props[$key]);
            }
            foreach ([...$skipped, 'org'] as $key) {
                $reload->missing($key);
            }
        });
    });

    return ['full' => $full, 'partial' => $partial];
}

it('la ricerca dei soci ricarica solo l\'elenco, con gli stessi risultati e meno query', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Operator);
    $this->orgContext()->run($org->id, function (): void {
        Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        Person::create(['first_name' => 'Bruno', 'last_name' => 'Rossetti']);
        Person::create(['first_name' => 'Carla', 'last_name' => 'Verdi']);
    });
    $this->actingAs($user);

    $n = partialReload('/gestionale/soci?q=ross&tipo=&stato=', 'Soci/Elenco',
        ['q', 'type', 'status', 'rows', 'next', 'prev'], ['types', 'year', 'activeCount', 'limit']);

    fwrite(STDERR, "\n[H39] soci, ricerca: {$n['full']} query la pagina intera, {$n['partial']} il filtro\n");
    expect($n['partial'])->toBeLessThan($n['full']);
});

it('i filtri della prima nota ricaricano solo i movimenti, con gli stessi risultati e meno query', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    [$cash, $membership] = $this->orgContext()->run($org->id, fn () => [
        Account::query()->sole()->id,
        Category::query()->where('kind', 'membership')->sole()->id,
    ]);
    $this->actingAs($user);
    foreach (['30,00', '45,50'] as $amount) {
        $this->post('/gestionale/prima-nota/incassi', [
            'date' => now()->toDateString(), 'account_id' => $cash, 'receipt' => true,
            'lines' => [['category_id' => $membership, 'amount' => $amount]],
        ])->assertSessionHas('status');
    }

    $year = now()->format('Y');
    $n = partialReload("/gestionale/prima-nota?anno={$year}&conto={$cash}&attivita=", 'Contabilita/PrimaNota',
        ['year', 'account', 'activity', 'rows', 'next', 'prev'], ['years', 'activities', 'accounts', 'categories', 'methods', 'balances', 'fiscal']);

    fwrite(STDERR, "\n[H39] prima nota, filtro: {$n['full']} query la pagina intera, {$n['partial']} il filtro\n");
    expect($n['partial'])->toBeLessThan($n['full']);
});

it('senza anno nell\'indirizzo la prima nota mostra l\'ultimo anno, come prima', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);

    $this->actingAs($user)->get('/gestionale/prima-nota')->assertInertia(fn (Assert $page) => $page
        ->where('year', fn ($year) => $year === ($page->toArray()['props']['years'][0]['year'] ?? (int) now()->format('Y'))));
});

it('il filtro dei certificati ricarica solo l\'elenco, con gli stessi risultati e meno query', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Operator);
    $this->orgContext()->run($org->id, function (): void {
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        Person::create(['first_name' => 'Bruno', 'last_name' => 'Verdi']);
        app(CertificateService::class)->add($anna->id, ['kind' => 'agonistic', 'issued_on' => now()->subYears(2)->toDateString(), 'expires_on' => now()->subYear()->toDateString(), 'doctor' => 'Dr. Bianchi']);
    });
    $this->actingAs($user);

    $n = partialReload('/gestionale/certificati?stato=expired', 'Soci/Certificati', ['filter', 'rows'], ['warnDays']);

    fwrite(STDERR, "\n[H39] certificati, filtro: {$n['full']} query la pagina intera, {$n['partial']} il filtro\n");
    expect($n['partial'])->toBeLessThan($n['full']);
});

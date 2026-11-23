<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use Illuminate\Support\Facades\DB;

// Costo fisso di ogni richiesta (lotto 3, H17): le istruzioni che una pagina del gestionale esegue prima del suo lavoro.
// Dati di prova inventati.

/** @return list<string> le istruzioni SQL eseguite dalla richiesta */
function sqlOf(Closure $request): array
{
    $sql = [];
    DB::listen(static function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $request();

    return $sql;
}

it('misura le istruzioni di una pagina del gestionale', function (string $url): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    $this->get($url)->assertOk(); // la prima richiesta riempie le cache di processo
    $sql = sqlOf(fn () => $this->get($url)->assertOk());

    expect(count($sql))->toBeLessThanOrEqual(0, count($sql)." istruzioni:\n".implode("\n", $sql));
})->with(['/gestionale', '/gestionale/soci']);

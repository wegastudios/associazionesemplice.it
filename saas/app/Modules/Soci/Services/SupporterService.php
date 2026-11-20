<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Soci\Contracts\MemberProfiles;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Contracts\Supporters;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Str;

/**
 * Chi dona senza essere socio. Se l'email è di una scheda esistente (prima chi è socio) la donazione si lega a quella
 * scheda e i dati scritti nel modulo non la modificano; altrimenti si riusa o si crea un «Sostenitore»: un contatto
 * che serve alla ricevuta, non un socio (non entra nel libro soci né nei conteggi).
 */
final readonly class SupporterService implements Supporters
{
    public function __construct(private MemberProfiles $profiles, private PeopleService $people) {}

    public function personFor(string $firstName, string $lastName, string $email, ?string $taxCode): string
    {
        $email = Str::lower(trim($email));
        $known = $this->profiles->loginCandidates($email)[0] ?? null;
        if ($known !== null) {
            return $known;
        }
        $again = Person::query()->where('email', $email)->where('type', MemberType::Supporter->value)->whereNull('anonymized_at')->orderBy('created_at')->first();
        if ($again !== null) {
            return $again->id;
        }
        $data = ['type' => MemberType::Supporter->value, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'tax_code' => $taxCode];
        try {
            return $this->people->create($data)->id;
        } catch (PeopleError $e) {
            // Il codice fiscale è già di un'altra scheda: la donazione si registra lo stesso, senza.
            if ($taxCode === null || ! str_contains(implode(' ', $e->messages), 'codice fiscale')) {
                throw $e;
            }

            return $this->people->create([...$data, 'tax_code' => null])->id;
        }
    }
}

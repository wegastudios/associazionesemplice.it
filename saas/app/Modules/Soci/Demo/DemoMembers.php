<?php

declare(strict_types=1);

namespace App\Modules\Soci\Demo;

use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;

/**
 * Soci finti per la demo: nomi comuni, codici fiscali e IBAN riconoscibilmente falsi (non superano i controlli), email
 * su dominio riservato. Quasi tutti con la tessera dell'anno in corso, alcuni senza (da rinnovare).
 */
final readonly class DemoMembers implements DemoSeeder
{
    private const FIRST = ['Giulia', 'Marco', 'Chiara', 'Luca', 'Sara', 'Paolo', 'Elena', 'Matteo', 'Francesca', 'Davide', 'Marta', 'Andrea'];

    private const LAST = ['Rossi', 'Bianchi', 'Verdi', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo'];

    /** Persone fisiche; i soci ente (2) si aggiungono. */
    public const COUNT = 36;

    public const ENTITIES = 2;

    public function __construct(private MembershipService $memberships) {}

    public function order(): int
    {
        return 10;
    }

    public function seed(string $orgId): void
    {
        $year = $this->memberships->socialYearLabel();
        $domain = config('saas.demo.mail_domain');
        $domain = is_string($domain) ? $domain : 'demo.example.test';

        for ($i = 0; $i < self::COUNT; $i++) {
            $first = self::FIRST[$i % count(self::FIRST)];
            $last = self::LAST[intdiv($i, count(self::FIRST)) % count(self::LAST)].($i >= 24 ? ' '.chr(65 + $i % 26) : '');
            $type = match (true) {
                $i < 3 => MemberType::Founder,
                $i % 9 === 0 => MemberType::Volunteer,
                default => MemberType::Ordinary,
            };

            $person = Person::query()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => sprintf('socio%02d@%s', $i + 1, $domain),
                'card_number' => sprintf('DEMO-%03d', $i + 1),
                'tax_code' => sprintf('DEMOFK%02dA01H501%s', 10 + $i % 80, chr(65 + $i % 26)),
                'birth_date' => now()->subYears(20 + ($i * 7) % 45)->subDays($i * 11)->toDateString(),
                'birth_place' => 'Cittàdemo',
                'address' => ['street' => 'Via di Prova '.($i + 1), 'city' => 'Cittàdemo', 'zip' => '00000', 'province' => 'XX'],
                'phone' => sprintf('+39 000 000 %04d', $i + 1),
                'iban' => sprintf('IT00X0000000000%012d', $i + 1),
                'type' => $type,
                'joined_on' => now()->subMonths(2 + $i * 2)->toDateString(),
            ]);

            // Ogni sesto socio ha la tessera scaduta, per vedere i rinnovi da fare.
            if ($i % 6 !== 5 && ! $type->isAutoRenewed()) {
                $this->memberships->grantManually($person->id, $year, 'Dati di demo');
            }
        }

        // Due soci ente (denominazione, codice fiscale di 11 cifre finto), per la pagina «Dati per il RUNTS».
        foreach (['Pro Loco di Cittàdemo' => true, 'Circolo Culturale Demo' => false] as $n => $same) {
            $entity = Person::query()->create([
                'first_name' => '', 'last_name' => $n, 'is_entity' => true, 'entity_same_section' => $same,
                'email' => sprintf('ente%d@%s', $n === 'Pro Loco di Cittàdemo' ? 1 : 2, $domain),
                'tax_code' => $same ? '99999999901' : '99999999902',
                'type' => MemberType::Ordinary, 'joined_on' => now()->subYears(2)->toDateString(),
            ]);
            $this->memberships->grantManually($entity->id, $year, 'Dati di demo');
        }
    }
}

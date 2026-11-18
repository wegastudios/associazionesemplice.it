<?php

declare(strict_types=1);

namespace App\Modules\Soci\Demo;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;

/**
 * Soci finti per la demo: nomi comuni, codici fiscali e IBAN riconoscibilmente falsi (non superano i controlli), email
 * su dominio riservato. Quanti sono lo decide SAAS_DEMO_PEOPLE. Ognuno è iscritto da un certo anno e rinnova negli anni
 * con la quota pagata in prima nota (con ricevuta); alcuni saltano un anno, e ogni sesto non ha la tessera dell'anno in
 * corso (da rinnovare). I fondatori hanno la tessera sempre.
 */
final readonly class DemoMembers implements DemoSeeder
{
    private const FIRST = ['Giulia', 'Marco', 'Chiara', 'Luca', 'Sara', 'Paolo', 'Elena', 'Matteo', 'Francesca', 'Davide', 'Marta', 'Andrea',
        'Alessia', 'Stefano', 'Silvia', 'Roberto', 'Valentina', 'Fabio', 'Laura', 'Simone', 'Anna', 'Giorgio', 'Irene', 'Nicola',
        'Federica', 'Daniele', 'Martina', 'Riccardo', 'Elisa', 'Tommaso', 'Cristina', 'Emanuele', 'Beatrice', 'Alberto', 'Monica', 'Enrico',
        'Paola', 'Claudio', 'Giorgia', 'Massimo'];

    private const LAST = ['Rossi', 'Bianchi', 'Verdi', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo',
        'Conti', 'De Luca', 'Mancini', 'Costa', 'Giordano', 'Rizzo', 'Lombardi', 'Moretti', 'Barbieri', 'Fontana', 'Santoro', 'Mariani',
        'Rinaldi', 'Caruso', 'Ferrara', 'Galli', 'Martini', 'Leone', 'Longo', 'Gentile', 'Martinelli', 'Vitale', 'Lombardo', 'Serra',
        'Coppola', 'De Santis', 'D\'Angelo', 'Marchetti'];

    /** Persone fisiche in un ambiente piccolo (prove automatiche); in demo sono SAAS_DEMO_PEOPLE. */
    public const COUNT = 36;

    public const ENTITIES = 2;

    public function __construct(private MembershipService $memberships, private Ledger $ledger) {}

    /** Quante persone fisiche crea in questo ambiente. */
    public static function count(): int
    {
        return DemoScale::people();
    }

    public function order(): int
    {
        return 10;
    }

    public function seed(string $orgId): void
    {
        $domain = config('saas.demo.mail_domain');
        $domain = is_string($domain) ? $domain : 'demo.example.test';
        $accounts = array_column($this->ledger->openAccounts(), 'id');
        $cash = $accounts[0] ?? null;
        $bank = $accounts[1] ?? $cash;
        $current = DemoScale::currentYear();
        $count = self::count();

        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST[$i % count(self::FIRST)];
            $last = self::LAST[intdiv($i, count(self::FIRST)) % count(self::LAST)].($i >= count(self::FIRST) * count(self::LAST) ? ' '.chr(65 + $i % 26) : '');
            $type = match (true) {
                $i < 3 => MemberType::Founder,
                $i % 9 === 0 => MemberType::Volunteer,
                default => MemberType::Ordinary,
            };
            // Iscritto da uno dei quattro anni (il più vecchio ha più soci storici).
            $from = $current - match (true) {
                $i < 3 => 3,
                default => ($i * 7) % 4,
            };

            $person = Person::query()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => sprintf('socio%03d@%s', $i + 1, $domain),
                'card_number' => sprintf('DEMO-%04d', $i + 1),
                'tax_code' => sprintf('DEMOFK%02dA01H501%s', 10 + $i % 80, chr(65 + $i % 26)),
                'birth_date' => now()->subYears(20 + ($i * 7) % 45)->subDays($i * 11)->toDateString(),
                'birth_place' => 'Cittàdemo',
                'address' => ['street' => 'Via di Prova '.($i + 1), 'city' => 'Cittàdemo', 'zip' => '00000', 'province' => 'XX'],
                'phone' => sprintf('+39 000 000 %04d', $i + 1),
                'iban' => sprintf('IT00X0000000000%012d', $i + 1),
                'type' => $type,
                'joined_on' => DemoScale::day($from, ($i * 5) % 60),
            ]);

            if ($type->isAutoRenewed() || $cash === null) {
                continue;
            }
            for ($year = $from; $year <= $current; $year++) {
                // Ogni sesto socio non ha la tessera dell'anno in corso; qualcuno salta un anno intermedio.
                if (($year === $current && $i % 6 === 5) || ($year < $current && $year > $from && ($i + $year) % 11 === 0)) {
                    continue;
                }
                $date = DemoScale::day($year, ($i * 3) % 75);
                $byBank = $i % 3 === 0;
                $this->memberships->renewWithPayment(
                    $person->id,
                    $byBank ? ($bank ?? $cash) : $cash,
                    $type === MemberType::Volunteer ? 1500 : 2500,
                    $date,
                    $this->memberships->membershipYearLabel($date),
                    $byBank ? PaymentMethod::BankTransfer : PaymentMethod::Cash,
                );
            }
        }

        // Due soci ente (denominazione, codice fiscale di 11 cifre finto), per la pagina «Dati per il RUNTS».
        $label = $this->memberships->membershipYearLabel();
        foreach (['Pro Loco di Cittàdemo' => true, 'Circolo Culturale Demo' => false] as $n => $same) {
            $entity = Person::query()->create([
                'first_name' => '', 'last_name' => $n, 'is_entity' => true, 'entity_same_section' => $same,
                'email' => sprintf('ente%d@%s', $n === 'Pro Loco di Cittàdemo' ? 1 : 2, $domain),
                'tax_code' => $same ? '99999999901' : '99999999902',
                'type' => MemberType::Ordinary, 'joined_on' => now()->subYears(2)->toDateString(),
            ]);
            $this->memberships->grantManually($entity->id, $label, 'Dati di demo');
        }
    }
}

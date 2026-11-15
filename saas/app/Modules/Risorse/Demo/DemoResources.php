<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Demo;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Risorse finte per la demo: due campi e una sala a ore (IVA 22%), una sala a giornate, tre posti barca stagionali («IVA
 * esente»), con prenotazioni nelle ultime settimane (incassate, con ricevuta) e nelle prossime, e una chiusura per
 * manutenzione.
 */
final readonly class DemoResources implements DemoSeeder
{
    public function __construct(
        private ResourceService $resources,
        private ReservationService $reservations,
        private Ledger $ledger,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function order(): int
    {
        return 30;
    }

    public function seed(string $orgId): void
    {
        $accounts = array_column($this->ledger->openAccounts(), 'id');
        $cash = $accounts[0] ?? null;
        $people = $this->people();
        if ($cash === null || $people === []) {
            return;
        }
        $hourly = ['typology' => 'hourly', 'category' => 'court', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 3,
            'advance_days' => 14, 'guests_max' => 3, 'guest_fee_cents' => 200, 'vat_rate' => 22];
        $courts = [
            $this->resources->save(null, $hourly + ['name' => 'Campo da tennis 1', 'fee_cents' => 1000, 'nonmember_fee_cents' => 1500, 'rates' => 'feriali 18:00-22:00 12,00', 'services' => 'Illuminazione 3,00']),
            $this->resources->save(null, $hourly + ['name' => 'Campo da tennis 2', 'fee_cents' => 1000, 'nonmember_fee_cents' => 1500, 'rates' => 'feriali 18:00-22:00 12,00']),
            $this->resources->save(null, ['name' => 'Sala prove', 'typology' => 'hourly', 'category' => 'room', 'open_time' => '09:00', 'close_time' => '23:00', 'slot_minutes' => 60,
                'min_slots' => 1, 'max_slots' => 4, 'fee_cents' => 800, 'nonmember_fee_cents' => 1200, 'vat_rate' => 22]),
        ];
        $hall = $this->resources->save(null, ['name' => 'Sala polivalente', 'typology' => 'daily', 'category' => 'room', 'fee_cents' => 6000, 'nonmember_fee_cents' => 9000,
            'guests_max' => 0, 'vat_rate' => 22, 'services' => 'Pulizie 40,00']);
        $berths = [];
        foreach ([1, 2, 3] as $n) {
            $berths[] = $this->resources->save(null, ['name' => 'Posto barca '.$n, 'typology' => 'seasonal', 'category' => 'berth', 'fee_cents' => 90000, 'vat_rate' => 0]);
        }

        $today = new DateTimeImmutable(now(ReservationService::TIMEZONE)->toDateString());
        $k = 0;
        foreach ($courts as $c => $court) {
            // Dalla quinta settimana fa alle prossime due, due fasce al giorno (mai la domenica).
            for ($d = -35; $d <= 14; $d++) {
                $day = $today->modify($d.' days');
                if ($day->format('N') === '7') {
                    continue;
                }
                foreach ([$c === 2 ? '19:00' : '10:00', $c === 2 ? '21:00' : '18:00'] as $slot) {
                    $k++;
                    if ($k % 3 === 0) {
                        continue; // un po' di fasce restano libere
                    }
                    $this->book($court->id, ['person_id' => $people[($k * 11) % count($people)], 'date' => $day->format('Y-m-d'), 'start' => $slot, 'units' => 1 + $k % 2,
                        'guests' => $k % 7 === 0 ? ['Ospite di prova'] : []], $d < 0 ? $cash : null, $k);
                }
            }
        }
        for ($d = -60; $d <= 40; $d += 5) {
            $k++;
            $day = $today->modify($d.' days')->format('Y-m-d');
            $this->book($hall->id, ['person_id' => $k % 4 === 0 ? null : $people[($k * 17) % count($people)], 'holder_name' => $k % 4 === 0 ? 'Associazione ospite di prova' : null,
                'date' => $day, 'units' => 1, 'services' => $k % 3 === 0 ? ['Pulizie'] : []], $d < 0 ? $cash : null, $k);
        }
        $year = DemoScale::currentYear();
        foreach ($berths as $i => $berth) {
            $this->book($berth->id, ['person_id' => $people[($i + 2) * 9 % count($people)], 'date' => $year.'-09-01', 'to' => ($year + 1).'-08-31'], $cash, $i);
        }
        $this->reservations->block($courts[0]->id, $today->modify('+3 days')->format('Y-m-d').' 08:00', $today->modify('+3 days')->format('Y-m-d').' 14:00', 'Manutenzione del fondo', null);
    }

    /** Prenota e, se c'è un conto, incassa. Una prenotazione che non si può fare (occupata, fuori orario) si salta. */
    private function book(string $resourceId, array $in, ?string $account, int $k): void
    {
        try {
            $id = $this->reservations->book($resourceId, $in, null)[0];
            if ($account !== null) {
                // Metà pagate in contanti, un quarto con bonifico, il resto ancora da incassare.
                if ($k % 4 === 0) {
                    return;
                }
                $this->reservations->collect($id, $account, min(substr((string) ($in['date'] ?? now()->toDateString()), 0, 10), now()->toDateString()), null, $k % 4 === 1 ? PaymentMethod::BankTransfer : PaymentMethod::Cash);
            }
        } catch (ResourceError) {
            return;
        }
    }

    /** @return list<string> le persone fisiche iscritte, nell'ordine di creazione */
    private function people(): array
    {
        $rows = $this->db->connection($this->context->require()->connection)->table('app.people')
            ->where('is_entity', false)->whereNull('deleted_at')->orderBy('card_number')->limit(400)->pluck('id');

        return array_values(array_filter($rows->all(), 'is_string'));
    }
}

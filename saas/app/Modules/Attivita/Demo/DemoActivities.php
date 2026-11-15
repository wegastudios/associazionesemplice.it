<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Demo;

use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;

/**
 * Corsi ed eventi finti per la demo, nell'anno sociale in corso e nel precedente: iscritti, contributi incassati mese per
 * mese (con ricevuta e IVA diversa da corso a corso, per mostrare «Aspetto fiscale») e costi degli istruttori. Chi
 * paga e quando dipende solo dal numero d'ordine della persona: sempre gli stessi dati.
 */
final readonly class DemoActivities implements DemoSeeder
{
    /** @var list<array{name: string, billing: string, fee: int, vat: int|null, people: int}> */
    private const COURSES = [
        ['name' => 'Yoga', 'billing' => 'monthly', 'fee' => 3000, 'vat' => 22, 'people' => 36],
        ['name' => 'Pilates', 'billing' => 'monthly', 'fee' => 3500, 'vat' => 22, 'people' => 28],
        ['name' => 'Ginnastica dolce', 'billing' => 'monthly', 'fee' => 2000, 'vat' => 0, 'people' => 40],
        ['name' => 'Inglese base', 'billing' => 'monthly', 'fee' => 4000, 'vat' => 10, 'people' => 18],
        ['name' => 'Corso di fotografia', 'billing' => 'once', 'fee' => 8000, 'vat' => null, 'people' => 14],
    ];

    public function __construct(
        private ActivityService $activities,
        private Ledger $ledger,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function order(): int
    {
        return 20;
    }

    public function seed(string $orgId): void
    {
        $accounts = array_column($this->ledger->openAccounts(), 'id');
        $cash = $accounts[0] ?? null;
        $bank = $accounts[1] ?? $cash;
        if ($cash === null) {
            return;
        }
        $people = $this->people();
        if ($people === []) {
            return;
        }
        $current = DemoScale::currentYear();
        $today = now()->format('Y-m');

        foreach ([$current - 1, $current] as $year) {
            $label = $year.'/'.($year + 1);
            foreach (self::COURSES as $c => $course) {
                $activity = $this->activities->save(null, [
                    'name' => $course['name'], 'kind' => 'course', 'social_year' => $label, 'billing' => $course['billing'],
                    'fee_cents' => $course['fee'], 'vat_rate' => $course['vat'],
                ]);
                $months = $this->activities->year($activity)['months'];
                $paidMonths = [];
                $n = min($course['people'], count($people));
                for ($k = 0; $k < $n; $k++) {
                    $person = $people[($k * 7 + $c * 13 + $year) % count($people)];
                    $startIndex = ($k + $c) % 3;
                    try {
                        $this->activities->enroll($activity->id, $person, $months[$startIndex]);
                    } catch (\Throwable) {
                        continue; // già iscritta: la scelta della persona può ripetersi
                    }
                    $byBank = $k % 3 === 0;
                    $account = $byBank ? ($bank ?? $cash) : $cash;
                    $method = $byBank ? PaymentMethod::BankTransfer : PaymentMethod::Cash;
                    if ($course['billing'] === 'once') {
                        $this->activities->collect($activity->id, $person, $account, DemoScale::day($year, 30 + $k * 3), [], null, $method);

                        continue;
                    }
                    // Un incasso ogni tre mesi, fino a oggi; qualcuno è in ritardo.
                    $due = array_values(array_filter(array_slice($months, $startIndex), static fn (string $m): bool => $m <= $today));
                    if ($k % 8 === 7) {
                        $due = array_slice($due, 0, max(0, count($due) - 3));
                    }
                    foreach (array_chunk($due, 3) as $chunk) {
                        $offset = (((int) substr($chunk[0], 5, 2) - 9 + 12) % 12) * 30 + ($k % 20);
                        $this->activities->collect($activity->id, $person, $account, DemoScale::day($year, $offset), $chunk, null, $method);
                        $paidMonths = array_merge($paidMonths, $chunk);
                    }
                }
                // Il compenso dell'istruttore: un costo al mese per i mesi con incassi.
                foreach (array_values(array_unique($paidMonths)) as $m) {
                    $offset = (((int) substr($m, 5, 2) - 9 + 12) % 12) * 30 + 27;
                    $this->activities->expense($activity->id, $cash, DemoScale::day($year, $offset), (int) round($course['fee'] * $course['people'] * 0.3), 'Compenso istruttore '.$course['name'], null, PaymentMethod::Cash);
                }
            }

            // Due eventi a pagamento: la cena sociale (IVA 10%) e la gita.
            foreach ([['Cena sociale', 2500, 10, 3], ['Gita al lago', 3000, null, 8]] as $e => [$name, $fee, $vat, $monthIndex]) {
                $month = $monthIndex < 4 ? $year : $year + 1;
                $date = sprintf('%d-%02d-15', $month, 9 + $monthIndex > 12 ? 9 + $monthIndex - 12 : 9 + $monthIndex);
                $event = $this->activities->save(null, [
                    'name' => $name, 'kind' => 'event', 'social_year' => $label, 'billing' => 'once',
                    'fee_cents' => $fee, 'vat_rate' => $vat, 'starts_on' => $date,
                ]);
                for ($k = 0; $k < min(40, count($people)); $k++) {
                    $person = $people[($k * 5 + $e * 11 + $year) % count($people)];
                    try {
                        $this->activities->enroll($event->id, $person);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($date <= now()->toDateString()) {
                        $this->activities->collect($event->id, $person, $cash, min($date, now()->toDateString()), [], null, PaymentMethod::Cash);
                    }
                }
            }
        }
    }

    /** @return list<string> le persone fisiche iscritte (non gli enti), nell'ordine di creazione */
    private function people(): array
    {
        $rows = $this->db->connection($this->context->require()->connection)->table('app.people')
            ->where('is_entity', false)->whereNull('deleted_at')->orderBy('card_number')->limit(400)->pluck('id');

        return array_values(array_filter($rows->all(), 'is_string'));
    }
}

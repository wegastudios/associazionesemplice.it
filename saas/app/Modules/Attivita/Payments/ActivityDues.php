<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Payments;

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Contracts\Billing;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Models\Enrollment;
use App\Modules\Attivita\Models\SessionBooking;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\PayableDues;
use InvalidArgumentException;

/**
 * Contributi delle attività: mensilità dovute dei corsi (m:{attività}:{persona}:{mese}), contributo unico di corsi ed
 * eventi (e:{attività}:{persona}), prenotazioni alle date degli eventi (b:{prenotazione}).
 */
final readonly class ActivityDues implements PayableDues
{
    public function __construct(
        private ActivityService $activities,
        private SessionService $sessions,
    ) {}

    public function dues(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $out = [];
        $ids = Enrollment::query()->whereIn('person_id', $personIds)->whereNull('end_month')->distinct()->pluck('activity_id')->all();
        foreach (Activity::query()->whereIn('id', $ids)->whereNull('archived_at')->orderBy('name')->get() as $a) {
            $monthly = $a->kind === ActivityKind::Course && $a->billing !== Billing::Once;
            foreach ($this->activities->status($a) as $s) {
                if (! $s['active'] || ! in_array($s['person_id'], $personIds, true)) {
                    continue;
                }
                if ($monthly) {
                    foreach ($s['summary']['unpaid_months'] as $m) {
                        if ($m['missing'] > 0) {
                            $out[] = ['key' => 'm:'.$a->id.':'.$s['person_id'].':'.$m['month'], 'person_id' => $s['person_id'], 'group' => 'Corsi',
                                'label' => $a->name.' · '.ActivityService::monthLabel($m['month']).' · '.$s['name'], 'amount_cents' => $m['missing']];
                        }
                    }
                } elseif (! $s['summary']['regular'] && $s['summary']['balance'] < 0) {
                    $out[] = ['key' => 'e:'.$a->id.':'.$s['person_id'], 'person_id' => $s['person_id'], 'group' => 'Corsi ed eventi',
                        'label' => $a->name.' · '.$s['name'], 'amount_cents' => -$s['summary']['balance']];
                }
            }
        }
        $bookings = SessionBooking::query()->join('app.activity_sessions as s', 's.id', '=', 'app.session_bookings.session_id')
            ->join('app.activities as a', 'a.id', '=', 's.activity_id')->join('app.people as p', 'p.id', '=', 'app.session_bookings.person_id')
            ->whereIn('app.session_bookings.person_id', $personIds)->where('app.session_bookings.status', 'booked')->whereNull('s.cancelled_at')
            ->where('app.session_bookings.fee_due_cents', '>', 0)->orderBy('s.session_date')
            ->get(['app.session_bookings.*', 's.session_date', 'a.name as activity_name', 'p.first_name', 'p.last_name']);
        $paid = $this->sessions->paid(array_values($bookings->map(static fn (SessionBooking $b): string => $b->id)->all()));
        foreach ($bookings as $b) {
            $due = $b->fee_due_cents - ($paid[$b->id] ?? 0);
            if ($due > 0) {
                $date = $b->getAttribute('session_date');
                $out[] = ['key' => 'b:'.$b->id, 'person_id' => $b->person_id, 'group' => 'Eventi',
                    'label' => self::str($b->getAttribute('activity_name')).' · '.(is_string($date) ? date('d/m/Y', (int) strtotime($date)) : '').' · '
                        .trim(self::str($b->getAttribute('first_name')).' '.self::str($b->getAttribute('last_name'))), 'amount_cents' => $due];
            }
        }

        return $out;
    }

    public function owns(string $key): bool
    {
        return preg_match('/^[meb]:/', $key) === 1;
    }

    public function settle(string $key, int $cents, string $accountId, string $date, string $method): ?string
    {
        $pm = PaymentMethod::from($method);
        try {
            if (preg_match('/^m:([0-9a-f-]{36}):([0-9a-f-]{36}):(\d{4}-\d{2})$/', $key, $m) === 1) {
                return $this->activities->collect($m[1], $m[2], $accountId, $date, [$m[3]], $cents, $pm);
            }
            if (preg_match('/^e:([0-9a-f-]{36}):([0-9a-f-]{36})$/', $key, $m) === 1) {
                return $this->activities->collect($m[1], $m[2], $accountId, $date, [], $cents, $pm);
            }
            if (preg_match('/^b:([0-9a-f-]{36})$/', $key, $m) === 1) {
                return $this->sessions->collect($m[1], $accountId, $date, $cents, $pm);
            }
        } catch (ActivityError|LedgerError $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
        throw new InvalidArgumentException('Voce non valida.');
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}

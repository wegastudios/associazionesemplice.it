<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Rules\ExpiryRules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Promemoria delle scadenze (dal Reminders del plugin): tessera e certificato medico in scadenza o scaduti da poco.
 * Un promemoria per persona, tipo e scadenza: rinnovando, la scadenza cambia e ne riparte uno nuovo. Per un minore
 * scrive al genitore o tutore operativo.
 */
final readonly class ReminderService
{
    public const ENABLED = 'soci.reminders_enabled';

    public const DAYS = 'soci.reminders_days';

    /** Giorni dopo la scadenza in cui si manda ancora il promemoria «scaduto». */
    public const EXPIRED_WINDOW = 30;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgSettings $settings,
        private CertificateService $certificates,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get(self::ENABLED, false) === true;
    }

    /**
     * @return array{membership_soon: int, membership_expired: int, cert_soon: int, cert_expired: int}
     */
    public function run(string $today, bool $send = true): array
    {
        $counts = ['membership_soon' => 0, 'membership_expired' => 0, 'cert_soon' => 0, 'cert_expired' => 0];
        $days = ExpiryRules::warnDays($this->settings->get(self::DAYS, ExpiryRules::DEFAULT_WARN));
        $orgName = $this->orgName();

        foreach ($this->expiries('app.valid_memberships', ['ordinary', 'volunteer']) as $personId => $until) {
            $kind = $this->kind('membership', $until, $today, $days);
            if ($kind !== null && $this->deliver($personId, $kind, $until, $today, $orgName, $send)) {
                $counts[$kind]++;
            }
        }
        foreach ($this->expiries('app.medical_certs', ['founder', 'ordinary', 'volunteer'], $today) as $personId => $until) {
            $kind = $this->kind('cert', $until, $today, $this->certificates->warnDays());
            if ($kind !== null && $this->deliver($personId, $kind, $until, $today, $orgName, $send)) {
                $counts[$kind]++;
            }
        }

        return $counts;
    }

    /** @return 'membership_soon'|'membership_expired'|'cert_soon'|'cert_expired'|null */
    private function kind(string $what, string $until, string $today, int $warn): ?string
    {
        $status = ExpiryRules::status($until, $today, $warn);
        if ($status === ExpiryRules::EXPIRING) {
            return $what === 'cert' ? 'cert_soon' : 'membership_soon';
        }
        if ($status === ExpiryRules::EXPIRED && -ExpiryRules::daysLeft($until, $today) <= self::EXPIRED_WINDOW) {
            return $what === 'cert' ? 'cert_expired' : 'membership_expired';
        }

        return null;
    }

    private function deliver(string $personId, string $kind, string $until, string $today, string $orgName, bool $send): bool
    {
        $person = Person::query()->find($personId);
        $to = $person === null ? null : $this->recipient($person, $today);
        if ($person === null || $to === null || $this->connection()->table('app.reminders')->where('person_id', $personId)->where('kind', $kind)->where('ref', $until)->exists()) {
            return false;
        }
        if (! $send) {
            return true;
        }

        // Prima si prenota l'invio (chiave unica): due giri in parallelo non mandano due email.
        $claimed = $this->connection()->table('app.reminders')->insertOrIgnore([
            'org_id' => $this->context->require()->id, 'person_id' => $personId, 'kind' => $kind, 'ref' => $until,
        ]);
        if ($claimed === 0) {
            return false;
        }

        $date = date('d/m/Y', (int) strtotime($until));
        $about = $to['about'] !== '' ? " di {$to['about']}" : '';
        [$subject, $body] = match ($kind) {
            'membership_soon' => ['La tessera sta per scadere', "La tessera associativa{$about} scade il {$date}. Si può rinnovare in segreteria."],
            'membership_expired' => ['La tessera è scaduta', "La tessera associativa{$about} è scaduta il {$date}. Per partecipare alle attività va rinnovata: rivolgiti alla segreteria."],
            'cert_soon' => ['Il certificato medico sta per scadere', "Il certificato medico{$about} scade il {$date}. Ricordati di portare in segreteria quello nuovo."],
            default => ['Il certificato medico è scaduto', "Il certificato medico{$about} è scaduto il {$date}. Senza un certificato valido non si può partecipare alle attività sportive."],
        };

        try {
            Notification::route('mail', $to['email'])->notify(new MemberNotification($subject, "Ciao {$to['name']},", $body, $orgName));
        } catch (Throwable $e) {
            $this->connection()->table('app.reminders')->where('person_id', $personId)->where('kind', $kind)->where('ref', $until)->delete();
            report($e);

            return false;
        }

        return true;
    }

    /** @return array{email: string, name: string, about: string}|null */
    private function recipient(Person $person, string $today): ?array
    {
        if ($person->anonymized_at !== null || $person->left_on !== null) {
            return null;
        }
        if (Age::isMinor($person->birth_date?->format('Y-m-d'), $today)) {
            $guardianId = $this->connection()->table('app.guardians')->where('person_id', $person->id)->where('is_operative', true)->value('guardian_person_id');
            $guardian = is_string($guardianId) ? Person::query()->find($guardianId) : null;

            return $guardian !== null && is_string($guardian->email) && $guardian->email !== ''
                ? ['email' => $guardian->email, 'name' => $guardian->first_name, 'about' => $person->first_name]
                : null;
        }

        return is_string($person->email) && filter_var($person->email, FILTER_VALIDATE_EMAIL) !== false
            ? ['email' => $person->email, 'name' => $person->first_name, 'about' => '']
            : null;
    }

    /**
     * Ultima scadenza per persona (solo date: per i certificati non si apre nessun dato sanitario).
     *
     * @param  'app.valid_memberships'|'app.medical_certs'  $table
     * @param  list<string>  $types
     * @return array<string, string>
     */
    private function expiries(string $table, array $types, ?string $issuedBefore = null): array
    {
        $q = $this->connection()->table($table.' as x')
            ->join('app.people as p', 'p.id', '=', 'x.person_id')
            ->whereIn('p.type', $types)->whereNull('p.deleted_at')->whereNull('p.left_on')->whereNull('p.suspended_at')
            ->groupBy('x.person_id')
            ->selectRaw($table === 'app.medical_certs' ? 'x.person_id, max(x.expires_on)::text AS until' : 'x.person_id, max(x.valid_to)::text AS until');
        if ($table === 'app.medical_certs') {
            $q->whereNull('x.deleted_at')->where('x.issued_on', '<=', $issuedBefore);
        }

        $out = [];
        foreach ($q->get() as $row) {
            if (is_string($row->person_id ?? null) && is_string($row->until ?? null)) {
                $out[$row->person_id] = $row->until;
            }
        }

        return $out;
    }

    private function orgName(): string
    {
        $name = $this->connection()->table('app.orgs')->value('name');

        return is_string($name) ? $name : '';
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}

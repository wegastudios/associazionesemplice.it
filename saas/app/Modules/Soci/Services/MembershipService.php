<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\Members;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Membership;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\SocialYear;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Tesseramento: la tessera di un anno sociale nasce dall'incasso della quota in prima nota (stessa transazione, con
 * ricevuta) o, per casi particolari, a mano. Il fondatore è sempre in regola senza tessera annuale.
 */
final readonly class MembershipService implements Members
{
    public const START_MONTH = 'soci.social_year_start_month';

    public const DEFAULT_FEE = 'soci.membership_fee_cents';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgSettings $settings,
        private Ledger $ledger,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    public function startMonth(): int
    {
        $month = $this->settings->int(self::START_MONTH, 1);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    public function socialYear(?string $date = null): SocialYear
    {
        return SocialYear::forDate($date ?? now()->toDateString(), $this->startMonth());
    }

    public function socialYearLabel(?string $date = null): string
    {
        return $this->socialYear($date)->label();
    }

    public function socialYearInfo(string $label): ?array
    {
        if (preg_match('/^(\d{4})(\/(\d{4}))?$/', $label, $m) !== 1 || (isset($m[3]) && (int) $m[3] !== (int) $m[1] + 1)) {
            return null;
        }
        $year = SocialYear::fromLabel($label, $this->startMonth());

        return [
            'label' => $year->label(),
            'start' => $year->start()->format('Y-m-d'),
            'end' => $year->end()->format('Y-m-d'),
            'months' => $year->months(),
            'next' => $year->next()->label(),
            'previous' => $year->previous()->label(),
        ];
    }

    /** Quota del socio: quella del suo livello, se c'è, altrimenti quella dell'associazione. */
    public function feeFor(Person $person): int
    {
        if ($person->level_id !== null) {
            $level = MemberLevel::query()->find($person->level_id);
            if ($level !== null && $level->fee_cents !== null) {
                return $level->fee_cents;
            }
        }

        return $this->settings->int(self::DEFAULT_FEE, 0);
    }

    /**
     * Rinnovo con incasso della quota: movimento in prima nota con ricevuta e tessera dell'anno sociale, tutto insieme.
     *
     * @return array{membership_id: string, receipt: string|null}
     */
    public function renewWithPayment(string $personId, string $accountId, ?int $amountCents = null, ?string $date = null, ?string $socialYear = null, ?PaymentMethod $method = null): array
    {
        $person = $this->member($personId);
        $year = $socialYear === null ? $this->socialYear($date) : SocialYear::fromLabel($socialYear, $this->startMonth());
        $amount = $amountCents ?? $this->feeFor($person);
        if ($amount <= 0) {
            throw new PeopleError(['Indica l\'importo della quota (o imposta la quota associativa).']);
        }
        $category = $this->ledger->categoryFor(CategoryKind::Membership) ?? throw new PeopleError(['Manca la categoria «Quota associativa» in prima nota.']);
        $this->assertNoMembership($person->id, $year->label());

        return $this->connection()->transaction(function () use ($person, $year, $amount, $category, $accountId, $date, $method): array {
            $income = $this->ledger->recordIncome(new Income(
                $date ?? now()->toDateString(),
                $accountId,
                [new IncomeLine($category, $amount, 'Quota associativa '.$year->label())],
                $method,
                $person->id,
            ));
            $id = $this->insert($person->id, $year, 'payment', $income['transactions'][0]);

            return ['membership_id' => $id, 'receipt' => $income['receipt']];
        });
    }

    /** Tessera senza incasso (socio onorario, quota pagata altrove…): resta traccia nel registro. */
    public function grantManually(string $personId, string $socialYear, string $reason): string
    {
        if (trim($reason) === '') {
            throw new PeopleError(['Indica perché la tessera non passa da un incasso.']);
        }
        $person = $this->member($personId);
        $year = SocialYear::fromLabel($socialYear, $this->startMonth());
        $this->assertNoMembership($person->id, $year->label());

        $id = $this->insert($person->id, $year, 'manual', null);
        $this->audit->record('membership.manual', 'app.memberships', $id, ['social_year' => $year->label(), 'reason' => mb_substr(trim($reason), 0, 200)]);

        return $id;
    }

    /** Toglie una tessera data a mano. Quelle pagate si tolgono stornando la quota in prima nota. */
    public function revoke(string $membershipId): void
    {
        $membership = Membership::query()->whereNull('voided_at')->find($membershipId)
            ?? throw new PeopleError(['Tessera inesistente o già tolta.']);
        if ($membership->source === 'payment') {
            throw new PeopleError(['Questa tessera nasce da un incasso: storna la quota in prima nota.']);
        }
        $this->connection()->table('app.memberships')->where('id', $membership->id)->update(['voided_at' => now()]);
        $this->audit->record('membership.revoked', 'app.memberships', $membership->id, ['social_year' => $membership->social_year]);
    }

    public function isActive(string $personId, ?string $date = null): bool
    {
        $person = Person::query()->find($personId);
        if ($person === null || ! $person->type->isMember() || $person->left_on !== null || $person->suspended_at !== null) {
            return false;
        }
        if ($person->type->isAutoRenewed()) {
            return true;
        }
        $day = $date ?? now()->toDateString();

        return $this->connection()->table('app.valid_memberships')->where('person_id', $personId)
            ->where('valid_from', '<=', $day)->where('valid_to', '>=', $day)->exists();
    }

    public function activeCount(?string $date = null): int
    {
        return $this->activeQuery($date ?? now()->toDateString())->count();
    }

    /**
     * Tra queste persone, quelle attive: una sola query per un'intera pagina dell'elenco.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function activeAmong(array $ids, ?string $date = null): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $this->activeQuery($date ?? now()->toDateString())->whereIn('p.id', $ids)->pluck('p.id')->all(),
            is_string(...),
        ));
    }

    private function activeQuery(string $day): Builder
    {
        return $this->connection()->table('app.people as p')
            ->whereNull('p.deleted_at')->whereNull('p.left_on')->whereNull('p.suspended_at')->whereNull('p.anonymized_at')
            ->where(function (Builder $q) use ($day): void {
                $q->where('p.type', 'founder')->orWhere(function (Builder $q) use ($day): void {
                    $q->whereIn('p.type', ['ordinary', 'volunteer'])->whereExists(function (Builder $e) use ($day): void {
                        $e->selectRaw('1')->from('app.valid_memberships as m')->whereColumn('m.person_id', 'p.id')
                            ->where('m.valid_from', '<=', $day)->where('m.valid_to', '>=', $day);
                    });
                });
            });
    }

    /** @return list<array{id: string, social_year: string, valid_to: string, source: string, valid: bool}> */
    public function history(string $personId): array
    {
        $valid = $this->connection()->table('app.valid_memberships')->where('person_id', $personId)->pluck('id')->all();

        return array_values(Membership::query()->where('person_id', $personId)->orderByDesc('valid_from')->get()
            ->map(static fn (Membership $m): array => [
                'id' => $m->id,
                'social_year' => $m->social_year,
                'valid_to' => $m->valid_to->format('d/m/Y'),
                'source' => $m->source,
                'valid' => in_array($m->id, $valid, true),
            ])->all());
    }

    private function member(string $personId): Person
    {
        $person = Str::isUuid($personId) ? Person::query()->find($personId) : null;
        if ($person === null) {
            throw new PeopleError(['Persona non trovata.']);
        }
        if (! $person->type->isMember()) {
            throw new PeopleError(['Solo i soci hanno la tessera: ospiti, genitori e tutori no.']);
        }
        if ($person->type->isAutoRenewed()) {
            throw new PeopleError(['Il socio fondatore ha la tessera sempre rinnovata.']);
        }
        if ($person->left_on !== null) {
            throw new PeopleError(['La persona è uscita dall\'associazione.']);
        }

        return $person;
    }

    private function assertNoMembership(string $personId, string $year): void
    {
        if ($this->connection()->table('app.valid_memberships')->where('person_id', $personId)->where('social_year', $year)->exists()) {
            throw new PeopleError(["Il socio ha già la tessera {$year}."]);
        }

        // Una tessera rimasta senza quota (incasso stornato) non vale più: si chiude, così l'anno si può rinnovare.
        $this->connection()->table('app.memberships as m')
            ->where('m.person_id', $personId)->where('m.social_year', $year)->whereNull('m.voided_at')
            ->whereNotIn('m.id', fn (Builder $q) => $q->select('id')->from('app.valid_memberships'))
            ->update(['voided_at' => now()]);
    }

    private function insert(string $personId, SocialYear $year, string $source, ?string $transactionId): string
    {
        $id = Str::uuid7()->toString();
        $userId = $this->auth->guard()->id();
        $this->connection()->table('app.memberships')->insert([
            'org_id' => $this->context->require()->id,
            'id' => $id,
            'person_id' => $personId,
            'social_year' => $year->label(),
            'valid_from' => $year->start()->format('Y-m-d'),
            'valid_to' => $year->end()->format('Y-m-d'),
            'source' => $source,
            'transaction_id' => $transactionId,
            'created_by' => is_string($userId) ? $userId : null,
        ]);

        return $id;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\CertificateKind;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\ConsentSource;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\ConsentService;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Anagrafica e tesseramento nel gestionale. */
final readonly class PeopleController
{
    public function __construct(
        private PeopleService $people,
        private MembershipService $memberships,
        private Ledger $ledger,
        private OrgSettings $settings,
        private GuardianService $guardians,
        private CertificateService $certificates,
        private ConsentService $consents,
    ) {}

    public function index(Request $request): Response
    {
        $q = $request->string('q')->toString();
        $type = $request->string('tipo')->toString();
        $query = $this->people->search($q)->orderBy('last_name')->orderBy('first_name')->orderBy('id');
        if (MemberType::tryFrom($type) !== null) {
            $query->where('type', $type);
        }
        $status = $request->string('stato')->toString();
        if ($status === 'in_regola') {
            $query->whereIn('id', $this->memberships->activeIds());
        } elseif ($status === 'da_rinnovare') {
            $query->whereNull('left_on')->whereIn('type', ['ordinary', 'volunteer'])->whereNotIn('id', $this->memberships->activeIds());
        } elseif ($status === 'usciti') {
            $query->whereNotNull('left_on');
        }
        $page = $query->cursorPaginate(50, ['id', 'first_name', 'last_name', 'email', 'card_number', 'type', 'left_on'])->withQueryString();
        $year = $this->memberships->socialYear();
        $active = $this->memberships->activeAmong(array_values(array_map(static fn (Person $p): string => $p->id, $page->items())));

        return Inertia::render('Soci/Elenco', [
            'q' => $q,
            'type' => $type,
            'status' => $status,
            'types' => self::types(),
            'year' => $year->label(),
            'rows' => collect($page->items())->map(fn (Person $p): array => [
                'id' => $p->id,
                'name' => $p->last_name.' '.$p->first_name,
                'email' => $p->email,
                'card' => $p->card_number,
                'type' => $p->type->label(),
                'left' => $p->left_on !== null,
                'active' => in_array($p->id, $active, true),
            ])->values(),
            'next' => $page->nextPageUrl(),
            'prev' => $page->previousPageUrl(),
            'activeCount' => $this->memberships->activeCount(),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canExport' => $request->user()?->can('people.export') ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request);
    }

    public function show(Request $request, string $person): Response
    {
        return $this->form(Person::query()->findOrFail($person), $request);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(function () use ($data): RedirectResponse {
            $person = $this->people->create($data);

            return redirect()->route('people.show', $person->id)->with('status', 'Scheda creata.');
        });
    }

    public function update(Request $request, string $person): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(function () use ($person, $data): RedirectResponse {
            $this->people->update($person, $data);

            return back()->with('status', 'Scheda salvata.');
        });
    }

    public function renew(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate([
            'account_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date_format:Y-m-d'],
            'social_year' => ['required', 'regex:/^\d{4}(\/\d{4})?$/'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]));
        $amount = Money::parse(Input::string($data, 'amount'));
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Importo non valido.']);
        }
        $method = Input::optionalString($data, 'method');

        return $this->attempt(function () use ($person, $data, $amount, $method): RedirectResponse {
            $r = $this->memberships->renewWithPayment($person, Input::string($data, 'account_id'), $amount, Input::string($data, 'date'), Input::string($data, 'social_year'), $method === null ? null : PaymentMethod::from($method));

            return back()->with('status', 'Tessera '.Input::string($data, 'social_year').' registrata'.($r['receipt'] !== null ? ", ricevuta n. {$r['receipt']}." : '.'));
        });
    }

    public function grant(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate([
            'social_year' => ['required', 'regex:/^\d{4}(\/\d{4})?$/'],
            'reason' => ['required', 'string', 'max:200'],
        ]));

        return $this->attempt(function () use ($person, $data): RedirectResponse {
            $this->memberships->grantManually($person, Input::string($data, 'social_year'), Input::string($data, 'reason'));

            return back()->with('status', 'Tessera registrata senza incasso.');
        });
    }

    public function revoke(string $membership): RedirectResponse
    {
        return $this->attempt(function () use ($membership): RedirectResponse {
            $this->memberships->revoke($membership);

            return back()->with('status', 'Tessera tolta.');
        });
    }

    /** Consenso dato o revocato in segreteria (modulo cartaceo, a voce): per un minore lo dà il genitore o tutore. */
    public function consent(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate([
            'channel' => ['required', Rule::enum(ConsentChannel::class)],
            'granted' => ['required', 'boolean'],
            'source' => ['required', Rule::in([ConsentSource::Paper->value, ConsentSource::Oral->value, ConsentSource::Admin->value])],
            'given_by' => ['nullable', 'uuid'],
        ]));
        $p = Person::query()->findOrFail($person);
        $givenBy = Input::optionalString($data, 'given_by');
        if ($givenBy !== null && ! in_array($givenBy, array_column($this->guardians->of($p->id), 'id'), true)) {
            throw ValidationException::withMessages(['ledger' => 'Il consenso per un minore lo dà uno dei suoi tutori.']);
        }
        $channel = ConsentChannel::from(Input::string($data, 'channel'));
        $granted = $request->boolean('granted');
        $this->consents->set($p->id, $channel, $granted, ConsentSource::from(Input::string($data, 'source')), $givenBy);

        return back()->with('status', $channel->label().': '.($granted ? 'consenso registrato.' : 'consenso revocato.'));
    }

    public function leave(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]));

        return $this->attempt(function () use ($person, $data): RedirectResponse {
            $this->people->leave($person, Input::string($data, 'date'), Input::optionalString($data, 'reason') ?? '');

            return back()->with('status', 'Uscita registrata.');
        });
    }

    /** Ricerca rapida per la prima nota e i collegamenti (ospitante, tutore): solo nome e tessera. */
    public function lookup(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        if (mb_strlen(trim($q)) < 2) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->people->search($q)->whereNull('left_on')->orderBy('last_name')->limit(10)
            ->get(['id', 'first_name', 'last_name', 'card_number', 'type'])
            ->map(static fn (Person $p): array => [
                'id' => $p->id,
                'label' => $p->last_name.' '.$p->first_name.($p->card_number !== null ? ' · tessera '.$p->card_number : ''),
                'type' => $p->type->value,
            ])->values());
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'start_month' => ['required', 'integer', 'between:1,12'],
            'fee' => ['nullable', 'string', 'max:20'],
            'warn_days' => ['nullable', 'integer', 'between:1,365'],
        ]));
        $fee = Input::optionalString($data, 'fee');
        $cents = $fee === null ? 0 : Money::parse($fee);
        if ($cents === null || $cents < 0) {
            throw ValidationException::withMessages(['fee' => 'Importo non valido.']);
        }
        $this->settings->set(MembershipService::START_MONTH, (int) $request->integer('start_month'));
        $this->settings->set(MembershipService::DEFAULT_FEE, $cents);
        $this->settings->set(CertificateService::WARN_DAYS, $request->integer('warn_days', 30));

        return back()->with('status', 'Impostazioni del tesseramento salvate.');
    }

    private function form(?Person $person, Request $request): Response
    {
        $year = $this->memberships->socialYear();

        return Inertia::render('Soci/Scheda', [
            'person' => $person === null ? null : [
                'id' => $person->id,
                'type' => $person->type->value,
                'is_entity' => $person->is_entity,
                'entity_same_section' => $person->entity_same_section,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'phone' => $person->phone,
                'card_number' => $person->card_number,
                'tax_code' => $person->tax_code,
                'birth_date' => $person->birth_date?->format('Y-m-d'),
                'birth_place' => $person->birth_place,
                'address' => is_array($person->address) && is_string($person->address['text'] ?? null) ? $person->address['text'] : '',
                'iban' => $person->iban,
                'notes' => $person->notes,
                'level_id' => $person->level_id,
                'joined_on' => $person->joined_on?->format('Y-m-d'),
                'left_on' => $person->left_on?->format('d/m/Y'),
                'left_reason' => $person->left_reason,
                'minor' => Age::isMinor($person->birth_date?->format('Y-m-d'), now()->toDateString()),
                'active' => $this->memberships->isActive($person->id),
                'memberships' => $this->memberships->history($person->id),
                'fee' => Money::plain($this->memberships->feeFor($person)),
                'guardians' => $this->guardians->of($person->id),
                'dependents' => $this->guardians->dependentsOf($person->id),
                'consents' => $this->consents->current($person->id),
                // I dati sanitari si aprono (e si annotano nel registro) solo per chi li può vedere.
                'certificates' => $request->user()?->can('health.view') === true ? $this->certificates->forPerson($person->id) : null,
            ],
            'firstFreeCard' => $this->people->firstFreeCard(),
            'types' => self::types(),
            'levels' => MemberLevel::query()->where('active', true)->orderBy('sort_order')->get(['id', 'name'])
                ->map(static fn (MemberLevel $l): array => ['id' => $l->id, 'name' => $l->name])->values(),
            'accounts' => $this->ledger->openAccounts(),
            'socialYear' => ['current' => $year->label(), 'next' => $year->next()->label()],
            'settings' => [
                'start_month' => $this->memberships->startMonth(),
                'fee' => Money::plain($this->settings->int(MembershipService::DEFAULT_FEE, 0)),
                'warn_days' => $this->certificates->warnDays(),
            ],
            'consentChannels' => array_map(static fn (ConsentChannel $c): array => ['value' => $c->value, 'label' => $c->label()], ConsentChannel::cases()),
            'consentSources' => array_map(static fn (ConsentSource $c): array => ['value' => $c->value, 'label' => $c->label()], [ConsentSource::Paper, ConsentSource::Oral, ConsentSource::Admin]),
            'certificateKinds' => array_map(static fn (CertificateKind $k): array => ['value' => $k->value, 'label' => $k->label()], CertificateKind::cases()),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canSettings' => $request->user()?->can('org.settings') ?? false,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = Input::of($request->validate([
            'type' => ['required', Rule::enum(MemberType::class)],
            'is_entity' => ['nullable', 'boolean'],
            'entity_same_section' => ['nullable', 'boolean'],
            'first_name' => ['required_unless:is_entity,1,true', 'nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'card_number' => ['nullable', 'string', 'max:40'],
            'tax_code' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'string', 'max:10'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:190'],
            'iban' => ['nullable', 'string', 'max:42'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'level_id' => ['nullable', 'uuid'],
            'host_person_id' => ['nullable', 'uuid'],
            'joined_on' => ['nullable', 'string', 'max:10'],
        ]));
        $address = Input::optionalString($data, 'address');
        $data['address'] = $address === null ? null : ['text' => $address];

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return list<array{value: string, label: string}> */
    private static function types(): array
    {
        return array_map(static fn (MemberType $t): array => ['value' => $t->value, 'label' => $t->label()], MemberType::cases());
    }

    /** @param  Closure(): RedirectResponse  $action */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => implode(' ', $e->messages)]);
        } catch (LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }
}

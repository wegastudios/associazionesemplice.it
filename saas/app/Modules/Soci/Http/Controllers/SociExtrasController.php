<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Input;
use App\Modules\Soci\Contracts\CertificateKind;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Rules\ExpiryRules;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\LevelService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tutori dei minori, certificati medici e livelli di socio. */
final readonly class SociExtrasController
{
    public function __construct(
        private GuardianService $guardians,
        private CertificateService $certificates,
        private LevelService $levels,
    ) {}

    public function linkGuardian(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate(['guardian_id' => ['required', 'uuid']]));

        return $this->attempt(fn () => $this->guardians->link($person, Input::string($data, 'guardian_id')), 'Tutore collegato.');
    }

    public function operativeGuardian(string $person, string $guardian): RedirectResponse
    {
        return $this->attempt(fn () => $this->guardians->setOperative($person, $guardian), 'Tutore operativo cambiato.');
    }

    public function unlinkGuardian(string $person, string $guardian): RedirectResponse
    {
        return $this->attempt(fn () => $this->guardians->unlink($person, $guardian), 'Tutore scollegato.');
    }

    public function addCertificate(Request $request, string $person): RedirectResponse
    {
        $data = Input::of($request->validate([
            'kind' => ['required', Rule::enum(CertificateKind::class)],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'doctor' => ['nullable', 'string', 'max:190'],
            'file' => ['nullable', 'file', 'max:10240', 'mimetypes:'.implode(',', CertificateService::MIMES)],
        ]));
        $file = $request->file('file');

        return $this->attempt(
            fn () => $this->certificates->add($person, $data, $file instanceof UploadedFile ? $file : null),
            'Certificato registrato.',
        );
    }

    public function deleteCertificate(string $certificate): RedirectResponse
    {
        return $this->attempt(fn () => $this->certificates->delete($certificate), 'Certificato tolto.');
    }

    public function downloadCertificate(string $certificate): StreamedResponse
    {
        try {
            return $this->certificates->download($certificate);
        } catch (PeopleError) {
            abort(404);
        }
    }

    public function certificates(Request $request): Response
    {
        $filter = $request->string('stato')->toString();
        $filter = in_array($filter, [ExpiryRules::VALID, ExpiryRules::EXPIRING, ExpiryRules::EXPIRED, ExpiryRules::NONE], true) ? $filter : '';

        return Inertia::render('Soci/Certificati', [
            'filter' => $filter,
            'rows' => $this->certificates->overview($filter),
            'warnDays' => $this->certificates->warnDays(),
        ]);
    }

    public function levels(Request $request): Response
    {
        return Inertia::render('Soci/Livelli', [
            'levels' => array_map(static fn (array $l): array => $l + ['fee' => $l['fee_cents'] === null ? '' : Money::plain($l['fee_cents'])], $this->levels->all()),
            'bases' => array_values(array_map(
                static fn (MemberType $t): array => ['value' => $t->value, 'label' => $t->label()],
                array_filter(MemberType::cases(), static fn (MemberType $t): bool => $t->isMember()),
            )),
            'canEdit' => $request->user()?->can('org.settings') ?? false,
        ]);
    }

    public function storeLevel(Request $request): RedirectResponse
    {
        [$name, $base, $fee] = $this->level($request);

        return $this->attempt(fn () => $this->levels->create($name, $base, $fee), 'Livello creato.');
    }

    public function updateLevel(Request $request, string $level): RedirectResponse
    {
        [$name, $base, $fee] = $this->level($request);
        $active = $request->boolean('active', true);

        return $this->attempt(fn () => $this->levels->update($level, $name, $base, $fee, $active), 'Livello salvato.');
    }

    /** @return array{string, MemberType, int|null} */
    private function level(Request $request): array
    {
        $data = Input::of($request->validate([
            'name' => ['required', 'string', 'max:80'],
            'base_type' => ['required', Rule::in(['founder', 'ordinary', 'volunteer'])],
            'fee' => ['nullable', 'string', 'max:20'],
            'active' => ['boolean'],
        ]));
        $raw = Input::optionalString($data, 'fee');
        $fee = $raw === null ? null : Money::parse($raw);
        if ($raw !== null && $fee === null) {
            throw ValidationException::withMessages(['fee' => 'Importo non valido.']);
        }

        return [Input::string($data, 'name'), MemberType::from(Input::string($data, 'base_type')), $fee];
    }

    /** @param  Closure(): mixed  $action */
    private function attempt(Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => implode(' ', $e->messages)]);
        }

        return back()->with('status', $message);
    }
}

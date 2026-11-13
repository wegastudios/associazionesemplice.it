<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Services\ApplicationService;
use App\Modules\Soci\Services\PeopleService;
use App\Modules\Soci\Services\ReminderService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Domande di adesione in gestionale, e le impostazioni di domande online e promemoria. */
final readonly class ApplicationsController
{
    public function __construct(
        private ApplicationService $applications,
        private PeopleService $people,
        private OrgSettings $settings,
        private OrgContext $context,
        private DatabaseManager $db,
        private OrgDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $slug = $this->directory->slug($this->context->require()->id);

        return Inertia::render('Soci/Domande', [
            'pending' => $this->applications->pending(),
            'decided' => $this->applications->decided(),
            'firstFreeCard' => $this->people->firstFreeCard(),
            'formUrl' => is_string($slug) ? url('/adesione/'.$slug) : null,
            'settings' => [
                'applications_enabled' => $this->applications->enabled(),
                'applications_rules' => $this->applications->needsRules(),
                'privacy_url' => $this->text(PublicApplicationController::PRIVACY_URL),
                'rules_url' => $this->text(PublicApplicationController::RULES_URL),
                'reminders_enabled' => $this->settings->get(ReminderService::ENABLED, false) === true,
                'reminders_days' => $this->settings->int(ReminderService::DAYS, 30),
            ],
            'canEdit' => $request->user()?->can('people.edit') ?? false,
            'canSettings' => $request->user()?->can('org.settings') ?? false,
        ]);
    }

    /** I dati del modulo: si aprono (cifrati) solo quando la segreteria guarda la domanda. */
    public function show(Request $request, string $application): Response
    {
        try {
            $a = $this->applications->details($application);
        } catch (PeopleError) {
            abort(404);
        }
        $data = $a->data;

        return Inertia::render('Soci/Domanda', [
            'application' => [
                'id' => $a->id,
                'status' => $a->status,
                'minor' => $a->minor,
                'submitted' => $a->submitted_at->format('d/m/Y H:i'),
                'reason' => $a->reason,
                'person_id' => $a->person_id,
                'type' => MemberType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '')?->label() ?? '',
                'data' => $data,
            ],
            'firstFreeCard' => $this->people->firstFreeCard(),
            'canEdit' => $request->user()?->can('people.edit') ?? false,
        ]);
    }

    public function approve(Request $request, string $application): RedirectResponse
    {
        $data = Input::of($request->validate(['card_number' => ['nullable', 'string', 'max:40']]));
        $name = $this->db->connection($this->context->require()->connection)->table('app.orgs')->value('name');

        try {
            $personId = $this->applications->approve($application, Input::optionalString($data, 'card_number'), is_string($name) ? $name : '');
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => implode(' ', $e->messages)]);
        }

        return redirect()->route('people.show', $personId)->with('status', 'Domanda accolta: scheda creata.');
    }

    public function reject(Request $request, string $application): RedirectResponse
    {
        $data = Input::of($request->validate(['reason' => ['required', 'string', 'max:200']]));
        try {
            $name = $this->db->connection($this->context->require()->connection)->table('app.orgs')->value('name');
            $this->applications->reject($application, Input::string($data, 'reason'), is_string($name) ? $name : '');
        } catch (PeopleError $e) {
            throw ValidationException::withMessages(['ledger' => implode(' ', $e->messages)]);
        }

        return redirect()->route('applications')->with('status', 'Domanda respinta.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'applications_enabled' => ['required', 'boolean'],
            'applications_rules' => ['required', 'boolean'],
            'privacy_url' => ['nullable', 'url:https', 'max:300'],
            'rules_url' => ['nullable', 'url:https', 'max:300'],
            'reminders_enabled' => ['required', 'boolean'],
            'reminders_days' => ['required', 'integer', 'between:1,365'],
        ]));
        $this->settings->set(ApplicationService::ENABLED, $request->boolean('applications_enabled'));
        $this->settings->set(ApplicationService::NEEDS_RULES, $request->boolean('applications_rules'));
        $this->settings->set(PublicApplicationController::PRIVACY_URL, Input::optionalString($data, 'privacy_url'));
        $this->settings->set(PublicApplicationController::RULES_URL, Input::optionalString($data, 'rules_url'));
        $this->settings->set(ReminderService::ENABLED, $request->boolean('reminders_enabled'));
        $this->settings->set(ReminderService::DAYS, $request->integer('reminders_days'));

        return back()->with('status', 'Impostazioni salvate.');
    }

    private function text(string $key): string
    {
        $value = $this->settings->get($key);

        return is_string($value) ? $value : '';
    }
}

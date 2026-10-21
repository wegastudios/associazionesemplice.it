<?php

namespace App\Http\Controllers\Admin\Hub;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Hub\SiteService;
use App\Services\Hub\SupportSettings;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Registro dei siti di supporto: credenziali, priorita consentite, riapertura, indirizzi di avviso. */
class SiteController extends Controller
{
    public function index(): View
    {
        $sites = Site::query()->withCount('tickets')->orderBy('name')->get();

        return view('admin.hub.sites.index', ['sites' => $sites]);
    }

    public function create(SupportSettings $settings): View
    {
        return view('admin.hub.sites.create', ['labels' => $settings->priorityLabels()]);
    }

    public function store(Request $request, SiteService $sites): View
    {
        $data = $this->validated($request);
        $result = $sites->create(
            $data['name'],
            $data['portal_email'],
            $data['allowed_priorities'],
            $data['url'] ?? null,
            SupportSettings::parseEmails((string) ($data['notify_emails'] ?? '')),
            $request->boolean('allow_reopen'),
            Actor::user($request->user()->id),
        );

        // Le credenziali si vedono una sola volta, subito dopo la creazione
        return view('admin.hub.sites.credentials', ['site' => $result['site'], 'token' => $result['token'], 'hmac' => $result['hmac_secret'], 'rotated' => false]);
    }

    public function show(Site $site, SupportSettings $settings): View
    {
        return view('admin.hub.sites.show', [
            'site' => $site->loadCount('tickets'),
            'labels' => $settings->priorityLabels(),
        ]);
    }

    public function update(Request $request, Site $site, SiteService $sites): RedirectResponse
    {
        $data = $this->validated($request);
        $sites->update($site, [
            'name' => $data['name'],
            'portal_email' => $data['portal_email'],
            'url' => $data['url'] ?? null,
            'allowed_priorities' => $data['allowed_priorities'],
            'allow_reopen' => $request->boolean('allow_reopen'),
            'notify_emails' => SupportSettings::parseEmails((string) ($data['notify_emails'] ?? '')),
        ], Actor::user($request->user()->id));

        return back()->with('ok', 'Regole del portale salvate.');
    }

    public function rotate(Request $request, Site $site, SiteService $sites): View
    {
        abort_if($site->isRevoked(), 409);
        $token = $sites->rotate($site, Actor::user($request->user()->id));

        return view('admin.hub.sites.credentials', ['site' => $site, 'token' => $token, 'hmac' => null, 'rotated' => true]);
    }

    public function revoke(Request $request, Site $site, SiteService $sites): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        $sites->revoke($site, Actor::user($request->user()->id), $request->input('reason'));

        return redirect()->route('admin.sites.show', $site)->with('ok', 'Portale revocato: le sue credenziali non funzionano piu.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'portal_email' => 'required|email|max:190',
            'url' => 'nullable|url|max:255',
            'allowed_priorities' => 'required|array|min:1',
            'allowed_priorities.*' => 'integer|between:0,4',
            'notify_emails' => 'nullable|string|max:2000',
        ], [
            'allowed_priorities.required' => 'Seleziona almeno un livello di priorità consentito.',
        ]);
    }
}

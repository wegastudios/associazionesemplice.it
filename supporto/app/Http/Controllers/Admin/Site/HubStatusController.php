<?php

namespace App\Http\Controllers\Admin\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Site\HubClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Stato del collegamento con l'hub e regole ricevute (priorità consentite, riapertura). */
class HubStatusController extends Controller
{
    public function show(HubClient $hub): View
    {
        return view('admin.site.hub', [
            'configured' => $hub->isConfigured(),
            'url' => config('support.hub.url'),
            'rules' => session('hub_rules'),
            'pending' => SupportTicket::query()->whereNull('hub_id')->count(),
        ]);
    }

    public function check(HubClient $hub): RedirectResponse
    {
        try {
            $rules = $hub->rules(fresh: true);
        } catch (HubRejectedException $e) {
            return back()->withErrors(['hub' => "L'hub ha rifiutato le credenziali o la richiesta: {$e->getMessage()}"]);
        } catch (HubUnavailableException $e) {
            return back()->withErrors(['hub' => $e->getMessage()]);
        }

        return back()->with('ok', 'Collegamento con l\'hub riuscito.')->with('hub_rules', $rules);
    }
}

<?php

namespace App\Http\Controllers\Admin\Hub;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Hub\SupportSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Impostazioni globali: etichette delle priorita e indirizzi avvisati per i livelli 3 e 4. */
class SettingsController extends Controller
{
    public function edit(SupportSettings $settings): View
    {
        return view('admin.hub.settings', [
            'labels' => $settings->priorityLabels(),
            'extra' => $settings->extraNotifyEmails(),
            'fixed' => config('support.notify_emails'),
        ]);
    }

    public function update(Request $request, SupportSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'labels' => 'required|array',
            'labels.*' => 'nullable|string|max:30',
            'notify_emails' => 'nullable|string|max:2000',
        ]);

        $settings->savePriorityLabels($data['labels']);
        $settings->saveExtraNotifyEmails(SupportSettings::parseEmails((string) ($data['notify_emails'] ?? '')));
        $audit->record('settings.updated', $request->user(), ['notify_emails' => $settings->extraNotifyEmails()]);

        return back()->with('ok', 'Impostazioni salvate.');
    }
}

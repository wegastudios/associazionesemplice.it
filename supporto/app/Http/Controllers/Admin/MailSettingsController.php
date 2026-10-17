<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/** Impostazioni della posta in uscita (server, mittente) modificabili dal pannello. */
class MailSettingsController extends Controller
{
    public function edit(MailSettings $settings): View
    {
        return view('admin.mail', ['values' => $settings->current(), 'hasPassword' => $settings->hasPassword(), 'defaults' => $settings->defaults()]);
    }

    public function update(Request $request, MailSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => 'required|integer|min:1|max:65535',
            'encryption' => 'required|in:starttls,ssl',
            'username' => 'nullable|string|max:190',
            'password' => 'nullable|string|max:190',
            'from_address' => 'required|email|max:190',
            'from_name' => 'required|string|max:100',
        ], [
            'host.regex' => 'Indica solo il nome del server (es. mail.esempio.it), senza protocollo.',
        ]);

        $settings->save($data);
        $audit->record('admin.mail_settings_updated', $request->user(), ['host' => $data['host'], 'from_address' => $data['from_address']]);

        return back()->with('ok', 'Impostazioni email salvate.');
    }

    public function test(Request $request, MailSettings $settings): RedirectResponse
    {
        $settings->apply();
        $to = $request->user()->email;

        try {
            Mail::raw('Messaggio di prova inviato dal supporto di Associazione Semplice: la posta in uscita funziona.', fn ($m) => $m->to($to)->subject('Prova di invio · Wega Support'));
        } catch (\Throwable $e) {
            return back()->withErrors(['mail' => 'Invio non riuscito: '.$e->getMessage()]);
        }

        return back()->with('ok', "Messaggio di prova inviato a {$to}.");
    }
}

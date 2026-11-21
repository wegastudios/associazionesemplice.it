<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\OperatorEntry;
use App\Modules\Piattaforma\Identity\StaffPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * `/accesso-unico` sul gestionale. Il biglietto del login unico arriva in POST da una pagina intermedia (nel GET non c'è
 * nulla da consumare). Chi entra passa sempre dalla sfida del secondo livello del gestionale; se nel browser c'è già
 * la sessione di un altro utente, prima si chiede conferma.
 */
final readonly class OperatorSsoController
{
    public function __construct(private OperatorEntry $entry) {}

    /** La pagina intermedia: legge il biglietto dal frammento dell'indirizzo e lo manda in POST. */
    public function __invoke(): Response|RedirectResponse
    {
        if (! $this->entry->enabled()) {
            return redirect()->away($this->entry->accountUrl());
        }

        return response()->view('operator-sso.forward', ['account' => $this->entry->accountUrl()]);
    }

    /** Lo script della pagina intermedia (un file a parte: la politica di sicurezza non ammette script nella pagina). */
    public function script(): Response
    {
        return response()->view('operator-sso.forward-js')->header('Content-Type', 'application/javascript; charset=utf-8');
    }

    public function enter(Request $request): Response|RedirectResponse
    {
        $token = $request->input('t');
        $person = $this->entry->enabled() && is_string($token) && $token !== '' && strlen($token) < 4000 ? $this->entry->consume($token) : null;
        $target = $person === null ? null : $this->entry->target($person);
        if ($person === null || $target === null) {
            return redirect()->away($this->entry->accountUrl());
        }

        // Mai un legame senza secondo livello: l'email da sola non basta per entrare.
        if (! $target['linked'] && ! $this->entry->hasTwoFactor($target['user'])) {
            return response()->view('operator-sso.denied', ['account' => $this->entry->accountUrl()]);
        }

        $current = Auth::guard('web')->id();
        if (is_string($current)) {
            if ($current === $target['user']) {
                return redirect('/gestionale');
            }
            $request->session()->put(OperatorEntry::PENDING_KEY, ['person' => $person->id, 'email' => $person->email, 'name' => $person->name, 'user' => $target['user'], 'until' => time() + OperatorEntry::PENDING_SECONDS]);

            return response()->view('operator-sso.switch', ['current' => $this->entry->describe($current), 'next' => $this->entry->describe($target['user'])]);
        }

        return $this->open($request, $person->id, $target['user'], $target['linked']);
    }

    /** «Sì, cambia utente»: vale solo per l'ingresso messo in attesa poco prima in questa sessione. */
    public function confirm(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(OperatorEntry::PENDING_KEY);
        if (! $this->entry->enabled() || ! is_array($pending) || ! is_int($pending['until'] ?? null) || $pending['until'] < time()
            || ! is_string($pending['person'] ?? null) || ! is_string($pending['email'] ?? null) || ! is_string($pending['name'] ?? null) || ! is_string($pending['user'] ?? null)) {
            return redirect('/gestionale');
        }
        $person = new StaffPerson($pending['person'], $pending['email'], $pending['name']);
        $target = $this->entry->target($person);
        if ($target === null || $target['user'] !== $pending['user'] || (! $target['linked'] && ! $this->entry->hasTwoFactor($target['user']))) {
            return redirect()->away($this->entry->accountUrl());
        }

        return $this->open($request, $person->id, $target['user'], $target['linked']);
    }

    /** Sessione nuova e sfida del secondo livello del gestionale; senza secondo livello vale la regola degli operatori (attivarlo prima di tutto). */
    private function open(Request $request, string $personId, string $userId, bool $linked): RedirectResponse
    {
        $request->session()->invalidate();

        if ($this->entry->hasTwoFactor($userId)) {
            $request->session()->put(['login.id' => $userId, 'login.remember' => false, 'url.intended' => '/gestionale']);
            if (! $linked) {
                $this->entry->expectLink($userId, $personId);
            }

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->loginUsingId($userId);
        $request->session()->regenerate();

        return redirect('/gestionale');
    }
}

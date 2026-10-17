<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Site\CustomerLink;
use App\Services\Site\SsoToken;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Accesso unico dal portale: lo staff entra nell'amministrazione, il cliente nella sua area (modulo precompilato e i suoi ticket). */
class SsoController extends Controller
{
    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $claims = SsoToken::verify((string) $request->query('t'));
        if ($claims === null) {
            return redirect()->route('help.index')->withErrors(['sso' => 'Il collegamento non è valido o è scaduto: accedi di nuovo dal portale.']);
        }

        $request->session()->regenerate();

        if ($claims['role'] !== 'customer') {
            $user = User::query()->where('email', $claims['sub'])->first() ?? new User(['email' => $claims['sub']]);
            if (! $user->exists) {
                $user->forceFill(['name' => $claims['name'] ?? Str::before($claims['sub'], '@'), 'password' => Str::random(48)]);
            }
            $user->forceFill(['global_role' => 'admin'])->save();
            Auth::login($user);
            $audit->record('admin.login_sso', null, ['role' => $claims['role']], Actor::user($user->id));

            return redirect()->route('admin.home');
        }

        $plan = $claims['plan'] !== null
            ? Plan::query()->where('is_active', true)->whereRaw('lower(name) = ?', [mb_strtolower($claims['plan'])])->first()
            : null;
        $request->session()->put('customer', [
            'email' => $claims['sub'],
            'name' => $claims['name'],
            'plan_id' => $plan?->id,
            'at' => time(),
        ]);

        return redirect()->route('ticket.mine');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('customer');
        $request->session()->regenerateToken();

        return redirect()->route('help.index');
    }

    /** I ticket del cliente connesso, con i link firmati per seguirli. */
    public function mine(Request $request): View|RedirectResponse
    {
        $customer = $request->session()->get('customer');
        if (! is_array($customer)) {
            return redirect(rtrim((string) config('support.sso.portal'), '/').'/supporto/accedi');
        }

        $tickets = SupportTicket::query()->where('customer_email', $customer['email'])->whereNotNull('hub_id')->latest('last_activity_at')->limit(100)->get();

        return view('site.mine', [
            'customer' => $customer,
            'tickets' => $tickets->map(fn (SupportTicket $t) => ['ticket' => $t, 'url' => CustomerLink::show($t)]),
        ]);
    }
}

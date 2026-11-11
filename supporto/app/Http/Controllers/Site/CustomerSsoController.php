<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AuditLogger;
use App\Services\Staff\StaffJwt;
use App\Support\ProductPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accesso dei CLIENTI dal SaaS (token customer_sso EdDSA): l'identità sta nel SaaS, qui restano solo customer_id, email, nome e piani
 * nella sessione e sui ticket. Nessuna password, nessun account. Chiavi dedicate, separate da quelle dello staff.
 */
class CustomerSsoController extends Controller
{
    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $claims = StaffJwt::verifyCustomer((string) $request->input('token'));
        if ($claims === null) {
            $audit->record('customer.sso_denied', null, ['ip' => $request->ip()]);

            return redirect()->route('help.index')->withErrors(['sso' => 'Il collegamento non è valido o è scaduto: accedi di nuovo dall\'area riservata.']);
        }

        $email = $claims['email'];
        $customerId = (string) $claims['sub'];

        $request->session()->regenerate();
        $request->session()->put('customer', [
            'email' => $email,
            'name' => isset($claims['name']) && is_string($claims['name']) && $claims['name'] !== '' ? mb_substr($claims['name'], 0, 150) : null,
            'plan_id' => null,
            'plans' => ProductPlans::sanitize($claims['plans'] ?? null),
            'customer_id' => $customerId,
            'at' => now()->timestamp,
        ]);

        // Primo accesso con l'id stabile: i ticket già aperti con la stessa email ricevono il customer_id
        SupportTicket::query()->where('customer_email', $email)->whereNull('customer_id')->update(['customer_id' => $customerId]);

        return redirect()->route('ticket.mine');
    }
}

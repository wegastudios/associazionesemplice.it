<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Services\Staff\StaffPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pagina «Utenti» dell'area Supporto: chiama il portale, che decide chi può fare cosa (admin | agente). */
class StaffUsersController extends Controller
{
    private const ERRORS = [
        'non_autorizzato' => 'Non hai il permesso di farlo.',
        'livello_troppo_alto' => 'Non puoi assegnare un livello più alto del tuo.',
        'ultimo_admin' => 'Deve restare almeno un amministratore.',
        'super_non_modificabile' => 'Questo utente non si può modificare.',
        'dati_non_validi' => 'I dati inviati non sono validi.',
    ];

    public function index(StaffPortal $portal): View
    {
        $result = $portal->people('list', (string) Auth::user()->email);

        return view('admin.site.users', [
            'people' => $result['status'] === 200 ? ($result['body']['people'] ?? []) : null,
            'error' => $result['status'] === 200 ? null : $this->message($result),
        ]);
    }

    public function invite(Request $request, StaffPortal $portal): RedirectResponse
    {
        return $this->run($portal, 'invite', $this->data($request, true), 'Invito inviato.');
    }

    public function grant(Request $request, StaffPortal $portal): RedirectResponse
    {
        return $this->run($portal, 'grant', $this->data($request, true), 'Livello aggiornato.');
    }

    public function revoke(Request $request, StaffPortal $portal): RedirectResponse
    {
        return $this->run($portal, 'revoke', $this->data($request, false), 'Ruolo tolto.');
    }

    public function deactivate(Request $request, StaffPortal $portal): RedirectResponse
    {
        return $this->run($portal, 'deactivate', $this->data($request, false), 'Utente disattivato.');
    }

    /** @return array{email: string, level?: string} */
    private function data(Request $request, bool $withLevel): array
    {
        return $request->validate([
            'email' => 'required|email|max:190',
            ...($withLevel ? ['level' => ['required', Rule::in(['admin', 'agente'])]] : []),
        ]);
    }

    /** @param  array<string, string>  $data */
    private function run(StaffPortal $portal, string $action, array $data, string $done): RedirectResponse
    {
        $data['email'] = mb_strtolower($data['email']);
        $result = $portal->people($action, (string) Auth::user()->email, $data);

        if ($result['status'] === 200 && ($result['body']['ok'] ?? false) === true) {
            return back()->with('ok', $done);
        }
        // Per dare «admin» o disattivare serve una riconferma recente: il portale la chiede di nuovo
        if (($result['body']['error'] ?? null) === 'riconferma_richiesta') {
            return redirect(rtrim((string) config('support.sso.portal'), '/').'/staff/riconferma');
        }

        return back()->withErrors(['staff' => $this->message($result)]);
    }

    /** @param  array{status: int, body: array<string, mixed>}  $result */
    private function message(array $result): string
    {
        if ($result['status'] === 0 || $result['status'] >= 500) {
            return 'Il portale non risponde: riprova tra poco.';
        }

        return self::ERRORS[$result['body']['error'] ?? ''] ?? 'Richiesta non riuscita.';
    }
}

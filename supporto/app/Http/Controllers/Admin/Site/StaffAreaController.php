<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Services\Staff\StaffPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Selettore «Vai a»: il SaaS dà un token staff_sso per l'altra area (stessa sessione centrale) e il browser lo manda in POST
 * all'area di destinazione. Il ruolo non viene mai dal supporto: l'altra area lo cerca da sé.
 */
class StaffAreaController extends Controller
{
    public const AREAS = ['saas' => 'SaaS', 'wordpress' => 'Portale WordPress'];

    public function go(Request $request, string $area, StaffPortal $portal): View|RedirectResponse
    {
        $staff = $request->session()->get('staff');
        abort_unless(isset(self::AREAS[$area]) && is_array($staff) && isset($staff['areas'][$area]), 404);

        $token = $portal->areaToken((string) $staff['sid'], $area);
        if ($token === null) {
            return back()->withErrors(['staff' => 'Non è stato possibile aprire quell\'area: riprova tra poco.']);
        }

        $destination = $this->destination($area);
        // Questa pagina sola può mandare il modulo verso l'altra area (di norma il sito accetta solo i propri moduli)
        $request->attributes->set('csp_form_action', parse_url($destination, PHP_URL_SCHEME).'://'.parse_url($destination, PHP_URL_HOST));

        return view('admin.site.goto', ['label' => self::AREAS[$area], 'destination' => $destination, 'token' => $token]);
    }

    /** Indirizzo che riceve il token (POST, campo token): sotto lo stesso dominio dell'emittente. */
    private function destination(string $area): string
    {
        $issuer = rtrim((string) config('support.staff_sso.issuer'), '/');
        $origin = parse_url($issuer, PHP_URL_SCHEME).'://'.parse_url($issuer, PHP_URL_HOST);

        return $area === 'saas' ? $issuer.'/admin/sso' : $origin.'/wordpress/admin/sso';
    }
}

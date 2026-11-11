<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Piattaforma\Admin\StaffSso;
use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ingresso dal portale: una POST con il token `staff_sso` (non passa dall'indirizzo né dal referer). La destinazione è
 * sempre e solo la home dell'amministrazione, mai un indirizzo preso dalla richiesta.
 */
final readonly class StaffSsoController
{
    public function __construct(private StaffAccess $access, private StaffSso $sso, private PlatformAudit $audit) {}

    public function receive(Request $request): Response
    {
        abort_unless($this->access->enabled(), 404);

        $token = $request->input('token');
        try {
            $this->sso->signIn(is_string($token) ? $token : '', $request);
        } catch (StaffAccessDenied) {
            $this->audit->record('staff.sso_failed', null, null, [], $request);

            return response()->view('admin.sso-denied', ['login' => $this->access->loginUrl()], 403);
        }

        // Pagina intermedia, non una redirezione: il cookie di sessione è «strict» e un salto che parte dal portale
        // non lo porterebbe; la navigazione che parte da qui sì.
        return response()->view('admin.sso-ok', ['home' => AdminLoginController::url('')]);
    }
}

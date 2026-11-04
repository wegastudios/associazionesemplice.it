<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * Pagina della verifica in due passaggi: attivazione (codice QR e chiave), conferma, codici di recupero.
 * Protetta dalla conferma della password. Le azioni sono gli endpoint di Fortify.
 */
final readonly class TwoFactorSetupController
{
    public function __construct(private Memberships $memberships) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->session()->get('status');

        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $showCodes = $user->hasConfirmedTwoFactor()
            && in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);

        return Inertia::render('Account/TwoFactor', [
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => $user->hasConfirmedTwoFactor(),
            'required' => $this->memberships->isOperator($user->id),
            'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $pending ? Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret) : null,
            'recoveryCodes' => $showCodes ? $user->recoveryCodes() : null,
        ]);
    }
}

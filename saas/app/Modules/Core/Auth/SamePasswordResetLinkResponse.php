<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * «Password dimenticata»: la risposta è identica che l'email esista o no, e anche se il link era appena
 * stato chiesto. Altrimenti il modulo direbbe a chiunque quali email hanno un account.
 */
final class SamePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $message = __('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}

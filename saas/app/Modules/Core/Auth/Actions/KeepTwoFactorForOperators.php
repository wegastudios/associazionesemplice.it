<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/** Gli operatori non possono disattivare la verifica in due passaggi: per loro è obbligatoria. */
final class KeepTwoFactorForOperators extends DisableTwoFactorAuthentication
{
    public function __construct(private readonly Memberships $memberships) {}

    /** @param  mixed  $user */
    public function __invoke($user): void
    {
        if ($user instanceof User && $this->memberships->isOperator($user->id)) {
            throw ValidationException::withMessages([
                'two_factor' => 'Per chi lavora nel gestionale la verifica in due passaggi è obbligatoria.',
            ]);
        }

        parent::__invoke($user);
    }
}

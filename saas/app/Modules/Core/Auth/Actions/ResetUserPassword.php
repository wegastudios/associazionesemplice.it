<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Auth\PasswordRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use UnexpectedValueException;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param  array<string, mixed>  $input */
    public function reset(User $user, array $input): void
    {
        $data = Validator::make($input, ['password' => PasswordRules::rules()])->validate();

        $user->forceFill([
            'password' => Hash::make(self::string($data['password'])),
            // Chi apre il link dell'email ne ha dimostrato il possesso.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }

    private static function string(mixed $value): string
    {
        // Le regole di validazione garantiscono già che sia un testo.
        return is_string($value) ? $value : throw new UnexpectedValueException('Password non valida.');
    }
}

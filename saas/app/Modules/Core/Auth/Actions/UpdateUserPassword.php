<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Auth\PasswordRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use UnexpectedValueException;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param  array<string, mixed>  $input */
    public function update(User $user, array $input): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => PasswordRules::rules(),
        ], [
            'current_password.current_password' => 'La password attuale non è corretta.',
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => Hash::make(self::string($data['password']))])->save();
    }

    private static function string(mixed $value): string
    {
        // Le regole di validazione garantiscono già che sia un testo.
        return is_string($value) ? $value : throw new UnexpectedValueException('Password non valida.');
    }
}

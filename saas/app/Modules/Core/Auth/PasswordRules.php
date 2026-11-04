<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /**
     * Almeno 12 caratteri; in produzione anche il controllo sulle password già finite in violazioni note
     * (Have I Been Pwned, k-anonimato: esce solo l'inizio dell'hash).
     */
    public static function register(bool $production): void
    {
        Password::defaults(static fn (): Password => $production
            ? Password::min(12)->max(200)->uncompromised()
            : Password::min(12)->max(200));
    }

    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }
}

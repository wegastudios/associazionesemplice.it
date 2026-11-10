<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Models\PlatformStaff;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

const STAFF_PASSWORD = 'una-password-di-prova-lunga';

function makeStaff(string $email = 'staff@example.test', string $role = 'admin', bool $twoFactor = true): PlatformStaff
{
    $staff = new PlatformStaff;
    $staff->forceFill(['email' => $email, 'name' => 'Staff di prova', 'role' => $role, 'password' => STAFF_PASSWORD]);
    if ($twoFactor) {
        $staff->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ]);
    }
    $staff->save();

    return $staff->refresh();
}

function staffCode(PlatformStaff $staff): string
{
    $secret = Fortify::currentEncrypter()->decrypt((string) $staff->fresh()?->two_factor_secret);

    return (new Google2FA)->getCurrentOtp(is_string($secret) ? $secret : '');
}


/** Entra come lo staff indicato (password e codice a due passaggi). */
function loginStaff(Tests\TestCase $test, PlatformStaff $staff): void
{
    $test->post('/admin/accesso', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertRedirect('/admin/verifica');
    $test->post('/admin/verifica', ['code' => staffCode($staff)])->assertRedirect('/admin');
}

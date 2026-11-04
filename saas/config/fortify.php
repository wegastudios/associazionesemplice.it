<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
 * Accesso con Laravel Fortify. Niente registrazione pubblica: gli utenti nascono dal portale o da un invito
 * dell'associazione. Verifica in due passaggi obbligatoria per gli operatori (middleware two-factor).
 */
return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/gestionale',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => null,
    ],

    'paths' => [
        'login' => 'accesso',
        'logout' => 'esci',
        'password' => [
            'request' => 'password-dimenticata',
            'email' => 'password-dimenticata',
            'reset' => 'reimposta-password/{token}',
            'update' => 'reimposta-password',
            'confirm' => 'conferma-password',
            'confirmation' => null,
        ],
        'two-factor' => [
            'login' => 'verifica-accesso',
        ],
    ],

    'redirects' => [
        'login' => '/gestionale',
        'logout' => '/accesso',
        'password-confirmation' => null,
        'password-reset' => '/accesso',
    ],

    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
        ]),
    ],
];

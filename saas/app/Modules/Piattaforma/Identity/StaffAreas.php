<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

/** Le tre aree dello staff, i loro livelli e il destinatario (`aud`) dei token `staff_sso` di ciascuna. */
final class StaffAreas
{
    /** @var array<string, string> area => aud */
    public const AUDIENCES = [
        'wordpress' => 'associazionesemplice-portale',
        'saas' => 'associazionesemplice-saas',
        'supporto' => 'associazionesemplice-supporto',
    ];

    /** @var array<string, list<string>> */
    public const LEVELS = [
        'wordpress' => ['admin', 'vendita', 'supporto'],
        'saas' => ['admin', 'vendita', 'supporto'],
        'supporto' => ['admin', 'agente'],
    ];

    public static function exists(string $area): bool
    {
        return isset(self::AUDIENCES[$area]);
    }
}

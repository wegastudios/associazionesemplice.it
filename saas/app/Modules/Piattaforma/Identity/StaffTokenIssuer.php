<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use AppModulesPortaleContractsStaffJwt;

/** Firma i token `staff_sso` (60 secondi, monouso) con la chiave `staff-…` del SaaS. */
final readonly class StaffTokenIssuer
{
    public function __construct(private StaffIdentityConfig $config) {}

    /**
     * @param  array<string, string>  $areas  area => livello di tutte le aree della persona (per il selettore «Vai a»)
     */
    public function issue(StaffPerson $person, string $area, string $sid, array $areas): string
    {
        $now = time();

        return StaffJwt::sign([
            'iss' => $this->config->issuer(),
            'aud' => StaffAreas::AUDIENCES[$area],
            'typ' => 'staff_sso',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => $person->email,
            'area' => $area,
            'level' => $areas[$area],
            // Il secondo fattore è facoltativo: per ora nessuno lo usa, e il token lo dice (mai un obbligo).
            'mfa' => false,
            'sid' => $sid,
            'areas' => $areas,
        ], $this->config->signingSeed(), 'staff-');
    }
}

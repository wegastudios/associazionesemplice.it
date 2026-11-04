<?php

declare(strict_types=1);

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Rules\CertificateRules;
use App\Modules\Soci\Rules\ExpiryRules;
use App\Modules\Soci\Rules\GuardianRules;

// Casi da GuardiansRulesTest del plugin.

function minorData(array $over = []): array
{
    return $over + ['id' => 'm', 'birth_date' => '2012-05-10'];
}

function parentData(array $over = []): array
{
    return $over + ['id' => 'p', 'type' => MemberType::Ordinary, 'email' => 'mamma@example.com', 'birth_date' => '1980-01-01'];
}

it('collega un tutore normale senza errori', function (): void {
    expect(GuardianRules::validate(minorData(), parentData(), 0, '2026-10-26'))->toBe([])
        ->and(GuardianRules::validate(minorData(), parentData(['birth_date' => null]), 1, '2026-10-26'))->toBe([]);
});

it('applica le regole dei tutori del plugin', function (): void {
    $joined = fn (array $e): string => implode(' ', $e);

    expect(GuardianRules::validate(minorData(['birth_date' => null]), parentData(), 0, '2026-10-26'))->toHaveCount(1)
        ->and(GuardianRules::validate(minorData(), parentData(['email' => '']), 0, '2026-10-26'))->not->toBe([])
        ->and(GuardianRules::validate(minorData(), parentData(['email' => 'non-una-email']), 0, '2026-10-26'))->not->toBe([])
        ->and($joined(GuardianRules::validate(minorData(), parentData(['birth_date' => '2010-01-01']), 0, '2026-10-26')))->toContain('maggiorenne')
        ->and($joined(GuardianRules::validate(minorData(), parentData(), 2, '2026-10-26')))->toContain('al massimo 2')
        ->and($joined(GuardianRules::validate(minorData(), parentData(['id' => 'm']), 0, '2026-10-26')))->toContain('se stessa')
        ->and($joined(GuardianRules::validate(minorData(), parentData(['type' => MemberType::Guest]), 0, '2026-10-26')))->toContain('ospite');

    foreach ([MemberType::Ordinary, MemberType::Volunteer, MemberType::Founder, MemberType::Guardian] as $type) {
        expect(GuardianRules::validate(minorData(), parentData(['type' => $type]), 0, '2026-10-26'))->toBe([]);
    }
    // Il secondo tutore, non operativo, non ha bisogno dell'email.
    expect(GuardianRules::validate(minorData(), parentData(['email' => null]), 1, '2026-10-26', operative: false))->toBe([]);
});

it('calcola lo stato delle scadenze', function (): void {
    expect(ExpiryRules::status(null, '2026-06-01', 30))->toBe('none')
        ->and(ExpiryRules::status('2026-05-31', '2026-06-01', 30))->toBe('expired')
        ->and(ExpiryRules::status('2026-06-01', '2026-06-01', 30))->toBe('expiring')
        ->and(ExpiryRules::status('2026-07-01', '2026-06-01', 30))->toBe('expiring')
        ->and(ExpiryRules::status('2026-07-02', '2026-06-01', 30))->toBe('valid')
        ->and(ExpiryRules::daysLeft('2026-05-30', '2026-06-01'))->toBe(-2)
        ->and(ExpiryRules::warnDays('0'))->toBe(30)
        ->and(ExpiryRules::warnDays(15))->toBe(15)
        ->and(ExpiryRules::plusYear('2024-02-29'))->toBe('2025-03-01');
});

it('controlla i dati del certificato', function (): void {
    expect(CertificateRules::clean(['kind' => 'agonistic', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-09', 'doctor' => ' <b>Dr. Bianchi</b> ']))
        ->toMatchArray(['issued_on' => '2026-01-10', 'doctor' => 'Dr. Bianchi'])
        ->and(fn () => CertificateRules::clean(['kind' => 'boh', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-09']))->toThrow(PeopleError::class, 'tipo')
        ->and(fn () => CertificateRules::clean(['kind' => 'other', 'issued_on' => '', 'expires_on' => '2027-01-09']))->toThrow(PeopleError::class, 'rilascio')
        ->and(fn () => CertificateRules::clean(['kind' => 'other', 'issued_on' => '2026-01-10', 'expires_on' => '2025-01-09']))->toThrow(PeopleError::class, 'prima');
});

it('sceglie il certificato che conta oggi', function (): void {
    $certs = [
        ['id' => 'a', 'issued_on' => '2025-01-01', 'expires_on' => '2026-01-01'],
        ['id' => 'b', 'issued_on' => '2025-06-01', 'expires_on' => '2026-06-01'],
        ['id' => 'c', 'issued_on' => '2026-12-01', 'expires_on' => '2027-12-01'],
        ['id' => 'd', 'issued_on' => '2025-07-01', 'expires_on' => '2026-06-01'],
    ];

    expect(CertificateRules::current($certs, '2026-05-01')['id'])->toBe('d')
        ->and(CertificateRules::status($certs, '2026-05-01', 30))->toBe('valid')
        ->and(CertificateRules::status([], '2026-05-01', 30))->toBe('none');
});

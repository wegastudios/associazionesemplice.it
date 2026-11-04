<?php

declare(strict_types=1);

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Rules\Iban;
use App\Modules\Soci\Rules\PersonRules;
use App\Modules\Soci\Rules\Phone;
use App\Modules\Soci\Rules\SocialYear;
use App\Modules\Soci\Rules\TaxCode;

// Casi dai test del plugin (AgeTest, PhoneTest, PeopleRulesTest): le stesse regole nei due progetti.

it('riconosce i tipi di persona come il plugin', function (): void {
    expect(MemberType::Volunteer->canTeach())->toBeTrue()
        ->and(MemberType::Founder->canTeach())->toBeFalse()
        ->and(MemberType::Founder->isAutoRenewed())->toBeTrue()
        ->and(MemberType::Ordinary->isAutoRenewed())->toBeFalse()
        ->and(MemberType::Guest->isMember())->toBeFalse()
        ->and(MemberType::Guest->requiresHost())->toBeTrue()
        ->and(MemberType::Guardian->canLogin())->toBeTrue()
        ->and(MemberType::Guardian->isMember())->toBeFalse();
});

it('controlla il codice fiscale, anche con l\'omocodia', function (): void {
    expect(TaxCode::isValid('vrdgpp75b10f205b'))->toBeTrue()
        ->and(TaxCode::isValid('VRDGPP75B10F205C'))->toBeFalse()
        ->and(TaxCode::isValid('VRDGPP75B10F20RT'))->toBe(TaxCode::isValid('VRDGPP75B10F20RT'))
        ->and(TaxCode::isValid(''))->toBeFalse()
        ->and(TaxCode::normalize(' vrdgpp75b10 f205b '))->toBe('VRDGPP75B10F205B');
});

it('controlla gli IBAN', function (): void {
    expect(Iban::isValid('IT60 X054 2811 1010 0000 0123 456'))->toBeTrue()
        ->and(Iban::isValid('IT60X0542811101000000123457'))->toBeFalse()
        ->and(Iban::isValid('IT60X054281110100000012345'))->toBeFalse()
        ->and(Iban::format('it60x0542811101000000123456'))->toBe('IT60 X054 2811 1010 0000 0123 456')
        ->and(Iban::mask('IT60X0542811101000000123456'))->toBe('IT** **** 3456');
});

it('valida e legge le date come il plugin', function (): void {
    expect(Age::isValidDate('2010-02-28'))->toBeTrue()
        ->and(Age::isValidDate('2010-02-30'))->toBeFalse()
        ->and(Age::isValidDate('1850-01-01'))->toBeFalse()
        ->and(Age::parse('31/12/2010'))->toBe('2010-12-31')
        ->and(Age::parse('31.12.2010'))->toBe('2010-12-31')
        ->and(Age::parse('5/1/2010'))->toBe('2010-01-05')
        ->and(Age::parse('32/12/2010'))->toBeNull()
        ->and(Age::years('2008-06-15', '2026-06-14'))->toBe(17)
        ->and(Age::years('2008-06-15', '2026-06-15'))->toBe(18)
        ->and(Age::years('2030-01-01', '2026-06-15'))->toBeNull()
        ->and(Age::isMinor('2008-06-15', '2026-06-14'))->toBeTrue()
        ->and(Age::isMinor(null, '2026-06-15'))->toBeFalse()
        ->and(Age::adultOn('2008-02-29'))->toBe('2026-03-01')
        ->and(Age::becameAdultWithin('2008-06-15', '2026-06-20', 30))->toBeTrue()
        ->and(Age::becameAdultWithin('2008-06-15', '2026-08-20', 30))->toBeFalse()
        ->and(Age::display('2010-01-05'))->toBe('05/01/2010');
});

it('riconosce lo stesso cellulare scritto in modi diversi', function (): void {
    foreach (['3331234567', '333 123 4567', '+39 333 123 4567', '0039 333-1234567', '(+39) 333.123.4567', '39 3331234567'] as $raw) {
        expect(Phone::key($raw))->toBe('3331234567');
    }
    expect(Phone::isValid('+44 7911 123456'))->toBeTrue()
        ->and(Phone::isValid('12345'))->toBeFalse()
        ->and(Phone::whatsapp('333 123 4567'))->toBe('393331234567')
        ->and(Phone::whatsapp('0044 7911 123456'))->toBe('447911123456');
});

it('calcola l\'anno sociale', function (): void {
    expect(SocialYear::forDate('2026-09-01', 9)->label())->toBe('2026/2027')
        ->and(SocialYear::forDate('2026-08-31', 9)->label())->toBe('2025/2026')
        ->and(SocialYear::forDate('2026-03-01', 1)->label())->toBe('2026')
        ->and(SocialYear::fromLabel('2025/2026', 9)->end()->format('Y-m-d'))->toBe('2026-08-31')
        ->and(SocialYear::fromLabel('2026', 9)->start()->format('Y-m-d'))->toBe('2026-01-01');
});

function personData(array $over = []): array
{
    return $over + ['type' => 'ordinary', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'm@example.com', 'card_number' => '', 'host_person_id' => null];
}

it('valida le schede come il plugin', function (): void {
    expect(PersonRules::validate(personData()))->toBe([])
        ->and(PersonRules::validate(personData(['email' => ''])))->toBe([])
        ->and(PersonRules::validate(personData(['email' => 'non-una-email'])))->not->toBe([])
        ->and(PersonRules::validate(personData(['first_name' => ' '])))->not->toBe([])
        ->and(PersonRules::validate(personData(['first_name' => str_repeat('a', 121)])))->not->toBe([])
        ->and(PersonRules::validate(personData(['first_name' => str_repeat('à', 100)])))->toBe([])
        ->and(PersonRules::validate(personData(['joined_on' => '2023-02-29'])))->not->toBe([])
        ->and(PersonRules::validate(personData(['tax_code' => 'VRDGPP75B10F205C'])))->toBe(['Il codice fiscale non è valido.'])
        ->and(PersonRules::validate(personData(['birth_date' => '2999-01-01']), null, '2026-01-01'))->toBe(['La data di nascita è nel futuro.'])
        ->and(PersonRules::validate(personData(['host_person_id' => 'x'])))->toBe(['Solo gli ospiti hanno un socio ospitante.']);
});

it('chiede agli ospiti un socio ospitante e il cellulare, e niente tessera', function (): void {
    $guest = personData(['type' => 'guest', 'email' => '', 'phone' => '333 1234567', 'host_person_id' => 'x']);

    expect(PersonRules::validate($guest, null))->not->toBe([])
        ->and(PersonRules::validate($guest, MemberType::Guest))->not->toBe([])
        ->and(PersonRules::validate($guest, MemberType::Ordinary))->toBe([])
        ->and(PersonRules::validate(['card_number' => '12'] + $guest, MemberType::Ordinary))->not->toBe([])
        ->and(PersonRules::validate(['phone' => '12345'] + $guest, MemberType::Ordinary))->not->toBe([]);
});

<?php

declare(strict_types=1);

use App\Modules\Soci\Rules\ApplicationRules;

// Casi portati dall'ApplicationRulesTest del plugin. Dati di prova inventati.
const APP_TODAY = '2026-10-26';

/** @param  array<string, string>  $over */
function adultForm(array $over = []): array
{
    return $over + [
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'Mario@Example.com', 'phone' => '333 1234567', 'tax_code' => 'rssmra80a01h501u',
        'birth_date' => '01/01/1980', 'type' => 'ordinary', 'privacy_ok' => '1', 'rules_ok' => '1',
    ];
}

/** @param  array<string, string>  $over */
function minorForm(array $over = []): array
{
    return $over + [
        'first_name' => 'Luigi', 'last_name' => 'Verdi', 'email' => '', 'phone' => '', 'tax_code' => 'VRDLGU10A01H501X', 'birth_date' => '2010-01-01', 'type' => 'ordinary',
        'privacy_ok' => '1', 'rules_ok' => '1', 'guardian_ok' => '1', 'guardian_first_name' => 'Anna', 'guardian_last_name' => 'Verdi',
        'guardian_email' => 'anna@example.com', 'guardian_phone' => '339 7654321',
    ];
}

/** @return array{errors: list<string>, minor: bool, data: array<string, mixed>} */
function checkForm(array $in, bool $rules = true): array
{
    return ApplicationRules::check($in, APP_TODAY, $rules, ['ordinary', 'volunteer']);
}

it('accetta una domanda corretta di un maggiorenne', function (): void {
    $r = checkForm(adultForm());
    expect($r['errors'])->toBe([])
        ->and($r['minor'])->toBeFalse()
        ->and($r['data']['email'])->toBe('mario@example.com')
        ->and($r['data']['tax_code'])->toBe('RSSMRA80A01H501U')
        ->and($r['data']['birth_date'])->toBe('1980-01-01')
        ->and($r['data']['guardian'])->toBe([]);
});

it('chiede i campi obbligatori', function (): void {
    $text = mb_strtolower(implode(' ', checkForm(['privacy_ok' => '1'])['errors']));
    foreach (['nome e cognome', 'data di nascita', 'codice fiscale', 'tipo di socio', 'email', 'cellulare'] as $word) {
        expect($text)->toContain($word);
    }
});

it('controlla il carattere di controllo del codice fiscale', function (): void {
    expect(implode(' ', checkForm(adultForm(['tax_code' => 'RSSMRA80A01H501X']))['errors']))->toContain('codice fiscale');
});

it('vuole privacy e regolamento accettati', function (): void {
    $text = implode(' ', checkForm(adultForm(['privacy_ok' => '', 'rules_ok' => '']))['errors']);
    expect($text)->toContain('privacy')->toContain('regolamento')
        ->and(checkForm(adultForm(['rules_ok' => '']), false)['errors'])->toBe([]);
});

it('ammette solo i tipi di socio previsti', function (): void {
    expect(checkForm(adultForm(['type' => 'founder']))['errors'])->not->toBe([])
        ->and(checkForm(adultForm(['type' => 'guest']))['errors'])->not->toBe([]);
});

it('vuole una data di nascita vera e non nel futuro', function (string $date): void {
    expect(checkForm(adultForm(['birth_date' => $date]))['errors'])->not->toBe([]);
})->with(['31/02/1990', '2030-01-01', '1900-01-01']);

it('chiede i dati del genitore per un minore', function (): void {
    $r = checkForm(minorForm());
    expect($r['errors'])->toBe([])
        ->and($r['minor'])->toBeTrue()
        ->and($r['data']['guardian']['email'])->toBe('anna@example.com')
        ->and($r['data']['email'])->toBe('');

    $text = mb_strtolower(implode(' ', checkForm(minorForm(['guardian_first_name' => '', 'guardian_email' => '', 'guardian_phone' => '', 'guardian_ok' => '']))['errors']));
    foreach (['genitore o tutore', 'email del genitore', 'cellulare del genitore', 'dichiari'] as $word) {
        expect($text)->toContain($word);
    }
});

it('lascia al minore un\'email facoltativa ma valida', function (): void {
    expect(checkForm(minorForm(['email' => 'luigi@example.com', 'phone' => '333 1234567']))['errors'])->toBe([])
        ->and(checkForm(minorForm(['email' => 'boh']))['errors'])->not->toBe([]);
});

it('controlla il codice fiscale del genitore solo se c\'è', function (): void {
    expect(checkForm(minorForm(['guardian_tax_code' => '']))['errors'])->toBe([])
        ->and(checkForm(minorForm(['guardian_tax_code' => 'ABCDEF12G34H567I']))['errors'])->not->toBe([]);
});

it('ignora i dati del genitore per un maggiorenne', function (): void {
    $r = checkForm(adultForm(['guardian_first_name' => 'Chi', 'guardian_email' => 'x@example.com']));
    expect($r['errors'])->toBe([])->and($r['data']['guardian'])->toBe([]);
});

it('considera maggiorenne chi compie 18 anni oggi', function (): void {
    expect(checkForm(adultForm(['birth_date' => '2008-10-26']))['minor'])->toBeFalse()
        ->and(checkForm(minorForm(['birth_date' => '2008-10-27']))['minor'])->toBeTrue();
});

it('toglie al minore l\'email uguale a quella del genitore', function (): void {
    $r = checkForm(minorForm(['email' => 'Anna@Example.com']));
    expect($r['errors'])->toBe([])->and($r['data']['email'])->toBe('')
        ->and(checkForm(minorForm(['email' => 'luigi@example.com']))['data']['email'])->toBe('luigi@example.com');
});

<?php

declare(strict_types=1);

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Import\PeopleSheet;

// Casi portati da PeopleCsvTest, PeopleCsvYearsTest, PeopleGuestsImportTest e PeopleBirthGuardianTest del plugin.
// Dati di prova inventati.

/** @return list<array<string, mixed>> */
function sheetRows(string $csv): array
{
    $p = PeopleSheet::parse($csv);
    expect($p)->toHaveKey('rows');

    return $p['rows'] ?? [];
}

function sheetExisting(): array
{
    return [
        ['id' => 'p1', 'card' => '10', 'first' => 'Mario', 'last' => 'Rossi', 'email' => 'mario@x.it', 'tax' => 'RSSMRA80A01H501U'],
        ['id' => 'p2', 'card' => null, 'first' => 'Anna', 'last' => 'Verdi', 'email' => 'anna@x.it', 'tax' => null],
    ];
}

function sheetPlan(string $csv, MemberType $default = MemberType::Ordinary, ?array $existing = null): array
{
    return PeopleSheet::plan(sheetRows($csv), $existing ?? sheetExisting(), $default);
}

it('legge le colonne in qualunque ordine', function (): void {
    $r = sheetRows("Cognome;Nome;N. Tessera;Email;Tipo\nRossi;Mario;12;m@x.it;volontario\nBianchi;Anna;;a@x.it;\n");
    expect($r)->toHaveCount(2)
        ->and($r[0]['first'])->toBe('Mario')->and($r[0]['card'])->toBe('12')->and($r[0]['type_text'])->toBe('volontario')
        ->and($r[1]['card'])->toBeNull()->and($r[1]['type_text'])->toBeNull();
});

it('gestisce gli artefatti di Excel e le virgolette', function (): void {
    $r = sheetRows("tessera,nome,cognome,email\n123.0,\"Anna, Maria\",\"De \"\"Luca\"\"\",a@b.it");
    expect($r[0]['card'])->toBe('123')->and($r[0]['first'])->toBe('Anna, Maria')->and($r[0]['last'])->toBe('De "Luca"');
});

it('rifiuta i file senza nome e cognome o vuoti', function (): void {
    expect(PeopleSheet::parse("Tessera;Nome\n1;Mario"))->toHaveKey('error')
        ->and(PeopleSheet::parse(''))->toHaveKey('error');
});

it('decodifica Windows-1252 e toglie il BOM', function (): void {
    $bytes = mb_convert_encoding("Nome;Cognome\nNiccolò;D'Amico", 'Windows-1252', 'UTF-8');
    expect(PeopleSheet::decode($bytes))->toContain('Niccolò')
        ->and(PeopleSheet::decode("\xEF\xBB\xBFa;b"))->toBe('a;b');
});

it('crea una persona nuova con il tipo predefinito', function (): void {
    $p = sheetPlan("Tessera;Nome;Cognome;Email\n11;Luca;Neri;luca@x.it", MemberType::Volunteer);
    expect($p[0]['action'])->toBe('create')->and($p[0]['type'])->toBe('volunteer')->and($p[0]['type_given'])->toBeFalse();
});

it('vuole un modo per riconoscere la persona', function (): void {
    expect(sheetPlan("Nome;Cognome;Email\nLuca;Neri;")[0]['action'])->toBe('error')
        ->and(sheetPlan("Nome;Cognome;Email\nLuca;Neri;nonvalida")[0]['action'])->toBe('error')
        ->and(sheetPlan("Nome;Cognome;Cellulare\nLuca;Neri;333 1234567", existing: [])[0]['action'])->toBe('create')
        ->and(sheetPlan("Tessera;Nome;Cognome\n30;Luca;Neri", existing: [])[0]['action'])->toBe('create')
        ->and(sheetPlan("Nome;Cognome;Cellulare\nLuca;Neri;12", existing: [])[0]['action'])->toBe('error');
});

it('riconosce chi c\'è già per cellulare se manca l\'email', function (): void {
    $existing = [['id' => 'p5', 'card' => null, 'first' => 'Luca', 'last' => 'Nerone', 'email' => null, 'tax' => null, 'phone' => '+39 333 1234567']];
    $p = sheetPlan("Nome;Cognome;Cellulare\nLuca;Neri;333-1234567", existing: $existing);
    expect($p[0]['action'])->toBe('update')->and($p[0]['matched_id'])->toBe('p5')
        ->and(array_column(sheetPlan("Nome;Cognome;Cellulare\nLuca;Neri;333 1111111\nPaola;Gialli;333 2222222", existing: []), 'action'))->toBe(['create', 'create']);
});

it('aggiorna chi c\'è già per tessera, email, codice fiscale o nome', function (): void {
    expect(sheetPlan("Tessera;Nome;Cognome;Email\n10;Mario;Rossi;mario@x.it")[0])->toMatchArray(['action' => 'update', 'matched_id' => 'p1'])
        ->and(sheetPlan("Nome;Cognome;Email\nAnnetta;Verdi;anna@x.it")[0]['matched_id'])->toBe('p2')
        ->and(sheetPlan("Nome;Cognome;Email\nAnna;Verdi;altra@x.it")[0]['matched_id'])->toBe('p2')
        ->and(sheetPlan("Nome;Cognome;Email;Codice fiscale\nM.;Rossi;nuova@x.it;rssmra80a01h501u")[0]['matched_id'])->toBe('p1');
});

it('rifiuta tessere altrui, doppioni nel file e identificativi in conflitto', function (): void {
    expect(sheetPlan("Tessera;Nome;Cognome;Email\n10;Luca;Neri;luca@x.it")[0]['action'])->toBe('error')
        ->and(array_column(sheetPlan("Tessera;Nome;Cognome;Email\n20;Luca;Neri;l@x.it\n20;Paola;Gialli;p@x.it\n21;Carlo;Blu;l@x.it"), 'action'))->toBe(['create', 'error', 'error'])
        ->and(sheetPlan("Tessera;Nome;Cognome;Email\n10;Mario;Rossi;anna@x.it")[0]['action'])->toBe('error')
        ->and(sheetPlan("Nome;Cognome;Email;Tipo\nLuca;Neri;l@x.it;boh")[0]['action'])->toBe('error');
});

it('trasforma le colonne con l\'anno in anni di iscrizione', function (): void {
    $r = PeopleSheet::parseTable([
        ['Nome', 'Cognome', 'N. Telefono', 'N° Tessera', '2024', '2025', '2026'],
        ['Anna', 'Rossi', '333 1234567', '7', 'X', 'x', ''],
        ['Luca', 'Bianchi', '', '8', '', 'sì', 'No'],
        ['Eva', 'Verdi', '', '9', '', '', ''],
    ])['rows'] ?? [];
    expect($r[0]['years'])->toBe(['2024', '2025'])->and($r[1]['years'])->toBe(['2025'])->and($r[2]['years'])->toBe([])
        ->and($r[0]['phone'])->toBe('333 1234567')->and($r[0]['card'])->toBe('7');

    foreach (['X', 'x', 'sì', '1', 'ok', '✓'] as $v) {
        expect(PeopleSheet::isMarked($v))->toBeTrue();
    }
    foreach ([null, '', '0', '-', 'no', 'No', 'n'] as $v) {
        expect(PeopleSheet::isMarked($v))->toBeFalse();
    }
});

it('legge anno corrente, anno scorso, data di ingresso e note', function (): void {
    $r = PeopleSheet::parseTable([
        ['Nome', 'Cognome', 'ANNO CORRENTE', 'ANNO SCORSO', 'Anno Iscrizione', 'Venute', 'Eventi passati'],
        ['Anna', 'Rossi', '', 'SI', '2017', '5', 'Cena 2024'],
        ['Eva', 'Verdi', 'SI', 'SI', '5/3/2021', '', ''],
    ])['rows'] ?? [];
    expect($r[0]['years'])->toBe(['@previous'])->and($r[1]['years'])->toBe(['@current', '@previous'])
        ->and($r[0]['since'])->toBe('2017-01-01')->and($r[1]['since'])->toBe('2021-03-05')
        ->and($r[0]['prior'])->toBe(5)->and($r[0]['notes'])->toBe('Cena 2024')->and($r[1]['notes'])->toBeNull()
        ->and(PeopleSheet::parseSince('2020-02-29'))->toBe('2020-02-29')
        ->and(PeopleSheet::parseSince('31/02/2021'))->toBeNull()
        ->and(PeopleSheet::parseCount('4 volte'))->toBe(4)->and(PeopleSheet::parseCount('boh'))->toBeNull();
});

it('trova l\'intestazione anche sotto un titolo', function (): void {
    $t = PeopleSheet::parseTable([['Elenco soci 2023'], ['Nome', 'Cognome', 'Email'], ['Mario', 'Rossi', 'm@example.com']], [1, 3, 4]);
    expect($t['rows'] ?? [])->toHaveCount(1)->and(($t['rows'] ?? [])[0]['line'])->toBe(4)
        ->and(PeopleSheet::acceptsHeader(['nome', 'cognome']))->toBeTrue()->and(PeopleSheet::acceptsHeader(['nome']))->toBeFalse();
});

it('legge data di nascita e tutore, e li controlla', function (): void {
    $rows = PeopleSheet::parseTable([
        ['Nome', 'Cognome', 'Email', 'Data di nascita', 'Tutore'],
        ['Luigi', 'Verdi', '', '01/02/2012', '15'],
        ['Anna', 'Neri', 'anna@example.com', '1980-05-06', ''],
    ])['rows'] ?? [];
    expect($rows[0]['birth'])->toBe('2012-02-01')->and($rows[0]['guardian'])->toBe('15')->and($rows[1]['guardian'])->toBeNull()
        ->and((PeopleSheet::parseTable([['Nome', 'Cognome', 'Nato il', 'Genitore'], ['Eva', 'Blu', '3.4.2015', 'madre@example.com']])['rows'] ?? [])[0]['birth'])->toBe('2015-04-03');

    expect(sheetPlan("Nome;Cognome;Email;Data di nascita\nEva;Blu;e@example.com;31/02/2015", existing: [])[0]['message'])->toContain('Data di nascita')
        ->and(sheetPlan("Nome;Cognome;Email;Tutore\nEva;Blu;e@example.com;15", existing: [])[0]['message'])->toContain('data di nascita')
        ->and(sheetPlan("Nome;Cognome;Email;Data di nascita;Tutore\nEva;Blu;e@example.com;03/04/2015;15", existing: [])[0]['action'])->toBe('create');
});

// Ospiti
function guestPlan(array $rows, array $existing = []): array
{
    $h = ['Tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Ospite di', 'Cellulare'];
    foreach ($rows as $i => $r) {
        if (! array_key_exists(6, $r)) {
            $rows[$i][6] = $r[1] === 'ospite' ? '333 1234567' : '';
        }
    }

    return PeopleSheet::plan(PeopleSheet::parseTable(array_merge([$h], $rows))['rows'] ?? [], $existing);
}

function guestMembers(): array
{
    return [
        ['id' => 'm7', 'card' => '1', 'first' => 'Mario', 'last' => 'Rossi', 'email' => 'mario@example.com', 'tax' => null, 'type' => 'ordinary', 'host_id' => null],
        ['id' => 'g9', 'card' => null, 'first' => 'Gino', 'last' => 'Bianchi', 'email' => null, 'tax' => null, 'type' => 'guest', 'host_id' => 'm7'],
    ];
}

it('collega l\'ospite all\'ospitante per tessera, email o nome', function (): void {
    $p = guestPlan([
        ['', 'ospite', 'Anna', 'Verdi', '', '1'],
        ['', 'ospite', 'Luca', 'Neri', 'luca@example.com', 'mario@example.com'],
        ['', 'ospite', 'Paola', 'Gialli', '', 'Rossi Mario'],
    ], guestMembers());
    foreach ($p as $x) {
        expect($x)->toMatchArray(['action' => 'create', 'type' => 'guest', 'host_id' => 'm7']);
    }
});

it('trova l\'ospitante anche nello stesso file', function (): void {
    $p = guestPlan([
        ['5', 'ordinario', 'Elena', 'Blu', 'elena@example.com', ''],
        ['', 'ospite', 'Piero', 'Rosa', '', '5'],
        ['', 'ospite', 'Sara', 'Viola', '', 'elena@example.com'],
    ]);
    expect(array_column($p, 'action'))->toBe(['create', 'create', 'create'])
        ->and($p[1]['host_id'])->toBeNull()->and($p[1]['host_text'])->toBe('5');
});

it('segnala gli errori degli ospiti', function (): void {
    $p = guestPlan([
        ['', 'ospite', 'Senza', 'Ospitante', '', ''],
        ['', 'ospite', 'Ospitante', 'Sconosciuto', '', '99'],
        ['', 'ospite', 'Email', 'Brutta', 'brutta', '1'],
        ['', 'ospite', 'Anna', 'Verdi', '', '1'],
        ['', 'ospite', 'Anna', 'Verdi', '', '1'],
        ['', 'ospite', 'Senza', 'Cellulare', '', '1', ''],
        ['', 'ospite', 'Cellulare', 'Giusto', '', '1', '+39 333 123 4567'],
    ], guestMembers());
    expect(array_column($p, 'action'))->toBe(['error', 'error', 'error', 'create', 'error', 'error', 'create'])
        ->and($p[0]['message'])->toContain('ospitante')->and($p[1]['message'])->toContain('non trovato')
        ->and($p[4]['message'])->toContain('già')->and($p[5]['message'])->toContain('ellulare');
});

it('aggiorna l\'ospite che c\'è già e non lo confonde con un socio', function (): void {
    expect(guestPlan([['', 'ospite', 'Gino', 'Bianchi', '', '1']], guestMembers())[0])->toMatchArray(['action' => 'update', 'matched_id' => 'g9'])
        ->and(guestPlan([['', 'ordinario', 'Gino', 'Bianchi', 'gino.b@example.com', '']], guestMembers())[0]['action'])->toBe('create');
});

it('riconosce i tipi scritti a parole', function (): void {
    expect(PeopleSheet::typeFromText('Socio e volontario'))->toBe(MemberType::Volunteer)
        ->and(PeopleSheet::typeFromText('FONDATRICE'))->toBe(MemberType::Founder)
        ->and(PeopleSheet::typeFromText('genitore'))->toBe(MemberType::Guardian)
        ->and(PeopleSheet::typeFromText('boh'))->toBeNull();
});

// Soci enti (denominazione, codice fiscale di 11 cifre con controllo o 16 caratteri, stessa sezione del RUNTS).
it('legge un ente: denominazione al posto di nome e cognome, sezione sì o no', function (): void {
    $r = sheetRows("Tipo soggetto;Denominazione;Codice fiscale;Stessa sezione RUNTS;Email\nente;Pro Loco Beta;00000000000;sì;info@beta.it\n");

    expect($r[0]['entity'])->toBeTrue()->and($r[0]['first'])->toBe('')->and($r[0]['last'])->toBe('Pro Loco Beta')
        ->and($r[0]['same_section'])->toBeTrue();
});

it('controlla il codice fiscale dell\'ente, la denominazione e gli ospiti', function (): void {
    expect(PeopleSheet::entityTaxValid('12345678903'))->toBeTrue()
        ->and(PeopleSheet::entityTaxValid('12345678900'))->toBeFalse()
        ->and(PeopleSheet::entityTaxValid('RSSMRA80A01H501U'))->toBeTrue()
        ->and(PeopleSheet::entityTaxValid('1234'))->toBeFalse();

    $plans = sheetPlan("Tipo soggetto;Denominazione;Codice fiscale;Stessa sezione RUNTS;Tipo;Email\n"
        ."ente;Pro Loco Beta;12345678903;sì;ordinario;info@beta.it\n"
        ."ente;;12345678903;;ordinario;a@beta.it\n"
        ."ente;Circolo Gamma;12345678900;;ordinario;b@beta.it\n"
        ."ente;Club Delta;12345678903;forse;ordinario;c@beta.it\n"
        ."ente;Ospite Srl;;;ospite;d@beta.it\n"
        ."boh;Altro;;;ordinario;e@beta.it\n");

    expect(array_column($plans, 'action'))->toBe(['create', 'error', 'error', 'error', 'error', 'error'])
        ->and($plans[1]['message'])->toContain('Denominazione')
        ->and($plans[2]['message'])->toContain('Codice fiscale dell\'ente non valido')
        ->and($plans[3]['message'])->toContain('Stessa sezione')
        ->and($plans[4]['message'])->toBe('Un ospite non può essere un ente')
        ->and($plans[5]['message'])->toContain('Tipo soggetto non riconosciuto');
});

it('non scambia una persona con un ente già in anagrafica', function (): void {
    $existing = [['id' => 'e1', 'card' => null, 'first' => '', 'last' => 'Pro Loco Beta', 'email' => null, 'tax' => '12345678903', 'type' => 'ordinary', 'entity' => true]];
    $plans = sheetPlan("Tipo soggetto;Denominazione;Nome;Cognome;Codice fiscale;Email\nente;Pro Loco Beta;;;12345678903;\npersona;;Mario;Rossi;12345678903;mario@x.it\n", MemberType::Ordinary, $existing);

    expect($plans[0]['action'])->toBe('update')->and($plans[0]['matched_id'])->toBe('e1')
        ->and($plans[1]['action'])->toBe('error')->and($plans[1]['message'])->toContain('è un ente, non una persona');
});

<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Soci\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

const CF = 'RSSMRA80A01H501U';

function personWithSensitiveData(string $orgId): Person
{
    return test()->orgContext()->run($orgId, fn () => Person::create([
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'tax_code' => CF,
        'birth_date' => '1980-01-01',
        'birth_place' => 'Roma',
        'address' => ['street' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma'],
        'phone' => '+39 333 1234567',
        'iban' => 'IT60X0542811101000000123456',
    ]));
}

it('salva i dati sensibili solo cifrati e li restituisce in chiaro', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $person = personWithSensitiveData($org->id);

    $raw = $this->orgContext()->run($org->id, fn () => DB::table('app.people')->where('id', $person->id)->first());
    $stored = implode('|', array_map(
        static fn (mixed $v): string => $v === null ? '' : (is_resource($v) ? Bytea::decode($v) : (string) $v),
        (array) $raw,
    ));

    foreach ([CF, 'Roma', 'Via Roma', '1980-01-01', '333 1234567', 'IT60X0542811101000000123456'] as $plain) {
        expect($stored)->not->toContain($plain);
    }

    $fresh = $this->orgContext()->run($org->id, fn () => Person::findOrFail($person->id));
    $this->orgContext()->run($org->id, function () use ($fresh): void {
        expect($fresh->tax_code)->toBe(CF)
            ->and($fresh->birth_date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($fresh->birth_date?->format('Y-m-d'))->toBe('1980-01-01')
            ->and($fresh->birth_place)->toBe('Roma')
            ->and($fresh->address)->toBe(['street' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma'])
            ->and($fresh->iban)->toBe('IT60X0542811101000000123456')
            ->and($fresh->toArray())->not->toHaveKeys(['tax_code_enc', 'tax_code_bidx']);
    });
});

it('cifra due volte lo stesso valore in modo diverso', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $a = personWithSensitiveData($org->id);

    $this->orgContext()->run($org->id, function () use ($a): void {
        $b = Person::create(['first_name' => 'Maria', 'last_name' => 'Rossi', 'phone' => '+39 333 1234567']);
        $rows = DB::table('app.people')->whereIn('id', [$a->id, $b->id])->pluck('phone_enc')->map(fn ($v) => Bytea::decode($v));

        expect($rows[0])->not->toBe($rows[1]);
    });
});

it('trova una persona per codice fiscale, scritto in qualunque modo', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $person = personWithSensitiveData($org->id);

    $this->orgContext()->run($org->id, function () use ($person): void {
        expect(Person::whereEncrypted('tax_code', 'rss mra 80a01 h501u')->sole()->id)->toBe($person->id)
            ->and(Person::whereEncrypted('tax_code', 'VRDLGU80A01H501X')->exists())->toBeFalse();
    });
});

it('usa impronte diverse per associazioni diverse e non trova i soci delle altre', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    personWithSensitiveData($alfa->id);
    personWithSensitiveData($beta->id);

    $indexA = $this->orgContext()->run($alfa->id, fn () => Bytea::decode(DB::table('app.people')->value('tax_code_bidx')));
    $indexB = $this->orgContext()->run($beta->id, fn () => Bytea::decode(DB::table('app.people')->value('tax_code_bidx')));

    expect($indexA)->not->toBe($indexB)
        ->and($this->orgContext()->run($alfa->id, fn () => Person::whereEncrypted('tax_code', CF)->count()))->toBe(1);
});

it('non ammette due schede con lo stesso codice fiscale nella stessa associazione', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    personWithSensitiveData($org->id);

    expect(fn () => $this->orgContext()->run($org->id, fn () => DB::transaction(
        fn () => Person::create(['first_name' => 'Altro', 'last_name' => 'Rossi', 'tax_code' => 'rssmra80a01h501u']),
    )))->toThrow(UniqueConstraintViolationException::class);
});

it('non apre un dato cifrato copiato da un\'altra associazione o da un altro campo', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    personWithSensitiveData($alfa->id);
    $victim = $this->orgContext()->run($beta->id, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Beta']));

    $stolen = $this->orgContext()->run($alfa->id, fn () => Bytea::decode(DB::table('app.people')->value('tax_code_enc')));

    $this->orgContext()->run($beta->id, function () use ($victim, $stolen): void {
        DB::table('app.people')->where('id', $victim->id)->update([
            'tax_code_enc' => Bytea::encode($stolen),
            'tax_code_bidx' => Bytea::encode(random_bytes(32)),
            'phone_enc' => Bytea::encode($stolen),
        ]);
        $copy = Person::findOrFail($victim->id);

        expect(fn () => $copy->tax_code)->toThrow(DecryptionFailed::class)
            ->and(fn () => $copy->phone)->toThrow(DecryptionFailed::class);
    });

    // Nell'associazione d'origine, un campo spostato in un altro non si apre.
    $this->orgContext()->run($alfa->id, function () use ($stolen): void {
        $person = Person::firstOrFail();
        DB::table('app.people')->where('id', $person->id)->update(['phone_enc' => Bytea::encode($stolen)]);

        expect(fn () => Person::firstOrFail()->phone)->toThrow(DecryptionFailed::class)
            ->and(Person::firstOrFail()->tax_code)->toBe(CF);
    });
});

it('salva le chiavi delle associazioni solo cifrate', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $rows = DB::table('core.org_keys')->where('org_id', $org->id)->get();

    expect($rows)->toHaveCount(1)
        ->and(strlen(Bytea::decode($rows[0]->wrapped_key)))->toBeGreaterThan(32);
});

it('non lascia al ruolo dell\'applicazione modificare o cancellare le chiavi', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    expect(fn () => DB::transaction(fn () => DB::table('core.org_keys')->where('org_id', $org->id)->delete()))
        ->toThrow(Illuminate\Database\QueryException::class, 'permission denied')
        ->and(fn () => DB::transaction(fn () => DB::table('core.org_keys')->where('org_id', $org->id)->update(['version' => 2])))
        ->toThrow(Illuminate\Database\QueryException::class, 'permission denied');
});

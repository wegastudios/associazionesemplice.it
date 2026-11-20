<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Support\Facades\DB;

// Dati di prova inventati.
function rowFmtByte(string $table, string $column, string $id): string
{
    return bin2hex(substr(Bytea::decode(DB::table($table)->where('id', $id)->value($column)), 0, 1));
}

function rowFmtImport(string $csv): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $csv);
    $s = app(PeopleImportService::class);
    $preview = $s->preview($path, 'soci.csv', MemberType::Ordinary);

    return $s->apply($preview['plan'] ?? [], false);
}

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('assegna l\'id della riga prima di salvare', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $person = new Person(['first_name' => 'Mario', 'last_name' => 'Rossi', 'phone' => '333 1234567']);
        $id = $person->getKey();
        expect($id)->toBeString()->not->toBe('');

        $person->save();
        expect($person->id)->toBe($id)->and(Person::query()->findOrFail($id)->phone)->toBe('333 1234567');

        $settings = new PaymentSettings(['stripe_secret' => 'sk_test_finta']);
        $settingsId = $settings->getKey();
        $settings->save();
        expect($settingsId)->toBeString()->and($settings->id)->toBe($settingsId);
    });
});

it('di default scrive ancora il formato storico 0x01', function (): void {
    expect(config('saas.crypto.row_bound_format'))->toBeFalse();

    $this->orgContext()->run($this->org->id, function (): void {
        $person = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'phone' => '333 1234567']);
        $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta']);

        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('01')
            ->and(rowFmtByte('app.people', 'tax_code_enc', $person->id))->toBe('01')
            ->and(rowFmtByte('app.payment_settings', 'stripe_secret_enc', $settings->id))->toBe('01')
            ->and(Person::query()->findOrFail($person->id)->phone)->toBe('333 1234567');
    });
});

it('con il formato legato alla riga acceso scrive 0x02 e rilegge', function (): void {
    config(['saas.crypto.row_bound_format' => true]);

    $this->orgContext()->run($this->org->id, function (): void {
        $person = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01', 'address' => ['text' => 'Via Roma 1'], 'phone' => '333 1234567']);
        $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta', 'paypal_secret' => 'segreto-finto']);
        $application = new Application(['first_name' => 'Anna', 'last_name' => 'Verdi', 'minor' => false, 'unconfirmed' => true, 'data' => ['tax_code' => 'VRDNNA85A41H501Z']]);
        $application->save();

        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02')
            ->and(rowFmtByte('app.people', 'tax_code_enc', $person->id))->toBe('02')
            ->and(rowFmtByte('app.payment_settings', 'paypal_secret_enc', $settings->id))->toBe('02')
            ->and(rowFmtByte('app.applications', 'data_enc', $application->id))->toBe('02');

        $fresh = Person::query()->findOrFail($person->id);
        expect($fresh->phone)->toBe('333 1234567')->and($fresh->tax_code)->toBe('RSSMRA80A01H501U')
            ->and($fresh->birth_date?->format('Y-m-d'))->toBe('1980-01-01')->and($fresh->address)->toBe(['text' => 'Via Roma 1'])
            ->and(Person::query()->whereEncrypted('tax_code', 'RSSMRA80A01H501U')->sole()->id)->toBe($person->id)
            ->and(PaymentSettings::query()->findOrFail($settings->id)->stripe_secret)->toBe('sk_test_finta')
            ->and(Application::query()->findOrFail($application->id)->data)->toBe(['tax_code' => 'VRDNNA85A41H501Z']);

        // Una modifica riscrive il campo nello stesso formato, con lo stesso id.
        $fresh->fill(['phone' => '339 7654321'])->save();
        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02')
            ->and(Person::query()->findOrFail($person->id)->phone)->toBe('339 7654321');
    });
});

it('non apre un campo 0x02 copiato su un\'altra riga', function (): void {
    config(['saas.crypto.row_bound_format' => true]);

    $this->orgContext()->run($this->org->id, function (): void {
        $a = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'iban' => 'IT60X0542811101000000123456']);
        $b = Person::create(['first_name' => 'Luigi', 'last_name' => 'Verdi', 'iban' => 'IT00A0000000000000000000000']);

        DB::table('app.people')->where('id', $b->id)->update(['iban_enc' => DB::table('app.people')->where('id', $a->id)->value('iban_enc')]);

        expect(fn () => Person::query()->findOrFail($b->id)->iban)->toThrow(DecryptionFailed::class)
            ->and(Person::query()->findOrFail($a->id)->iban)->toBe('IT60X0542811101000000123456');
    });
});

it('legge le righe vecchie 0x01 anche con il formato acceso', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $old = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'phone' => '333 1234567']);
        config(['saas.crypto.row_bound_format' => true]);

        expect(rowFmtByte('app.people', 'phone_enc', $old->id))->toBe('01')
            ->and(Person::query()->findOrFail($old->id)->phone)->toBe('333 1234567');
    });
});

it('l\'importazione dei soci cifra con l\'id della riga e si rilegge', function (): void {
    foreach ([false, true] as $bound) {
        config(['saas.crypto.row_bound_format' => $bound]);
        $suffix = $bound ? 'b' : 'a';

        $this->orgContext()->run($this->org->id, function () use ($bound, $suffix): void {
            $csv = "Tessera;Tipo;Nome;Cognome;Email;Cellulare;Codice fiscale;Data di nascita\n"
                .($bound ? '11' : '1').";ordinario;Mario;Rossi;mario{$suffix}@example.com;333 1234567;".($bound ? 'RSSMRA80A01H501U' : 'VRDLGU10A01H501X').";01/01/1980\n"
                .($bound ? '12' : '2').";ordinario;Anna;Verdi;anna{$suffix}@example.com;339 7654321;;\n";

            $r = rowFmtImport($csv);
            expect($r['created'])->toBe(2)->and($r['failed'])->toBe([]);

            $mario = Person::query()->where('card_number', $bound ? '11' : '1')->sole();
            expect(rowFmtByte('app.people', 'phone_enc', $mario->id))->toBe($bound ? '02' : '01')
                ->and(Person::query()->findOrFail($mario->id)->phone)->toBe('333 1234567')
                ->and(Person::query()->findOrFail($mario->id)->tax_code)->toBe($bound ? 'RSSMRA80A01H501U' : 'VRDLGU10A01H501X');
        });
    }
});

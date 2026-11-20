<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\PeopleImportService;
use App\Modules\Soci\Services\PeopleService;
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

    expect($preview['error'] ?? null)->toBeNull()
        ->and(array_column($preview['plan'] ?? [], 'message'))->toBe([null, null]);

    return $s->apply($preview['plan'] ?? [], false);
}

/** @return array<string, mixed> */
function rowFmtPersonData(): array
{
    return [
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01', 'birth_place' => 'Roma',
        'address' => ['text' => 'Via Roma 1'], 'phone' => '333 1234567', 'iban' => 'IT60X0542811101000000123456', 'notes' => 'Nota finta',
    ];
}

function rowFmtPersonCheck(string $id, string $format): void
{
    foreach (['tax_code', 'birth_date', 'birth_place', 'address', 'phone', 'iban', 'notes'] as $field) {
        expect(rowFmtByte('app.people', $field.'_enc', $id))->toBe($format, "campo {$field}");
    }
    $p = Person::query()->findOrFail($id);
    expect($p->tax_code)->toBe('RSSMRA80A01H501U')->and($p->birth_date?->format('Y-m-d'))->toBe('1980-01-01')->and($p->birth_place)->toBe('Roma')
        ->and($p->address)->toBe(['text' => 'Via Roma 1'])->and($p->phone)->toBe('333 1234567')->and($p->iban)->toBe('IT60X0542811101000000123456')
        ->and($p->notes)->toBe('Nota finta')
        ->and(Person::query()->whereEncrypted('tax_code', 'RSSMRA80A01H501U')->sole()->id)->toBe($id);
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

it('di default scrive 0x02 per i campi dei soci e dei pagamenti e li rilegge', function (): void {
    expect(config('saas.crypto.row_bound_format'))->toBeTrue();

    $this->orgContext()->run($this->org->id, function (): void {
        $person = Person::create(rowFmtPersonData());
        rowFmtPersonCheck($person->id, '02');

        $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta', 'stripe_webhook' => 'whsec_finto', 'paypal_secret' => 'segreto-finto']);
        foreach (['stripe_secret', 'stripe_webhook', 'paypal_secret'] as $field) {
            expect(rowFmtByte('app.payment_settings', $field.'_enc', $settings->id))->toBe('02', "campo {$field}");
        }
        $fresh = PaymentSettings::query()->findOrFail($settings->id);
        expect($fresh->stripe_secret)->toBe('sk_test_finta')->and($fresh->stripe_webhook)->toBe('whsec_finto')->and($fresh->paypal_secret)->toBe('segreto-finto');

        // Dopo una modifica restano 0x02 con lo stesso id e si rileggono.
        $fresh->fill(['stripe_secret' => 'sk_test_nuova'])->save();
        expect(rowFmtByte('app.payment_settings', 'stripe_secret_enc', $settings->id))->toBe('02')
            ->and(PaymentSettings::query()->findOrFail($settings->id)->stripe_secret)->toBe('sk_test_nuova')
            ->and(PaymentSettings::query()->findOrFail($settings->id)->paypal_secret)->toBe('segreto-finto');

        $person->fill(['phone' => '339 7654321', 'address' => ['text' => 'Via Milano 2']])->save();
        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02')
            ->and(Person::query()->findOrFail($person->id)->phone)->toBe('339 7654321')
            ->and(Person::query()->findOrFail($person->id)->address)->toBe(['text' => 'Via Milano 2'])
            ->and(Person::query()->findOrFail($person->id)->iban)->toBe('IT60X0542811101000000123456');
    });
});

it('con l\'interruttore spento scrive ancora 0x01', function (): void {
    config(['saas.crypto.row_bound_format' => false]);

    $this->orgContext()->run($this->org->id, function (): void {
        $person = Person::create(rowFmtPersonData());
        $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta']);

        rowFmtPersonCheck($person->id, '01');
        expect(rowFmtByte('app.payment_settings', 'stripe_secret_enc', $settings->id))->toBe('01')
            ->and(PaymentSettings::query()->findOrFail($settings->id)->stripe_secret)->toBe('sk_test_finta');
    });
});

it('non lega alla riga i campi degli altri modelli: restano 0x01', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $application = new Application(['first_name' => 'Anna', 'last_name' => 'Verdi', 'minor' => false, 'unconfirmed' => true, 'data' => ['tax_code' => 'VRDNNA85A41H501Z']]);
        $application->save();

        expect(rowFmtByte('app.applications', 'data_enc', $application->id))->toBe('01')
            ->and(Application::query()->findOrFail($application->id)->data)->toBe(['tax_code' => 'VRDNNA85A41H501Z']);
    });
});

it('una riga vecchia 0x01 passa a 0x02 al salvataggio del campo e si rilegge', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        config(['saas.crypto.row_bound_format' => false]);
        $person = Person::create(rowFmtPersonData());
        $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta', 'paypal_secret' => 'segreto-finto']);
        config(['saas.crypto.row_bound_format' => true]);

        $person = Person::query()->findOrFail($person->id);
        $person->fill(['phone' => '339 7654321'])->save();
        // Solo il campo toccato si riscrive; gli altri restano 0x01 e si aprono comunque.
        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02')
            ->and(rowFmtByte('app.people', 'iban_enc', $person->id))->toBe('01')
            ->and(Person::query()->findOrFail($person->id)->phone)->toBe('339 7654321')
            ->and(Person::query()->findOrFail($person->id)->iban)->toBe('IT60X0542811101000000123456')
            ->and(Person::query()->findOrFail($person->id)->tax_code)->toBe('RSSMRA80A01H501U');

        $settings = PaymentSettings::query()->findOrFail($settings->id);
        $settings->fill(['stripe_secret' => 'sk_test_nuova'])->save();
        expect(rowFmtByte('app.payment_settings', 'stripe_secret_enc', $settings->id))->toBe('02')
            ->and(rowFmtByte('app.payment_settings', 'paypal_secret_enc', $settings->id))->toBe('01')
            ->and(PaymentSettings::query()->findOrFail($settings->id)->stripe_secret)->toBe('sk_test_nuova')
            ->and(PaymentSettings::query()->findOrFail($settings->id)->paypal_secret)->toBe('segreto-finto');
    });
});

it('le schede create e aggiornate dal servizio dei soci sono 0x02 e si rileggono', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $service = app(PeopleService::class);
        $data = ['first_name' => 'Anna', 'last_name' => 'Verdi', 'type' => MemberType::Ordinary->value, 'phone' => '339 7654321'];
        $person = $service->create($data);
        expect(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02');

        $service->update($person->id, ['phone' => '338 1112233'] + $data);
        expect(Person::query()->findOrFail($person->id)->phone)->toBe('338 1112233')
            ->and(rowFmtByte('app.people', 'phone_enc', $person->id))->toBe('02');
    });
});

it('non apre un campo 0x02 copiato su un\'altra riga', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $a = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'iban' => 'IT60X0542811101000000123456']);
        $b = Person::create(['first_name' => 'Luigi', 'last_name' => 'Verdi', 'iban' => 'IT00A0000000000000000000000']);

        DB::table('app.people')->where('id', $b->id)->update(['iban_enc' => DB::table('app.people')->where('id', $a->id)->value('iban_enc')]);

        expect(fn () => Person::query()->findOrFail($b->id)->iban)->toThrow(DecryptionFailed::class)
            ->and(Person::query()->findOrFail($a->id)->iban)->toBe('IT60X0542811101000000123456');
    });
});

it('l\'importazione dei soci cifra con l\'id della riga e si rilegge', function (): void {
    foreach ([false, true] as $bound) {
        config(['saas.crypto.row_bound_format' => $bound]);
        $suffix = $bound ? 'Bis' : 'Uno';

        $this->orgContext()->run($this->org->id, function () use ($bound, $suffix): void {
            $csv = "Tessera;Tipo;Nome;Cognome;Email;Cellulare;Codice fiscale;Data di nascita\n"
                .($bound ? '11' : '1').";ordinario;Mario;Rossi{$suffix};mario{$suffix}@example.com;333 1234567;".($bound ? 'RSSMRA80A01H501U;01/01/1980' : ';01/01/1980')."\n"
                .($bound ? '12' : '2').";ordinario;Anna;Verdi{$suffix};anna{$suffix}@example.com;339 7654321;;06/05/1985\n";

            $r = rowFmtImport($csv);
            expect($r['created'])->toBe(2)->and($r['failed'])->toBe([]);

            $mario = Person::query()->where('card_number', $bound ? '11' : '1')->sole();
            expect(rowFmtByte('app.people', 'phone_enc', $mario->id))->toBe($bound ? '02' : '01')
                ->and(Person::query()->findOrFail($mario->id)->phone)->toBe('333 1234567')
                ->and(Person::query()->findOrFail($mario->id)->tax_code)->toBe($bound ? 'RSSMRA80A01H501U' : null);
        });
    }
});

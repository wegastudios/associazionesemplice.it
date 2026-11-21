<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Cifratura\Upgrade\FormatUpgrader;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Models\Person;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dati di prova inventati.
const FMT_UP_BACKUPS = 'app.crypt_format_backups';

/** Connessione dell'associazione corrente (condivisa o dedicata). */
function fmtUpDb(): ConnectionInterface
{
    return DB::connection(app(OrgContext::class)->require()->connection);
}

/** Esegue la funzione dentro l'associazione indicata. */
function fmtUpIn(string $orgId, callable $callback): mixed
{
    return app(OrgContext::class)->run($orgId, $callback);
}

/**
 * Soci e impostazioni di pagamento scritti nel formato vecchio (0x01), come le righe di prima del cambio.
 *
 * @return array{people: list<string>, settings: string}
 */
function fmtUpSeed(string $orgId, int $people = 2): array
{
    config(['saas.crypto.row_bound_format' => false]);

    try {
        /** @var array{people: list<string>, settings: string} */
        return fmtUpIn($orgId, static function () use ($people): array {
            $ids = [];
            for ($i = 1; $i <= $people; $i++) {
                $ids[] = Person::create([
                    'first_name' => 'Mario', 'last_name' => "Rossi{$i}", 'tax_code' => "RSSMRA80A01H50{$i}U", 'birth_date' => '1980-01-01',
                    'birth_place' => 'Roma', 'address' => ['text' => "Via Roma {$i}"], 'phone' => "333 123456{$i}",
                    'iban' => 'IT60X0542811101000000123456', 'notes' => "Nota finta {$i}",
                ])->id;
            }
            $settings = PaymentSettings::create(['stripe_secret' => 'sk_test_finta', 'stripe_webhook' => 'whsec_finto', 'paypal_secret' => 'pp_finto']);

            return ['people' => $ids, 'settings' => $settings->id];
        });
    } finally {
        config(['saas.crypto.row_bound_format' => true]);
    }
}

function fmtUpRaw(string $orgId, string $table, string $column, string $id): string
{
    return Bytea::decode(fmtUpIn($orgId, static fn (): mixed => fmtUpDb()->table($table)->where('id', $id)->value($column)));
}

function fmtUpFormat(string $orgId, string $table, string $column, string $id): string
{
    return bin2hex(substr(fmtUpRaw($orgId, $table, $column, $id), 0, 1));
}

/** @return list<string> */
function fmtUpPeopleColumns(): array
{
    return array_map(static fn (string $f): string => $f.'_enc', FormatUpgrader::FIELDS['app.people']);
}

function fmtUpPersonReads(string $orgId, string $id, int $i): void
{
    fmtUpIn($orgId, static function () use ($id, $i): void {
        $p = Person::query()->findOrFail($id);
        expect($p->tax_code)->toBe("RSSMRA80A01H50{$i}U")->and($p->birth_date?->format('Y-m-d'))->toBe('1980-01-01')
            ->and($p->birth_place)->toBe('Roma')->and($p->address)->toBe(['text' => "Via Roma {$i}"])->and($p->phone)->toBe("333 123456{$i}")
            ->and($p->iban)->toBe('IT60X0542811101000000123456')->and($p->notes)->toBe("Nota finta {$i}")
            ->and(Person::query()->whereEncrypted('tax_code', "RSSMRA80A01H50{$i}U")->sole()->id)->toBe($id);
    });
}

/** @return array<string, string> tutte le colonne cifrate e le impronte, per riga */
function fmtUpSnapshot(string $orgId): array
{
    return fmtUpIn($orgId, static function (): array {
        $out = [];
        foreach (fmtUpDb()->table('app.people')->get() as $row) {
            foreach ([...fmtUpPeopleColumns(), 'tax_code_bidx'] as $column) {
                $out["people.{$row->id}.{$column}"] = $row->{$column} === null ? '' : bin2hex(Bytea::decode($row->{$column}));
            }
        }
        foreach (fmtUpDb()->table('app.payment_settings')->get() as $row) {
            foreach (['stripe_secret_enc', 'stripe_webhook_enc', 'paypal_secret_enc'] as $column) {
                $out["settings.{$row->id}.{$column}"] = bin2hex(Bytea::decode($row->{$column}));
            }
        }

        return $out;
    });
}

function fmtUpCall(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

function fmtUpAuditRows(string $orgId, string $action): array
{
    /** @var list<object> $rows */
    $rows = fmtUpIn($orgId, static fn (): array => fmtUpDb()->table('audit.log')->where('action', $action)->get(['details'])->all());

    return array_map(static fn (object $r): array => json_decode((string) $r->details, true), $rows);
}

function fmtUpBackupCount(string $orgId): int
{
    return fmtUpIn($orgId, static fn (): int => fmtUpDb()->table(FMT_UP_BACKUPS)->count());
}

beforeEach(function (): void {
    $this->alfa = $this->createOrg('Polisportiva Alfa');
    $this->beta = $this->createOrg('Polisportiva Beta');
});

it('elenca gli stessi campi che i modelli scrivono legati alla riga', function (): void {
    $found = fmtUpIn($this->alfa->id, static function (): array {
        $out = [];
        foreach ([Person::class, PaymentSettings::class] as $class) {
            $model = new $class;
            $fields = [];
            foreach ($model->getCasts() as $key => $cast) {
                if (is_string($cast) && str_starts_with($cast, Encrypted::class.':') && in_array('bound', explode(',', substr($cast, strlen(Encrypted::class) + 1)), true)) {
                    $fields[] = $key;
                }
            }
            $out[$model->getTable()] = $fields;
        }

        return $out;
    });

    foreach (FormatUpgrader::FIELDS as $table => $fields) {
        expect($found[$table])->toEqualCanonicalizing($fields);
    }
    expect(array_keys($found))->toEqualCanonicalizing(array_keys(FormatUpgrader::FIELDS));
});

it('porta i campi a 0x02, li rilegge identici e non cambia le impronte', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    $before = fmtUpSnapshot($this->alfa->id);
    foreach ($seed['people'] as $id) {
        foreach (fmtUpPeopleColumns() as $column) {
            expect(fmtUpFormat($this->alfa->id, 'app.people', $column, $id))->toBe('01');
        }
    }

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output);
    foreach ($seed['people'] as $i => $id) {
        foreach (fmtUpPeopleColumns() as $column) {
            expect(fmtUpFormat($this->alfa->id, 'app.people', $column, $id))->toBe('02', $column);
        }
        fmtUpPersonReads($this->alfa->id, $id, $i + 1);
    }
    foreach (['stripe_secret_enc', 'stripe_webhook_enc', 'paypal_secret_enc'] as $column) {
        expect(fmtUpFormat($this->alfa->id, 'app.payment_settings', $column, $seed['settings']))->toBe('02');
    }
    fmtUpIn($this->alfa->id, function () use ($seed): void {
        $settings = PaymentSettings::query()->findOrFail($seed['settings']);
        expect($settings->stripe_secret)->toBe('sk_test_finta')->and($settings->stripe_webhook)->toBe('whsec_finto')->and($settings->paypal_secret)->toBe('pp_finto');
    });

    $after = fmtUpSnapshot($this->alfa->id);
    foreach ($before as $key => $value) {
        if (str_ends_with($key, '_bidx')) {
            expect($after[$key])->toBe($value, 'impronta cambiata');
        } else {
            expect($after[$key])->not->toBe($value);
        }
    }
});

it('salva in appoggio il vecchio testo cifrato, senza dati in chiaro, e scrive nel registro', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    $oldIban = fmtUpRaw($this->alfa->id, 'app.people', 'iban_enc', $seed['people'][0]);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    expect($code)->toBe(0, $output);

    // 2 soci x 7 campi + 3 segreti.
    expect(fmtUpBackupCount($this->alfa->id))->toBe(17);
    $backup = fmtUpIn($this->alfa->id, static fn (): object => fmtUpDb()->table(FMT_UP_BACKUPS)->where('row_id', $seed['people'][0])->where('column_name', 'iban_enc')->first());
    $newIban = fmtUpRaw($this->alfa->id, 'app.people', 'iban_enc', $seed['people'][0]);
    expect($backup->org_id)->toBe($this->alfa->id)->and($backup->table_name)->toBe('app.people')
        ->and(Bytea::decode($backup->old_value))->toBe($oldIban)
        ->and(Bytea::decode($backup->new_hash))->toBe(hash('sha256', $newIban, true))
        ->and($backup->created_at)->not->toBeNull();

    // Nessun valore in chiaro in appoggio né nell'output.
    $dump = json_encode(fmtUpIn($this->alfa->id, static fn (): array => fmtUpDb()->table(FMT_UP_BACKUPS)->get()->map(static fn (object $r): string => bin2hex(Bytea::decode($r->old_value)))->all()));
    expect($dump)->not->toContain(bin2hex('IT60X0542811101000000123456'));
    foreach (['IT60X', 'Rossi', 'sk_test_finta', 'Via Roma', bin2hex($oldIban), bin2hex(substr($oldIban, 5, 24))] as $secret) {
        expect($output)->not->toContain($secret);
    }

    $rows = fmtUpAuditRows($this->alfa->id, 'crypto.format_upgraded');
    expect($rows)->toHaveCount(1)->and($rows[0])->toEqual(['fields_converted' => 17, 'rows_already' => 0, 'rows_converted' => 3, 'rows_unreadable' => 0]);
    expect(DB::table('core.platform_audit')->where('action', 'crypto.format_upgrade')->count())->toBe(1);
    expect(fmtUpAuditRows($this->beta->id, 'crypto.format_upgraded'))->toBe([]);
});

it('è ripetibile: le righe già 0x02 si saltano e non si fanno altre copie', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    $afterFirst = fmtUpSnapshot($this->alfa->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and(fmtUpSnapshot($this->alfa->id))->toBe($afterFirst)->and(fmtUpBackupCount($this->alfa->id))->toBe(17);
    $rows = fmtUpAuditRows($this->alfa->id, 'crypto.format_upgraded');
    expect($rows)->toHaveCount(2)->and($rows[1]['rows_converted'])->toBe(0)->and($rows[1]['rows_already'])->toBe(3);
    fmtUpPersonReads($this->alfa->id, $seed['people'][0], 1);
});

it('converte anche solo i campi rimasti 0x01 di una riga mista', function (): void {
    $seed = fmtUpSeed($this->alfa->id, 1);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    // Il socio viene modificato (0x02) e un campo torna 0x01 (come con l'interruttore spento).
    config(['saas.crypto.row_bound_format' => false]);
    fmtUpIn($this->alfa->id, static function () use ($seed): void {
        $p = Person::query()->findOrFail($seed['people'][0]);
        $p->phone = '333 9999999';
        $p->save();
    });
    config(['saas.crypto.row_bound_format' => true]);
    expect(fmtUpFormat($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][0]))->toBe('01')
        ->and(fmtUpFormat($this->alfa->id, 'app.people', 'iban_enc', $seed['people'][0]))->toBe('02');

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and(fmtUpFormat($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][0]))->toBe('02');
    fmtUpIn($this->alfa->id, fn () => expect(Person::query()->findOrFail($seed['people'][0])->phone)->toBe('333 9999999'));
    // Il vecchio testo della copia d'appoggio è quello più recente: una sola copia per campo.
    expect(fmtUpBackupCount($this->alfa->id))->toBe(10);
});

it('con --dry-run non scrive nulla e stampa solo conteggi', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    fmtUpSeed($this->beta->id, 1);
    $beforeAlfa = fmtUpSnapshot($this->alfa->id);
    $beforeBeta = fmtUpSnapshot($this->beta->id);
    $platformBefore = DB::table('core.platform_audit')->count();

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--dry-run' => true]);

    expect($code)->toBe(0, $output)
        ->and(fmtUpSnapshot($this->alfa->id))->toBe($beforeAlfa)->and(fmtUpSnapshot($this->beta->id))->toBe($beforeBeta)
        ->and(fmtUpBackupCount($this->alfa->id))->toBe(0)->and(fmtUpBackupCount($this->beta->id))->toBe(0)
        ->and(fmtUpAuditRows($this->alfa->id, 'crypto.format_upgraded'))->toBe([])
        ->and(DB::table('core.platform_audit')->count())->toBe($platformBefore)
        ->and($output)->toContain($this->alfa->id)->toContain('da convertire 2 (campi 14)')->toContain('da convertire 1 (campi 3)')
        ->toContain('da salvare in appoggio: 17 campi')->toContain('già 0x02 0')->toContain('che non si decifrano 0');
    foreach (['IT60X', 'Rossi', 'sk_test_finta', 'Via Roma', '333 123'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    unset($seed);
});

it('con --dry-run conta le righe già 0x02 e quelle che non si decifrano ed esce con errore', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    fmtUpIn($this->alfa->id, static fn () => fmtUpDb()->table('app.people')->where('id', $seed['people'][1])->update(['iban_enc' => Bytea::encode("\x01".random_bytes(60))]));
    $before = fmtUpSnapshot($this->alfa->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id, '--dry-run' => true]);

    expect($code)->not->toBe(0)->and(fmtUpSnapshot($this->alfa->id))->toBe($before)
        ->and($output)->toContain('che non si decifrano 1')->toContain("app.people {$seed['people'][1]} iban_enc");
});

it('annulla una associazione con un campo illeggibile senza toccare le altre, ed esce con errore', function (): void {
    $alfa = fmtUpSeed($this->alfa->id);
    $beta = fmtUpSeed($this->beta->id, 1);
    $goodIban = fmtUpRaw($this->alfa->id, 'app.people', 'iban_enc', $alfa['people'][1]);
    fmtUpIn($this->alfa->id, static fn () => fmtUpDb()->table('app.people')->where('id', $alfa['people'][1])->update(['iban_enc' => Bytea::encode("\x01".random_bytes(60))]));
    $alfaBefore = fmtUpSnapshot($this->alfa->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format');

    expect($code)->not->toBe(0)
        // Alfa: niente cambiato, nessuna copia d'appoggio, solo la riga di errore nel registro.
        ->and(fmtUpSnapshot($this->alfa->id))->toBe($alfaBefore)->and(fmtUpBackupCount($this->alfa->id))->toBe(0)
        ->and(fmtUpAuditRows($this->alfa->id, 'crypto.format_upgraded'))->toBe([])
        ->and(fmtUpAuditRows($this->alfa->id, 'crypto.format_failed'))->toHaveCount(1)
        // Beta: passata.
        ->and(fmtUpFormat($this->beta->id, 'app.people', 'iban_enc', $beta['people'][0]))->toBe('02')
        ->and(fmtUpBackupCount($this->beta->id))->toBe(10)
        ->and(fmtUpAuditRows($this->beta->id, 'crypto.format_upgraded'))->toHaveCount(1)
        ->and($output)->toContain("app.people {$alfa['people'][1]} iban_enc")->toContain('associazioni con problemi 1');
    foreach (['IT60X', 'Rossi', 'sk_test_finta', 'Via Roma'] as $secret) {
        expect($output)->not->toContain($secret);
    }

    // Sistemata la riga (qui: rimessa a un testo valido), il comando ripetuto porta a termine anche Alfa.
    fmtUpIn($this->alfa->id, static fn () => fmtUpDb()->table('app.people')->where('id', $alfa['people'][1])->update(['iban_enc' => Bytea::encode($goodIban)]));
    [$code] = fmtUpCall('saas:crypt:upgrade-format');
    expect($code)->toBe(0)->and(fmtUpFormat($this->alfa->id, 'app.people', 'phone_enc', $alfa['people'][1]))->toBe('02');
});

it('rifiuta una --org non valida o inesistente', function (): void {
    expect(fmtUpCall('saas:crypt:upgrade-format', ['--org' => 'non-un-uuid'])[0])->not->toBe(0)
        ->and(fmtUpCall('saas:crypt:upgrade-format', ['--org' => '018f0000-0000-7000-8000-000000000000'])[0])->not->toBe(0);
});

it('lavora solo sull\'associazione indicata con --org e le copie restano nella sua', function (): void {
    fmtUpSeed($this->alfa->id, 1);
    $beta = fmtUpSeed($this->beta->id, 1);
    $betaBefore = fmtUpSnapshot($this->beta->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and(fmtUpSnapshot($this->beta->id))->toBe($betaBefore)
        ->and(fmtUpBackupCount($this->alfa->id))->toBe(10)->and(fmtUpBackupCount($this->beta->id))->toBe(0)
        ->and($output)->not->toContain($this->beta->id);
    // Dentro Alfa le copie di Beta non si vedono (RLS), e viceversa.
    fmtUpIn($this->alfa->id, fn () => expect(fmtUpDb()->table(FMT_UP_BACKUPS)->where('org_id', $this->beta->id)->count())->toBe(0));
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->beta->id]);
    expect(fmtUpIn($this->beta->id, static fn (): array => fmtUpDb()->table(FMT_UP_BACKUPS)->distinct()->pluck('org_id')->all()))->toBe([$this->beta->id]);
    unset($beta);
});

it('non scrive una copia d\'appoggio in un\'altra associazione nemmeno cercando di farlo', function (): void {
    fmtUpIn($this->alfa->id, function (): void {
        // In un punto di salvataggio: l'errore del database non deve far abortire la transazione del test.
        expect(fn () => fmtUpDb()->transaction(fn () => fmtUpDb()->table(FMT_UP_BACKUPS)->insert([
            'org_id' => $this->beta->id, 'id' => (string) Str::uuid7(), 'table_name' => 'app.people', 'row_id' => (string) Str::uuid7(),
            'column_name' => 'iban_enc', 'old_value' => Bytea::encode("\x01"), 'new_hash' => Bytea::encode(str_repeat('a', 32)),
        ])))->toThrow(QueryException::class);
    });
});

it('rimette i vecchi testi 0x01 con il comando inverso', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    $original = fmtUpSnapshot($this->alfa->id);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:undo', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and(fmtUpSnapshot($this->alfa->id))->toBe($original)->and(fmtUpBackupCount($this->alfa->id))->toBe(0)
        ->and($output)->toContain('campi da rimettere 17, saltati (cambiati dopo) 0');
    fmtUpPersonReads($this->alfa->id, $seed['people'][0], 1);
    $rows = fmtUpAuditRows($this->alfa->id, 'crypto.format_restored');
    expect($rows)->toEqual([['fields_restored' => 17, 'fields_skipped' => 0]])
        ->and(DB::table('core.platform_audit')->where('action', 'crypto.format_undo')->count())->toBe(1);
});

it('con --dry-run il comando inverso non scrive nulla', function (): void {
    fmtUpSeed($this->alfa->id);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    $upgraded = fmtUpSnapshot($this->alfa->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:undo', ['--org' => $this->alfa->id, '--dry-run' => true]);

    expect($code)->toBe(0, $output)->and(fmtUpSnapshot($this->alfa->id))->toBe($upgraded)->and(fmtUpBackupCount($this->alfa->id))->toBe(17)
        ->and(fmtUpAuditRows($this->alfa->id, 'crypto.format_restored'))->toBe([])
        ->and($output)->toContain('campi da rimettere 17');
});

it('il comando inverso salta le righe cambiate dopo il passaggio e lo dice', function (): void {
    $seed = fmtUpSeed($this->alfa->id);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    $phoneBefore = fmtUpRaw($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][0]);
    fmtUpIn($this->alfa->id, static function () use ($seed): void {
        $p = Person::query()->findOrFail($seed['people'][0]);
        $p->phone = '333 0000000';
        $p->save();
    });
    $phoneChanged = fmtUpRaw($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][0]);
    expect($phoneChanged)->not->toBe($phoneBefore);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:undo', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)
        // Il campo cambiato resta com'è (0x02, nuovo valore); gli altri tornano 0x01.
        ->and(fmtUpRaw($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][0]))->toBe($phoneChanged)
        ->and(fmtUpFormat($this->alfa->id, 'app.people', 'iban_enc', $seed['people'][0]))->toBe('01')
        ->and(fmtUpFormat($this->alfa->id, 'app.people', 'phone_enc', $seed['people'][1]))->toBe('01')
        ->and($output)->toContain('campi da rimettere 16, saltati (cambiati dopo) 1')
        ->and(fmtUpBackupCount($this->alfa->id))->toBe(1);
    fmtUpIn($this->alfa->id, fn () => expect(Person::query()->findOrFail($seed['people'][0])->phone)->toBe('333 0000000'));
    fmtUpPersonReads($this->alfa->id, $seed['people'][1], 2);
});

it('il comando inverso salta anche il campo svuotato dopo il passaggio', function (): void {
    $seed = fmtUpSeed($this->alfa->id, 1);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    fmtUpIn($this->alfa->id, static fn () => fmtUpDb()->table('app.people')->where('id', $seed['people'][0])->update(['iban_enc' => null]));

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:undo', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and($output)->toContain('saltati (cambiati dopo) 1');
});

it('toglie le copie d\'appoggio dopo 30 giorni e lo scrive nel registro', function (): void {
    fmtUpSeed($this->alfa->id);
    fmtUpSeed($this->beta->id, 1);
    fmtUpCall('saas:crypt:upgrade-format');
    // Due copie vecchie di 31 giorni (iban dei soci di Alfa), una di 29 (codice fiscale), una vecchia di Beta.
    fmtUpIn($this->alfa->id, static function (): void {
        fmtUpDb()->table(FMT_UP_BACKUPS)->where('column_name', 'iban_enc')->where('table_name', 'app.people')->update(['created_at' => now()->subDays(31)]);
        fmtUpDb()->table(FMT_UP_BACKUPS)->where('column_name', 'tax_code_enc')->update(['created_at' => now()->subDays(29)]);
    });
    fmtUpIn($this->beta->id, static fn () => fmtUpDb()->table(FMT_UP_BACKUPS)->where('column_name', 'iban_enc')->update(['created_at' => now()->subDays(40)]));
    expect(fmtUpBackupCount($this->alfa->id))->toBe(17)->and(fmtUpBackupCount($this->beta->id))->toBe(7);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:prune', ['--dry-run' => true]);
    expect($code)->toBe(0)->and($output)->toContain('Da togliere')->toContain(': 3')
        ->and(fmtUpBackupCount($this->alfa->id))->toBe(17);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:prune');

    expect($code)->toBe(0, $output)->and($output)->toContain('Tolte')->toContain(': 3')
        ->and(fmtUpBackupCount($this->alfa->id))->toBe(15)->and(fmtUpBackupCount($this->beta->id))->toBe(6)
        ->and(fmtUpAuditRows($this->alfa->id, 'crypto.format_pruned'))->toEqual([['days' => 30, 'fields_removed' => 2]])
        ->and(fmtUpAuditRows($this->beta->id, 'crypto.format_pruned'))->toEqual([['days' => 30, 'fields_removed' => 1]])
        ->and(DB::table('core.platform_audit')->where('action', 'crypto.format_prune')->count())->toBe(1);

    // Senza altro da togliere non scrive altre righe.
    fmtUpCall('saas:crypt:upgrade-format:prune');
    expect(fmtUpAuditRows($this->alfa->id, 'crypto.format_pruned'))->toHaveCount(1)
        ->and(DB::table('core.platform_audit')->where('action', 'crypto.format_prune')->count())->toBe(1);
});

it('dopo la pulizia il comando inverso non ha più nulla da rimettere', function (): void {
    fmtUpSeed($this->alfa->id, 1);
    fmtUpCall('saas:crypt:upgrade-format', ['--org' => $this->alfa->id]);
    fmtUpIn($this->alfa->id, static fn () => fmtUpDb()->table(FMT_UP_BACKUPS)->update(['created_at' => now()->subDays(31)]));
    fmtUpCall('saas:crypt:upgrade-format:prune');
    $upgraded = fmtUpSnapshot($this->alfa->id);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format:undo', ['--org' => $this->alfa->id]);

    expect($code)->toBe(0, $output)->and($output)->toContain('campi da rimettere 0')->and(fmtUpSnapshot($this->alfa->id))->toBe($upgraded);
});

it('mette la pulizia delle copie d\'appoggio nello scheduler, ogni giorno', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => str_contains((string) $e->command, 'saas:crypt:upgrade-format:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('7 4 * * *');
});

it('funziona anche con il database dedicato di un\'associazione', function (): void {
    $this->useDedicatedDatabase();
    $org = $this->createOrg('Polisportiva Dedicata', self::DEDICATED);
    $seed = fmtUpSeed($org->id, 1);

    [$code, $output] = fmtUpCall('saas:crypt:upgrade-format', ['--org' => $org->id]);

    expect($code)->toBe(0, $output)->and(fmtUpFormat($org->id, 'app.people', 'iban_enc', $seed['people'][0]))->toBe('02')
        ->and(fmtUpBackupCount($org->id))->toBe(10);
    fmtUpPersonReads($org->id, $seed['people'][0], 1);
});

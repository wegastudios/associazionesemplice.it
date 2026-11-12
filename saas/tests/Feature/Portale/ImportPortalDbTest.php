<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Importazione dei dati durevoli del portale nello schema `portal` (docs/MIGRAZIONE_DB_PORTALE.md). Il «portale» è un file
 * SQLite di prova con dati inventati. Lo schema `portal` non è dell'applicazione: si scrive e si legge con il ruolo
 * proprietario, fuori dalla transazione dei test, e si ripulisce alla fine.
 */

function portalDbFixture(): string
{
    $file = (string) tempnam(sys_get_temp_dir(), 'portale-db-');
    $pdo = new PDO('sqlite:'.$file);
    $t = '2026-11-01 10:00:00';
    $pdo->exec('CREATE TABLE subscriptions (id TEXT PRIMARY KEY, customer_id TEXT, plan_code TEXT, period TEXT, provider TEXT, status TEXT, domain TEXT, license_key_enc TEXT, expires_at TEXT, cancel_at_period_end INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO subscriptions VALUES ('s1','c1','full','manual','manual','active','esempio.test','cifrato','2027-01-01 00:00:00',0,'$t','$t')");
    $pdo->exec('CREATE TABLE orders (id TEXT PRIMARY KEY)');
    $pdo->exec('CREATE TABLE saas_orgs (ref TEXT PRIMARY KEY, customer_id TEXT, name TEXT, slug TEXT, rev INTEGER, created_at TEXT)');
    $pdo->exec("INSERT INTO saas_orgs VALUES ('o1','c1','Polisportiva','polisportiva',0,'$t')");
    $pdo->exec('CREATE TABLE saas_outbox (id TEXT PRIMARY KEY)');
    $pdo->exec('CREATE TABLE releases (product TEXT, version TEXT, size INTEGER, sha256 TEXT, created_at TEXT, PRIMARY KEY (product, version))');
    $pdo->exec("INSERT INTO releases VALUES ('free','1.3.11',100,'abc','$t')");
    $pdo->exec('CREATE TABLE downloads (id TEXT PRIMARY KEY, customer_id TEXT, at TEXT, product TEXT, version TEXT)');
    $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, val TEXT)');
    $pdo->exec("INSERT INTO settings VALUES ('general','{\"a\":1}')");
    $pdo->exec('CREATE TABLE email_templates (event TEXT PRIMARY KEY, subject TEXT, body TEXT, enabled INTEGER, updated_at TEXT)');
    $pdo->exec("INSERT INTO email_templates VALUES ('benvenuto','Ciao','Testo',1,'$t')");
    $pdo->exec('CREATE TABLE audit (id TEXT PRIMARY KEY, at TEXT, actor TEXT, action TEXT, detail TEXT)');
    $pdo->exec("INSERT INTO audit VALUES ('a1','$t','x@example.test','login',NULL)");
    $pdo->exec('CREATE TABLE events (provider TEXT, event_id TEXT, processed_at TEXT, PRIMARY KEY (provider, event_id))');
    $pdo->exec("INSERT INTO events VALUES ('stripe','evt_1','$t')");

    return $file;
}

function portalSchemaClean(): void
{
    DB::connection('pgsql_owner')->statement('TRUNCATE portal.subscriptions, portal.orders, portal.saas_orgs, portal.saas_outbox, portal.releases, portal.downloads, portal.settings, portal.email_templates, portal.audit, portal.events');
}

it('importa i dati durevoli, ed è ripetibile e verificabile', function (): void {
    // Le scritture del comando usano il ruolo proprietario: si chiude la transazione dei test per vederle.
    DB::commit();
    try {
        $source = portalDbFixture();
        $this->artisan('saas:portal:import-db', ['source' => $source, '--dry-run' => true])->expectsOutputToContain('Prova a vuoto')->assertSuccessful();
        expect(DB::connection('pgsql_owner')->table('portal.subscriptions')->count())->toBe(0);

        $this->artisan('saas:portal:import-db', ['source' => $source])->expectsOutputToContain('Importazione finita')->assertSuccessful();
        $this->artisan('saas:portal:import-db', ['source' => $source])->assertSuccessful();

        $owner = DB::connection('pgsql_owner');
        $sub = $owner->table('portal.subscriptions')->where('id', 's1')->first();
        expect($owner->table('portal.subscriptions')->count())->toBe(1)
            ->and($sub->cancel_at_period_end)->toBe(0)->and($sub->created_at)->toBe('2026-11-01 10:00:00')
            ->and($owner->table('portal.settings')->where('name', 'general')->value('val'))->toBe('{"a":1}')
            ->and($owner->table('portal.email_templates')->where('event', 'benvenuto')->value('enabled'))->toBe(1)
            ->and($owner->table('portal.events')->count())->toBe(1);

        $this->artisan('saas:portal:import-db', ['source' => $source, '--verify' => true])->expectsOutputToContain('Verifica riuscita')->assertSuccessful();

        // Una riga in più nel portale: la verifica lo dice.
        (new PDO('sqlite:'.$source))->exec("INSERT INTO settings VALUES ('altra','x')");
        $this->artisan('saas:portal:import-db', ['source' => $source, '--verify' => true])->expectsOutputToContain('NON riuscita')->assertFailed();
    } finally {
        portalSchemaClean();
        DB::beginTransaction();
    }
});

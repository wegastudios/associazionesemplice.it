<?php

declare(strict_types=1);

/*
 * H20, condizione di Audit: lo stato di un'associazione (core.orgs.status) e i suoi diritti (core.org_entitlements) si
 * scrivono solo da chi poi dimentica la copia in cache (OrgDirectory::setStatus, PortalSync, con l'evento
 * OrgAccessChanged). Chiunque altro li scriva fa fallire questa prova: o passa da lì, o finisce nell'elenco qui sotto
 * con la ragione.
 */

/** File ammessi, con la ragione. */
const ACCESS_WRITERS = [
    'app/Modules/Portale/Entitlements/PortalSync.php' => 'scrive i diritti del portale e chiama EntitlementReader::forget',
    'app/Modules/Core/Org/DatabaseOrgDirectory.php' => 'setStatus: emette OrgAccessChanged',
    'app/Modules/Core/Org/DatabaseOrgPurger.php' => 'chiusura dopo la cancellazione: emette OrgAccessChanged',
    'app/Modules/Core/Demo/DemoEnvironment.php' => 'pacchetti della demo: emette OrgAccessChanged',
    'app/Modules/Core/Org/OrgProvisioner.php' => 'nascita dell\'associazione: non c\'è ancora nessuna copia',
    'app/Modules/Core/Console/SelfTestPurgeCommand.php' => 'associazione di prova cancellata con setStatus prima',
];

/** Le istruzioni di un sorgente PHP che scrivono stato o diritti (per istruzione, fino al «;»). */
function accessWrites(string $source): array
{
    $found = [];
    foreach (explode(';', $source) as $statement) {
        $entitlements = preg_match('/core\.org_entitlements\b/', $statement) === 1
            && preg_match('/->\s*(insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|truncate)\s*\(/', $statement) === 1;
        $orgStatus = preg_match('/core\.orgs[\'"\s]/', $statement) === 1
            && preg_match('/->\s*(insert|upsert|update|updateOrInsert)\s*\(/', $statement) === 1
            && preg_match('/[\'"]status[\'"]/', $statement) === 1;
        $raw = preg_match('/\b(UPDATE|INSERT\s+INTO|DELETE\s+FROM|TRUNCATE)\s+"?core"?\."?(orgs|org_entitlements)\b/i', $statement) === 1;
        $model = preg_match('/\bOrg::\S*(update|upsert|insert)\s*\(/', $statement) === 1 && str_contains($statement, "'status'");
        if ($entitlements || $orgStatus || $raw || $model) {
            $found[] = trim(mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160));
        }
    }

    return $found;
}

it('riconosce le scritture su stato e diritti (la prova non è vuota)', function (): void {
    expect(accessWrites("DB::table('core.orgs')->where('id', \$id)->update(['status' => 'active']);"))->toHaveCount(1)
        ->and(accessWrites("\$db->table('core.org_entitlements')->upsert([[]], ['org_id']);"))->toHaveCount(1)
        ->and(accessWrites("DB::statement('UPDATE core.orgs SET status = ?', ['suspended']);"))->toHaveCount(1)
        ->and(accessWrites("DB::table('core.orgs')->where('id', \$id)->update(['portal_ref' => 'x']);"))->toHaveCount(0)
        ->and(accessWrites("DB::table('core.org_entitlements')->where('org_id', \$id)->first();"))->toHaveCount(0);
});

it('scrive stato e diritti delle associazioni solo da setStatus, PortalSync e gli altri punti ammessi', function (): void {
    $base = dirname(__DIR__, 2);
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/app", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Migrations/')) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        if (isset(ACCESS_WRITERS[$relative])) {
            continue;
        }
        foreach (accessWrites((string) file_get_contents($file->getPathname())) as $write) {
            $violations[] = "{$relative}: {$write}";
        }
    }

    expect($violations)->toBe([]);
});

it('tiene l\'elenco dei punti ammessi aggiornato: ogni file esiste ancora', function (): void {
    foreach (array_keys(ACCESS_WRITERS) as $path) {
        expect(dirname(__DIR__, 2).'/'.$path)->toBeFile();
    }
});

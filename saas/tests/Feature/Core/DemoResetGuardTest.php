<?php

declare(strict_types=1);

use App\Modules\Core\Demo\DemoResetGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('rifiuta se manca il database atteso o se il database collegato è un altro', function (): void {
    expect(DemoResetGuard::refusal('as_prod', '', [], 'demosaas', '/home/x/saas-demo/current', 'saas-demo'))->toContain('SAAS_DEMO_DATABASE')
        ->and(DemoResetGuard::refusal('as_prod', 'as_demo', [], 'demosaas', '/home/x/saas-demo/current', 'saas-demo'))->toContain('non è quello della demo')
        ->and(DemoResetGuard::refusal('as_demo', 'as_demo', [], 'demosaas', '/home/x/saas-demo/current', 'saas-demo'))->toBeNull();
});

it('rifiuta se nel database c\'è un\'associazione che non è la demo', function (): void {
    expect(DemoResetGuard::refusal('as_demo', 'as_demo', ['demosaas', 'cral-vera'], 'demosaas', '/home/x/saas-demo/current', 'saas-demo'))->toContain('cral-vera')
        ->and(DemoResetGuard::refusal('as_demo', 'as_demo', ['demosaas'], 'demosaas', '/home/x/saas-demo/current', 'saas-demo'))->toBeNull();
});

it('rifiuta se l\'applicazione non sta in una cartella saas-demo', function (): void {
    expect(DemoResetGuard::refusal('as_demo', 'as_demo', [], 'demosaas', '/home/x/saas/current', 'saas-demo'))->toContain('saas-demo')
        ->and(DemoResetGuard::refusal('as_demo', 'as_demo', [], 'demosaas', '/home/x/saas-demo-vecchia/current', 'saas-demo'))->not->toBeNull()
        ->and(DemoResetGuard::refusal('as_demo', 'as_demo', [], 'demosaas', 'C:\srv\saas-demo\current', 'saas-demo'))->toBeNull();
});

it('il comando si ferma prima di cancellare quando il database non è quello atteso', function (): void {
    demoOn(['saas.demo.database' => 'un_altro_database']);
    $orgs = DB::table('core.orgs')->count();

    expect(Artisan::call('saas:demo:reset'))->toBe(1)
        ->and(Artisan::output())->toContain('non è quello della demo')
        ->and(DB::table('core.orgs')->count())->toBe($orgs);
});

it('svuota la cartella dei file della demo precedente ma non la radice', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prova-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'saas-demo'.DIRECTORY_SEPARATOR.'org-files';
    File::ensureDirectoryExists($root.'/org-1/2026');
    File::put($root.'/org-1/2026/a.enc', 'x');
    File::put($root.'/org-2.enc', 'y');

    expect(DemoResetGuard::clearFiles($root, 'saas-demo'))->toBe(2)
        ->and(is_dir($root))->toBeTrue()
        ->and(File::allFiles($root))->toBe([])
        ->and(File::directories($root))->toBe([]);

    File::deleteDirectory(dirname($root, 2));
});

it('non tocca i file se la radice non sta sotto la cartella della demo', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prova-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'saas'.DIRECTORY_SEPARATOR.'org-files';
    File::ensureDirectoryExists($root);
    File::put($root.'/importante.enc', 'x');

    expect(DemoResetGuard::clearFiles($root, 'saas-demo'))->toBeNull()
        ->and(File::exists($root.'/importante.enc'))->toBeTrue();

    File::deleteDirectory(dirname($root, 2));
});

it('rifiuta se un\'associazione ha un piano vero, anche con lo stesso nome della demo', function (): void {
    $org = $this->createOrg('Associazione Vera');
    DB::table('core.org_entitlements')->insert([
        'org_id' => $org->id, 'rev' => 1, 'status' => 'active', 'until' => '2027-03-31',
        'claims' => json_encode(['members' => 100]), 'token' => 'token-vero',
    ]);
    // Database atteso e cartella giusti, così a fermare l'azzeramento resta solo il piano vero.
    config(['saas.demo.org_slug' => $org->slug, 'saas.demo.database' => DB::connection()->getDatabaseName(), 'saas.demo.path_marker' => basename(base_path())]);

    expect(DemoResetGuard::check())->toContain('piano vero');
});

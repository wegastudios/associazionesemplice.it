<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\OrgKeyMissing;
use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Keys\KeyRing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('non ricrea in silenzio la chiave di un\'associazione che non ce l\'ha', function (): void {
    Log::spy();
    $orgId = (string) Str::uuid();

    expect(fn () => app(KeyRing::class)->current($orgId))->toThrow(OrgKeyMissing::class);

    expect(DB::table('core.org_keys')->where('org_id', $orgId)->exists())->toBeFalse();
    Log::shouldHaveReceived('critical')->once();
});

it('scrive nel registro della piattaforma quando una chiave manca', function (): void {
    $orgId = (string) Str::uuid();

    try {
        app(KeyRing::class)->current($orgId);
    } catch (OrgKeyMissing) {
        // atteso
    }

    $row = DB::table('core.platform_audit')->where('action', KeyAudit::ORG_KEY_MISSING)->where('subject', $orgId)->first();
    expect($row)->not->toBeNull();
});

it('crea la chiave alla nascita dell\'associazione e la usa senza errori', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $key = app(KeyRing::class)->current($org->id);

    expect($key->version)->toBe(1)
        ->and(DB::table('core.org_keys')->where('org_id', $org->id)->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgDirectory;

it('non lascia prendere a un\'associazione vera un nome riservato', function (): void {
    $orgs = app(OrgDirectory::class);

    foreach (['demosaas', 'app', 'app-demo', 'DemoSaaS'] as $slug) {
        expect($orgs->slugTaken($slug))->toBeTrue();
    }
    expect($orgs->slugTaken('associazione-esempio-libera'))->toBeFalse();
});

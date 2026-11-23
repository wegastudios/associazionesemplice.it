<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

// Gira dopo `npm run build` (in CI): i test delle pagine usano withoutVite(), quindi qui si controlla che quello che
// app.blade.php chiede al manifest ci sia davvero, altrimenti ogni pagina risponderebbe 500.
beforeEach(function (): void {
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('Manifest di Vite assente: serve npm run build.');
    }
});

it('trova nel manifest ogni pagina, con il suo pacchetto da precaricare', function (): void {
    $pages = array_filter(File::allFiles(resource_path('js/pages')), fn (SplFileInfo $f): bool => $f->getExtension() === 'vue');
    expect($pages)->not->toBeEmpty();
    /** @var array<string, array{file: string}> $manifest */
    $manifest = File::json(public_path('build/manifest.json'));

    foreach ($pages as $file) {
        $component = 'resources/js/pages/'.str_replace('\\', '/', $file->getRelativePathname());
        expect($manifest)->toHaveKey($component);

        $html = (string) (new Vite)(['resources/css/app.css', 'resources/js/app.js', $component]);
        expect($html)->toContain('/build/'.$manifest[$component]['file'].'"');
    }
});

it('passa i font da Vite in WOFF2 e precarica quello del testo', function (): void {
    $font = (new Vite)->asset('resources/fonts/montserrat-500-latin.woff2');
    expect($font)->toMatch('#/build/assets/montserrat-500-latin-[\w-]+\.woff2$#');

    $css = (string) (new Vite)(['resources/css/app.css']);
    preg_match('#/build/(assets/[^"]+\.css)#', $css, $m);
    $built = File::get(public_path('build/'.$m[1]));

    expect($built)->toContain(basename($font))
        ->and($built)->toContain('montserrat-700-esteso-')
        ->and($built)->not->toContain('.ttf');
});

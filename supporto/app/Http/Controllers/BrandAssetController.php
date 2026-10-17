<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serve gli asset di marchio (simbolo) da resources/brand, senza dipendenze esterne. */
class BrandAssetController extends Controller
{
    private const FILES = [
        'logo.png' => 'image/png',
        'logo-512.png' => 'image/png',
        'favicon.png' => 'image/png',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        return response()->file(resource_path('brand/'.$file), [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}

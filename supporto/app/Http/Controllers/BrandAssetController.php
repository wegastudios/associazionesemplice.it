<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serve gli asset di marchio (simbolo) da resources/brand, senza dipendenze esterne. */
class BrandAssetController extends Controller
{
    private const FILES = [
        'logo-orizzontale-colore.svg' => 'image/svg+xml',
        'logo-orizzontale-negativo.svg' => 'image/svg+xml',
        'simbolo-colore.svg' => 'image/svg+xml',
        'simbolo-negativo.svg' => 'image/svg+xml',
        'favicon.svg' => 'image/svg+xml',
        'favicon.ico' => 'image/x-icon',
        'favicon-32.png' => 'image/png',
        'apple-touch-icon.png' => 'image/png',
        'Montserrat-Medium.ttf' => 'font/ttf',
        'Montserrat-SemiBold.ttf' => 'font/ttf',
        'Montserrat-Bold.ttf' => 'font/ttf',
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

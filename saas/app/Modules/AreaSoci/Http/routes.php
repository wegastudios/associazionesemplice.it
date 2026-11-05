<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Controllers\AccessController;
use App\Modules\AreaSoci\Http\Controllers\MemberAreaController;
use App\Modules\AreaSoci\Http\Controllers\StaffLinkController;
use Illuminate\Support\Facades\Route;

$domain = config('saas.members_domain');

// Area soci: https://<slug>.<dominio dei soci>/area. Il sito dell'associazione (pacchetto Sito) avrà il resto.
Route::domain('{org}.'.(is_string($domain) ? $domain : 'associazionesemplice.it'))->where(['org' => '[a-z0-9-]{3,40}'])
    ->middleware(['web', 'member-org'])->prefix('area')->group(function (): void {
        Route::get('/accesso', [AccessController::class, 'show'])->name('member.login');
        Route::post('/accesso', [AccessController::class, 'request'])->name('member.login.request');
        Route::post('/accesso/codice', [AccessController::class, 'code'])->name('member.login.code');
        Route::get('/entra/{token}', [AccessController::class, 'link'])->where('token', '[A-Za-z0-9_-]{43}')->name('member.login.link');
        Route::get('/verifica/{person}', [MemberAreaController::class, 'verify'])->whereUuid('person')->middleware('signed')->name('member.verify');

        Route::middleware('member')->group(function (): void {
            Route::get('/', [MemberAreaController::class, 'card'])->name('member.home');
            Route::get('/profilo', [MemberAreaController::class, 'profile'])->name('member.profile');
            Route::put('/profilo', [MemberAreaController::class, 'updateProfile'])->name('member.profile.update');
            Route::get('/quote', [MemberAreaController::class, 'payments'])->name('member.payments');
            Route::get('/ricevute/{receipt}.pdf', [MemberAreaController::class, 'receipt'])->whereUuid('receipt')->name('member.receipt');
            Route::get('/eventi', [MemberAreaController::class, 'events'])->name('member.events');
            Route::post('/eventi/{session}/prenota', [MemberAreaController::class, 'book'])->whereUuid('session')->name('member.events.book');
            Route::post('/eventi/{session}/attesa', [MemberAreaController::class, 'waitlist'])->whereUuid('session')->name('member.events.waitlist');
            Route::post('/prenotazioni/{booking}/annulla', [MemberAreaController::class, 'cancel'])->whereUuid('booking')->name('member.events.cancel');
            Route::post('/scheda/{person}', [AccessController::class, 'switch'])->whereUuid('person')->name('member.switch');
            Route::post('/esci', [AccessController::class, 'logout'])->name('member.logout');
            Route::post('/esci-ovunque', [AccessController::class, 'logoutAll'])->name('member.logout.all');
        });
    });

// Gestionale: link d'accesso preparato dalla segreteria, uscita da tutti i dispositivi.
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area', 'can:people.edit'])->prefix('gestionale')->group(function (): void {
    Route::post('/soci/{person}/area-soci/link', [StaffLinkController::class, 'create'])->whereUuid('person')->name('member.staff.link');
    Route::post('/soci/{person}/area-soci/esci', [StaffLinkController::class, 'logoutAll'])->whereUuid('person')->name('member.staff.logout');
});

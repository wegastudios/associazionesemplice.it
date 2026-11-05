<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Controllers\AccessController;
use App\Modules\AreaSoci\Http\Controllers\MemberAreaController;
use App\Modules\AreaSoci\Http\Controllers\MemberPaymentsController;
use App\Modules\AreaSoci\Http\Controllers\MemberResourcesController;
use App\Modules\AreaSoci\Http\Controllers\PaymentSettingsController;
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
            Route::get('/risorse', [MemberResourcesController::class, 'index'])->name('member.resources');
            Route::post('/risorse/{resource}/prenota', [MemberResourcesController::class, 'book'])->whereUuid('resource')->middleware('throttle:30,10')->name('member.resources.book');
            Route::post('/risorse/prenotazioni/{reservation}/annulla', [MemberResourcesController::class, 'cancel'])->whereUuid('reservation')->name('member.resources.cancel');
            Route::get('/paga', [MemberPaymentsController::class, 'index'])->name('member.pay');
            Route::post('/paga', [MemberPaymentsController::class, 'checkout'])->middleware('throttle:20,10')->name('member.pay.checkout');
            Route::get('/pagamenti/{payment}/esito', [MemberPaymentsController::class, 'result'])->whereUuid('payment')->name('member.pay.result');
            Route::get('/pagamenti/{payment}/annullato', [MemberPaymentsController::class, 'cancelled'])->whereUuid('payment')->name('member.pay.cancelled');
        });
    });

// Webhook di Stripe, sul sottodominio dell'associazione: niente sessione né CSRF, vale la firma.
Route::domain('{org}.'.(is_string($domain) ? $domain : 'associazionesemplice.it'))->where(['org' => '[a-z0-9-]{3,40}'])
    ->middleware(['member-org', 'throttle:120,1'])
    ->post('/area/webhook/stripe', [MemberPaymentsController::class, 'stripeWebhook'])->name('member.webhook.stripe');

// Gestionale: link d'accesso preparato dalla segreteria, uscita da tutti i dispositivi.
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area', 'can:people.edit'])->prefix('gestionale')->group(function (): void {
    Route::post('/soci/{person}/area-soci/link', [StaffLinkController::class, 'create'])->whereUuid('person')->name('member.staff.link');
    Route::post('/soci/{person}/area-soci/esci', [StaffLinkController::class, 'logoutAll'])->whereUuid('person')->name('member.staff.logout');
});

// Gestionale: pagamenti online (impostazioni per chi gestisce l'associazione, elenco per la contabilità).
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area'])->prefix('gestionale')->group(function (): void {
    Route::get('/pagamenti-online', [PaymentSettingsController::class, 'index'])->middleware('can:accounting.view')->name('payments.online');
    Route::put('/pagamenti-online', [PaymentSettingsController::class, 'update'])->middleware(['can:org.settings', 'password.confirm'])->name('payments.online.update');
    Route::post('/pagamenti-online/prova/{provider}', [PaymentSettingsController::class, 'test'])->whereIn('provider', ['stripe', 'paypal'])->middleware('can:org.settings')->name('payments.online.test');
    Route::post('/pagamenti-online/{payment}/controllato', [PaymentSettingsController::class, 'reviewed'])->whereUuid('payment')->middleware('can:accounting.edit')->name('payments.online.reviewed');
});

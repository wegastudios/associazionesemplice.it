<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Controllers\AccessController;
use App\Modules\AreaSoci\Http\Controllers\DemoMemberEntryController;
use App\Modules\AreaSoci\Http\Controllers\MemberAreaController;
use App\Modules\AreaSoci\Http\Controllers\MemberPaymentsController;
use App\Modules\AreaSoci\Http\Controllers\MemberResourcesController;
use App\Modules\AreaSoci\Http\Controllers\PaymentSettingsController;
use App\Modules\AreaSoci\Http\Controllers\PublicDonationController;
use App\Modules\AreaSoci\Http\Controllers\StaffLinkController;
use App\Modules\AreaSoci\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

$domain = config('saas.members_domain');

// Area soci: https://<slug>.<dominio dei soci>/area. Il sito dell'associazione (pacchetto Sito) avrà il resto.
// Le stesse pagine rispondono anche sul dominio personalizzato dell'associazione (solo se verificato: lo decide
// `member-org`), con gli stessi indirizzi e i nomi preceduti da «custom.»: link e codice arrivano sullo stesso dominio.
$memberArea = function (): void {
    Route::get('/accesso', [AccessController::class, 'show'])->name('member.login');
    Route::post('/accesso', [AccessController::class, 'request'])->name('member.login.request');
    Route::post('/accesso/codice', [AccessController::class, 'code'])->name('member.login.code');
    // Solo demo (DemoEnvironment::memberEntryOpen): altrove il controller risponde 404. Nessun dato arriva dalla richiesta.
    Route::post('/accesso/demo', DemoMemberEntryController::class)->name('member.login.demo');
    Route::get('/entra/{token}', [AccessController::class, 'linkPage'])->where('token', '[A-Za-z0-9_-]{43}')->name('member.login.link');
    Route::post('/entra/{token}', [AccessController::class, 'link'])->where('token', '[A-Za-z0-9_-]{43}')->name('member.login.link.enter');
    Route::get('/verifica/{person}', [MemberAreaController::class, 'verify'])->whereUuid('person')->middleware('signed')->name('member.verify');
    // Verifica in due passaggi: il codice dopo il primo passaggio e il ripristino via email (telefono e codici di riserva persi).
    Route::get('/accesso/verifica', [TwoFactorController::class, 'codePage'])->name('member.tfa.code');
    Route::post('/accesso/verifica', [TwoFactorController::class, 'code'])->middleware('throttle:20,10')->name('member.tfa.code.check');
    Route::post('/accesso/verifica/ripristino', [TwoFactorController::class, 'requestReset'])->middleware('throttle:5,60')->name('member.tfa.reset.request');
    Route::get('/verifica/ripristino/{token}', [TwoFactorController::class, 'resetPage'])->where('token', '[A-Za-z0-9_-]{43}')->name('member.tfa.reset');
    Route::post('/verifica/ripristino/{token}', [TwoFactorController::class, 'reset'])->where('token', '[A-Za-z0-9_-]{43}')->middleware('throttle:20,10')->name('member.tfa.reset.confirm');

    Route::middleware('member')->group(function (): void {
        Route::get('/', [MemberAreaController::class, 'card'])->name('member.home');
        Route::get('/profilo', [MemberAreaController::class, 'profile'])->name('member.profile');
        Route::post('/tessera/rigenera', [MemberAreaController::class, 'regenerateCard'])->middleware('throttle:10,10')->name('member.card.regenerate');
        Route::put('/profilo', [MemberAreaController::class, 'updateProfile'])->middleware('throttle:member-profile')->name('member.profile.update');
        Route::get('/quote', [MemberAreaController::class, 'payments'])->name('member.payments');
        Route::get('/ricevute/{receipt}.pdf', [MemberAreaController::class, 'receipt'])->whereUuid('receipt')->middleware('throttle:member-receipt')->name('member.receipt');
        Route::get('/eventi', [MemberAreaController::class, 'events'])->name('member.events');
        Route::post('/eventi/{session}/prenota', [MemberAreaController::class, 'book'])->whereUuid('session')->middleware('throttle:member-book')->name('member.events.book');
        Route::post('/eventi/{session}/attesa', [MemberAreaController::class, 'waitlist'])->whereUuid('session')->middleware('throttle:member-book')->name('member.events.waitlist');
        Route::post('/prenotazioni/{booking}/annulla', [MemberAreaController::class, 'cancel'])->whereUuid('booking')->middleware('throttle:member-cancel')->name('member.events.cancel');
        Route::post('/scheda/{person}', [AccessController::class, 'switch'])->whereUuid('person')->name('member.switch');
        Route::post('/esci', [AccessController::class, 'logout'])->name('member.logout');
        Route::post('/esci-ovunque', [AccessController::class, 'logoutAll'])->name('member.logout.all');
        Route::post('/profilo/verifica/accendi', [TwoFactorController::class, 'start'])->middleware('throttle:10,10')->name('member.tfa.start');
        Route::post('/profilo/verifica/conferma', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,10')->name('member.tfa.confirm');
        Route::post('/profilo/verifica/annulla', [TwoFactorController::class, 'cancel'])->name('member.tfa.cancel');
        Route::post('/profilo/verifica/codici', [TwoFactorController::class, 'regenerate'])->middleware('throttle:20,10')->name('member.tfa.regenerate');
        Route::post('/profilo/verifica/spegni', [TwoFactorController::class, 'disable'])->middleware('throttle:20,10')->name('member.tfa.disable');
        Route::get('/risorse', [MemberResourcesController::class, 'index'])->name('member.resources');
        Route::post('/risorse/{resource}/prenota', [MemberResourcesController::class, 'book'])->whereUuid('resource')->middleware('throttle:member-book')->name('member.resources.book');
        Route::post('/risorse/prenotazioni/{reservation}/annulla', [MemberResourcesController::class, 'cancel'])->whereUuid('reservation')->middleware('throttle:member-cancel')->name('member.resources.cancel');
        Route::get('/paga', [MemberPaymentsController::class, 'index'])->name('member.pay');
        Route::post('/paga', [MemberPaymentsController::class, 'checkout'])->middleware('throttle:20,10')->name('member.pay.checkout');
        Route::get('/pagamenti/{payment}/esito', [MemberPaymentsController::class, 'result'])->whereUuid('payment')->middleware('throttle:member-pay-result')->name('member.pay.result');
        Route::get('/pagamenti/{payment}/annullato', [MemberPaymentsController::class, 'cancelled'])->whereUuid('payment')->name('member.pay.cancelled');
    });
};

Route::domain('{org}.'.(is_string($domain) ? $domain : 'associazionesemplice.it'))->where(['org' => '[a-z0-9-]{3,40}'])
    ->middleware(['web', 'cookie-session', 'member-org'])->prefix('area')->group($memberArea);

Route::name('custom.')->domain('{host}')->where(['host' => '[a-z0-9]([a-z0-9.-]{2,251}[a-z0-9])?'])
    ->middleware(['web', 'cookie-session', 'member-org'])->prefix('area')->group($memberArea);

// Donazione pubblica, senza accesso e aperta anche ai non soci: pochi invii per indirizzo e per associazione.
// Come l'area soci, sessione nel cookie (marchio «cookie-session»): niente riga nel database per ogni visita.
Route::middleware(['web', 'cookie-session'])->group(function (): void {
    Route::get('/dona/{slug}', [PublicDonationController::class, 'show'])->name('donation.form');
    Route::post('/dona/{slug}', [PublicDonationController::class, 'store'])->middleware('throttle:donations')->name('donation.submit');
    Route::get('/dona/{slug}/{payment}/esito', [PublicDonationController::class, 'result'])->whereUuid('payment')->name('donation.result');
    Route::get('/dona/{slug}/{payment}/annullato', [PublicDonationController::class, 'cancelled'])->whereUuid('payment')->name('donation.cancelled');
});

// Webhook di Stripe, sul sottodominio dell'associazione: niente sessione né CSRF, vale la firma.
Route::domain('{org}.'.(is_string($domain) ? $domain : 'associazionesemplice.it'))->where(['org' => '[a-z0-9-]{3,40}'])
    ->middleware(['member-org', 'throttle:120,1'])
    ->post('/area/webhook/stripe', [MemberPaymentsController::class, 'stripeWebhook'])->name('member.webhook.stripe');

// Gestionale: link d'accesso preparato dalla segreteria, uscita da tutti i dispositivi.
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area', 'can:people.edit'])->prefix('gestionale')->group(function (): void {
    Route::post('/soci/{person}/area-soci/link', [StaffLinkController::class, 'create'])->whereUuid('person')->name('member.staff.link');
    Route::post('/soci/{person}/area-soci/esci', [StaffLinkController::class, 'logoutAll'])->whereUuid('person')->name('member.staff.logout');
    Route::post('/soci/{person}/area-soci/verifica-spegni', [StaffLinkController::class, 'twoFactorOff'])->whereUuid('person')->middleware('throttle:10,1')->name('member.staff.tfa-off');
});

// Gestionale: pagamenti online (impostazioni per chi gestisce l'associazione, elenco per la contabilità).
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area'])->prefix('gestionale')->group(function (): void {
    Route::get('/pagamenti-online', [PaymentSettingsController::class, 'index'])->middleware('can:accounting.view')->name('payments.online');
    Route::put('/pagamenti-online', [PaymentSettingsController::class, 'update'])->middleware(['can:org.settings', 'password.confirm'])->name('payments.online.update');
    Route::put('/pagamenti-online/donazioni', [PaymentSettingsController::class, 'donations'])->middleware('can:org.settings')->name('payments.online.donations');
    Route::post('/pagamenti-online/prova/{provider}', [PaymentSettingsController::class, 'test'])->whereIn('provider', ['stripe', 'paypal'])->middleware('can:org.settings')->name('payments.online.test');
    Route::post('/pagamenti-online/{payment}/controllato', [PaymentSettingsController::class, 'reviewed'])->whereUuid('payment')->middleware('can:accounting.edit')->name('payments.online.reviewed');
    Route::post('/pagamenti-online/{payment}/rimborso', [PaymentSettingsController::class, 'refund'])->whereUuid('payment')->middleware(['can:accounting.refund', 'password.confirm'])->name('payments.online.refund');
});

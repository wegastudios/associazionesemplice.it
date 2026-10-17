<?php

use App\Http\Controllers\Admin\Site\ArticleController;
use App\Http\Controllers\Admin\Site\HubStatusController;
use App\Http\Controllers\Admin\Site\PlanController;
use App\Http\Controllers\Admin\Site\SectionController;
use App\Http\Controllers\Admin\Site\TicketController as AdminTicketController;
use App\Http\Controllers\Admin\Site\TopicController;
use App\Http\Controllers\Site\HelpCenterController;
use App\Http\Controllers\Site\PublicTicketController;
use App\Http\Controllers\Site\SsoController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/admin-auth.php';

// Centro assistenza pubblico
Route::get('/', [HelpCenterController::class, 'index'])->name('help.index');
Route::get('/cerca', [HelpCenterController::class, 'search'])->name('help.search');
Route::get('/argomenti/{topic:slug}', [HelpCenterController::class, 'topic'])->name('help.topic');
Route::get('/articoli/{article}', [HelpCenterController::class, 'article'])->whereNumber('article')->name('help.article');

// Accesso unico dal portale e area del cliente connesso
Route::get('/sso', [SsoController::class, 'login'])->middleware('throttle:20,1')->name('sso.login');
Route::post('/esci', [SsoController::class, 'logout'])->name('sso.logout');
Route::get('/i-miei-ticket', [SsoController::class, 'mine'])->name('ticket.mine');

// Ticket del cliente: il modulo e pubblico, il seguito del ticket passa da un link firmato ricevuto per email
Route::get('/ticket/nuovo', [PublicTicketController::class, 'create'])->name('ticket.create');
Route::post('/ticket', [PublicTicketController::class, 'store'])->middleware('throttle:public-ticket')->name('ticket.store');
Route::get('/ticket/ricevuto', [PublicTicketController::class, 'received'])->name('ticket.received');
Route::get('/ticket/{hubId}', [PublicTicketController::class, 'show'])->whereNumber('hubId')->middleware('signed')->name('ticket.show');
Route::post('/ticket/{hubId}/risposta', [PublicTicketController::class, 'reply'])->whereNumber('hubId')->middleware(['signed', 'throttle:public-ticket'])->name('ticket.reply');

// Pannello dello staff del portale
Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
    Route::redirect('/', '/admin/ticket')->name('home');

    Route::get('/ticket', [AdminTicketController::class, 'index'])->name('tickets.index');
    Route::get('/ticket/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('/ticket/{ticket}/risposta', [AdminTicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/ticket/{ticket}/stato', [AdminTicketController::class, 'status'])->name('tickets.status');
    Route::post('/ticket/{ticket}/priorita', [AdminTicketController::class, 'priority'])->name('tickets.priority');
    Route::post('/ticket/{ticket}/gestione', [AdminTicketController::class, 'manage'])->name('tickets.manage');
    Route::post('/ticket/{ticket}/registra', [AdminTicketController::class, 'register'])->name('tickets.register');

    Route::get('/argomenti', [TopicController::class, 'index'])->name('topics.index');
    Route::post('/argomenti', [TopicController::class, 'store'])->name('topics.store');
    Route::post('/argomenti/{topic}', [TopicController::class, 'update'])->name('topics.update');
    Route::post('/argomenti/{topic}/elimina', [TopicController::class, 'destroy'])->name('topics.destroy');
    Route::post('/argomenti/{topic}/sezioni', [SectionController::class, 'store'])->name('sections.store');
    Route::post('/sezioni/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::post('/sezioni/{section}/elimina', [SectionController::class, 'destroy'])->name('sections.destroy');

    Route::get('/articoli', [ArticleController::class, 'index'])->name('articles.index');
    Route::get('/articoli/nuovo', [ArticleController::class, 'create'])->name('articles.create');
    Route::post('/articoli', [ArticleController::class, 'store'])->name('articles.store');
    Route::get('/articoli/{article}', [ArticleController::class, 'edit'])->name('articles.edit');
    Route::post('/articoli/{article}', [ArticleController::class, 'update'])->name('articles.update');
    Route::post('/articoli/{article}/elimina', [ArticleController::class, 'destroy'])->name('articles.destroy');

    Route::get('/piani', [PlanController::class, 'index'])->name('plans.index');
    Route::post('/piani', [PlanController::class, 'store'])->name('plans.store');
    Route::post('/piani/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::post('/piani/{plan}/elimina', [PlanController::class, 'destroy'])->name('plans.destroy');

    Route::get('/hub', [HubStatusController::class, 'show'])->name('hub.status');
    Route::post('/hub/verifica', [HubStatusController::class, 'check'])->middleware('throttle:10,1')->name('hub.check');
});

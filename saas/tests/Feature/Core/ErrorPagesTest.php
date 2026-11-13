<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('la pagina 403 mostra il motivo vero, non la frase generica', function (): void {
    Route::middleware('web')->get('/_prova/pacchetto', fn () => abort(403, 'Questa funzione fa parte di un pacchetto che la tua associazione non ha attivato.'));
    Route::middleware('web')->get('/_prova/ruolo', fn () => abort(403));

    $this->get('/_prova/pacchetto')->assertForbidden()->assertSee('pacchetto che la tua associazione non ha attivato', false)->assertDontSee('chiedi a chi gestisce', false);
    $this->get('/_prova/ruolo')->assertForbidden()->assertSee('Il tuo ruolo non comprende questa pagina', false);
});

it('le pagine d\'errore riportano al gestionale, e nell\'area soci all\'area soci', function (): void {
    Route::middleware('web')->get('/_prova/x', fn () => abort(404));
    Route::middleware('web')->get('/area/_prova', fn () => abort(404));

    $this->get('/_prova/x')->assertNotFound()->assertSee('Torna al gestionale', false)->assertDontSee('Torna al tuo account', false);
    $this->get('/area/_prova')->assertNotFound()->assertSee('Torna all’area soci', false)->assertDontSee('Torna al tuo account', false);
});

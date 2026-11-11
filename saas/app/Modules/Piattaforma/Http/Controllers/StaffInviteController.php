<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\StaffInvites;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pagina di un invito dello staff: la persona sceglie la password. Senza sessione né cookie: il codice nell'indirizzo è
 * il segreto, vale una volta sola e scade (docs/UTENTI_E_ACCESSI.md §4). Mai indicizzata.
 */
final readonly class StaffInviteController
{
    public function __construct(private StaffInvites $invites) {}

    public function show(string $code): View
    {
        $invite = $this->invites->open($code);

        return view('staff.invite', ['invite' => $invite, 'code' => $code, 'errors' => [], 'done' => false]);
    }

    public function accept(Request $request, string $code): View
    {
        $invite = $this->invites->open($code);
        if ($invite === null) {
            return view('staff.invite', ['invite' => null, 'code' => $code, 'errors' => [], 'done' => false]);
        }

        $errors = $this->invites->accept($code, $request->string('password')->toString(), $request->string('password_confirmation')->toString(), $request->ip());

        return view('staff.invite', ['invite' => $invite, 'code' => $code, 'errors' => $errors, 'done' => $errors === []]);
    }
}

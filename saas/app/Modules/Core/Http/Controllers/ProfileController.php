<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il profilo di chi lavora nel gestionale: nome, email, password (endpoint di Fortify), verifica in due passaggi e
 * dispositivi collegati. Password e verifica in due passaggi restano le rotte di Fortify.
 */
final readonly class ProfileController
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $request->session()->getId();

        $sessions = DB::table('core.sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static fn (object $s): array => [
                'current' => $s->id === $current,
                'ip' => is_string($s->ip_address) ? $s->ip_address : '',
                'device' => self::device(is_string($s->user_agent) ? $s->user_agent : ''),
                'last' => date('c', is_numeric($s->last_activity) ? (int) $s->last_activity : 0),
            ])->values()->all();

        return Inertia::render('Account/Profilo', [
            'profile' => ['name' => $user->name, 'email' => $user->email],
            'twoFactor' => $user->hasConfirmedTwoFactor(),
            'sessions' => $sessions,
        ]);
    }

    public function name(Request $request): RedirectResponse
    {
        $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['name' => $request->string('name')->trim()->toString()])->save();
        }

        return back()->with('status', 'Nome aggiornato.');
    }

    public function email(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('pgsql.core.users', 'email')->ignore($user instanceof User ? $user->id : null)],
            'password' => ['required', 'string', 'current_password:web'],
        ], ['password.current_password' => 'La password non è corretta.', 'email.unique' => 'Questa email è già usata da un altro accesso.']);
        if ($user instanceof User) {
            $user->forceFill(['email' => mb_strtolower($request->string('email')->trim()->toString())])->save();
        }

        return back()->with('status', 'Email aggiornata.');
    }

    /** Chiude tutte le altre sessioni della persona (altri telefoni e computer), tenendo questa. */
    public function closeOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']], ['password.current_password' => 'La password non è corretta.']);
        $user = $request->user();
        if ($user instanceof User) {
            DB::table('core.sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        return back()->with('status', 'Gli altri dispositivi sono stati scollegati.');
    }

    private static function device(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iPhone/iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Dispositivo',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };

        return trim($os.($browser !== '' ? ' · '.$browser : ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * L'accesso unico dello staff (docs/LOGIN_UNICO_E_DATABASE.md): secondo livello facoltativo e recupero password
 * dell'elenco unico (`core.staff_*`). La password si controlla in `StaffDirectory`; qui il codice e il recupero.
 */
final readonly class StaffAccess
{
    public const RESET_MINUTES = 60;

    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private StaffDirectory $directory,
        private TwoFactorAuthenticationProvider $totp,
    ) {}

    /** Vero se la persona ha attivato il secondo livello: solo allora si chiede il codice. */
    public function hasTwoFactor(StaffPerson $person): bool
    {
        $row = $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->first(['two_factor_secret', 'two_factor_confirmed_at']);

        return $row !== null && $row->two_factor_secret !== null && $row->two_factor_confirmed_at !== null;
    }

    /** Una persona attiva, per identificativo. */
    public function find(string $id): ?StaffPerson
    {
        $row = $this->db->connection('pgsql')->table('core.staff_people')->where('id', $id)->where('active', true)->first(['id', 'email', 'name']);

        return $row === null ? null : new StaffPerson(is_string($row->id) ? $row->id : '', is_string($row->email) ? $row->email : '', is_string($row->name) ? $row->name : '');
    }

    /** Chiude la sessione centrale (uscita). */
    public function revokeSession(string $sid): void
    {
        $this->db->connection('pgsql')->table('core.staff_sessions')->where('sid', $sid)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function codeIsValid(StaffPerson $person, string $code): bool
    {
        $secret = $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->value('two_factor_secret');
        if (! is_string($secret) || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $plain = Fortify::currentEncrypter()->decrypt($secret);

        return is_string($plain) && $this->totp->verify($plain, $code);
    }

    /** Manda il link per scegliere una nuova password. Non dice mai se l'email esiste (chi chiama risponde sempre allo stesso modo). */
    public function requestReset(string $email, string $base, ?string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $db = $this->db->connection('pgsql');
        $row = $db->table('core.staff_people')->where('email', $email)->where('active', true)->first(['id', 'email']);
        if ($row === null) {
            return;
        }

        $code = bin2hex(random_bytes(24));
        $db->table('core.staff_resets')->where('person_id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);
        $db->table('core.staff_resets')->insert([
            'id' => (string) Str::uuid7(),
            'token_hash' => hash('sha256', $code),
            'person_id' => $row->id,
            'expires_at' => now()->addMinutes(self::RESET_MINUTES),
        ]);
        $this->directory->audit('staff.password.reset_requested', is_string($row->id) ? $row->id : null, null, [], $ip);

        $link = rtrim($base, '/').'/staff/reimposta/'.$code;
        try {
            Mail::raw(
                "Ciao,\n\nhai chiesto di scegliere una nuova password per l'accesso a Associazione Semplice. Usa questo link (vale ".self::RESET_MINUTES." minuti e si usa una volta sola):\n\n{$link}\n\nSe non l'hai chiesto tu, ignora questa email: la tua password resta quella di prima.\n",
                static function (Message $message) use ($email): void {
                    $message->to($email)->subject('Nuova password per Associazione Semplice');
                },
            );
        } catch (Throwable) {
            // Nessun errore a chi chiede: dire che la posta non parte rivelerebbe che l'email esiste.
        }
    }

    public function resetIsOpen(string $code): bool
    {
        return $this->openReset($code) !== null;
    }

    /**
     * Sceglie la nuova password dal link. Chiude tutte le sessioni aperte della persona.
     *
     * @return list<string> errori (vuoto = fatto)
     */
    public function reset(string $code, string $password, string $confirmation, ?string $ip): array
    {
        if (mb_strlen($password) < StaffInvites::MIN_PASSWORD) {
            return ['La password deve avere almeno '.StaffInvites::MIN_PASSWORD.' caratteri.'];
        }
        if ($password !== $confirmation) {
            return ['Le due password non coincidono.'];
        }
        if (strlen($password) > 1024) {
            return ['La password è troppo lunga.'];
        }

        $db = $this->db->connection('pgsql');

        return $db->transaction(function () use ($db, $code, $password, $ip): array {
            $row = $db->table('core.staff_resets')->where('token_hash', hash('sha256', $code))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($row === null) {
                return ['Il link non è più valido: chiedine uno nuovo.'];
            }
            $personId = is_string($row->person_id) ? $row->person_id : '';
            $db->table('core.staff_resets')->where('id', $row->id)->update(['used_at' => now()]);
            $db->table('core.staff_people')->where('id', $personId)->update(['password' => $this->hasher->make($password)]);
            $db->table('core.staff_sessions')->where('person_id', $personId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->directory->audit('staff.password.reset', $personId, null, [], $ip);

            return [];
        });
    }

    private function openReset(string $code): ?object
    {
        return $this->db->connection('pgsql')->table('core.staff_resets')->where('token_hash', hash('sha256', $code))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }
}

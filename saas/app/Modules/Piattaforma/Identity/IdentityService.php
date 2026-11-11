<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Le operazioni sull'identità unica che il portale chiede con richieste firmate (docs/IDENTITA_API.md): accesso, codice,
 * profilo, password, email, secondo livello, sessioni. Ogni metodo che riguarda una persona parte da una sessione
 * centrale valida (`sid`); nessuna password o segreto resta altrove.
 */
final readonly class IdentityService
{
    public const TICKET_SECONDS = 300;

    private const TICKET_ATTEMPTS = 5;

    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private StaffDirectory $directory,
        private StaffAccess $access,
        private TwoFactorAuthenticationProvider $totp,
    ) {}

    /**
     * Password giusta. Senza secondo livello apre la sessione; con il secondo livello dà un ticket per il codice.
     *
     * @return array{status: 'ok', sid: string, person: array{email: string, name: string}, areas: array<string, string>}|array{status: 'code_required', ticket: string}|null
     */
    public function login(string $email, string $password, ?string $ip): ?array
    {
        $person = $this->directory->authenticate($email, $password);
        if ($person === null) {
            $this->directory->audit('staff.login_failed', null, mb_strtolower($email), [], $ip);

            return null;
        }
        if ($this->access->hasTwoFactor($person)) {
            $ticket = bin2hex(random_bytes(24));
            Cache::put('identity-ticket:'.hash('sha256', $ticket), ['person' => $person->id, 'tries' => 0], self::TICKET_SECONDS);

            return ['status' => 'code_required', 'ticket' => $ticket];
        }

        return $this->open($person, $ip);
    }

    /** @return array{status: 'ok', sid: string, person: array{email: string, name: string}, areas: array<string, string>}|null */
    public function code(string $ticket, string $code, ?string $ip): ?array
    {
        $key = 'identity-ticket:'.hash('sha256', $ticket);
        $state = Cache::get($key);
        $personId = is_array($state) && is_string($state['person'] ?? null) ? $state['person'] : null;
        $tries = is_array($state) && is_int($state['tries'] ?? null) ? $state['tries'] : 0;
        $person = $personId === null ? null : $this->access->find($personId);
        if ($person === null) {
            return null;
        }
        if (! $this->access->codeIsValid($person, $code)) {
            $this->directory->audit('staff.code_failed', $person->id, null, [], $ip);
            if ($tries + 1 >= self::TICKET_ATTEMPTS) {
                Cache::forget($key);
            } else {
                Cache::put($key, ['person' => $personId, 'tries' => $tries + 1], self::TICKET_SECONDS);
            }

            return null;
        }
        Cache::forget($key);

        return $this->open($person, $ip);
    }

    /** @return array{valid: bool, person: array{email: string, name: string}|null, areas: array<string, string>, two_factor: bool} */
    public function session(string $sid): array
    {
        $person = $this->personOf($sid);
        if ($person === null) {
            return ['valid' => false, 'person' => null, 'areas' => [], 'two_factor' => false];
        }

        return ['valid' => true, 'person' => $this->card($person), 'areas' => $this->directory->areas($person), 'two_factor' => $this->access->hasTwoFactor($person)];
    }

    /** @return array<string, mixed>|null */
    public function profile(string $sid): ?array
    {
        $person = $this->personOf($sid);
        if ($person === null) {
            return null;
        }
        $sessions = [];
        foreach ($this->db->connection('pgsql')->table('core.staff_sessions')->where('person_id', $person->id)->whereNull('revoked_at')->where('created_at', '>', now()->subSeconds(StaffDirectory::SESSION_MAX))->orderByDesc('last_used_at')->get(['sid', 'ip', 'created_at', 'last_used_at']) as $row) {
            $id = is_string($row->sid) ? $row->sid : '';
            $sessions[] = ['id' => self::sessionRef($id), 'ip' => $row->ip, 'created_at' => $row->created_at, 'last_used_at' => $row->last_used_at, 'current' => $id === $sid];
        }

        return ['person' => $this->card($person), 'areas' => $this->directory->areas($person), 'two_factor' => $this->access->hasTwoFactor($person), 'sessions' => $sessions];
    }

    /** @return list<string> errori (vuoto = fatto) */
    public function changePassword(string $sid, string $current, string $new, ?string $ip): array
    {
        $person = $this->personOf($sid);
        if ($person === null || $this->directory->authenticate($person->email, $current) === null) {
            return ['Password attuale non corretta.'];
        }
        if (mb_strlen($new) < StaffInvites::MIN_PASSWORD || strlen($new) > 1024) {
            return ['La nuova password deve avere almeno '.StaffInvites::MIN_PASSWORD.' caratteri.'];
        }
        $db = $this->db->connection('pgsql');
        $db->table('core.staff_people')->where('id', $person->id)->update(['password' => $this->hasher->make($new)]);
        $db->table('core.staff_sessions')->where('person_id', $person->id)->where('sid', '!=', $sid)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->directory->audit('staff.password.changed', $person->id, null, [], $ip);

        return [];
    }

    /**
     * Manda il link di conferma al NUOVO indirizzo (1 ora, una volta sola).
     *
     * @return list<string> errori (vuoto = fatto)
     */
    public function requestEmailChange(string $sid, string $password, string $newEmail, string $linkPrefix, ?string $ip): array
    {
        $person = $this->personOf($sid);
        if ($person === null || $this->directory->authenticate($person->email, $password) === null) {
            return ['Password non corretta.'];
        }
        $newEmail = mb_strtolower(trim($newEmail));
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false || strlen($newEmail) > 254) {
            return ['Email non valida.'];
        }
        $db = $this->db->connection('pgsql');
        if ($db->table('core.staff_people')->where('email', $newEmail)->exists()) {
            // Stessa risposta di un invio riuscito: non si rivela chi ha già un account.
            return [];
        }

        $code = bin2hex(random_bytes(24));
        $db->table('core.staff_email_changes')->where('person_id', $person->id)->whereNull('used_at')->update(['used_at' => now()]);
        $db->table('core.staff_email_changes')->insert(['id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', $code), 'person_id' => $person->id, 'new_email' => $newEmail, 'expires_at' => now()->addHour()]);
        $this->directory->audit('staff.email.change_requested', $person->id, null, [], $ip);
        $link = $linkPrefix.$code;
        try {
            Mail::raw(
                "Ciao,\n\nper confermare il nuovo indirizzo email del tuo accesso a Associazione Semplice usa questo link (vale un'ora e si usa una volta sola):\n\n{$link}\n\nSe non l'hai chiesto tu, ignora questa email.\n",
                static function (Message $message) use ($newEmail): void {
                    $message->to($newEmail)->subject('Conferma il nuovo indirizzo email');
                },
            );
        } catch (Throwable) {
            // Nessun errore a chi chiede: il resto della richiesta è già registrato.
        }

        return [];
    }

    public function confirmEmailChange(string $code, ?string $ip): bool
    {
        $db = $this->db->connection('pgsql');

        return $db->transaction(function () use ($db, $code, $ip): bool {
            $row = $db->table('core.staff_email_changes')->where('token_hash', hash('sha256', $code))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            $personId = $row !== null && is_string($row->person_id) ? $row->person_id : null;
            $email = $row !== null && is_string($row->new_email) ? $row->new_email : null;
            if ($row === null || $personId === null || $email === null || $db->table('core.staff_people')->where('email', $email)->exists()) {
                return false;
            }
            $db->table('core.staff_email_changes')->where('id', $row->id)->update(['used_at' => now()]);
            $db->table('core.staff_people')->where('id', $personId)->update(['email' => $email]);
            $this->directory->audit('staff.email.changed', $personId, null, [], $ip);

            return true;
        });
    }

    /**
     * Il segreto del secondo livello (non ancora attivo) con il suo QR.
     *
     * @return array{secret: string, qr_svg: string}|null
     */
    public function startTwoFactor(string $sid, string $password): ?array
    {
        $person = $this->personOf($sid);
        if ($person === null || $this->directory->authenticate($person->email, $password) === null || $this->access->hasTwoFactor($person)) {
            return null;
        }
        $secret = $this->totp->generateSecretKey();
        $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->update(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => null]);

        $style = new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72)));
        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString($this->totp->qrCodeUrl('Associazione Semplice', $person->email, $secret));

        return ['secret' => $secret, 'qr_svg' => trim(substr($svg, (int) strpos($svg, "\n") + 1))];
    }

    public function confirmTwoFactor(string $sid, string $code, ?string $ip): bool
    {
        $person = $this->personOf($sid);
        $secret = $person === null ? null : $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->value('two_factor_secret');
        if ($person === null || ! is_string($secret) || $this->access->hasTwoFactor($person) || ! $this->access->codeIsValid($person, $code)) {
            return false;
        }
        $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->update(['two_factor_confirmed_at' => now()]);
        $this->directory->audit('staff.two_factor.enabled', $person->id, null, [], $ip);

        return true;
    }

    public function disableTwoFactor(string $sid, string $password, string $code, ?string $ip): bool
    {
        $person = $this->personOf($sid);
        if ($person === null || ! $this->access->hasTwoFactor($person) || $this->directory->authenticate($person->email, $password) === null || ! $this->access->codeIsValid($person, $code)) {
            return false;
        }
        $this->db->connection('pgsql')->table('core.staff_people')->where('id', $person->id)->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->directory->audit('staff.two_factor.disabled', $person->id, null, [], $ip);

        return true;
    }

    /** Chiude una sessione della stessa persona (per riferimento breve, mai il `sid`). */
    public function revokeSession(string $sid, string $ref): bool
    {
        $person = $this->personOf($sid);
        if ($person === null) {
            return false;
        }
        $revoked = 0;
        foreach ($this->db->connection('pgsql')->table('core.staff_sessions')->where('person_id', $person->id)->whereNull('revoked_at')->pluck('sid') as $other) {
            if (is_string($other) && hash_equals(self::sessionRef($other), $ref)) {
                $this->access->revokeSession($other);
                $revoked++;
            }
        }

        return $revoked > 0;
    }

    public function logout(string $sid): void
    {
        $this->access->revokeSession($sid);
    }

    public function personOf(string $sid): ?StaffPerson
    {
        return preg_match('/^[0-9a-f]{32}$/', $sid) === 1 ? $this->directory->personOfSession($sid) : null;
    }

    private static function sessionRef(string $sid): string
    {
        return substr(hash('sha256', 'ref|'.$sid), 0, 16);
    }

    /** @return array{email: string, name: string} */
    private function card(StaffPerson $person): array
    {
        return ['email' => $person->email, 'name' => $person->name];
    }

    /** @return array{status: 'ok', sid: string, person: array{email: string, name: string}, areas: array<string, string>} */
    private function open(StaffPerson $person, ?string $ip): array
    {
        $sid = $this->directory->openSession($person, $ip);
        $this->directory->audit('staff.login', $person->id, null, ['area' => 'accesso'], $ip);

        return ['status' => 'ok', 'sid' => $sid, 'person' => $this->card($person), 'areas' => $this->directory->areas($person)];
    }
}

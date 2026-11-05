<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\MemberProfiles;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Accesso dei soci senza password (dal MemberSession del plugin). Con l'email si riceve un link e un codice a 6 cifre
 * (validi 15 minuti, una volta sola, al massimo 5 tentativi sul codice); chi non ha email riceve dalla segreteria un
 * link valido 7 giorni. Nel database restano solo le impronte (sha256) di link, codici e sessioni.
 *
 * @phpstan-type Session array{id: string, person_id: string, email: string|null}
 */
final readonly class MemberAccess
{
    public const CODE_MINUTES = 15;

    public const STAFF_DAYS = 7;

    public const MAX_ATTEMPTS = 5;

    public const IDLE_DAYS = 30;

    public const MAX_DAYS = 90;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private MemberProfiles $profiles,
        private Mailer $mailer,
        private AuditLog $audit,
    ) {}

    /** Manda link e codice se l'email è di qualcuno che può entrare; la risposta per chi chiede è sempre la stessa. */
    public function requestEmail(string $email, string $baseUrl, string $orgName): void
    {
        $email = Str::lower(trim($email));
        if ($this->profiles->loginCandidates($email) === []) {
            return;
        }
        $link = self::random();
        $code = (string) random_int(100000, 999999);
        $id = Str::uuid7()->toString();
        $this->table('app.member_login_tokens')->insert([
            'org_id' => $this->org(), 'id' => $id, 'kind' => 'email', 'email' => $email, 'link_hash' => self::hash($link),
            'code_hash' => self::hash($id.':'.$code), 'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);
        $this->mailer->to($email)->send(new LoginLink($orgName, rtrim($baseUrl, '/').'/area/entra/'.$link, $code, self::CODE_MINUTES));
        $this->audit->record('member.login_requested', null, null, ['kind' => 'email']);
    }

    /** Il codice ricevuto per email: la persona che entra, o null. */
    public function verifyCode(string $email, string $code): ?string
    {
        $email = Str::lower(trim($email));
        $row = $this->table('app.member_login_tokens')->where('kind', 'email')->where('email', $email)->whereNull('used_at')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->first(['id', 'code_hash', 'attempts']);
        if ($row === null || ! is_string($row->id ?? null) || ! is_string($row->code_hash ?? null)) {
            return null;
        }
        $attempts = is_numeric($row->attempts ?? null) ? (int) $row->attempts : 0;
        if ($attempts >= self::MAX_ATTEMPTS || ! hash_equals($row->code_hash, self::hash($row->id.':'.trim($code)))) {
            $this->table('app.member_login_tokens')->where('id', $row->id)->update(['attempts' => min(100, $attempts + 1)]);

            return null;
        }
        $this->table('app.member_login_tokens')->where('id', $row->id)->update(['used_at' => now()]);

        return $this->profiles->loginCandidates($email)[0] ?? null;
    }

    /** @return array{person_id: string, email: string|null}|null chi entra con il link, o null se il link non vale */
    public function consumeLink(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $row = $this->table('app.member_login_tokens')->where('link_hash', self::hash($token))->whereNull('used_at')
            ->where('expires_at', '>', now())->first(['id', 'kind', 'email', 'person_id']);
        if ($row === null || ! is_string($row->id ?? null)) {
            return null;
        }
        $this->table('app.member_login_tokens')->where('id', $row->id)->update(['used_at' => now()]);
        if ($row->kind === 'staff') {
            return is_string($row->person_id ?? null) && $this->profiles->canLogin($row->person_id) ? ['person_id' => $row->person_id, 'email' => null] : null;
        }
        $email = is_string($row->email ?? null) ? $row->email : '';
        $person = $this->profiles->loginCandidates($email)[0] ?? null;

        return $person === null ? null : ['person_id' => $person, 'email' => $email];
    }

    /** Link d'accesso preparato dalla segreteria (per WhatsApp): vale 7 giorni, una volta sola. */
    public function staffLink(string $personId, ?string $userId, string $baseUrl): string
    {
        if (! $this->profiles->canLogin($personId)) {
            throw new MemberAccessError('Questa persona non può entrare nell\'area soci (ospiti esclusi).');
        }
        $link = self::random();
        $this->table('app.member_login_tokens')->insert([
            'org_id' => $this->org(), 'id' => Str::uuid7()->toString(), 'kind' => 'staff', 'person_id' => $personId,
            'link_hash' => self::hash($link), 'expires_at' => now()->addDays(self::STAFF_DAYS), 'created_by' => $userId,
        ]);
        $this->audit->record('member.link_created', 'app.people', $personId);

        return rtrim($baseUrl, '/').'/area/entra/'.$link;
    }

    /** Apre una sessione: il token va nel cookie, nel database solo l'impronta. */
    public function open(string $personId, ?string $email, ?string $userAgent): string
    {
        $token = self::random();
        $this->table('app.member_sessions')->insert([
            'org_id' => $this->org(), 'id' => Str::uuid7()->toString(), 'token_hash' => self::hash($token), 'email' => $email, 'person_id' => $personId,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255), 'expires_at' => now()->addDays(self::MAX_DAYS),
        ]);
        $this->audit->record('member.login', 'app.people', $personId);

        return $token;
    }

    /** @return Session|null la sessione del cookie, se è ancora valida (l'ultimo uso si aggiorna al massimo ogni 5 minuti) */
    public function resolve(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $row = $this->table('app.member_sessions')->where('token_hash', self::hash($token))->whereNull('revoked_at')
            ->where('expires_at', '>', now())->where('last_used_at', '>', now()->subDays(self::IDLE_DAYS))
            ->first(['id', 'person_id', 'email', 'last_used_at']);
        if ($row === null || ! is_string($row->id ?? null) || ! is_string($row->person_id ?? null) || ! $this->profiles->canLogin($row->person_id)) {
            return null;
        }
        if (is_string($row->last_used_at ?? null) && strtotime($row->last_used_at) < time() - 300) {
            $this->table('app.member_sessions')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return ['id' => $row->id, 'person_id' => $row->person_id, 'email' => is_string($row->email ?? null) ? $row->email : null];
    }

    public function close(string $sessionId): void
    {
        $this->table('app.member_sessions')->where('id', $sessionId)->update(['revoked_at' => now()]);
    }

    /** Chiude tutte le sessioni della persona (anche dalla segreteria). @return int sessioni chiuse */
    public function closeAll(string $personId): int
    {
        $n = $this->table('app.member_sessions')->where('person_id', $personId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->record('member.logout_all', 'app.people', $personId, ['sessions' => $n]);

        return $n;
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $v): string
    {
        return hash('sha256', $v);
    }

    private function org(): string
    {
        return $this->context->require()->id;
    }

    private function table(string $table): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table($table);
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/** L'elenco unico dello staff: persone, livelli per area, sessioni centrali e registro (tabelle `core.staff_*`). */
final readonly class StaffDirectory
{
    /** La sessione centrale dura al massimo 8 ore (UTENTI_E_ACCESSI §5). */
    public const SESSION_MAX = 8 * 3600;

    public function __construct(private DatabaseManager $db, private StaffIdentityConfig $config, private Hasher $hasher) {}

    /** Email e password giuste e persona attiva; altrimenti nulla (senza dire quale delle due cose è sbagliata). */
    public function authenticate(string $email, string $password): ?StaffPerson
    {
        $row = $this->db->connection('pgsql')->table('core.staff_people')->where('email', mb_strtolower($email))->first();
        $hash = $row !== null && is_string($row->password ?? null) && $row->password !== '' ? $row->password : null;
        // Cliente importato dal portale: finché non ha una password del SaaS vale l'impronta del portale (una volta sola).
        $legacy = $row !== null && $hash === null && is_string($row->legacy_hash ?? null) && $row->legacy_hash !== '' ? $row->legacy_hash : null;

        // Il confronto si fa sempre, anche per chi non esiste: il tempo di risposta non rivela quali email ci sono.
        $ok = $legacy !== null
            ? password_verify(self::prehash($password), $legacy)
            : $this->hasher->check($password, $hash ?? $this->dummyHash());
        if ($row === null || ($hash === null && $legacy === null) || ! $ok || $row->active !== true) {
            return null;
        }
        if ($legacy !== null) {
            $this->db->connection('pgsql')->table('core.staff_people')->where('id', $row->id)->update(['password' => $this->hasher->make($password), 'legacy_hash' => null]);
        }

        return new StaffPerson(self::text($row->id), self::text($row->email), self::text($row->name));
    }

    /**
     * Livello per area. I super amministratori sono admin di tutte le aree, sempre; gli altri hanno solo i ruoli assegnati.
     *
     * @return array<string, string>
     */
    public function areas(StaffPerson $person): array
    {
        if (in_array($person->email, $this->config->superAdmins(), true)) {
            return array_fill_keys(array_keys(StaffAreas::AUDIENCES), 'admin');
        }

        $areas = [];
        foreach ($this->db->connection('pgsql')->table('core.staff_roles')->where('person_id', $person->id)->get(['area', 'level']) as $role) {
            $areas[self::text($role->area)] = self::text($role->level);
        }

        return $areas;
    }

    public function openSession(StaffPerson $person, ?string $ip): string
    {
        $sid = bin2hex(random_bytes(16));
        $db = $this->db->connection('pgsql');
        $db->table('core.staff_sessions')->insert(['sid' => $sid, 'person_id' => $person->id, 'ip' => $ip]);
        $db->table('core.staff_people')->where('id', $person->id)->update(['last_login_at' => now()]);

        return $sid;
    }

    /** La persona di una sessione centrale ancora valida (non revocata, entro 8 ore, persona attiva). */
    public function personOfSession(string $sid): ?StaffPerson
    {
        $db = $this->db->connection('pgsql');
        $row = $db->table('core.staff_sessions as s')
            ->join('core.staff_people as p', 'p.id', '=', 's.person_id')
            ->where('s.sid', $sid)
            ->whereNull('s.revoked_at')
            ->where('s.created_at', '>', now()->subSeconds(self::SESSION_MAX))
            ->where('p.active', true)
            ->first(['p.id', 'p.email', 'p.name']);
        if ($row === null) {
            return null;
        }
        $db->table('core.staff_sessions')->where('sid', $sid)->update(['last_used_at' => now()]);

        return new StaffPerson(self::text($row->id), self::text($row->email), self::text($row->name));
    }

    /** @param  array<string, mixed>  $details  mai password, segreti o codici */
    public function audit(string $action, ?string $personId, ?string $subject = null, array $details = [], ?string $ip = null): void
    {
        $this->db->connection('pgsql')->table('core.staff_audit')->insert([
            'id' => (string) Str::uuid7(),
            'person_id' => $personId,
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    /** Il pre-hash che il portale applica prima di bcrypt (`Auth::prehash`): base64 dello sha256 binario. */
    public static function prehash(string $password): string
    {
        return base64_encode(hash('sha256', $password, true));
    }

    private function dummyHash(): string
    {
        return $this->hasher->make('password-inesistente-'.bin2hex(random_bytes(4)));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

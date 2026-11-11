<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Inviti dello staff: l'amministratore invita una email con i ruoli per area; la persona sceglie da sé la password dal
 * link ricevuto (valido 7 giorni, una volta sola). Nessuno vede mai la password; del codice resta solo l'impronta.
 */
final readonly class StaffInvites
{
    public const VALID_DAYS = 7;

    public const MIN_PASSWORD = 12;

    public function __construct(private DatabaseManager $db, private Hasher $hasher, private StaffDirectory $directory) {}

    /**
     * Crea l'invito e restituisce il codice da mettere nel link (si vede una volta sola).
     *
     * @param  array<string, string>  $roles  area => livello
     *
     * @throws InvalidArgumentException
     */
    public function create(string $email, string $name, array $roles, ?string $createdBy = null): string
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new InvalidArgumentException('Email non valida.');
        }
        if ($name === '' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('Il nome è obbligatorio (al massimo 200 caratteri).');
        }
        foreach ($roles as $area => $level) {
            if (! StaffAreas::exists($area) || ! in_array($level, StaffAreas::LEVELS[$area], true)) {
                throw new InvalidArgumentException("Ruolo non valido: {$area} / {$level}.");
            }
        }

        $db = $this->db->connection('pgsql');
        if ($db->table('core.staff_people')->where('email', $email)->where('password', '!=', '')->whereNotNull('password')->exists()) {
            throw new InvalidArgumentException('Questa persona ha già un accesso.');
        }

        $code = bin2hex(random_bytes(24));
        $db->table('core.staff_invites')->insert([
            'id' => (string) Str::uuid7(),
            'token_hash' => hash('sha256', $code),
            'email' => $email,
            'name' => $name,
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'created_by' => $createdBy,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);
        $this->directory->audit('staff.people.invite', $createdBy, $email, ['roles' => $roles]);

        return $code;
    }

    /** L'email e il nome di un invito ancora valido (non usato, non scaduto); altrimenti nulla. */
    public function open(string $code): ?object
    {
        return $this->db->connection('pgsql')->table('core.staff_invites')
            ->where('token_hash', hash('sha256', $code))->whereNull('used_at')->where('expires_at', '>', now())
            ->first(['id', 'email', 'name', 'roles']);
    }

    /**
     * Usa l'invito: crea (o attiva) la persona con la password scelta e le assegna i ruoli. Una sola volta.
     *
     * @return list<string> gli errori della password; vuoto = fatto
     */
    public function accept(string $code, string $password, string $confirmation, ?string $ip): array
    {
        if ($password !== $confirmation) {
            return ['Le due password non coincidono.'];
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return ['La password deve avere almeno '.self::MIN_PASSWORD.' caratteri.'];
        }
        if (strlen($password) > 1024) {
            return ['La password è troppo lunga.'];
        }

        $db = $this->db->connection('pgsql');

        return $db->transaction(function () use ($db, $code, $password, $ip): array {
            // Blocca la riga: due invii insieme non usano lo stesso invito due volte.
            $invite = $db->table('core.staff_invites')->where('token_hash', hash('sha256', $code))->whereNull('used_at')
                ->where('expires_at', '>', now())->lock('for update')->first();
            if ($invite === null) {
                return ['Questo invito non è più valido.'];
            }
            $email = is_string($invite->email ?? null) ? $invite->email : '';
            $name = is_string($invite->name ?? null) ? $invite->name : '';
            $roles = json_decode(is_string($invite->roles ?? null) ? $invite->roles : '{}', true);

            $person = $db->table('core.staff_people')->where('email', $email)->first(['id']);
            $id = is_object($person) && is_string($person->id ?? null) ? $person->id : (string) Str::uuid7();
            $fields = ['name' => $name, 'password' => $this->hasher->make($password), 'active' => true];
            if (is_object($person)) {
                $db->table('core.staff_people')->where('id', $id)->update($fields);
            } else {
                $db->table('core.staff_people')->insert(['id' => $id, 'email' => $email] + $fields);
            }
            foreach (is_array($roles) ? $roles : [] as $area => $level) {
                if (is_string($area) && is_string($level)) {
                    $db->table('core.staff_roles')->updateOrInsert(['person_id' => $id, 'area' => $area], ['level' => $level]);
                }
            }
            $db->table('core.staff_invites')->where('id', $invite->id)->update(['used_at' => now()]);
            $this->directory->audit('staff.people.accept', $id, $email, [], $ip);

            return [];
        });
    }
}

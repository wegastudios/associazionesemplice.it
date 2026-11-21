<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use App\Modules\Portale\Contracts\StaffAccessDenied;
use App\Modules\Portale\Contracts\StaffJwt;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

/**
 * Ingresso degli operatori delle associazioni nel gestionale dal login unico (docs/LOGIN_UNICO_E_DATABASE.md, passo 6).
 * Il portale chiede, con la sessione centrale della persona, un biglietto `operator_sso` (60 secondi, monouso) che
 * viaggia nel frammento dell'indirizzo (mai nelle richieste, quindi mai nei log) e arriva a `/accesso-unico` in POST.
 * Il biglietto dice solo chi è la persona: il gestionale chiede sempre il secondo livello (la sfida del gestionale)
 * e l'utente si lega alla persona in modo esplicito, la prima volta, dopo password e secondo livello. L'email può
 * soltanto proporre il legame. Acceso solo con SAAS_UNIFIED_OPERATORS (spento di default).
 */
final readonly class OperatorEntry
{
    private const AUDIENCE = 'associazionesemplice-gestionale';

    /** Chiave di sessione: il legame da creare appena l'utente completa l'accesso. */
    public const LINK_KEY = 'operator.sso_link';

    /** Chiave di sessione: l'ingresso in attesa della conferma di cambio utente. */
    public const PENDING_KEY = 'operator.sso_pending';

    /** Secondi in cui il legame atteso resta valido (il tempo di scrivere il codice della sfida). */
    public const WAIT_SECONDS = 600;

    /** Secondi in cui la conferma del cambio utente resta valida. */
    public const PENDING_SECONDS = 300;

    public function __construct(private StaffIdentityConfig $identity, private IdentityService $sessions, private StaffDirectory $directory, private DatabaseManager $db, private Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('saas.unified_login.operators') === true && $this->identity->enabled();
    }

    /** Dove va chi non ha accesso al gestionale o scade il biglietto. */
    public function accountUrl(): string
    {
        $public = $this->config->get('saas.public_url');

        return rtrim(is_string($public) && $public !== '' ? $public : 'https://associazionesemplice.it', '/').'/account';
    }

    /** L'indirizzo d'ingresso per la sessione centrale `$sid`, o null se la persona non è operatore di un'associazione attiva. */
    public function urlFor(string $sid): ?string
    {
        $person = $this->sessions->personOf($sid);
        if ($person === null || $this->target($person) === null) {
            return null;
        }
        $now = time();
        $token = StaffJwt::sign([
            'iss' => $this->identity->issuer(),
            'aud' => self::AUDIENCE,
            'typ' => 'operator_sso',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => $person->email,
            'pid' => $person->id,
            // Nel biglietto, che passa dal browser, non va l'identificativo vero: un riferimento valido solo per questo ingresso.
            'sid' => $this->directory->issueAreaRef($sid, 'operator'),
        ], $this->identity->signingSeed(), 'staff-');

        // Nel frammento (dopo il #): il browser non lo manda al server. La pagina intermedia lo rimanda in POST.
        return $this->appBase().'/accesso-unico#t='.rawurlencode($token);
    }

    /** Consuma il biglietto (una volta sola) e dà la persona della sessione centrale, o null. */
    public function consume(string $token): ?StaffPerson
    {
        $kid = StaffJwt::kidOf($token);
        $seed = $this->identity->signingSeed();
        if ($kid === null || $seed === '') {
            return null;
        }
        $public = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))), '+/', '-_'), '=');
        try {
            $claims = StaffJwt::verify($token, [$kid => $public], $this->identity->issuer(), self::AUDIENCE, 'operator_sso', time());
        } catch (StaffAccessDenied) {
            return null;
        }
        $pid = $claims['pid'] ?? null;
        $sid = $claims['sid'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (! is_string($pid) || ! is_string($sid) || ! is_string($jti) || $jti === '' || strlen($jti) > 100) {
            return null;
        }
        // Monouso, e il riferimento deve valere ancora per la sessione centrale, della stessa persona.
        if (! Cache::add('operator-sso:'.$jti, 1, 180)) {
            return null;
        }
        $person = $this->directory->personOfAreaRef($sid, 'operator');

        return $person !== null && $person->id === $pid ? $person : null;
    }

    /**
     * L'utente del gestionale per questa persona: quello legato, o (solo come proposta, `linked` falso) quello con la stessa email.
     * Sempre attivo e operatore di almeno un'associazione.
     *
     * @return array{user: string, linked: bool}|null
     */
    public function target(StaffPerson $person): ?array
    {
        $con = $this->db->connection('pgsql');
        $linked = $con->table('core.operator_links')->where('person_id', $person->id)->value('user_id');
        if (is_string($linked)) {
            return $this->isOperator($linked) ? ['user' => $linked, 'linked' => true] : null;
        }

        $id = $con->table('core.users')->where('email', mb_strtolower($person->email))->value('id');
        // L'email propone soltanto: se l'utente è già legato a un'altra persona, non è lui.
        if (! is_string($id) || $con->table('core.operator_links')->where('user_id', $id)->exists()) {
            return null;
        }

        return $this->isOperator($id) ? ['user' => $id, 'linked' => false] : null;
    }

    /**
     * Nome ed email dell'utente del gestionale, per le pagine di conferma.
     *
     * @return array{name: string, email: string}
     */
    public function describe(string $userId): array
    {
        $row = $this->db->connection('pgsql')->table('core.users')->where('id', $userId)->first(['name', 'email']);

        $name = $row?->name;
        $email = $row?->email;

        return ['name' => is_string($name) ? $name : '', 'email' => is_string($email) ? $email : ''];
    }

    /** Vero se l'utente ha la verifica in due passaggi confermata. */
    public function hasTwoFactor(string $userId): bool
    {
        $row = $this->db->connection('pgsql')->table('core.users')->where('id', $userId)->first(['two_factor_secret', 'two_factor_confirmed_at']);

        return $row !== null && $row->two_factor_secret !== null && $row->two_factor_confirmed_at !== null;
    }

    /** Ricorda in sessione il legame da creare quando l'utente avrà completato l'accesso. */
    public function expectLink(string $userId, string $personId): void
    {
        Session::put(self::LINK_KEY, ['user' => $userId, 'person' => $personId, 'until' => time() + self::WAIT_SECONDS]);
    }

    /** Alla fine di un accesso completo: se era atteso un legame per questo utente (e ha il secondo livello), lo crea. */
    public function completeLink(string $userId): void
    {
        $pending = Session::pull(self::LINK_KEY);
        if (! is_array($pending) || ($pending['user'] ?? null) !== $userId || ! is_string($pending['person'] ?? null) || ! is_int($pending['until'] ?? null) || $pending['until'] < time()) {
            return;
        }
        if (! $this->hasTwoFactor($userId)) {
            return;
        }
        $created = $this->db->connection('pgsql')->table('core.operator_links')->insertOrIgnore(['user_id' => $userId, 'person_id' => $pending['person']]);
        if ($created > 0) {
            $this->directory->audit('operator.linked', $pending['person'], $userId);
        }
    }

    private function isOperator(string $userId): bool
    {
        $con = $this->db->connection('pgsql');

        return $con->table('core.users')->where('id', $userId)->whereNull('disabled_at')->exists()
            && $con->table('core.org_users')->where('user_id', $userId)->exists();
    }

    private function appBase(): string
    {
        $url = $this->config->get('app.url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://app.associazionesemplice.it', '/');
    }
}

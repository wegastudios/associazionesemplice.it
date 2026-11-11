<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

use App\Modules\Portale\Contracts\StaffJwt;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/** Configurazione del SaaS come fornitore di identità dello staff (config/saas.php, sezione staff_issuer). */
final readonly class StaffIdentityConfig
{
    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('saas.staff_issuer.enabled') === true;
    }

    /** Emittente (`iss`) dei token `staff_sso` che il SaaS firma. */
    public function issuer(): string
    {
        return rtrim($this->string('saas.staff_issuer.issuer'), '/');
    }

    /** Emittente (`iss`) delle richieste firmate che le aree mandano al SaaS. */
    public function requestIssuer(): string
    {
        return rtrim($this->string('saas.staff_issuer.request_issuer'), '/');
    }

    /** Seme (32 byte) della chiave `staff-…` con cui il SaaS firma gli `staff_sso`. */
    public function signingSeed(): string
    {
        $seed = base64_decode($this->string('saas.staff_issuer.signing_key'), true);

        return $seed !== false && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES
            ? $seed
            : throw new RuntimeException('SAAS_STAFF_SIGNING_KEY non valida.');
    }

    /**
     * Chiavi pubbliche delle aree: "kid=x@area,…" (x = chiave pubblica Ed25519 in base64url).
     *
     * @return array<string, array{x: string, area: string}>
     */
    public function areaKeys(): array
    {
        $keys = [];
        $configured = $this->config->get('saas.staff_issuer.area_keys', '');
        foreach (explode(',', is_string($configured) ? $configured : '') as $entry) {
            [$kid, $rest] = array_pad(explode('=', trim($entry), 2), 2, '');
            [$x, $area] = array_pad(explode('@', $rest, 2), 2, '');
            if ($kid !== '' && StaffAreas::exists($area) && StaffJwt::publicKeyLength($x) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[$kid] = ['x' => $x, 'area' => $area];
            }
        }

        return $keys;
    }

    /**
     * Email dei super amministratori (la titolare): admin di tutte le aree, sempre. Solo dalla configurazione del server.
     *
     * @return list<string>
     */
    public function superAdmins(): array
    {
        $configured = $this->config->get('saas.staff_issuer.super_admins', '');

        return array_values(array_filter(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            explode(',', is_string($configured) ? $configured : ''),
        ), static fn (string $email): bool => $email !== ''));
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Configurazione mancante: {$key}");
    }
}

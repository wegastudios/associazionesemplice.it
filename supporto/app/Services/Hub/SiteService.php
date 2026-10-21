<?php

namespace App\Services\Hub;

use App\Models\Site;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Support\Facades\Crypt;

/** Registrazione, rotazione e revoca delle credenziali dei siti di supporto. */
class SiteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra un sito. Le credenziali sono restituite una sola volta.
     *
     * @param  list<int>  $priorities  livelli 0-4 che il sito puo assegnare
     * @param  list<string>  $notifyEmails
     * @return array{site: Site, token: string, hmac_secret: string}
     */
    public function create(string $name, string $portalEmail, array $priorities, ?string $url = null, array $notifyEmails = [], bool $allowReopen = true, ?Actor $actor = null): array
    {
        $clientId = 'wsc_'.bin2hex(random_bytes(8));
        $secret = $this->newSecret();
        $hmac = 'whsec_'.bin2hex(random_bytes(24));

        $site = Site::create([
            'name' => $name,
            'client_id' => $clientId,
            'secret_hash' => $this->hash($secret),
            'hmac_secret_encrypted' => Crypt::encryptString($hmac),
            'url' => $url,
            'portal_email' => $portalEmail,
            'allowed_priorities' => $this->cleanPriorities($priorities),
            'allow_reopen' => $allowReopen,
            'notify_emails' => $notifyEmails ?: null,
        ]);

        $this->audit->record('site.created', $site, ['allowed_priorities' => $site->allowed_priorities], $actor);

        return ['site' => $site, 'token' => "{$clientId}.{$secret}", 'hmac_secret' => $hmac];
    }

    /**
     * @param  array{name?: string, portal_email?: string, url?: ?string, allowed_priorities?: list<int>, allow_reopen?: bool, notify_emails?: list<string>}  $data
     */
    public function update(Site $site, array $data, ?Actor $actor = null): Site
    {
        $before = $site->only(['name', 'portal_email', 'url', 'allowed_priorities', 'allow_reopen', 'notify_emails']);

        if (isset($data['allowed_priorities'])) {
            $data['allowed_priorities'] = $this->cleanPriorities($data['allowed_priorities']);
        }
        if (array_key_exists('notify_emails', $data)) {
            $data['notify_emails'] = $data['notify_emails'] ?: null;
        }

        $site->forceFill($data)->save();
        $this->audit->record('site.updated', $site, ['before' => $before, 'after' => $site->only(array_keys($before))], $actor);

        return $site;
    }

    /** Ruota il segreto; il precedente resta valido per la sola finestra di transizione. */
    public function rotate(Site $site, ?Actor $actor = null): string
    {
        $secret = $this->newSecret();

        $site->forceFill([
            'previous_secret_hash' => $site->secret_hash,
            'previous_secret_expires_at' => now()->addHours((int) config('support.secret_rotation_hours')),
            'secret_hash' => $this->hash($secret),
        ])->save();

        $this->audit->record('site.rotated', $site, [], $actor);

        return "{$site->client_id}.{$secret}";
    }

    public function revoke(Site $site, ?Actor $actor = null, ?string $reason = null): void
    {
        $site->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('site.revoked', $site, array_filter(['reason' => $reason]), $actor);
    }

    public function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** @param  list<int|string>  $levels  @return list<int> */
    private function cleanPriorities(array $levels): array
    {
        $valid = array_values(array_intersect(array_map('intval', $levels), config('support.priority_levels')));
        sort($valid);

        return array_values(array_unique($valid));
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }
}
